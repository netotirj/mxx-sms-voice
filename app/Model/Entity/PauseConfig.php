<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;

class PauseConfig
{
    public $id;
    public $tenancy_id;
    public $user_id;
    public $name;
    public $description;
    public $type;
    public $max_time;
    public $icon;
    public $status;
    public $created_at;
    public $updated_at;

    /**
     * Salva ou Atualiza uma configuração de pausa
     * @param array $data
     * @return int|bool
     */
    public static function saveBreak(array $data): bool|int
    {
        $db = new Database('pausas_config');

        if (!empty($data['id'])) {
            $id = (int)$data['id'];
            unset($data['id']);

            return $db->update('id = ' . $id, $data);
        }

        return $db->insert($data);
    }

    /**
     * Busca a lista de pausas respeitando tenancy e filtro opcional por usuário
     */
    public static function getBreaksList(string $tenancyId, ?int $filterUserId = null, ?string $status = null): array
    {
        $db = new Database('pausas_config');

        $where = 'tenancy_id = :tenancy_id';
        $params = [
            ':tenancy_id' => $tenancyId
        ];

        if (!is_null($filterUserId)) {
            $where .= ' AND user_id = :user_id';
            $params[':user_id'] = $filterUserId;
        }

        if (!is_null($status) && $status !== '') {
            $where .= ' AND status = :status';
            $params[':status'] = $status;
        }

        $result = $db->select($where, $params, null, null, '*');

        if (!$result) {
            return [];
        }

        $pausas = $result->fetchAll(\PDO::FETCH_ASSOC);
        return $pausas ?: [];
    }

    /**
     * Busca os dados de uma pausa específica
     */
    public static function getBreakById(string $id, string $tenancyId): array
    {
        $db = new Database('pausas_config');

        $result = $db->select(
            'id = :id AND tenancy_id = :tenancy_id',
            [
                ':id' => $id,
                ':tenancy_id' => $tenancyId
            ]
        );

        if (!$result) {
            return [];
        }

        $pause = $result->fetch(\PDO::FETCH_ASSOC);
        return $pause ?: [];
    }

    /**
     * Exclui uma configuração de pausa
     */
    public static function deleteBreak(int $id): bool
    {
        $db = new Database('pausas_config');
        return $db->delete('id = ' . $id);
    }
}