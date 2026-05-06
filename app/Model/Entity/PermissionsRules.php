<?php
namespace App\Model\Entity;

use PDO;
use WilliamCosta\DatabaseManager\Database;

class PermissionsRules {

    /**
     * Busca os papéis (roles) do Tenancy ou Globais
     */
    public static function getRoles(string $tenancyId, ?int $userId = null): array
    {
        $table = 'sys_roles r';
        // 🛡️ Filtra pela empresa E garante que se for um papel customizado, seja do criador correto
        $where = 'r.tenancy_id = :tenancy_id';
        $params = [':tenancy_id' => $tenancyId];

        if ($userId !== null) {
            // Se passar o ID, garante que ele veja os cargos globais (NULL) OU os dele
            $where = "(r.tenancy_id = :tenancy_id AND (r.user_id = :user_id OR r.user_id IS NULL))";
            $params[':user_id'] = $userId;
        }

        return (new Database($table))
            ->select(
                $where,
                $params,
                'r.id DESC',
                null,
                "r.id, r.name, r.description, r.status, 
             (SELECT COUNT(*) FROM sys_routes) AS total_routes_system,
             (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id AND ur.tenancy_id = :tenancy_id) AS total_users"
            )
            ->fetchAll(\PDO::FETCH_ASSOC);
    }


