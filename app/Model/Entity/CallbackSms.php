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
    public int $batch_id;
    public string $phone_sms;
    public string $operator;
    public string $status_sms;
    public string $value_sms;
    public string $camp_name;
    public string $id_partner;
    public string $date_send;
    public string $update_date;


    /**
     * Associa um batch_id a todos os callbacks de uma campanha específica
     *
     * @param int|null $userId
     * @param string|null $tenancyId
     * @param int|null $batchId
     * @return object
     */


    public static function countSentSms(?int $userId, ?string $tenancyId, ?int $batchId = null): object
    {
        $where = '
        status_sms IN ("SENT", "DELIVERED", "UNDELIVERABLE", "EXPIRED")
        AND 1=1
    ';

        $params = [];

        // 🔹 Aplica filtro por tenancy apenas se for informado
        if (!empty($tenancyId)) {
            $where .= ' AND tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $tenancyId;
        }

        // 🔹 Aplica filtro por usuário, se houver
        if ($userId !== null) {
            $where .= ' AND user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        // 🔹 Filtro opcional de lote (batch)
        if (!is_null($batchId)) {
            $where .= ' AND batch_id = :batch_id';
            $params[':batch_id'] = $batchId;
        }

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
        status_sms IN ("ACCEPT")
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
            update_date = :update_date
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
            ':batch_id'    => $this->batch_id,
            ':phone_sms'   => $this->phone_sms,
            ':id_partner'  => $this->id_partner,
            ':tenancy_id'  => $this->tenancy_id,
        ];

        $stmt = (new Database())->execute($query, $params);

        return $stmt->rowCount();
    }

    public static function fetchStatusCountsWithDay(?string $tenancyId, ?int $userId = null, ?int $resellerId = null, ?int $campaignId = null): array
    {
        $db = new Database('callback');

        $params = [];
        $extraCondition = '';

        // 🔹 Filtro opcional de tenancy (ignorado para super_admin)
        if (!empty($tenancyId)) {
            $extraCondition .= " AND tenancy_id = :tenancy_id";
            $params[':tenancy_id'] = $tenancyId;
        }

        // 🔹 Filtro de usuário / revendedor
        if (!is_null($userId)) {
            $extraCondition .= " AND user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        if (!is_null($resellerId)) {
            $extraCondition .= " AND user_id = :reseller_id";
            $params[':reseller_id'] = $resellerId;
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

            return $result;
        };

        // 🔹 Consulta mensal, semanal e diária
        $statusMes = $getStatusData("MONTH(date_send) = MONTH(CURDATE()) AND YEAR(date_send) = YEAR(CURDATE())");
        $statusSemana = $getStatusData("YEARWEEK(date_send, 1) = YEARWEEK(CURDATE(), 1)");
        $statusDia = $getStatusData("DATE(date_send) = CURDATE()");

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

        return [
            'statusMes' => $statusMes,
            'statusSemana' => $statusSemana,
            'statusDia' => $statusDia,
            'totalMesAtual' => $totalMesAtual,
            'totalMesAnterior' => $totalMesAnterior,
            'totalSemanaAtual' => $totalSemanaAtual,
            'totalSemanaAnterior' => $totalSemanaAnterior,
            'totalDiaAtual' => $totalDiaAtual,
            'totalDiaAnterior' => $totalDiaAnterior,
        ];
    }


    public static function countGroupedByOperatorAllStatus(?string $tenancyId, ?int $userId = null, ?int $resellerId = null, ?string $period = null): array
    {
        $conditions = [];
        $params = [];

        // 🔹 Filtro de tenancy (ignorado para super admin)
        if (!empty($tenancyId)) {
            $conditions[] = 'tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $tenancyId;
        }

        // 🔹 Filtro de usuário
        if (!is_null($userId)) {
            $conditions[] = 'user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        // 🔹 Filtro de revendedor
        if (!is_null($resellerId)) {
            $conditions[] = 'reseller_id = :reseller_id';
            $params[':reseller_id'] = $resellerId;
        }

        $period = strtolower((string)$period);
        if ($period === 'day') {
            $conditions[] = 'DATE(date_send) = CURDATE()';
        } elseif ($period === 'week') {
            $conditions[] = 'YEARWEEK(date_send, 1) = YEARWEEK(CURDATE(), 1)';
        } elseif ($period === 'month') {
            $conditions[] = 'MONTH(date_send) = MONTH(CURDATE()) AND YEAR(date_send) = YEAR(CURDATE())';
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
        $where = "1=1";
        $params = [];

        // Filtros de hierarquia no banco novo.
        if (!empty($filters['tenancy_id'])) {
            $where .= " AND c.tenancy_id = :tenancy_id";
            $params[':tenancy_id'] = $filters['tenancy_id'];
        }

        if (!empty($filters['user_id'])) {
            $where .= " AND c.user_id = :user_id";
            $params[':user_id'] = (int)$filters['user_id'];
        }

        if (!empty($filters['reseller_id'])) {
            $where .= " AND (
                c.user_id = :reseller_id
                OR c.user_id IN (
                    SELECT u.id
                    FROM users u
                    WHERE u.user_id = :reseller_id
                )
            )";
            $params[':reseller_id'] = (int)$filters['reseller_id'];
        }

        if (!empty($filters['status_sms'])) {
            $where .= " AND c.status_sms = :status_sms";
            $params[':status_sms'] = strtoupper(trim((string)$filters['status_sms']));
        }

        $dateColumn = "COALESCE(c.update_date, c.date_send, c.received_at, c.webhook_created)";
        $period = strtolower((string)($filters['period'] ?? ''));

        if (!empty($filters['date_from'])) {
            $where .= " AND {$dateColumn} >= :date_from";
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where .= " AND {$dateColumn} <= :date_to";
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        if (empty($filters['date_from']) && empty($filters['date_to'])) {
            if ($period === 'day') {
                $where .= " AND DATE({$dateColumn}) = CURDATE()";
            } elseif ($period === 'week') {
                $where .= " AND {$dateColumn} >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
                            AND {$dateColumn} < DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)";
            } elseif ($period === 'month') {
                $where .= " AND YEAR({$dateColumn}) = YEAR(CURDATE())
                            AND MONTH({$dateColumn}) = MONTH(CURDATE())";
            }
        }

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
                c.camp_name
              FROM callback c
              WHERE {$where}
              ORDER BY {$order}
              LIMIT 5000";

        return (new Database())->execute($query, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }


}
