<?php

namespace App;

use App\Config\TelephonyConfig;
use Predis\Client as RedisClient;
use Throwable;
use Exception;

class RedisConn
{
    /**
     * @throws Exception
     */
    public static function get(): RedisClient
    {
        try {
            return new RedisClient(TelephonyConfig::redisConfig());

        } catch (Throwable $e) {
            throw new Exception("Redis connection failed: " . $e->getMessage());
        }
    }
}
