<?php

namespace App\Service;

use App\Model\Entity\BalanceSms;
use App\Model\Entity\Rates;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppVoiceBilling
{
    public static function commercialPricePerMinuteForUser(int $userId, string $tenancyId, ?string $phone = null, bool $refreshExchange = true): float
    {
        if ($userId <= 0 || trim($tenancyId) === '') {
            return 0.0;
        }

        $summary = PlanRuntimeService::getDisplaySummary($tenancyId);
        $pricingContext = self::resolvePricingContext($userId, $tenancyId, $summary, (string)($phone ?? ''), $refreshExchange);

        return round((float)($pricingContext['final_price_per_minute_brl'] ?? 0), 4);
    }

    public static function handleCallWebhook(array $account, array $call, array $value = []): void
    {
        $callId = trim((string)($call['id'] ?? $call['call_id'] ?? ''));
        if ($callId === '') {
            return;
        }

        $accountId = (int)($account['id'] ?? 0);
        $tenancyId = trim((string)($account['tenancy_id'] ?? ''));
        if ($accountId <= 0 || $tenancyId === '') {
            return;
        }

        $customerId = (int)($account['user_id'] ?? 0) ?: null;
        $summary = PlanRuntimeService::getDisplaySummary($tenancyId);
        $planId = $summary?->plan_id ? (int)$summary->plan_id : null;
        $existing = WhatsAppCallCdrStore::findByCallId($callId);

        $status = self::resolveStatus($call, $existing);
        $startedAt = self::resolveDateTime($call['started_at'] ?? $call['start_time'] ?? null);
        $answeredAt = self::resolveDateTime($call['answered_at'] ?? null);
        $endedAt = self::resolveDateTime($call['ended_at'] ?? $call['end_time'] ?? null);
        $durationSeconds = self::resolveDurationSeconds($call, $answeredAt, $endedAt);

        $pricingContext = self::resolvePricingContext($customerId, $tenancyId, $summary, self::pricingReferencePhone($call));

        $row = [
            'tenancy_id' => $tenancyId,
            'customer_id' => $customerId,
            'plan_id' => $planId,
            'account_id' => $accountId,
            'call_id' => $callId,
            'direction' => self::normalizeDirection((string)($call['direction'] ?? '')),
            'from_number' => $call['from'] ?? $call['from_number'] ?? null,
            'to_number' => $call['to'] ?? $call['to_number'] ?? null,
            'status' => $status,
            'started_at' => $startedAt,
            'answered_at' => $answeredAt,
            'ended_at' => $endedAt,
            'duration_seconds' => $durationSeconds,
            'raw_payload' => [
                'account' => [
                    'id' => $accountId,
                    'user_id' => $customerId,
                    'tenancy_id' => $tenancyId,
                ],
                'pricing' => $pricingContext,
                'value' => $value,
                'call' => $call,
            ],
        ];

        if ($existing && (float)($existing['final_price'] ?? 0) > 0) {
            $row['billable_seconds'] = $existing['billable_seconds'] ?? null;
            $row['billable_minutes'] = $existing['billable_minutes'] ?? null;
            $row['pulse_seconds'] = $existing['pulse_seconds'] ?? null;
            $row['price_per_minute'] = $existing['price_per_minute'] ?? null;
            $row['base_cost'] = $existing['base_cost'] ?? null;
            $row['markup_percent'] = $existing['markup_percent'] ?? null;
            $row['final_price'] = $existing['final_price'] ?? null;
            WhatsAppCallCdrStore::upsert($row);

            if (!empty($existing['balance_debited_at']) || !$customerId || (float)($row['final_price'] ?? 0) <= 0) {
                return;
            }

            self::attemptBalanceDebit($customerId, $tenancyId, (float)$row['final_price'], $callId);
            return;
        }

        if (!self::isBillableStatus(
            $status,
            $durationSeconds,
            self::normalizeDirection((string)($call['direction'] ?? ''))
        )) {
            $metaRateUsdPerMinute = round((float)($pricingContext['meta_rate_usd_per_minute'] ?? $pricingContext['cost_usd'] ?? 0), 6);
            $row['billable_seconds'] = 0;
            $row['billable_minutes'] = 0.0;
            $row['country_code'] = $pricingContext['country_code'] ?? null;
            $row['cost_usd'] = 0.0;
            $row['exchange_rate'] = (float)($pricingContext['exchange_rate'] ?? 0);
            $row['effective_rate'] = (float)($pricingContext['effective_rate'] ?? 0);
            $row['cost_brl'] = 0.0;
            $row['margin_percent'] = (float)($pricingContext['markup_percent'] ?? 0);
            $row['price_brl'] = 0.0;
            $row['final_price_brl'] = 0.0;
            $row['pulse_seconds'] = self::pulseSeconds($summary);
            $row['price_per_minute'] = (float)($pricingContext['final_price_per_minute_brl'] ?? 0);
            $row['base_cost'] = 0.0;
            $row['markup_percent'] = (float)($pricingContext['markup_percent'] ?? 0);
            $row['final_price'] = 0.0;
            $row['pricing_payload'] = [
                'country_code' => $pricingContext['country_code'] ?? null,
                'cost_usd' => 0.0,
                'exchange_rate' => (float)($pricingContext['exchange_rate'] ?? 0),
                'effective_rate' => (float)($pricingContext['effective_rate'] ?? 0),
                'cost_brl' => 0.0,
                'margin_percent' => (float)($pricingContext['markup_percent'] ?? 0),
                'final_price_brl' => 0.0,
                'meta_rate_usd_per_minute' => $metaRateUsdPerMinute,
                'final_price_per_minute_brl' => (float)($pricingContext['final_price_per_minute_brl'] ?? 0),
                'base_price_per_minute_brl' => (float)($pricingContext['base_price_per_minute_brl'] ?? 0),
            ];
            WhatsAppCallCdrStore::upsert($row);
            return;
        }

        $pulseSeconds = self::pulseSeconds($summary);
        $pricePerMinute = round((float)($pricingContext['final_price_per_minute_brl'] ?? 0), 4);
        $basePricePerMinute = round((float)($pricingContext['base_price_per_minute_brl'] ?? 0), 4);
        $markupPercent = round((float)($pricingContext['markup_percent'] ?? 0), 4);
        $metaRateUsdPerMinute = round((float)($pricingContext['meta_rate_usd_per_minute'] ?? $pricingContext['cost_usd'] ?? 0), 6);
        $billableSeconds = (int)(ceil($durationSeconds / $pulseSeconds) * $pulseSeconds);
        $billableMinutes = round($billableSeconds / 60, 4);
        $costUsd = round($billableMinutes * $metaRateUsdPerMinute, 6);
        $baseCost = round($billableMinutes * $basePricePerMinute, 4);
        $finalPrice = round($billableMinutes * $pricePerMinute, 4);

        $row['billable_seconds'] = $billableSeconds;
        $row['billable_minutes'] = $billableMinutes;
        $row['country_code'] = $pricingContext['country_code'] ?? null;
        $row['cost_usd'] = $costUsd;
        $row['exchange_rate'] = (float)($pricingContext['exchange_rate'] ?? 0);
        $row['effective_rate'] = (float)($pricingContext['effective_rate'] ?? 0);
        $row['cost_brl'] = $baseCost;
        $row['margin_percent'] = $markupPercent;
        $row['price_brl'] = $finalPrice;
        $row['final_price_brl'] = $finalPrice;
        $row['pulse_seconds'] = $pulseSeconds;
        $row['price_per_minute'] = $pricePerMinute;
        $row['base_cost'] = $baseCost;
        $row['markup_percent'] = $markupPercent;
        $row['final_price'] = $finalPrice;
        $row['pricing_payload'] = [
            'country_code' => $pricingContext['country_code'] ?? null,
            'cost_usd' => $costUsd,
            'exchange_rate' => (float)($pricingContext['exchange_rate'] ?? 0),
            'effective_rate' => (float)($pricingContext['effective_rate'] ?? 0),
            'cost_brl' => $baseCost,
            'margin_percent' => $markupPercent,
            'final_price_brl' => $finalPrice,
            'meta_rate_usd_per_minute' => $metaRateUsdPerMinute,
            'final_price_per_minute_brl' => $pricePerMinute,
            'base_price_per_minute_brl' => $basePricePerMinute,
        ];

        WhatsAppCallCdrStore::upsert($row);

        if ($finalPrice > 0 && $customerId) {
            self::attemptBalanceDebit($customerId, $tenancyId, $finalPrice, $callId);
        }
    }

    private static function pulseSeconds(?object $summary): int
    {
        return max(1, (int)($summary->whatsapp_voice_billing_pulse_seconds ?? 6));
    }

    private static function pricePerMinute(?object $summary): float
    {
        return round((float)($summary->whatsapp_voice_price_per_minute ?? 0), 4);
    }

    private static function isBillableStatus(string $status, int $durationSeconds, ?string $direction): bool
    {
        if ($direction !== 'outbound') {
            return false;
        }

        if ($durationSeconds <= 0) {
            return false;
        }

        return in_array($status, ['ANSWER', 'COMPLETED', 'ACCEPTED'], true);
    }

    private static function resolveStatus(array $call, ?array $existing = null): string
    {
        $status = strtoupper(trim((string)($call['status'] ?? '')));
        $event = strtoupper(trim((string)($call['event'] ?? '')));
        $existingStatus = strtoupper(trim((string)($existing['status'] ?? '')));

        if ($status === '' && $event === 'TERMINATE' && $existingStatus !== '') {
            return $existingStatus;
        }

        if ($status !== '') {
            return $status;
        }

        return $event !== '' ? $event : 'UNKNOWN';
    }

    private static function resolveDurationSeconds(array $call, ?string $answeredAt, ?string $endedAt): int
    {
        foreach (['duration_seconds', 'duration'] as $field) {
            if (isset($call[$field]) && is_numeric($call[$field])) {
                return max(0, (int)$call[$field]);
            }
        }

        return 0;
    }

    private static function resolveDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int)$value);
        }

        try {
            return (new \DateTime((string)$value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function normalizeDirection(string $direction): ?string
    {
        $direction = strtoupper(trim($direction));
        return match ($direction) {
            'USER_INITIATED', 'INBOUND' => 'inbound',
            'BUSINESS_INITIATED', 'OUTBOUND' => 'outbound',
            '' => null,
            default => strtolower($direction),
        };
    }

    private static function pricingReferencePhone(array $call): string
    {
        $direction = self::normalizeDirection((string)($call['direction'] ?? ''));
        $from = (string)($call['from'] ?? $call['from_number'] ?? '');
        $to = (string)($call['to'] ?? $call['to_number'] ?? '');

        if ($direction === 'inbound') {
            return $from !== '' ? $from : $to;
        }

        return $to !== '' ? $to : $from;
    }

    private static function attemptBalanceDebit(int $userId, string $tenancyId, float $amount, string $callId): void
    {
        if ($userId <= 0 || $tenancyId === '' || $amount <= 0 || trim($callId) === '') {
            return;
        }

        $lockToken = self::chargeLockToken();
        if (!WhatsAppCallCdrStore::tryAcquireChargeLock($callId, $lockToken)) {
            return;
        }

        try {
            $plan = self::buildHierarchyDebitPlan($userId, $tenancyId, $amount, $callId, $lockToken);
            $result = FinancialHierarchyBillingService::debitPlan($plan, static function (Database $db) use ($callId, $lockToken): void {
                $db->run(
                    "UPDATE whatsapp_call_cdr
                     SET balance_debited_at = NOW(),
                         updated_at = NOW()
                     WHERE call_id = :call_id
                       AND charge_lock_token = :token",
                    [
                        ':call_id' => $callId,
                        ':token' => $lockToken,
                    ]
                );
            });

            if (!empty($result['ok'])) {
                return;
            }
        } catch (\Throwable $e) {
            error_log('[whatsapp_voice_debit_attempt] ' . $e->getMessage());
        }

        WhatsAppCallCdrStore::releaseChargeLock($callId, $lockToken);
    }

    private static function chargeLockToken(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return sha1(uniqid('wa_voice_charge_', true));
        }
    }

    private static function resolvePricingContext(?int $customerId, string $tenancyId, ?object $summary, string $phone, bool $refreshExchange = true): array
    {
        $exchangeRefresh = $refreshExchange
            ? self::refreshExchangeRate()
            : [
                'success' => true,
                'message' => 'Header/display pricing using persisted exchange snapshot.',
                'cache' => 'display_only',
            ];
        $dynamicPricing = self::dynamicVoicePricing($customerId, $phone);
        $exchange = self::currentExchangeSnapshot();
        $basePricePerMinute = round((float)($dynamicPricing['cost_brl'] ?? 0), 4);
        $dynamicFinalPricePerMinute = round((float)($dynamicPricing['final_price_brl'] ?? 0), 4);
        $planPricePerMinute = $customerId
            ? round((float)(WhatsAppBilling::planFloorPriceForUser($customerId, $tenancyId, 'voice') ?? 0), 4)
            : round((float)($summary->whatsapp_voice_price_per_minute ?? 0), 4);
        $finalPricePerMinute = $dynamicFinalPricePerMinute > 0 && $planPricePerMinute > 0
            ? max($dynamicFinalPricePerMinute, $planPricePerMinute)
            : max($dynamicFinalPricePerMinute, $planPricePerMinute, 0);
        $markupPercent = round((float)($dynamicPricing['margin_percent'] ?? 0), 4);
        $source = $planPricePerMinute > $dynamicFinalPricePerMinute && $dynamicFinalPricePerMinute > 0
            ? 'plan_floor'
            : 'whatsapp_meta_voice_dynamic';

        if ($finalPricePerMinute <= 0) {
            $finalPricePerMinute = self::pricePerMinute($summary);
            $basePricePerMinute = $finalPricePerMinute;
            $planPricePerMinute = $finalPricePerMinute;
            $markupPercent = 0.0;
            $source = 'plan_voice_rate';

            if ($customerId && $finalPricePerMinute <= 0) {
                $resellerRate = self::priceFromResellerVoiceRate($customerId, $tenancyId);
                if ($resellerRate !== null && $resellerRate > 0) {
                    $finalPricePerMinute = round($resellerRate, 4);
                    $basePricePerMinute = $finalPricePerMinute;
                    $markupPercent = 0.0;
                    $source = 'reseller_whatsapp_voice_rate';
                }
            }
        }

        return [
            'country_code' => WhatsAppDynamicPricing::countryCodeFromPhone($phone),
            'price_per_minute_brl' => round(max(0, $finalPricePerMinute), 4),
            'base_price_per_minute_brl' => round(max(0, $basePricePerMinute), 4),
            'final_price_per_minute_brl' => round(max(0, $finalPricePerMinute), 4),
            'plan_price_per_minute_brl' => round(max(0, $planPricePerMinute), 4),
            'markup_percent' => $markupPercent,
            'pricing_source' => $source,
            'price_per_minute_mode' => 'final_commercial_brl',
            'pricing_rule' => 'max(dynamic,plan)',
            'exchange_refresh' => $exchangeRefresh,
            'meta_rate_usd_per_minute' => round((float)($dynamicPricing['cost_usd'] ?? 0), 6),
            'cost_usd' => round((float)($dynamicPricing['cost_usd'] ?? 0), 6),
            'dynamic_price_per_minute_brl' => $dynamicFinalPricePerMinute,
            'dynamic_final_price_brl' => $dynamicFinalPricePerMinute,
            'final_commercial_price_per_minute_brl' => round(max(0, $finalPricePerMinute), 4),
            'dollar_rate' => round((float)($exchange['effective_rate'] ?? 0), 6),
            'exchange_rate' => round((float)($exchange['rate_brl'] ?? 0), 6),
            'effective_rate' => round((float)($exchange['effective_rate'] ?? 0), 6),
            'safety_margin_percent' => round((float)($exchange['safety_margin_percent'] ?? 0), 4),
            'exchange_updated_at' => $exchange['updated_at'] ?? null,
            'exchange_alert' => !empty($exchange['alert_flag']),
        ];
    }

    private static function dynamicVoicePricing(?int $customerId, string $phone): array
    {
        try {
            return WhatsAppDynamicPricing::calculatePrice(
                'voice',
                WhatsAppDynamicPricing::countryCodeFromPhone($phone),
                $customerId
            );
        } catch (\Throwable $e) {
            error_log('[whatsapp_voice_dynamic_pricing] ' . $e->getMessage());
            return [];
        }
    }

    private static function priceFromResellerVoiceRate(int $userId, string $tenancyId): ?float
    {
        $resellerId = self::resolveResellerIdForUser($userId, $tenancyId);
        if (!$resellerId) {
            return null;
        }

        try {
            return Rates::getActiveWhatsAppCategoryPrice($tenancyId, $resellerId, 'voice');
        } catch (\Throwable $e) {
            error_log('[whatsapp_voice_reseller_pricing] ' . $e->getMessage());
            return null;
        }
    }

    private static function buildHierarchyDebitPlan(
        int $userId,
        string $tenancyId,
        float $retailAmount,
        string $callId,
        string $lockToken
    ): array {
        $context = FinancialHierarchyResolver::resolveContext($userId, $tenancyId);
        $cdr = WhatsAppCallCdrStore::findByCallId($callId) ?: [];
        $billableMinutes = round((float)($cdr['billable_minutes'] ?? 0), 4);
        $phone = (string)($cdr['to_number'] ?? $cdr['from_number'] ?? '');
        $summary = PlanRuntimeService::getDisplaySummary($tenancyId);
        $resellerAmount = 0.0;
        $adminAmount = 0.0;

        if ($billableMinutes > 0 && $context->reseller_id && $context->reseller_id !== $userId) {
            $resellerPricing = self::resolvePricingContext((int)$context->reseller_id, $tenancyId, $summary, $phone, false);
            $resellerAmount = round($billableMinutes * (float)($resellerPricing['final_price_per_minute_brl'] ?? 0), 4);
        }

        if ($billableMinutes > 0 && $context->owner_admin_id > 0 && $context->owner_admin_id !== $userId) {
            $adminPricing = self::resolvePricingContext((int)$context->owner_admin_id, $tenancyId, $summary, $phone, false);
            $adminAmount = round($billableMinutes * (float)($adminPricing['final_price_per_minute_brl'] ?? 0), 4);
        }

        return FinancialHierarchyBillingService::buildDebitPlan([
            'actor_user_id' => $userId,
            'tenancy_id' => $tenancyId,
            'module' => 'whatsapp_voice',
            'event' => 'call_debited',
            'retail_amount' => $retailAmount,
            'reseller_amount' => $resellerAmount,
            'admin_amount' => $adminAmount,
            'provider_reference' => $callId,
            'related_type' => 'whatsapp_voice_call',
            'related_id' => $callId,
            'operation_key_base' => 'whatsapp_voice:' . $callId,
            'description_prefix' => 'TARIFACAO WHATSAPP VOZ',
            'metadata' => [
                'call_id' => $callId,
                'charge_lock_token' => $lockToken,
                'billable_minutes' => $billableMinutes,
            ],
        ]);
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
            error_log('[whatsapp_voice_reseller_lookup] ' . $e->getMessage());
            return null;
        }
    }

    private static function refreshExchangeRate(): array
    {
        try {
            return WhatsAppDynamicPricing::refreshUsdRateIfStale();
        } catch (\Throwable $e) {
            error_log('[whatsapp_voice_exchange_refresh] ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    private static function currentExchangeSnapshot(): array
    {
        try {
            $row = (new Database('currency_exchange_rates'))
                ->select(
                    'currency = :currency',
                    [':currency' => 'USD'],
                    'updated_at DESC',
                    1,
                    [
                        'rate_brl',
                        'safety_margin_percent',
                        'effective_rate',
                        'daily_change_percent',
                        'alert_flag',
                        'updated_at',
                    ]
                )
                ->fetch(\PDO::FETCH_ASSOC);

            return is_array($row) ? $row : [];
        } catch (\Throwable $e) {
            error_log('[whatsapp_voice_exchange_snapshot] ' . $e->getMessage());
            return [];
        }
    }
}
