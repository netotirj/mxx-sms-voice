<?php

namespace App\Model\Entity;

use Exception;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class CallbackSms
{

    public int $id;
    public string $tenancy_id;
    public int $user_id;
    public ?int $campaign_id = null;
    public ?int $batch_id = null;
    public string $phone_sms;
    public string $operator;
    public string $status_sms;
    public string $value_sms;
    public string $camp_name;
    public string $id_partner;
    public string $date_send;
    public string $update_date;
    public ?string $codigo_status = null;
    public ?string $codigo_detalhe = null;
    public ?string $descricao_detalhe = null;
    public ?string $webhook_action = null;
    public ?string $webhook_object = null;
    public ?string $webhook_created = null;
    public ?string $response_text = null;
    public ?string $origin_id = null;
    public ?string $received_at = null;
    public ?string $sms_reference_id = null;
    public ?string $sms_customer_id = null;
    public ?string $sms_account_id = null;
    public ?string $sms_user_id = null;

    private static function buildScopeConditions(
        ?string $tenancyId,
        ?int $userId = null,
        ?int $resellerId = null,
        string $alias = ''
    ): array {
        $column = static fn(string $name): string => $alias !== '' ? "{$alias}.{$name}" : $name;
        $conditions = [];
        $params = [];

        if (!empty($tenancyId)) {
            $conditions[] = $column('tenancy_id') . ' = :tenancy_id';
            $params[':tenancy_id'] = $tenancyId;
        }

        if (!is_null($userId)) {
            $conditions[] = $column('user_id') . ' = :user_id';
            $params[':user_id'] = $userId;
        }

        if (!is_null($resellerId)) {
            $resellerTenantSql = !empty($tenancyId) ? ' AND u.tenancy_id = :reseller_tenancy_id' : '';
            $conditions[] = '(' . $column('user_id') . ' = :reseller_id OR ' . $column('user_id') . ' IN (
                SELECT u.id
                FROM users u
                WHERE u.user_id = :reseller_id' . $resellerTenantSql . '
            ))';
            $params[':reseller_id'] = $resellerId;

            if (!empty($tenancyId)) {
                $params[':reseller_tenancy_id'] = $tenancyId;
            }
        }

        return [$conditions, $params];
    }

    private static function distinctInboundMoExpression(string $alias = ''): string
    {
        $prefix = $alias !== '' ? $alias . '.' : '';

        return "COALESCE(
            NULLIF({$prefix}sms_reference_id, ''),
            NULLIF({$prefix}origin_id, ''),
            CONCAT(
                COALESCE({$prefix}id_partner, ''),
                '|',
                COALESCE({$prefix}phone_sms, ''),
                '|',
                COALESCE({$prefix}response_text, ''),
                '|',
                DATE(COALESCE({$prefix}received_at, {$prefix}update_date, {$prefix}date_send))
            )
        )";
    }

    public static function normalizeOperatorForDashboard(?string $operator): ?string
    {
        $value = trim((string)$operator);
        if ($value === '') {
            return null;
        }

        $upper = strtoupper($value);
        if (in_array($upper, ['MO', 'UNKNOWN'], true)) {
            return null;
        }

        return $value;
    }

    public static function pickInboundMoOperator(?string $payloadOperator, ?string $fallbackOperator): string
    {
        return self::normalizeOperatorForDashboard($payloadOperator)
            ?? self::normalizeOperatorForDashboard($fallbackOperator)
            ?? 'UNKNOWN';
    }

    public static function findLatestOutboundContext(string $tenancyId, string $partnerId, string $phone): ?array
    {
        $tenancyId = trim($tenancyId);
        $partnerId = trim($partnerId);
        $phone = trim($phone);

        if ($tenancyId === '' || $partnerId === '' || $phone === '') {
            return null;
        }

        $row = (new Database())->execute(
            "SELECT
                id,
                user_id,
                campaign_id,
                batch_id,
                camp_name,
                operator,
                status_sms,
                date_send,
                update_date,
                received_at
             FROM callback
             WHERE tenancy_id = :tenancy_id
               AND id_partner = :id_partner
               AND phone_sms = :phone_sms
               AND UPPER(COALESCE(status_sms, '')) <> 'MO'
             ORDER BY COALESCE(update_date, date_send, received_at) DESC, id DESC
             LIMIT 1",
            [
                ':tenancy_id' => $tenancyId,
                ':id_partner' => $partnerId,
                ':phone_sms' => $phone,
            ]
        )->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }


    /**
     * Associa um batch_id a todos os callbacks de uma campanha específica
     *
     * @param int|string|null $userId
     * @param string|null $tenancyId
     * @param int|null $batchId
     * @return object
     */


    public static function countSentSms(int|string|null $userId, ?string $tenancyId, ?int $batchId = null, ?int $resellerId = null): object
    {
        [$conditions, $params] = self::buildScopeConditions(
            $tenancyId,
            ($userId !== null && $userId !== '') ? (int)$userId : null,
            $resellerId
        );
        $conditions[] = 'status_sms IN ("SENT", "DELIVERED", "UNDELIVERABLE", "EXPIRED")';

        // 🔹 Filtro opcional de lote (batch)
        if (!is_null($batchId)) {
            $conditions[] = 'batch_id = :batch_id';
            $params[':batch_id'] = $batchId;
        }

        $where = implode(' AND ', $conditions);

        $result = (new Database('callback'))->select(
            $where,
            $params,
            null,
            null,
            '
            COUNT(*) as qtd,
            SUM(value_sms) as value_total,
            SUM(CASE WHEN status_sms = "DELIVERED" THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN status_sms = "SENT" THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status_sms = "UNDELIVERABLE" THEN 1 ELSE 0 END) as undeliverable,
            SUM(CASE WHEN status_sms = "EXPIRED" THEN 1 ELSE 0 END) as expired
        '
        )->fetchObject();

        return (object)[
            'qtd'           => (int)($result->qtd ?? 0),
            'value_total'   => (float)($result->value_total ?? 0),
            'delivered'     => (int)($result->delivered ?? 0),
            'sent'          => (int)($result->sent ?? 0),
            'undeliverable' => (int)($result->undeliverable ?? 0),
            'expired'       => (int)($result->expired ?? 0),
        ];
    }




    public static function countSentSmsAccept(int $userId, string $tenancyId, ?int $batchId = null): object
    {
        $where = '
        status_sms IN ("ACCEPTED", "SENT", "DELIVERED")
        AND tenancy_id = :tenancy_id
        AND user_id = :user_id
    ';

        $params = [
            ':user_id' => $userId,
            ':tenancy_id' => $tenancyId
        ];

        if (!is_null($batchId)) {
            $where .= ' AND batch_id = :batch_id';
            $params[':batch_id'] = $batchId;
        }

        $result = (new Database('callback'))->select(
            $where,
            $params,
            null,
            null,
            'COUNT(*) as qtd, SUM(value_sms) as value_total'
        )->fetchObject();

        // Retorna sempre objeto seguro
        return (object)[
            'qtd'         => (int)($result->qtd ?? 0),
            'value_total' => (float)($result->value_total ?? 0)
        ];
    }


    public function insertStatus(): \PDOStatement
    {

        $query = "
            INSERT INTO callback (phone_sms, status_sms, value_sms, camp_name, id_partner, date_send, user_id, tenancy_id, campaign_id, batch_id, operator)
            VALUES (:phone_sms, :status_sms, :value_sms, :camp_name, :id_partner, :date_send, :user_id, :tenancy_id, :campaign_id, :batch_id, :operator)
        ";

        $params = [
            ':phone_sms' => $this->phone_sms,
            ':status_sms' => $this->status_sms,
            ':value_sms'  => $this->value_sms,
            ':camp_name' => $this->camp_name,
            ':id_partner' => $this->id_partner,
            ':date_send' => $this->date_send,
            ':user_id' => $this->user_id,
            ':tenancy_id' => $this->tenancy_id,
            ':campaign_id' => $this->campaign_id,
            ':batch_id' => $this->batch_id,
            ':operator' => $this->operator ?? 'UNKNOWN',
        ];

        return (new Database())->execute($query, $params);
    }

    /**
     * @throws Exception
     */


    public function updateStatus(): int
    {
        $query = "
        UPDATE callback
        SET
            status_sms = :status_sms,
            value_sms  = :value_sms,
            operator   = :operator,
            update_date = :update_date,
            codigo_status = :codigo_status,
            codigo_detalhe = :codigo_detalhe,
            descricao_detalhe = :descricao_detalhe,
            webhook_action = :webhook_action,
            webhook_object = :webhook_object,
            webhook_created = :webhook_created,
            response_text = COALESCE(:response_text, response_text),
            origin_id = :origin_id,
            received_at = :received_at,
            sms_reference_id = :sms_reference_id,
            sms_customer_id = :sms_customer_id,
            sms_account_id = :sms_account_id,
            sms_user_id = :sms_user_id
        WHERE
            batch_id   = :batch_id
            AND phone_sms = :phone_sms
            AND id_partner = :id_partner
            AND tenancy_id = :tenancy_id
    ";

        $params = [
            ':status_sms'  => strtoupper(trim($this->status_sms)),
            ':value_sms'   => $this->value_sms ?? 0.00,
            ':operator'    => $this->operator ?? '',
            ':update_date' => $this->update_date ?? date('Y-m-d H:i:s'),
            ':codigo_status' => $this->codigo_status,
            ':codigo_detalhe' => $this->codigo_detalhe,
            ':descricao_detalhe' => $this->descricao_detalhe,
            ':webhook_action' => $this->webhook_action,
            ':webhook_object' => $this->webhook_object,
            ':webhook_created' => $this->webhook_created,
            ':response_text' => $this->response_text,
            ':origin_id' => $this->origin_id,
            ':received_at' => $this->received_at ?? date('Y-m-d H:i:s'),
            ':sms_reference_id' => $this->sms_reference_id,
            ':sms_customer_id' => $this->sms_customer_id,
            ':sms_account_id' => $this->sms_account_id,
            ':sms_user_id' => $this->sms_user_id,
            ':batch_id'    => $this->batch_id,
            ':phone_sms'   => $this->phone_sms,
            ':id_partner'  => $this->id_partner,
            ':tenancy_id'  => $this->tenancy_id,
        ];

        $stmt = (new Database())->execute($query, $params);

        return $stmt->rowCount();
    }

    public function updateMoResponse(): int
    {
        $query = "
            UPDATE callback
            SET
                webhook_action = :webhook_action,
                webhook_object = :webhook_object,
                webhook_created = :webhook_created,
                response_text = :response_text,
                origin_id = :origin_id,
                received_at = :received_at,
                update_date = :update_date,
                sms_reference_id = :sms_reference_id,
                sms_customer_id = :sms_customer_id,
                sms_account_id = :sms_account_id,
                sms_user_id = :sms_user_id
            WHERE
                id_partner = :id_partner
                AND phone_sms = :phone_sms
                AND tenancy_id = :tenancy_id
        ";

        $stmt = (new Database())->execute($query, [
            ':webhook_action' => $this->webhook_action,
            ':webhook_object' => $this->webhook_object,
            ':webhook_created' => $this->webhook_created,
            ':response_text' => $this->response_text,
            ':origin_id' => $this->origin_id,
            ':received_at' => $this->received_at ?? date('Y-m-d H:i:s'),
            ':update_date' => $this->update_date ?? date('Y-m-d H:i:s'),
            ':sms_reference_id' => $this->sms_reference_id,
            ':sms_customer_id' => $this->sms_customer_id,
            ':sms_account_id' => $this->sms_account_id,
            ':sms_user_id' => $this->sms_user_id,
            ':id_partner' => $this->id_partner,
            ':phone_sms' => $this->phone_sms,
            ':tenancy_id' => $this->tenancy_id,
        ]);

        return $stmt->rowCount();
    }

    public function insertInboundMo(): \PDOStatement
    {
        $query = "
            INSERT INTO callback (
                phone_sms,
                status_sms,
                value_sms,
                camp_name,
                id_partner,
                date_send,
                update_date,
                user_id,
                tenancy_id,
                campaign_id,
                batch_id,
                operator,
                webhook_action,
                webhook_object,
                webhook_created,
                response_text,
                origin_id,
                received_at,
                sms_reference_id,
                sms_customer_id,
                sms_account_id,
                sms_user_id
            ) VALUES (
                :phone_sms,
                :status_sms,
                :value_sms,
                :camp_name,
                :id_partner,
                :date_send,
                :update_date,
                :user_id,
                :tenancy_id,
                :campaign_id,
                :batch_id,
                :operator,
                :webhook_action,
                :webhook_object,
                :webhook_created,
                :response_text,
                :origin_id,
                :received_at,
                :sms_reference_id,
                :sms_customer_id,
                :sms_account_id,
                :sms_user_id
            )
        ";

        return (new Database())->execute($query, [
            ':phone_sms' => $this->phone_sms,
            ':status_sms' => $this->status_sms ?: 'MO',
            ':value_sms' => $this->value_sms ?? 0.00,
            ':camp_name' => $this->camp_name ?? '',
            ':id_partner' => $this->id_partner,
            ':date_send' => $this->date_send ?? $this->received_at ?? date('Y-m-d H:i:s'),
            ':update_date' => $this->update_date ?? $this->received_at ?? date('Y-m-d H:i:s'),
            ':user_id' => $this->user_id,
            ':tenancy_id' => $this->tenancy_id,
            ':campaign_id' => $this->campaign_id,
            ':batch_id' => $this->batch_id,
            ':operator' => $this->operator ?? 'MO',
            ':webhook_action' => $this->webhook_action,
            ':webhook_object' => $this->webhook_object,
            ':webhook_created' => $this->webhook_created,
            ':response_text' => $this->response_text,
            ':origin_id' => $this->origin_id,
            ':received_at' => $this->received_at ?? date('Y-m-d H:i:s'),
            ':sms_reference_id' => $this->sms_reference_id,
            ':sms_customer_id' => $this->sms_customer_id,
            ':sms_account_id' => $this->sms_account_id,
            ':sms_user_id' => $this->sms_user_id,
        ]);
    }

    public static function inboundMoExists(
        string $tenancyId,
        int $userId,
        ?string $smsReferenceId = null,
        ?string $originId = null,
        ?string $partnerId = null,
        ?string $phone = null
    ): bool {
        $tenancyId = trim($tenancyId);
        $userId = (int)$userId;
        if ($tenancyId === '' || $userId <= 0) {
            return false;
        }

        $where = "tenancy_id = :tenancy_id AND user_id = :user_id AND status_sms = 'MO'";
        $params = [
            ':tenancy_id' => $tenancyId,
            ':user_id' => $userId,
        ];

        $smsReferenceId = trim((string)$smsReferenceId);
        $originId = trim((string)$originId);
        $partnerId = trim((string)$partnerId);
        $phone = trim((string)$phone);

        if ($smsReferenceId !== '') {
            $where .= ' AND sms_reference_id = :sms_reference_id';
            $params[':sms_reference_id'] = $smsReferenceId;
        } elseif ($originId !== '') {
            $where .= ' AND origin_id = :origin_id';
            $params[':origin_id'] = $originId;
        } elseif ($partnerId !== '' && $phone !== '') {
            $where .= ' AND id_partner = :id_partner AND phone_sms = :phone_sms';
            $params[':id_partner'] = $partnerId;
            $params[':phone_sms'] = $phone;
        } else {
            return false;
        }

        $row = (new Database('callback'))->select(
            $where,
            $params,
            'id DESC',
            1,
            'id'
        )->fetchColumn();

        return !empty($row);
    }

    public static function fetchStatusCountsWithDay(?string $tenancyId, ?int $userId = null, ?int $resellerId = null, ?int $campaignId = null): array
    {
        $db = new Database('callback');

        [$scopeConditions, $params] = self::buildScopeConditions($tenancyId, $userId, $resellerId);
        $extraCondition = '';
        foreach ($scopeConditions as $condition) {
            $extraCondition .= " AND {$condition}";
        }

        // 🔹 Filtro de campanha
        if (!is_null($campaignId)) {
            $extraCondition .= " AND campaign_id = :campaign_id";
            $params[':campaign_id'] = $campaignId;
        }

        // 🔹 Mapa de status → nomes legíveis
        $map = [
            'ACCEPTED'      => 'ACEITA',
            'SENT'          => 'ENVIADA',
            'UNKNOWN'       => 'FALHADA',
            'EXPIRED'       => 'EXPIRADA',
            'DELETED'       => 'DELETADA',
            'REJECTED'      => 'REJEITADA',
            'BLACKLIST'     => 'BLACKLIST',
            'DELIVERED'     => 'ENTREGUE',
            'UNDELIVERABLE' => 'NAO_ENTREGAVEL',
            'MO'            => 'RESPOSTA',
        ];

        $statusList = array_keys($map);

        // 🔹 Função auxiliar
        $getStatusData = function(string $dateCondition) use ($db, $params, $extraCondition, $statusList, $map) {
            $result = array_fill_keys(array_values($map), 0);

            $statusData = $db->select(
                "$dateCondition $extraCondition GROUP BY status_sms",
                $params,
                null,
                null,
                'status_sms, COUNT(*) as total'
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($statusData as $row) {
                $status = strtoupper($row['status_sms']);
                if (in_array($status, $statusList)) {
                    $translated = $map[$status];
                    $result[$translated] = (int)$row['total'];
                }
            }

            try {
                $responseRow = $db->execute(
                    "SELECT COUNT(DISTINCT " . self::distinctInboundMoExpression() . ") AS total
                     FROM callback
                     WHERE ({$dateCondition}) {$extraCondition}
                       AND (
                            UPPER(COALESCE(status_sms, '')) = 'MO'
                            OR LOWER(COALESCE(webhook_action, '')) = 'mo'
                       )",
                    $params
                )->fetchObject();

                $result['RESPOSTA'] = (int)($responseRow->total ?? 0);
            } catch (\Throwable) {
            }

            return $result;
        };

        // 🔹 Consulta mensal, semanal e diária
        $statusMes = $getStatusData("MONTH(date_send) = MONTH(CURDATE()) AND YEAR(date_send) = YEAR(CURDATE())");
        $statusMesAnterior = $getStatusData("MONTH(date_send) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(date_send) = YEAR(CURDATE() - INTERVAL 1 MONTH)");
        $statusSemana = $getStatusData("YEARWEEK(date_send, 1) = YEARWEEK(CURDATE(), 1)");
        $statusSemanaAnterior = $getStatusData("YEARWEEK(date_send, 1) = YEARWEEK(CURDATE() - INTERVAL 1 WEEK, 1)");
        $statusDia = $getStatusData("DATE(date_send) = CURDATE()");
        $statusDiaAnterior = $getStatusData("DATE(date_send) = CURDATE() - INTERVAL 1 DAY");

        // 🔹 Totais agregados
        $totalMesAtual = (int)$db->select(
            "MONTH(date_send) = MONTH(CURDATE()) AND YEAR(date_send) = YEAR(CURDATE()) $extraCondition",
            $params,
            null,
            null,
            'COUNT(*) as total'
        )->fetchColumn();

        $totalMesAnterior = (int)$db->select(
            "MONTH(date_send) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(date_send) = YEAR(CURDATE() - INTERVAL 1 MONTH) $extraCondition",
            $params,
            null,
            null,
            'COUNT(*) as total'
        )->fetchColumn();

        $totalSemanaAtual = (int)$db->select(
            "YEARWEEK(date_send, 1) = YEARWEEK(CURDATE(), 1) $extraCondition",
            $params,
            null,
            null,
            'COUNT(*) as total'
        )->fetchColumn();

        $totalSemanaAnterior = (int)$db->select(
            "YEARWEEK(date_send, 1) = YEARWEEK(CURDATE() - INTERVAL 1 WEEK, 1) $extraCondition",
            $params,
            null,
            null,
            'COUNT(*) as total'
        )->fetchColumn();

        $totalDiaAtual = (int)$db->select(
            "DATE(date_send) = CURDATE() $extraCondition",
            $params,
            null,
            null,
            'COUNT(*) as total'
        )->fetchColumn();

        $totalDiaAnterior = (int)$db->select(
            "DATE(date_send) = CURDATE() - INTERVAL 1 DAY $extraCondition",
            $params,
            null,
            null,
            'COUNT(*) as total'
        )->fetchColumn();

        $totalCustoMesAtual = (float)$db->select(
            "MONTH(date_send) = MONTH(CURDATE()) AND YEAR(date_send) = YEAR(CURDATE()) $extraCondition",
            $params,
            null,
            null,
            'COALESCE(SUM(value_sms), 0) as total'
        )->fetchColumn();

        $totalCustoMesAnterior = (float)$db->select(
            "MONTH(date_send) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(date_send) = YEAR(CURDATE() - INTERVAL 1 MONTH) $extraCondition",
            $params,
            null,
            null,
            'COALESCE(SUM(value_sms), 0) as total'
        )->fetchColumn();

        $totalCustoSemanaAtual = (float)$db->select(
            "YEARWEEK(date_send, 1) = YEARWEEK(CURDATE(), 1) $extraCondition",
            $params,
            null,
            null,
            'COALESCE(SUM(value_sms), 0) as total'
        )->fetchColumn();

        $totalCustoSemanaAnterior = (float)$db->select(
            "YEARWEEK(date_send, 1) = YEARWEEK(CURDATE() - INTERVAL 1 WEEK, 1) $extraCondition",
            $params,
            null,
            null,
            'COALESCE(SUM(value_sms), 0) as total'
        )->fetchColumn();

        $totalCustoDiaAtual = (float)$db->select(
            "DATE(date_send) = CURDATE() $extraCondition",
            $params,
            null,
            null,
            'COALESCE(SUM(value_sms), 0) as total'
        )->fetchColumn();

        $totalCustoDiaAnterior = (float)$db->select(
            "DATE(date_send) = CURDATE() - INTERVAL 1 DAY $extraCondition",
            $params,
            null,
            null,
            'COALESCE(SUM(value_sms), 0) as total'
        )->fetchColumn();

        return [
            'statusMes' => $statusMes,
            'statusMesAnterior' => $statusMesAnterior,
            'statusSemana' => $statusSemana,
            'statusSemanaAnterior' => $statusSemanaAnterior,
            'statusDia' => $statusDia,
            'statusDiaAnterior' => $statusDiaAnterior,
            'totalMesAtual' => $totalMesAtual,
            'totalMesAnterior' => $totalMesAnterior,
            'totalSemanaAtual' => $totalSemanaAtual,
            'totalSemanaAnterior' => $totalSemanaAnterior,
            'totalDiaAtual' => $totalDiaAtual,
            'totalDiaAnterior' => $totalDiaAnterior,
            'totalCustoMesAtual' => $totalCustoMesAtual,
            'totalCustoMesAnterior' => $totalCustoMesAnterior,
            'totalCustoSemanaAtual' => $totalCustoSemanaAtual,
            'totalCustoSemanaAnterior' => $totalCustoSemanaAnterior,
            'totalCustoDiaAtual' => $totalCustoDiaAtual,
            'totalCustoDiaAnterior' => $totalCustoDiaAnterior,
        ];
    }


    public static function countGroupedByOperatorAllStatus(?string $tenancyId, ?int $userId = null, ?int $resellerId = null, ?string $period = null): array
    {
        [$conditions, $params] = self::buildScopeConditions($tenancyId, $userId, $resellerId);

        $period = strtolower((string)$period);
        if ($period === 'day') {
            $conditions[] = 'DATE(date_send) = CURDATE()';
        } elseif ($period === 'day_previous') {
            $conditions[] = 'DATE(date_send) = CURDATE() - INTERVAL 1 DAY';
        } elseif ($period === 'week') {
            $conditions[] = 'YEARWEEK(date_send, 1) = YEARWEEK(CURDATE(), 1)';
        } elseif ($period === 'week_previous') {
            $conditions[] = 'YEARWEEK(date_send, 1) = YEARWEEK(CURDATE() - INTERVAL 1 WEEK, 1)';
        } elseif ($period === 'month') {
            $conditions[] = 'MONTH(date_send) = MONTH(CURDATE()) AND YEAR(date_send) = YEAR(CURDATE())';
        } elseif ($period === 'month_previous') {
            $conditions[] = 'MONTH(date_send) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(date_send) = YEAR(CURDATE() - INTERVAL 1 MONTH)';
        }

        // 🔹 Monta o WHERE dinamicamente
        $where = '';
        if (!empty($conditions)) {
            $where = implode(' AND ', $conditions);
        } else {
            $where = '1=1'; // caso super admin (sem filtros)
        }

        $where .= ' GROUP BY operator';

        // 🔹 Consulta
        $result = (new Database('callback'))->select(
            $where,
            $params,
            null,
            null,
            'operator, COUNT(*) as qtd'
        )->fetchAll(PDO::FETCH_ASSOC);

        // 🔹 Formata o retorno
        $data = [];
        foreach ($result as $row) {
            $operator = $row['operator'] ?? 'UNKNOWN';
            $data[$operator] = ['qtd' => (int)$row['qtd']];
        }

        return $data;
    }

    public static function countInboundMoDistinct(?string $tenancyId, ?int $userId = null, ?int $resellerId = null): int
    {
        [$scopeConditions, $params] = self::buildScopeConditions($tenancyId, $userId, $resellerId);
        $conditions = array_merge($scopeConditions, [
            "(UPPER(COALESCE(status_sms, '')) = 'MO' OR LOWER(COALESCE(webhook_action, '')) = 'mo')"
        ]);

        $where = implode(' AND ', $conditions);

        $row = (new Database('callback'))->execute(
            "SELECT COUNT(DISTINCT " . self::distinctInboundMoExpression() . ") AS total
             FROM callback
             WHERE {$where}",
            $params
        )->fetchObject();

        return (int)($row->total ?? 0);
    }

    public static function countGroupedByOperatorMoDistinct(?string $tenancyId, ?int $userId = null, ?int $resellerId = null, ?string $period = null): array
    {
        [$scopeConditions, $params] = self::buildScopeConditions($tenancyId, $userId, $resellerId);
        $conditions = array_merge($scopeConditions, [
            "(UPPER(COALESCE(status_sms, '')) = 'MO' OR LOWER(COALESCE(webhook_action, '')) = 'mo')"
        ]);

        $period = strtolower((string)$period);
        if ($period === 'day') {
            $conditions[] = 'DATE(date_send) = CURDATE()';
        } elseif ($period === 'day_previous') {
            $conditions[] = 'DATE(date_send) = CURDATE() - INTERVAL 1 DAY';
        } elseif ($period === 'week') {
            $conditions[] = 'YEARWEEK(date_send, 1) = YEARWEEK(CURDATE(), 1)';
        } elseif ($period === 'week_previous') {
            $conditions[] = 'YEARWEEK(date_send, 1) = YEARWEEK(CURDATE() - INTERVAL 1 WEEK, 1)';
        } elseif ($period === 'month') {
            $conditions[] = 'MONTH(date_send) = MONTH(CURDATE()) AND YEAR(date_send) = YEAR(CURDATE())';
        } elseif ($period === 'month_previous') {
            $conditions[] = 'MONTH(date_send) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(date_send) = YEAR(CURDATE() - INTERVAL 1 MONTH)';
        }

        $where = implode(' AND ', $conditions);
        $sql = "SELECT
                    CASE
                        WHEN TRIM(COALESCE(c.operator, '')) <> ''
                             AND UPPER(TRIM(COALESCE(c.operator, ''))) NOT IN ('MO', 'UNKNOWN')
                            THEN TRIM(c.operator)
                        ELSE COALESCE((
                            SELECT TRIM(cb_out.operator)
                            FROM callback cb_out
                            WHERE cb_out.tenancy_id = c.tenancy_id
                              AND cb_out.id_partner = c.id_partner
                              AND cb_out.phone_sms = c.phone_sms
                              AND UPPER(COALESCE(cb_out.status_sms, '')) <> 'MO'
                              AND UPPER(TRIM(COALESCE(cb_out.operator, ''))) NOT IN ('', 'MO', 'UNKNOWN')
                            ORDER BY COALESCE(cb_out.update_date, cb_out.date_send, cb_out.received_at) DESC, cb_out.id DESC
                            LIMIT 1
                        ), 'UNKNOWN')
                    END AS operator_label,
                    COUNT(DISTINCT " . self::distinctInboundMoExpression('c') . ") AS qtd
                FROM callback c
                WHERE {$where}
                GROUP BY operator_label";

        $result = (new Database('callback'))->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        foreach ($result as $row) {
            $operator = $row['operator_label'] ?? 'UNKNOWN';
            $data[$operator] = ['qtd' => (int)($row['qtd'] ?? 0)];
        }

        return $data;
    }



    public static function getCallbackSmsCount(?string $tenancyId = null, ?string $searchValue = null, ?int $userId = null): int
    {
        $query = "SELECT COUNT(*) as qtd FROM callback WHERE 1=1";
        $params = [];

        // 🔹 Filtro opcional por tenancy
        if (!empty($tenancyId)) {
            $query .= " AND tenancy_id = :tenancy_id";
            $params[':tenancy_id'] = $tenancyId;
        }

        // 🔹 Filtro opcional por user_id
        if ($userId !== null) {
            $query .= " AND user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        // 🔹 Filtro de busca
        if (!empty($searchValue)) {
            $query .= " AND (
            id LIKE :search OR 
            phone_sms LIKE :search OR
            value_sms LIKE :search OR
            camp_name LIKE :search OR 
            operator LIKE :search OR 
            status_sms LIKE :search OR 
            update_date LIKE :search
        )";
            $params[':search'] = '%' . $searchValue . '%';
        }

        $result = (new Database())->execute($query, $params)->fetchObject();
        return (int)($result->qtd ?? 0);
    }


    public static function getSmsForRealtime(array $filters = [], string $order = "id DESC"): array
    {
        [$scopeConditions, $params] = self::buildScopeConditions(
            $filters['tenancy_id'] ?? null,
            !empty($filters['user_id']) ? (int)$filters['user_id'] : null,
            !empty($filters['reseller_id']) ? (int)$filters['reseller_id'] : null,
            'c'
        );
        $conditions = $scopeConditions;

        if (!empty($filters['status_sms'])) {
            $conditions[] = "c.status_sms = :status_sms";
            $params[':status_sms'] = strtoupper(trim((string)$filters['status_sms']));
        }

        $dateColumn = "COALESCE(c.update_date, c.date_send, c.received_at, c.webhook_created)";
        $period = strtolower((string)($filters['period'] ?? ''));

        if (!empty($filters['date_from'])) {
            $conditions[] = "{$dateColumn} >= :date_from";
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = "{$dateColumn} <= :date_to";
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        if (empty($filters['date_from']) && empty($filters['date_to'])) {
            if ($period === 'day') {
                $conditions[] = "DATE({$dateColumn}) = CURDATE()";
            } elseif ($period === 'week') {
                $conditions[] = "{$dateColumn} >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)";
                $conditions[] = "{$dateColumn} < DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)";
            } elseif ($period === 'month') {
                $conditions[] = "YEAR({$dateColumn}) = YEAR(CURDATE())";
                $conditions[] = "MONTH({$dateColumn}) = MONTH(CURDATE())";
            }
        }

        $where = !empty($conditions) ? implode(' AND ', $conditions) : '1=1';

        $allowedOrder = [
            'event_date DESC',
            'event_date ASC',
            'id DESC',
            'id ASC',
            'update_date DESC',
            'date_send DESC',
        ];

        if (!in_array($order, $allowedOrder, true)) {
            $order = 'event_date DESC';
        }

        // Query direta, sem LIMIT de paginação (apenas um limit de segurança de carga)
        $query = "SELECT 
                c.id,
                c.status_sms,
                c.phone_sms,
                c.value_sms,
                c.operator,
                c.date_send,
                c.update_date,
                {$dateColumn} AS event_date,
                c.camp_name,
                c.webhook_action,
                c.response_text
              FROM callback c
              WHERE {$where}
              ORDER BY {$order}
              LIMIT 5000";

        return (new Database())->execute($query, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }


}
