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
        self::CATEGORY_AUTHENTICATION => 0.04,
        self::CATEGORY_SERVICE => 0.00,
    ];

    public static function normalizeCategory(?string $category): string
    {
        $category = strtoupper(trim((string)$category));
        return in_array($category, [
            self::CATEGORY_MARKETING,
            self::CATEGORY_UTILITY,
            self::CATEGORY_AUTHENTICATION,
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

    public static function isFreeByMetaPolicy(string $messageType, ?string $category, bool $serviceWindowOpen): bool
    {
        if (!$serviceWindowOpen) {
            return false;
        }

        if ($messageType !== 'template') {
            return true;
        }

        return self::normalizeCategory($category) === self::CATEGORY_UTILITY;
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
        $body = mb_strtolower(trim($body), 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $body);
        if (is_string($ascii) && $ascii !== '') {
            $body = $ascii;
        }
        $body = preg_replace('/\s+/', ' ', $body) ?: $body;
        $body = trim(preg_replace('/[^a-z0-9 ]+/', ' ', $body) ?: $body);

        if (in_array($body, ['sair', 'parar', 'stop', 'cancelar', 'descadastrar', 'remover'], true)) {
            return true;
        }

        return (bool)preg_match('/\b(nao quero|nao receber|nao me envie|remover|remova|cancelar|pare|parar|sair|stop|descadastrar|descadastro)\b/i', $body);
    }
}
