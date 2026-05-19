<?php

namespace App\Service;

use PDO;
use RuntimeException;
use WilliamCosta\DatabaseManager\Database;

class CampaignDispatchRepository
{
    public static function createSchedule(array $data): int
    {
        CampaignDispatchSchema::ensureSchema();

        $payload = [
            'tenancy_id' => (string)$data['tenancy_id'],
            'user_id' => (int)$data['user_id'],
            'channel' => strtolower((string)$data['channel']),
            'native_table' => $data['native_table'] ?? null,
            'native_id' => $data['native_id'] ?? null,
            'legacy_schedule_id' => $data['legacy_schedule_id'] ?? null,
            'schedule_name' => (string)$data['schedule_name'],
            'dedupe_key' => (string)$data['dedupe_key'],
            'timezone' => (string)($data['timezone'] ?? 'America/Sao_Paulo'),
            'scheduled_at' => (string)$data['scheduled_at'],
            'available_at' => (string)$data['available_at'],
            'status' => (string)($data['status'] ?? 'scheduled'),
            'dispatch_mode' => (string)($data['dispatch_mode'] ?? 'native'),
            'payload_json' => isset($data['payload'])
                ? json_encode($data['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'run_after_failure_at' => $data['run_after_failure_at'] ?? null,
            'last_error' => $data['last_error'] ?? null,
            'attempt_count' => (int)($data['attempt_count'] ?? 0),
            'max_attempts' => (int)($data['max_attempts'] ?? 5),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        return (int)(new Database('campaign_dispatch_schedules'))->insert($payload);
    }

    public static function findByNative(string $channel, string $nativeTable, int $nativeId): ?array
    {
        CampaignDispatchSchema::ensureSchema();

        $row = (new Database('campaign_dispatch_schedules'))
            ->select(
                'channel = :channel AND native_table = :native_table AND native_id = :native_id AND status NOT IN ("completed", "cancelled")',
                [
                    ':channel' => strtolower($channel),
                    ':native_table' => $nativeTable,
                    ':native_id' => $nativeId,
                ],
                'id DESC',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function getById(int $id): ?array
    {
        CampaignDispatchSchema::ensureSchema();

        $row = (new Database('campaign_dispatch_schedules'))
            ->select('id = :id', [':id' => $id], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function acquireDueSchedules(string $workerName, int $limit = 10, int $leaseSeconds = 120): array
    {
        CampaignDispatchSchema::ensureSchema();

        $db = new Database();
        $lease = max(30, (int)$leaseSeconds);
        $safeLimit = max(1, (int)$limit);
        $rows = $db->execute(
            "SELECT id
               FROM campaign_dispatch_schedules
              WHERE status IN ('scheduled', 'retry_waiting')
                AND available_at <= NOW()
                AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL {$lease} SECOND))
              ORDER BY available_at ASC, id ASC
              LIMIT {$safeLimit}"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $claimed = [];
        foreach ($rows as $row) {
            $scheduleId = (int)($row['id'] ?? 0);
            if ($scheduleId <= 0) {
                continue;
            }

            $token = self::newLockToken($scheduleId, $workerName);
            $updated = $db->run(
                "UPDATE campaign_dispatch_schedules
                    SET locked_at = NOW(),
                        locked_by = :locked_by,
                        status = 'dispatching',
                        updated_at = NOW()
                  WHERE id = :id
                    AND status IN ('scheduled', 'retry_waiting')
                    AND available_at <= NOW()
                    AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL {$lease} SECOND))",
                [
                    ':locked_by' => $token,
                    ':id' => $scheduleId,
                ]
            )->rowCount();

            if ($updated !== 1) {
                continue;
            }

            $schedule = self::getById($scheduleId);
            if ($schedule) {
                $claimed[] = $schedule;
            }
        }

        return $claimed;
    }

    public static function releaseSchedule(
        int $scheduleId,
        string $status,
        ?string $errorMessage = null,
        ?array $extra = null
    ): void {
        CampaignDispatchSchema::ensureSchema();

        $values = [
            'status' => $status,
            'last_error' => $errorMessage,
            'locked_at' => null,
            'locked_by' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($status === 'completed') {
            $values['completed_at'] = date('Y-m-d H:i:s');
        }

        if ($status === 'running' && empty($values['started_at'])) {
            $values['started_at'] = date('Y-m-d H:i:s');
        }

        if ($status === 'retry_waiting') {
            $current = self::getById($scheduleId) ?? [];
            $attempts = (int)($current['attempt_count'] ?? 0) + 1;
            $maxAttempts = max(1, (int)($current['max_attempts'] ?? 5));

            if ($attempts >= $maxAttempts) {
                $values['status'] = 'failed';
                $values['attempt_count'] = $attempts;
                $values['completed_at'] = date('Y-m-d H:i:s');
                $values['run_after_failure_at'] = null;
                $values['available_at'] = date('Y-m-d H:i:s');
                $values['last_error'] = $errorMessage ?: 'Numero maximo de tentativas atingido.';
            } else {
                $delay = min(3600, max(60, 60 * (2 ** max(0, $attempts - 1))));
                $values['attempt_count'] = $attempts;
                $values['run_after_failure_at'] = date('Y-m-d H:i:s', time() + $delay);
                $values['available_at'] = $values['run_after_failure_at'];
            }
        }

        if (is_array($extra)) {
            foreach ($extra as $field => $value) {
                $values[$field] = $value;
            }
        }

        (new Database('campaign_dispatch_schedules'))->update(
            'id = :id',
            $values,
            [':id' => $scheduleId]
        );
    }

    public static function markScheduleQueued(int $scheduleId): void
    {
        (new Database('campaign_dispatch_schedules'))->update(
            'id = :id',
            [
                'status' => 'queued',
                'started_at' => date('Y-m-d H:i:s'),
                'locked_at' => null,
                'locked_by' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':id' => $scheduleId]
        );
    }

    public static function createRun(int $scheduleId, string $workerType, string $workerName, array $context = []): int
    {
        CampaignDispatchSchema::ensureSchema();

        return (int)(new Database('campaign_dispatch_runs'))->insert([
            'schedule_id' => $scheduleId,
            'worker_type' => $workerType,
            'worker_name' => $workerName,
            'status' => 'running',
            'queued_count' => 0,
            'processed_count' => 0,
            'failed_count' => 0,
            'context_json' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'started_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function finishRun(int $runId, string $status, int $queuedCount = 0, int $processedCount = 0, int $failedCount = 0, ?string $errorMessage = null): void
    {
        CampaignDispatchSchema::ensureSchema();

        (new Database('campaign_dispatch_runs'))->update(
            'id = :id',
            [
                'status' => $status,
                'queued_count' => $queuedCount,
                'processed_count' => $processedCount,
                'failed_count' => $failedCount,
                'error_message' => $errorMessage,
                'finished_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':id' => $runId]
        );
    }

    public static function leaseSmsQueueRows(string $workerName, int $limit = 200, int $leaseSeconds = 120): array
    {
        CampaignDispatchSchema::ensureSchema();

        $db = new Database();
        $lease = max(30, (int)$leaseSeconds);
        $safeLimit = max(1, (int)$limit);
        $rows = $db->execute(
            "SELECT id
               FROM campaign_sms_queue
              WHERE status IN ('pending', 'retry_waiting')
                AND available_at <= NOW()
                AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL {$lease} SECOND))
              ORDER BY available_at ASC, id ASC
              LIMIT {$safeLimit}"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $claimed = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $updated = $db->run(
                "UPDATE campaign_sms_queue
                    SET status = 'processing',
                        locked_at = NOW(),
                        locked_by = :locked_by,
                        updated_at = NOW()
                  WHERE id = :id
                    AND status IN ('pending', 'retry_waiting')
                    AND available_at <= NOW()
                    AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL {$lease} SECOND))",
                [
                    ':locked_by' => self::newLockToken($id, $workerName),
                    ':id' => $id,
                ]
            )->rowCount();

            if ($updated !== 1) {
                continue;
            }

            $item = $db->execute(
                'SELECT * FROM campaign_sms_queue WHERE id = :id LIMIT 1',
                [':id' => $id]
            )->fetch(PDO::FETCH_ASSOC);

            if ($item) {
                $claimed[] = $item;
            }
        }

        return $claimed;
    }

    public static function enqueueSmsRows(array $rows): int
    {
        CampaignDispatchSchema::ensureSchema();

        $queue = new Database('campaign_sms_queue');
        $count = 0;

        foreach ($rows as $row) {
            $queue->insert($row);
            $count++;
        }

        return $count;
    }

    public static function markSmsQueueSent(int $id, int $callbackId, array $providerResponse = []): void
    {
        (new Database('campaign_sms_queue'))->update(
            'id = :id',
            [
                'status' => 'sent',
                'callback_id' => $callbackId,
                'provider_response_json' => $providerResponse === [] ? null : json_encode($providerResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sent_at' => date('Y-m-d H:i:s'),
                'locked_at' => null,
                'locked_by' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':id' => $id]
        );
    }

    public static function markSmsQueueFailed(int $id, string $errorMessage, int $attempts, int $maxAttempts, array $providerResponse = []): void
    {
        $status = $attempts >= $maxAttempts ? 'failed' : 'retry_waiting';
        $delay = min(3600, max(60, 60 * (2 ** max(0, $attempts - 1))));

        (new Database('campaign_sms_queue'))->update(
            'id = :id',
            [
                'status' => $status,
                'attempts' => $attempts,
                'last_error' => $errorMessage,
                'provider_response_json' => $providerResponse === [] ? null : json_encode($providerResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'available_at' => $status === 'retry_waiting' ? date('Y-m-d H:i:s', time() + $delay) : date('Y-m-d H:i:s'),
                'locked_at' => null,
                'locked_by' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':id' => $id]
        );
    }

    public static function summarizeSmsSchedule(int $scheduleId): array
    {
        $row = (new Database())->execute(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN status IN ('failed', 'dead') THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN status IN ('pending', 'retry_waiting', 'processing') THEN 1 ELSE 0 END) AS pending_count
             FROM campaign_sms_queue
             WHERE schedule_id = :schedule_id",
            [':schedule_id' => $scheduleId]
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int)($row['total'] ?? 0),
            'sent_count' => (int)($row['sent_count'] ?? 0),
            'failed_count' => (int)($row['failed_count'] ?? 0),
            'pending_count' => (int)($row['pending_count'] ?? 0),
        ];
    }

    public static function purgeOldData(int $logsRetentionDays = 30, int $runsRetentionDays = 30, int $smsRetentionDays = 15): array
    {
        CampaignDispatchSchema::ensureSchema();

        $db = new Database();

        $logs = $db->execute(
            "DELETE FROM campaign_dispatch_logs
             WHERE created_at < DATE_SUB(NOW(), INTERVAL {$logsRetentionDays} DAY)"
        )->rowCount();

        $runs = $db->execute(
            "DELETE FROM campaign_dispatch_runs
             WHERE finished_at IS NOT NULL
               AND finished_at < DATE_SUB(NOW(), INTERVAL {$runsRetentionDays} DAY)"
        )->rowCount();

        $sms = $db->execute(
            "DELETE FROM campaign_sms_queue
             WHERE status IN ('sent', 'failed', 'cancelled')
               AND updated_at < DATE_SUB(NOW(), INTERVAL {$smsRetentionDays} DAY)"
        )->rowCount();

        return [
            'logs_deleted' => $logs,
            'runs_deleted' => $runs,
            'sms_queue_deleted' => $sms,
        ];
    }

    private static function newLockToken(int $id, string $workerName): string
    {
        return $workerName . ':' . $id . ':' . substr(bin2hex(random_bytes(6)), 0, 12);
    }
}
