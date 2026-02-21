<?php

namespace App;

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
            return new RedisClient([
                'scheme' => 'tcp',
                'host'   => '192.168.1.8',
                'password' => 'mxx123',
                'port'   => 6379,
            ]);

        } catch (Throwable $e) {
            throw new Exception("Redis connection failed: " . $e->getMessage());
        }
    }
}