    /**
     * Busca os dados de um papel pelo nome (Ex: 'reseller')
     */
    public static function getRoleByName($name) {
        // O execute() retorna o statement do PDO. Nele sim podemos dar o fetch.
        $query = "SELECT * FROM sys_roles WHERE name = :name LIMIT 1";
        $statement = (new Database())->execute($query, [':name' => $name]);

        return $statement->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Cadastra rotas no catálogo global (sys_routes)
     */
    public static function registerGlobalRoute(string $name, string $label, array $routes): void
    {
        $db = new Database('sys_routes');
        foreach ($routes as $path) {
            $path = trim($path);
            if (empty($path)) continue;

            $db->insert([
                'module_name' => $label,
                'route_path'  => $path
            ]);
        }
    }

    /**
     * Busca permissões cruzadas para o Modal de Switches
     */
    public static function getCombinedPermissions(int $roleId, string $tenancyId): array
    {
        $db = new Database();
        // 🛡️ Adicionamos a trava de segurança direto no JOIN para garantir que
        // as permissões pertençam à Tenancy correta
        $query = "SELECT 
            sr.id as route_id,
            sr.module_name as label,
            sr.route_path as name,
            IF(srp.id IS NULL, 0, 1) as enabled
          FROM sys_routes sr
          LEFT JOIN sys_role_permissions srp 
            ON srp.route_id = sr.id 
            AND srp.role_id = :role_id 
            AND srp.tenancy_id = :tenancy_id
          ORDER BY sr.module_name ASC, sr.route_path ASC";

        return $db->execute($query, [
            ':role_id'    => $roleId,
            ':tenancy_id' => $tenancyId
        ])->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getRoleByNameAndTenancy($name, $tenancyId) {
        return (new Database('sys_roles'))->select('name = "'.$name.'" AND tenancy_id = "'.$tenancyId.'"')->fetchObject(self::class);
    }


    /**
     * Salva ou Atualiza o papel
     */
    public static function registerRole($name, $label, $status, $tenancyId, $userId = null, $id = null): int
    {
        $db = new Database('sys_roles');

        $values = [
            'tenancy_id'  => $tenancyId,
            'name'        => $name,
            'label'       => $label,
            'description' => $label,
            'status'      => $status
        ];

        if (!empty($userId)) {
            $values['user_id'] = (int)$userId;
        }

        if ($id && is_numeric($id)) {
            unset($values['user_id']); // mantém o dono original no update
            $db->update('id = ' . (int)$id . ' AND tenancy_id = "' . $tenancyId . '"', $values);
            return (int)$id;
        }

        $values['created_at'] = date('Y-m-d H:i:s');
        return $db->insert($values);
    }

    /**
     * Liga ou Desliga a permissão no banco
     */
    public static function toggleRolePermission(string $tenancyId, int $roleId, int $routeId, int $active): void
    {
        $db = new Database('sys_role_permissions');
        if ($active === 1) {
            $db->execute("INSERT IGNORE INTO sys_role_permissions (tenancy_id, role_id, route_id) VALUES (:tid, :rid, :roid)", [
                ':tid'  => $tenancyId,
                ':rid'  => $roleId,
                ':roid' => $routeId
            ]);
        } else {
            $db->execute("DELETE FROM sys_role_permissions WHERE tenancy_id = :tid AND role_id = :rid AND route_id = :roid", [
                ':tid'  => $tenancyId,
                ':rid'  => $roleId,
                ':roid' => $routeId
            ]);
        }
    }

    /**
     * A verificação que o seu Middleware usa (CRÍTICO)
     */
    // Dentro da PermissionsRules.php
    public static function userCanAccessRoute(int $userId, string $routeName, string $tenancyId): bool
    {
        $db = new Database();
        $routeName = self::normalizeRoutePath($routeName);

        // 1. Busca o papel correto dentro da tenancy atual.
        $userRole = $db->execute("SELECT role_id FROM user_roles WHERE user_id = :uid AND tenancy_id = :tid LIMIT 1", [
            ':uid' => $userId,
            ':tid' => $tenancyId,
        ])->fetch(\PDO::FETCH_ASSOC);

        if (!$userRole) {
            $userRole = $db->execute(
                "SELECT role_id FROM users WHERE id = :uid AND tenancy_id = :tid LIMIT 1",
                [
                    ':uid' => $userId,
                    ':tid' => $tenancyId,
                ]
            )->fetch(\PDO::FETCH_ASSOC);
        }

        $roleId = (int)($userRole['role_id'] ?? 0);
        if ($roleId <= 0) {
            error_log(sprintf(
                '[permissions] access_denied reason=no_role user_id=%d tenancy_id=%s route=%s',
                $userId,
                $tenancyId,
                $routeName
            ));
            return false;
        }

        // 2. Verifica permissão exata e herança da rota pai do módulo.
        $routeCandidates = self::buildRouteCandidates($routeName);
        $placeholders = [];
        $params = [
            ':rid' => $roleId,
            ':tid' => $tenancyId,
        ];
        foreach ($routeCandidates as $index => $candidate) {
            $key = ':route_' . $index;
            $placeholders[] = $key;
            $params[$key] = $candidate;
        }

        $access = $db->execute("
        SELECT srp.id 
        FROM sys_role_permissions srp
        INNER JOIN sys_routes sr ON sr.id = srp.route_id
        WHERE srp.role_id = :rid
          AND srp.tenancy_id = :tid
          AND sr.route_path IN (" . implode(', ', $placeholders) . ")
        LIMIT 1
    ", $params)->fetch(\PDO::FETCH_ASSOC);

        if (!$access) {
            error_log(sprintf(
                '[permissions] access_denied reason=missing_route_permission user_id=%d tenancy_id=%s role_id=%d route=%s candidates=%s',
                $userId,
                $tenancyId,
                $roleId,
                $routeName,
                json_encode($routeCandidates, JSON_UNESCAPED_SLASHES)
            ));
        }

        return (bool)$access;
    }

    private static function normalizeRoutePath(string $routeName): string
    {
        $routeName = '/' . trim($routeName, '/');
        return preg_replace('#/+#', '/', $routeName) ?: '/';
    }

    private static function buildRouteCandidates(string $routeName): array
    {
        $normalized = self::normalizeRoutePath($routeName);
        $candidates = [$normalized];
        $segments = array_values(array_filter(explode('/', trim($normalized, '/')), 'strlen'));

        while (count($segments) > 1) {
            array_pop($segments);
            $candidates[] = '/' . implode('/', $segments);
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Vincula o usuário ao papel (Usado no getSetNewUsers)
     */
    public static function assignRoleToUser(int $userId, string $tenancyId, int $roleId): void
    {
        $db = new Database('user_roles');

        // PRIMEIRO: Remove qualquer papel anterior deste usuário nesta tenancy
        // Isso evita que ele acumule permissões de "Admin" e "Financeiro" ao mesmo tempo
        $db->execute("DELETE FROM user_roles WHERE user_id = :uid AND tenancy_id = :tid", [
            ':uid' => $userId,
            ':tid' => $tenancyId
        ]);

        // SEGUNDO: Insere o novo vínculo (se o roleId for maior que 0)
        if ($roleId > 0) {
            $db->insert([
                'user_id'    => $userId,
                'tenancy_id' => $tenancyId,
                'role_id'    => $roleId
            ]);
        }
    }

    /**
     * Inicializa permissões totais para um Admin
     */
    public static function initAdminPermissions(string $tenancyId, int $roleId): void
    {
        $db = new Database();
        $routes = $db->execute("SELECT id FROM sys_routes")->fetchAll(\PDO::FETCH_ASSOC);
        $dbPermissions = new Database('sys_role_permissions');

        foreach ($routes as $route) {
            $dbPermissions->insert([
                'tenancy_id' => $tenancyId,
                'role_id'    => $roleId,
                'route_id'   => $route['id']
            ]);
        }
    }

    public static function getRolePermissionsNames(int $roleId): array
    {
        // Mudamos de sr.module_name para sr.route_path
        // O DISTINCT continua sendo importante para não repetir rotas no array
        $query = "SELECT DISTINCT(sr.route_path)
          FROM sys_role_permissions srp
          INNER JOIN sys_routes sr ON sr.id = srp.route_id
          WHERE srp.role_id = :role_id";

        try {
            $db = new Database();
            $statement = $db->execute($query, [':role_id' => $roleId]);

            // Retorna um array simples contendo apenas as strings das rotas
            $results = $statement->fetchAll(\PDO::FETCH_COLUMN);

            if(empty($results)) {
                error_log("Nenhuma rota de permissão encontrada para o papel ID: " . $roleId);
            }

            return $results ?: [];
        } catch (\Exception $e) {
            error_log("Erro SQL em getRolePermissionsNames: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Remove o vínculo entre usuário e papel na tabela intermediária
     */
    public static function removeRoleFromUser(int $userId, string $tenancyId, int $roleId): void
    {
        $db = new Database('user_roles');
        $db->execute("DELETE FROM user_roles WHERE user_id = :uid AND tenancy_id = :tid AND role_id = :rid", [
            ':uid' => $userId,
            ':tid' => $tenancyId,
            ':rid' => $roleId
        ]);
    }
}
