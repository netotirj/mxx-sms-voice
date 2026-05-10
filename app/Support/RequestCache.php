<?php

namespace App\Support;

class RequestCache
{
    private static array $store = [];

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$store);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$store[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): mixed
    {
        self::$store[$key] = $value;
        return $value;
    }

    public static function forget(string $prefix): void
    {
        foreach (array_keys(self::$store) as $key) {
            if ($key === $prefix || str_starts_with($key, $prefix . '.')) {
                unset(self::$store[$key]);
            }
        }
    }

    public static function remember(string $key, callable $resolver): mixed
    {
        if (self::has($key)) {
            return self::$store[$key];
        }

        return self::put($key, $resolver());
    }

    public static function reset(): void
    {
        self::$store = [];
    }
}
