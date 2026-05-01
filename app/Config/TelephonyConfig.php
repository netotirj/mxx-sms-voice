<?php

namespace App\Config;

use WilliamCosta\DotEnv\Environment;

class TelephonyConfig
{
    private static bool $envLoaded = false;

    private static function ensureEnvLoaded(): void
    {
        if (self::$envLoaded) {
            return;
        }

        self::$envLoaded = true;

        $root = dirname(__DIR__, 2);
        if (is_file($root . DIRECTORY_SEPARATOR . '.env')) {
            Environment::load($root);
        }
    }

    public static function requireEnv(string $key, ?string $fallbackKey = null): string
    {
        self::ensureEnvLoaded();

        $value = self::env($key);
        if ($value !== null && $value !== '') {
            return (string) $value;
        }

        if ($fallbackKey) {
            $fallbackValue = self::env($fallbackKey);
            if ($fallbackValue !== null && $fallbackValue !== '') {
                return (string) $fallbackValue;
            }
        }

        $suffix = $fallbackKey ? " ou {$fallbackKey}" : '';
        throw new \RuntimeException("Variável de ambiente obrigatória ausente: {$key}{$suffix}");
    }

    public static function env(string $key, mixed $default = null): mixed
    {
        self::ensureEnvLoaded();

        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }

    public static function ariHost(): string
    {
        $ariHost = self::env('ARI_HOST');
        if (!empty($ariHost)) {
            return (string) $ariHost;
        }

        $serverAsterisk = self::env('SERVERASTERISK');
        if (!empty($serverAsterisk)) {
            return (string) $serverAsterisk;
        }

        $apiBase = self::env('ASTERISK_API_BASE_URL');
        if (!empty($apiBase)) {
            $parsedHost = parse_url((string) $apiBase, PHP_URL_HOST);
            if (!empty($parsedHost)) {
                return (string) $parsedHost;
            }
        }

        throw new \RuntimeException('Variável de ambiente obrigatória ausente: ARI_HOST ou SERVERASTERISK');
    }

    public static function ariPort(): int
    {
        return (int) self::env('ARI_PORT', 8088);
    }

    public static function ariUser(): string
    {
        return (string) self::env('ARI_USER', 'maxx');
    }

    public static function ariPass(): string
    {
        return (string) self::env('ARI_PASS', 'mxx123');
    }

    public static function ariAuth(): array
    {
        return [self::ariUser(), self::ariPass()];
    }

    public static function stasisApp(): string
    {
        return (string) self::env('STASIS_APP', 'app-asterisk');
    }

    public static function ariBaseUrl(): string
    {
        return 'http://' . self::ariHost() . ':' . self::ariPort() . '/ari/';
    }

    public static function asteriskPublicBaseUrl(): string
    {
        $defaultBase = 'https://' . self::env('ASTERISK_PUBLIC_DOMAIN', self::ariHost());
        return rtrim((string) self::env('ASTERISK_PUBLIC_BASE_URL', $defaultBase), '/');
    }

    public static function asteriskApiBaseUrl(): string
    {
        $defaultBase = 'http://' . self::ariHost();
        return rtrim((string) self::env('ASTERISK_API_BASE_URL', $defaultBase), '/');
    }

    public static function asteriskIndexUrl(): string
    {
        return self::asteriskApiBaseUrl() . '/index.php';
    }

    public static function audioScriptUrl(): string
    {
        return self::asteriskPublicBaseUrl() . '/audio.php';
    }

    public static function recordingScriptUrl(): string
    {
        return self::asteriskPublicBaseUrl() . '/recording.php';
    }

    public static function redisHost(): string
    {
        return self::requireEnv('REDIS_HOST', 'ARI_HOST');
    }

    public static function redisPort(): int
    {
        return (int) self::env('REDIS_PORT', 6379);
    }

    public static function redisPassword(): string
    {
        return (string) self::env('REDIS_PASSWORD', 'mxx123');
    }

    public static function redisConfig(): array
    {
        return [
            'scheme' => 'tcp',
            'host' => self::redisHost(),
            'port' => self::redisPort(),
            'password' => self::redisPassword(),
        ];
    }

    public static function ftpHost(): string
    {
        return (string) self::env('ASTERISK_FTP_HOST', self::ariHost());
    }

    public static function ftpUser(): string
    {
        return (string) self::env('ASTERISK_FTP_USER', 'astmin');
    }

    public static function ftpPass(): string
    {
        return (string) self::env('ASTERISK_FTP_PASS', '123321');
    }
}
