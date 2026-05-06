<?php

namespace App\Model\Entity;
use WilliamCosta\DatabaseManager\Database;

#[\AllowDynamicProperties]
class UserPlans
{
    public int $id;
    public int $user_id;
    public string $tenancy_id;
    public string $plan_id;
    public string $start_date;
    public string $created_at;
    public string $updated_at;
    public string $description;
    public string $amount_plan;
    public string $value_sms;
    public int $is_popular = 0;
    public int $sales_count = 0;

    public static function getActivePlanByUser(int $planId, string $tenancyId, int $userId)
    {
        $query = "
        SELECT *
        FROM mxx_user_plans
        WHERE plan_id = :plan_id
          AND tenancy_id = :tenancy_id
          AND user_id = :user_id
          AND status_payment = 'confirmed'
        ORDER BY created_at DESC
        LIMIT 1
    ";

        $params = [
            ':plan_id'    => $planId,
            ':tenancy_id' => $tenancyId,
            ':user_id'    => $userId
        ];

        $result = (new Database('mxx_user_plans'))
            ->execute($query, $params)
            ->fetchObject(self::class);

        return $result ?: null;
    }

    public static function getActivePlanByTenancy(int $planId, string $tenancyId)
    {
        $query = "
        SELECT *
        FROM mxx_user_plans
        WHERE plan_id = :plan_id
          AND tenancy_id = :tenancy_id
          AND status_payment = 'confirmed'
        ORDER BY created_at DESC
        LIMIT 1
    ";

        $params = [
            ':plan_id'    => $planId,
            ':tenancy_id' => $tenancyId
        ];

        $result = (new Database('mxx_user_plans'))
            ->execute($query, $params)
            ->fetchObject(self::class);

        return $result ?: null;
    }



    public static function getAllActivePlansByUser(int $userId, string $tenancyId): array
    {
        $query = "
        SELECT up.*, p.name_plan
        FROM mxx_user_plans up
        JOIN mxx_plans p ON up.plan_id = p.id
        WHERE up.user_id = :user_id
          AND up.tenancy_id = :tenancy_id
          AND up.status_payment = 'confirmed'
          AND up.status = 'active'
        ORDER BY up.updated_at DESC
    ";

        $params = [
            ':user_id' => $userId,
            ':tenancy_id' => $tenancyId
        ];

        return (new Database('mxx_user_plans'))
            ->execute($query, $params)
            ->fetchAll(\PDO::FETCH_OBJ);
    }



    public static function getUserPlanInfoByPlanId(int $planId, string $tenancyId): ?object
    {
        $query = "
        SELECT
            up.*,
            p.name_plan,
            p.amount_plan,
            p.value_sms,
            p.value_voice,
            p.voice_open_rate,
            p.voice_smart_rate,
            p.value_torpedo,
            p.value_whatsapp,
            p.value_whatsapp_marketing,
            p.value_whatsapp_utility,
            p.value_whatsapp_authentication
        FROM mxx_user_plans up
        LEFT JOIN mxx_plans p ON up.plan_id = p.id
        WHERE up.plan_id = :plan_id
          AND up.tenancy_id = :tenancy_id
          AND up.status_payment = 'confirmed'
        ORDER BY up.updated_at DESC
        LIMIT 1
    ";

        $params = [
            ':plan_id'    => $planId,
            ':tenancy_id' => $tenancyId
        ];

        $result = (new Database())->execute($query, $params)->fetchObject();

        return $result ?: null;
    }

    public static function getUserPlanInfo(int $planId, int $userId, string $tenancyId)
    {
        $query = "
        SELECT 
            mup.id AS user_plan_id,
            mup.user_id,
            mup.tenancy_id,
            mup.plan_id,
            mup.status_payment,
            mup.created_at,
            mp.name_plan,
            mp.amount_plan,
            mp.value_sms,
            mp.value_voice,
            mp.voice_open_rate,
            mp.voice_smart_rate,
            mp.value_torpedo,
            mp.value_whatsapp,
            mp.value_whatsapp_marketing,
            mp.value_whatsapp_utility,
            mp.value_whatsapp_authentication
        FROM mxx_user_plans mup
        INNER JOIN mxx_plans mp ON mp.id = mup.plan_id
        WHERE 
            mup.plan_id = :plan_id
            AND mup.user_id = :user_id
            AND mup.tenancy_id = :tenancy_id
        ORDER BY mup.created_at DESC
        LIMIT 1
    ";

        $params = [
            ':plan_id' => $planId,
            ':user_id' => $userId,
            ':tenancy_id' => $tenancyId
        ];

        return (new Database())->execute($query, $params)->fetchObject();
    }

