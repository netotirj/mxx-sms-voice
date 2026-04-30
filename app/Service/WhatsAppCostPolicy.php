<?php

namespace App\Service;

class WhatsAppCostPolicy
{
    public const CATEGORY_MARKETING = 'MARKETING';
    public const CATEGORY_UTILITY = 'UTILITY';
    public const CATEGORY_SERVICE = 'SERVICE';
    public const CATEGORY_AUTHENTICATION = 'AUTHENTICATION';

    public const DEFAULT_PRICES_BRL = [
        self::CATEGORY_MARKETING => 0.35,
        self::CATEGORY_UTILITY => 0.04,
        self::CATEGORY_SERVICE => 0.00,
    ];

    public static function normalizeCategory(?string $category): string
    {
        $category = strtoupper(trim((string)$category));
        if ($category === self::CATEGORY_AUTHENTICATION) {
            return self::CATEGORY_UTILITY;
        }

        return in_array($category, [
            self::CATEGORY_MARKETING,
            self::CATEGORY_UTILITY,
            self::CATEGORY_SERVICE,
        ], true) ? $category : self::CATEGORY_MARKETING;
    }

    public static function billingCategory(string $messageType, ?string $category, bool $serviceWindowOpen): string
    {
        if ($serviceWindowOpen && $messageType !== 'template') {
            return self::CATEGORY_SERVICE;
        }

        return self::normalizeCategory($category);
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

    public static function defaultPriceBrl(string $category): float
    {
        $category = self::normalizeCategory($category);
        return self::DEFAULT_PRICES_BRL[$category] ?? self::DEFAULT_PRICES_BRL[self::CATEGORY_MARKETING];
    }

    public static function looksLikeOptOut(string $body): bool
    {
        $body = strtoupper(trim($body));
        $body = preg_replace('/\s+/', ' ', $body) ?: $body;

        return in_array($body, ['SAIR', 'PARAR', 'STOP', 'CANCELAR', 'DESCADASTRAR'], true);
    }
}
