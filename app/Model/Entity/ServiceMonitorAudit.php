<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;

class ServiceMonitorAudit
{
    public static function record(array $user, string $action, string $serviceName, ?string $ipAddress = null, array $context = []): void
    {
        self::ensureTable();

        (new Database('service_monitor_audit_logs'))->insert([
            'user_id' => (int)($user['id'] ?? 0),
            'tenancy_id' => (string)($user['tenancy_id'] ?? ''),
            'action' => mb_substr(trim($action), 0, 80),
            'service_name' => mb_substr(trim($serviceName), 0, 160),
            'ip_address' => $ipAddress ? mb_substr(trim($ipAddress), 0, 64) : null,
            'context_json' => $context !== [] ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function ensureTable(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        (new Database())->execute("
            CREATE TABLE IF NOT EXISTS service_monitor_audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NULL,
                tenancy_id VARCHAR(64) NOT NULL,
                action VARCHAR(80) NOT NULL,
                service_name VARCHAR(160) NOT NULL,
                ip_address VARCHAR(64) NULL,
                context_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_service_monitor_audit_user (user_id, created_at),
                KEY idx_service_monitor_audit_tenancy (tenancy_id, created_at),
                KEY idx_service_monitor_audit_service (service_name, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $checked = true;
    }
}
