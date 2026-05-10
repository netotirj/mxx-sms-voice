<?php

namespace App\Service;

use App\RedisConn;

class DashboardService
{
    public const CARDS_RETRY_MS = 15000;
    public const CHARTS_RETRY_MS = 30000;
    private const SMS_BALANCE_TTL = 45;
    private const FILE_CACHE_DIR = __DIR__ . '/../../runtime/cache/dashboard';

    public static function sseRetryLine(int $milliseconds): void
    {
        echo "retry: {$milliseconds}\n";
    }

    public static function cachedSmsBalance(string $tenancyId, callable $fetcher): ?float
    {
        $key = 'sms_balance:' . ($tenancyId !== '' ? $tenancyId : 'global');
        $lastValidKey = $key . ':last_valid';

        $cached = self::getJson($key);
        if (is_array($cached) && array_key_exists('balance', $cached)) {
            self::log('sms_balance', 'hit', microtime(true), ['key' => $key]);
            return (float)$cached['balance'];
        }

        $started = microtime(true);
        $value = $fetcher();
        $numeric = is_numeric($value) ? (float)$value : null;

        if ($numeric !== null && $numeric > 0) {
            self::setJson($key, ['balance' => $numeric, 'cached_at' => time()], self::SMS_BALANCE_TTL);
            self::setJson($lastValidKey, ['balance' => $numeric, 'cached_at' => time()], 3600);
            self::log('sms_balance', 'miss_store', $started, ['key' => $key]);
            return $numeric;
        }

        $lastValid = self::getJson($lastValidKey);
        if (is_array($lastValid) && isset($lastValid['balance']) && (float)$lastValid['balance'] > 0) {
            self::setJson($key, $lastValid, self::SMS_BALANCE_TTL);
            self::log('sms_balance', 'fallback_last_valid', $started, ['key' => $key]);
            return (float)$lastValid['balance'];
        }

        self::log('sms_balance', 'miss_invalid', $started, ['key' => $key]);
        return $numeric;
    }

    public static function remember(string $key, int $ttl, callable $producer): array
    {
        $cached = self::getJson($key);
        if (is_array($cached)) {
            self::log($key, 'hit', microtime(true));
            return $cached;
        }

        $started = microtime(true);
        $payload = $producer();
        if (!is_array($payload)) {
            $payload = [];
        }

        self::setJson($key, $payload, $ttl);
        self::log($key, 'miss_store', $started);
        return $payload;
    }

    public static function log(string $endpoint, string $cacheStatus, float $startedAt, array $context = []): void
    {
        $durationMs = round((microtime(true) - $startedAt) * 1000, 2);
        error_log('[dashboard] endpoint=' . $endpoint
            . ' cache=' . $cacheStatus
            . ' duration_ms=' . $durationMs
            . ($context ? ' context=' . json_encode($context, JSON_UNESCAPED_UNICODE) : ''));
    }

    private static function getJson(string $key): ?array
    {
        try {
            $raw = RedisConn::get()->get($key);
            if (!$raw) {
                return self::fileCacheGet($key);
            }

            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) {
                self::fileCachePut($key, $decoded, self::fallbackTtlForKey($key));
                return $decoded;
            }

            return self::fileCacheGet($key);
        } catch (\Throwable $e) {
            error_log('[dashboard] redis_get_failed key=' . $key . ' error=' . $e->getMessage());
            return self::fileCacheGet($key);
        }
    }

    private static function setJson(string $key, array $payload, int $ttl): void
    {
        try {
            RedisConn::get()->setex($key, $ttl, json_encode($payload, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            error_log('[dashboard] redis_set_failed key=' . $key . ' error=' . $e->getMessage());
        }

        self::fileCachePut($key, $payload, $ttl);
    }

    private static function fileCacheGet(string $key): ?array
    {
        $path = self::fileCachePath($key);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return null;
        }

        $expiresAt = (int)($payload['_expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < time()) {
            @unlink($path);
            return null;
        }

        return is_array($payload['data'] ?? null) ? $payload['data'] : null;
    }

    private static function fileCachePut(string $key, array $payload, int $ttl): void
    {
        $dir = dirname(self::fileCachePath($key));
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        @file_put_contents(
            self::fileCachePath($key),
            json_encode([
                '_expires_at' => time() + max(1, $ttl),
                'data' => $payload,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private static function fileCachePath(string $key): string
    {
        return self::FILE_CACHE_DIR . '/' . md5($key) . '.json';
    }

    private static function fallbackTtlForKey(string $key): int
    {
        return str_contains($key, 'sms_balance:') ? self::SMS_BALANCE_TTL : 300;
    }
}
