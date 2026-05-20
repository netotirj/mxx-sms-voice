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

        $context = self::commercialPricingContextForUser($userId, $tenancyId, $category, $countryCode);
        return round((float)($context['final_commercial_price_brl'] ?? 0), 4);
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
        $context = self::commercialPricingContextForUser($userId, $tenancyId, $category, $countryCode);
        $refresh = $context['exchange_refresh'] ?? null;
        $pricing = is_array($context['dynamic_pricing_payload'] ?? null)
            ? $context['dynamic_pricing_payload']
            : [];
        $finalPrice = $chargedPriceBrl !== null
            ? round(max(0, $chargedPriceBrl), 4)
            : round((float)($context['final_commercial_price_brl'] ?? 0), 4);
        $pricing['final_price_brl'] = $finalPrice;
        $pricing['charged_price_brl'] = $finalPrice;
        $pricing['profit_brl'] = round($finalPrice - (float)($pricing['cost_brl'] ?? 0), 6);

        return array_merge($pricing, [
            'plan_id' => $context['plan_id'] ?? null,
            'plan_price_brl' => $context['plan_price_brl'] ?? null,
            'dynamic_price_brl' => $context['dynamic_price_brl'] ?? null,
            'final_commercial_price_brl' => $finalPrice,
            'pricing_rule' => 'max(dynamic,plan)',
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

    public static function categoryDisplayPricesForUser(int $userId, string $tenancyId, ?string $countryCode = null): array
    {
        $resolved = PlanDisplayPricingService::getEffectiveWhatsAppRatesForUser(
            $userId,
            $tenancyId,
            PlanRuntimeService::getDisplaySummary($tenancyId)
        );

        return [
            'marketing' => round((float)($resolved['marketing']['display'] ?? 0), 4),
            'utility' => round((float)($resolved['utility']['display'] ?? 0), 4),
            'authentication' => round((float)($resolved['authentication']['display'] ?? 0), 4),
            'service' => 0.0,
        ];
    }

    public static function planFloorPriceForUser(int $userId, string $tenancyId, string $category): ?float
    {
        return self::contractedPlanPriceForUser($userId, $tenancyId, $category);
    }

    public static function contractedPlanPriceForUser(int $userId, string $tenancyId, string $category): ?float
    {
        $rawCategory = strtolower(trim($category));
        if ($rawCategory === 'voice') {
            $snapshotVoicePrice = self::snapshotPlanCategoryPrice($userId, $tenancyId, 'voice');
            if ($snapshotVoicePrice !== null && $snapshotVoicePrice > 0) {
                return $snapshotVoicePrice;
            }

            return self::currentVoicePlanPrice($userId, $tenancyId);
        }

        $category = WhatsAppCostPolicy::normalizeCategory($category);
        if ($category === WhatsAppCostPolicy::CATEGORY_SERVICE) {
            return 0.0;
        }

        $snapshotPrice = self::snapshotPlanCategoryPrice($userId, $tenancyId, $category);
        if ($snapshotPrice !== null && $snapshotPrice > 0) {
            return $snapshotPrice;
        }

        $planId = self::resolvePlanId($userId, $tenancyId);
        if (!$planId) {
            return null;
        }

        return self::contractedPriceFromPlanCatalog($planId, $category);
    }

    public static function currentQuotePriceForUser(int $userId, string $tenancyId, string $category, ?string $countryCode = null): ?float
    {
        $category = WhatsAppCostPolicy::normalizeCategory($category);
        if ($category === WhatsAppCostPolicy::CATEGORY_SERVICE) {
            return 0.0;
        }

        $resolved = self::resolveCurrentQuotePriceForUser($userId, $tenancyId, $category, $countryCode, false);
        $quote = round((float)($resolved['price'] ?? 0), 4);

        return $quote > 0 ? $quote : null;
    }

    public static function assertCanSend(int $userId, string $tenancyId, string $category, float $priceBrl): void
    {
        $category = WhatsAppCostPolicy::normalizeCategory($category);
        $priceBrl = round(max(0, $priceBrl), 4);

        if ($category === WhatsAppCostPolicy::CATEGORY_SERVICE || $priceBrl <= 0) {
            return;
        }

        $plan = self::buildHierarchyDebitPlan(
            $userId,
            $tenancyId,
            $category,
            $priceBrl,
            '',
            'whatsapp_auth',
            'whatsapp_message'
        );

        try {
            FinancialHierarchyBillingService::assertSufficientBalance($plan);
        } catch (\Throwable $e) {
            throw new \RuntimeException(self::ERROR_INSUFFICIENT_BALANCE);
        }
    }

    public static function assertCanSendBatch(int $userId, string $tenancyId, array $messages): void
    {
        $legs = [];

        foreach ($messages as $message) {
            $category = WhatsAppCostPolicy::normalizeCategory((string)($message['message_category'] ?? ''));
            $priceBrl = round((float)($message['price_brl'] ?? 0), 4);
            if ($category === WhatsAppCostPolicy::CATEGORY_SERVICE || $priceBrl <= 0) {
                continue;
            }

            $plan = self::buildHierarchyDebitPlan(
                $userId,
                $tenancyId,
                $category,
                $priceBrl,
                (string)($message['contact_phone'] ?? ''),
                'whatsapp_batch_auth',
                'whatsapp_message'
            );

            foreach ((array)($plan['legs'] ?? []) as $leg) {
                $key = implode(':', [
                    (string)($leg['wallet'] ?? ''),
                    (string)($leg['user_id'] ?? 0),
                    (string)($leg['slug'] ?? ''),
                ]);

                if (!isset($legs[$key])) {
                    $legs[$key] = $leg;
                    continue;
                }

                $legs[$key]['amount'] = round((float)$legs[$key]['amount'] + (float)($leg['amount'] ?? 0), 4);
            }
        }

        if (empty($legs)) {
            return;
        }

        try {
            FinancialHierarchyBillingService::assertSufficientBalance([
                'module' => 'whatsapp',
                'event' => 'batch_auth',
                'context' => FinancialHierarchyResolver::resolveContext($userId, $tenancyId)->toArray(),
                'legs' => array_values($legs),
            ]);
        } catch (\Throwable $e) {
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

        $plan = self::buildHierarchyDebitPlan(
            (int)$row['client_id'],
            (string)$row['tenancy_id'],
            $category,
            $priceBrl,
            (string)($row['phone_number'] ?? ''),
            'whatsapp_delivered',
            'whatsapp_message',
            $wamid,
            [
                'cdr_id' => (int)$row['id'],
                'whatsapp_outbox_id' => (int)($row['whatsapp_outbox_id'] ?? 0),
                'whatsapp_message_id' => (int)($row['whatsapp_message_id'] ?? 0),
                'message_category' => strtolower($category),
                'cost_usd' => (float)($row['cost_usd'] ?? 0),
                'exchange_rate' => (float)($row['exchange_rate'] ?? 0),
            ],
            sprintf(
                'WHATSAPP DELIVERED | phone:%s category:%s template:%s cost:%s usd:%s fx:%s',
                (string)$row['phone_number'],
                strtolower($category),
                (string)($row['template_name'] ?? ''),
                number_format($priceBrl, 4, '.', ''),
                number_format((float)($row['cost_usd'] ?? 0), 6, '.', ''),
                number_format((float)($row['exchange_rate'] ?? 0), 6, '.', '')
            )
        );

        $operation = FinancialHierarchyBillingService::debitPlan($plan, static function (Database $db) use ($row): void {
            $db->run(
                "UPDATE whatsapp_message_cdr
                 SET billed = 1,
                     delivered_at = NOW()
                 WHERE id = :id",
                [':id' => (int)$row['id']]
            );

            if (!empty($row['whatsapp_outbox_id'])) {
                $db->run(
                    "UPDATE whatsapp_outbox
                     SET billed = 1,
                         updated_at = NOW()
                     WHERE id = :id",
                    [':id' => (int)$row['whatsapp_outbox_id']]
                );
            }

            if (!empty($row['whatsapp_message_id'])) {
                $db->run(
                    "UPDATE whatsapp_messages
                     SET billed = 1,
                         updated_at = NOW()
                     WHERE id = :id",
                    [':id' => (int)$row['whatsapp_message_id']]
                );
            }
        });

        if (empty($operation['ok'])) {
            error_log('[whatsapp_billing] Falha ao debitar saldo na entrega WhatsApp cdr_id=' . (int)$row['id']);
            return false;
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

    private static function priceFromPlanPricingTable(int $planId, string $category): ?float
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

    private static function commercialPricingContextForUser(int $userId, string $tenancyId, string $category, ?string $countryCode = null, bool $refreshExchange = true): array
    {
        $category = WhatsAppCostPolicy::normalizeCategory($category);
        $planId = self::resolvePlanId($userId, $tenancyId);
        $planPrice = self::contractedPlanPriceForUser($userId, $tenancyId, $category);
        $resolvedQuote = self::resolveCurrentQuotePriceForUser($userId, $tenancyId, $category, $countryCode, $refreshExchange);
        $currentQuote = round((float)($resolvedQuote['price'] ?? 0), 4);
        $dynamicPricingPayload = (array)($resolvedQuote['payload'] ?? []);
        $exchangeRefresh = $resolvedQuote['exchange_refresh'] ?? null;
        $fallbackPrice = $planPrice;

        if ($fallbackPrice === null || $fallbackPrice <= 0) {
            $resellerFallbackPrice = self::priceFromResellerCategoryRate($userId, $tenancyId, $category);
            if ($resellerFallbackPrice !== null && $resellerFallbackPrice > 0) {
                $fallbackPrice = round((float)$resellerFallbackPrice, 4);
            }
        }

        $finalCommercialPrice = 0.0;
        $pricingSource = 'default_fallback';

        if ($currentQuote > 0) {
            $finalCommercialPrice = $fallbackPrice !== null && $fallbackPrice > 0
                ? max($currentQuote, round((float)$fallbackPrice, 4))
                : $currentQuote;
            $pricingSource = $fallbackPrice !== null && $fallbackPrice > $currentQuote
                ? 'plan_floor'
                : ((string)($resolvedQuote['source'] ?? 'current_quote'));
        } elseif ($fallbackPrice !== null && $fallbackPrice > 0) {
            $finalCommercialPrice = round((float)$fallbackPrice, 4);
            $pricingSource = 'plan_fallback';
        } else {
            $finalCommercialPrice = WhatsAppCostPolicy::defaultPriceBrl($category);
        }

        return [
            'plan_id' => $planId,
            'plan_price_brl' => $planPrice !== null ? round((float)$planPrice, 4) : null,
            'dynamic_price_brl' => $currentQuote > 0 ? round((float)$currentQuote, 4) : null,
            'final_commercial_price_brl' => round((float)$finalCommercialPrice, 4),
            'pricing_rule' => 'max(dynamic,plan)',
            'pricing_source' => $pricingSource,
            'exchange_refresh' => $exchangeRefresh,
            'dynamic_pricing_payload' => $dynamicPricingPayload,
        ];
    }

    private static function resolveCurrentQuotePriceForUser(
        int $userId,
        string $tenancyId,
        string $category,
        ?string $countryCode = null,
        bool $refreshExchange = true
    ): array {
        $dynamicPrice = null;
        $dynamicPricingPayload = [];
        $exchangeRefresh = null;

        try {
            $exchangeRefresh = $refreshExchange
                ? WhatsAppDynamicPricing::refreshUsdRateIfStale()
                : [
                    'success' => true,
                    'message' => 'Header/display pricing using persisted exchange snapshot.',
                    'cache' => 'display_only',
                ];
            $dynamicPricingPayload = WhatsAppDynamicPricing::calculatePrice(
                strtolower($category),
                $countryCode ?: (string)TelephonyConfig::env('WHATSAPP_DEFAULT_COUNTRY_CODE', 'BR'),
                $userId
            );
            $dynamicPrice = round((float)($dynamicPricingPayload['final_price_brl'] ?? 0), 4);
        } catch (\Throwable $e) {
            error_log('[whatsapp_dynamic_pricing_fallback] ' . $e->getMessage());
            if ($refreshExchange) {
                throw $e;
            }
        }

        if ($dynamicPrice !== null && $dynamicPrice > 0) {
            return [
                'price' => $dynamicPrice,
                'source' => 'dynamic_price',
                'payload' => $dynamicPricingPayload,
                'exchange_refresh' => $exchangeRefresh,
            ];
        }

        $planId = self::resolvePlanId($userId, $tenancyId);
        if ($planId) {
            $persistedQuote = self::priceFromPlanPricingTable($planId, $category);
            if ($persistedQuote !== null && $persistedQuote > 0) {
                return [
                    'price' => round((float)$persistedQuote, 4),
                    'source' => 'persisted_quote',
                    'payload' => $dynamicPricingPayload,
                    'exchange_refresh' => $exchangeRefresh,
                ];
            }
        }

        return [
            'price' => 0.0,
            'source' => 'unavailable',
            'payload' => $dynamicPricingPayload,
            'exchange_refresh' => $exchangeRefresh,
        ];
    }

    private static function snapshotPlanCategoryPrice(int $userId, string $tenancyId, string $category): ?float
    {
        try {
            $row = (new Database())->execute(
                "SELECT snapshot_json
                 FROM tenancy_balance
                 WHERE user_id = :user_id
                   AND tenancy_id = :tenancy_id
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 1",
                [
                    ':user_id' => $userId,
                    ':tenancy_id' => $tenancyId,
                ]
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                return null;
            }

            $snapshot = json_decode((string)($row['snapshot_json'] ?? ''), true);
            if (!is_array($snapshot)) {
                return null;
            }

            $pricing = is_array($snapshot['pricing'] ?? null) ? $snapshot['pricing'] : [];
            $key = match (strtolower($category)) {
                'marketing' => 'value_whatsapp_marketing',
                'utility' => 'value_whatsapp_utility',
                'authentication' => 'value_whatsapp_authentication',
                'voice' => 'whatsapp_voice_price_per_minute',
                default => null,
            };

            if ($key === null || !isset($pricing[$key])) {
                return null;
            }

            $price = round((float)$pricing[$key], 4);
            return $price > 0 ? $price : null;
        } catch (\Throwable $e) {
            error_log('[whatsapp_snapshot_plan_price] ' . $e->getMessage());
            return null;
        }
    }

    private static function currentVoicePlanPrice(int $userId, string $tenancyId): ?float
    {
        $planId = self::resolvePlanId($userId, $tenancyId);
        if (!$planId) {
            return null;
        }

        try {
            $row = (new Database('mxx_plans'))
                ->select(
                    'id = :id',
                    [':id' => $planId],
                    '',
                    '1',
                    ['whatsapp_voice_price_per_minute']
                )
                ->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                return null;
            }

            $price = round((float)($row['whatsapp_voice_price_per_minute'] ?? 0), 4);
            return $price > 0 ? $price : null;
        } catch (\Throwable $e) {
            error_log('[whatsapp_current_voice_plan_price] ' . $e->getMessage());
            return null;
        }
    }

    private static function contractedPriceFromPlanCatalog(int $planId, string $category): ?float
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
                return null;
            }

            $fallback = round((float)($plan['value_whatsapp'] ?? 0), 4);
            $price = match (strtolower($category)) {
                'marketing' => self::planCategoryPrice($plan, 'value_whatsapp_marketing', $fallback),
                'utility' => self::planCategoryPrice($plan, 'value_whatsapp_utility', $fallback),
                'authentication' => self::planCategoryPrice($plan, 'value_whatsapp_authentication', $fallback),
                default => $fallback,
            };

            return $price > 0 ? $price : null;
        } catch (\Throwable $e) {
            error_log('[whatsapp_plan_catalog_price] ' . $e->getMessage());
            return null;
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

    private static function buildHierarchyDebitPlan(
        int $userId,
        string $tenancyId,
        string $category,
        float $retailPrice,
        string $phone,
        string $event,
        string $relatedType,
        ?string $relatedId = null,
        array $metadata = [],
        ?string $descriptionPrefix = null
    ): array {
        $context = FinancialHierarchyResolver::resolveContext($userId, $tenancyId);
        $resellerAmount = 0.0;
        $adminAmount = 0.0;
        $countryCode = $phone !== '' ? WhatsAppDynamicPricing::countryCodeFromPhone($phone) : null;

        if ($context->reseller_id && $context->reseller_id !== $userId) {
            $resellerAmount = round(self::priceForUser((int)$context->reseller_id, $tenancyId, $category, $countryCode), 4);
        }

        if ($context->owner_admin_id > 0 && $context->owner_admin_id !== $userId) {
            $adminAmount = self::upstreamBaseCostForCategory($category, $countryCode);
        }

        return FinancialHierarchyBillingService::buildDebitPlan([
            'actor_user_id' => $userId,
            'tenancy_id' => $tenancyId,
            'module' => 'whatsapp',
            'event' => $event,
            'retail_amount' => $retailPrice,
            'reseller_amount' => $resellerAmount,
            'admin_amount' => $adminAmount,
            'provider_reference' => $relatedId,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'operation_key_base' => 'whatsapp:' . $event . ':' . ($relatedId ?: ($tenancyId . ':' . $userId)),
            'description_prefix' => $descriptionPrefix ?: strtoupper('whatsapp_' . $event),
            'metadata' => $metadata,
        ]);
    }

    private static function upstreamBaseCostForCategory(string $category, ?string $countryCode = null): float
    {
        $normalizedCategory = strtolower(WhatsAppCostPolicy::normalizeCategory($category));
        if ($normalizedCategory === strtolower(WhatsAppCostPolicy::CATEGORY_SERVICE)) {
            return 0.0;
        }

        try {
            $pricing = WhatsAppDynamicPricing::calculatePrice(
                $normalizedCategory,
                $countryCode ?: (string)TelephonyConfig::env('WHATSAPP_DEFAULT_COUNTRY_CODE', 'BR'),
                0
            );
            return round((float)($pricing['cost_brl'] ?? 0), 4);
        } catch (\Throwable $e) {
            error_log('[whatsapp_upstream_base_cost] ' . $e->getMessage());
            return 0.0;
        }
    }
}
