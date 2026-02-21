<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;

class UserAuthentication
{
    public ?int $id = null;
    public ?string $name = null;
    public ?string $last_name = null;
    public ?string $tenancy_id = null;
    public ?string $email = null;
    public ?string $job_title = null;
    public ?string $status_account = null;
    public ?string $user_function;
    public ?string $password = null;
    public ?string $status = null;
    public ?string $last_activity = null;
    public ?string $remember_token = null;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;

    /**
     * Registra um novo usuário no banco de dados.
     */

    public function RegisterUsers(): int
    {

        $now = date('Y-m-d H:i:s');

        $fields = [
            'name'        => $this->name,
            'last_name'    => $this->last_name,
            'email'       => $this->email,
            'job_title'   => $this->job_title,
            'user_function' => $this->user_function,
            'password'    => $this->password,
            'status'      => $this->status ?? 'n',
            'createdAt'  => $this->createdAt ?? $now,
            'updatedAt'  => $this->updated_at ?? $now,
        ];

        if (!empty($this->tenancy_id)) {
            $fields['tenancy_id'] = $this->tenancy_id;
        }

        if (!empty($this->last_activity)) {
            $fields['last_activity'] = $this->last_activity;
        }

        $this->id = (new Database('users'))->insert($fields);

        return $this->id;
    }


    public function updateProfile(): bool
    {
        return (new Database('users'))->update('id = ' . (int)$this->id, [
            'email'  => $this->email,
            'status' => $this->status,
        ]);
    }

    public function updatePassword(): bool
    {
        return (new Database('users'))->update('email = "' . addslashes($this->email) . '"', [
            'password' => $this->password,
        ]);
    }

    public function updateStatusUser(): bool
    {
        return (new Database('users'))->update('email = "' . addslashes($this->email) . '"', [
            'status' => $this->status,
        ]);
    }

    /*public static function getUserByEmail(string $email): ?self
    {
        return (new Database('users'))
            ->select('email = "' . addslashes($email) . '"')
            ->fetchObject(self::class) ?: null;
    }*/

    public static function getUserByEmail(string $email): ?self
    {
        $db = new Database();

        $query = "SELECT u.*, t.account_code
              FROM users u
              INNER JOIN tenancies t ON t.id = u.tenancy_id
              WHERE u.email = :email
              LIMIT 1";

        $params = [':email' => $email];

        $result = $db->execute($query, $params)->fetchObject(self::class);

        return $result ?: null;
    }

    public static function getUserByAccountCode(string $accountCode): ?self
    {
        $db = new Database();

        $query = "SELECT u.*, t.account_code
              FROM users u
              INNER JOIN tenancies t ON t.id = u.tenancy_id
              WHERE t.account_code = :code
              AND u.user_function = 'admin'
              LIMIT 1";

        $params = [':code' => $accountCode];

        $result = $db->execute($query, $params)->fetchObject(self::class);

        return $result ?: null;
    }


    public static function setStatus(string $email, string $status): bool
    {
        $obUser = new self();
        $obUser->email = $email;
        $obUser->status = $status;
        return $obUser->updateStatusUser();
    }

    public static function setStatusAndActivity(string $email, string $status, string $activityTimestamp): bool
    {
        return (new Database('users'))->update(
            'email = "' . addslashes($email) . '"',
            [
                'status' => $status,
                'last_activity' => $activityTimestamp
            ]
        );
    }

    public static function updateLastActivity(string $email, string $timestamp): bool
    {
        return (new Database('users'))->update(
            'email = "' . addslashes($email) . '"',
            ['last_activity' => $timestamp]
        );
    }

    public static function setRememberToken(int $userId, string $hashedToken, string $tenancyId): bool
    {
        // Garante que tenancyId seja string segura
        $tenancyId = addslashes($tenancyId);

        return (new Database('users'))->update(
            'id = ' . (int)$userId . ' AND tenancy_id = "' . $tenancyId . '"',
            ['remember_token' => $hashedToken]
        );
    }

    public static function clearRememberToken(int $userId): bool
    {
        return (new Database('users'))->update(
            'id = ' . (int)$userId,
            ['remember_token' => '']  // ✅ String vazia
        );
    }

    public static function getUserByRememberToken(string $token, string $tenancyId): ?self
    {
        // Seleciona apenas os usuários do tenant que têm token definido
        $results = (new Database('users'))->select(
            'remember_token IS NOT NULL AND tenancy_id = :tenancyId',
            ['tenancyId' => $tenancyId]
        );

        // Verifica cada hash armazenado
        while ($row = $results->fetchObject(self::class)) {
            if (password_verify($token, $row->remember_token)) {
                return $row; // Retorna usuário correspondente
            }
        }

        return null; // Nenhum token válido encontrado
    }


}

