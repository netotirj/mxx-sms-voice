<?php

namespace App\Model\Entity;

use PDO;
use WilliamCosta\DatabaseManager\Database;

class SipMonitorLog
{
    private const TABLE = 'sip_monitor_logs';

    public static function ensureTable(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        (new Database())->execute("
            CREATE TABLE IF NOT EXISTS sip_monitor_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                session_id BIGINT UNSIGNED NOT NULL,
                source VARCHAR(24) NOT NULL,
                line VARCHAR(1000) NOT NULL,
                detected_call_id VARCHAR(255) NULL,
                detected_method VARCHAR(32) NULL,
                detected_status VARCHAR(32) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_sip_monitor_logs_session (session_id, created_at),
                KEY idx_sip_monitor_logs_source (source, created_at),
                KEY idx_sip_monitor_logs_call_id (detected_call_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $checked = true;
    }

    public static function countBySession(int $sessionId): int
    {
        self::ensureTable();

        $row = (new Database(self::TABLE))
            ->select('session_id = :session_id', [':session_id' => $sessionId], '', '1', 'COUNT(*) AS total')
            ->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    public static function insertMany(array $rows): void
    {
        self::ensureTable();
        if ($rows === []) {
            return;
        }

        $db = new Database(self::TABLE);
        foreach ($rows as $row) {
            $db->insert($row);
        }
    }

    public static function latestBySession(int $sessionId, int $limit = 200): array
    {
        self::ensureTable();

        return (new Database(self::TABLE))
            ->select(
                'session_id = :session_id',
                [':session_id' => $sessionId],
                'id DESC',
                (string)max(1, min(1000, $limit))
            )
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
