<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;

class UserSearch1404
{
    public $id = 0;
    public $user_id = null;
    public $tenancy_id = '';
    public $name = '';
    public int $role_id = 0; // 🚀 ADICIONE ESTA LINHA
    public $last_name = '';
    public $password = '';
    public $email = '';
    public $image = '';
    public $user_function = '';
    public $status = '';
   public $status_account = '';
    public $last_activity = null;
    public $remember_token = '';
    public $createdAt = '';
    public $updatedAt = '';

    /**
     * Busca um usuário pelo ID (partner_id)
     */


  public static function getUserByPartnerId(int $partnerId): ?self
    {
        $db = new Database('users');
        $data = $db->select('id = :id', [':id' => $partnerId])->fetch();

        if (!$data) {
            return null;
        }

        $user = new self();
        foreach ($data as $camp => $value) {
            if (property_exists($user, $camp)) {
                $user->$camp = $value ?? $user->$camp;
            }
        }

        return $user;
    }

    public static function getUsers(string $tenancyId, ?int $userId = null): array
    {
        $where = 'u.tenancy_id = :tenancy_id';
        $params = [':tenancy_id' => $tenancyId];

        if (!is_null($userId)) {
            // --- A MUDANÇA É AQUI ---
            // Se passarmos um ID (ex: do Revendedor), buscamos todos onde o PAI é ele
            // OU onde o ID seja ele mesmo (para ele continuar aparecendo na lista se quiser)
            $where .= ' AND (u.user_id = :user_id OR u.id = :my_id)';
            $params[':user_id'] = $userId;
            $params[':my_id']   = $userId;
        }

        $result = (new Database('users u
        INNER JOIN tenancies t ON t.id = u.tenancy_id
        LEFT JOIN address a ON a.tenancy_id = t.id'))
            ->select(
                $where,
                $params,
                null,
                null,
                'u.id,
             u.user_id, 
             u.name,
             u.role_id,
             u.tenancy_id,
             u.last_name, 
             u.email,
             u.image,               
             u.job_title, 
             u.status_account,
             u.reseller_balance, 
             u.user_function, 
             u.last_activity, 
             u.createdAt, 
             t.name AS tenancy_name,
             t.tenancy_phone AS tenancy_phone,
             a.street AS address_street,
             a.number AS address_number,
             a.complement AS address_complement,
             a.neighborhood AS address_neighborhood,
             a.city AS address_city,
             a.state AS address_state,
             a.zipcode AS address_zipcode,
             a.country AS address_country'
            )
            ->fetchAll(\PDO::FETCH_ASSOC);

        // Arrays de tradução
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
            'reseller'    => 'Revendedor',
            'operator'    => 'Operador (Antigo)',
            'o'           => 'Operador'
        ];

        $translationsJob = [
            'Developer' => 'Desenvolvedor',
            'Reseller'  => 'Revenda',
            'Sales Representative' => 'Representante de Vendas',
            'financial' => 'Financeiro',
            'Team Manager' => 'Gerente de Equipe'
        ];

        // Traduzir os campos
        foreach ($result as &$row) {
            $row['user_function_translated'] = $translations[$row['user_function']] ?? $row['user_function'];
            $row['job_title_translated']     = $translationsJob[$row['job_title']] ?? $row['job_title'];
        }

        return $result;
    }

    public static function getAllUsersNoFilter(): array
    {
        // 🔎 Faz a consulta completa, sem restrição de tenancy
        $result = (new Database('users u
        INNER JOIN tenancies t ON t.id = u.tenancy_id
        LEFT JOIN address a ON a.tenancy_id = t.id'))
            ->select(
                '', // <-- substituído null por string vazia
                [],
                null,
                null,
                'u.id, 
             u.name,
             u.last_name, 
             u.email,
             u.image,               
             u.job_title, 
             u.status_account,
             u.reseller_balance, 
             u.user_function, 
             u.last_activity, 
             u.createdAt, 
             t.name AS tenancy_name,
             t.tenancy_phone AS tenancy_phone,
             a.street AS address_street,
             a.number AS address_number,
             a.complement AS address_complement,
             a.neighborhood AS address_neighborhood,
             a.city AS address_city,
             a.state AS address_state,
             a.zipcode AS address_zipcode,
             a.country AS address_country'
            )
            ->fetchAll(\PDO::FETCH_ASSOC);

        // 🔤 Traduções
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
            'reseller'    => 'Revendedor',
            'operator'    => 'Operador (Antigo)',
            'o'           => 'Operador'
        ];

        $translationsJob = [
            'Developer'            => 'Desenvolvedor',
            'Reseller'             => 'Revenda',
            'Sales Representative' => 'Representante de Vendas',
            'financial'            => 'Financeiro',
            'Team Manager'         => 'Gerente de Equipe'
        ];

        // 🔁 Traduz os campos
        foreach ($result as &$row) {
            $row['user_function_translated'] = $translations[$row['user_function']] ?? $row['user_function'];
            $row['job_title_translated']     = $translationsJob[$row['job_title']] ?? $row['job_title'];
        }

        return $result;
    }


    /**
     * Retorna usuários pelo tenancy e opcionalmente pelo userId
     */
    public static function getUserById(string $tenancyId, int $userId): ?array
    {
        $db = new Database('users u 
            INNER JOIN tenancies t ON t.id = u.tenancy_id');

        $where = 'u.tenancy_id = :tenancy_id AND u.id = :id';
        $params = [
            ':tenancy_id' => $tenancyId,
            ':id' => $userId
        ];

        $user = $db->select(
            $where,
            $params,
            null,
            null,
            'u.id, 
             u.name,
             u.last_name, 
             u.email,
             u.image, 
             u.password,
             u.job_title, 
             u.status_account, 
             u.user_function, 
             u.last_activity, 
             u.createdAt, 
             t.name AS tenancy_name,
             t.tenancy_phone AS tenancy_phone'
        )->fetch(\PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    public function insertUsers(): int
    {

        $now = date('Y-m-d H:i:s');

        $fields = [
            'name'           => $this->name,
            'user_id'        => $this->user_id,
            'last_name'      => $this->last_name,
            'email'          => $this->email,
            'user_function'  => $this->user_function,
            'role_id'        => $this->role_id ?? 0, // 🚀 ADICIONE ESTA LINHA
            'password'       => $this->password,
            'status'         => 'n',
            'status_account' => $this->status_account ?? 'inactive',
            'last_activity'  => !empty($this->last_activity) ? $this->last_activity : null,
            'createdAt'      => $this->createdAt ?? $now,
            'updatedAt'      => $this->updatedAt ?? $now,
        ];

        if ($this->tenancy_id) {
            $fields['tenancy_id'] = $this->tenancy_id;
        }
        $this->id = (new Database('users'))->insert($fields);

        return $this->id;
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

    public static function updateUsers(int $userId, array $data, string $tenancyId): bool
    {
        if (empty($userId) || $userId <= 0) {
            return false;
        }

        // ─── Valida e monta campos para update ───
        $fields = [
            'name'               => trim($data['name'] ?? ''),
            'last_name'          => trim($data['last_name'] ?? ''),
            'email'              => strtolower(trim($data['email'] ?? '')),
            'user_function'      => $data['user_function'],
            'role_id'            => $data['role_id'] ?? 0, // 🚀 ADICIONE ESTA LINHA
            'status_account'     => $data['status_account'] ?? 'inactive',
            'updatedAt'          => date('Y-m-d H:i:s'),
        ];

        // Atualiza senha se estiver presente e válida
        if (!empty($data['password']) && ($data['password'] === ($data['confirm_password'] ?? ''))) {
            $fields['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        // ─── Atualiza usuário no banco ───
        return (new Database('users'))->update(
                'id = :id AND tenancy_id = :tenancy_id',
                $fields,
                [
                    ':id' => $userId,
                    ':tenancy_id' => $tenancyId
                ]
            ) > 0;
    }



    public static function updateStatusUser(int $userId, string $tenancyId, string $status): bool
    {
        return (new Database('users'))->update(
                'id = :id AND tenancy_id = :tenancy_id',
                [
                    'status_account' => $status
                ],
                [
                    ':id' => $userId,
                    ':tenancy_id' => $tenancyId
                ]
            ) > 0;
    }

    /**
     * Atualiza apenas o papel (role_id) do usuário
     */
    public static function updateRoleUser(int $userId, string $tenancyId, int $roleId): bool
    {
        return (new Database('users'))->update(
                'id = :id AND tenancy_id = :tenancy_id',
                [
                    'role_id' => $roleId
                ],
                [
                    ':id'         => $userId,
                    ':tenancy_id' => $tenancyId
                ]
            ) > 0;
    }

    public static function deleteUsers(int $userId, string $tenancyId): bool
    {
        return (new Database('users'))->delete(
                'id = :id AND tenancy_id = :tenancy_id',
                [
                    ':id' => $userId,
                    ':tenancy_id' => $tenancyId
                ]
            ) > 0;
    }

}

