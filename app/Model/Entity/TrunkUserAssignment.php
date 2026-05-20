<?php

namespace App\Model\Entity;

use PDO;
use WilliamCosta\DatabaseManager\Database;

class TrunkUserAssignment
{
    private const TABLE = 'trunk_user_assignments';

    public static function preferredForUser(string $tenancyId, int $userId): ?array
    {
        self::ensureTable();

        if ($tenancyId === '' || $userId <= 0) {
            return null;
        }

        $row = (new Database(self::TABLE))->select(
            'tenancy_id = :tenancy_id AND user_id = :user_id AND status = :status AND is_primary = :is_primary',
            [
                ':tenancy_id' => $tenancyId,
                ':user_id' => $userId,
                ':status' => 'active',
                ':is_primary' => 1,
            ],
            'updated_at DESC, id DESC',
            '1'
        )->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function listByTrunk(string $tenancyId, int $trunkApiId): array
    {
        self::ensureTable();

        if ($tenancyId === '' || $trunkApiId <= 0) {
            return [];
        }

        $rows = (new Database(self::TABLE . ' tua INNER JOIN users u ON u.id = tua.user_id AND u.tenancy_id = tua.tenancy_id'))
            ->select(
                'tua.tenancy_id = :tenancy_id AND tua.trunk_api_id = :trunk_api_id AND tua.status = :status',
                [
                    ':tenancy_id' => $tenancyId,
                    ':trunk_api_id' => $trunkApiId,
                    ':status' => 'active',
                ],
                'u.name ASC, u.id ASC',
                null,
                'tua.id, tua.user_id, tua.trunk_api_id, tua.trunk_code, tua.trunk_name, tua.is_primary, tua.created_at, tua.updated_at,
                 u.name, u.last_name, u.email, u.user_function, u.status_account'
            )
            ->fetchAll(PDO::FETCH_ASSOC);

        return $rows ?: [];
    }

    public static function upsertPrimary(
        string $tenancyId,
        int $userId,
        int $trunkApiId,
        string $trunkCode,
        string $trunkName,
        int $actorUserId
    ): array {
        self::ensureTable();

        $db = new Database();
        $now = date('Y-m-d H:i:s');

        $db->execute(
            'UPDATE ' . self::TABLE . '
                SET status = :inactive_status,
                    is_primary = 0,
                    updated_at = :updated_at
              WHERE tenancy_id = :tenancy_id
                AND user_id = :user_id
                AND status = :active_status',
            [
                ':inactive_status' => 'inactive',
                ':updated_at' => $now,
                ':tenancy_id' => $tenancyId,
                ':user_id' => $userId,
                ':active_status' => 'active',
            ]
        );

        $existing = (new Database(self::TABLE))->select(
            'tenancy_id = :tenancy_id AND user_id = :user_id AND trunk_api_id = :trunk_api_id',
            [
                ':tenancy_id' => $tenancyId,
                ':user_id' => $userId,
                ':trunk_api_id' => $trunkApiId,
            ],
            'id DESC',
            '1'
        )->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            (new Database(self::TABLE))->update(
                'id = :id',
                [
                    'trunk_code' => $trunkCode,
                    'trunk_name' => $trunkName,
                    'status' => 'active',
                    'is_primary' => 1,
                    'linked_by_user_id' => $actorUserId,
                    'updated_at' => $now,
                ],
                [':id' => (int)$existing['id']]
            );

            return self::preferredForUser($tenancyId, $userId) ?? [];
        }

        $id = (new Database(self::TABLE))->insert([
            'tenancy_id' => $tenancyId,
            'user_id' => $userId,
            'trunk_api_id' => $trunkApiId,
            'trunk_code' => $trunkCode,
            'trunk_name' => $trunkName,
            'status' => 'active',
            'is_primary' => 1,
            'linked_by_user_id' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id ? (self::preferredForUser($tenancyId, $userId) ?? []) : [];
    }

    public static function removeActiveAssignment(string $tenancyId, int $userId, int $trunkApiId): bool
    {
        self::ensureTable();

        if ($tenancyId === '' || $userId <= 0 || $trunkApiId <= 0) {
            return false;
        }

        return (new Database(self::TABLE))->update(
            'tenancy_id = :tenancy_id AND user_id = :user_id AND trunk_api_id = :trunk_api_id AND status = :status',
            [
                'status' => 'inactive',
                'is_primary' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                ':tenancy_id' => $tenancyId,
                ':user_id' => $userId,
                ':trunk_api_id' => $trunkApiId,
                ':status' => 'active',
            ]
        );
    }

    private static function ensureTable(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        (new Database())->execute("
            CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenancy_id VARCHAR(64) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                trunk_api_id BIGINT UNSIGNED NOT NULL,
                trunk_code VARCHAR(190) NOT NULL DEFAULT '',
                trunk_name VARCHAR(190) NOT NULL DEFAULT '',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                is_primary TINYINT(1) NOT NULL DEFAULT 1,
                linked_by_user_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_trunk_user_primary (tenancy_id, user_id, trunk_api_id),
                KEY idx_trunk_user_active (tenancy_id, trunk_api_id, status),
                KEY idx_trunk_user_user (tenancy_id, user_id, status, is_primary)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $checked = true;
    }
}
