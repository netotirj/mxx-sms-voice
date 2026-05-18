<?php

namespace App\Service;

use WilliamCosta\DatabaseManager\Database;

class PlanAccessPolicy
{
    private const int ACTIVE_WINDOW_SECONDS = 3600;

    public static function currentPlan(string $tenancyId): ?array
    {
        return PlanRuntimeService::getActivePlanByTenancy($tenancyId);
    }

    public static function canCreateUsers(string $tenancyId): bool
    {
        return PlanRuntimeService::canUseFeature($tenancyId, 'administrative')
            && PlanRuntimeService::canUseFeature($tenancyId, 'create_users');
    }

    public static function trunkLimit(string $tenancyId): ?int
    {
        $limits = PlanRuntimeService::getEffectiveLimits($tenancyId);
        return array_key_exists('trunks', $limits)
            ? self::normalizeCapabilityLimit($limits['trunks'])
            : null;
    }

    public static function whatsAppAccountLimit(string $tenancyId): ?int
    {
        $limits = PlanRuntimeService::getEffectiveLimits($tenancyId);
        return array_key_exists('whatsapp_accounts', $limits)
            ? self::normalizeCapabilityLimit($limits['whatsapp_accounts'])
            : null;
    }

    public static function concurrentLimit(string $tenancyId): ?int
    {
        $limits = PlanRuntimeService::getEffectiveLimits($tenancyId);
        if (!array_key_exists('simultaneous_access', $limits)) {
            return null;
        }

        $limit = (int)$limits['simultaneous_access'];

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

    public static function assertCanCreateTrunk(string $tenancyId, int $currentCount): array
    {
        $limit = self::trunkLimit($tenancyId);

        if ($limit === null) {
            return [
                'allowed' => false,
                'message' => 'Nenhum plano ativo foi encontrado para esta conta.',
            ];
        }

        if ($limit < 0) {
            return [
                'allowed' => true,
                'limit' => $limit,
                'current' => $currentCount,
            ];
        }

        if ($currentCount >= $limit) {
            return [
                'allowed' => false,
                'message' => 'Limite de trunks do plano atingido. Por favor, contate o administrador da conta.',
                'limit' => $limit,
                'current' => $currentCount,
            ];
        }

        return [
            'allowed' => true,
            'limit' => $limit,
            'current' => $currentCount,
        ];
    }

    public static function assertCanCreateWhatsAppAccount(string $tenancyId, int $currentCount): array
    {
        $limit = self::whatsAppAccountLimit($tenancyId);

        if ($limit === null) {
            return [
                'allowed' => false,
                'message' => 'Nenhum plano ativo foi encontrado para esta conta.',
            ];
        }

        if ($limit < 0) {
            return [
                'allowed' => true,
                'limit' => $limit,
                'current' => $currentCount,
            ];
        }

        if ($currentCount >= $limit) {
            return [
                'allowed' => false,
                'message' => 'Limite de contas WhatsApp atingido. Por favor, contate o administrador da conta.',
                'limit' => $limit,
                'current' => $currentCount,
            ];
        }

        return [
            'allowed' => true,
            'limit' => $limit,
            'current' => $currentCount,
        ];
    }

    public static function currentWhatsAppAccountsCount(string $tenancyId, ?int $ignoreNumberId = null): int
    {
        $params = [
            ':tenancy_id' => $tenancyId,
            ':removed_status' => 'removed',
            ':rejected_status' => 'rejected',
            ':deleted_status' => 'deleted',
            ':cancelled_status' => 'cancelled',
            ':active_status' => 'active',
        ];
        $ignoreSql = '';

        if ($ignoreNumberId !== null && $ignoreNumberId > 0) {
            $ignoreSql = ' AND wn.id <> :ignore_number_id';
            $params[':ignore_number_id'] = $ignoreNumberId;
        }

        $sql = "
            SELECT COUNT(*) AS total
            FROM (
                SELECT wn.id
                FROM whatsapp_numbers wn
                WHERE wn.company_id = :tenancy_id
                  AND wn.removed_at IS NULL
                  AND wn.status NOT IN (:removed_status, :rejected_status, :deleted_status, :cancelled_status)
                  {$ignoreSql}

                UNION

                SELECT wa.id
                FROM whatsapp_accounts wa
                LEFT JOIN whatsapp_numbers wn
                  ON wn.whatsapp_account_id = wa.id
                 AND wn.company_id = wa.tenancy_id
                 AND wn.removed_at IS NULL
                 AND wn.status NOT IN (:removed_status, :rejected_status, :deleted_status, :cancelled_status)
                WHERE wa.tenancy_id = :tenancy_id
                  AND wa.status = :active_status
                  AND wn.id IS NULL
            ) AS wa_capability_count
        ";

        $row = (new Database())->execute($sql, $params)->fetch(\PDO::FETCH_ASSOC);
        return (int)($row['total'] ?? 0);
    }

    private static function normalizeCapabilityLimit(mixed $value): int
    {
        $limit = (int)$value;
        if ($limit < 0) {
            return -1;
        }

        return max(0, $limit);
    }
}
