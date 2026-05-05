<?php

namespace App\Model\Entity;

use PDO;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppOutbox
{
    public static function enqueue(array $data): int
    {
        $idempotencyKey = self::idempotencyKey($data);
        $lock = self::acquireIdempotencyLock($idempotencyKey);

        try {
            $existing = self::findRecentDuplicate($data, $idempotencyKey);
            if ($existing > 0) {
                self::auditEnqueue($data, $existing, $idempotencyKey, true);
                return $existing;
            }

            $values = [
            'tenancy_id' => $data['tenancy_id'],
            'user_id' => (int)$data['user_id'],
            'account_id' => (int)$data['account_id'],
            'campaign_id' => $data['campaign_id'] ?? null,
            'campaign_recipient_id' => $data['campaign_recipient_id'] ?? null,
            'conversation_id' => $data['conversation_id'] ?? null,
            'contact_phone' => $data['contact_phone'],
            'contact_name' => $data['contact_name'] ?? null,
            'sequence' => (int)($data['sequence'] ?? 1),
            'message_type' => $data['message_type'],
            'body' => $data['body'] ?? null,
            'template_name' => $data['template_name'] ?? null,
            'template_language' => $data['template_language'] ?? 'pt_BR',
            'template_category' => $data['template_category'] ?? null,
            'template_components' => isset($data['template_components'])
                ? json_encode($data['template_components'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'service_window_open' => (int)($data['service_window_open'] ?? 0),
            'message_category' => isset($data['message_category']) ? strtolower((string)$data['message_category']) : null,
            'price_brl' => (float)($data['price_brl'] ?? 0),
            'billed' => (int)($data['billed'] ?? 0),
            'status' => $data['status'] ?? 'queued',
            'attempts' => 0,
            'max_attempts' => (int)($data['max_attempts'] ?? 3),
            'available_at' => $data['available_at'] ?? date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            ];

            foreach ([
                'idempotency_key' => $idempotencyKey,
                'preview_body' => $data['preview_body'] ?? $data['body'] ?? null,
                'template_variables' => isset($data['template_variables'])
                    ? json_encode($data['template_variables'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'pricing_snapshot' => isset($data['pricing_snapshot'])
                    ? json_encode($data['pricing_snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
            ] as $column => $value) {
                if (self::hasColumn($column)) {
                    $values[$column] = $value;
                }
            }

            $id = (int)(new Database('whatsapp_outbox'))->insert($values);
            self::auditEnqueue($data, $id, $idempotencyKey, false);
            return $id;
        } finally {
            if ($lock['acquired']) {
                self::releaseIdempotencyLock($lock['connection'], $idempotencyKey);
            }
        }
    }

    private static function findRecentDuplicate(array $data, string $idempotencyKey): int
    {
        if (self::hasColumn('idempotency_key')) {
            $row = (new Database('whatsapp_outbox'))->select(
                "idempotency_key = :idempotency_key
                 AND status IN ('queued', 'sending', 'sent')",
                [':idempotency_key' => $idempotencyKey],
                'id DESC',
                '1',
                'id'
            )->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                return (int)$row['id'];
            }
        }

        $row = (new Database('whatsapp_outbox'))->select(
            "tenancy_id = :tenancy_id
             AND user_id = :user_id
             AND account_id = :account_id
             AND contact_phone = :contact_phone
             AND message_type = :message_type
             AND COALESCE(template_name, '') = :template_name
             AND COALESCE(body, '') = :body
             AND status IN ('queued', 'sending', 'sent')
             AND created_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)",
            [
                ':tenancy_id' => (string)$data['tenancy_id'],
                ':user_id' => (int)$data['user_id'],
                ':account_id' => (int)$data['account_id'],
                ':contact_phone' => (string)$data['contact_phone'],
                ':message_type' => (string)$data['message_type'],
                ':template_name' => (string)($data['template_name'] ?? ''),
                ':body' => (string)($data['body'] ?? ''),
            ],
            'id DESC',
            '1',
            'id'
        )->fetch(PDO::FETCH_ASSOC);

        return (int)($row['id'] ?? 0);
    }

    private static function idempotencyKey(array $data): string
    {
        $components = $data['template_components'] ?? null;
        $variables = $data['template_variables'] ?? null;

        return hash('sha256', json_encode([
            'tenancy_id' => (string)($data['tenancy_id'] ?? ''),
            'user_id' => (int)($data['user_id'] ?? 0),
            'account_id' => (int)($data['account_id'] ?? 0),
            'campaign_id' => (int)($data['campaign_id'] ?? 0),
            'campaign_recipient_id' => (int)($data['campaign_recipient_id'] ?? 0),
            'contact_phone' => preg_replace('/\D+/', '', (string)($data['contact_phone'] ?? '')),
            'sequence' => (int)($data['sequence'] ?? 1),
            'message_type' => (string)($data['message_type'] ?? ''),
            'template_name' => (string)($data['template_name'] ?? ''),
            'template_language' => (string)($data['template_language'] ?? 'pt_BR'),
            'body' => (string)($data['body'] ?? ''),
            'components' => $components,
            'variables' => $variables,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function acquireIdempotencyLock(string $key): array
    {
        $connection = new Database();
        $lockName = self::idempotencyLockName($key);
        $acquired = (int)$connection
            ->execute('SELECT GET_LOCK(:lock_name, 5) AS acquired', [':lock_name' => $lockName])
            ->fetchColumn() === 1;

        return ['connection' => $connection, 'acquired' => $acquired];
    }

    private static function releaseIdempotencyLock(Database $connection, string $key): void
    {
        $connection->execute('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => self::idempotencyLockName($key)]);
    }

    private static function idempotencyLockName(string $key): string
    {
        return 'maxx_whatsapp_outbox_' . substr($key, 0, 43);
    }

    private static function auditEnqueue(array $data, int $outboxId, string $idempotencyKey, bool $duplicate): void
    {
        error_log(json_encode([
            'event' => $duplicate ? 'whatsapp_outbox_duplicate_suppressed' : 'whatsapp_outbox_enqueued',
            'outbox_id' => $outboxId,
            'idempotency_key' => $idempotencyKey,
            'conversation_id' => $data['conversation_id'] ?? null,
            'campaign_id' => $data['campaign_id'] ?? null,
            'campaign_recipient_id' => $data['campaign_recipient_id'] ?? null,
            'contact_phone' => self::maskPhoneForLog((string)($data['contact_phone'] ?? '')),
            'template_name' => $data['template_name'] ?? null,
            'message_type' => $data['message_type'] ?? null,
            'preview_body' => $data['preview_body'] ?? $data['body'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function hasColumn(string $column): bool
    {
        static $columns = null;
        if ($columns === null) {
            try {
                $rows = (new Database())->execute('SHOW COLUMNS FROM whatsapp_outbox')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $columns = array_fill_keys(array_map(static fn ($row) => (string)$row['Field'], $rows), true);
            } catch (\Throwable $e) {
                $columns = [];
            }
        }

        return isset($columns[$column]);
    }

    private static function maskPhoneForLog(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone) ?: '';
        if (strlen($phone) <= 6) {
            return '***';
        }

        return substr($phone, 0, 4) . '***' . substr($phone, -2);
    }

    public static function nextDue(int $limit = 50, ?string $cancelCategory = null): array
    {
        $where = "status = 'queued' AND available_at <= NOW()";
        $params = [];

        if ($cancelCategory) {
            $where .= ' AND (template_category IS NULL OR template_category <> :cancel_category)';
            $params[':cancel_category'] = $cancelCategory;
        }

        return (new Database('whatsapp_outbox'))
            ->select($where, $params, 'available_at ASC, id ASC', (string)$limit)
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function resetStaleSending(int $olderThanSeconds = 300): int
    {
        $olderThanSeconds = max(60, $olderThanSeconds);

        return (new Database('whatsapp_outbox'))->execute(
            "UPDATE whatsapp_outbox
             SET status = 'queued',
                 error_message = 'Envio retomado após interrupção do worker.',
                 available_at = NOW(),
                 updated_at = NOW()
             WHERE status = 'sending'
               AND updated_at < DATE_SUB(NOW(), INTERVAL {$olderThanSeconds} SECOND)"
        )->rowCount();
    }

    public static function markSending(int $id): bool
    {
        return (new Database('whatsapp_outbox'))->execute(
            "UPDATE whatsapp_outbox
             SET status = 'sending',
                 updated_at = NOW()
             WHERE id = :id
               AND status = 'queued'",
            [':id' => $id]
        )->rowCount() === 1;
    }

    public static function markSent(int $id, ?string $wamid, ?int $messageId = null): bool
    {
        return (new Database('whatsapp_outbox'))->update(
            'id = :id',
            [
                'status' => 'sent',
                'wamid' => $wamid,
                'message_id' => $messageId,
                'sent_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':id' => $id]
        );
    }

    public static function markBilled(int $id): bool
    {
        return (new Database('whatsapp_outbox'))->update(
            'id = :id',
            [
                'billed' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':id' => $id]
        );
    }

    public static function markFailed(int $id, string $error, int $attempts, int $maxAttempts): bool
    {
        $status = $attempts >= $maxAttempts ? 'failed' : 'queued';
        $delay = min(3600, max(30, 30 * (2 ** max(0, $attempts - 1))));

        return (new Database('whatsapp_outbox'))->update(
            'id = :id',
            [
                'status' => $status,
                'attempts' => $attempts,
                'error_message' => $error,
                'available_at' => $status === 'queued' ? date('Y-m-d H:i:s', time() + $delay) : date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':id' => $id]
        );
    }

    public static function postpone(int $id, int $delaySeconds, string $reason): bool
    {
        $delaySeconds = max(1, min(86400, $delaySeconds));

        return (new Database('whatsapp_outbox'))->update(
            "id = :id AND status IN ('queued', 'sending')",
            [
                'status' => 'queued',
                'error_message' => $reason,
                'available_at' => date('Y-m-d H:i:s', time() + $delaySeconds),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':id' => $id]
        );
    }

    public static function cancelByCampaignAndCategory(int $campaignId, string $category): int
    {
        $sql = "
            UPDATE whatsapp_outbox
            SET status = 'cancelled', updated_at = NOW()
            WHERE campaign_id = :campaign_id
              AND template_category = :category
              AND status IN ('queued', 'sending')
        ";

        return (new Database('whatsapp_outbox'))->execute($sql, [
            ':campaign_id' => $campaignId,
            ':category' => $category,
        ])->rowCount();
    }
}
