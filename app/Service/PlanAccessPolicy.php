<?php

namespace App\Service;

use WilliamCosta\DatabaseManager\Database;

class PlanAccessPolicy
{
    private const int ACTIVE_WINDOW_SECONDS = 3600;

    public static function currentPlan(string $tenancyId): ?array
    {
        $query = "
            SELECT p.*
            FROM mxx_user_plans up
            INNER JOIN mxx_plans p ON p.id = up.plan_id
            WHERE up.tenancy_id = :tenancy_id
              AND up.status_payment = 'confirmed'
              AND up.status = 'active'
              AND p.status = 'active'
            ORDER BY up.updated_at DESC, up.created_at DESC, up.id DESC
            LIMIT 1
        ";

        $row = (new Database())->execute($query, [
            ':tenancy_id' => $tenancyId,
        ])->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function canCreateUsers(string $tenancyId): bool
    {
        $plan = self::currentPlan($tenancyId);

        if (!$plan) {
            return false;
        }

        return strtolower((string)($plan['users_create'] ?? 'n')) === 'y';
    }

    public static function concurrentLimit(string $tenancyId): ?int
    {
        $plan = self::currentPlan($tenancyId);

        if (!$plan) {
            return null;
        }

        $limit = (int)($plan['simultaneous_access'] ?? 1);

        return $limit > 0 ? $limit : null;
    }

    public static function activeUsersCount(string $tenancyId, ?int $excludeUserId = null): int
    {
        $where = "
            tenancy_id = :tenancy_id
            AND status = 'y'
            AND last_activity IS NOT NULL
            AND last_activity >= :cutoff
        ";

        $params = [
            ':tenancy_id' => $tenancyId,
            ':cutoff' => date('Y-m-d H:i:s', time() - self::ACTIVE_WINDOW_SECONDS),
        ];

        if ($excludeUserId !== null) {
            $where .= " AND id <> :exclude_user_id";
            $params[':exclude_user_id'] = $excludeUserId;
        }

        $row = (new Database('users'))
            ->select($where, $params, '', '', 'COUNT(*) AS total')
            ->fetch(\PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    public static function assertCanLogin(array $user): array
    {
        $tenancyId = (string)($user['tenancy_id'] ?? '');
        $userId = (int)($user['id'] ?? 0);
        $role = (string)($user['user_function'] ?? '');

        if ($tenancyId === '' || $role === 'super_admin') {
            return ['allowed' => true];
        }

        $limit = self::concurrentLimit($tenancyId);
        if ($limit === null) {
            return ['allowed' => true];
        }

        $active = self::activeUsersCount($tenancyId, $userId);
        if ($active >= $limit) {
            return [
                'allowed' => false,
                'message' => "Limite de {$limit} acesso(s) simultâneo(s) do plano atingido.",
                'limit' => $limit,
                'active' => $active,
            ];
        }

        return [
            'allowed' => true,
            'limit' => $limit,
            'active' => $active,
        ];
    }
}
