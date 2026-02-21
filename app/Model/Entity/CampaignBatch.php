<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;

class CampaignBatch
{
    public ?int $id;
    public ?int $campaign_id = null;
    public int $user_id;
    public string $tenancy_id;
    public string $created_at;

    /**
     * Cria um novo registro em campaign_batches
     *
     * @param array $data
     * @return int ID do batch criado
     */
    public static function create(array $data): int
    {

        // Executa o INSERT e retorna o ID inserido
        return (new Database('campaign_batches'))->insert([
            'campaign_id' => $data['campaign_id'],
            'user_id' => $data['user_id'],
            'tenancy_id' => $data['tenancy_id']
        ]);
    }

public static function getLastBatchByUser(int $userId, string $tenancyId): ?CampaignBatch
{
    $query = "
        SELECT * FROM campaign_batches
        WHERE user_id = :user_id
        AND tenancy_id = :tenancy_id
        ORDER BY id DESC
        LIMIT 1
    ";

    $params = [
        ':user_id' => $userId,
        ':tenancy_id' => $tenancyId
    ];

    $result = (new Database())->execute($query, $params)->fetchObject(CampaignBatch::class);

    return $result instanceof CampaignBatch ? $result : null;
}


public static function markAsCharged(int $batchId, int $charged = 1): bool
    {
        return (new Database('campaign_batches'))->update(
            'id = ' . $batchId,
            ['charged' => $charged]
        );
    }



}
