<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;
use PDO;

class CdrVoice1404
{
    public $channel_id;
    public $job_id;
    public $call_id;
    public $campaign_id;
    public $campaign_type;
    public $tenancy_id;
    public $user_id;
    public $user_name;
    public $user_account_code;
    public $channel_number;
    public $endpoints;
    public $number;
    public $destination;
    public $type;
    public $state;
    public $dialstatus;
    public $cause;
    public $cause_txt;
    public $duration;
    public $taxa_of_service;
    public $techprefix;
    public $value;
    public $role;
    public $started;
    public $answered;
    public $ended;

    /**
     * Insere um novo registro de CDR na tabela.
     */
    public function insertCdr(): \PDOStatement
    {
        $query = "
        INSERT INTO cdr (
            channel_id,
            job_id,
            call_id,             
            campaign_id,
            campaign_type,
            tenancy_id,
            user_id,
            user_name,
            user_account_code,
            channel_number,
            endpoints,
            `number`,
            destination,
            `type`,
            `state`,
            dialstatus,
            cause,
            cause_txt,
            duration,
            `taxa_of_service`,             
            `value`,
            started,
            answered,
            ended
        ) VALUES (
            :channel_id,
            :job_id,
            :call_id,      
            :campaign_id,
            :campaign_type,
            :tenancy_id,
            :user_id,
            :user_name,
            :user_account_code,
            :channel_number,
            :endpoints,   
            :number,
            :destination,
            :type,
            :state,
            :dialstatus,
            :cause,
            :cause_txt,
            :duration,
            :taxa_of_service,      
            :value,
            :started,
            :answered,
            :ended
        )
    ";

        $params = [
            ':channel_id'    => $this->channel_id,
            ':job_id'        => $this->job_id,
            ':call_id'       => $this->call_id,
            ':campaign_id'   => $this->campaign_id,
            ':campaign_type' => $this->campaign_type,
            ':tenancy_id'    => $this->tenancy_id,
            ':user_id'       => $this->user_id,
            ':user_name'      => $this->user_name,
            ':user_account_code' => $this->user_account_code,
            ':channel_number'=> $this->channel_number,
            ':endpoints'      => $this->endpoints,
            ':number'        => $this->number,
            ':destination'   => $this->destination,
            ':type'          => $this->type ?? 'normal',
            ':state'         => $this->state,
            ':dialstatus'    => $this->dialstatus,
            ':cause'         => $this->cause,
            ':cause_txt'     => $this->cause_txt,
            ':duration'      => $this->duration ?? 0,
            ':taxa_of_service' => $this->taxa_of_service ?? 0,
            ':value'         => $this->value ?? 0,
            ':started'       => $this->started,
            ':answered'      => $this->answered,
            ':ended'         => $this->ended,
        ];

        return (new Database())->execute($query, $params);
    }


