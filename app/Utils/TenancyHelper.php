<?php

namespace App\Utils;

/**
 * TenancyHelper
 *
 * Facilita filtragem automática por tenancy_id em consultas
 * multi-tenant usando o wrapper Database.
 */
class TenancyHelper
{
    /**
     * Retorna cláusula WHERE padronizada com tenancy_id.
     *
     * @param string $existingWhere Condição WHERE existente ou vazia.
     * @param int $tenancyId ID do tenant.
     * @return string
     */
    public static function appendTenancyWhere(string $existingWhere, int $tenancyId): string
    {
        $tenancyFilter = 'tenancy_id = ' . intval($tenancyId);

        if (empty($existingWhere)) {
            return $tenancyFilter;
        }

        return '(' . $existingWhere . ') AND ' . $tenancyFilter;
    }

    /**
     * Adiciona tenancy_id ao array de dados de inserção/atualização.
     *
     * @param array $data
     * @param int $tenancyId
     * @return array
     */
    public static function withTenancyId(array $data, int $tenancyId): array
    {
        $data['tenancy_id'] = $tenancyId;
        return $data;
    }
}
