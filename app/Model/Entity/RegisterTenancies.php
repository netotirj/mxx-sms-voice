<?php

namespace App\Model\Entity;
use PDOStatement;
use WilliamCosta\DatabaseManager\Database;

class RegisterTenancies
{
    public string $id;
    public string $name;
    public string $tenancy_phone;
    public string $status;
    public string $created_at;
    public string $updated_at;
    public string $account_code;


    public function insertUserTenancy(): bool
    {
        (new Database('tenancies'))->insert([
            'id'            => $this->id,
            'name'          => $this->name,
            'tenancy_phone' => $this->tenancy_phone,
            'account_code'  => $this->account_code, // ✅ NOVO
            'status'        => $this->status,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at
        ]);

        return true;
    }

    public static function accountCodeExists(string $accountCode): bool
    {
        $db = new Database();

        $query = "SELECT 1 FROM tenancies WHERE account_code = :code LIMIT 1";
        $params = [':code' => $accountCode];

        return (bool) $db->execute($query, $params)->fetchColumn();
    }

    /**
     * Atualiza o plano ativo da tenancy
     *
     * @param string $tenancyId
     * @param int $userPlanId
     * @return bool
     */
    public static function updateActivePlan(string $tenancyId, int $userPlanId): bool
    {
        $query = "UPDATE tenancies SET active_plan_id = :plan_id WHERE id = :tenancy_id";
        $params = [
            ':plan_id'     => $userPlanId,
            ':tenancy_id'  => $tenancyId
        ];

        return (new Database())->execute($query, $params) != false;
    }

    /**
     * Retorna o ID do plano ativo da tenancy
     *
     * @param string $tenancyId
     * @return int|null
     */
    public static function getActivePlanId(string $tenancyId): ?int
    {
        $query = "SELECT active_plan_id FROM tenancies WHERE id = :id";

        $params = [':id' => $tenancyId];

        $result = (new Database())->execute($query, $params)->fetchObject();

        return $result && $result->active_plan_id ? (int) $result->active_plan_id : null;
    }

    public static function getTenancyByAdminEmail(string $email): ?object
    {
        $db = new Database();

        $query = "SELECT u.id as user_id,
                     u.email,
                     u.user_function,
                     u.tenancy_id,
                     u.name,
                     t.tenancy_phone,
                     t.account_code
              FROM users u
              INNER JOIN tenancies t ON t.id = u.tenancy_id
              WHERE u.email = :email
                AND u.user_function = 'admin'
              LIMIT 1";

        $params = [':email' => $email];

        $result = $db->execute($query, $params)->fetchObject();

        if (!$result) {
            // E-mail não existe ou não é admin → retorna null
            return null;
        }

        // Mascarar telefone antes de retornar
        $phone = $result->tenancy_phone;
        $result->maskedPhone = substr($phone, 0, 2) . "*****" . substr($phone, -2);

        return $result;
    }

    public static function getTenancyOwnerUserId(string $tenancyId): ?int
    {
        $db = new Database();

        $query = "SELECT u.id
              FROM users u
              WHERE u.tenancy_id = :tenancy_id
                AND u.user_function = 'admin'
              LIMIT 1";

        $params = [':tenancy_id' => $tenancyId];

        $result = $db->execute($query, $params)->fetchObject();

        return $result ? (int)$result->id : null;
    }


    /**
     * Salva o código de reset na tabela password_resets
     *
     * @param string $tenancyId
     * @param int $userId
     * @param string $code
     * @param int $expireSeconds
     * @return PDOStatement
     */
    public static function save(string $tenancyId, int $userId, string $code, int $expireSeconds): PDOStatement
    {
        $db = new Database();

        $expiresAt = date('Y-m-d H:i:s', time() + $expireSeconds);

        $query = "INSERT INTO password_resets (user_id, tenancy_id, code, expires_at) 
                  VALUES (:user_id, :tenancy_id, :code, :expires_at)";

        $params = [
            ':user_id'    => $userId,
            ':tenancy_id' => $tenancyId,
            ':code'       => $code,
            ':expires_at' => $expiresAt
        ];

        return $db->execute($query, $params);
    }

    public static function validateResetCode(string $code, int $userId): ?object
    {
        $db = new Database();

        $query = "SELECT * FROM password_resets
              WHERE user_id = :user_id
                AND code = :code
                AND expires_at >= NOW()
                AND used = 0
              LIMIT 1";

        $params = [
            ':user_id' => $userId,
            ':code'    => $code
        ];

        $result = $db->execute($query, $params)->fetchObject();

        return $result ?: null; // se não encontrar, código inválido ou expirado
    }

    public static function markCodeUsed(int $id): void
    {
        $db = new Database();
        $query = "UPDATE password_resets SET used = 1 WHERE id = :id";
        $db->execute($query, [':id' => $id]);
    }

    /**
     * Inicializa a estrutura de permissões para uma nova Tenancy
     */
    public function setupInitialPermissions(): void
    {
        // 1. Criar os papéis padrão para esta nova Tenancy
        // Usando a nova tabela 'sys_roles'
        $roleAdminId = \App\Model\Entity\PermissionsRules::createRole(
            $this->id,
            'admin',
            'Administrador'
        );

        \App\Model\Entity\PermissionsRules::createRole(
            $this->id,
            'operator',
            'Operador'
        );

        // 2. Liberar todas as rotas da 'sys_routes' para o Admin deste novo cliente
        // Isso garante que o dono da conta veja tudo o que você cadastrou no catálogo global
        \App\Model\Entity\PermissionsRules::initAdminPermissions($this->id, $roleAdminId);
    }



}