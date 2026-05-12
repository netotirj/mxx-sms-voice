<?php

namespace App\Config;

class WhatsAppConfig
{
    private const LOCAL_DEV_HOSTS = [
        'localhost',
        '127.0.0.1',
        '::1',
    ];

    public static function graphVersion(): string
    {
        return (string) TelephonyConfig::env('META_GRAPH_VERSION', 'v25.0');
    }

    public static function graphBaseUrl(): string
    {
        return rtrim((string) TelephonyConfig::env('META_GRAPH_BASE_URL', 'https://graph.facebook.com'), '/');
    }

    public static function webhookVerifyToken(): string
    {
        return (string) TelephonyConfig::env('WHATSAPP_WEBHOOK_VERIFY_TOKEN', 'maxx-whatsapp-webhook');
    }

    public static function webhookAppSecret(): string
    {
        return trim((string) TelephonyConfig::env('WHATSAPP_WEBHOOK_APP_SECRET', ''));
    }

    public static function metaAppId(): string
    {
        return trim((string) TelephonyConfig::env('WHATSAPP_META_APP_ID', ''));
    }

    public static function metaAppSecret(): string
    {
        return trim((string) TelephonyConfig::env('WHATSAPP_META_APP_SECRET', ''));
    }

    public static function embeddedSignupConfigId(): string
    {
        return trim((string) TelephonyConfig::env('WHATSAPP_META_EMBEDDED_SIGNUP_CONFIG_ID', ''));
    }

    public static function embeddedSignupRedirectUri(): string
    {
        $configured = trim((string) TelephonyConfig::env('WHATSAPP_META_EMBEDDED_SIGNUP_REDIRECT_URI', ''));
        if ($configured !== '') {
            return $configured;
        }

        $baseUrl = rtrim((string) TelephonyConfig::env('URL', ''), '/');
        return $baseUrl !== '' ? $baseUrl . '/campaign/whatsapp' : '';
    }

    public static function embeddedSignupEnabled(): bool
    {
        return self::metaAppId() !== '' && self::embeddedSignupConfigId() !== '';
    }

    public static function embeddedSignupHostedUrl(): string
    {
        if (!self::embeddedSignupEnabled()) {
            return '';
        }

        $extras = rawurlencode(json_encode([
            'featureType' => 'whatsapp_business_app_onboarding',
            'sessionInfoVersion' => '3',
            'version' => 'v4',
        ], JSON_UNESCAPED_SLASHES));

        return 'https://business.facebook.com/messaging/whatsapp/onboard/?app_id='
            . rawurlencode(self::metaAppId())
            . '&config_id=' . rawurlencode(self::embeddedSignupConfigId())
            . '&extras=' . $extras;
    }

    public static function platformWabaId(): string
    {
        return trim((string) TelephonyConfig::env('WHATSAPP_PLATFORM_WABA_ID', ''));
    }

    public static function phoneNumberId(): string
    {
        return trim((string) TelephonyConfig::env('WHATSAPP_PHONE_NUMBER_ID', ''));
    }

    public static function platformAccessToken(): string
    {
        return trim((string) TelephonyConfig::env('WHATSAPP_PLATFORM_ACCESS_TOKEN', ''));
    }

    public static function defaultTwoStepPin(): string
    {
        return trim((string) TelephonyConfig::env('WHATSAPP_DEFAULT_2FA_PIN', ''));
    }

    public static function callingBridgeEnabled(): bool
    {
        $value = strtolower((string) TelephonyConfig::env('WHATSAPP_CALLING_BRIDGE_ENABLED', 'true'));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function callingLogFile(): string
    {
        return trim((string) TelephonyConfig::env(
            'WHATSAPP_CALLING_LOG_FILE',
            'C:/wamp64/logs/meta_whatsapp_calling.log'
        ));
    }

    public static function fakeSend(): bool
    {
        $value = strtolower((string) TelephonyConfig::env('WHATSAPP_FAKE_SEND', 'false'));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function sslVerify(): bool|string
    {
        $value = trim((string) TelephonyConfig::env('WHATSAPP_SSL_VERIFY', 'true'));
        $normalized = strtolower($value);

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        if (self::shouldBypassSslForLocalDev()) {
            return false;
        }

        if ($value !== '' && !in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return $value;
        }

        return true;
    }

    private static function shouldBypassSslForLocalDev(): bool
    {
        $appEnv = strtolower(trim((string) TelephonyConfig::env('APP_ENV', '')));
        if (in_array($appEnv, ['local', 'development', 'dev'], true)) {
            return true;
        }

        $url = trim((string) TelephonyConfig::env('URL', ''));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== '' && in_array($host, self::LOCAL_DEV_HOSTS, true)) {
            return true;
        }

        return str_starts_with($host, 'dev.');
    }
}
