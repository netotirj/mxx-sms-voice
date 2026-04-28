<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;

class CampaignVoice1404
{

    public int $id;
    public int $user_id;
    public string $tenancy_id;
    public string $name;
    public string $type;
    public int $total_contacts;
    public string $job_id;
    public string $queue_id;
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
            'queue_id'       => $this->queue_id,
            'total_contacts' => $this->total_contacts,
            'status'         => $this->status
        ]);

        return !empty($this->id);
    }


    public static function getVoiceLists(?int $userId = null, ?string $tenancyId = null): array
    {
        $where  = [];
        $params = [];

        if (!empty($userId)) {
            $where[] = 'user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        if (!empty($tenancyId)) {
            $where[] = 'tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $tenancyId;
        }

        // Constrói a string final do WHERE
        $whereSql = !empty($where) ? implode(' AND ', $where) : '';

        // Executa o SELECT — note o uso de string vazia no primeiro argumento
        $results = (new Database('voice_list'))->select(
            $whereSql,
            $params,
            'id DESC'
        )->fetchAll(\PDO::FETCH_ASSOC);

        return $results ?: [];
    }

    public static function getVoiceListsDetail(
        ?int $userId = null,
        ?string $tenancyId = null
    ): array {
        $where  = [];
        $params = [];

        // 🔒 Filtro por usuário (quando existir)
        if ($userId !== null) {
            $where[] = 'user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        // 🔒 Filtro por tenancy (quando existir)
        if ($tenancyId !== null) {
            $where[] = 'tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $tenancyId;
        }

        // WHERE dinâmico
        $whereSql = !empty($where)
            ? implode(' AND ', $where)
            : '';

        return (new Database('campaign_voice'))
            ->select(
                $whereSql,
                $params,
                'id DESC'
            )
            ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /*public static function getPhonesByListId(int $listId, string $tenancyId, int $userId = null): array
    {
        try {
            $db = new Database('voice_list_contacts vc 
            INNER JOIN voice_list vl ON vl.id = vc.voice_list_id');

            // filtros base
            $where = '
            vc.voice_list_id = :list_id
            AND vl.tenancy_id = :tenancy_id
        ';

            $params = [
                ':list_id'    => $listId,
                ':tenancy_id' => $tenancyId
            ];

            // se user_id veio, aplica o filtro (mantém fluxo original)
            if (!empty($userId)) {
                $where .= ' AND vl.user_id = :user_id';
                $params[':user_id'] = $userId;
            }

            $stmt = $db->select($where, $params, null, null, 'vc.phone');

            return $stmt->fetchAll(\PDO::FETCH_COLUMN, 0) ?: [];

        } catch (\Throwable $e) {
            error_log('getPhonesByListId error: '.$e->getMessage());
            return [];
        }
    }*/

    public static function getPhonesByListId(int $listId, string $tenancyId, int $userId = null): array
    {
        try {
            $db = new Database('voice_list_contacts vc 
        INNER JOIN voice_list vl ON vl.id = vc.voice_list_id');

            $where = 'vc.voice_list_id = :list_id AND vl.tenancy_id = :tenancy_id';
            $params = [
                ':list_id'    => $listId,
                ':tenancy_id' => $tenancyId
            ];

            if (!empty($userId)) {
                $where .= ' AND vl.user_id = :user_id';
                $params[':user_id'] = $userId;
            }

            // Selecionamos o phone e o voice_list_id
            $stmt = $db->select($where, $params, null, null, 'vc.phone, vc.voice_list_id');

            // Retorna tudo como array associativo
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        } catch (\Throwable $e) {
            error_log('getPhonesByListId error: '.$e->getMessage());
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
        $where  = [];
        $params = [];

        if (!empty($tenancyId)) {
            $where[] = 'tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $tenancyId;
        }

        if (!empty($userId)) {
            $where[] = 'user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $whereSql = $where ? implode(' AND ', $where) : '1=1';

        $sql = "
        SELECT
            SUM(CASE WHEN status = 'y' THEN 1 ELSE 0 END) AS y,
            SUM(CASE WHEN status = 'p' THEN 1 ELSE 0 END) AS p,
            SUM(CASE WHEN status = 'n' THEN 1 ELSE 0 END) AS n,
            SUM(CASE WHEN status = 'f' THEN 1 ELSE 0 END) AS f,
            SUM(CASE WHEN status = 'c' THEN 1 ELSE 0 END) AS c
        FROM campaign_voice
        WHERE {$whereSql}
    ";

        $row = (new Database('campaign_voice'))->execute($sql, $params)->fetchObject();

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
    /*public static function addContactToList(int $voiceListId, string $name, string $phone, string $status = 'pending'): int
    {
        $db = new Database('voice_list_contacts');

        $contactId = $db->insert([
            'voice_list_id' => $voiceListId,
            'name'          => $name,
            'phone'         => $phone,
            'status'        => $status,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s')
        ]);

        return (int)$contactId;
    }*/

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
        $r = (new Database('campaign_voice'))->select(
            'id = :id',
            [':id' => $id]
        )->fetch(\PDO::FETCH_ASSOC);

        return $r ?: null;
    }


    public static function updateStatusByJob(string $jobId, string $status): bool
    {
        return (new Database('campaign_voice'))->update(
            'job_id = "' . $jobId . '"',
            ['status' => $status]
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
