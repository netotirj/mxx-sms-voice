<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;
use PDO;

class CdrVoice
{
    public $channel_id;
    public $job_id;
    public $call_id;
    public $campaign_id;
    public $campaign_type;
    public $tenancy_id;
    public $user_id;
    public $channel_number;
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
            channel_number,
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
            :channel_number,
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
            ':channel_number'=> $this->channel_number,
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

    /*public static function countCdrVoice(?int $userId, ?string $tenancyId): object
    {
        $where = "1=1";
        $params = [];

        // 🔹 Filtrar por tenancy (se houver)
        if (!empty($tenancyId)) {
            $where .= " AND tenancy_id = :tenancy_id";
            $params[':tenancy_id'] = $tenancyId;
        }

        // 🔹 Filtrar por usuário (se houver)
        if (!empty($userId)) {
            $where .= " AND user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        // 🔹 Consulta agregada igual ao SMS
        $result = (new Database('cdr'))->select(
            $where,
            $params,
            null,
            null,
            "
            COUNT(*) AS total,

            SUM(CASE WHEN dialstatus = 'ANSWER'   THEN 1 ELSE 0 END) AS answer,
            SUM(CASE WHEN dialstatus = 'NOANSWER' THEN 1 ELSE 0 END) AS noanswer,
            SUM(CASE WHEN dialstatus = 'FAILED'   THEN 1 ELSE 0 END) AS failed,
            SUM(CASE WHEN dialstatus = 'BUSY'     THEN 1 ELSE 0 END) AS busy,
            SUM(CASE WHEN dialstatus = 'CANCEL'   THEN 1 ELSE 0 END) AS cancel,

            SUM(value)    AS value_total,
            SUM(duration) AS duration_total
        "
        )->fetchObject();

        return (object)[
            'total'         => (int)   ($result->total         ?? 0),
            'answer'        => (int)   ($result->answer        ?? 0),
            'noanswer'      => (int)   ($result->noanswer      ?? 0),
            'failed'        => (int)   ($result->failed        ?? 0),
            'busy'          => (int)   ($result->busy          ?? 0),
            'cancel'        => (int)   ($result->cancel        ?? 0),

            'value_total'   => (float) ($result->value_total   ?? 0),
            'duration_total'=> (int)   ($result->duration_total?? 0),
        ];
    }*/

    /*public static function countCdrVoice(?int $userId, ?string $tenancyId): object
    {
        $where = "1=1";
        $params = [];

        // 🔹 Filtrar por tenancy (se houver)
        if (!empty($tenancyId)) {
            $where .= " AND tenancy_id = :tenancy_id";
            $params[':tenancy_id'] = $tenancyId;
        }

        // 🔹 Filtrar por usuário (se houver)
        if (!empty($userId)) {
            $where .= " AND user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        // ✅ NÃO CONTAR RAMAL (ramal sempre tem channel_number preenchido)
        $where .= " AND (channel_number IS NULL OR channel_number = '')";

        // 🔹 Consulta agregada
        $result = (new Database('cdr'))->select(
            $where,
            $params,
            null,
            null,
            "
            COUNT(*) AS total,

            SUM(CASE WHEN dialstatus = 'ANSWER'   THEN 1 ELSE 0 END) AS answer,
            SUM(CASE WHEN dialstatus = 'NOANSWER' THEN 1 ELSE 0 END) AS noanswer,
            SUM(CASE WHEN dialstatus = 'FAILED'   THEN 1 ELSE 0 END) AS failed,
            SUM(CASE WHEN dialstatus = 'BUSY'     THEN 1 ELSE 0 END) AS busy,
            SUM(CASE WHEN dialstatus = 'CANCEL'   THEN 1 ELSE 0 END) AS cancel,

            SUM(COALESCE(value, 0))           AS value_total,
            SUM(COALESCE(taxa_of_service, 0)) AS taxa_total,
            SUM(COALESCE(duration, 0))        AS duration_total
        "
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
    }*/

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
