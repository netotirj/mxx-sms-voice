<?php

namespace App\Service;

use App\Model\Entity\Notifications;
use App\Model\Entity\PermissionsRules;
use App\Model\Entity\SupportTicket;
use App\Model\Entity\SystemUpdate;
use WilliamCosta\DatabaseManager\Database;

class SystemSchemaMaintenance
{
    public static function syncPermissionsSchema(): array
    {
        $db = new Database();
        $changes = [];

        self::ensureRouteColumn(
            PermissionsRules::columnAccessScope(),
            "ALTER TABLE sys_routes ADD COLUMN " . PermissionsRules::columnAccessScope() . " VARCHAR(20) NOT NULL DEFAULT 'tenant' AFTER route_path",
            $changes
        );
        self::ensureRouteColumn(
            PermissionsRules::columnAssignableBy(),
            "ALTER TABLE sys_routes ADD COLUMN " . PermissionsRules::columnAssignableBy() . " VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER " . PermissionsRules::columnAccessScope(),
            $changes
        );

        $db->execute(
            "UPDATE sys_routes
             SET " . PermissionsRules::columnAccessScope() . " = 'tenant',
                 " . PermissionsRules::columnAssignableBy() . " = 'admin'
             WHERE " . PermissionsRules::columnAccessScope() . " IS NULL
                OR " . PermissionsRules::columnAccessScope() . " = ''
                OR " . PermissionsRules::columnAssignableBy() . " IS NULL
                OR " . PermissionsRules::columnAssignableBy() . " = ''"
        );
        $changes[] = 'routes_governance_defaults';

        foreach (PermissionsRules::superAdminOnlyRoutePrefixes() as $prefix) {
            $normalizedPrefix = PermissionsRules::normalizeGovernancePrefixPublic($prefix);
            $db->execute(
                "UPDATE sys_routes
                 SET " . PermissionsRules::columnAccessScope() . " = 'platform',
                     " . PermissionsRules::columnAssignableBy() . " = 'super_admin'
                 WHERE route_path = :prefix
                    OR route_path LIKE :prefix_like",
                [
                    ':prefix' => $normalizedPrefix,
                    ':prefix_like' => $normalizedPrefix . '%',
                ]
            );
        }
        $changes[] = 'routes_superadmin_scope_sync';

        PermissionsRules::syncRouteModuleGrouping();
        $changes[] = 'routes_module_grouping_sync';

        $db->execute("
            CREATE TABLE IF NOT EXISTS sys_role_templates (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                label VARCHAR(150) NOT NULL,
                description VARCHAR(255) NULL,
                status CHAR(1) NOT NULL DEFAULT 'y',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_sys_role_templates_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $changes[] = 'create_sys_role_templates';

        $db->execute("
            CREATE TABLE IF NOT EXISTS sys_role_template_permissions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                template_id INT UNSIGNED NOT NULL,
                route_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_sys_role_template_permissions (template_id, route_id),
                KEY idx_sys_role_template_permissions_route (route_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $changes[] = 'create_sys_role_template_permissions';

        self::ensureSysRolesColumn(
            PermissionsRules::columnRoleTemplateId(),
            "ALTER TABLE sys_roles ADD COLUMN " . PermissionsRules::columnRoleTemplateId() . " INT UNSIGNED NULL AFTER user_id",
            $changes
        );
        self::ensureSysRolesColumn(
            PermissionsRules::columnInheritsTemplate(),
            "ALTER TABLE sys_roles ADD COLUMN " . PermissionsRules::columnInheritsTemplate() . " CHAR(1) NOT NULL DEFAULT 'n' AFTER " . PermissionsRules::columnRoleTemplateId(),
            $changes
        );

        self::ensureIndex(
            'sys_roles',
            'idx_sys_roles_tenancy',
            "ALTER TABLE sys_roles ADD INDEX idx_sys_roles_tenancy (tenancy_id)",
            $changes
        );
        self::ensureIndex(
            'user_roles',
            'idx_user_roles_user_tenancy',
            "ALTER TABLE user_roles ADD INDEX idx_user_roles_user_tenancy (user_id, tenancy_id)",
            $changes
        );

        PermissionsRules::seedRoleTemplates();

        return $changes;
    }

    public static function syncWhatsAppSupportSchema(): array
    {
        $db = new Database();
        $changes = [];

        $exists = (bool)$db->execute("
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'whatsapp_support_sessions'
              AND COLUMN_NAME = 'support_ticket_id'
            LIMIT 1
        ")->fetchColumn();

        if (!$exists) {
            $db->execute("
                ALTER TABLE whatsapp_support_sessions
                    ADD COLUMN support_ticket_id INT UNSIGNED NULL AFTER conversation_id,
                    ADD KEY idx_wass_support_ticket (support_ticket_id)
            ");
            $changes[] = 'whatsapp_support_sessions.support_ticket_id';
        }

        return $changes;
    }

    public static function syncApplicationSchema(): array
    {
        Notifications::syncSchema();
        SystemUpdate::syncSchema();
        SupportTicket::syncSchema();

        return [
            'notifications',
            'system_updates',
            'support_tickets',
            'support_ticket_audit_logs',
        ];
    }

    private static function ensureSysRolesColumn(string $column, string $sql, array &$changes): void
    {
        $exists = (bool)(new Database())->execute(
            'SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column
             LIMIT 1',
            [
                ':table' => 'sys_roles',
                ':column' => $column,
            ]
        )->fetchColumn();

        if (!$exists) {
            (new Database())->execute($sql);
            $changes[] = 'sys_roles.' . $column;
        }
    }

    private static function ensureRouteColumn(string $column, string $sql, array &$changes): void
    {
        $exists = (bool)(new Database())->execute(
            'SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column
             LIMIT 1',
            [
                ':table' => 'sys_routes',
                ':column' => $column,
            ]
        )->fetchColumn();

        if (!$exists) {
            (new Database())->execute($sql);
            $changes[] = 'sys_routes.' . $column;
        }
    }

    private static function ensureIndex(string $table, string $index, string $sql, array &$changes): void
    {
        $exists = (bool)(new Database())->execute(
            'SELECT 1
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND INDEX_NAME = :index_name
             LIMIT 1',
            [
                ':table' => $table,
                ':index_name' => $index,
            ]
        )->fetchColumn();

        if (!$exists) {
            (new Database())->execute($sql);
            $changes[] = $table . '.' . $index;
        }
    }
}
