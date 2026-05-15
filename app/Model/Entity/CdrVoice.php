<?php

namespace App\Model\Entity;

use App\Utils\TenancyHelper;
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
    public $user_name;
    public $user_account_code;
    public $channel_number;
    public $endpoints;
    public $callerid_num;
    public $number;
    public $destination;
    public $direction;
    public $trunk;
    public $trunk_id;
    public $trunk_billing_type;
    public $plan_id;
    public $type;
    public $state;
    public $dialstatus;
    public $cause;
    public $cause_txt;
    public $sip_code;
    public $duration;
    public $duration_seconds;
    public $billsec;
    public $billed_seconds;
    public $taxa_of_service;
    public $techprefix;
    public $value;
    public $final_price;
    public $charged_at;
    public $agent_abandoned;
    public $agent_abandon_reason;
    public $call_minute_cost;
    public $tariff_used;
    public $hangup_by;
    public $sms_cost;
    public $torpedo_cost;
    public $application;
    public $cdr_timestamp;
    public $role;
    public $started;
    public $answered;
    public $ended;

    /**
     * Insere um novo registro de CDR na tabela.
     */
    public function insertCdr(): \PDOStatement
    {
        self::ensureCallerIdColumn();

        $hasCallerIdNum = self::hasColumn('callerid_num');
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
            " . ($hasCallerIdNum ? "callerid_num," : "") . "
            `number`,
            destination,
            direction,
            trunk,
            trunk_id,
            trunk_billing_type,
            plan_id,
            techprefix,
            `type`,
            `state`,
            dialstatus,
            cause,
            cause_txt,
            sip_code,
            duration,
            duration_seconds,
            billsec,
            billed_seconds,
            `taxa_of_service`,             
            `value`,
            final_price,
            charged_at,
            agent_abandoned,
            agent_abandon_reason,
            call_minute_cost,
            tariff_used,
            hangup_by,
            sms_cost,
            torpedo_cost,
            application,
            cdr_timestamp,
            role,
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
            " . ($hasCallerIdNum ? ":callerid_num," : "") . "
            :number,
            :destination,
            :direction,
            :trunk,
            :trunk_id,
            :trunk_billing_type,
            :plan_id,
            :techprefix,
            :type,
            :state,
            :dialstatus,
            :cause,
            :cause_txt,
            :sip_code,
            :duration,
            :duration_seconds,
            :billsec,
            :billed_seconds,
            :taxa_of_service,      
            :value,
            :final_price,
            :charged_at,
            :agent_abandoned,
            :agent_abandon_reason,
            :call_minute_cost,
            :tariff_used,
            :hangup_by,
            :sms_cost,
            :torpedo_cost,
            :application,
            :cdr_timestamp,
            :role,
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
            ':callerid_num'   => $this->callerid_num,
            ':number'        => $this->number,
            ':destination'   => $this->destination,
            ':direction'     => $this->direction ?? 'outbound',
            ':trunk'         => $this->trunk,
            ':trunk_id'      => $this->trunk_id,
            ':trunk_billing_type' => $this->trunk_billing_type,
            ':plan_id'       => $this->plan_id,
            ':techprefix'    => $this->techprefix,
            ':type'          => $this->type ?? 'normal',
            ':state'         => $this->state,
            ':dialstatus'    => $this->dialstatus,
            ':cause'         => $this->cause,
            ':cause_txt'     => $this->cause_txt,
            ':sip_code'      => $this->sip_code,
            ':duration'      => $this->duration ?? 0,
            ':duration_seconds' => $this->duration_seconds ?? $this->duration ?? 0,
            ':billsec'       => $this->billsec ?? $this->duration ?? 0,
            ':billed_seconds' => $this->billed_seconds ?? $this->billsec ?? $this->duration ?? 0,
            ':taxa_of_service' => $this->taxa_of_service ?? 0,
            ':value'         => $this->value ?? 0,
            ':final_price'   => $this->final_price ?? $this->value ?? 0,
            ':charged_at'    => $this->charged_at,
            ':agent_abandoned' => $this->agent_abandoned ?? 0,
            ':agent_abandon_reason' => $this->agent_abandon_reason,
            ':call_minute_cost' => $this->call_minute_cost ?? 0,
            ':tariff_used'   => $this->tariff_used ?? $this->call_minute_cost ?? 0,
            ':hangup_by'     => $this->hangup_by,
            ':sms_cost'      => $this->sms_cost ?? 0,
            ':torpedo_cost'  => $this->torpedo_cost ?? 0,
            ':application'   => $this->application,
            ':cdr_timestamp' => $this->cdr_timestamp,
            ':role'          => $this->role,
            ':started'       => $this->started,
            ':answered'      => $this->answered,
            ':ended'         => $this->ended,
        ];

        return (new Database())->execute($query, $params);
    }

    private static function ensureCallerIdColumn(): void
    {
        // Mantido como no-op: o fluxo de CDR nao deve alterar a estrutura do banco.
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
        // 1. Organizamos o contexto para a função que você criou
        $userContext = [
            'tenancy_id'    => $filters['tenancy_id'] ?? null,
            'id'            => $filters['user_id'] ?? null,
            'user_function' => $filters['user_function'] ?? ''
        ];

        // 2. Limpamos o array para não dar erro de coluna inexistente no loop abaixo
        unset($filters['tenancy_id'], $filters['user_id'], $filters['user_function']);

        // 3. Chamamos a sua função específica para CDR (Blindando o sistema)
        $whereSQL = TenancyHelper::applyCdrSecurityFilter($userContext, 'c');

        // 4. Parâmetros dinâmicos (Datas, status, etc)
        $params = [];
        foreach ($filters as $key => $value) {
            $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
            $whereSQL .= " AND c.{$safeKey} = :{$safeKey}";
            $params[":{$safeKey}"] = $value;
        }

        // 5. Query final
        $query = "SELECT c.* FROM cdr c WHERE $whereSQL " .
            ($order ? "ORDER BY $order" : "ORDER BY c.created_at DESC") .
            ($limit ? " LIMIT $limit" : "");

        return (new Database())->execute($query, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function countCdrVoice(array $filters): object
    {
        // 1. Extraímos os dados para o contexto de segurança
        $userContext = [
            'tenancy_id'    => $filters['tenancy_id'] ?? null,
            'id'            => $filters['user_id'] ?? null,
            'user_function' => $filters['user_function'] ?? ''
        ];

        // 2. Chamamos a nossa função "blindada" de segurança do CDR
        // Ela vai cuidar de separar Agente, Revendedor e Admin automaticamente
        $whereSecurity = TenancyHelper::applyCdrSecurityFilter($userContext, 'cdr');

        $params = [];
        // Se você tiver filtros de data (ex: created_at), eles entram aqui
        if (isset($filters['start_date']) && isset($filters['end_date'])) {
            $whereSecurity .= " AND cdr.created_at BETWEEN :start AND :end";
            $params[':start'] = $filters['start_date'];
            $params[':end']   = $filters['end_date'];
        }

        // ✅ Só voz (conforme seu original)
        $whereSecurity .= " AND cdr.type IN ('normal','outbound','inbound', 'service_fee')";

        $callKey = "COALESCE(NULLIF(cdr.call_id,''), cdr.channel_id)";

        $fields = "
        COUNT(*) AS total,
        SUM(CASE WHEN status_rank = 50 THEN 1 ELSE 0 END) AS answer,
        SUM(CASE WHEN status_rank = 40 THEN 1 ELSE 0 END) AS busy,
        SUM(CASE WHEN status_rank = 30 THEN 1 ELSE 0 END) AS noanswer,
        SUM(CASE WHEN status_rank = 20 THEN 1 ELSE 0 END) AS failed,
        SUM(CASE WHEN status_rank = 10 THEN 1 ELSE 0 END) AS cancel,
        SUM(value_one)    AS value_total,
        SUM(taxa_one)      AS taxa_total,
        SUM(duration_one) AS duration_total
    ";

        // Aplicamos o filtro de segurança DENTRO da subquery
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
        WHERE {$whereSecurity}
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



    public static function fetchVoiceStatusCountsWithDay(?string $tenancyId, ?int $userId = null, ?int $resellerId = null): array
    {
        $db = new Database('cdr');
        $params = [];

        // 1. Preparamos o contexto para o Helper de segurança
        // Precisamos descobrir o cargo de forma inteligente aqui
        $role = 'agent';
        if (!is_null($resellerId)) $role = 'reseller';
        if (is_null($userId) && is_null($resellerId)) $role = 'admin';
        if (empty($tenancyId) && is_null($userId) && is_null($resellerId)) $role = 'super_admin';

        $userContext = [
            'tenancy_id'    => $tenancyId,
            'id'            => $resellerId ?? $userId,
            'user_function' => $role
        ];

        // 2. Usamos o nosso Helper que já funciona nos Cards
        // O alias 'cdr' deve bater com o nome da tabela no seu Database
        $securityFilter = TenancyHelper::applyCdrSecurityFilter($userContext, 'cdr');

        // 3. Montamos a condição extra (A segurança já vem no $securityFilter)
        $extraCondition = " AND " . $securityFilter;

        // Mapeamento original
        $map = [
            'ANSWER'    => 'ATENDIDA',
            'NOANSWER'  => 'NAO_ATENDIDA',
            'FAILED'    => 'FALHA',
            'BUSY'      => 'OCUPADO',
            'CANCEL'    => 'CANCELADO',
            'VOICEMAIL' => 'CAIXA_POSTAL'
        ];
        $statusList = array_keys($map);
        $periodDate = self::periodDateExpression('cdr');

        // Função auxiliar ajustada
        $getStatusData = function(string $dateCond) use ($db, $extraCondition, $params, $statusList, $map) {
            $result = array_fill_keys(array_values($map), 0);

            // Importante: usamos 'cdr.' antes das datas se o seu helper usar alias
            $rows = $db->select(
                "{$dateCond} {$extraCondition} GROUP BY dialstatus",
                $params,
                null,
                null,
                "dialstatus, COUNT(*) AS total"
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $status = strtoupper($row['dialstatus'] ?? '');
                if (isset($map[$status])) {
                    $result[$map[$status]] = (int)$row['total'];
                }
            }
            return $result;
        };

        // Filtros de data (Garantindo que o campo started seja filtrado)
        $statusMes = $getStatusData("MONTH({$periodDate}) = MONTH(CURDATE()) AND YEAR({$periodDate}) = YEAR(CURDATE())");
        $statusMesAnterior = $getStatusData("MONTH({$periodDate}) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR({$periodDate}) = YEAR(CURDATE() - INTERVAL 1 MONTH)");
        $statusSemana = $getStatusData("YEARWEEK({$periodDate}, 1) = YEARWEEK(CURDATE(), 1)");
        $statusSemanaAnterior = $getStatusData("YEARWEEK({$periodDate}, 1) = YEARWEEK(CURDATE() - INTERVAL 1 WEEK, 1)");
        $statusDia = $getStatusData("DATE({$periodDate}) = CURDATE()");
        $statusDiaAnterior = $getStatusData("DATE({$periodDate}) = CURDATE() - INTERVAL 1 DAY");

        // Totais agregados com o filtro de segurança
        $totalMesAtual = (int)$db->select("MONTH({$periodDate}) = MONTH(CURDATE()) AND YEAR({$periodDate}) = YEAR(CURDATE()) $extraCondition", $params, null, null, "COUNT(*) AS total")->fetchColumn();
        $totalMesAnterior = (int)$db->select("MONTH({$periodDate}) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR({$periodDate}) = YEAR(CURDATE() - INTERVAL 1 MONTH) $extraCondition", $params, null, null, "COUNT(*) AS total")->fetchColumn();
        $totalSemanaAtual = (int)$db->select("YEARWEEK({$periodDate}, 1) = YEARWEEK(CURDATE(), 1) $extraCondition", $params, null, null, "COUNT(*) AS total")->fetchColumn();
        $totalSemanaAnterior = (int)$db->select("YEARWEEK({$periodDate}, 1) = YEARWEEK(CURDATE() - INTERVAL 1 WEEK, 1) $extraCondition", $params, null, null, "COUNT(*) AS total")->fetchColumn();
        $totalDiaAtual = (int)$db->select("DATE({$periodDate}) = CURDATE() $extraCondition", $params, null, null, "COUNT(*) AS total")->fetchColumn();
        $totalDiaAnterior = (int)$db->select("DATE({$periodDate}) = CURDATE() - INTERVAL 1 DAY $extraCondition", $params, null, null, "COUNT(*) AS total")->fetchColumn();
        $totalCustoMesAtual = (float)$db->select("MONTH({$periodDate}) = MONTH(CURDATE()) AND YEAR({$periodDate}) = YEAR(CURDATE()) $extraCondition", $params, null, null, "COALESCE(SUM(value), 0) AS total")->fetchColumn();
        $totalCustoMesAnterior = (float)$db->select("MONTH({$periodDate}) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR({$periodDate}) = YEAR(CURDATE() - INTERVAL 1 MONTH) $extraCondition", $params, null, null, "COALESCE(SUM(value), 0) AS total")->fetchColumn();
        $totalCustoSemanaAtual = (float)$db->select("YEARWEEK({$periodDate}, 1) = YEARWEEK(CURDATE(), 1) $extraCondition", $params, null, null, "COALESCE(SUM(value), 0) AS total")->fetchColumn();
        $totalCustoSemanaAnterior = (float)$db->select("YEARWEEK({$periodDate}, 1) = YEARWEEK(CURDATE() - INTERVAL 1 WEEK, 1) $extraCondition", $params, null, null, "COALESCE(SUM(value), 0) AS total")->fetchColumn();
        $totalCustoDiaAtual = (float)$db->select("DATE({$periodDate}) = CURDATE() $extraCondition", $params, null, null, "COALESCE(SUM(value), 0) AS total")->fetchColumn();
        $totalCustoDiaAnterior = (float)$db->select("DATE({$periodDate}) = CURDATE() - INTERVAL 1 DAY $extraCondition", $params, null, null, "COALESCE(SUM(value), 0) AS total")->fetchColumn();

        return [
            'statusMes'        => $statusMes,
            'statusMesAnterior' => $statusMesAnterior,
            'statusSemana'     => $statusSemana,
            'statusSemanaAnterior' => $statusSemanaAnterior,
            'statusDia'        => $statusDia,
            'statusDiaAnterior' => $statusDiaAnterior,
            'totalMesAtual'    => $totalMesAtual,
            'totalMesAnterior' => $totalMesAnterior,
            'totalSemanaAtual' => $totalSemanaAtual,
            'totalSemanaAnterior' => $totalSemanaAnterior,
            'totalDiaAtual'    => $totalDiaAtual,
            'totalDiaAnterior' => $totalDiaAnterior,
            'totalCustoMesAtual' => $totalCustoMesAtual,
            'totalCustoMesAnterior' => $totalCustoMesAnterior,
            'totalCustoSemanaAtual' => $totalCustoSemanaAtual,
            'totalCustoSemanaAnterior' => $totalCustoSemanaAnterior,
            'totalCustoDiaAtual' => $totalCustoDiaAtual,
            'totalCustoDiaAnterior' => $totalCustoDiaAnterior,
        ];
    }

    private static function periodDateExpression(string $table = 'cdr'): string
    {
        $parts = [];

        if (self::hasColumn('started')) {
            $parts[] = "NULLIF({$table}.started, '0000-00-00 00:00:00')";
        }

        if (self::hasColumn('cdr_timestamp')) {
            $parts[] = "NULLIF({$table}.cdr_timestamp, '0000-00-00 00:00:00')";
        }

        if (self::hasColumn('created_at')) {
            $parts[] = "{$table}.created_at";
        }

        if (!$parts) {
            return 'NOW()';
        }

        return count($parts) === 1 ? $parts[0] : 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private static function hasColumn(string $column): bool
    {
        static $columns = null;
        if (is_array($columns)) {
            return isset($columns[$column]);
        }

        try {
            $rows = (new Database())->execute('SHOW COLUMNS FROM cdr')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $columns = [];
            foreach ($rows as $row) {
                $field = trim((string)($row['Field'] ?? ''));
                if ($field !== '') {
                    $columns[$field] = true;
                }
            }
        } catch (\Throwable) {
            $columns = [];
        }

        return isset($columns[$column]);
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

        // 1. Contexto para o Helper (Pegamos o que vem da Controller)
        $userContext = [
            'tenancy_id'    => $filters['tenancy_id'] ?? null,
            'id'            => $filters['user_id'] ?? null,
            'user_function' => $filters['user_role'] ?? 'admin'
        ];

        // 🚀 AQUI ESTÁ O SEGREDO: Usamos o applyCdrSecurityFilter para o Revendedor ver a equipe
        $securityFilter = TenancyHelper::applyCdrSecurityFilter($userContext, 'c');

        // Filtros base técnicos
        $whereSQL = "c.channel_number IS NOT NULL AND c.channel_number <> '' AND $securityFilter";

        $params = [];

        // 2. Filtros Dinâmicos da UI
        if (!empty($filters['agent'])) {
            $whereSQL .= " AND (c.channel_number = :agent_val OR c.user_name = :agent_name)";
            $params[':agent_val']  = $filters['agent'];
            $params[':agent_name'] = $filters['agent'];
        }

        // Filtro de Data Início
        if (!empty($filters['date_from'])) {
            $whereSQL .= " AND c.started >= :date_from";
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        } else {
            // Se não mandar data, por padrão mostra o dia de hoje
            $whereSQL .= " AND c.started >= :date_today";
            $params[':date_today'] = date('Y-m-d 00:00:00');
        }

        // Filtro de Data Fim
        if (!empty($filters['date_to'])) {
            $whereSQL .= " AND c.started <= :date_to";
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['status'])) {
            $whereSQL .= " AND c.dialstatus = :status";
            $params[':status'] = $filters['status'];
        }

        // 🚀 QUERY DE REGISTROS (Ajustada com a segurança)
        $queryRecords = "SELECT c.*, 
                    cv.name as campanha_nome,
                    COALESCE(NULLIF(cv.queue_id, ''), NULLIF(qa.queue_id, '')) as queue_id,
                    COALESCE(NULLIF(q.name, ''), NULLIF(qa.queue_name, ''), NULLIF(cv.queue_id, ''), NULLIF(qa.queue_id, '')) as queue_name,
                    (UNIX_TIMESTAMP(c.answered) - UNIX_TIMESTAMP(c.started)) as espera_seg,
                    (UNIX_TIMESTAMP(c.ended) - UNIX_TIMESTAMP(c.answered)) as conversa_seg
                    FROM cdr c
                    LEFT JOIN campaign_voice cv ON cv.id = c.campaign_id AND cv.tenancy_id = c.tenancy_id
                    LEFT JOIN queues_config q ON q.queue_id = cv.queue_id AND q.tenancy_id = c.tenancy_id
                    LEFT JOIN (
                        SELECT
                            qm.tenancy_id,
                            qm.agent_ramal,
                            MIN(qm.queue_id) as queue_id,
                            MIN(qc.name) as queue_name
                        FROM queue_members qm
                        LEFT JOIN queues_config qc ON qc.queue_id = qm.queue_id AND qc.tenancy_id = qm.tenancy_id
                        GROUP BY qm.tenancy_id, qm.agent_ramal
                    ) qa ON qa.tenancy_id = c.tenancy_id AND qa.agent_ramal = c.channel_number
                    WHERE $whereSQL 
                    ORDER BY c.started DESC LIMIT 500";

        // 📊 QUERY DE STATS (Para os cards do relatório baterem com a lista)
        $queryStats = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN dialstatus = 'ANSWER' THEN 1 ELSE 0 END) as atendidas,
                    SUM(CASE WHEN dialstatus <> 'ANSWER' THEN 1 ELSE 0 END) as abandonadas,
                    AVG(CASE WHEN dialstatus = 'ANSWER' AND answered IS NOT NULL AND ended IS NOT NULL THEN (UNIX_TIMESTAMP(ended) - UNIX_TIMESTAMP(answered)) ELSE NULL END) as tma_avg
                    FROM cdr c
                    WHERE $whereSQL";

        try {
            $records = $db->execute($queryRecords, $params)->fetchAll(\PDO::FETCH_ASSOC);
            $stats = $db->execute($queryStats, $params)->fetchObject();

            return [
                'records' => $records ?: [],
                'stats' => [
                    'total'       => (int)($stats->total ?? 0),
                    'atendidas'   => (int)($stats->atendidas ?? 0),
                    'abandonadas' => (int)($stats->abandonadas ?? 0),
                    'tma'         => (int)($stats->tma_avg ?? 0)
                ]
            ];
        } catch (\Exception $e) {
            error_log("Erro CDR Report: " . $e->getMessage());
            return ['records' => [], 'stats' => ['total'=>0, 'atendidas'=>0, 'abandonadas'=>0, 'tma'=>0]];
        }
    }

    /**
     * Calcula o SLA do dia atual.
     * Adicionado parâmetro $ownerId para blindagem de perfil.
     */
    public static function getSlaToday(string $tenancyId, int $secondsThreshold = 20, $ownerId = null): float
    {
        $db = new Database('cdr');

        $userContext = [
            'tenancy_id'    => $tenancyId,
            'id'            => $ownerId,
            'user_function' => ($ownerId === null) ? 'admin' : ($_SESSION['user']['function'] ?? 'reseller')
        ];
        $securityFilter = TenancyHelper::applyCdrSecurityFilter($userContext, 'c');

        $where = "DATE(c.started) = CURDATE() 
              AND c.tenancy_id = :tenancy_id 
              AND c.channel_number IS NOT NULL 
              AND c.channel_number <> ''
              AND c.dialstatus = 'ANSWER'
              AND c.answered IS NOT NULL
              AND $securityFilter"; // 🚀 Blindagem

        $params = [
            ':tenancy_id' => $tenancyId,
            ':threshold'  => $secondsThreshold
        ];

                $query = "SELECT 
                COUNT(*) as total_atendidas,
                SUM(CASE WHEN (UNIX_TIMESTAMP(c.answered) - UNIX_TIMESTAMP(c.started)) <= :threshold THEN 1 ELSE 0 END) as dentro_meta
            FROM cdr c WHERE $where";

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

        // Preparamos o contexto para o Helper de segurança
        $userContext = [
            'tenancy_id'    => $tenantId,
            'id'            => $ownerId,
            'user_function' => ($ownerId === null) ? 'admin' : ($_SESSION['user']['function'] ?? 'reseller')
        ];

        // O alias 'c' deve bater com a sua query abaixo
        $securityFilter = TenancyHelper::applyCdrSecurityFilter($userContext, 'c');

        $params = [':tid' => $tenantId];
        $where = "c.tenancy_id = :tid 
              AND DATE(c.started) = CURDATE()
              AND c.channel_number IS NOT NULL 
              AND c.channel_number <> ''
              AND $securityFilter"; // 🚀 Segurança aplicada aqui

        $query = "SELECT 
        SUM(CASE WHEN c.dialstatus = 'ANSWER' THEN 1 ELSE 0 END) as atendidas,
        SUM(CASE WHEN c.dialstatus <> 'ANSWER' THEN 1 ELSE 0 END) as abandonadas,
        AVG(CASE WHEN c.dialstatus = 'ANSWER' THEN (UNIX_TIMESTAMP(c.ended) - UNIX_TIMESTAMP(c.answered)) ELSE NULL END) as tma_seg,
        AVG(CASE WHEN c.dialstatus = 'ANSWER' THEN (UNIX_TIMESTAMP(c.answered) - UNIX_TIMESTAMP(c.started)) ELSE NULL END) as tme_seg
        FROM cdr c WHERE $where";

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


}
