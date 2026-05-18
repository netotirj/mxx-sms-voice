<?php

namespace App\Service;

use App\RedisConn;
use WilliamCosta\DatabaseManager\Database;

class FinancialReconciliationService
{
    public static function auditBalanceConsistency(int $limit = 200): array
    {
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
        $db = new Database();
        $safeLimit = max(1, (int)$limit);
        $callKey = "COALESCE(NULLIF(c.call_id, ''), c.channel_id)";
        $voiceTypeFilter = "LOWER(COALESCE(c.type, 'voice')) IN ('normal', 'voice', 'outbound', 'inbound')";
        $billableFilter = "COALESCE(c.final_price, c.value, 0) > 0";

        $missingLedger = $db->execute(
            "SELECT
                c.tenancy_id,
                c.user_id,
                {$callKey} AS call_key,
                MAX(c.call_id) AS call_id,
                MAX(c.channel_id) AS channel_id,
                MAX(c.destination) AS destination,
                MAX(c.dialstatus) AS dialstatus,
                MAX(c.trunk_billing_type) AS trunk_billing_type,
                MAX(COALESCE(c.billsec, c.duration, 0)) AS billed_seconds,
                MAX(COALESCE(c.final_price, c.value, 0)) AS cdr_amount,
                COUNT(*) AS cdr_rows
             FROM cdr c
             LEFT JOIN financial_transaction_ledger l
               ON l.related_type = 'voice_call'
              AND l.related_id = {$callKey}
              AND l.status = 'committed'
             WHERE {$billableFilter}
               AND {$voiceTypeFilter}
             GROUP BY c.tenancy_id, c.user_id, {$callKey}
             HAVING COUNT(DISTINCT l.id) = 0
             ORDER BY MAX(c.created_at) DESC
             LIMIT {$safeLimit}"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $valueMismatch = $db->execute(
            "SELECT
                c.tenancy_id,
                c.user_id,
                {$callKey} AS call_key,
                MAX(c.call_id) AS call_id,
                MAX(c.channel_id) AS channel_id,
                MAX(c.destination) AS destination,
                MAX(c.dialstatus) AS dialstatus,
                MAX(COALESCE(c.final_price, c.value, 0)) AS cdr_amount,
                COALESCE(SUM(CASE WHEN l.status = 'committed' THEN l.amount ELSE 0 END), 0) AS ledger_amount,
                COUNT(DISTINCT CASE WHEN l.status = 'committed' THEN l.id END) AS committed_ledger_rows
             FROM cdr c
             LEFT JOIN financial_transaction_ledger l
               ON l.related_type = 'voice_call'
              AND l.related_id = {$callKey}
             WHERE {$billableFilter}
               AND {$voiceTypeFilter}
             GROUP BY c.tenancy_id, c.user_id, {$callKey}
             HAVING COUNT(DISTINCT CASE WHEN l.status = 'committed' THEN l.id END) > 0
                AND ROUND(MAX(COALESCE(c.final_price, c.value, 0)), 4) <> ROUND(COALESCE(SUM(CASE WHEN l.status = 'committed' THEN l.amount ELSE 0 END), 0), 4)
             ORDER BY MAX(c.created_at) DESC
             LIMIT {$safeLimit}"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $ledgerWithoutCdr = $db->execute(
            "SELECT
                l.operation_key,
                l.related_id AS call_key,
                l.tenancy_id,
                l.user_id,
                l.source,
                l.amount,
                l.processed_at
             FROM financial_transaction_ledger l
             LEFT JOIN cdr c
               ON {$callKey} = l.related_id
              AND c.tenancy_id = l.tenancy_id
              AND {$voiceTypeFilter}
             WHERE l.related_type = 'voice_call'
               AND l.status = 'committed'
               AND l.source IN ('voice_cdr_usage', 'voice_service_fee', 'voice_cdr')
               AND c.id IS NULL
             ORDER BY l.processed_at DESC
             LIMIT {$safeLimit}"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $duplicateLedger = $db->execute(
            "SELECT
                l.tenancy_id,
                l.user_id,
                l.related_id AS call_key,
                COUNT(*) AS ledger_rows,
                SUM(l.amount) AS ledger_amount,
                MAX(l.processed_at) AS latest_processed_at
             FROM financial_transaction_ledger l
             WHERE l.related_type = 'voice_call'
               AND l.status = 'committed'
               AND l.source IN ('voice_cdr_usage', 'voice_service_fee', 'voice_cdr')
             GROUP BY l.tenancy_id, l.user_id, l.related_id
             HAVING COUNT(*) > 1
             ORDER BY latest_processed_at DESC
             LIMIT {$safeLimit}"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return [
            'scope' => 'voice_billing',
            'generated_at' => date('Y-m-d H:i:s'),
            'missing_ledger_count' => count($missingLedger),
            'value_mismatch_count' => count($valueMismatch),
            'ledger_without_cdr_count' => count($ledgerWithoutCdr),
            'duplicate_ledger_count' => count($duplicateLedger),
            'missing_ledger_rows' => $missingLedger,
            'value_mismatch_rows' => $valueMismatch,
            'ledger_without_cdr_rows' => $ledgerWithoutCdr,
            'duplicate_ledger_rows' => $duplicateLedger,
        ];
    }

