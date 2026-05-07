<?php

namespace App\Utils;

/**
 * TenancyHelper - O Guardião da Segurança
 * Centraliza a lógica de multi-tenancy e hierarquia de acesso aos dados.
 */
class TenancyHelper
{
    public static function isSuperAdmin(array $user): bool
    {
        return strtolower(trim((string)($user['user_function'] ?? $user['function'] ?? ''))) === 'super_admin';
    }

    /**
     * Monta prefixo seguro para alias de tabela.
     * Ex:
     *  - 'u'   => 'u.'
     *  - 'c'   => 'c.'
     *  - ''    => ''
     *  - null  => ''
     */
    private static function prefix(?string $alias): string
    {
        $alias = trim((string)$alias);
        return $alias !== '' ? $alias . '.' : '';
    }

    private static function sqlString(mixed $value): string
    {
        return "'" . addslashes((string)$value) . "'";
    }

    /**
     * Aplica o filtro de segurança completo (Tenancy + Hierarquia)
     *
     * @param string      $existingWhere Condição WHERE já existente
     * @param array       $user          Dados do usuário logado
     * @param string      $userColumn    Coluna que vincula o registro ao usuário
     * @param string|null $alias         Alias da tabela (opcional)
     * @return string
     */
    public static function applySecurityFilter(
        string $existingWhere,
        array $user,
        string $userColumn = 'user_id',
        ?string $alias = 'u'
    ): string {
        $tenancyId = (string)($user['tenancy_id'] ?? '');
        $userId    = (int)($user['id'] ?? 0);
        $role      = strtolower($user['user_function'] ?? $user['function'] ?? '');

        $prefix = self::prefix($alias);

        // 🛡️ Filtro base: SEMPRE limita à tenancy do usuário logado
        $securityFilter = "{$prefix}tenancy_id = " . self::sqlString($tenancyId);

        switch ($role) {
            case 'super_admin':
                // Dono/desenvolvedor do sistema: acesso global, sem filtro de tenancy.
                $securityFilter = '1=1';
                break;

            // 🏢 Administrativo / gerencial: vê tudo da tenancy
            case 'admin':
            case 'rh':
            case 'financial':
            case 'reception':
            case 'manager':
            case 'supervisor':
            case 'monitor':
            case 'support_l2':
            case 'support_ticket_manager':
                break;

            // 🤝 Parceiros
            case 'reseller':
                $securityFilter .= " AND ({$prefix}{$userColumn} = {$userId}
                    OR {$prefix}{$userColumn} IN (
                        SELECT id FROM users
                        WHERE user_id = {$userId}
                          AND tenancy_id = " . self::sqlString($tenancyId) . "
                    ))";
                break;

            // 🎧 Operacional / restrito
            case 'agent':
            case 'support_l1':
            case 'ticket_support':
            default:
                // IMPORTANTE:
                // uso userColumn aqui para evitar erro lógico em tabelas onde o vínculo é user_id e não id
                $securityFilter .= " AND {$prefix}{$userColumn} = {$userId}";
                break;
        }

        return empty(trim($existingWhere))
            ? $securityFilter
            : "({$existingWhere}) AND ({$securityFilter})";
    }

    /**
     * Filtro de segurança para CDR / logs / tabelas operacionais ligadas a user_id
     *
     * @param array       $user
     * @param string|null $alias
     * @return string
     */
    public static function applyCdrSecurityFilter(array $user, ?string $alias = 'c'): string
    {
        $tenancyId = (string)($user['tenancy_id'] ?? '');
        $userId    = (int)($user['id'] ?? 0);
        $role      = strtolower($user['user_function'] ?? $user['function'] ?? '');

        $prefix = self::prefix($alias);

        // Filtro base: SEMPRE a tenancy
        $filter = "{$prefix}tenancy_id = " . self::sqlString($tenancyId);

        return match ($role) {
            'super_admin' => '1=1',

            'admin', 'manager', 'supervisor', 'rh', 'financial', 'reception', 'monitor', 'support_l2', 'support_ticket_manager' => $filter,

            'reseller' => "{$filter} AND (
                {$prefix}user_id = {$userId}
                OR {$prefix}user_id IN (SELECT id FROM users WHERE user_id = {$userId})
            )",

            default => "{$filter} AND {$prefix}user_id = {$userId}",
        };
    }

    /**
     * Garante que tenancy_id esteja nos dados de inserção
     */
    public static function withTenancy(array $data, int|string $tenancyId): array
    {
        $data['tenancy_id'] = $tenancyId;
        return $data;
    }
}
