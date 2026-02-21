<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;

class Rates
{
    public int $id;

    /**
     * @param string $tenancyId
     * @param int|null $userId
     * @return array
     */

    public static function getRates(string $tenancyId, ?int $userId = null): array
    {
        $where = 'rr.tenancy_id = :tenancy_id';
        $params = [':tenancy_id' => $tenancyId];

        if (!is_null($userId)) {
            $where .= ' AND rr.user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        return (new Database('reseller_rates rr
            INNER JOIN users u ON u.id = rr.user_id'))
            ->select(
                $where,
                $params,
                null,
                null,
                'rr.id,
                 rr.user_id,
                 rr.type,
                 rr.service_event,
                 rr.service_scope,
                 rr.name,
                 rr.rate,
                 rr.status,
                 rr.created_at,
                 rr.updated_at,
                 u.name AS reseller_name,
                 u.email AS reseller_email'
            )
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @param string $tenancyId
     * @param int $userId
     * @param string $type
     * @return array|null
     */

    public static function getLatestActiveRate(string $tenancyId, int $userId, string $type = 'voice'): ?array
    {
        $where = 'rr.tenancy_id = :tenancy_id 
          AND rr.user_id = :user_id 
          AND rr.type = :type
          AND rr.status = "active"';

        $params = [
            ':tenancy_id' => $tenancyId,
            ':user_id'    => $userId,
            ':type'       => $type,
        ];

        $result = (new Database('reseller_rates rr
            INNER JOIN users u ON u.id = rr.user_id'))
            ->select(
                $where,
                $params,
                'rr.updated_at DESC',
                1,
                'rr.id,
             rr.user_id,
             rr.type,
             rr.name,
             rr.rate,
             rr.status,
             rr.created_at,
             rr.updated_at,
             rr.service_event,
             rr.service_scope,
             u.name AS reseller_name,
             u.email AS reseller_email'
            )
            ->fetch(\PDO::FETCH_ASSOC);

        return $result ?: null;
    }


    public static function getActiveRatesByUser(string $tenancyId, int $userId, ?string $type = null): array
    {
        if (!empty($type)) {
            $type = strtolower($type);

            // NORMAL → VOICE
            if ($type === 'normal') {
                $type = 'voice';
            }
        }

        $where = 'rr.tenancy_id = :tenancy_id 
          AND rr.user_id = :user_id 
          AND rr.status = "active"';

        $params = [
            ':tenancy_id' => $tenancyId,
            ':user_id'    => $userId
        ];

        // =============================================================
        // 1️⃣ TYPE INFORMADO → Buscar apenas o mais recente daquele type
        // =============================================================
        if (!empty($type)) {

            $params[':type'] = $type;
            $where .= ' AND rr.type = :type';

            $row = (new Database('reseller_rates rr'))
                ->select($where, $params, 'rr.updated_at DESC', 1)
                ->fetch(\PDO::FETCH_ASSOC);

            $filtered = $row ? (float)$row['rate'] : 0;

            // 🔥 SEMPRE retornar TODOS os tipos
            return [
                'voice'       => $type === 'voice'       ? $filtered : 0,
                'sms'         => $type === 'sms'         ? $filtered : 0,
                'torpedo'     => $type === 'torpedo'     ? $filtered : 0,
                'whatsapp'    => $type === 'whatsapp'    ? $filtered : 0,
                'service_fee' => $type === 'service_fee' ? $filtered : 0,
            ];

        }

        // =============================================================
        // 2️⃣ SEM TYPE → Buscar tudo e pegar o mais recente de cada type
        // =============================================================
        $results = (new Database('reseller_rates rr'))
            ->select($where, $params, 'rr.updated_at DESC')
            ->fetchAll(\PDO::FETCH_ASSOC);

        $rates = [
            'voice'       => 0,
            'sms'         => 0,
            'torpedo'     => 0,
            'whatsapp'    => 0,
            'service_fee' => 0
        ];



        foreach ($results as $row) {
            $t = strtolower($row['type']);

            // só pega o mais recente (primeira vez que aparece)
            if (array_key_exists($t, $rates) && $rates[$t] == 0) {
                $rates[$t] = (float)$row['rate'];
            }
        }

        return $rates;
    }

    public static function getRateById(int $rateId): ?object
    {
        $db = new Database('reseller_rates');

        $obj = $db->select(
            'id = :id',
            [':id' => $rateId],
            null,
            1,
            'id, user_id, tenancy_id, type, service_event, service_scope, name, rate, status, created_at, updated_at'
        )->fetchObject();

        return $obj ?: null;
    }


    public static function getLatestActiveServiceFee(string $tenancyId, int $userId): ?array
    {
        return self::getLatestActiveRate($tenancyId, $userId, 'service_fee');
    }



    /**
     * @param int $userId
     * @param string $rateType
     * @param string $name
     * @param float $rate
     * @param string $tenancyId
     * @param string|null $serviceEvent
     * @param string|null $serviceScope
     * @return int
     */

    public static function createRate(
        int $userId,
        string $rateType,
        string $name,
        float $rate,
        string $tenancyId,
        ?string $serviceEvent = null,
        ?string $serviceScope = null
    ): int
    {
        // 🔒 Segurança: se não for taxa de serviço, força NULL
        if ($rateType !== 'service_fee') {
            $serviceEvent = null;
            $serviceScope = null;
        }

        $db = new Database('reseller_rates');

        return $db->insert([
            'user_id'        => $userId,
            'type'           => $rateType,
            'service_event'  => $serviceEvent,   // NULL ou 'answered'
            'service_scope'  => $serviceScope,   // NULL | reseller_trunks | all_trunks
            'name'           => $name,
            'rate'           => $rate,
            'tenancy_id'     => $tenancyId,
            'status'         => 'active',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
    }


    /**
     * @param int $rateId
     * @param array $fields
     * @return bool
     */

    public static function updateRate(int $rateId, array $fields): bool
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        $db = new Database('reseller_rates');
        return $db->update("id = $rateId", $fields);
    }


    /**
     * Atualiza status de de uma tarifa
     * @param int $userId
     * @param string $tenancyId
     * @param string $status
     * @return bool
     */

    public static function updateStatusRate(int $userId, string $tenancyId, string $status): bool
    {
        return (new Database('reseller_rates'))->update(
                'id = :id AND tenancy_id = :tenancy_id',
                [
                    'status' => $status
                ],
                [
                    ':id' => $userId,
                    ':tenancy_id' => $tenancyId
                ]
            ) > 0;
    }

    /**
     * @param int $rateId
     * @param string $tenancyId
     * @return bool
     */

    public static function deleteRate(int $rateId, string $tenancyId): bool
    {
        return (new Database('reseller_rates'))->delete(
                'id = :id AND tenancy_id = :tenancy_id',
                [
                    ':id' => $rateId,
                    ':tenancy_id' => $tenancyId
                ]
            ) > 0;
    }


}