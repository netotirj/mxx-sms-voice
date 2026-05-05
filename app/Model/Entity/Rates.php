<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;

class Rates
{
    public int $id;
    private const WHATSAPP_CATEGORIES = ['marketing', 'utility', 'authentication'];

    public static function ensureWhatsAppPricingTable(): void
    {
        (new Database())->execute(
            "CREATE TABLE IF NOT EXISTS reseller_whatsapp_pricing (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                reseller_id INT UNSIGNED NOT NULL,
                tenancy_id CHAR(36) NOT NULL,
                category ENUM('marketing','utility','authentication') NOT NULL,
                price_brl DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_reseller_whatsapp_category (reseller_id, tenancy_id, category),
                KEY idx_reseller_whatsapp_lookup (tenancy_id, reseller_id, status, category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function getWhatsAppCategoryRates(string $tenancyId, ?int $resellerId = null): array
    {
        self::ensureWhatsAppPricingTable();

        $where = 'rwp.tenancy_id = :tenancy_id';
        $params = [':tenancy_id' => $tenancyId];

        if ($resellerId !== null) {
            $where .= ' AND rwp.reseller_id = :reseller_id';
            $params[':reseller_id'] = $resellerId;
        }

        return (new Database('reseller_whatsapp_pricing rwp'))
            ->select(
                $where,
                $params,
                'rwp.reseller_id ASC, FIELD(rwp.category, "marketing", "utility", "authentication") ASC',
                null,
                'rwp.id, rwp.reseller_id, rwp.tenancy_id, rwp.category, rwp.price_brl, rwp.status, rwp.created_at, rwp.updated_at'
            )
            ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function getWhatsAppCategoryRatesMap(string $tenancyId, int $resellerId): array
    {
        $map = [
            'marketing' => null,
            'utility' => null,
            'authentication' => null,
        ];

        foreach (self::getWhatsAppCategoryRates($tenancyId, $resellerId) as $row) {
            $category = strtolower((string)$row['category']);
            if (array_key_exists($category, $map)) {
                $map[$category] = [
                    'price_brl' => round((float)$row['price_brl'], 4),
                    'status' => (string)$row['status'],
                    'updated_at' => $row['updated_at'] ?? null,
                ];
            }
        }

        return $map;
    }

    public static function getActiveWhatsAppCategoryPrice(string $tenancyId, int $resellerId, string $category): ?float
    {
        self::ensureWhatsAppPricingTable();

        $category = strtolower(trim($category));
        if (!in_array($category, self::WHATSAPP_CATEGORIES, true)) {
            return null;
        }

        $row = (new Database('reseller_whatsapp_pricing'))
            ->select(
                'tenancy_id = :tenancy_id AND reseller_id = :reseller_id AND category = :category AND status = "active"',
                [
                    ':tenancy_id' => $tenancyId,
                    ':reseller_id' => $resellerId,
                    ':category' => $category,
                ],
                'updated_at DESC',
                1,
                'price_brl'
            )
            ->fetch(\PDO::FETCH_ASSOC);

        return $row ? round((float)$row['price_brl'], 4) : null;
    }

    public static function upsertWhatsAppCategoryRates(string $tenancyId, int $resellerId, array $prices, string $status = 'active'): void
    {
        self::ensureWhatsAppPricingTable();

        $status = $status === 'inactive' ? 'inactive' : 'active';

        foreach (self::WHATSAPP_CATEGORIES as $category) {
            if (!array_key_exists($category, $prices)) {
                continue;
            }

            $price = round(max(0, (float)$prices[$category]), 4);

            (new Database())->execute(
                "INSERT INTO reseller_whatsapp_pricing
                    (reseller_id, tenancy_id, category, price_brl, status, created_at, updated_at)
                 VALUES
                    (:reseller_id, :tenancy_id, :category, :price_brl, :status, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    price_brl = VALUES(price_brl),
                    status = VALUES(status),
                    updated_at = NOW()",
                [
                    ':reseller_id' => $resellerId,
                    ':tenancy_id' => $tenancyId,
                    ':category' => $category,
                    ':price_brl' => $price,
                    ':status' => $status,
                ]
            );
        }
    }

    public static function updateWhatsAppCategoryRatesStatus(string $tenancyId, int $resellerId, string $status): bool
    {
        self::ensureWhatsAppPricingTable();

        $status = $status === 'inactive' ? 'inactive' : 'active';

        return (new Database('reseller_whatsapp_pricing'))->update(
                'tenancy_id = :tenancy_id AND reseller_id = :reseller_id',
                ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')],
                [
                    ':tenancy_id' => $tenancyId,
                    ':reseller_id' => $resellerId,
                ]
            ) > 0;
    }

    public static function deleteWhatsAppCategoryRates(string $tenancyId, int $resellerId): bool
    {
        self::ensureWhatsAppPricingTable();

        return (new Database('reseller_whatsapp_pricing'))->delete(
                'tenancy_id = :tenancy_id AND reseller_id = :reseller_id',
                [
                    ':tenancy_id' => $tenancyId,
                    ':reseller_id' => $resellerId,
                ]
            ) > 0;
    }

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
                'whatsapp'    => $type === 'whatsapp'    ? self::legacyWhatsAppRateValue($tenancyId, $userId, $filtered) : 0,
                'whatsapp_categories' => $type === 'whatsapp' ? self::flatWhatsAppCategoryRates($tenancyId, $userId) : [],
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
            'whatsapp_categories' => [],
            'service_fee' => 0
        ];



        foreach ($results as $row) {
            $t = strtolower($row['type']);

            // só pega o mais recente (primeira vez que aparece)
            if (array_key_exists($t, $rates) && $rates[$t] == 0) {
                $rates[$t] = (float)$row['rate'];
            }
        }

        $rates['whatsapp_categories'] = self::flatWhatsAppCategoryRates($tenancyId, $userId);
        if (!empty($rates['whatsapp_categories']['marketing'])) {
            $rates['whatsapp'] = (float)$rates['whatsapp_categories']['marketing'];
        }

        return $rates;
    }

    private static function flatWhatsAppCategoryRates(string $tenancyId, int $userId): array
    {
        $rates = [];
        foreach (self::getWhatsAppCategoryRatesMap($tenancyId, $userId) as $category => $row) {
            $rates[$category] = is_array($row) && ($row['status'] ?? '') === 'active'
                ? (float)$row['price_brl']
                : 0.0;
        }

        return $rates;
    }

    private static function legacyWhatsAppRateValue(string $tenancyId, int $userId, float $fallback): float
    {
        $categories = self::flatWhatsAppCategoryRates($tenancyId, $userId);
        return !empty($categories['marketing']) ? (float)$categories['marketing'] : $fallback;
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

    public static function updateRate(int $rateId, array $fields, ?string $tenancyId = null): bool
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        $db = new Database('reseller_rates');

        if ($tenancyId !== null && $tenancyId !== '') {
            return $db->update(
                    'id = :id AND tenancy_id = :tenancy_id',
                    $fields,
                    [
                        ':id' => $rateId,
                        ':tenancy_id' => $tenancyId,
                    ]
                ) > 0;
        }

        return $db->update('id = :id', $fields, [':id' => $rateId]) > 0;
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
