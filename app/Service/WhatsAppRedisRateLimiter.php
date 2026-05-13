<?php

namespace App\Service;

use App\RedisConn;

class WhatsAppRedisRateLimiter
{
    public static function reserve(int $accountId, int $dailyLimit, int $minuteLimit, int $secondLimit): array
    {
        if ($dailyLimit <= 0 || $minuteLimit <= 0 || $secondLimit <= 0) {
            return [
                'allowed' => false,
                'delay_seconds' => 3600,
                'reason' => 'Envio pausado temporariamente para proteger a qualidade do número.',
            ];
        }

        try {
            $redis = RedisConn::app();
            $now = time();
            $dailyTtl = max(60, strtotime('tomorrow 00:05:00') - $now);
            $minuteTtl = 60;
            $secondTtl = 2;
            $minuteSlot = date('YmdHi', $now);
            $secondSlot = date('YmdHis', $now);
            $daySlot = date('Ymd', $now);

            $keys = [
                "wa:rate:day:{$accountId}:{$daySlot}",
                "wa:rate:min:{$accountId}:{$minuteSlot}",
                "wa:rate:sec:{$accountId}:{$secondSlot}",
            ];

            $script = <<<'LUA'
local day = tonumber(redis.call('GET', KEYS[1]) or '0')
local minute = tonumber(redis.call('GET', KEYS[2]) or '0')
local second = tonumber(redis.call('GET', KEYS[3]) or '0')
local dayLimit = tonumber(ARGV[1])
local minuteLimit = tonumber(ARGV[2])
local secondLimit = tonumber(ARGV[3])

if day >= dayLimit then
  return {0, tonumber(ARGV[4]), 'Limite diário do número atingido.'}
end
if minute >= minuteLimit then
  return {0, 30, 'Controle de velocidade por minuto.'}
end
if second >= secondLimit then
  return {0, 2, 'Controle de velocidade por segundo.'}
end

redis.call('INCR', KEYS[1])
redis.call('EXPIRE', KEYS[1], tonumber(ARGV[4]))
redis.call('INCR', KEYS[2])
redis.call('EXPIRE', KEYS[2], tonumber(ARGV[5]))
redis.call('INCR', KEYS[3])
redis.call('EXPIRE', KEYS[3], tonumber(ARGV[6]))
return {1, 0, ''}
LUA;

            $result = $redis->eval(...array_merge(
                [$script, 3],
                $keys,
                [$dailyLimit, $minuteLimit, $secondLimit, $dailyTtl, $minuteTtl, $secondTtl]
            ));

            return [
                'allowed' => (int)($result[0] ?? 0) === 1,
                'delay_seconds' => max(1, (int)($result[1] ?? 1)),
                'reason' => (string)($result[2] ?? 'Limite de envio atingido.'),
            ];
        } catch (\Throwable $e) {
            return [
                'allowed' => false,
                'delay_seconds' => 60,
                'reason' => 'Controle de velocidade indisponível. Envio pausado por segurança.',
            ];
        }
    }
}
