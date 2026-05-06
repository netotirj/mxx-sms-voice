<?php

namespace App\Model\Entity;

use App\Utils\TenancyHelper;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppCampaign
{
    public static function create(array $data): int
    {
        return (int) (new Database('whatsapp_campaigns'))->insert([
            'tenancy_id' => $data['tenancy_id'],
            'user_id' => $data['user_id'],
            'account_id' => $data['account_id'],
            'name' => $data['name'],
            'message_type' => $data['message_type'],
            'message_body' => $data['message_body'] ?? null,
            'template_name' => $data['template_name'] ?? null,
            'template_language' => $data['template_language'] ?? null,
            'template_category' => $data['template_category'] ?? null,
            'template_components' => isset($data['template_components'])
                ? json_encode($data['template_components'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'total_recipients' => $data['total_recipients'] ?? 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'status' => $data['status'] ?? 'draft',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function listForUser(array $user): array
    {
        $where = TenancyHelper::applySecurityFilter('', $user, 'user_id', 'wc');

        return (new Database('whatsapp_campaigns wc LEFT JOIN whatsapp_accounts wa ON wa.id = wc.account_id AND wa.tenancy_id = wc.tenancy_id'))
            ->select($where, [], 'wc.id DESC', '', [
                'wc.*',
                'wa.label AS account_label',
                'wa.display_phone_number AS account_phone',
                "(SELECT COALESCE(SUM(cdr.price_brl), 0)
                    FROM whatsapp_message_cdr cdr
                    INNER JOIN whatsapp_outbox wo ON wo.id = cdr.whatsapp_outbox_id
                    WHERE wo.campaign_id = wc.id
                      AND cdr.billed = 1) AS billed_cost_brl",
                "(SELECT COUNT(*)
                    FROM whatsapp_message_cdr cdr
                    INNER JOIN whatsapp_outbox wo ON wo.id = cdr.whatsapp_outbox_id
                    WHERE wo.campaign_id = wc.id
                      AND cdr.billed = 1) AS billed_message_count",
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getForUser(int $id, array $user): ?array
    {
        $where = TenancyHelper::applySecurityFilter('id = :id', $user, 'user_id', 'whatsapp_campaigns');
        $row = (new Database('whatsapp_campaigns'))
            ->select($where, [':id' => $id], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function addRecipient(int $campaignId, array $recipient): int
    {
        $values = [
            'campaign_id' => $campaignId,
            'phone' => $recipient['phone'],
            'name' => $recipient['name'] ?? null,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (self::recipientColumnExists('template_variables')) {
            $values['template_variables'] = isset($recipient['template_variables'])
                ? json_encode($recipient['template_variables'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
        }

        return (int) (new Database('whatsapp_campaign_recipients'))->insert($values);
    }

    public static function getPendingRecipients(int $campaignId): array
    {
        return (new Database('whatsapp_campaign_recipients'))
            ->select("campaign_id = :campaign_id AND status IN ('pending', 'failed')", [':campaign_id' => $campaignId], 'id ASC')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function updateRecipientResult(int $id, string $status, ?string $wamid = null, ?string $error = null): bool
    {
        if ($status === 'queued' && !self::recipientStatusSupportsQueued()) {
            return true;
        }

        return (new Database('whatsapp_campaign_recipients'))->update(
            'id = :id',
            [
                'status' => $status,
                'wamid' => $wamid,
                'error_message' => $error,
                'sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':id' => $id]
        );
    }

    private static function recipientStatusSupportsQueued(): bool
    {
        static $supports = null;
        if ($supports === null) {
            try {
                $row = (new Database())->execute('SHOW COLUMNS FROM whatsapp_campaign_recipients LIKE "status"')
                    ->fetch(PDO::FETCH_ASSOC);
                $supports = is_array($row) && str_contains((string)($row['Type'] ?? ''), "'queued'");
            } catch (\Throwable $e) {
                $supports = false;
            }
        }

        return $supports;
    }

    private static function recipientColumnExists(string $column): bool
    {
        static $columns = null;
        if ($columns === null) {
            try {
                $rows = (new Database())->execute('SHOW COLUMNS FROM whatsapp_campaign_recipients')
                    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $columns = array_fill_keys(array_map(static fn ($row) => (string)$row['Field'], $rows), true);
            } catch (\Throwable $e) {
                $columns = [];
            }
        }

        return isset($columns[$column]);
    }

    public static function updateCounters(int $campaignId): bool
    {
        $sql = "
            UPDATE whatsapp_campaigns wc
            SET
                sent_count = (
                    SELECT COUNT(*) FROM whatsapp_campaign_recipients r
                    WHERE r.campaign_id = wc.id AND r.status = 'sent'
                ),
                failed_count = (
                    SELECT COUNT(*) FROM whatsapp_campaign_recipients r
                    WHERE r.campaign_id = wc.id AND r.status = 'failed'
                ),
                status = CASE
                    WHEN total_recipients > 0
                     AND total_recipients = (
                        SELECT COUNT(*) FROM whatsapp_campaign_recipients r
                        WHERE r.campaign_id = wc.id AND r.status IN ('sent', 'failed')
                     )
                    THEN 'finished'
                    ELSE status
                END,
                updated_at = NOW()
            WHERE wc.id = :campaign_id
        ";

        (new Database('whatsapp_campaigns'))->execute($sql, [':campaign_id' => $campaignId]);
        return true;
    }

    public static function markStatus(int $campaignId, string $status): bool
    {
        return (new Database('whatsapp_campaigns'))->update(
            'id = :id',
            ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')],
            [':id' => $campaignId]
        );
    }
}
