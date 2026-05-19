<?php

namespace App\Service;

class CampaignSchedulerService
{
    public static function schedule(
        array $user,
        string $channel,
        string $scheduleName,
        \DateTimeInterface $scheduledAt,
        array $payload,
        array $options = []
    ): int {
        CampaignDispatchSchema::ensureSchema();

        $timezone = $options['timezone'] ?? ($user['timezone'] ?? getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
        $nativeTable = $options['native_table'] ?? null;
        $nativeId = $options['native_id'] ?? null;
        $legacyScheduleId = $options['legacy_schedule_id'] ?? null;
        $dedupeKey = $options['dedupe_key'] ?? self::dedupeKey($channel, (string)$user['tenancy_id'], $scheduleName, $scheduledAt, $payload, $nativeTable, $nativeId);

        $existing = $nativeTable && $nativeId
            ? CampaignDispatchRepository::findByNative($channel, (string)$nativeTable, (int)$nativeId)
            : null;
        if ($existing) {
            return (int)$existing['id'];
        }

        $id = CampaignDispatchRepository::createSchedule([
            'tenancy_id' => (string)$user['tenancy_id'],
            'user_id' => (int)$user['id'],
            'channel' => $channel,
            'native_table' => $nativeTable,
            'native_id' => $nativeId,
            'legacy_schedule_id' => $legacyScheduleId,
            'schedule_name' => $scheduleName,
            'dedupe_key' => $dedupeKey,
            'timezone' => $timezone,
            'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
            'available_at' => $scheduledAt->format('Y-m-d H:i:s'),
            'status' => 'scheduled',
            'dispatch_mode' => $options['dispatch_mode'] ?? 'native',
            'payload' => $payload,
            'max_attempts' => (int)($options['max_attempts'] ?? 5),
        ]);

        CampaignDispatchLogger::log(
            $channel,
            'schedule_created',
            'Agendamento criado.',
            [
                'schedule_id' => $id,
                'native_table' => $nativeTable,
                'native_id' => $nativeId,
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                'timezone' => $timezone,
            ],
            $id,
            null,
            (string)$user['tenancy_id'],
            (int)$user['id']
        );

        return $id;
    }

    public function runScheduler(int $limit = 20, string $workerName = 'campaign-scheduler'): array
    {
        $schedules = CampaignDispatchRepository::acquireDueSchedules($workerName, $limit);
        $summary = [
            'processed' => 0,
            'queued' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        foreach ($schedules as $schedule) {
            $summary['processed']++;
            $runId = CampaignDispatchRepository::createRun((int)$schedule['id'], 'scheduler', $workerName, [
                'channel' => $schedule['channel'],
            ]);

            try {
                $user = CampaignDispatchUserResolver::resolve((string)$schedule['tenancy_id'], (int)$schedule['user_id']);
                if (!$user) {
                    throw new \RuntimeException('Usuário do agendamento não encontrado.');
                }

                $payload = json_decode((string)($schedule['payload_json'] ?? ''), true);
                if (!is_array($payload)) {
                    $payload = [];
                }

                $result = match (strtolower((string)$schedule['channel'])) {
                    'sms' => $this->dispatchSms($schedule, $user),
                    'voice' => $this->dispatchVoice($schedule, $payload, $user),
                    'whatsapp' => $this->dispatchWhatsApp($schedule, $user),
                    default => throw new \RuntimeException('Canal de agendamento não suportado: ' . $schedule['channel']),
                };

                $queued = (int)($result['queued_count'] ?? $result['queued'] ?? 0);
                $completedNow = !in_array((string)$schedule['channel'], ['sms'], true);

                if (strtolower((string)$schedule['channel']) === 'sms') {
                    CampaignDispatchRepository::markScheduleQueued((int)$schedule['id']);
                } else {
                    CampaignDispatchRepository::releaseSchedule((int)$schedule['id'], 'completed');
                }

                CampaignDispatchRepository::finishRun(
                    $runId,
                    'completed',
                    $queued,
                    $queued,
                    0
                );

                CampaignDispatchLogger::log(
                    (string)$schedule['channel'],
                    'schedule_dispatched',
                    'Agendamento despachado com sucesso.',
                    array_merge($result, ['schedule_id' => (int)$schedule['id']]),
                    (int)$schedule['id'],
                    $runId,
                    (string)$schedule['tenancy_id'],
                    (int)$schedule['user_id']
                );

                $summary['queued'] += $queued;
                if ($completedNow) {
                    $summary['completed']++;
                }
            } catch (\Throwable $e) {
                CampaignDispatchRepository::releaseSchedule((int)$schedule['id'], 'retry_waiting', $e->getMessage());
                CampaignDispatchRepository::finishRun($runId, 'failed', 0, 0, 1, $e->getMessage());
                CampaignDispatchLogger::log(
                    (string)$schedule['channel'],
                    'schedule_dispatch_failed',
                    'Falha ao despachar agendamento.',
                    [
                        'schedule_id' => (int)$schedule['id'],
                        'error' => $e->getMessage(),
                    ],
                    (int)$schedule['id'],
                    $runId,
                    (string)$schedule['tenancy_id'],
                    (int)$schedule['user_id'],
                    'error'
                );
                $summary['failed']++;
            }
        }

        return $summary;
    }

    public function runRetryWorker(int $limit = 20, string $workerName = 'campaign-retry-worker'): array
    {
        return $this->runScheduler($limit, $workerName);
    }

    private function dispatchSms(array $schedule, array $user): array
    {
        $result = SmsCampaignQueueService::enqueueSchedule($schedule, $user);

        if (!empty($schedule['native_table']) && (string)$schedule['native_table'] === 'campaign' && !empty($result['campaign_id'])) {
            try {
                (new \WilliamCosta\DatabaseManager\Database('campaign'))->update(
                    'id = :id AND tenancy_id = :tenancy_id',
                    ['status' => 'y', 'updated_at' => date('Y-m-d H:i:s')],
                    [
                        ':id' => (int)$result['campaign_id'],
                        ':tenancy_id' => (string)$user['tenancy_id'],
                    ]
                );
            } catch (\Throwable) {
            }
        }

        return $result;
    }

    private function dispatchVoice(array $schedule, array $payload, array $user): array
    {
        return VoiceCampaignDispatchService::dispatchSchedule($schedule, $payload);
    }

    private function dispatchWhatsApp(array $schedule, array $user): array
    {
        $payload = json_decode((string)($schedule['payload_json'] ?? ''), true);
        $campaignId = (int)($payload['campaign_id'] ?? $schedule['native_id'] ?? 0);
        if ($campaignId <= 0) {
            throw new \RuntimeException('Agendamento WhatsApp sem campaign_id.');
        }

        return WhatsAppCampaignDispatchService::enqueueCampaign($user, $campaignId);
    }

    private static function dedupeKey(
        string $channel,
        string $tenancyId,
        string $scheduleName,
        \DateTimeInterface $scheduledAt,
        array $payload,
        ?string $nativeTable,
        mixed $nativeId
    ): string {
        return hash('sha256', json_encode([
            'channel' => strtolower($channel),
            'tenancy_id' => $tenancyId,
            'schedule_name' => $scheduleName,
            'scheduled_at' => $scheduledAt->format(\DateTimeInterface::ATOM),
            'native_table' => $nativeTable,
            'native_id' => $nativeId,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
