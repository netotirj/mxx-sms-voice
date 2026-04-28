<?php

namespace App\Config;

class WhatsAppConfig
{
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

        if ($value !== '' && !in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return $value;
        }

        return true;
    }
}
