<?php

namespace App\Model\Entity;

use App\Controller\Pages\AsteriskExtensionsSip;
use WilliamCosta\DatabaseManager\Database;

class CallCenterQueues1404
{
    /**
     * Insere uma nova configuração de fila
     */
    public static function createQueue(
        int $userId,
        string $tenancyId,
        string $queueId,
        string $name,
        string $strategy,
        string $priority = 'Média',
        int $recordCalls = 0
    ): int {
        $db = new Database('queues_config');

        return $db->insert([
            'user_id'    => $userId,
            'tenancy_id' => $tenancyId,
            'queue_id'   => $queueId,
            'name'       => $name,
            'strategy'   => $strategy,
            'priority'   => $priority,
            'record_calls' => $recordCalls,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Vincula um agente a uma fila
     */
    public static function addQueueMember(
        string $tenancyId,
        string $queueId,
        string $agentRamal
    ): int {
        $db = new Database('queue_members');

        return $db->insert([
            'tenancy_id'  => $tenancyId,
            'queue_id'    => $queueId,
            'agent_ramal' => $agentRamal
        ]);
    }

    /**
     * Remove todos os membros de uma fila (útil antes de atualizar)
     */
    public static function clearQueueMembers(string $queueId, string $tenancyId): bool
    {
        $db = new Database('queue_members');
        // Supondo que sua classe Database tenha um método delete ou query
        return $db->delete("queue_id = '$queueId' AND tenancy_id = '$tenancyId'");
    }

    /**
     * Atualiza as configurações de uma fila com trava de Tenant e User
     */
    public static function updateQueue(string $tenancyId, string $queueId, array $data): bool
    {
        $db = new Database('queues_config');
        $data['updated_at'] = date('Y-m-d H:i:s');

        // O WHERE agora ignora o dono original e foca na empresa
        $where = "queue_id = '{$queueId}' AND tenancy_id = '{$tenancyId}'";

        return $db->update($where, $data);
    }

    public static function getQueues(string $tenancyId, ?int $userId = null): array
    {
        $table = 'queues_config q 
      INNER JOIN users u ON u.id = q.user_id
      LEFT JOIN (
          SELECT queue_id, tenancy_id, COUNT(*) as total 
          FROM queue_members 
          GROUP BY queue_id, tenancy_id
      ) m ON m.queue_id = q.queue_id AND m.tenancy_id = q.tenancy_id';

        $where = 'q.tenancy_id = :tenancy_id';
        $params = [':tenancy_id' => $tenancyId];

        $userFilter = "";
        if (!is_null($userId)) {
            $where .= ' AND q.user_id = :user_id';
            $params[':user_id'] = $userId;
            $userFilter = " AND c.user_id = :user_id";
        }

        $ramalFilter = " AND c.channel_number IS NOT NULL AND c.channel_number <> '' ";

        return (new Database($table))
            ->select(
                $where,
                $params,
                'q.id DESC',
                null,
                "q.id,
             q.queue_id,
             q.name,
             q.strategy,
             q.priority,
             q.record_calls, /* 🚀 ADICIONADO PARA O DASHBOARD */
             q.created_at,
             u.name AS creator_name,
             COALESCE(m.total, 0) AS total_agents,
             
             /* ATENDIDAS HOJE */
             (SELECT COUNT(*) FROM cdr c
              INNER JOIN campaign_voice cv ON cv.id = c.campaign_id
              WHERE cv.queue_id = q.queue_id 
              AND c.tenancy_id = q.tenancy_id 
              AND c.dialstatus = 'ANSWER' 
              AND DATE(c.created_at) = CURDATE()
              $ramalFilter
              $userFilter) AS atendidas_hoje,
            
             /* T.M.E FILA */
             (SELECT ROUND(AVG(TIMESTAMPDIFF(SECOND, c.started, c.answered))) 
              FROM cdr c
              INNER JOIN campaign_voice cv ON cv.id = c.campaign_id
              WHERE cv.queue_id = q.queue_id 
              AND c.tenancy_id = q.tenancy_id 
              AND c.answered IS NOT NULL
              AND DATE(c.created_at) = CURDATE()
              $ramalFilter
              $userFilter) AS tme_fila"
            )
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Busca a configuração de uma fila específica para o motor de voz
     */
    public static function getQueueConfig(string $queueId, string $tenancyId): array
    {
        $db = new Database('queues_config');
        $queue = $db->select("queue_id = '{$queueId}' AND tenancy_id = '{$tenancyId}'")
            ->fetch(\PDO::FETCH_ASSOC);

        if (!$queue) return [];

        $dbMembers = new Database('queue_members');
        $members = $dbMembers->select("queue_id = '{$queueId}' AND tenancy_id = '{$tenancyId}'")
            ->fetchAll(\PDO::FETCH_ASSOC);

        // 🔥 IMPORTANTE: Mudar aqui de 'extension' para 'agent_ramal'
        $queue['agents'] = array_column($members, 'agent_ramal');

        return $queue;
    }

    public static function toggleQueueMember(string $tenancyId, string $queueId, string $ramal, string $action, array $obUser): bool
    {
        $dbMembers = new Database('queue_members');
        $dbQueues  = new Database('queues_config');

        // --- 1. AÇÃO DE REMOVER: SEMPRE LIVRE ---
        $where = "queue_id = '{$queueId}' AND agent_ramal = '{$ramal}' AND tenancy_id = '{$tenancyId}'";
        if ($action === 'remove') {
            return $dbMembers->delete($where);
        }

        // --- 2. AÇÃO DE ADICIONAR: VALIDAÇÃO HIERÁRQUICA ---
        if ($action === 'add') {
            $role = strtolower($obUser['function'] ?? '');
            $loggedUserId = (int)$obUser['id'];

            // Busca dados da fila para saber quem é o DONO da fila
            $queueData = $dbQueues->select("queue_id = '{$queueId}' AND tenancy_id = '{$tenancyId}'")->fetch();
            $queueOwnerId = (int)($queueData['user_id'] ?? 0);

            /**
             * REGRA DE OURO:
             * Se a fila pertence ao Admin logado OU se for Super Admin, LIBERA TUDO.
             * Caso contrário (fila de outro user), precisamos validar se o ramal pertence ao dono da fila.
             */
            if ($loggedUserId !== $queueOwnerId && $role !== 'super_admin') {

                // 2.1 Consulta o Asterisk para saber quem é o dono do RAMAL
                $apiSip = new AsteriskExtensionsSip();
                $resSip = $apiSip->listExtensions(['function' => 'super_admin']); // Buscamos tudo para validar
                $extensions = $resSip['ok'] ? ($resSip['data']['data'] ?? []) : [];

                $ramalOwnerId = null;
                foreach ($extensions as $ext) {
                    if ((string)$ext['username'] === (string)$ramal) {
                        $ramalOwnerId = (int)($ext['user_id'] ?? 0);
                        break;
                    }
                }

                // 2.2 VALIDAÇÃO FINAL: O dono do ramal TEM QUE SER o dono da fila
                if ($ramalOwnerId !== $queueOwnerId) {
                    throw new \Exception("Ação Bloqueada: Este agente não pertence ao dono desta fila.");
                }
            }

            // --- 3. EXECUÇÃO DO VÍNCULO ---
            $exists = $dbMembers->select($where)->fetch();
            if ($exists) return true;

            return $dbMembers->insert([
                    'tenancy_id'  => $tenancyId,
                    'queue_id'    => $queueId,
                    'agent_ramal' => $ramal
                ]) > 0;
        }

        return false;
    }



    /**
     * Retorna apenas a lista de ramais (extensions) vinculados a uma fila
     * @param string $queueId
     * @param string $tenancyId
     * @return array
     */
    public static function getQueueMembersExtensions(string $queueId, string $tenancyId): array
    {
        $db = new Database('queue_members');
        $members = $db->select("queue_id = :qid AND tenancy_id = :tid", [
            ':qid' => $queueId,
            ':tid' => $tenancyId
        ])->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($members)) {
            return [];
        }

        // 🎯 Ajustado para usar 'agent_ramal' conforme a imagem do seu banco
        return array_values(array_filter(array_column($members, 'agent_ramal'), function($value) {
            return !empty(trim((string)$value));
        }));
    }

    public static function deleteQueue(string $tenancyId, string $queueId): bool
    {
        $dbQueues = new Database('queues_config');
        $dbMembers = new Database('queue_members');

        // 1. Remove os membros primeiro (limpeza de chaves/vínculos)
        $dbMembers->delete("queue_id = '{$queueId}' AND tenancy_id = '{$tenancyId}'");

        // 2. Remove a configuração da fila
        return $dbQueues->delete("queue_id = '{$queueId}' AND tenancy_id = '{$tenancyId}'");
    }


}