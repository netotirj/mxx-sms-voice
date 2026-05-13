<?php

namespace App;

use App\Config\TelephonyConfig;
use Predis\Client as RedisClient;
use Throwable;
use Exception;

class RedisConn
{
    private static ?RedisClient $appClient = null;
    private static ?RedisClient $telephonyClient = null;

    /**
     * @throws Exception
     */
    public static function get(): RedisClient
    {
        return self::telephony();
    }

    /**
     * @throws Exception
     */
    public static function app(): RedisClient
    {
        try {
            return self::$appClient ??= new RedisClient(TelephonyConfig::appRedisConfig());
        } catch (Throwable $e) {
            throw new Exception("Redis connection failed: " . $e->getMessage());
        }
    }

    /**
     * @throws Exception
     */
    public static function telephony(): RedisClient
    {
        try {
            return self::$telephonyClient ??= new RedisClient(TelephonyConfig::telephonyRedisConfig());
        } catch (Throwable $e) {
            throw new Exception("Redis connection failed: " . $e->getMessage());
        }
    }
}
