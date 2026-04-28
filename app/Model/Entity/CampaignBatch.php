<?php

namespace App\Model\Entity;

use App\Utils\TenancyHelper;
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
        // 🚀 O Helper gera a string de filtro com base nos valores que você passa no contexto
        $where = TenancyHelper::applySecurityFilter('', [
            'tenancy_id'    => $tenancyId,
            'id'            => $userId,
            'user_function' => 'reseller' // Força a regra de segurança do Revendedor
        ], 'user_id', 'campaign_batches'); // Adicionei o alias correto da tabela

        $query = "
    SELECT * FROM campaign_batches 
    WHERE {$where} 
    ORDER BY id DESC 
    LIMIT 1
";

        // ⚠️ Removi os params :user_id e :tenancy_id pois o Helper já inseriu os valores no $where
        $params = [];

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
