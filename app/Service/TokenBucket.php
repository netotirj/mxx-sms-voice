<?php
namespace App\Service;

class TokenBucket
{
    private $redis;
    private const string LUA_SCRIPT = <<<'LUA'
-- KEYS[1] = key_prefix (e.g. campaign:job:xyz:bucket)
-- ARGV[1] = now (seconds, float as string)
-- ARGV[2] = rate (tokens per second)
-- ARGV[3] = capacity (max tokens bucket can hold)
-- ARGV[4] = tokens_to_consume (usually 1)

local key = KEYS[1]
local now = tonumber(ARGV[1])
local rate = tonumber(ARGV[2])
local capacity = tonumber(ARGV[3])
local consume = tonumber(ARGV[4])

-- stored fields
local tok = tonumber(redis.call("HGET", key, "tokens") or "0")
local last = tonumber(redis.call("HGET", key, "last") or "0")

-- refill
if last == 0 then
  last = now
end

local elapsed = math.max(0, now - last)
local add = elapsed * rate
tok = math.min(capacity, tok + add)
last = now

if tok >= consume then
  tok = tok - consume
  redis.call("HSET", key, "tokens", tostring(tok))
  redis.call("HSET", key, "last", tostring(last))
  -- optional: set TTL so stale buckets expire
  redis.call("EXPIRE", key, 3600)
  return {1, tostring(tok)}
else
  redis.call("HSET", key, "tokens", tostring(tok))
  redis.call("HSET", key, "last", tostring(last))
  redis.call("EXPIRE", key, 3600)
  return {0, tostring(tok)}
end
LUA;

    public function __construct($redisClient)
    {
        $this->redis = $redisClient;
    }

    /**
     * Tenta consumir `tokens` tokens do bucket da campanha.
     * @param string $campaignKeyPrefix e.g. "campaign:job:xxx:bucket"
     * @param float $rate tokens per second
     * @param float $capacity max tokens
     * @param int $tokens number tokens to consume (1)
     * @return array [allowed(bool), tokens_left(float as string)]
     */
    public function tryConsume(string $campaignKeyPrefix, float $rate, float $capacity, int $tokens = 1): array
    {
        $now = microtime(true);
        // Executa script LUA atômico
        $res = $this->redis->eval(self::LUA_SCRIPT, 1, $campaignKeyPrefix, (string)$now, (string)$rate, (string)$capacity, (string)$tokens);
        // res is array: [0|1, tokens_left_as_string]
        if (is_array($res) && count($res) >= 2) {
            $allowed = intval($res[0]) === 1;
            $left = $res[1];
            return [$allowed, $left];
        }
        return [false, "0"];
    }

    /**
     * Convenience wrapper: block up to $timeoutSeconds trying to get token
     * returns true if got token, false if timeout
     */
    public function waitAndConsume(string $campaignKeyPrefix, float $rate, float $capacity, float $timeoutSeconds = 2.0, int $tokens = 1): bool
    {
        $start = microtime(true);
        $sleepUs = 50_000; // 50ms between attempts (adjustable)
        while ((microtime(true) - $start) < $timeoutSeconds) {
            [$allowed, $left] = $this->tryConsume($campaignKeyPrefix, $rate, $capacity, $tokens);
            if ($allowed) {
                return true;
            }
            // short backoff
            usleep($sleepUs);
        }
        return false;
    }
}
