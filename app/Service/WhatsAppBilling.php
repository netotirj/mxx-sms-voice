<?php

namespace App\Service;

use App\Config\TelephonyConfig;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\Rates;
use App\Model\Entity\RegisterTenancies;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppBilling
{
    public const ERROR_INSUFFICIENT_BALANCE = 'Saldo insuficiente';
    public const ERROR_MISSING_CATEGORY = 'Categoria da mensagem WhatsApp não informada.';

    public static function resolveCategory(string $messageType, ?string $category, bool $serviceWindowOpen): string
    {
        if (trim((string)$category) === '' && $messageType === 'template') {
            throw new \RuntimeException(self::ERROR_MISSING_CATEGORY);
        }

        return WhatsAppCostPolicy::billingCategory($messageType, $category, $serviceWindowOpen);
    }

    public static function priceForUser(int $userId, string $tenancyId, string $category, ?string $countryCode = null): float
    {
        $category = WhatsAppCostPolicy::normalizeCategory($category);
        if ($category === WhatsAppCostPolicy::CATEGORY_SERVICE) {
            return 0.0;
        }

        $resellerPrice = self::priceFromResellerCategoryRate($userId, $tenancyId, $category);
        if ($resellerPrice !== null) {
            return $resellerPrice;
        }

        $dynamicPrice = null;
        try {
            WhatsAppDynamicPricing::refreshUsdRateIfStale();
            $dynamicPrice = WhatsAppDynamicPricing::salePriceBrl(
                strtolower($category),
                $countryCode ?: (string)TelephonyConfig::env('WHATSAPP_DEFAULT_COUNTRY_CODE', 'BR'),
                $userId
            );
        } catch (\Throwable $e) {
            error_log('[whatsapp_dynamic_pricing_fallback] ' . $e->getMessage());
        }

        if ($dynamicPrice !== null) {
            return round((float)$dynamicPrice, 4);
        }

        $planId = self::resolvePlanId($userId, $tenancyId);
        if ($planId) {
            self::syncPlanWhatsappPricingFromMxxPlan($planId);
        }

        if (!$planId) {
            return WhatsAppCostPolicy::defaultPriceBrl($category);
        }

        return self::priceFromPlan($planId, $category) ?? WhatsAppCostPolicy::defaultPriceBrl($category);
    }

    public static function pricingSnapshot(int $userId, string $tenancyId, string $category, string $phone, ?float $chargedPriceBrl = null): array
    {
        $category = WhatsAppCostPolicy::normalizeCategory($category);
        if ($category === WhatsAppCostPolicy::CATEGORY_SERVICE) {
            return [
                'message_category' => strtolower($category),
                'final_price_brl' => 0.0,
                'pricing_source' => 'service_window',
                'quoted_at' => date('Y-m-d H:i:s'),
            ];
        }

        $countryCode = WhatsAppDynamicPricing::countryCodeFromPhone($phone);
        $refresh = WhatsAppDynamicPricing::refreshUsdRateIfStale();
        $pricing = WhatsAppDynamicPricing::calculatePrice(strtolower($category), $countryCode, $userId);
        $planId = self::resolvePlanId($userId, $tenancyId);
        $planPrice = $planId ? self::priceFromPlan($planId, $category) : null;
        $finalPrice = $chargedPriceBrl !== null
            ? round(max(0, $chargedPriceBrl), 4)
            : round((float)($pricing['final_price_brl'] ?? 0), 4);
        $pricing['final_price_brl'] = $finalPrice;
        $pricing['charged_price_brl'] = $finalPrice;
        $pricing['profit_brl'] = round($finalPrice - (float)($pricing['cost_brl'] ?? 0), 6);

        return array_merge($pricing, [
            'plan_id' => $planId,
            'plan_price_brl' => $planPrice,
            'charged_price_brl' => $finalPrice,
            'final_price_brl' => $finalPrice,
            'exchange_refresh' => $refresh,
            'quoted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function publicPlanWhatsappPrices(?int $customerId = null, string $countryCode = 'BR'): array
    {
        $prices = [];
        foreach ([
            'marketing' => WhatsAppCostPolicy::CATEGORY_MARKETING,
            'utility' => WhatsAppCostPolicy::CATEGORY_UTILITY,
            'authentication' => WhatsAppCostPolicy::CATEGORY_AUTHENTICATION,
        ] as $key => $category) {
            try {
                $prices[$key] = WhatsAppDynamicPricing::calculatePrice($key, $countryCode, $customerId);
            } catch (\Throwable $e) {
                $fallback = WhatsAppCostPolicy::defaultPriceBrl($category);
                $prices[$key] = [
                    'message_type' => $key,
                    'country_code' => $countryCode,
                    'final_price_brl' => $fallback,
                    'pricing_source' => 'fallback',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $prices;
    }

    public static function categoryPricesForUser(int $userId, string $tenancyId, ?string $countryCode = null): array
    {
        $prices = [];
        foreach ([
            'marketing' => WhatsAppCostPolicy::CATEGORY_MARKETING,
            'utility' => WhatsAppCostPolicy::CATEGORY_UTILITY,
            'authentication' => WhatsAppCostPolicy::CATEGORY_AUTHENTICATION,
            'service' => WhatsAppCostPolicy::CATEGORY_SERVICE,
        ] as $key => $category) {
            $prices[$key] = self::priceForUser($userId, $tenancyId, $category, $countryCode);
        }

        return $prices;
    }

    public static function assertCanSend(int $userId, string $tenancyId, string $category, float $priceBrl): void
    {
        $category = WhatsAppCostPolicy::normalizeCategory($category);
        $priceBrl = round(max(0, $priceBrl), 4);

        if ($category === WhatsAppCostPolicy::CATEGORY_SERVICE || $priceBrl <= 0) {
            return;
        }

        $balance = BalanceSms::getBalanceSms($userId, $tenancyId, self::resolvePlanId($userId, $tenancyId));
        if (!$balance || (float)$balance->balance < $priceBrl) {
            throw new \RuntimeException(self::ERROR_INSUFFICIENT_BALANCE);
        }
    }

    public static function assertCanSendBatch(int $userId, string $tenancyId, array $messages): void
    {
        $total = 0.0;
        foreach ($messages as $message) {
            $category = WhatsAppCostPolicy::normalizeCategory((string)($message['message_category'] ?? ''));
            $priceBrl = round((float)($message['price_brl'] ?? 0), 4);
            if ($category !== WhatsAppCostPolicy::CATEGORY_SERVICE) {
                $total += $priceBrl;
            }
        }

        if ($total <= 0) {
            return;
        }

        $balance = BalanceSms::getBalanceSms($userId, $tenancyId, self::resolvePlanId($userId, $tenancyId));
        if (!$balance || (float)$balance->balance < round($total, 4)) {
            throw new \RuntimeException(self::ERROR_INSUFFICIENT_BALANCE);
        }
    }

    public static function authorizeOutbox(array $row): array
    {
        $category = (string)($row['message_category'] ?? '');
        if ($category === '') {
            $category = self::resolveCategory(
                (string)$row['message_type'],
                $row['template_category'] ?? null,
                (bool)$row['service_window_open']
            );
        }

        $freeByMetaPolicy = WhatsAppCostPolicy::isFreeByMetaPolicy(
            (string)($row['message_type'] ?? ''),
            $row['template_category'] ?? $category,
            (bool)($row['service_window_open'] ?? false)
        );
        $priceBrl = $freeByMetaPolicy
            ? 0.0
            : (isset($row['price_brl'])
            ? round((float)$row['price_brl'], 4)
            : self::priceForUser((int)$row['user_id'], (string)$row['tenancy_id'], $category));
        $pricingSnapshot = $freeByMetaPolicy
            ? self::freeMetaPolicySnapshot($category, (string)($row['contact_phone'] ?? ''), 'utility_template_customer_service_window')
            : self::pricingSnapshot(
                (int)$row['user_id'],
                (string)$row['tenancy_id'],
                $category,
                (string)($row['contact_phone'] ?? ''),
                $priceBrl
            );

        self::assertCanSend((int)$row['user_id'], (string)$row['tenancy_id'], $category, $priceBrl);

        return [
            'message_category' => $category,
            'price_brl' => $priceBrl,
            'pricing_snapshot' => $pricingSnapshot,
            'billed' => false,
        ];
    }

    public static function billSent(array $row, ?int $messageId, ?string $wamid, array $billing): bool
    {
        $category = WhatsAppCostPolicy::normalizeCategory((string)$billing['message_category']);
        $priceBrl = round((float)$billing['price_brl'], 4);
        $row['pricing_snapshot'] = $billing['pricing_snapshot'] ?? null;
        self::generateCdr($row, 'sent', $messageId, $wamid, $category, $priceBrl, false);
        return false;
    }

    public static function recordBlocked(array $row, string $error): void
    {
        try {
            $category = self::resolveCategory(
                (string)$row['message_type'],
                $row['template_category'] ?? null,
                (bool)($row['service_window_open'] ?? false)
            );
            $priceBrl = self::priceForUser((int)$row['user_id'], (string)$row['tenancy_id'], $category);
            self::generateCdr($row, 'blocked', null, null, $category, 0.0, false, $error);
        } catch (\Throwable $e) {
            error_log('[whatsapp_billing_cdr_blocked] ' . $e->getMessage());
        }
    }

    public static function recordFailed(array $row, string $error): void
    {
        try {
            $billing = self::authorizeOutbox($row);
            self::generateCdr(
                array_merge($row, ['pricing_snapshot' => $billing['pricing_snapshot'] ?? null]),
                'failed',
                null,
                null,
                (string)$billing['message_category'],
                0.0,
                false,
                $error
            );
        } catch (\Throwable $e) {
            error_log('[whatsapp_billing_cdr_failed] ' . $e->getMessage());
        }
    }

    public static function recordDirectSent(array $row, ?int $messageId, ?string $wamid, string $category, float $priceBrl): void
    {
        $category = WhatsAppCostPolicy::normalizeCategory($category);
        $priceBrl = round(max(0, $priceBrl), 4);
        $pricingSnapshot = is_array($row['pricing_snapshot'] ?? null)
            ? $row['pricing_snapshot']
            : self::pricingSnapshot(
                (int)$row['user_id'],
                (string)$row['tenancy_id'],
                $category,
                (string)($row['contact_phone'] ?? ''),
                $priceBrl
            );

        $row['pricing_snapshot'] = $pricingSnapshot;
        self::generateCdr($row, 'sent', $messageId, $wamid, $category, $priceBrl, false);
    }

    public static function billDeliveredByWamid(string $wamid): bool
    {
        $wamid = trim($wamid);
        if ($wamid === '') {
            return false;
        }

        $row = (new Database('whatsapp_message_cdr c'))
            ->select(
                "c.wamid = :wamid AND c.status = 'sent'",
                [':wamid' => $wamid],
                'c.id DESC',
                '1',
                [
                    'c.id',
                    'c.client_id',
                    'c.tenancy_id',
                    'c.phone_number',
                    'c.message_category',
                    'c.template_name',
                    'c.price_brl',
                    'c.cost_usd',
                    'c.exchange_rate',
                    'c.whatsapp_outbox_id',
                    'c.whatsapp_message_id',
                    'c.billed',
                    'c.delivered_at',
                ]
            )
            ->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        $category = WhatsAppCostPolicy::normalizeCategory((string)$row['message_category']);
        $priceBrl = round((float)($row['price_brl'] ?? 0), 4);
        $alreadyBilled = (int)($row['billed'] ?? 0) === 1;

        if ($alreadyBilled) {
            if (empty($row['delivered_at'])) {
                (new Database('whatsapp_message_cdr'))->update(
                    'id = :id',
                    ['delivered_at' => date('Y-m-d H:i:s')],
                    [':id' => (int)$row['id']]
                );
            }
            return true;
        }

        if (!self::isChargeableDeliveredTemplate($row, $category, $priceBrl)) {
            return false;
        }

        $billed = self::debitBalance((int)$row['client_id'], (string)$row['tenancy_id'], $priceBrl);
        if (!$billed) {
            error_log('[whatsapp_billing] Falha ao debitar saldo na entrega WhatsApp cdr_id=' . (int)$row['id']);
            return false;
        }

        BalanceSms::insertBalanceLog([
            'user_id' => (int)$row['client_id'],
            'tenancy_id' => (string)$row['tenancy_id'],
            'amount' => $priceBrl,
            'description' => sprintf(
                'WHATSAPP DELIVERED | phone:%s category:%s template:%s cost:%s usd:%s fx:%s',
                (string)$row['phone_number'],
                strtolower($category),
                (string)($row['template_name'] ?? ''),
                number_format($priceBrl, 4, '.', ''),
                number_format((float)($row['cost_usd'] ?? 0), 6, '.', ''),
                number_format((float)($row['exchange_rate'] ?? 0), 6, '.', '')
            ),
        ]);

        (new Database('whatsapp_message_cdr'))->update(
            'id = :id',
            [
                'billed' => 1,
                'delivered_at' => date('Y-m-d H:i:s'),
            ],
            [':id' => (int)$row['id']]
        );

        if (!empty($row['whatsapp_outbox_id'])) {
            (new Database('whatsapp_outbox'))->update(
                'id = :id',
                ['billed' => 1, 'updated_at' => date('Y-m-d H:i:s')],
                [':id' => (int)$row['whatsapp_outbox_id']]
            );
        }

        if (!empty($row['whatsapp_message_id'])) {
            (new Database('whatsapp_messages'))->update(
                'id = :id',
                ['billed' => 1, 'updated_at' => date('Y-m-d H:i:s')],
                [':id' => (int)$row['whatsapp_message_id']]
            );
        }

        return true;
    }

    private static function isChargeableDeliveredTemplate(array $row, string $category, float $priceBrl): bool
    {
        if ($priceBrl <= 0) {
            return false;
        }

        $category = WhatsAppCostPolicy::normalizeCategory($category);
        if (!in_array($category, [
            WhatsAppCostPolicy::CATEGORY_MARKETING,
            WhatsAppCostPolicy::CATEGORY_UTILITY,
            WhatsAppCostPolicy::CATEGORY_AUTHENTICATION,
        ], true)) {
            return false;
        }

        return trim((string)($row['template_name'] ?? '')) !== '';
    }

    private static function resolvePlanId(int $userId, string $tenancyId): ?int
    {
        $planId = RegisterTenancies::getActivePlanId($tenancyId);
        if ($planId) {
            return $planId;
        }

        $balance = BalanceSms::getBalanceSms($userId, $tenancyId);
        return $balance && $balance->plan_id ? (int)$balance->plan_id : null;
    }

    public static function freeMetaPolicySnapshot(string $category, string $phone, string $reason): array
    {
        $category = WhatsAppCostPolicy::normalizeCategory($category);

        return [
            'message_type' => strtolower($category),
            'message_category' => strtolower($category),
            'country_code' => WhatsAppDynamicPricing::countryCodeFromPhone($phone),
            'cost_usd' => 0.0,
            'exchange_rate' => 0.0,
            'safety_margin_percent' => 0.0,
            'effective_rate' => 0.0,
            'cost_brl' => 0.0,
            'margin_percent' => 0.0,
            'base_margin_percent' => 0.0,
            'extra_margin_percent' => 0.0,
            'final_price_brl' => 0.0,
            'charged_price_brl' => 0.0,
            'profit_brl' => 0.0,
            'exchange_alert' => false,
            'pricing_source' => 'meta_free_policy',
            'free_reason' => $reason,
            'quoted_at' => date('Y-m-d H:i:s'),
        ];
    }

    private static function ensureDefaultPricing(int $planId): void
    {
        foreach ([WhatsAppCostPolicy::CATEGORY_MARKETING, WhatsAppCostPolicy::CATEGORY_UTILITY, WhatsAppCostPolicy::CATEGORY_AUTHENTICATION] as $category) {
            try {
                (new Database())->execute(
                    "INSERT INTO plan_whatsapp_pricing (plan_id, category, price_brl, created_at, updated_at)
                     VALUES (:plan_id, :category, :price_brl, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE price_brl = price_brl",
                    [
                        ':plan_id' => $planId,
                        ':category' => strtolower($category),
                        ':price_brl' => WhatsAppCostPolicy::defaultPriceBrl($category),
                    ]
                );
            } catch (\Throwable $e) {
                error_log('[whatsapp_default_pricing_seed] ' . $e->getMessage());
            }
        }
    }

    private static function priceFromPlan(int $planId, string $category): ?float
    {
        $row = (new Database('plan_whatsapp_pricing'))
            ->select(
                'plan_id = :plan_id AND category = :category',
                [
                    ':plan_id' => $planId,
                    ':category' => strtolower(WhatsAppCostPolicy::normalizeCategory($category)),
                ],
                '',
                '1',
                ['price_brl']
            )
            ->fetch(\PDO::FETCH_ASSOC);

        return $row ? round((float)$row['price_brl'], 4) : null;
    }

    private static function syncPlanWhatsappPricingFromMxxPlan(int $planId): void
    {
        try {
            $columns = self::mxxPlanColumns();
            $fields = ['id', 'value_whatsapp'];
            foreach (['value_whatsapp_marketing', 'value_whatsapp_utility', 'value_whatsapp_authentication'] as $column) {
                if (isset($columns[$column])) {
                    $fields[] = $column;
                }
            }

            $plan = (new Database('mxx_plans'))
                ->select('id = :id', [':id' => $planId], '', '1', $fields)
                ->fetch(\PDO::FETCH_ASSOC);

            if (!$plan) {
                self::ensureDefaultPricing($planId);
                return;
            }

            $fallback = round((float)($plan['value_whatsapp'] ?? 0), 4);
            $prices = [
                'marketing' => self::planCategoryPrice($plan, 'value_whatsapp_marketing', $fallback),
                'utility' => self::planCategoryPrice($plan, 'value_whatsapp_utility', $fallback),
                'authentication' => self::planCategoryPrice($plan, 'value_whatsapp_authentication', $fallback),
            ];

            foreach ($prices as $category => $price) {
                if ($price <= 0) {
                    continue;
                }

                (new Database())->execute(
                    "INSERT INTO plan_whatsapp_pricing (plan_id, category, price_brl, created_at, updated_at)
                     VALUES (:plan_id, :category, :price_brl, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE price_brl = VALUES(price_brl), updated_at = NOW()",
                    [
                        ':plan_id' => $planId,
                        ':category' => $category,
                        ':price_brl' => $price,
                    ]
                );
            }
        } catch (\Throwable $e) {
            error_log('[whatsapp_plan_pricing_sync] ' . $e->getMessage());
            self::ensureDefaultPricing($planId);
        }
    }

    private static function planCategoryPrice(array $plan, string $column, float $fallback): float
    {
        $value = array_key_exists($column, $plan) ? round((float)$plan[$column], 4) : 0.0;
        return $value > 0 ? $value : $fallback;
    }

    private static function mxxPlanColumns(): array
    {
        static $columns = null;
        if ($columns === null) {
            try {
                $rows = (new Database())->execute('SHOW COLUMNS FROM mxx_plans')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                $columns = array_fill_keys(array_map(static fn ($row) => (string)$row['Field'], $rows), true);
            } catch (\Throwable $e) {
                $columns = [];
            }
        }

        return $columns;
    }

    private static function priceFromResellerCategoryRate(int $userId, string $tenancyId, string $category): ?float
    {
        $category = strtolower(WhatsAppCostPolicy::normalizeCategory($category));
        $resellerId = self::resolveResellerIdForUser($userId, $tenancyId);

        if (!$resellerId) {
            return null;
        }

        try {
            return Rates::getActiveWhatsAppCategoryPrice($tenancyId, $resellerId, $category);
        } catch (\Throwable $e) {
            error_log('[whatsapp_reseller_pricing_fallback] ' . $e->getMessage());
            return null;
        }
    }

    private static function resolveResellerIdForUser(int $userId, string $tenancyId): ?int
    {
        try {
            $user = (new Database('users'))
                ->select(
                    'id = :id AND tenancy_id = :tenancy_id',
                    [
                        ':id' => $userId,
                        ':tenancy_id' => $tenancyId,
                    ],
                    '',
                    1,
                    'id, user_id, user_function'
                )
                ->fetch(\PDO::FETCH_ASSOC);

            if (!$user) {
                return null;
            }

            if (($user['user_function'] ?? '') === 'reseller') {
                return (int)$user['id'];
            }

            $parentId = (int)($user['user_id'] ?? 0);
            if ($parentId <= 0) {
                return null;
            }

            $parent = (new Database('users'))
                ->select(
                    'id = :id AND tenancy_id = :tenancy_id AND user_function = "reseller"',
                    [
                        ':id' => $parentId,
                        ':tenancy_id' => $tenancyId,
                    ],
                    '',
                    1,
                    'id'
                )
                ->fetch(\PDO::FETCH_ASSOC);

            return $parent ? (int)$parent['id'] : null;
        } catch (\Throwable $e) {
            error_log('[whatsapp_reseller_lookup] ' . $e->getMessage());
            return null;
        }
    }

    private static function debitBalance(int $userId, string $tenancyId, float $amount): bool
    {
        $amount = round(max(0, $amount), 4);
        if ($amount <= 0) {
            return true;
        }

        $balance = BalanceSms::getBalanceSms($userId, $tenancyId, self::resolvePlanId($userId, $tenancyId));
        if (!$balance || (float)$balance->balance < $amount) {
            return false;
        }

        if (isset($balance->id, $balance->plan_id) && $balance->id && $balance->plan_id) {
            return (new Database())->execute(
                "UPDATE tenancy_balance
                 SET balance = balance - :amount, updated_at = NOW()
                 WHERE id = :id
                   AND user_id = :user_id
                   AND tenancy_id = :tenancy_id
                   AND balance >= :amount",
                [
                    ':amount' => $amount,
                    ':id' => (int)$balance->id,
                    ':user_id' => $userId,
                    ':tenancy_id' => $tenancyId,
                ]
            )->rowCount() === 1;
        }

        return BalanceSms::decrementResellerBalance($amount, $userId, $tenancyId);
    }

    private static function generateCdr(
        array $row,
        string $status,
        ?int $messageId,
        ?string $wamid,
        string $category,
        float $priceBrl,
        bool $billed,
        ?string $error = null
    ): void {
        if (!self::shouldInsertCdr($row, $status, $wamid)) {
            return;
        }

        $normalizedCategory = WhatsAppCostPolicy::normalizeCategory($category);
        $countryCode = WhatsAppDynamicPricing::countryCodeFromPhone((string)$row['contact_phone']);
        $pricing = [];
        try {
            $pricing = is_array($row['pricing_snapshot'] ?? null)
                ? $row['pricing_snapshot']
                : WhatsAppDynamicPricing::pricingForLedger(strtolower($normalizedCategory), $countryCode, (int)$row['user_id']);
            $pricing['final_price_brl'] = round(max(0, $priceBrl), 4);
        } catch (\Throwable $e) {
            error_log('[whatsapp_pricing_ledger] ' . $e->getMessage());
        }

        $values = [
            'client_id' => (int)$row['user_id'],
            'tenancy_id' => (string)$row['tenancy_id'],
            'type' => 'whatsapp',
            'phone_number' => (string)$row['contact_phone'],
            'message_category' => strtolower($normalizedCategory),
            'template_name' => $row['template_name'] ?? null,
            'direction' => 'outbound',
            'price_brl' => round(max(0, $priceBrl), 4),
            'billed' => $billed ? 1 : 0,
            'status' => $status,
            'whatsapp_outbox_id' => (int)($row['id'] ?? 0) ?: null,
            'whatsapp_message_id' => $messageId,
            'wamid' => $wamid,
            'error_message' => $error,
            'timestamp' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        foreach ([
            'country_code',
            'cost_usd',
            'exchange_rate',
            'effective_rate',
            'cost_brl',
            'margin_percent',
            'final_price_brl',
        ] as $column) {
            if (array_key_exists($column, $pricing) && self::cdrHasColumn($column)) {
                $values[$column] = $pricing[$column];
            }
        }
        if (isset($pricing['pricing_payload']) && self::cdrHasColumn('pricing_payload')) {
            $values['pricing_payload'] = json_encode($pricing['pricing_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        (new Database('whatsapp_message_cdr'))->insert($values);
    }

    private static function shouldInsertCdr(array $row, string $status, ?string $wamid): bool
    {
        $outboxId = (int)($row['id'] ?? 0);
        if ($outboxId > 0) {
            $existing = (new Database('whatsapp_message_cdr'))
                ->select(
                    'whatsapp_outbox_id = :outbox_id AND status = :status',
                    [
                        ':outbox_id' => $outboxId,
                        ':status' => $status,
                    ],
                    '',
                    '1',
                    ['id']
                )
                ->fetch(\PDO::FETCH_ASSOC);
            if ($existing) {
                return false;
            }
        }

        if ($wamid) {
            $existing = (new Database('whatsapp_message_cdr'))
                ->select('wamid = :wamid AND status = :status', [':wamid' => $wamid, ':status' => $status], '', '1', ['id'])
                ->fetch(\PDO::FETCH_ASSOC);
            if ($existing) {
                return false;
            }
        }

        return true;
    }

    private static function cdrHasColumn(string $column): bool
    {
        static $columns = null;
        if ($columns === null) {
            try {
                $rows = (new Database())->execute('SHOW COLUMNS FROM whatsapp_message_cdr')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                $columns = array_fill_keys(array_map(static fn ($row) => (string)$row['Field'], $rows), true);
            } catch (\Throwable $e) {
                $columns = [];
            }
        }

        return isset($columns[$column]);
    }
}
