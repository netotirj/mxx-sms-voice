<?php

namespace App\Config;

use App\Controller\Pages\AssasApi;

class AsaasConfig
{
    public static function environment(): string
    {
        return strtolower(trim((string)(getenv('ASAAS_ENV') ?: 'production')));
    }

    public static function isSandbox(): bool
    {
        return in_array(self::environment(), ['sandbox', 'test', 'testing', 'homolog', 'homologation'], true);
    }

    public static function baseUrl(): string
    {
        return trim((string)(self::isSandbox() ? getenv('ASASURLSANDBOX') : getenv('ASASURL')));
    }

    public static function apiKey(): string
    {
        return trim((string)(self::isSandbox() ? getenv('ASASSANDBOX') : getenv('ASASKEY')));
    }

    public static function pixKey(): string
    {
        return trim((string)(self::isSandbox() ? getenv('PIXKEYSANDBOX') : getenv('PIXKEY')));
    }

    public static function webhookSecret(): string
    {
        return trim((string)getenv('ASAAS_WEBHOOK_SECRET'));
    }

    public static function webhookUrl(): string
    {
        $baseUrl = defined('VIEW_URL')
            ? (string)VIEW_URL
            : ((string)(getenv('URL') ?: 'https://maxxsolutions.com.br/painel'));

        return rtrim($baseUrl, '/') . '/refills/webhooks-asaas';
    }

    public static function isConfigured(): bool
    {
        return self::baseUrl() !== '' && self::apiKey() !== '' && self::pixKey() !== '';
    }

    public static function createClient(): AssasApi
    {
        return new AssasApi(self::baseUrl(), self::apiKey());
    }

    public static function publicContext(): array
    {
        return [
            'environment' => self::environment(),
            'sandbox' => self::isSandbox(),
            'baseUrl' => self::baseUrl(),
            'webhookUrl' => self::webhookUrl(),
        ];
    }
}
