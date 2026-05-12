<?php

namespace App\Model\Entity;
use App\Service\PlanRuntimeService;
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
    public int $trunks = 0;
    public int $whatsapp_accounts = 0;

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
            p.value_whatsapp_authentication,
            p.trunks,
            p.whatsapp_accounts
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
            mp.value_whatsapp_authentication,
            mp.trunks,
            mp.whatsapp_accounts
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
        $plans = PlanCatalog::all([
            'status' => 'active',
            'type_plan' => $typePlan ?: null,
        ]);

        $plans = array_values(array_filter($plans, static function (array $planData): bool {
            $name = strtolower(trim((string)($planData['name_plan'] ?? '')));
            $slug = strtolower((string)($planData['slug'] ?? ''));
            return $name !== 'bootstrap' && $slug !== 'bootstrap-admin-bootstrap';
        }));

        if (empty($plans)) {
            return [];
        }

        $popularPlanId = self::getMostSoldPlanId($typePlan);

        $entities = [];
        foreach ($plans as $planData) {
            $plan = new self();
            foreach ($planData as $field => $value) {
                $plan->{$field} = $value;
            }
            $plan->is_popular = ($popularPlanId > 0 && (int)$plan->id === $popularPlanId) ? 1 : 0;
            $entities[] = $plan;
        }

        return $entities;
    }

    private static function getMostSoldPlanId(?string $typePlan = null): int
    {
        $where = 'p.status = "active" AND LOWER(TRIM(p.name_plan)) <> "bootstrap" AND p.slug <> "bootstrap-admin-bootstrap"';
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
        $planData = PlanCatalog::findById($id);
        if (!$planData) {
            return null;
        }

        $plan = new self();
        foreach ($planData as $field => $value) {
            $plan->{$field} = $value;
        }

        return $plan;
    }

    public static function getPlanServiceSummary(int $planId, int $userId, string $tenancyId): ?object
    {
        $summary = PlanRuntimeService::getDisplaySummary($tenancyId);
        if (!$summary) {
            return null;
        }

        return ((int)($summary->plan_id ?? 0) === $planId) ? $summary : null;
    }

    public static function getLatestBalancePlanServiceSummary(int $userId, string $tenancyId): ?object
    {
        return PlanRuntimeService::getDisplaySummary($tenancyId);
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

    public static function markRefunded(int $userPlanId): bool
    {
        return (new Database('mxx_user_plans'))->update(
                'id = ' . (int)$userPlanId,
                [
                    'status_payment' => 'refunded',
                    'status' => 'inactive',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]
            ) > 0;
    }

}
