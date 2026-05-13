<?php

namespace App\Model\Entity;

use App\Utils\TenancyHelper;
use WilliamCosta\DatabaseManager\Database;

class CampaignVoice
{

    public int $id;
    public int $user_id;
    public string $tenancy_id;
    public string $name;
    public string $type;
    public int $total_contacts;
    public string $job_id;
    public ?string $queue_id = null;
    public string $status;
    public string $total_calls;
    public string $answered_calls;
    public string $failed_calls;
    public string $updated_at;
    public string $created_at;

    /**
     * Retorna todas as listas de voz (filtradas ou não)
     */

    public function create(): bool
    {
        $this->id = (new Database('campaign_voice'))->insert([
            'user_id'        => $this->user_id,
            'tenancy_id'     => $this->tenancy_id,
            'name'           => $this->name,
            'type'           => $this->type, // 🔴 obrigatório
            'job_id'         => $this->job_id,
            'queue_id'       => $this->queue_id ?? '',
            'total_contacts' => $this->total_contacts,
            'status'         => $this->status
        ]);

        return !empty($this->id);
    }


    public static function getVoiceLists(?int $userId = null, ?string $tenancyId = null, ?string $role = null): array
    {
        $userContext = [
            'tenancy_id'    => $tenancyId,
            'id'            => $userId,
            'user_function' => $role !== null && $role !== ''
                ? strtolower(trim($role))
                : (is_null($userId) ? 'admin' : 'reseller')
        ];

        // O Helper já gera: "tenancy_id = X AND (user_id = Y...)"
        $where = TenancyHelper::applySecurityFilter('', $userContext, 'user_id', 'voice_list');

        $results = (new Database('voice_list'))->select(
            $where,
            [], // Params vazios pois o Helper injeta os valores tratados
            'id DESC'
        )->fetchAll(\PDO::FETCH_ASSOC);

        return $results ?: [];
    }

