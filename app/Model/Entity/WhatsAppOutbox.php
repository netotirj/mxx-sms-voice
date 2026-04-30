<?php

namespace App\Model\Entity;

use PDO;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppOutbox
{
    public static function enqueue(array $data): int
    {
        return (int)(new Database('whatsapp_outbox'))->insert([
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
            'billable_estimate' => (int)($data['billable_estimate'] ?? 0),
            'estimated_cost_usd' => (float)($data['estimated_cost_usd'] ?? 0),
            'status' => $data['status'] ?? 'queued',
            'attempts' => 0,
            'max_attempts' => (int)($data['max_attempts'] ?? 3),
            'available_at' => $data['available_at'] ?? date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
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