    public static function getAllActivePlans(?string $typePlan = null): array
    {
        $where = 'status = "active"';
        $params = [];

        // Se o type_plan foi enviado, filtra
        if (!empty($typePlan)) {
            $where .= ' AND type_plan = :type_plan';
            $params[':type_plan'] = $typePlan;
        }

        $plans = (new Database('mxx_plans'))
            ->select($where, $params, 'id ASC')
            ->fetchAll(\PDO::FETCH_CLASS, self::class);

        if (empty($plans)) {
            return [];
        }

        $popularPlanId = self::getMostSoldPlanId($typePlan);

        foreach ($plans as $plan) {
            $plan->is_popular = ($popularPlanId > 0 && (int)$plan->id === $popularPlanId) ? 1 : 0;
        }

        return $plans;
    }

    private static function getMostSoldPlanId(?string $typePlan = null): int
    {
        $where = 'p.status = "active"';
        $params = [];

        if (!empty($typePlan)) {
            $where .= ' AND p.type_plan = :type_plan';
            $params[':type_plan'] = $typePlan;
        }

        $query = "
            SELECT
                p.id,
                COUNT(w.webhook_id) AS sales_count
            FROM mxx_plans p
            LEFT JOIN mxx_user_plans up
                ON up.plan_id = p.id
            LEFT JOIN webhook_pix w
                ON w.user_plain_id = up.id
                AND w.payment_status = 'PAYMENT_RECEIVED'
                AND w.confirmed_date IS NOT NULL
            WHERE {$where}
            GROUP BY p.id, p.amount_plan
            ORDER BY sales_count DESC, CAST(p.amount_plan AS DECIMAL(10,2)) DESC, p.id ASC
            LIMIT 1
        ";

        $row = (new Database())->execute($query, $params)->fetchObject();

        if (!$row || (int)($row->sales_count ?? 0) <= 0) {
            return 0;
        }

        return (int)$row->id;
    }

    public static function getPlanById(int $id): ?self
    {
        return (new Database('mxx_plans'))
            ->select('id = :id', ['id' => $id])
            ->fetchObject(self::class) ?: null;
    }

    public static function getPlanServiceSummary(int $planId, int $userId, string $tenancyId): ?object
    {
        $query = "
            SELECT
                p.id AS plan_id,
                p.name_plan,
                p.description,
                p.amount_plan,
                COALESCE(b.value_sms, p.value_sms, 0) AS value_sms,
                COALESCE(b.value_voice, p.value_voice, 0) AS value_voice,
                COALESCE(b.voice_open_rate, p.voice_open_rate, 0) AS voice_open_rate,
                COALESCE(b.voice_smart_rate, p.voice_smart_rate, 0) AS voice_smart_rate,
                COALESCE(b.value_torpedo, p.value_torpedo, 0) AS value_torpedo,
                COALESCE(b.service_fee, 0) AS service_fee,
                COALESCE(
                    MAX(CASE WHEN w.category = 'marketing' THEN w.price_brl END),
                    p.value_whatsapp_marketing,
                    p.value_whatsapp,
                    0
                ) AS value_whatsapp_marketing,
                COALESCE(
                    MAX(CASE WHEN w.category = 'utility' THEN w.price_brl END),
                    p.value_whatsapp_utility,
                    p.value_whatsapp,
                    0
                ) AS value_whatsapp_utility,
                COALESCE(
                    MAX(CASE WHEN w.category = 'authentication' THEN w.price_brl END),
                    p.value_whatsapp_authentication,
                    p.value_whatsapp,
                    0
                ) AS value_whatsapp_authentication
            FROM mxx_plans p
            LEFT JOIN tenancy_balance b
                ON b.id = (
                    SELECT tb.id
                    FROM tenancy_balance tb
                    WHERE tb.plan_id = p.id
                      AND tb.user_id = :user_id
                      AND tb.tenancy_id = :tenancy_id
                    ORDER BY tb.updated_at DESC
                    LIMIT 1
                )
            LEFT JOIN plan_whatsapp_pricing w
                ON w.plan_id = p.id
            WHERE p.id = :plan_id
            GROUP BY
                p.id,
                p.name_plan,
                p.description,
                p.amount_plan,
                p.value_sms,
                p.value_voice,
                p.voice_open_rate,
                p.voice_smart_rate,
                p.value_torpedo,
                p.value_whatsapp,
                p.value_whatsapp_marketing,
                p.value_whatsapp_utility,
                p.value_whatsapp_authentication,
                b.value_sms,
                b.value_voice,
                b.voice_open_rate,
                b.voice_smart_rate,
                b.value_torpedo,
                b.service_fee
            LIMIT 1
        ";

        $result = (new Database())->execute($query, [
            ':plan_id' => $planId,
            ':user_id' => $userId,
            ':tenancy_id' => $tenancyId,
        ])->fetchObject();

        return $result ?: null;
    }

