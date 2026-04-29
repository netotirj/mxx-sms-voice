<?php

namespace App\Service;

class WhatsAppCostPolicy
{
    public const CATEGORY_MARKETING = 'MARKETING';
    public const CATEGORY_UTILITY = 'UTILITY';
    public const CATEGORY_AUTHENTICATION = 'AUTHENTICATION';

    private const BRAZIL_RATES_USD = [
        self::CATEGORY_MARKETING => 0.0625,
        self::CATEGORY_UTILITY => 0.0080,
        self::CATEGORY_AUTHENTICATION => 0.0068,
    ];

    public static function normalizeCategory(?string $category): string
    {
        $category = strtoupper(trim((string)$category));

        return in_array($category, [
            self::CATEGORY_MARKETING,
            self::CATEGORY_UTILITY,
            self::CATEGORY_AUTHENTICATION,
        ], true) ? $category : self::CATEGORY_MARKETING;
    }

    public static function isServiceWindowOpen(?string $lastInboundAt): bool
    {
        if (!$lastInboundAt) {
            return false;
        }

        $timestamp = strtotime($lastInboundAt);
        if (!$timestamp) {
            return false;
        }

        return $timestamp >= (time() - 24 * 60 * 60);
    }

    public static function estimateBrazilCostUsd(string $messageType, ?string $category, bool $serviceWindowOpen): float
    {
        if ($messageType === 'text') {
            return $serviceWindowOpen ? 0.0 : self::BRAZIL_RATES_USD[self::CATEGORY_MARKETING];
        }

        $category = self::normalizeCategory($category);
        if ($category === self::CATEGORY_UTILITY && $serviceWindowOpen) {
            return 0.0;
        }

        return self::BRAZIL_RATES_USD[$category] ?? self::BRAZIL_RATES_USD[self::CATEGORY_MARKETING];
    }

    public static function buildCostMeta(string $messageType, ?string $category, bool $serviceWindowOpen): array
    {
        $category = $messageType === 'template' ? self::normalizeCategory($category) : null;

        return [
            'pricing_estimate' => [
                'country' => 'BR',
                'currency' => 'USD',
                'message_type' => $messageType,
                'template_category' => $category,
                'service_window_open' => $serviceWindowOpen,
                'billable_estimate' => self::estimateBrazilCostUsd($messageType, $category, $serviceWindowOpen) > 0,
                'estimated_cost_usd' => self::estimateBrazilCostUsd($messageType, $category, $serviceWindowOpen),
                'note' => 'Estimativa local. O custo final depende de entrega, pais do destinatario e rate card vigente da Meta.',
            ],
        ];
    }

    public static function looksLikeOptOut(string $body): bool
    {
        $body = strtoupper(trim($body));
        $body = preg_replace('/\s+/', ' ', $body) ?: $body;

        return in_array($body, ['SAIR', 'PARAR', 'STOP', 'CANCELAR', 'DESCADASTRAR'], true);
    }
}