    public static function getVoiceListsDetail(?int $userId = null, ?string $tenancyId = null, ?string $role = null): array
    {
        $userContext = [
            'tenancy_id'    => $tenancyId,
            'id'            => $userId,
            'user_function' => $role !== null && $role !== ''
                ? strtolower(trim($role))
                : (is_null($userId) ? 'admin' : 'reseller')
        ];

        $where = TenancyHelper::applySecurityFilter('', $userContext, 'user_id', 'campaign_voice');

        return (new Database('campaign_voice'))
            ->select($where, [], 'id DESC')
            ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function getPhonesByListId(int $listId, string $tenancyId, int $userId = null): array
    {
        try {
            $db = new Database('voice_list_contacts vc INNER JOIN voice_list vl ON vl.id = vc.voice_list_id');

            $userContext = [
                'tenancy_id'    => $tenancyId,
                'id'            => $userId,
                'user_function' => is_null($userId) ? 'admin' : 'reseller'
            ];

            // Filtro fixo (list_id) + Filtro de Segurança do Helper
            $where = TenancyHelper::applySecurityFilter('vc.voice_list_id = :list_id', $userContext, 'user_id', 'vl');

            return $db->select($where, [':list_id' => $listId], null, null, 'vc.phone, vc.voice_list_id')
                ->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        } catch (\Throwable $e) {
            return [];
        }
    }


    public static function getContactDetailsByListAndPhone(int $listId, string $phone, string $tenancyId, int $userId): ?array
    {
        try {
            $db = new Database('voice_list_contacts vc 
        INNER JOIN voice_list vl ON vl.id = vc.voice_list_id');

            // Filtro Triplo de Segurança:
            // 1. A lista certa (voice_list_id)
            // 2. O telefone certo (phone)
            // 3. O dono da lista (user_id) + a empresa (tenancy_id)
            $where = 'vc.voice_list_id = :list_id 
                  AND vc.phone = :phone 
                  AND vl.tenancy_id = :tenancy_id 
                  AND vl.user_id = :user_id';

            $params = [
                ':list_id'    => $listId,
                ':phone'      => $phone,
                ':tenancy_id' => $tenancyId,
                ':user_id'    => $userId
            ];

            $stmt = $db->select($where, $params, null, '1', 'vc.*, vl.name as campaign_name');
            $data = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $data ?: null;

        } catch (\Throwable $e) {
            error_log('getContactDetails error: ' . $e->getMessage());
            return null;
        }
    }


    public static function countVoiceCampaignsByStatus(?string $tenancyId = null, ?int $userId = null): array
    {
        $userContext = [
            'tenancy_id'    => $tenancyId,
            'id'            => $userId,
            'user_function' => is_null($userId) ? 'admin' : 'reseller'
        ];

        $where = TenancyHelper::applySecurityFilter('', $userContext, 'user_id', 'campaign_voice');

        $sql = "
            SELECT
                SUM(CASE WHEN status = 'y' THEN 1 ELSE 0 END) AS y,
                SUM(CASE WHEN status = 'p' THEN 1 ELSE 0 END) AS p,
                SUM(CASE WHEN status = 'n' THEN 1 ELSE 0 END) AS n,
                SUM(CASE WHEN status = 'f' THEN 1 ELSE 0 END) AS f,
                SUM(CASE WHEN status = 'c' THEN 1 ELSE 0 END) AS c
            FROM campaign_voice
            WHERE {$where}
        ";

        $row = (new Database('campaign_voice'))->execute($sql)->fetchObject();

        return [
            'y' => (int)($row->y ?? 0),
            'p' => (int)($row->p ?? 0),
            'n' => (int)($row->n ?? 0),
            'f' => (int)($row->f ?? 0),
            'c' => (int)($row->c ?? 0),
        ];
    }

    /**
     * Retorna todos os contatos vinculados a uma lista
     */
    public static function getContactsByListId(int $voiceListId): array
    {
        $where  = 'voice_list_id = :voice_list_id';
        $params = [':voice_list_id' => $voiceListId];

        $results = (new Database('voice_list_contacts'))->select(
            $where,
            $params
        )->fetchAll(\PDO::FETCH_ASSOC);

        return $results ?: [];
    }

    /**
     * Cria uma nova lista de voz
     */
    public static function createVoiceList(int $userId, string $tenancyId, string $name): int
    {
        $db = new Database('voice_list');

        $voiceListId = $db->insert([
            'user_id'        => $userId,
            'tenancy_id'     => $tenancyId,
            'name'           => $name,
            'status'         => 'active',
            'total_contacts' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s')
        ]);

        return (int)$voiceListId;
    }

    /**
     * Adiciona um contato a uma lista
     */

    public static function addContactToList(
        int $voiceListId,
        string $name,
        string $phone,
        ?string $cpf = null,
        ?string $extraData = null,

    ): int
    {
        $db = new Database('voice_list_contacts');

        $contactId = $db->insert([
            'voice_list_id' => $voiceListId,
            'name'          => $name,
            'phone'         => $phone,
            'cpf'           => $cpf,
            'extra_data'    => $extraData,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s')
        ]);

        return (int)$contactId;
    }

    /**
     * Atualiza o total de contatos de uma lista
     */
    public static function updateTotalContacts(int $voiceListId, int $count): bool
    {
        $db = new Database('voice_list');

        return $db->update(
                'id = :id',
                [
                    'total_contacts' => $count,
                    'updated_at'     => date('Y-m-d H:i:s')
                ],
                [':id' => $voiceListId]
            ) > 0;
    }

    public static function incrementCampaignCounters(
        int $campaignId,
        string $dialstatus
    ): bool {
        $dialstatus = strtoupper(trim($dialstatus));

        $answered = ($dialstatus === 'ANSWER') ? 1 : 0;
        $failed   = ($dialstatus === 'ANSWER') ? 0 : 1;

        $sql = "
        UPDATE campaign_voice
        SET
            total_calls    = total_calls + 1,
            answered_calls = answered_calls + :answered,
            failed_calls   = failed_calls + :failed,
            status         = CASE
                WHEN status NOT IN ('c', 'n') AND total_calls + 1 >= total_contacts THEN 'f'
                ELSE status
            END,
            updated_at     = NOW()
        WHERE id = :campaign_id
    ";

        $params = [
            ':campaign_id' => $campaignId,
            ':answered'    => $answered,
            ':failed'      => $failed,
        ];

        (new Database('campaign_voice'))->execute($sql, $params);

        return true;
    }

    public static function getById(int $id): ?array
    {
        // Aqui, como não recebemos tenancy_id no parâmetro, o ideal é que
        // a sua Controller valide isso antes ou que o getById receba a Tenancy.
        // Se for uma busca interna do sistema (motor de voz), deixamos o ID simples:

        $r = (new Database('campaign_voice'))->select(
            'id = :id',
            [':id' => $id]
        )->fetch(\PDO::FETCH_ASSOC);

        return $r ?: null;
    }


    public static function updateStatusByJob(string $jobId, string $status): bool
    {
        // Usamos o placeholder :job_id para evitar quebras por caracteres especiais ou SQL Injection
        return (new Database('campaign_voice'))->update(
            'job_id = :job_id',
            ['status' => $status],
            [':job_id' => $jobId]
        );
    }

    public static function deleteVoiceList(int $voiceListId): bool
    {
        // Apaga os contatos primeiro (boa prática; evita FK)
        $dbContacts = new Database('voice_list_contacts');
        $dbContacts->delete('voice_list_id = :id', [':id' => $voiceListId]);

        // Agora apaga a lista
        $dbList = new Database('voice_list');
        return $dbList->delete('id = :id', [':id' => $voiceListId]) > 0;
    }

}