    public static function getLatestBalancePlanServiceSummary(int $userId, string $tenancyId): ?object
    {
        $query = "
            SELECT
                p.id AS plan_id,
                p.name_plan,
                p.description,
                p.amount_plan,
                COALESCE(b.value_sms, p.value_sms, 0) AS value_sms,
                COALESCE(b.value_voice, p.value_voice, 0) AS value_voice,
                COALESCE(b.voice_open_rate, p.voice_open_rate, 0) AS voice_open_rate,
                COALESCE(b.voice_smart_rate, p.voice_smart_rate, 0) AS voice_smart_rate,
                COALESCE(b.value_torpedo, p.value_torpedo, 0) AS value_torpedo,
                COALESCE(b.service_fee, 0) AS service_fee,
                COALESCE(
                    MAX(CASE WHEN w.category = 'marketing' THEN w.price_brl END),
                    p.value_whatsapp_marketing,
                    p.value_whatsapp,
                    0
                ) AS value_whatsapp_marketing,
                COALESCE(
                    MAX(CASE WHEN w.category = 'utility' THEN w.price_brl END),
                    p.value_whatsapp_utility,
                    p.value_whatsapp,
                    0
                ) AS value_whatsapp_utility,
                COALESCE(
                    MAX(CASE WHEN w.category = 'authentication' THEN w.price_brl END),
                    p.value_whatsapp_authentication,
                    p.value_whatsapp,
                    0
                ) AS value_whatsapp_authentication
            FROM tenancy_balance b
            INNER JOIN mxx_plans p
                ON p.id = b.plan_id
            LEFT JOIN plan_whatsapp_pricing w
                ON w.plan_id = p.id
            WHERE b.user_id = :user_id
              AND b.tenancy_id = :tenancy_id
            GROUP BY
                b.id,
                p.id,
                p.name_plan,
                p.description,
                p.amount_plan,
                p.value_sms,
                p.value_voice,
                p.voice_open_rate,
                p.voice_smart_rate,
                p.value_torpedo,
                p.value_whatsapp,
                p.value_whatsapp_marketing,
                p.value_whatsapp_utility,
                p.value_whatsapp_authentication,
                b.value_sms,
                b.value_voice,
                b.voice_open_rate,
                b.voice_smart_rate,
                b.value_torpedo,
                b.service_fee,
                b.updated_at
            ORDER BY b.updated_at DESC
            LIMIT 1
        ";

        $result = (new Database())->execute($query, [
            ':user_id' => $userId,
            ':tenancy_id' => $tenancyId,
        ])->fetchObject();

        return $result ?: null;
    }

    public static function createUserPlan(array $data): int
    {
        // Cria a instância de Database para a tabela 'maxx_user_plains'
        $db = new Database('mxx_user_plans');

        // Insere os dados e retorna o ID inserido
        return $db->insert([
            'user_id'             => $data['user_id'],
            'plan_id'             => $data['plan_id'],
            'tenancy_id'          => $data['tenancy_id'],
            'status_payment'      => $data['status'] ?? 'pending',
            'start_date'          => $data['start_date'] ?? date('Y-m-d H:i:s'),
            'end_date'            => $data['end_date'] ?? date('Y-m-d H:i:s'),
            'created_at'          => $data['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at'          => $data['updated_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public static function updateUserPlan(int $userPlanId, array $data): bool
    {
        $fields = [];

        if (isset($data['user_id'])) {
            $fields['user_id'] = $data['user_id'];
        }

        if (isset($data['plan_id'])) {
            $fields['plan_id'] = $data['plan_id'];
        }

        if (isset($data['tenancy_id'])) {
            $fields['tenancy_id'] = $data['tenancy_id'];
        }

        if (isset($data['status_payment'])) {
            $fields['status_payment'] = $data['status_payment'];
        }

        $fields['updated_at'] = date('Y-m-d H:i:s');

        if (empty($fields)) {
            return false; // nada a atualizar
        }

        return (new Database('mxx_user_plans'))
            //->update('id = ' . $userPlanId, $fields);
            ->update('id = ' . $userPlanId . ' AND status_payment != "confirmed"', $fields);
    }

    public static function deactivatePlan(int $planId, string $tenancyId, int $userId): bool
    {
        return (new Database('mxx_user_plans'))->update(
                'plan_id = ' . (int)$planId .
                ' AND tenancy_id = "' . addslashes($tenancyId) . '"' .
                ' AND user_id = ' . (int)$userId,
                [
                    'status'     => 'inactive',
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            ) > 0;
    }

}
