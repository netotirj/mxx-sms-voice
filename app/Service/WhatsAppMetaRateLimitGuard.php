<?php

namespace App\Service;

use WilliamCosta\DatabaseManager\Database;

class WhatsAppMetaRateLimitGuard
{
    public static function currentPause(array $account): ?array
    {
        $row = self::healthRow($account);
        $blockedUntil = (string)($row['blocked_until'] ?? '');
        if ($blockedUntil !== '' && strtotime($blockedUntil) > time()) {
            return [
                'blocked_until' => $blockedUntil,
                'delay_seconds' => max(1, strtotime($blockedUntil) - time()),
            ];
        }

        return null;
    }

    public static function handleMetaResult(array $result, array $account): ?array
    {
        if (empty($result['rate_limited'])) {
            return null;
        }

        self::ensureLogTable();

        $delay = self::backoffSeconds($result, $account);
        $blockedUntil = date('Y-m-d H:i:s', time() + $delay);
        $endpoint = (string)($result['endpoint'] ?? 'unknown');
        $statusCode = (int)($result['status'] ?? 0);
        $errorCode = isset($result['error_code']) ? (string)$result['error_code'] : null;
        $retryAfter = isset($result['retry_after']) ? (int)$result['retry_after'] : null;

        (new Database('whatsapp_meta_rate_limit_logs'))->insert([
            'tenant_id' => (string)($account['tenancy_id'] ?? ''),
            'account_id' => (int)($account['id'] ?? 0),
            'waba_id' => (string)($account['waba_id'] ?? ''),
            'phone_number_id' => (string)($account['phone_number_id'] ?? ''),
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'error_code' => $errorCode,
            'retry_after' => $retryAfter,
            'backoff_seconds' => $delay,
            'blocked_until' => $blockedUntil,
            'error_message' => (string)($result['error'] ?? ''),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        self::pauseNumber($account, $blockedUntil, (string)($result['error'] ?? 'Rate limit da Meta.'));

        return [
            'delay_seconds' => $delay,
            'blocked_until' => $blockedUntil,
            'reason' => 'Rate limit da Meta. Número pausado até ' . $blockedUntil . '.',
        ];
    }

    public static function pauseNumber(array $account, string $blockedUntil, string $reason): void
    {
        self::ensureHealth($account);

        (new Database('whatsapp_number_health'))->update('account_id = :account_id', [
            'blocked_until' => $blockedUntil,
            'recommendation' => $reason,
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':account_id' => (int)$account['id']]);

        (new Database('whatsapp_numbers'))->update('meta_id = :meta_id', [
            'send_blocked_until' => $blockedUntil,
            'last_error' => $reason,
            'last_meta_error' => $reason,
            'last_meta_error_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':meta_id' => (string)$account['phone_number_id']]);
    }

    private static function backoffSeconds(array $result, array $account): int
    {
        $retryAfter = isset($result['retry_after']) ? (int)$result['retry_after'] : 0;
        if ($retryAfter > 0) {
            return min(86400, max(60, $retryAfter));
        }

        $recent = (int)(new Database('whatsapp_meta_rate_limit_logs'))
            ->select(
                'phone_number_id = :phone_number_id AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
                [':phone_number_id' => (string)($account['phone_number_id'] ?? '')],
                '',
                '',
                'COUNT(*) AS total'
            )
            ->fetchColumn();

        return min(86400, 60 * (2 ** min(8, max(0, $recent))));
    }

    private static function healthRow(array $account): array
    {
        self::ensureHealth($account);

        return (new Database('whatsapp_number_health'))
            ->select('account_id = :account_id', [':account_id' => (int)$account['id']], '', '1')
            ->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function ensureHealth(array $account): void
    {
        $exists = (new Database('whatsapp_number_health'))
            ->select('account_id = :account_id', [':account_id' => (int)$account['id']], '', '1', 'account_id')
            ->fetchColumn();

        if ($exists) {
            return;
        }

        (new Database('whatsapp_number_health'))->insert([
            'account_id' => (int)$account['id'],
            'phone_number_id' => (string)$account['phone_number_id'],
            'tenancy_id' => (string)$account['tenancy_id'],
            'quality_status' => 'unknown',
            'current_daily_limit' => 50,
            'sent_today' => 0,
            'sent_last_minute' => 0,
            'sent_last_second' => 0,
            'blocked_until' => null,
            'recommendation' => null,
            'last_meta_sync_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function ensureLogTable(): void
    {
        (new Database())->execute("
            CREATE TABLE IF NOT EXISTS whatsapp_meta_rate_limit_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id VARCHAR(64) NOT NULL,
                account_id BIGINT UNSIGNED NULL,
                waba_id VARCHAR(128) NULL,
                phone_number_id VARCHAR(128) NOT NULL,
                endpoint VARCHAR(255) NOT NULL,
                status_code INT NULL,
                error_code VARCHAR(32) NULL,
                retry_after INT NULL,
                backoff_seconds INT NOT NULL,
                blocked_until DATETIME NOT NULL,
                error_message TEXT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_whatsapp_meta_rate_phone_created (phone_number_id, created_at),
                KEY idx_whatsapp_meta_rate_tenant_created (tenant_id, created_at),
                KEY idx_whatsapp_meta_rate_waba_created (waba_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
