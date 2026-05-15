<?php

namespace App\Service;

use App\RedisConn;
use WilliamCosta\DatabaseManager\Database;

class FinancialReconciliationService
{
    public static function auditBalanceConsistency(int $limit = 200): array
    {
        FinancialTransactionService::ensureSchema();

        $rows = (new Database())->execute(
            "SELECT operation_key, tenancy_id, user_id, wallet, source, amount, status, balance_before, balance_after, created_at
             FROM financial_transaction_ledger
             WHERE status <> 'committed'
             ORDER BY created_at DESC
             LIMIT " . max(1, (int)$limit)
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return [
            'scope' => 'balance_consistency',
            'generated_at' => date('Y-m-d H:i:s'),
            'count' => count($rows),
            'rows' => $rows,
        ];
    }

    public static function auditVoiceBilling(int $limit = 200): array
    {
        $rows = (new Database())->execute(
            "SELECT c.id, c.call_id, c.channel_id, c.tenancy_id, c.user_id, c.value, c.dialstatus, c.type, c.created_at
             FROM cdr c
             LEFT JOIN financial_transaction_ledger l
               ON l.related_type = 'voice_call'
              AND l.related_id = c.call_id
              AND l.user_id = c.user_id
              AND l.status = 'committed'
             WHERE c.value > 0
               AND COALESCE(c.call_id, '') <> ''
               AND l.id IS NULL
             ORDER BY c.id DESC
             LIMIT " . max(1, (int)$limit)
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return [
            'scope' => 'voice_billing',
            'generated_at' => date('Y-m-d H:i:s'),
            'count' => count($rows),
            'rows' => $rows,
        ];
    }

    public static function auditSmsBilling(int $limit = 200): array
    {
        $rows = (new Database())->execute(
            "SELECT cb.batch_id,
                    cb.tenancy_id,
                    cb.user_id,
                    SUM(CASE WHEN cb.status_sms IN ('SENT','DELIVERED','UNDELIVERABLE','EXPIRED') THEN 1 ELSE 0 END) AS billable_count,
                    SUM(CASE WHEN cb.status_sms IN ('SENT','DELIVERED','UNDELIVERABLE','EXPIRED') THEN cb.value_sms ELSE 0 END) AS callback_value,
                    MAX(camp.charged) AS charged_status
             FROM callback cb
             LEFT JOIN campaign_batches camp ON camp.id = cb.batch_id
             WHERE cb.batch_id IS NOT NULL
             GROUP BY cb.batch_id, cb.tenancy_id, cb.user_id
             HAVING billable_count > 0
                AND (
                    charged_status IS NULL
                    OR charged_status NOT IN (1,3)
                    OR callback_value <= 0
                )
             ORDER BY cb.batch_id DESC
             LIMIT " . max(1, (int)$limit)
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return [
            'scope' => 'sms_billing',
            'generated_at' => date('Y-m-d H:i:s'),
            'count' => count($rows),
            'rows' => $rows,
        ];
    }

    public static function auditWhatsAppBilling(int $limit = 200): array
    {
        $messageRows = (new Database())->execute(
            "SELECT id, tenancy_id, client_id, wamid, status, billed, price_brl, created_at
             FROM whatsapp_message_cdr
             WHERE status IN ('sent','delivered')
               AND COALESCE(price_brl, 0) > 0
               AND COALESCE(billed, 0) = 0
             ORDER BY id DESC
             LIMIT " . max(1, (int)$limit)
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $callRows = (new Database())->execute(
            "SELECT id, tenancy_id, customer_id, call_id, status, final_price, balance_debited_at, updated_at
             FROM whatsapp_call_cdr
             WHERE COALESCE(final_price, 0) > 0
               AND balance_debited_at IS NULL
             ORDER BY id DESC
             LIMIT " . max(1, (int)$limit)
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return [
            'scope' => 'whatsapp_billing',
            'generated_at' => date('Y-m-d H:i:s'),
            'message_count' => count($messageRows),
            'call_count' => count($callRows),
            'message_rows' => $messageRows,
            'call_rows' => $callRows,
        ];
    }

    public static function auditOrphanActiveCalls(int $limit = 200): array
    {
        try {
            $redis = RedisConn::get();
            $raw = $redis->get('asterisk:active_calls');
            $decoded = json_decode((string)$raw, true);
            $calls = is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return [
                'scope' => 'orphan_active_calls',
                'generated_at' => date('Y-m-d H:i:s'),
                'error' => $e->getMessage(),
                'count' => 0,
                'rows' => [],
            ];
        }

        $rows = [];
        foreach ($calls as $call) {
            if (!is_array($call)) {
                continue;
            }

            $callId = trim((string)($call['call_id'] ?? $call['linkedid'] ?? ''));
            $tenancyId = trim((string)($call['tenant_id'] ?? $call['tenancy_id'] ?? ''));
            if ($callId === '' || $tenancyId === '') {
                continue;
            }

            $exists = (new Database())->execute(
                "SELECT id
                 FROM cdr
                 WHERE call_id = :call_id
                   AND tenancy_id = :tenancy_id
                 ORDER BY id DESC
                 LIMIT 1",
                [
                    ':call_id' => $callId,
                    ':tenancy_id' => $tenancyId,
                ]
            )->fetchColumn();

            if ($exists) {
                continue;
            }

            $rows[] = [
                'call_id' => $callId,
                'tenancy_id' => $tenancyId,
                'channel' => $call['channel'] ?? $call['channel_id'] ?? null,
                'status' => $call['status'] ?? null,
                'updated_at' => $call['updated_at'] ?? null,
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return [
            'scope' => 'orphan_active_calls',
            'generated_at' => date('Y-m-d H:i:s'),
            'count' => count($rows),
            'rows' => $rows,
        ];
    }
}
