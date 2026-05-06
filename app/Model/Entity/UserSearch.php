<?php

namespace App\Model\Entity;

use App\Utils\TenancyHelper;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class UserSearch
{
    public $id = 0;
    public $user_id = null;
    public $tenancy_id = '';
    public $name = '';
    public int $role_id = 0;
    public $last_name = '';
    public $password = '';
    public $email = '';
    public $image = '';
    public $user_function = '';
    public $status = '';
    public string $status_account = '';
    public string $account_code = '';

    public $last_activity = null;
    public $remember_token = '';
    public $createdAt = '';
    public $updatedAt = '';

    /**
     * Tradução centralizada para evitar repetição de código
     */
    private static function translateUserFields(array $result): array
    {
        $translations = [
            'admin'       => 'Administrador Geral',
            'rh'          => 'Recursos Humanos',
            'financial'   => 'Financeiro',
            'reception'   => 'Recepção',
            'manager'     => 'Gerente de Operações',
            'supervisor'  => 'Supervisor',
            'agent'       => 'Agente / Operador',
            'monitor'     => 'Monitor de Qualidade',
            'support_l1'  => 'Suporte Nível 1',
            'support_l2'  => 'Suporte Nível 2',
            'ticket_support' => 'Atendimento de Tickets',
            'support_ticket_manager' => 'Gestor de Tickets',
            'reseller'    => 'Revendedor',
            'operator'    => 'Operador (Antigo)',
            'o'           => 'Operador'
        ];

        foreach ($result as &$row) {
            $row['user_function_translated'] = $translations[$row['user_function']] ?? $row['user_function'];
        }
        return $result;
    }

    /**
     * Busca principal - Respeita os parâmetros enviados pelo Controller
     */
    public static function getUsers(string $tenancyId, ?int $userId = null): array
    {
        $where = 'u.tenancy_id = :tenancy_id';
        $params = [':tenancy_id' => $tenancyId];

        if ($userId !== null) {
            $where .= ' AND (u.id = :user_id OR u.user_id = :user_id)';
            $params[':user_id'] = $userId;
        }

        $result = (new Database('users u
            INNER JOIN tenancies t ON t.id = u.tenancy_id
            LEFT JOIN address a ON a.tenancy_id = t.id'))
            ->select($where, $params, 'u.id ASC', null,
                'u.id, u.user_id, u.name, u.role_id, u.tenancy_id, u.last_name, u.email, u.image,               
                 u.job_title, u.status_account, u.reseller_balance,
                 COALESCE((SELECT SUM(tb.balance)
                           FROM tenancy_balance tb
                           WHERE tb.user_id = u.id AND tb.tenancy_id = u.tenancy_id), 0) AS admin_balance,
                 u.user_function, u.last_activity, 
                 u.createdAt, t.name AS tenancy_name, t.tenancy_phone AS tenancy_phone,
                 a.street AS address_street, a.number AS address_number, a.complement AS address_complement,
                 a.neighborhood AS address_neighborhood, a.city AS address_city, a.state AS address_state,
                 a.zipcode AS address_zipcode, a.country AS address_country'
            )
            ->fetchAll(PDO::FETCH_ASSOC);

        return self::translateUserFields($result);
    }

    public static function getAllUsersGlobal(): array
    {
        $result = (new Database('users u
            INNER JOIN tenancies t ON t.id = u.tenancy_id
            LEFT JOIN address a ON a.tenancy_id = t.id'))
            ->select('1=1', [], 'u.id ASC', null,
                'u.id, u.user_id, u.name, u.role_id, u.tenancy_id, u.last_name, u.email, u.image,
                 u.job_title, u.status_account, u.reseller_balance,
                 COALESCE((SELECT SUM(tb.balance)
                           FROM tenancy_balance tb
                           WHERE tb.user_id = u.id AND tb.tenancy_id = u.tenancy_id), 0) AS admin_balance,
                 u.user_function, u.last_activity,
                 u.createdAt, t.name AS tenancy_name, t.tenancy_phone AS tenancy_phone,
                 a.street AS address_street, a.number AS address_number, a.complement AS address_complement,
                 a.neighborhood AS address_neighborhood, a.city AS address_city, a.state AS address_state,
                 a.zipcode AS address_zipcode, a.country AS address_country'
            )
            ->fetchAll(PDO::FETCH_ASSOC);

        return self::translateUserFields($result);
    }

    public static function getUserById(string $tenancyId, int $userId): ?array
    {
        $where = 'u.tenancy_id = :tenancy_id AND u.id = :id';
        $params = [':tenancy_id' => $tenancyId, ':id' => $userId];

        $user = (new Database('users u INNER JOIN tenancies t ON t.id = u.tenancy_id'))
            ->select($where, $params)->fetch(PDO::FETCH_ASSOC);

        return $user ? self::translateUserFields([$user])[0] : null;
    }

    public static function getUserByIdGlobal(int $userId): ?array
    {
        $user = (new Database('users u INNER JOIN tenancies t ON t.id = u.tenancy_id'))
            ->select('u.id = :id', [':id' => $userId])->fetch(PDO::FETCH_ASSOC);

        return $user ? self::translateUserFields([$user])[0] : null;
    }

    public static function getUserByPartnerId(string $partnerId): ?self
    {
        if (preg_match('/^u(\d+)-/i', $partnerId, $matches)) {
            $partnerId = $matches[1];
        }

        if (!ctype_digit((string)$partnerId)) {
            return null;
        }

        $data = (new Database('users'))->select('id = :id', [
            ':id' => (int)$partnerId
        ])->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            return null;
        }

        $user = new self();
        foreach ($data as $field => $value) {
            if (property_exists($user, $field)) {
                $user->$field = $value ?? $user->$field;
            }
        }

        return $user;
    }

    public static function getResellers(string $tenancyId, ?int $userId = null): array
    {
        $where = 'u.tenancy_id = :tenancy_id AND u.user_function = :function';
        $params = [
            ':tenancy_id' => $tenancyId,
            ':function'   => 'reseller'
        ];

        if (!is_null($userId)) {
            $where .= ' AND u.id = :id';
            $params[':id'] = $userId;
        }

        return (new Database('users u
        INNER JOIN tenancies t ON t.id = u.tenancy_id'))
            ->select(
                $where,
                $params,
                null,
                null,
                'u.id,
             u.name,
             u.last_name,
             u.email,
             u.reseller_balance,
             u.job_title,
             u.status_account,
             u.user_function,
             u.last_activity,
             u.createdAt,
             t.name AS tenancy_name,
             t.tenancy_phone AS tenancy_phone'
            )
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function getUsersByReseller(string $tenancyId, int $resellerId): array
    {
        return (new Database('users u'))
            ->select(
                'u.tenancy_id = :tenancy_id 
             AND u.user_id = :reseller_id 
             AND u.id != :reseller_id',
                [
                    ':tenancy_id'  => $tenancyId,
                    ':reseller_id' => $resellerId
                ],
                null,
                null,
                'u.id,
             u.name,
             u.last_name,
             u.email,
             u.user_function,
             u.status_account,
             u.createdAt'
            )
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Gravação - Usa as propriedades carregadas na instância
     */
    public function insertUsers(): int
    {
        $fields = [
            'name'           => $this->name,
            'user_id'        => $this->user_id,
            'account_code'   => $this->account_code,
            'last_name'      => $this->last_name,
            'email'          => $this->email,
            'user_function'  => $this->user_function,
            'role_id'        => $this->role_id ?? 0,
            'password'       => $this->password,
            'status_account' => $this->status_account ?? 'inactive',
            'status'         => $this->status,
            'tenancy_id'     => $this->tenancy_id,
            'createdAt'      => $this->createdAt ?? date('Y-m-d H:i:s')
        ];

        $this->id = (new Database('users'))->insert($fields);
        return $this->id;
    }

    /**
     * Update - Respeita o contrato com o Controller
     */
    public static function updateUsers(int $userId, array $data, ?string $tenancyId): bool
    {
        $fields = [
            'name'           => trim($data['name'] ?? ''),
            'last_name'      => trim($data['last_name'] ?? ''),
            'email'          => strtolower(trim($data['email'] ?? '')),
            'user_function'  => $data['user_function'],
            'role_id'        => $data['role_id'] ?? 0,
            'status_account' => $data['status_account'] ?? 'inactive',
            'updatedAt'      => date('Y-m-d H:i:s'),
        ];

        if (!empty($data['password'])) {
            $fields['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        $where = 'id = :id';
        $params = [':id' => $userId];

        if ($tenancyId !== null) {
            $where .= ' AND tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $tenancyId;
        }

        return (new Database('users'))->update($where, $fields, $params) > 0;
    }

    public function updatePassword(): bool
    {
        return (new Database('users'))->update(
                'id = :id AND tenancy_id = :tenancy_id',
                [
                    'password' => $this->password, // campo a atualizar
                ],
                [
                    ':id' => $this->id,
                    ':tenancy_id' => $this->tenancy_id
                ]
            ) > 0;
    }

    public function updateUserImage(): bool
    {
        return (new Database('users'))->update(
                'id = :id AND tenancy_id = :tenancy_id',
                [
                    'image' => $this->image, // campo a atualizar
                ],
                [
                    ':id' => $this->id,
                    ':tenancy_id' => $this->tenancy_id
                ]
            ) > 0;
    }

    public function updateRefillReseller(float $value): bool
    {

        $query = "UPDATE users 
              SET reseller_balance = reseller_balance + :value                 
              WHERE id = :id AND tenancy_id = :tenancy_id";

        $params = [
            ':value'      => $value,
            ':id'         => $this->id,
            ':tenancy_id' => $this->tenancy_id
        ];

        $db = new Database();
        $stmt = $db->execute($query, $params);

        // retorna true se alguma linha foi afetada
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete - Respeita o contrato com o Controller
     */
    public static function deleteUsers(int $userId, ?string $tenancyId): bool
    {
        $where = 'id = :id';
        $params = [':id' => $userId];

        if ($tenancyId !== null) {
            $where .= ' AND tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $tenancyId;
        }

        return (new Database('users'))->delete($where, $params) > 0;
    }

    // Mantive os métodos específicos que você já usa (UpdateStatus, updateRole, etc)
    public static function updateStatusUser(int $userId, ?string $tenancyId, string $status): bool
    {
        $where = 'id = :id';
        $params = [':id' => $userId];

        if ($tenancyId !== null) {
            $where .= ' AND tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $tenancyId;
        }

        return (new Database('users'))->update($where, ['status_account' => $status], $params) > 0;
    }
}
