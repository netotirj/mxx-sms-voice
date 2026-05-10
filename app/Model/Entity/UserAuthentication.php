<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;

class UserAuthentication
{
    public ?int $id = null;
    public ?int $role_id = null;
    public ?int $account_code  = null;
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
            'role_id'       => $this->role_id,
            'password'    => $this->password,
            'status'      => $this->status ?? 'n',
            'account_code' => $this->account_code,
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
        return (new Database('users'))->update('email = :email', [
            'password' => $this->password,
        ], [
            ':email' => (string)$this->email,
        ]);
    }

    public function updateStatusUser(): bool
    {
        return (new Database('users'))->update('email = :email', [
            'status' => $this->status,
        ], [
            ':email' => (string)$this->email,
        ]);
    }

    public static function getUserByEmail(string $email): ?self
    {
        return (new Database('users'))
            ->select('email = :email', [
                ':email' => $email,
            ])
            ->fetchObject(self::class) ?: null;
    }

    public static function getUserById(int $id): ?self
    {
        return (new Database('users'))
            ->select('id = ' . (int)$id . ' LIMIT 1')
            ->fetchObject(self::class) ?: null;
    }


    public static function getUserByAccountCode(string $accountCode): ?self
    {
        $db = new Database();

        $query = "SELECT *
              FROM users
              WHERE account_code = :code
                AND user_function IN ('admin','super_admin')
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
            'email = :email',
            [
                'status' => $status,
                'last_activity' => $activityTimestamp
            ],
            [
                ':email' => $email,
            ]
        );
    }

    public static function updateLastActivity(string $email, string $timestamp): bool
    {
        return (new Database('users'))->update(
            'email = :email',
            ['last_activity' => $timestamp],
            [':email' => $email]
        );
    }

    public static function setRememberToken(int $userId, string $hashedToken, string $tenancyId): bool
    {
        return (new Database('users'))->update(
            'id = :id AND tenancy_id = :tenancy_id',
            ['remember_token' => $hashedToken],
            [
                ':id' => $userId,
                ':tenancy_id' => $tenancyId,
            ]
        );
    }

    public static function clearRememberToken(int $userId): bool
    {
        return (new Database('users'))->update(
            'id = ' . (int)$userId,
            ['remember_token' => '']  // ✅ String vazia
        );
    }

    public static function getUserByRememberToken(string $token): ?self
    {
        if (empty($token)) {
            return null;
        }

        $results = (new Database('users'))->select(
            'remember_token IS NOT NULL AND remember_token != ""'
        );

        while ($row = $results->fetchObject(self::class)) {
            if (!empty($row->remember_token) && password_verify($token, $row->remember_token)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Invalida os tokens de acesso persistente do usuário no banco
     */
    public static function invalidateUserSession(int $userId, string $tenancyId): bool
    {
        return (new Database('users'))->update(
            'id = :id AND tenancy_id = :tenancy_id',
            [
                'remember_token' => '',              // 🔥 mata auto login
                'last_activity'  => null,            // 🔥 zera presença
                'status'         => 'n'              // 🔥 marca offline
            ],
            [
                ':id' => $userId,
                ':tenancy_id' => $tenancyId,
            ]
        );
    }


}
