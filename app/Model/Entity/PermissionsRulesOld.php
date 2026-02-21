<?php

namespace App\Model\Entity;

use PDO;
use WilliamCosta\DatabaseManager\Database;

class PermissionsRulesOld
{

    public static function userCanAccessRoute(int $userId, string $routeName, string $tenancyId): bool
    {
        $db = new Database();

        // 1️⃣ Função do usuário
        $user = $db->execute("SELECT user_function FROM users WHERE id = :user_id", [
            ':user_id' => $userId
        ])->fetch(PDO::FETCH_ASSOC);

        if (!$user || empty($user['user_function'])) return false;

        $userFunction = $user['user_function'];

        // 2️⃣ Se for admin → ignora overrides
        if ($userFunction === 'admin') {
            $adminCount = $db->execute("
            SELECT COUNT(*) AS total
            FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id AND r.tenancy_id = ur.tenancy_id
            INNER JOIN role_permissions rp ON rp.role_id = r.id AND rp.tenancy_id = r.tenancy_id
            INNER JOIN permissions p ON p.id = rp.permission_id
            INNER JOIN permission_routes pr ON pr.permission_id = p.id
            WHERE ur.user_id = :user_id
              AND ur.tenancy_id = :tenancy_id
              AND pr.route_name = :route_name
        ", [
                ':user_id'    => $userId,
                ':tenancy_id' => $tenancyId,
                ':route_name' => $routeName
            ])->fetch(PDO::FETCH_ASSOC);

            return ($adminCount['total'] ?? 0) > 0;
        }

