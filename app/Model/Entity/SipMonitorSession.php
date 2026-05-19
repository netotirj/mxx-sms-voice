<?php

namespace App\Model\Entity;

use PDO;
use WilliamCosta\DatabaseManager\Database;

class SipMonitorSession
{
    private const TABLE = 'sip_monitor_sessions';

    public static function ensureTable(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        (new Database())->execute("
            CREATE TABLE IF NOT EXISTS sip_monitor_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenancy_id VARCHAR(64) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                mode VARCHAR(24) NOT NULL,
                resolved_mode VARCHAR(24) NOT NULL DEFAULT 'auto',
                filter_type VARCHAR(32) NOT NULL DEFAULT 'all',
                filter_value VARCHAR(191) NULL,
                network_interface VARCHAR(64) NULL,
                port INT UNSIGNED NOT NULL DEFAULT 5060,
                tls_port INT UNSIGNED NULL,
                include_tls TINYINT(1) NOT NULL DEFAULT 0,
                duration_minutes INT UNSIGNED NOT NULL DEFAULT 5,
                status VARCHAR(24) NOT NULL DEFAULT 'starting',
                pid INT NULL,
                notes LONGTEXT NULL,
                started_at DATETIME NOT NULL,
                ended_at DATETIME NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_sip_monitor_active (status, expires_at),
                KEY idx_sip_monitor_user (tenancy_id, user_id, status),
                KEY idx_sip_monitor_started (started_at),
                KEY idx_sip_monitor_resolved_mode (resolved_mode, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $checked = true;
    }

    public static function create(array $payload): int
    {
        self::ensureTable();

        return (int)(new Database(self::TABLE))->insert($payload);
    }

    public static function update(int $id, array $values): bool
    {
        self::ensureTable();

        $values['updated_at'] = $values['updated_at'] ?? date('Y-m-d H:i:s');
        return (new Database(self::TABLE))->update('id = :id', $values, [':id' => $id]);
    }

    public static function findById(int $id): ?array
    {
        self::ensureTable();

        $row = (new Database(self::TABLE))
            ->select('id = :id', [':id' => $id], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function findActiveByUser(string $tenancyId, int $userId): ?array
    {
        self::ensureTable();

        $row = (new Database(self::TABLE))
            ->select(
                'tenancy_id = :tenancy_id AND user_id = :user_id AND (status = :starting OR status = :running)',
                [
                    ':tenancy_id' => $tenancyId,
                    ':user_id' => $userId,
                    ':starting' => 'starting',
                    ':running' => 'running',
                ],
                'id DESC',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function countActive(): int
    {
        self::ensureTable();

        $row = (new Database(self::TABLE))
            ->select(
                'status = :starting OR status = :running',
                [
                    ':starting' => 'starting',
                    ':running' => 'running',
                ],
                '',
                '1',
                'COUNT(*) AS total'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    public static function listExpiredActive(string $now): array
    {
        self::ensureTable();

        return (new Database(self::TABLE))
            ->select(
                '(status = :starting OR status = :running) AND expires_at <= :now',
                [
                    ':starting' => 'starting',
                    ':running' => 'running',
                    ':now' => $now,
                ],
                'expires_at ASC, id ASC'
            )
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function listRecent(int $limit = 10): array
    {
        self::ensureTable();

        return (new Database(self::TABLE))
            ->select('1 = 1', [], 'id DESC', (string)max(1, min(50, $limit)))
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
