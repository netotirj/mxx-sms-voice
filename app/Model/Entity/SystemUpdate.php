<?php

namespace App\Model\Entity;

use PDO;
use WilliamCosta\DatabaseManager\Database;

class SystemUpdate
{
    public static function create(array $user, array $data): int
    {
        self::ensureTable();

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Informe o título da atualização.');
        }

        if (($data['scope'] ?? 'global') === 'user' && (int)($data['target_user_id'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('Selecione o usuário que verá esta atualização.');
        }

        $status = self::normalizeStatus((string)($data['status'] ?? 'planned'));
        $cancellationReason = trim((string)($data['cancellation_reason'] ?? ''));
        if ($status === 'cancelled' && $cancellationReason === '') {
            throw new \InvalidArgumentException('Informe o motivo do cancelamento.');
        }

        return (int)(new Database('system_updates'))->insert([
            'tenancy_id' => self::normalizeScope((string)($data['scope'] ?? 'global'), $user),
            'created_by' => (int)($user['id'] ?? 0),
            'target_user_id' => self::normalizeTargetUserId((string)($data['scope'] ?? 'global'), $data['target_user_id'] ?? null),
            'module' => mb_substr(trim((string)($data['module'] ?? 'Sistema')), 0, 120),
            'category' => self::normalizeCategory((string)($data['category'] ?? 'update')),
            'title' => mb_substr($title, 0, 160),
            'description' => trim((string)($data['description'] ?? '')),
            'status' => $status,
            'visibility' => self::normalizeVisibility((string)($data['visibility'] ?? 'public')),
            'steps_json' => json_encode($status === 'cancelled' ? [] : self::normalizeSteps($data['steps'] ?? []), JSON_UNESCAPED_UNICODE),
            'cancellation_reason' => $status === 'cancelled' ? $cancellationReason : null,
            'cancelled_at' => $status === 'cancelled' ? date('Y-m-d H:i:s') : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function listForAdmin(array $user): array
    {
        self::ensureTable();

        [$where, $params] = self::scope($user);

        return (new Database('system_updates'))
            ->select($where, $params, 'updated_at DESC, id DESC')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function listForHeader(array $user): array
    {
        self::ensureTable();

        return (new Database('system_updates'))
            ->select(
                "visibility = 'public' AND status <> 'cancelled' AND (target_user_id IS NULL OR target_user_id = :target_user_id)",
                [':target_user_id' => (int)($user['id'] ?? 0)],
                'FIELD(status, "in_progress", "planned", "done"), updated_at DESC, id DESC',
                '6'
            )
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function listTargetUsers(array $user): array
    {
        self::ensureTable();

        $role = strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));
        $where = '1=1';
        $params = [];

        if ($role !== 'super_admin') {
            $where = 'u.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = (string)($user['tenancy_id'] ?? '');
        }

        return (new Database('users u LEFT JOIN tenancies t ON t.id = u.tenancy_id'))
            ->select($where, $params, 'u.name ASC, u.id ASC', '', [
                'u.id',
                'u.name',
                'u.last_name',
                'u.email',
                'u.tenancy_id',
                'u.user_function',
                't.name AS tenancy_name',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function update(array $user, int $id, array $data): bool
    {
        self::ensureTable();
        [$where, $params] = self::scope($user);
        $params[':id'] = $id;

        if (($data['scope'] ?? 'global') === 'user' && (int)($data['target_user_id'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('Selecione o usuário que verá esta atualização.');
        }

        $status = self::normalizeStatus((string)($data['status'] ?? 'planned'));
        $cancellationReason = trim((string)($data['cancellation_reason'] ?? ''));
        if ($status === 'cancelled' && $cancellationReason === '') {
            throw new \InvalidArgumentException('Informe o motivo do cancelamento.');
        }

        $values = [
            'module' => mb_substr(trim((string)($data['module'] ?? 'Sistema')), 0, 120),
            'category' => self::normalizeCategory((string)($data['category'] ?? 'update')),
            'title' => mb_substr(trim((string)($data['title'] ?? 'Atualização')), 0, 160),
            'description' => trim((string)($data['description'] ?? '')),
            'status' => $status,
            'visibility' => self::normalizeVisibility((string)($data['visibility'] ?? 'public')),
            'tenancy_id' => self::normalizeScope((string)($data['scope'] ?? $data['tenancy_id'] ?? 'global'), $user),
            'target_user_id' => self::normalizeTargetUserId((string)($data['scope'] ?? 'global'), $data['target_user_id'] ?? null),
            'steps_json' => json_encode($status === 'cancelled' ? [] : self::normalizeSteps($data['steps'] ?? []), JSON_UNESCAPED_UNICODE),
            'cancellation_reason' => $status === 'cancelled' ? $cancellationReason : null,
            'cancelled_at' => $status === 'cancelled' ? date('Y-m-d H:i:s') : null,
            'completed_at' => $status === 'done' ? date('Y-m-d H:i:s') : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        return (bool)(new Database('system_updates'))->update("id = :id AND ({$where})", $values, $params);
    }

    private static function normalizeSteps(mixed $steps): array
    {
        if (is_string($steps)) {
            $steps = preg_split('/\r\n|\r|\n/', $steps) ?: [];
        }

        $normalized = [];
        foreach ((array)$steps as $step) {
            if (is_array($step)) {
                $label = trim((string)($step['label'] ?? ''));
                $done = !empty($step['done']);
            } else {
                $label = trim((string)$step);
                $done = false;
            }

            if ($label !== '') {
                $normalized[] = ['label' => mb_substr($label, 0, 180), 'done' => $done];
            }
        }

        return $normalized;
    }

    private static function normalizeStatus(string $status): string
    {
        return in_array($status, ['planned', 'in_progress', 'done', 'cancelled'], true) ? $status : 'planned';
    }

    private static function normalizeCategory(string $category): string
    {
        return in_array($category, ['bug', 'update', 'correction', 'improvement', 'incident', 'maintenance'], true)
            ? $category
            : 'update';
    }

    private static function normalizeVisibility(string $visibility): string
    {
        return in_array($visibility, ['public', 'internal'], true) ? $visibility : 'public';
    }

    private static function normalizeScope(string $scope, array $user): string
    {
        return 'global';
    }

    private static function normalizeTargetUserId(string $scope, mixed $targetUserId): ?int
    {
        $id = (int)($targetUserId ?? 0);
        return $scope === 'user' && $id > 0 ? $id : null;
    }

    private static function scope(array $user): array
    {
        $role = strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));
        if ($role === 'super_admin') {
            return ['1=1', []];
        }

        return ['tenancy_id = :tenancy_id', [':tenancy_id' => (string)($user['tenancy_id'] ?? '')]];
    }

    private static function ensureTable(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        (new Database())->execute("
            CREATE TABLE IF NOT EXISTS system_updates (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenancy_id VARCHAR(64) NOT NULL DEFAULT 'global',
                created_by INT UNSIGNED NULL,
                target_user_id INT UNSIGNED NULL,
                module VARCHAR(120) NOT NULL DEFAULT 'Sistema',
                category ENUM('bug', 'update', 'correction', 'improvement', 'incident', 'maintenance') NOT NULL DEFAULT 'update',
                title VARCHAR(160) NOT NULL,
                description TEXT NULL,
                status ENUM('planned', 'in_progress', 'done', 'cancelled') NOT NULL DEFAULT 'planned',
                visibility ENUM('public', 'internal') NOT NULL DEFAULT 'public',
                steps_json JSON NULL,
                cancellation_reason TEXT NULL,
                cancelled_at DATETIME NULL,
                completed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_system_updates_tenancy (tenancy_id),
                KEY idx_system_updates_target_user (target_user_id),
                KEY idx_system_updates_category (category),
                KEY idx_system_updates_status (status, visibility),
                KEY idx_system_updates_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        self::ensureTargetUserColumn();
        self::ensureCategoryColumn();
        self::ensureCancellationColumns();

        $checked = true;
    }

    private static function ensureTargetUserColumn(): void
    {
        $exists = (new Database())->execute("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'system_updates'
              AND COLUMN_NAME = 'target_user_id'
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);

        if (!$exists) {
            (new Database())->execute("
                ALTER TABLE system_updates
                    ADD COLUMN target_user_id INT UNSIGNED NULL AFTER created_by,
                    ADD KEY idx_system_updates_target_user (target_user_id)
            ");
        }
    }

    private static function ensureCancellationColumns(): void
    {
        $db = new Database();

        $reasonExists = $db->execute("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'system_updates'
              AND COLUMN_NAME = 'cancellation_reason'
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);

        if (!$reasonExists) {
            $db->execute("
                ALTER TABLE system_updates
                    ADD COLUMN cancellation_reason TEXT NULL AFTER steps_json
            ");
        }

        $cancelledAtExists = $db->execute("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'system_updates'
              AND COLUMN_NAME = 'cancelled_at'
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);

        if (!$cancelledAtExists) {
            $db->execute("
                ALTER TABLE system_updates
                    ADD COLUMN cancelled_at DATETIME NULL AFTER cancellation_reason
            ");
        }
    }

    private static function ensureCategoryColumn(): void
    {
        $db = new Database();

        $exists = $db->execute("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'system_updates'
              AND COLUMN_NAME = 'category'
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);

        if (!$exists) {
            $db->execute("
                ALTER TABLE system_updates
                    ADD COLUMN category ENUM('bug', 'update', 'correction', 'improvement', 'incident', 'maintenance') NOT NULL DEFAULT 'update' AFTER module,
                    ADD KEY idx_system_updates_category (category)
            ");
            return;
        }

        $indexExists = $db->execute("
            SELECT INDEX_NAME
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'system_updates'
              AND INDEX_NAME = 'idx_system_updates_category'
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);

        if (!$indexExists) {
            $db->execute("
                ALTER TABLE system_updates
                    ADD KEY idx_system_updates_category (category)
            ");
        }
    }
}
