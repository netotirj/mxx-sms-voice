<?php
namespace App\Model\Entity;

use PDO;
use WilliamCosta\DatabaseManager\Database;

class PermissionsRules1404 {

    /**
     * Busca os papéis (roles) do Tenancy ou Globais
     */
    public static function getRoles(string $tenancyId): array
    {
        $table = 'sys_roles r';
        // Filtra pela empresa ou papéis globais do sistema
        $where = '(r.tenancy_id = :tenancy_id OR r.tenancy_id IS NULL)';
        $params = [':tenancy_id' => $tenancyId];

        return (new Database($table))
            ->select(
                $where,
                $params,
                'r.id DESC',
                null,
                "r.id, 
             r.name, 
             r.description, 
             r.status, 
             (SELECT COUNT(*) FROM sys_routes) AS total_routes_system,
             (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id AND ur.tenancy_id = :tenancy_id) AS total_users"
            )
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Busca um papel pelo nome (slug) - Retornando ARRAY para o Controller
     */
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
    public static function registerRole($name, $label, $status, $tenancyId, $id = null): int
    {
        $db = new Database('sys_roles');

        $values = [
            'tenancy_id'  => $tenancyId,
            'name'        => $name,
            'label'       => $label,
            'description' => $label, // Usando o label como descrição também
            'status'      => $status
        ];

        // Se veio ID, faz UPDATE
        if ($id && is_numeric($id)) {
            $db->update('id = ' . $id, $values);
            return (int)$id;
        }

        // Se não veio ID, faz INSERT
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

        // 1. Busca o papel (role)
        $userRole = $db->execute("SELECT role_id FROM user_roles WHERE user_id = :uid LIMIT 1", [
            ':uid' => $userId
        ])->fetch(\PDO::FETCH_ASSOC);

        if (!$userRole) return false;

        // 2. Verifica permissão (Comparando a rota com e sem barra)
        $access = $db->execute("
        SELECT srp.id 
        FROM sys_role_permissions srp
        INNER JOIN sys_routes sr ON sr.id = srp.route_id
        WHERE srp.role_id = :rid 
          AND (sr.route_path = :path OR sr.route_path = :path_alt)
        LIMIT 1
    ", [
            ':rid'      => $userRole['role_id'],
            ':path'     => $routeName,
            ':path_alt' => '/'.trim($routeName, '/')
        ])->fetch(\PDO::FETCH_ASSOC);

        return (bool)$access;
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