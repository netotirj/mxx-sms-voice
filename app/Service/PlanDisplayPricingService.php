<?php

namespace App\Service;

class PlanDisplayPricingService
{
    private const DECIMAL_SCALE = 10000;

    /**
     * Regra oficial de exibicao no sistema:
     * valor final = max(valor do plano contratado, cotacao atual).
     */
    public static function getDisplayPlanPrice(mixed $contractedPrice, mixed $currentQuote): float
    {
        $contracted = self::normalizeDecimal($contractedPrice);
        $quote = self::normalizeDecimal($currentQuote);

        return self::fromScaleInt(max(
            self::toScaleInt($contracted),
            self::toScaleInt($quote)
        ));
    }

    public static function getEffectiveWhatsAppRatesForUser(int $userId, string $tenancyId, ?object $summary = null): array
    {
        $summary ??= PlanRuntimeService::getDisplaySummary($tenancyId);
        if (!$summary) {
            return [
                'marketing' => ['contracted' => 0.0, 'quote' => 0.0, 'display' => 0.0],
                'utility' => ['contracted' => 0.0, 'quote' => 0.0, 'display' => 0.0],
                'authentication' => ['contracted' => 0.0, 'quote' => 0.0, 'display' => 0.0],
                'voice' => ['contracted' => 0.0, 'quote' => 0.0, 'display' => 0.0],
            ];
        }

        $categories = [
            'marketing' => [
                'contracted' => self::normalizeDecimal($summary->value_whatsapp_marketing ?? 0),
                'quote' => self::normalizeDecimal(WhatsAppBilling::currentQuotePriceForUser($userId, $tenancyId, 'marketing')),
            ],
            'utility' => [
                'contracted' => self::normalizeDecimal($summary->value_whatsapp_utility ?? 0),
                'quote' => self::normalizeDecimal(WhatsAppBilling::currentQuotePriceForUser($userId, $tenancyId, 'utility')),
            ],
            'authentication' => [
                'contracted' => self::normalizeDecimal($summary->value_whatsapp_authentication ?? 0),
                'quote' => self::normalizeDecimal(WhatsAppBilling::currentQuotePriceForUser($userId, $tenancyId, 'authentication')),
            ],
            'voice' => [
                'contracted' => self::normalizeDecimal($summary->whatsapp_voice_price_per_minute ?? 0),
                'quote' => self::normalizeDecimal(WhatsAppVoiceBilling::currentQuotePerMinuteForUser($userId, $tenancyId)),
            ],
        ];

        foreach ($categories as $key => $row) {
            $categories[$key]['display'] = self::getDisplayPlanPrice($row['contracted'], $row['quote']);
        }

        return $categories;
    }

    private static function normalizeDecimal(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round(max(0, (float)$value), 4);
    }

    private static function toScaleInt(float $value): int
    {
        return (int)round($value * self::DECIMAL_SCALE);
    }

    private static function fromScaleInt(int $value): float
    {
        return round($value / self::DECIMAL_SCALE, 4);
    }
}
