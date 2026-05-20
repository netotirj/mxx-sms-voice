<?php

namespace App\Model\Entity;

use App\Utils\TenancyHelper;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class CampaignSearch
{
    public ?int $id;
    public ?string $tenancy_id;
    public ?int $user_id;
    public ?string $name;
    public ?string $message;
    public ?string $status;
    public ?string $type_msg;
    public ?int $charset_msg;
    public ?string $created_at;
    public ?string $updated_at;


    /**
     * Retorna a quantidade total de campanhas por tenancy_id e busca opcional.
     *
     * @param string $tenancyId
     * @param string|null $searchValue
     * @param int|null $userId
     * @return int
     */

    public static function getCampaignsCount(string $tenancyId, ?string $searchValue = null, ?int $userId = null): int
    {
        $query = "SELECT COUNT(*) as qtd FROM campaign WHERE tenancy_id = :tenancy_id";
        $params = [':tenancy_id' => $tenancyId];

        if ($userId !== null) {
            $query .= " AND user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        if (!empty($searchValue)) {
            $query .= " AND (name LIKE :search OR message LIKE :search)";
            $params[':search'] = '%' . $searchValue . '%';
        }

        return (new Database)->execute($query, $params)
            ->fetchObject()
            ->qtd ?? 0;
    }


    /**
     * Retorna campanhas paginadas e ordenadas para tenancy_id, com busca opcional.
     *
     * @param string $tenancyId
     * @param string|null $searchValue
     * @param int $start
     * @param int $length
     * @param string $orderColumn
     * @param string $orderDir
     * @param int|null $userId
     * @return self[]
     */

    public static function getCampaignsPaginated(
        string $tenancyId,
        ?string $searchValue,
        int $start,
        int $length,
        string $orderColumn = 'id',
        string $orderDir = 'DESC',
        ?int $userId = null
    ): array {
        $params = [':tenancy_id' => $tenancyId];
        $where = "c.tenancy_id = :tenancy_id";

        if ($userId !== null) {
            $where .= " AND c.user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        if (!empty($searchValue)) {
            $where .= " AND (c.name LIKE :search OR c.message LIKE :search)";
            $params[':search'] = '%' . $searchValue . '%';
        }

        $order = "c.{$orderColumn} {$orderDir}";
        $limit = "{$start}, {$length}";

        $query = "
        SELECT 
            c.id,
            c.name,
            c.message,
            c.status,
            c.created_at,
            COUNT(IF(ct.phone IS NOT NULL AND ct.phone <> '', 1, NULL)) AS qtd_contacts
        FROM campaign c
        LEFT JOIN contacts ct 
            ON ct.campaign_id = c.id AND ct.tenancy_id = c.tenancy_id
        WHERE {$where}
        GROUP BY c.id
        ORDER BY {$order}
        LIMIT {$limit}
    ";

        return (new Database)->execute($query, $params)
            ->fetchAll(\PDO::FETCH_OBJ);
    }

    public function register(): bool
    {
        $this->id = (new Database('campaign'))->insert([
            'tenancy_id' => $this->tenancy_id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'message' => $this->message,
            'status' => $this->status,
            'charset_msg' => $this->charset_msg,
            'type_msg' => $this->type_msg,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ]);

        return true;
    }

    public static function getCampaignByIdAndTenancy(int $id, string $tenancy_id): ?self
    {
        // O contexto aqui valida se o ID da campanha pertence à Tenancy
        $where = TenancyHelper::applySecurityFilter("id = :id", [
            'tenancy_id' => $tenancy_id,
            'user_function' => 'admin' // Admin da própria tenancy
        ], 'user_id', 'campaign');

        return (new Database('campaign'))
            ->select($where, [':id' => $id])
            ->fetchObject(self::class) ?: null;
    }

    public static function countCampaignsByTenancyAndUser(
        ?string $tenancy_id,
        ?int $user_id,
        ?string $status = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): int
    {
        $db = new Database('campaign');

        // Contexto para o Helper decidir se filtra por user_id ou só tenancy
        $userContext = [
            'tenancy_id'    => $tenancy_id,
            'id'            => $user_id,
            'user_function' => is_null($user_id) ? 'admin' : 'reseller'
        ];

        // O status continua sendo um filtro manual (existingWhere)
        $initialConditions = [];
        $params = $status !== null ? [':status' => $status] : [];

        if ($status !== null) {
            $initialConditions[] = "status = :status";
        }

        if ($startDate !== null && $endDate !== null) {
            $initialConditions[] = "created_at BETWEEN :start_date AND :end_date";
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
        }

        $initialWhere = implode(' AND ', $initialConditions);

        $where = TenancyHelper::applySecurityFilter($initialWhere, $userContext, 'user_id', 'campaign');

        $count = $db->select(
            $where,
            $params,
            null,
            null,
            'COUNT(*) as total'
        )->fetchColumn();

        return (int) $count;
    }

    public static function countCampaignsByStatus(
        ?string $tenancyId,
        ?int $userId = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): array
    {
        return [
            'ativa'      => self::countCampaignsByTenancyAndUser($tenancyId, $userId, 'y', $startDate, $endDate),
            'finalizada' => self::countCampaignsByTenancyAndUser($tenancyId, $userId, 'f', $startDate, $endDate),
            'inativa'    => self::countCampaignsByTenancyAndUser($tenancyId, $userId, 'n', $startDate, $endDate),
        ];
    }



    public static function getContactsForSmsDispatch(int $campaignId, string $tenancyId, int $userId): array
    {
        try {
            // Usamos o alias 'c' para a tabela campaign para o Helper não se perder
            $userContext = [
                'tenancy_id'    => $tenancyId,
                'id'            => $userId,
                'user_function' => 'reseller'
            ];

            // Filtro base: o ID da campanha
            $where = TenancyHelper::applySecurityFilter('contacts.campaign_id = :campaignId', $userContext, 'user_id', 'c');

            $results = (new Database('contacts INNER JOIN campaign AS c ON c.id = contacts.campaign_id'))
                ->select(
                    $where,
                    [':campaignId' => $campaignId],
                    null,
                    null,
                    'contacts.phone, c.message, c.name, c.type_msg, c.charset_msg'
                )
                ->fetchAll(PDO::FETCH_ASSOC);

            return is_array($results) ? $results : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Atualiza os dados da campanha no banco de dados
     */

    public function update(): bool
    {
        // Proteção: impede update se ID ou tenancy_id estiverem vazios
        if (empty($this->id) || empty($this->tenancy_id)) {
            return false;
        }

        // Atualiza no banco
        return (new Database('campaign'))->update('id = ' . (int)$this->id, [
            'name'       => htmlspecialchars(trim($this->name)),
            'message'     => htmlspecialchars(trim($this->message)),
            'type_msg'    => htmlspecialchars(trim($this->type_msg)),
            'charset_msg' => htmlspecialchars(trim($this->charset_msg)),
            'status'      => $this->status ?? 'active',
            'updated_at'  => date('Y-m-d H:i:s')
        ]);
    }

    public static function updateStatusCamp(int $id, string $tenancyId, string $newStatus = 'f'): bool
    {
        return (new Database('campaign'))->update(
                'id = :id AND tenancy_id = :tenancy_id',
                [
                    'status' => $newStatus
                ],
                [
                    ':id' => $id,
                    ':tenancy_id' => $tenancyId
                ]
            ) > 0;
    }

    public static function deleteByIdAndTenancy(int $id, string $tenancyId): bool
    {
        return (new Database('campaign'))->delete(
                'id = :id AND tenancy_id = :tenancy_id',
                [
                    ':id' => $id,
                    ':tenancy_id' => $tenancyId
                ]
            ) > 0;
    }

}
