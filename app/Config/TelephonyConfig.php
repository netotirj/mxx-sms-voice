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
        return self::telephonyRedisConfig();
    }

    public static function appRedisHost(): string
    {
        return (string) self::env('APP_REDIS_HOST', '127.0.0.1');
    }

    public static function appRedisPort(): int
    {
        return (int) self::env('APP_REDIS_PORT', 6379);
    }

    public static function appRedisPassword(): string
    {
        return (string) self::env('APP_REDIS_PASSWORD', '');
    }

    public static function appRedisDatabase(): int
    {
        return (int) self::env('APP_REDIS_DB', 0);
    }

    public static function appRedisTimeout(): float
    {
        return (float) self::env('APP_REDIS_TIMEOUT', 1.5);
    }

    public static function appRedisReadTimeout(): float
    {
        return (float) self::env('APP_REDIS_READ_TIMEOUT', 1.5);
    }

    public static function appRedisConfig(): array
    {
        return self::buildRedisConfig(
            self::appRedisHost(),
            self::appRedisPort(),
            self::appRedisPassword(),
            self::appRedisDatabase(),
            self::appRedisTimeout(),
            self::appRedisReadTimeout()
        );
    }

    public static function telephonyRedisHost(): string
    {
        return (string) self::env('TELEPHONY_REDIS_HOST', self::redisHost());
    }

    public static function telephonyRedisPort(): int
    {
        return (int) self::env('TELEPHONY_REDIS_PORT', self::redisPort());
    }

    public static function telephonyRedisPassword(): string
    {
        return (string) self::env('TELEPHONY_REDIS_PASSWORD', self::redisPassword());
    }

    public static function telephonyRedisDatabase(): int
    {
        return (int) self::env('TELEPHONY_REDIS_DB', 0);
    }

    public static function telephonyRedisTimeout(): float
    {
        return (float) self::env('TELEPHONY_REDIS_TIMEOUT', 2.5);
    }

    public static function telephonyRedisReadTimeout(): float
    {
        return (float) self::env('TELEPHONY_REDIS_READ_TIMEOUT', 2.5);
    }

    public static function telephonyRedisConfig(): array
    {
        return self::buildRedisConfig(
            self::telephonyRedisHost(),
            self::telephonyRedisPort(),
            self::telephonyRedisPassword(),
            self::telephonyRedisDatabase(),
            self::telephonyRedisTimeout(),
            self::telephonyRedisReadTimeout()
        );
    }

    private static function buildRedisConfig(
        string $host,
        int $port,
        string $password,
        int $database,
        float $timeout,
        float $readTimeout
    ): array {
        $config = [
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'timeout' => $timeout,
            'read_write_timeout' => $readTimeout,
        ];

        if ($password !== '') {
            $config['password'] = $password;
        }

        return $config;
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
