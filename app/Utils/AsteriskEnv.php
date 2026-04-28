<?php

namespace App\Utils;

class AsteriskEnv
{
    private const DEFAULT_WS_HOST = 'mxx-sip.maxxsolutions.com.br';

    private static function env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    private static function hostFromUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : null;
    }

    private static function isIpAddress(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    private static function isLocalHost(string $host): bool
    {
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }

    public static function host(): string
    {
        return self::env('SERVERASTERISK', '13.58.78.167');
    }

    public static function apiBaseUrl(): string
    {
        return rtrim(self::env('ASTERISK_API_BASE_URL', 'http://' . self::host()), '/');
    }

    public static function ariHost(): string
    {
        return self::env('ARI_HOST', self::host());
    }

    public static function ariPort(): int
    {
        return (int)(self::env('ARI_PORT', '8088'));
    }

    public static function ariUser(): string
    {
        return self::env('ARI_USER', 'maxx');
    }

    public static function ariPass(): string
    {
        return self::env('ARI_PASS', 'AriMxXPwd081092!');
    }

    public static function ariAuth(): array
    {
        return [self::ariUser(), self::ariPass()];
    }

    public static function stasisApp(): string
    {
        return self::env('STASIS_APP', 'app-asterisk');
    }

    public static function wsHost(): string
    {
        $configured = trim((string) self::env('ASTERISK_WS_HOST', ''));
        if ($configured !== '' && !self::isIpAddress($configured)) {
            return $configured;
        }

        $publicHost = self::hostFromUrl(self::env('ASTERISK_PUBLIC_BASE_URL'));
        if ($publicHost !== null && !self::isIpAddress($publicHost) && !self::isLocalHost($publicHost)) {
            return $publicHost;
        }

        $appHost = self::hostFromUrl(self::env('URL'));
        if ($appHost !== null && !self::isIpAddress($appHost) && !self::isLocalHost($appHost)) {
            return $appHost;
        }

        $asteriskHost = self::host();
        if (!self::isIpAddress($asteriskHost) && !self::isLocalHost($asteriskHost)) {
            return $asteriskHost;
        }

        return self::DEFAULT_WS_HOST;
    }

    public static function wsPort(): int
    {
        return (int)(self::env('ASTERISK_WS_PORT', '8089'));
    }

    public static function ariBaseUrl(): string
    {
        return self::apiBaseUrl() . ':' . self::ariPort() . '/ari/';
    }

    public static function ftpHost(): string
    {
        return self::env('ASTERISK_FTP_HOST', self::host());
    }

    public static function ftpUser(): string
    {
        return self::env('ASTERISK_FTP_USER', 'astmin');
    }

    public static function ftpPass(): string
    {
        return self::env('ASTERISK_FTP_PASS', '123321');
    }

    public static function recordingUrl(): string
    {
        return self::apiBaseUrl() . '/recording.php';
    }

    public static function audioUrl(): string
    {
        return self::apiBaseUrl() . '/audio.php';
    }
}