        // 3️⃣ Obtém as roles do usuário
        $roles = $db->execute("
        SELECT role_id 
        FROM user_roles 
        WHERE user_id = :user_id AND tenancy_id = :tenancy_id
    ", [
            ':user_id'    => $userId,
            ':tenancy_id' => $tenancyId
        ])->fetchAll(PDO::FETCH_COLUMN);

        // 4️⃣ Verifica override (user_id → role_id)
        if ($roles) {
            $placeholders = implode(',', array_fill(0, count($roles), '?'));
            $params = array_merge([$tenancyId, $userId], $roles, [$routeName]);

            $override = $db->execute("
            SELECT is_enabled
            FROM role_permission_overrides
            WHERE tenancy_id = ?
              AND (user_id = ? OR user_id IS NULL OR role_id IN ($placeholders))
              AND permission_name = ?
            ORDER BY 
                CASE WHEN user_id = ? THEN 1 
                     WHEN user_id IS NULL THEN 2 
                     ELSE 3 
                END
            LIMIT 1
        ", array_merge($params, [$userId]))->fetch(PDO::FETCH_ASSOC);

            if ($override) {
                return (bool)$override['is_enabled'];
            }
        }

        // 5️⃣ Verifica permissões padrão da função (via roles_defaults)
        $count = $db->execute("
        SELECT COUNT(*) AS total
        FROM role_default_permissions rdp
        INNER JOIN roles_defaults rd ON rd.id = rdp.role_default_id
        WHERE rd.role_name = :role_name
          AND rdp.route_path = :route_name
    ", [
            ':role_name'  => $userFunction,
            ':route_name' => $routeName
        ])->fetch(PDO::FETCH_ASSOC);

        return ($count['total'] ?? 0) > 0;
    }


    public static function getByUserId(int $userId, string $tenancyId): array
    {
        $rows = (new Database())->execute(
            "SELECT DISTINCT p.name
               FROM permissions p
               JOIN role_permissions rp ON rp.permission_id = p.id
               JOIN user_roles ur ON ur.role_id = rp.role_id
               JOIN users u ON u.id = ur.user_id
              WHERE ur.user_id = :user_id
                AND u.tenancy_id = :tenancy_id",
            [
                ':user_id'    => $userId,
                ':tenancy_id' => $tenancyId
            ]
        )->fetchAll();

        return array_column($rows, 'name');
    }

    public static function createRole(string $tenancyId, string $roleName, string $label): int
    {
        $db = new Database('roles');

        $role = $db->execute(
            "SELECT id FROM roles WHERE tenancy_id = :tenancy_id AND name = :name LIMIT 1",
            [
                ':tenancy_id' => $tenancyId,
                ':name'       => $roleName
            ]
        )->fetch();

        if ($role) {
            return (int)$role['id'];
        }

        return $db->insert([
            'tenancy_id' => $tenancyId,
            'name'       => $roleName,
            'label'      => $label
        ]);
    }

    public static function assignPermissionToRole(int $roleId, string $tenancyId, int $permissionId): void
    {
        (new Database())->execute(
            "INSERT IGNORE INTO role_permissions (role_id, tenancy_id, permission_id)
             VALUES (:role_id, :tenancy_id, :permission_id)",
            [
                ':role_id'       => $roleId,
                ':tenancy_id'    => $tenancyId,
                ':permission_id' => $permissionId
            ]
        );
    }

    public static function assignRoleToUser(int $userId, string $tenancyId, int $roleId): void
    {
        (new Database())->execute(
            "INSERT IGNORE INTO user_roles (user_id, tenancy_id, role_id)
             VALUES (:user_id, :tenancy_id, :role_id)",
            [
                ':user_id'    => $userId,
                ':tenancy_id' => $tenancyId,
                ':role_id'    => $roleId
            ]
        );
    }

    public static function assignRouteToPermission(int $permissionId, string $tenancyId, string $routeName): void
    {
        (new Database())->execute(
            "INSERT IGNORE INTO permission_routes (permission_id, tenancy_id, route_name)
             VALUES (:permission_id, :tenancy_id, :route_name)",
            [
                ':permission_id' => $permissionId,
                ':tenancy_id'    => $tenancyId,
                ':route_name'    => $routeName
            ]
        );
    }


    public static function createDefaultRolesAndPermissions(string $tenancyId): void
    {
        $db = new Database();

        // cria roles padrão, mas somente se ainda não existem no tenancy
        $roleAdminId = self::createRole($tenancyId, 'admin', 'Administrador');
        self::createRole($tenancyId, 'operator', 'Operador');
        self::createRole($tenancyId, 'manager', 'Gerente');

        // pega permissões globais (sem tenancy_id)
        $permissions = $db->execute("SELECT id FROM permissions WHERE tenancy_id IS NULL ORDER BY id")->fetchAll();

        foreach ($permissions as $perm) {
            self::assignPermissionToRole($roleAdminId, $tenancyId, $perm['id']);
        }

        // pega rotas globais
        $routes = $db->execute("SELECT permission_id, route_name 
                            FROM permission_routes 
                            WHERE tenancy_id IS NULL")->fetchAll();

        foreach ($routes as $route) {
            self::assignRouteToPermission($route['permission_id'], $tenancyId, $route['route_name']);
        }
    }



    public static function getAdminRoleId(string $tenancyId): ?int
    {
        $db = new Database();
        $role = $db->execute(
            "SELECT id FROM roles WHERE tenancy_id = :tenancy_id AND name = 'admin' LIMIT 1",
            [':tenancy_id' => $tenancyId]
        )->fetch();

        return $role['id'] ?? null;
    }

    /**
     * Cria uma permissão (ou retorna a existente)
     */
    public static function createPermission(string $tenancyId, string $permName, string $label): int
    {
        $db = new Database('permissions');

        $perm = $db->execute(
            "SELECT id FROM permissions WHERE tenancy_id = :tenancy_id AND name = :name LIMIT 1",
            [
                ':tenancy_id' => $tenancyId,
                ':name'       => $permName
            ]
        )->fetch();

        if ($perm) {
            return (int)$perm['id'];
        }

        return $db->insert([
            'tenancy_id' => $tenancyId,
            'name'       => $permName,
            'label'      => $label
        ]);
    }





    public static function syncNewRoutesForAllTenancies(int $roleId, string $tenancyId): void
    {
        $db = new Database();

        // 1️⃣ Buscar permissões globais + rotas
        $allPermissions = $db->execute("
        SELECT p.id AS permission_id, p.name, pr.route_name
        FROM permissions p
        LEFT JOIN permission_routes pr 
               ON pr.permission_id = p.id 
              AND pr.tenancy_id IS NULL
    ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allPermissions as $perm) {
            // 2️⃣ Vincula permissão ao role, sem duplicar
            $db->execute("
            INSERT INTO role_permissions (role_id, tenancy_id, permission_id)
            VALUES (:role_id, :tenancy_id, :permission_id)
            ON DUPLICATE KEY UPDATE role_id = role_id
        ", [
                ':role_id'       => $roleId,
                ':tenancy_id'    => $tenancyId,
                ':permission_id' => $perm['permission_id']
            ]);

            // 3️⃣ Vincula rota à permissão, sem duplicar
            if (!empty($perm['route_name'])) {
                $db->execute("
                INSERT INTO permission_routes (permission_id, tenancy_id, route_name)
                VALUES (:permission_id, :tenancy_id, :route_name)
                ON DUPLICATE KEY UPDATE route_name = route_name
            ", [
                    ':permission_id' => $perm['permission_id'],
                    ':tenancy_id'    => $tenancyId,
                    ':route_name'    => $perm['route_name']
                ]);
            }
        }
    }







    public static function syncUserFunctionPermissions(int $userId, string $tenancyId): void
    {
        $db = new Database();

        $user = $db->execute("SELECT user_function FROM users WHERE id = :user_id", [
            ':user_id' => $userId
        ])->fetch(PDO::FETCH_ASSOC);

        if (!$user || empty($user['user_function'])) return;

        $roleName = $user['user_function'];
        $roleId   = self::createRole($tenancyId, $roleName, ucfirst($roleName));

        $defaults = $db->execute("
        SELECT rdp.permission_name, rdp.permission_label, rdp.route_path
        FROM role_default_permissions rdp
        INNER JOIN roles_defaults rd ON rd.id = rdp.role_default_id
        WHERE rd.role_name = :role
    ", [':role' => $roleName])->fetchAll(PDO::FETCH_ASSOC);

        foreach ($defaults as $perm) {
            $permId = self::createPermission($tenancyId, $perm['permission_name'], $perm['permission_label']);
            self::assignPermissionToRole($roleId, $tenancyId, $permId);

            if (!empty($perm['route_path'])) {
                self::assignRouteToPermission($permId, $tenancyId, $perm['route_path']);
            }
        }

        // Associa usuário ao role sem duplicar
        $exists = $db->execute("
        SELECT 1 
        FROM user_roles 
        WHERE user_id = :user AND role_id = :role AND tenancy_id = :tenancy
        LIMIT 1
    ", [
            ':user'    => $userId,
            ':role'    => $roleId,
            ':tenancy' => $tenancyId
        ])->fetch();

        if (!$exists) {
            $db->execute("
            INSERT INTO user_roles (user_id, role_id, tenancy_id)
            VALUES (:user, :role, :tenancy)
        ", [
                ':user'    => $userId,
                ':role'    => $roleId,
                ':tenancy' => $tenancyId
            ]);
        }
    }


    public static function getDefaultRolePermissions(): array
    {
        $db = new Database();

        $rows = $db->execute("
        SELECT 
            rd.id AS role_id,
            rd.role_name,
            rd.status,
            rd.description,
            rdp.permission_label
        FROM roles_defaults rd
        LEFT JOIN role_default_permissions rdp 
               ON rdp.role_default_id = rd.id
        ORDER BY rd.id, rdp.permission_label
    ")->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        $translations = [
            'manager'   => 'Gerente',
            'operator'  => 'Usuário',
            'financial' => 'Financeiro',
            'reseller'  => 'Revendedor'
        ];
        foreach ($rows as $row) {
            $roleId = $row['role_id'];

            if (!isset($result[$roleId])) {
                $result[$roleId] = [
                    'id'          => $row['role_id'],
                    'role_name'   => $translations[$row['role_name']] ?? $row['role_name'],
                    'status'      => $row['status'],
                    'description' => $row['description'],
                    'permissions' => []
                ];
            }

            if (!empty($row['permission_label'])) {
                $result[$roleId]['permissions'][] = $row['permission_label'];
            }
        }

        return array_values($result); // retorna como lista
    }

    public static function getDefaultRolePermissionsById(int $roleId, string $tenancyId): array
    {
        $db = new Database();

        $rows = $db->execute("
        SELECT 
            rd.id AS role_id,
            rd.role_name,
            rd.status,
            rd.description,
            rdp.id AS permission_id,
            rdp.permission_label,
            rdp.permission_name,
            rdp.route_path,
            rpo.is_enabled  -- <-- override vem daqui
        FROM roles_defaults rd
        LEFT JOIN role_default_permissions rdp 
            ON rdp.role_default_id = rd.id
        LEFT JOIN role_permission_overrides rpo
            ON rpo.role_id = rd.id
            AND rpo.tenancy_id = :tenancy_id
            AND rpo.user_id IS NULL
            AND rpo.permission_name = rdp.route_path
        WHERE rd.id = :role_id
        ORDER BY rdp.permission_label
    ", [
            ':role_id' => $roleId,
            ':tenancy_id' => $tenancyId
        ])->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            return [];
        }

        $result = [
            'id' => $rows[0]['role_id'],
            'status' => $rows[0]['status'],
            //'role_name' => ($translations[$rows[0]['role_name']] ?? $rows[0]['role_name']),
            'description' => $rows[0]['description'],
            'permissions' => []
        ];

        foreach ($rows as $row) {
            if (!empty($row['permission_name'])) {
                $result['permissions'][] = [
                    'id'     => $row['permission_id'],
                    'name'   => $row['permission_name'],
                    'label'  => $row['permission_label'],
                    'router' => $row['route_path'],
                    'active' => !($row['is_enabled'] !== null) || (bool)$row['is_enabled']
                    // Se não houver override, assume como ativo
                ];
            }
        }

        return $result;
    }


    public static function getRoleIdByUserId(int $userId): ?int
    {
        $db = new Database();

        $sql = "
            SELECT role_id
            FROM user_roles
            WHERE user_id = :user_id
            LIMIT 1
        ";

        $role = $db->execute($sql, [
            ':user_id' => $userId
        ])->fetch(\PDO::FETCH_ASSOC);

        return $role['role_id'] ?? null;
    }

    /**
     * Associa todas as permissões padrão de um role ao tenancy específico
     */
    public static function assignDefaultRolePermissionsToTenancy(string $roleName, string $tenancyId, int $roleId): void
    {
        $db = new Database();

        // Pega todas as permissões padrão para a função
        $defaults = $db->execute("
        SELECT rdp.permission_name, rdp.permission_label, rdp.route_path
        FROM role_default_permissions rdp
        INNER JOIN roles_defaults rd ON rd.id = rdp.role_default_id
        WHERE rd.role_name = :role
    ", [
            ':role' => $roleName
        ])->fetchAll(PDO::FETCH_ASSOC);

        foreach ($defaults as $perm) {
            // Verifica se a permissão já existe para este tenancy
            $existing = $db->execute("
            SELECT id 
            FROM permissions 
            WHERE name = :name AND tenancy_id = :tenancy
            LIMIT 1
        ", [
                ':name'    => $perm['permission_name'],
                ':tenancy' => $tenancyId
            ])->fetch(PDO::FETCH_ASSOC);

            // Cria a permissão se não existir
            $permId = $existing['id'] ?? self::createPermission($tenancyId, $perm['permission_name'], $perm['permission_label']);

            // Associa a permissão ao role
            self::assignPermissionToRole($roleId, $tenancyId, $permId);

            // Associa a rota à permissão, se houver
            if (!empty($perm['route_path'])) {
                self::assignRouteToPermission($permId, $tenancyId, $perm['route_path']);
            }
        }
    }

    public static function saveOverride(string $tenancyId, ?int $userId, int $roleId, string $permissionRouter, int $enabled = 1): void
    {

        $db = new Database();

        $stmt = $db->execute("
        UPDATE role_permission_overrides
        SET is_enabled = IF(is_enabled = 1, 0, 1)
        WHERE tenancy_id = :tenancy_id
          AND user_id IS NULL
          AND role_id = :role_id
          AND permission_name = :permission_name
    ", [
            ':tenancy_id'      => $tenancyId,
            ':role_id'         => $roleId,
            ':permission_name' => $permissionRouter
        ]);

        if ($stmt->rowCount() === 0) {
            // Insere como ativo caso não exista
            $db->execute("
            INSERT INTO role_permission_overrides 
                (tenancy_id, user_id, role_id, permission_name, is_enabled)
            VALUES (:tenancy_id, NULL, :role_id, :permission_name, 0)
        ", [
                ':tenancy_id'      => $tenancyId,
                ':role_id'         => $roleId,
                ':permission_name' => $permissionRouter
            ]);
        }
    }

}