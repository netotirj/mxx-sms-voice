<?php

namespace App\Model\Entity;

use PDO;
use WilliamCosta\DatabaseManager\Database;

class PublicDemoRequest
{
    public static function ensureSchema(): void
    {
        (new Database())->execute("
            CREATE TABLE IF NOT EXISTS public_demo_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                channel VARCHAR(20) NOT NULL,
                phone VARCHAR(20) NOT NULL,
                email VARCHAR(190) NULL,
                ip_address VARCHAR(64) NOT NULL,
                user_agent VARCHAR(255) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'received',
                message VARCHAR(255) NULL,
                turnstile_success TINYINT(1) NOT NULL DEFAULT 0,
                turnstile_error VARCHAR(255) NULL,
                provider_response JSON NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_public_demo_ip_channel_created (ip_address, channel, created_at),
                KEY idx_public_demo_phone_channel_created (phone, channel, created_at),
                KEY idx_public_demo_email_channel_created (email, channel, created_at),
                KEY idx_public_demo_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        self::addColumnIfMissing('public_demo_requests', 'email', 'VARCHAR(190) NULL AFTER phone');
        self::addColumnIfMissing('public_demo_requests', 'turnstile_success', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER message');
        self::addColumnIfMissing('public_demo_requests', 'turnstile_error', 'VARCHAR(255) NULL AFTER turnstile_success');
        self::addIndexIfMissing('public_demo_requests', 'idx_public_demo_email_channel_created', '(email, channel, created_at)');
    }

    public static function create(array $data): int
    {
        self::ensureSchema();

        return (int)(new Database('public_demo_requests'))->insert([
            'channel' => $data['channel'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'ip_address' => $data['ip_address'],
            'user_agent' => mb_substr((string)($data['user_agent'] ?? ''), 0, 255),
            'status' => $data['status'] ?? 'received',
            'message' => $data['message'] ?? null,
            'turnstile_success' => !empty($data['turnstile_success']) ? 1 : 0,
            'turnstile_error' => $data['turnstile_error'] ?? null,
            'provider_response' => isset($data['provider_response'])
                ? json_encode($data['provider_response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
        ]);
    }

    public static function updateResult(int $id, string $status, ?string $message = null, array $providerResponse = []): bool
    {
        self::ensureSchema();

        return (new Database('public_demo_requests'))->update('id = :id', [
            'status' => $status,
            'message' => $message,
            'provider_response' => $providerResponse !== []
                ? json_encode($providerResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
        ], [':id' => $id]);
    }

    public static function countRecent(string $where, array $params, int $hours = 24): int
    {
        self::ensureSchema();

        $params[':since'] = date('Y-m-d H:i:s', time() - ($hours * 3600));
        $row = (new Database('public_demo_requests'))
            ->select("{$where} AND created_at >= :since", $params, '', '1', 'COUNT(*) AS total')
            ->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    private static function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $exists = (new Database())->execute("SHOW COLUMNS FROM {$table} LIKE :column", [
            ':column' => $column,
        ])->fetch(PDO::FETCH_ASSOC);

        if (!$exists) {
            (new Database())->execute("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private static function addIndexIfMissing(string $table, string $index, string $columns): void
    {
        $exists = (new Database())->execute("SHOW INDEX FROM {$table} WHERE Key_name = :index_name", [
            ':index_name' => $index,
        ])->fetch(PDO::FETCH_ASSOC);

        if (!$exists) {
            (new Database())->execute("ALTER TABLE {$table} ADD KEY {$index} {$columns}");
        }
    }
}