    public static function auditSmsBilling(int $limit = 200): array
    {
        $db = new Database();
        $safeLimit = max(1, (int)$limit);
        $billableStatus = "('SENT','DELIVERED','UNDELIVERABLE','EXPIRED')";

        $rows = $db->execute(
            "SELECT
                cb.batch_id,
                cb.tenancy_id,
                cb.user_id,
                SUM(CASE WHEN UPPER(COALESCE(cb.status_sms, '')) IN {$billableStatus} THEN 1 ELSE 0 END) AS callback_billable_count,
                ROUND(SUM(CASE WHEN UPPER(COALESCE(cb.status_sms, '')) IN {$billableStatus} THEN COALESCE(cb.value_sms, 0) ELSE 0 END), 4) AS callback_value,
                COUNT(DISTINCT CASE WHEN l.status = 'committed' THEN l.id END) AS ledger_rows,
                ROUND(COALESCE(SUM(CASE WHEN l.status = 'committed' THEN l.amount ELSE 0 END), 0), 4) AS ledger_value,
                MAX(camp.charged) AS charged_status
             FROM callback cb
             LEFT JOIN campaign_batches camp
               ON camp.id = cb.batch_id
             LEFT JOIN financial_transaction_ledger l
               ON l.related_type = 'sms_batch'
              AND l.related_id = CAST(cb.batch_id AS CHAR)
              AND l.source = 'sms_batch_callback'
             WHERE cb.batch_id IS NOT NULL
             GROUP BY cb.batch_id, cb.tenancy_id, cb.user_id
             HAVING callback_billable_count > 0
                AND ROUND(callback_value, 4) <> ROUND(ledger_value, 4)
             ORDER BY cb.batch_id DESC
             LIMIT {$safeLimit}"
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
        $db = new Database();
        $safeLimit = max(1, (int)$limit);

        $messageRows = $db->execute(
            "SELECT
                c.id,
                c.tenancy_id,
                c.client_id,
                c.wamid,
                c.status,
                c.billed,
                ROUND(COALESCE(c.final_price_brl, c.price_brl, 0), 4) AS cdr_amount,
                ROUND(COALESCE(SUM(CASE WHEN l.status = 'committed' THEN l.amount ELSE 0 END), 0), 4) AS ledger_amount,
                COUNT(DISTINCT CASE WHEN l.status = 'committed' THEN l.id END) AS ledger_rows,
                c.created_at
             FROM whatsapp_message_cdr c
             LEFT JOIN financial_transaction_ledger l
               ON l.related_type = 'whatsapp_message'
              AND l.related_id = c.wamid
             WHERE c.status IN ('sent', 'delivered')
               AND COALESCE(c.final_price_brl, c.price_brl, 0) > 0
             GROUP BY c.id, c.tenancy_id, c.client_id, c.wamid, c.status, c.billed, c.final_price_brl, c.price_brl, c.created_at
             HAVING ROUND(cdr_amount, 4) <> ROUND(ledger_amount, 4)
             ORDER BY c.id DESC
             LIMIT {$safeLimit}"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $callRows = $db->execute(
            "SELECT
                c.id,
                c.tenancy_id,
                c.customer_id,
                c.call_id,
                c.status,
                ROUND(COALESCE(c.final_price, 0), 4) AS cdr_amount,
                ROUND(COALESCE(SUM(CASE WHEN l.status = 'committed' THEN l.amount ELSE 0 END), 0), 4) AS ledger_amount,
                COUNT(DISTINCT CASE WHEN l.status = 'committed' THEN l.id END) AS ledger_rows,
                c.balance_debited_at,
                c.updated_at
             FROM whatsapp_call_cdr c
             LEFT JOIN financial_transaction_ledger l
               ON l.related_type = 'whatsapp_voice_call'
              AND l.related_id = c.call_id
             WHERE COALESCE(c.final_price, 0) > 0
             GROUP BY c.id, c.tenancy_id, c.customer_id, c.call_id, c.status, c.final_price, c.balance_debited_at, c.updated_at
             HAVING ROUND(cdr_amount, 4) <> ROUND(ledger_amount, 4)
             ORDER BY c.id DESC
             LIMIT {$safeLimit}"
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
