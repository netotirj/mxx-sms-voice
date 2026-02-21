<?php

namespace App\Model\Entity;

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

        return (new Database('campaign'))
            ->select('id = :id AND tenancy_id = :tenancy_id', [
                ':id' => $id,
                ':tenancy_id' => $tenancy_id
            ])
            ->fetchObject(self::class) ?: null;
    }

    /*public static function countCampaignsByTenancyAndUser(?string $tenancy_id, ?int $user_id, ?string $status = null): int
    {
        $db = new Database('campaign');

        $where = 'tenancy_id = :tenancy_id AND user_id = :user_id';
        $params = [
            ':tenancy_id' => $tenancy_id,
            ':user_id' => $user_id,
        ];

        if ($status !== null) {
            $where .= ' AND status = :status';
            $params[':status'] = $status;
        }

        $count = $db->select(
            $where,
            $params,
            null,
            null,
            'COUNT(*) as total'
        )->fetchColumn();

        return (int) $count;
    }*/

    public static function countCampaignsByTenancyAndUser(?string $tenancy_id, ?int $user_id, ?string $status = null): int
    {
        $db = new Database('campaign');

        $where  = "1 = 1";
        $params = [];

        // Só aplica filtro se NÃO for null
        if ($tenancy_id !== null) {
            $where .= " AND tenancy_id = :tenancy_id";
            $params[':tenancy_id'] = $tenancy_id;
        }

        if ($user_id !== null) {
            $where .= " AND user_id = :user_id";
            $params[':user_id'] = $user_id;
        }

        if ($status !== null) {
            $where .= " AND status = :status";
            $params[':status'] = $status;
        }

        $count = $db->select(
            $where,
            $params,
            null,
            null,
            'COUNT(*) as total'
        )->fetchColumn();

        return (int) $count;
    }

    public static function countCampaignsByStatus(?string $tenancyId, ?int $userId = null): array
    {
        return [
            'ativa'      => self::countCampaignsByTenancyAndUser($tenancyId, $userId, 'y'),
            'finalizada' => self::countCampaignsByTenancyAndUser($tenancyId, $userId, 'f'),
            'inativa'    => self::countCampaignsByTenancyAndUser($tenancyId, $userId, 'n'),
        ];
    }



    public static function getContactsForSmsDispatch(int $campaignId, string $tenancyId, int $userId): array
    {
        try {
            $results = (new Database('contacts INNER JOIN campaign AS c ON c.id = contacts.campaign_id'))
                ->select(
                    '
                contacts.campaign_id = :campaignId
                AND contacts.tenancy_id = :tenancyId
                AND c.tenancy_id = contacts.tenancy_id
                AND c.user_id = :userId
                ',
                    [
                        ':campaignId' => $campaignId,
                        ':tenancyId'  => $tenancyId,
                        ':userId'     => $userId
                    ],
                    null,
                    null,
                    'contacts.phone, c.message, c.name, c.type_msg, c.charset_msg'
                )
                ->fetchAll(PDO::FETCH_ASSOC);

            return is_array($results) ? $results : [];
        } catch (\Throwable $e) {
            // Log ou print do erro pode ser adicionado aqui
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
