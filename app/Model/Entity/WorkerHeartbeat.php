<?php

namespace App\Model\Entity;

use PDO;
use WilliamCosta\DatabaseManager\Database;

class WorkerHeartbeat
{
    public static function record(
        string $serviceName,
        string $workerType,
        string $lastStatus,
        string $lastMessage = '',
        int $processedDelta = 0,
        int $failedDelta = 0,
        ?float $memoryMb = null
    ): void {
        self::ensureTable();

        $serviceName = trim($serviceName);
        $workerType = trim($workerType);
        if ($serviceName === '' || $workerType === '') {
            return;
        }

        $status = mb_substr(trim($lastStatus), 0, 120);
        $message = mb_substr(trim($lastMessage), 0, 1000);
        $memoryValue = $memoryMb !== null ? round(max(0, $memoryMb), 2) : null;
        $now = date('Y-m-d H:i:s');

        (new Database())->execute(
            "
            INSERT INTO worker_heartbeats (
                service_name,
                worker_type,
                last_seen_at,
                last_status,
                last_message,
                processed_count,
                failed_count,
                memory_mb,
                created_at,
                updated_at
            ) VALUES (
                :service_name,
                :worker_type,
                :last_seen_at,
                :last_status,
                :last_message,
                :processed_count,
                :failed_count,
                :memory_mb,
                :created_at,
                :updated_at
            )
            ON DUPLICATE KEY UPDATE
                worker_type = VALUES(worker_type),
                last_seen_at = VALUES(last_seen_at),
                last_status = VALUES(last_status),
                last_message = VALUES(last_message),
                processed_count = processed_count + VALUES(processed_count),
                failed_count = failed_count + VALUES(failed_count),
                memory_mb = VALUES(memory_mb),
                updated_at = VALUES(updated_at)
            ",
            [
                ':service_name' => $serviceName,
                ':worker_type' => $workerType,
                ':last_seen_at' => $now,
                ':last_status' => $status,
                ':last_message' => $message,
                ':processed_count' => max(0, $processedDelta),
                ':failed_count' => max(0, $failedDelta),
                ':memory_mb' => $memoryValue,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );
    }

    public static function listByServices(array $serviceNames): array
    {
        self::ensureTable();

        $serviceNames = array_values(array_filter(array_map(
            static fn ($value): string => trim((string)$value),
            $serviceNames
        )));

        if ($serviceNames === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($serviceNames as $index => $serviceName) {
            $placeholder = ':service_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $serviceName;
        }

        $rows = (new Database())->execute(
            'SELECT *
               FROM worker_heartbeats
              WHERE service_name IN (' . implode(', ', $placeholders) . ')
              ORDER BY updated_at DESC',
            $params
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string)($row['service_name'] ?? '')] = $row;
        }

        return $indexed;
    }

    private static function ensureTable(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        (new Database())->execute("
            CREATE TABLE IF NOT EXISTS worker_heartbeats (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                service_name VARCHAR(160) NOT NULL,
                worker_type VARCHAR(80) NOT NULL,
                last_seen_at DATETIME NOT NULL,
                last_status VARCHAR(120) NOT NULL DEFAULT 'unknown',
                last_message VARCHAR(1000) NULL,
                processed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                failed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                memory_mb DECIMAL(10,2) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_worker_heartbeats_service (service_name),
                KEY idx_worker_heartbeats_worker_type (worker_type),
                KEY idx_worker_heartbeats_seen (last_seen_at),
                KEY idx_worker_heartbeats_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $checked = true;
    }
}
