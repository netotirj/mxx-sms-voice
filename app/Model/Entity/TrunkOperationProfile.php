<?php

namespace App\Model\Entity;

use PDO;
use WilliamCosta\DatabaseManager\Database;

class TrunkOperationProfile
{
    private const TABLE = 'trunk_operation_profiles';

    public static function upsert(
        string $tenancyId,
        int $trunkApiId,
        string $trunkCode,
        string $trunkName,
        int $manualPriority,
        bool $isOperationTrunk,
        int $actorUserId
    ): bool {
        self::ensureTable();

        if ($tenancyId === '' || $trunkApiId <= 0) {
            return false;
        }

        $existing = self::findByTrunk($tenancyId, $trunkApiId);
        $now = date('Y-m-d H:i:s');

        if ($isOperationTrunk) {
            (new Database())->execute(
                'UPDATE ' . self::TABLE . '
                    SET is_operation_trunk = 0,
                        updated_at = :updated_at
                  WHERE tenancy_id = :tenancy_id',
                [
                    ':updated_at' => $now,
                    ':tenancy_id' => $tenancyId,
                ]
            );
        }

        if ($existing) {
            return (bool)(new Database(self::TABLE))->update(
                'id = :id',
                [
                    'trunk_code' => $trunkCode,
                    'trunk_name' => $trunkName,
                    'manual_priority' => $manualPriority,
                    'is_operation_trunk' => $isOperationTrunk ? 1 : 0,
                    'updated_by_user_id' => $actorUserId,
                    'updated_at' => $now,
                ],
                [':id' => (int)$existing['id']]
            );
        }

        return ((int)(new Database(self::TABLE))->insert([
            'tenancy_id' => $tenancyId,
            'trunk_api_id' => $trunkApiId,
            'trunk_code' => $trunkCode,
            'trunk_name' => $trunkName,
            'manual_priority' => $manualPriority,
            'is_operation_trunk' => $isOperationTrunk ? 1 : 0,
            'updated_by_user_id' => $actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ])) > 0;
    }

    public static function mapByTenancy(string $tenancyId): array
    {
        self::ensureTable();

        if ($tenancyId === '') {
            return [];
        }

        $rows = (new Database(self::TABLE))->select(
            'tenancy_id = :tenancy_id',
            [':tenancy_id' => $tenancyId],
            'manual_priority DESC, updated_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(string)($row['trunk_api_id'] ?? '')] = $row;
        }

        return $map;
    }

    public static function operationTrunk(string $tenancyId): ?array
    {
        self::ensureTable();

        if ($tenancyId === '') {
            return null;
        }

        $row = (new Database(self::TABLE))->select(
            'tenancy_id = :tenancy_id AND is_operation_trunk = :is_operation_trunk',
            [
                ':tenancy_id' => $tenancyId,
                ':is_operation_trunk' => 1,
            ],
            'updated_at DESC, id DESC',
            '1'
        )->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function findByTrunk(string $tenancyId, int $trunkApiId): ?array
    {
        self::ensureTable();

        $row = (new Database(self::TABLE))->select(
            'tenancy_id = :tenancy_id AND trunk_api_id = :trunk_api_id',
            [
                ':tenancy_id' => $tenancyId,
                ':trunk_api_id' => $trunkApiId,
            ],
            'id DESC',
            '1'
        )->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
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
                trunk_api_id BIGINT UNSIGNED NOT NULL,
                trunk_code VARCHAR(190) NOT NULL DEFAULT '',
                trunk_name VARCHAR(190) NOT NULL DEFAULT '',
                manual_priority INT NOT NULL DEFAULT 0,
                is_operation_trunk TINYINT(1) NOT NULL DEFAULT 0,
                updated_by_user_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_trunk_operation_profile (tenancy_id, trunk_api_id),
                KEY idx_trunk_operation_priority (tenancy_id, manual_priority),
                KEY idx_trunk_operation_flag (tenancy_id, is_operation_trunk)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $checked = true;
    }
}