    /**
     * Busca registros de CDR com base em filtros dinâmicos.
     *
     * @param array $filters Exemplo: ['tenancy_id' => 'uuid', 'user_id' => 5]
     * @param string|null $order Exemplo: 'created_at DESC'
     * @param string|null $limit Exemplo: '50'
     * @return array
     */
    public static function getCdrVoice(array $filters = [], ?string $order = null, ?string $limit = null): array
    {
        $where = [];
        $params = [];

        foreach ($filters as $key => $value) {
            $where[] = "$key = :$key";
            $params[":$key"] = $value;
        }

        $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $orderSQL = $order ? "ORDER BY $order" : 'ORDER BY created_at DESC';
        $limitSQL = $limit ? "LIMIT $limit" : '';

        $query = "
            SELECT *
            FROM cdr
            $whereSQL
            $orderSQL
            $limitSQL
        ";

        $stmt = (new Database())->execute($query, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function countCdrVoice(?int $userId, ?string $tenancyId): object
    {
        $where = "1=1";
        $params = [];

        if (!empty($tenancyId)) {
            $where .= " AND tenancy_id = :tenancy_id";
            $params[':tenancy_id'] = $tenancyId;
        }

        if (!empty($userId)) {
            $where .= " AND user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        // ✅ só voz (manual + discador)
        $where .= " AND type IN ('normal','outbound','inbound', 'service_fee')";

        $callKey = "COALESCE(NULLIF(call_id,''), channel_id)";

        $fields = "
        COUNT(*) AS total,

        SUM(CASE WHEN status_rank = 50 THEN 1 ELSE 0 END) AS answer,
        SUM(CASE WHEN status_rank = 40 THEN 1 ELSE 0 END) AS busy,
        SUM(CASE WHEN status_rank = 30 THEN 1 ELSE 0 END) AS noanswer,
        SUM(CASE WHEN status_rank = 20 THEN 1 ELSE 0 END) AS failed,
        SUM(CASE WHEN status_rank = 10 THEN 1 ELSE 0 END) AS cancel,

        SUM(value_one)    AS value_total,
        SUM(taxa_one)     AS taxa_total,
        SUM(duration_one) AS duration_total
    ";

        $table = "(
        SELECT
            {$callKey} AS call_key,

            MAX(
                CASE dialstatus
                    WHEN 'ANSWER'   THEN 50
                    WHEN 'BUSY'     THEN 40
                    WHEN 'NOANSWER' THEN 30
                    WHEN 'FAILED'   THEN 20
                    WHEN 'CANCEL'   THEN 10
                    ELSE 0
                END
            ) AS status_rank,

            MAX(COALESCE(value, 0))           AS value_one,
            MAX(COALESCE(taxa_of_service, 0)) AS taxa_one,
            MAX(COALESCE(duration, 0))        AS duration_one
        FROM cdr
        WHERE {$where}
        GROUP BY call_key
    ) t";

        $result = (new Database($table))->select(
            "1=1",
            $params,
            null,
            null,
            $fields
        )->fetchObject();

        return (object)[
            'total'          => (int)   ($result->total          ?? 0),
            'answer'         => (int)   ($result->answer         ?? 0),
            'noanswer'       => (int)   ($result->noanswer       ?? 0),
            'failed'         => (int)   ($result->failed         ?? 0),
            'busy'           => (int)   ($result->busy           ?? 0),
            'cancel'         => (int)   ($result->cancel         ?? 0),
            'value_total'    => (float) ($result->value_total    ?? 0),
            'taxa_total'     => (float) ($result->taxa_total     ?? 0),
            'duration_total' => (int)   ($result->duration_total ?? 0),
        ];
    }



    public static function fetchVoiceStatusCountsWithDay( ?string $tenancyId, ?int $userId = null, ?int $resellerId = null): array
    {
        $db = new Database('cdr');

        $params = [];
        $extraCondition = '';

        // 🔹 Filtrar tenancy
        if (!empty($tenancyId)) {
            $extraCondition .= " AND tenancy_id = :tenancy_id";
            $params[':tenancy_id'] = $tenancyId;
        }

        // 🔹 Filtrar user
        if (!is_null($userId)) {
            $extraCondition .= " AND user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        // 🔹 Filtrar revendedor
        if (!is_null($resellerId)) {
            $extraCondition .= " AND user_id = :reseller_id";
            $params[':reseller_id'] = $resellerId;
        }

        // 🔹 Mapeamento: dialstatus → status VOZ
        $map = [
            'ANSWER'    => 'ATENDIDA',
            'NOANSWER'  => 'NAO_ATENDIDA',
            'FAILED'    => 'FALHA',
            'BUSY'      => 'OCUPADO',
            'CANCEL'    => 'CANCELADO',
            'VOICEMAIL' => 'CAIXA_POSTAL',  // se existir em seu CDR
            'COMPLETED' => 'COMPLETADA',    // se existir esse status
        ];

        $statusList = array_keys($map);

        // Função auxiliar idêntica ao SMS
        $getStatusData = function(string $dateCond)
        use ($db, $extraCondition, $params, $statusList, $map)
        {
            $result = array_fill_keys(array_values($map), 0);

            $rows = $db->select(
                "$dateCond $extraCondition GROUP BY dialstatus",
                $params,
                null,
                null,
                "dialstatus, COUNT(*) AS total"
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $status = strtoupper($row['dialstatus'] ?? '');
                if (in_array($status, $statusList)) {
                    $translated = $map[$status];
                    $result[$translated] = (int)$row['total'];
                }
            }

            return $result;
        };

        // 🔹 Status mensal
        $statusMes = $getStatusData(
            "MONTH(started) = MONTH(CURDATE()) 
         AND YEAR(started) = YEAR(CURDATE())"
        );

        // 🔹 Status diário
        $statusDia = $getStatusData(
            "DATE(started) = CURDATE()"
        );

        // 🔹 Totais agregados
        $totalMesAtual = (int)$db->select(
            "MONTH(started) = MONTH(CURDATE()) 
         AND YEAR(started) = YEAR(CURDATE()) $extraCondition",
            $params,
            null,
            null,
            "COUNT(*) AS total"
        )->fetchColumn();

        $totalMesAnterior = (int)$db->select(
            "MONTH(started) = MONTH(CURDATE() - INTERVAL 1 MONTH) 
         AND YEAR(started) = YEAR(CURDATE() - INTERVAL 1 MONTH) $extraCondition",
            $params,
            null,
            null,
            "COUNT(*) AS total"
        )->fetchColumn();

        $totalDiaAtual = (int)$db->select(
            "DATE(started) = CURDATE() $extraCondition",
            $params,
            null,
            null,
            "COUNT(*) AS total"
        )->fetchColumn();

        $totalDiaAnterior = (int)$db->select(
            "DATE(started) = CURDATE() - INTERVAL 1 DAY $extraCondition",
            $params,
            null,
            null,
            "COUNT(*) AS total"
        )->fetchColumn();

        return [
            'statusMes'        => $statusMes,
            'statusDia'        => $statusDia,
            'totalMesAtual'    => $totalMesAtual,
            'totalMesAnterior' => $totalMesAnterior,
            'totalDiaAtual'    => $totalDiaAtual,
            'totalDiaAnterior' => $totalDiaAnterior,
        ];
    }


    /**
     * Calcula o SLA (Service Level Agreement) do dia atual.
     * Baseado em chamadas atendidas dentro do tempo limite (padrão 20s).
     * @param array $filters
     * @return array Porcentagem de 0 a 100
     */


    public static function getCdrReport(array $filters = []): array
    {
        $db = new Database('cdr');

        // 1. Filtros base (Sempre obrigatórios)
        $where = [
            "c.tenancy_id = :tenancy_id",
            "c.channel_number IS NOT NULL",
            "c.channel_number <> ''"
        ];

        $params = [':tenancy_id' => $filters['tenancy_id']];

        // 🛡️ BLINDAGEM: Verifica se existe filtro de user_id (Agente/Reseller)
        if (!empty($filters['user_id'])) {
            $where[] = "c.user_id = :user_id";
            $params[':user_id'] = $filters['user_id'];
        }

        // 2. Filtros Dinâmicos da UI
        if (!empty($filters['agent'])) {
            // Criamos um sub-grupo de OR para não interferir nos outros ANDs do WHERE
            // Isso permite buscar pelo número do ramal OU pelo nome do agente
            $where[] = "(c.channel_number = :agent_val OR c.user_name = :agent_name)";

            $params[':agent_val']  = $filters['agent'];
            $params[':agent_name'] = $filters['agent'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "c.started >= :date_from";
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = "c.started <= :date_to";
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['status'])) {
            $where[] = "c.dialstatus = :status";
            $params[':status'] = $filters['status'];
        }

        $whereSQL = implode(' AND ', $where);

        // 🚀 QUERY DE REGISTROS COM JOIN PARA PEGAR NOMES
        $queryRecords = "SELECT c.*, 
    cv.name as campanha_nome, 
    q.name as queue_name,
    (UNIX_TIMESTAMP(c.answered) - UNIX_TIMESTAMP(c.started)) as espera_seg,
    (UNIX_TIMESTAMP(c.ended) - UNIX_TIMESTAMP(c.answered)) as conversa_seg
    FROM cdr c
    LEFT JOIN campaign_voice cv ON cv.id = c.campaign_id
    LEFT JOIN queues_config q ON q.queue_id = cv.queue_id
    WHERE $whereSQL 
    ORDER BY c.started DESC LIMIT 100";

        // 📊 QUERY DE STATS (Respeita o mesmo WHERE blindado)
        $queryStats = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN dialstatus = 'ANSWER' THEN 1 ELSE 0 END) as atendidas,
    SUM(CASE WHEN dialstatus <> 'ANSWER' THEN 1 ELSE 0 END) as abandonadas,
    AVG(CASE WHEN dialstatus = 'ANSWER' AND answered IS NOT NULL AND ended IS NOT NULL THEN (UNIX_TIMESTAMP(ended) - UNIX_TIMESTAMP(answered)) ELSE NULL END) as tma_avg,
    AVG(CASE WHEN dialstatus = 'ANSWER' AND answered IS NOT NULL THEN (UNIX_TIMESTAMP(answered) - UNIX_TIMESTAMP(started)) ELSE NULL END) as tme_avg
    FROM cdr c
    WHERE $whereSQL";

        try {
            $records = $db->execute($queryRecords, $params)->fetchAll(\PDO::FETCH_ASSOC);
            $stats = $db->execute($queryStats, $params)->fetchObject();

            return [
                'records' => $records,
                'stats' => [
                    'total' => (int)($stats->total ?? 0),
                    'atendidas' => (int)($stats->atendidas ?? 0),
                    'abandonadas' => (int)($stats->abandonadas ?? 0),
                    'tma' => (int)($stats->tma_avg ?? 0),
                    'tme' => (int)($stats->tme_avg ?? 0)
                ]
            ];
        } catch (\Exception $e) {
            // Log de erro se necessário
            return ['records' => [], 'stats' => ['total'=>0, 'atendidas'=>0, 'abandonadas'=>0, 'tma'=>0, 'tme'=>0]];
        }
    }

    /**
     * Calcula o SLA do dia atual.
     * Adicionado parâmetro $ownerId para blindagem de perfil.
     */
    public static function getSlaToday(string $tenancyId, int $secondsThreshold = 20, $ownerId = null): float
    {
        $db = new Database('cdr');

        $where = "DATE(started) = CURDATE() 
          AND tenancy_id = :tenancy_id 
          AND channel_number IS NOT NULL 
          AND channel_number <> ''
          AND dialstatus = 'ANSWER'
          AND answered IS NOT NULL";

        $params = [
            ':tenancy_id' => $tenancyId,
            ':threshold'  => $secondsThreshold
        ];

        // 🛡️ BLINDAGEM: Se houver ownerId, filtra por ele
        if ($ownerId !== null) {
            $where .= " AND user_id = :owner_id";
            $params[':owner_id'] = $ownerId;
        }

        $query = "SELECT 
        COUNT(*) as total_atendidas,
        SUM(CASE WHEN (UNIX_TIMESTAMP(answered) - UNIX_TIMESTAMP(started)) <= :threshold THEN 1 ELSE 0 END) as dentro_meta
    FROM cdr WHERE $where";

        try {
            $stmt = $db->execute($query, $params);
            $result = $stmt->fetchObject();
            $total  = (int)($result->total_atendidas ?? 0);
            $dentro = (int)($result->dentro_meta ?? 0);
            if ($total === 0) return 100.0;
            return round(($dentro / $total) * 100, 1);
        } catch (\Exception $e) {
            return 100.0;
        }
    }

    /**
     * Tendência de SLA da última hora.
     */
    public static function getSlaTrendLastHour(string $tenancyId, $ownerId = null): array
    {
        $db = new Database('cdr');
        $dataPoints = [];
        $now = time();
        for ($i = 50; $i >= 0; $i -= 10) {
            $time = $now - ($i * 60);
            $key = floor($time / 600);
            $dataPoints[$key] = ['label' => date('H:i', floor($time / 600) * 600), 'sla' => 100];
        }

        $params = [':tenancy_id' => $tenancyId];
        $where = "started >= NOW() - INTERVAL 1 HOUR
              AND tenancy_id = :tenancy_id
              AND channel_number IS NOT NULL
              AND channel_number <> ''
              AND dialstatus = 'ANSWER'";

        // 🛡️ BLINDAGEM
        if ($ownerId !== null) {
            $where .= " AND user_id = :owner_id";
            $params[':owner_id'] = $ownerId;
        }

        $query = "SELECT FLOOR(UNIX_TIMESTAMP(started) / 600) AS time_key,
              COUNT(*) as total,
              SUM(CASE WHEN (UNIX_TIMESTAMP(answered) - UNIX_TIMESTAMP(started)) <= 20 THEN 1 ELSE 0 END) as dentro
              FROM cdr WHERE $where GROUP BY time_key";

        try {
            $stmt = $db->execute($query, $params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (isset($dataPoints[$row['time_key']])) {
                    $sla = ($row['total'] > 0) ? ($row['dentro'] / $row['total']) * 100 : 100;
                    $dataPoints[$row['time_key']]['sla'] = round($sla, 1);
                }
            }
            return ['values' => array_column($dataPoints, 'sla'), 'labels' => array_column($dataPoints, 'label')];
        } catch (\Exception $e) {
            return ['values' => [100, 100, 100, 100, 100, 100], 'labels' => array_column($dataPoints, 'label')];
        }
    }

    /**
     * Resumo dos Cards Superiores (Atendidas, TMA, TME).
     */
    public static function getDailyStatsSummary(string $tenantId, $ownerId = null): array
    {
        $db = new Database('cdr');

        $params = [':tid' => $tenantId];
        $where = "tenancy_id = :tid 
              AND DATE(started) = CURDATE()
              AND channel_number IS NOT NULL 
              AND channel_number <> ''";

        // 🛡️ BLINDAGEM
        if ($ownerId !== null) {
            $where .= " AND user_id = :owner_id";
            $params[':owner_id'] = $ownerId;
        }

        $query = "SELECT 
        SUM(CASE WHEN dialstatus = 'ANSWER' THEN 1 ELSE 0 END) as atendidas,
        SUM(CASE WHEN dialstatus <> 'ANSWER' THEN 1 ELSE 0 END) as abandonadas,
        AVG(CASE WHEN dialstatus = 'ANSWER' THEN (UNIX_TIMESTAMP(ended) - UNIX_TIMESTAMP(answered)) ELSE NULL END) as tma_seg,
        AVG(CASE WHEN dialstatus = 'ANSWER' THEN (UNIX_TIMESTAMP(answered) - UNIX_TIMESTAMP(started)) ELSE NULL END) as tme_seg
        FROM cdr WHERE $where";

        try {
            $stmt = $db->execute($query, $params);
            $res = $stmt->fetch(\PDO::FETCH_ASSOC);
            return [
                'atendidas'   => (int)($res['atendidas'] ?? 0),
                'abandonadas' => (int)($res['abandonadas'] ?? 0),
                'tma'         => (int)($res['tma_seg'] ?? 0),
                'tme'         => (int)($res['tme_seg'] ?? 0)
            ];
        } catch (\Exception $e) {
            return ['atendidas' => 0, 'abandonadas' => 0, 'tma' => 0, 'tme' => 0];
        }
    }



    /**
     * Busca um único registro de CDR pelo ID do canal.
     */
    public static function getByChannelId(string $channelId): ?array
    {
        $query = "SELECT * FROM cdr WHERE channel_id = :channel_id LIMIT 1";
        $stmt = (new Database())->execute($query, [':channel_id' => $channelId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Remove CDRs de um tenancy específico (usado em exclusões em cascata manuais).
     */
    public static function deleteByTenancy(string $tenancyId): bool
    {
        $query = "DELETE FROM cdr WHERE tenancy_id = :tenancy_id";
        $stmt = (new Database())->execute($query, [':tenancy_id' => $tenancyId]);
        return $stmt->rowCount() > 0;
    }
}
