<?php

namespace App\Service;

use App\Model\Entity\AgentsPortal;
use App\Model\Entity\PauseConfig;
use App\Model\Entity\PauseLog;
use App\RedisConn;
use Exception;
use GuzzleHttp\Client;
use Throwable;

class AgentManager
{
    private $redis;
    private Client $http;
    private string $agentsKey = 'discador:agentes';
    private string $pubChannel = 'discador:events';
    private ?string $ariHost = null;
    private ?array $ariAuth = null;
    private string $ariBaseUri = '';
    private int $httpTimeout = 1;

    private AsteriskErrorMonitor $monitor;


    /**
     * @throws Exception
     */
    public function __construct(array $opts = [])
    {
        $this->redis = RedisConn::get();

        $this->monitor = new AsteriskErrorMonitor($this->redis);

        $this->ariHost = $opts['ari_host'] ?? null;
        $this->ariAuth = $opts['ari_auth'] ?? null;
        $this->httpTimeout = isset($opts['http_timeout']) ? (int)$opts['http_timeout'] : 1;

        if (!empty($this->ariHost)) {
            $this->ariBaseUri = "http://{$this->ariHost}:8088/";
            $this->http = new Client([
                'base_uri' => $this->ariBaseUri,
                'timeout' => $this->httpTimeout,
                'http_errors' => false,
            ]);
        } else {
            $this->http = new Client(['timeout' => $this->httpTimeout, 'http_errors' => false]);
        }
    }

    public function getRedis()
    {
        return $this->redis;
    }

    /* =====================
     * Utilities
     * ===================== */
    private function normalizeRamal($r): string
    {
        return preg_replace('/\D/', '', (string)$r);
    }

    private function isValidRamal(string $r): bool
    {
        return preg_match('/^\d{8}$/', $r) === 1;
    }

    private function publish(string $event, array $payload = []): void
    {
        try {
            $this->redis->publish($this->pubChannel, json_encode([
                'event' => $event,
                'data' => $payload,
                'ts' => time()
            ]));
        } catch (Throwable $e) {
        }
    }

    private function resolveBusinessState(string $ramal, array $current, bool $finalOnline): array
    {
        $statusNoRedis = strtoupper((string)($current['status'] ?? 'OFFLINE'));
        $statusName = $current['status_name'] ?? null;
        $color = $current['color'] ?? 'slate';

        $tenancyId = (string)($current['tenancy_id'] ?? '');
        $userId = (int)($current['user_id'] ?? 0);

        if ($tenancyId !== '') {
            $agent = AgentsPortal::getAgentByExtension($ramal, $tenancyId);
            if ($agent instanceof AgentsPortal && (($agent->status ?? 'n') === 'n')) {
                return [
                    'status' => 'OFFLINE',
                    'status_name' => 'DESATIVADO',
                    'color' => 'slate'
                ];
            }
        }

        if ($tenancyId !== '' && $userId > 0) {
            $activeLogs = PauseLog::getLogsAtivos($tenancyId, $userId) ?: [];
            if (!empty($activeLogs)) {
                $activeLog = $activeLogs[0];
                $pauseConfigId = is_array($activeLog)
                    ? ($activeLog['pausa_config_id'] ?? null)
                    : ($activeLog->pausa_config_id ?? null);

                $pauseName = 'PAUSA';
                $pauseColor = 'orange';

                if (!empty($pauseConfigId)) {
                    $config = PauseConfig::getBreakById((string)$pauseConfigId, $tenancyId);
                    if (!empty($config)) {
                        $pauseName = strtoupper((string)($config['name'] ?? 'PAUSA'));
                        $pauseColor = (string)($config['color_theme'] ?? 'orange');
                    }
                }

                return [
                    'status' => 'PAUSA',
                    'status_name' => $pauseName,
                    'color' => $pauseColor
                ];
            }
        }

        if ($statusNoRedis === 'OCUPADO') {
            return [
                'status' => 'OCUPADO',
                'status_name' => null,
                'color' => 'blue'
            ];
        }

        if ($finalOnline) {
            return [
                'status' => 'LIVRE',
                'status_name' => null,
                'color' => $statusNoRedis === 'PAUSA' ? 'emerald' : $color
            ];
        }

        return [
            'status' => 'OFFLINE',
            'status_name' => null,
            'color' => 'slate'
        ];
    }

    /* =====================
     * Cadastro / sync
     * ===================== */

    /***
     * @param array $endpoints
     * @param bool $forceOnline
     * @return array
     * 10042006
     */
    /*public function addFromPayload(array $endpoints, bool $forceOnline = false): array
    {
        $result = [];
        $now = time();

        foreach ($endpoints as $raw) {
            $ramal = $this->normalizeRamal($raw);
            if (!$this->isValidRamal($ramal)) {
                continue;
            }

            $rawInfo = $this->redis->hget($this->agentsKey, $ramal);
            $info = $rawInfo ? (json_decode($rawInfo, true) ?: []) : [];

            $oldStatus = strtoupper((string)($info['status'] ?? ''));
            $st = $this->checkEndpointOnline($ramal); // true/false/null

            if ($forceOnline) {
                $st = true;
            }

            if ($st === false) {
                $newStatus = 'OFFLINE';

                if ($oldStatus !== $newStatus) {
                    $info['status_since'] = $now;
                }

                $info['status'] = $newStatus;
                $info['online'] = false;
                $info['ari_check'] = 'ok';
                $info['ari_last_ok_ts'] = $now;
                $info['stale'] = false;

                unset(
                    $info['reserved_by'],
                    $info['reserved_channel'],
                    $info['reserved_at'],
                    $info['channel']
                );

            } elseif ($st === true) {
                $currentStatus = strtoupper((string)($info['status'] ?? ''));

                // 🛡️ PROTEÇÃO VOICE: Se o ramal estiver RESERVADO ou em statuses de ocupação,
                // não podemos forçar 'LIVRE', senão o Voice perde o controle.
                $reservedBy = $info['reserved_by'] ?? null;

                // Adicione 'RESERVADO' ou outros status que seu Voice use na lista abaixo
                if (in_array($currentStatus, ['PAUSA', 'OCUPADO', 'RESERVADO', 'BUSY', 'DIALING'], true) || !empty($reservedBy)) {
                    $newStatus = $currentStatus;
                } else {
                    $newStatus = 'LIVRE';
                }

                if ($oldStatus !== $newStatus) {
                    $info['status_since'] = $now;
                }

                $info['status'] = $newStatus;
                $info['online'] = true;
                $info['ari_check'] = 'ok';
                $info['ari_last_ok_ts'] = $now;
                $info['stale'] = false;

                // ⚠️ NÃO DE DELETER no reserved_by se ele existir,
                // pois o Voice precisa dele para saber que o ramal está em uso.
                if (empty($info['reserved_by'])) {
                    unset($info['reserved_by']);
                }

            } else {
                // ARI inconclusivo
                $info['ari_check'] = 'unknown';
                $info['ari_check_ts'] = $now;
                $info['stale'] = true;

                $lastOk = (int)($info['ari_last_ok_ts'] ?? 0);
                if ($lastOk > 0 && ($now - $lastOk) > 30) {
                    $newStatus = 'UNKNOWN';

                    if ($oldStatus !== $newStatus) {
                        $info['status_since'] = $now;
                    }

                    $info['online'] = null;
                    $info['status'] = $newStatus;
                }

                if (empty($info['status'])) {
                    $info['status'] = 'UNKNOWN';
                    $info['online'] = null;
                    $info['status_since'] = $now;
                }
            }

            $info['updated'] = $now;

            $this->redis->hset(
                $this->agentsKey,
                $ramal,
                json_encode($info, JSON_UNESCAPED_UNICODE)
            );

            $this->publish('agent:updated', [
                'ramal' => $ramal,
                'info'  => $info
            ]);

            $result[$ramal] = [
                'online' => $st,
                'status' => $info['status'] ?? null,
            ];
        }

        return $result;
    }*/

    public function addFromPayload(array $endpoints, bool $publish = true): array
    {
        $results = [];
        $now = time();

        foreach ($endpoints as $ramal) {
            $ramalStr = $this->normalizeRamal($ramal);
            if (!$this->isValidRamal($ramalStr)) {
                continue;
            }

            // 1. LER O REDIS PRIMEIRO (O usuário é o dono da decisão aqui)
            $currentRaw = $this->redis->hGet($this->agentsKey, $ramalStr);
            $current = $currentRaw ? json_decode($currentRaw, true) : [];
            $current = is_array($current) ? $current : [];

            // 2. VERIFICAÇÃO TÉCNICA (Apenas saúde do canal)
            $isOnline = $this->checkEndpointOnline($ramalStr);

            // Mantém o estado técnico anterior se o ARI falhar
            $currentOnline = $current['online'] ?? ($current['registered'] ?? false);
            $finalOnline = ($isOnline === null) ? $currentOnline : $isOnline;

            $businessState = $this->resolveBusinessState($ramalStr, $current, (bool)$finalOnline);
            $statusParaGravar = $businessState['status'];
            $motivoPausa = $businessState['status_name'];
            $color = $businessState['color'];

            $statusSince = (int)($current['status_since'] ?? 0);
            if ($statusSince <= 0 || strtoupper((string)($current['status'] ?? '')) !== $statusParaGravar) {
                $statusSince = $now;
            }

            // 4. MONTAGEM DO PAYLOAD (Preservação total)
            $payload = array_merge($current, [
                'online'         => (bool)$finalOnline,
                'status'         => $statusParaGravar,
                'status_since'   => $statusSince,
                'status_name'    => $motivoPausa,
                'color'          => $color,
                'ari_check'      => ($isOnline === true) ? 'ok' : (($isOnline === false) ? 'error' : 'unknown'),
                'ari_last_ok_ts' => ($isOnline === true) ? $now : ($current['ari_last_ok_ts'] ?? 0),
                'stale'          => !$finalOnline,
                'updated'        => $now,
                'ari_check_ts'   => $now,
                'last_update_by' => 'agent_manager_healthcheck'
            ]);

            $this->redis->hSet($this->agentsKey, $ramalStr, json_encode($payload));
            $results[$ramalStr] = $payload;
        }

        if ($publish && !empty($results)) {
            $this->redis->publish($this->pubChannel, json_encode($results));
        }

        return $results;
    }


    /* =====================
     * Reserva ATÔMICA avançada
     * 10042006
     * ===================== */

    /*public function reserveAgentAdvanced(array $criteria, string $workerId): ?string
    {
        $script = <<<LUA
local agentsKey    = KEYS[1]
local now          = tonumber(ARGV[1])
local worker       = ARGV[2]

local preferred    = cjson.decode(ARGV[3] or "[]")
local skills       = cjson.decode(ARGV[4] or "[]")
local minPriority  = tonumber(ARGV[5] or "0") or 0

-- 0/1 vindo do PHP
local strictPreferred = tonumber(ARGV[6] or "0") == 1

-- TTL (segundos) para reserva (evita “preso pra sempre”)
local RES_TTL = tonumber(ARGV[7] or "20") or 20

-- strategy: rrmemory | leastrecent | linear | ringall
local strategy = tostring(ARGV[8] or "rrmemory"):lower()

-- memória do rrmemory (chave fixa)
local LAST_KEY = "discador:agentes:last_ramal"

local hasPreferred = preferred and #preferred > 0

local function normalize(r)
  return tostring(r):gsub("%D","")
end

local function valid(r)
  return r:match("^%d%d%d%d%d%d%d%d$") ~= nil
end

local function is_online(info)
  local v = info and info.online
  return (v == true) or (v == 1) or (v == "1")
end

local function is_livre(info)
  local st = tostring((info and info.status) or ""):upper()
  return st == "LIVRE"
end

-- ✅ TTL baseado em reserved_at (fallback: updated p/ compat)
local function reservation_expired(info)
  local ra = tonumber((info and info.reserved_at) or 0) or 0
  if ra > 0 then
    return (now - ra) > RES_TTL
  end

  -- fallback compat dados antigos
  local up = tonumber((info and info.updated) or 0) or 0
  if up <= 0 then return true end
  return (now - up) > RES_TTL
end

-- considera livre quando reserved_by é: nil / false / cjson.null / "" / "null"
-- e também considera livre se a reserva expirou pelo TTL
local function free(info)
  if not info then return true end

  local v = info.reserved_by

  -- se tem algo reservado, mas expirou, limpamos na “mão” (em memória)
  if v ~= nil and v ~= false and v ~= cjson.null then
    if reservation_expired(info) then
      info.reserved_by = cjson.null
      info.reserved_channel = nil
      info.reserved_at = nil
      return true
    end
    return false
  end

  if v == nil or v == false or v == cjson.null then
    return true
  end

  local s = tostring(v)
  if s == "" then return true end
  if s:lower() == "null" then return true end

  return false
end

local function eligible(info)
  return info and is_online(info) and is_livre(info) and free(info)
end

local function score(info)
  local p = tonumber(info.priority or 0) or 0
  if p < minPriority then return -1 end

  local s = p

  -- bônus por skills
  if info.skills and skills and type(info.skills) == "table" and type(skills) == "table" then
    for _,sk in ipairs(skills) do
      for _,ask in ipairs(info.skills) do
        if sk == ask then
          s = s + 50
        end
      end
    end
  end

  return s
end

-- ✅ grava reserved_at para TTL de reserva
local function reserve_ramal(r, info)
  info.reserved_by      = worker
  info.reserved_channel = nil
  info.reserved_at      = now
  info.updated          = now
  info.last_update_by   = "reserveAgentAdvanced"

  redis.call('HSET', agentsKey, r, cjson.encode(info))

  -- rrmemory: atualiza memória do último ramal escolhido
  if strategy == "rrmemory" then
    redis.call('SET', LAST_KEY, r)
  end

  return r
end

-- ==============================
-- 🎯 1) COLETA CANDIDATOS
-- ==============================
local candidates = {}

-- se vier preferred, candidatos começam por eles (respeita strict)
if hasPreferred then
  for _,rawr in ipairs(preferred) do
    local r = normalize(rawr)
    if valid(r) then
      local raw = redis.call('HGET', agentsKey, r)
      if raw then
        local info = cjson.decode(raw)
        if eligible(info) and score(info) >= 0 then
          table.insert(candidates, r)
        end
      end
    end
  end

  if strictPreferred and #candidates == 0 then
    return nil
  end
end

-- se não tem candidates (ou não tinha preferred), varre todos
if #candidates == 0 then
  for _,key in ipairs(redis.call('HKEYS', agentsKey)) do
    local r = normalize(key)
    if valid(r) then
      local raw = redis.call('HGET', agentsKey, r)
      if raw then
        local info = cjson.decode(raw)
        if eligible(info) and score(info) >= 0 then
          table.insert(candidates, r)
        end
      end
    end
  end
end

if #candidates == 0 then
  return nil
end

-- ==============================
-- 🧠 2) ESCOLHA POR STRATEGY
-- ==============================
local function sort_numeric(a,b)
  return tostring(a) < tostring(b)
end

-- linear: ordem fixa (crescente)
if strategy == "linear" then
  table.sort(candidates, sort_numeric)
  local r = candidates[1]
  local info = cjson.decode(redis.call('HGET', agentsKey, r))
  return reserve_ramal(r, info)
end

-- rrmemory: começa após o último escolhido
if strategy == "rrmemory" then
  table.sort(candidates, sort_numeric)

  local last = redis.call('GET', LAST_KEY)
  if last then
    last = normalize(last)
  end

  if last and valid(last) then
    local idx = nil
    for i=1,#candidates do
      if candidates[i] == last then
        idx = i
        break
      end
    end

    if idx then
      -- pega o próximo na lista circular
      local nexti = idx + 1
      if nexti > #candidates then nexti = 1 end
      local r = candidates[nexti]
      local info = cjson.decode(redis.call('HGET', agentsKey, r))
      return reserve_ramal(r, info)
    end
  end

  -- fallback se não achou last
  local r = candidates[1]
  local info = cjson.decode(redis.call('HGET', agentsKey, r))
  return reserve_ramal(r, info)
end

-- leastrecent: quem está há mais tempo sem receber (menor last_call_ts / last_assigned)
if strategy == "leastrecent" then
  local bestR = nil
  local bestTs = nil
  local bestScore = nil
  local bestInfo = nil

  for _,r in ipairs(candidates) do
    local info = cjson.decode(redis.call('HGET', agentsKey, r))

    local ts = tonumber(info.last_call_ts or info.last_assigned or 0) or 0
    local sc = score(info)

    -- critérios:
    -- 1) menor ts (mais antigo)
    -- 2) desempate por score maior (skills/priority)
    -- 3) desempate por ramal menor
    if (bestTs == nil) or (ts < bestTs)
       or (ts == bestTs and (bestScore == nil or sc > bestScore))
       or (ts == bestTs and sc == bestScore and tostring(r) < tostring(bestR)) then
      bestR = r
      bestTs = ts
      bestScore = sc
      bestInfo = info
    end
  end

  if bestR then
    return reserve_ramal(bestR, bestInfo)
  end

  return nil
end

-- ringall: na prática você NÃO deveria reservar aqui (decide no stasis),
-- mas se chamar mesmo assim, escolhe "o primeiro disponível" (ordenado)
table.sort(candidates, sort_numeric)
do
  local r = candidates[1]
  local info = cjson.decode(redis.call('HGET', agentsKey, r))
  return reserve_ramal(r, info)
end
LUA;

        $res = $this->redis->eval(
            $script,
            1,
            $this->agentsKey,
            time(),
            $workerId,
            json_encode($criteria['preferred'] ?? []),
            json_encode($criteria['skills'] ?? []),
            (int)($criteria['min_priority'] ?? 0),
            (int)($criteria['strict_preferred'] ?? 0),
            (int)($criteria['reserve_ttl'] ?? 20),
            (string)($criteria['strategy'] ?? 'rrmemory')
        );

        if ($res) {
            $this->publish('agent:reserved', ['ramal' => (string)$res, 'worker' => $workerId]);
            return (string)$res;
        }

        return null;
    }*/

    public function reserveAgentAdvanced(array $criteria, string $workerId): ?string
    {
        $script = <<<LUA
local agentsKey    = KEYS[1]
local now          = tonumber(ARGV[1])
local worker       = ARGV[2]

local preferred    = cjson.decode(ARGV[3] or "[]")
local skills       = cjson.decode(ARGV[4] or "[]")
local minPriority  = tonumber(ARGV[5] or "0") or 0
local strictPreferred = tonumber(ARGV[6] or "0") == 1
local RES_TTL = tonumber(ARGV[7] or "20") or 20
local strategy = tostring(ARGV[8] or "rrmemory"):lower()

local LAST_KEY = "discador:agentes:last_ramal"

local function normalize(r)
  return tostring(r):gsub("%D","")
end

local function valid(r)
  return r:match("^%d%d%d%d%d%d%d%d$") ~= nil
end

local function is_online(info)
  local v = info and info.online
  return (v == true) or (v == 1) or (v == "1")
end

-- 🛡️ AJUSTE NA LÓGICA DE STATUS
-- Agora o script só considera LIVRE se o status for exatamente LIVRE ou ONLINE.
-- Se estiver como PAUSA, OCUPADO ou qualquer outra coisa, ele retorna falso.
local function is_livre(info)
  local st = tostring((info and info.status) or ""):upper()
  return (st == "LIVRE" or st == "ONLINE")
end

local function reservation_expired(info)
  local ra = tonumber((info and info.reserved_at) or 0) or 0
  if ra > 0 then
    return (now - ra) > RES_TTL
  end
  local up = tonumber((info and info.updated) or 0) or 0
  if up <= 0 then return true end
  return (now - up) > RES_TTL
end

local function free(info)
  if not info then return true end
  local v = info.reserved_by
  if v ~= nil and v ~= false and v ~= cjson.null then
    if reservation_expired(info) then
      info.reserved_by = cjson.null
      info.reserved_channel = nil
      info.reserved_at = nil
      return true
    end
    return false
  end
  if v == nil or v == false or v == cjson.null then
    return true
  end
  local s = tostring(v)
  if s == "" then return true end
  if s:lower() == "null" then return true end
  return false
end

local function eligible(info)
  -- Aqui a mágica acontece: is_online (saúde) + is_livre (regra de pausa) + free (não reservado)
  return info and is_online(info) and is_livre(info) and free(info)
end

local function score(info)
  local p = tonumber(info.priority or 0) or 0
  if p < minPriority then return -1 end
  local s = p
  if info.skills and skills and type(info.skills) == "table" and type(skills) == "table" then
    for _,sk in ipairs(skills) do
      for _,ask in ipairs(info.skills) do
        if sk == ask then s = s + 50 end
      end
    end
  end
  return s
end

local function reserve_ramal(r, info)
  info.reserved_by      = worker
  info.reserved_channel = nil
  info.reserved_at      = now
  info.updated          = now
  info.last_update_by   = "reserveAgentAdvanced"
  redis.call('HSET', agentsKey, r, cjson.encode(info))
  if strategy == "rrmemory" then
    redis.call('SET', LAST_KEY, r)
  end
  return r
end

local candidates = {}
if hasPreferred then
  for _,rawr in ipairs(preferred) do
    local r = normalize(rawr)
    if valid(r) then
      local raw = redis.call('HGET', agentsKey, r)
      if raw then
        local info = cjson.decode(raw)
        if eligible(info) and score(info) >= 0 then
          table.insert(candidates, r)
        end
      end
    end
  end
  if strictPreferred and #candidates == 0 then return nil end
end

if #candidates == 0 then
  for _,key in ipairs(redis.call('HKEYS', agentsKey)) do
    local r = normalize(key)
    if valid(r) then
      local raw = redis.call('HGET', agentsKey, r)
      if raw then
        local info = cjson.decode(raw)
        if eligible(info) and score(info) >= 0 then
          table.insert(candidates, r)
        end
      end
    end
  end
end

if #candidates == 0 then return nil end

local function sort_numeric(a,b) return tostring(a) < tostring(b) end

if strategy == "linear" then
  table.sort(candidates, sort_numeric)
  local r = candidates[1]
  local info = cjson.decode(redis.call('HGET', agentsKey, r))
  return reserve_ramal(r, info)
end

if strategy == "rrmemory" then
  table.sort(candidates, sort_numeric)
  local last = redis.call('GET', LAST_KEY)
  if last then last = normalize(last) end
  if last and valid(last) then
    local idx = nil
    for i=1,#candidates do
      if candidates[i] == last then idx = i break end
    end
    if idx then
      local nexti = idx + 1
      if nexti > #candidates then nexti = 1 end
      local r = candidates[nexti]
      local info = cjson.decode(redis.call('HGET', agentsKey, r))
      return reserve_ramal(r, info)
    end
  end
  local r = candidates[1]
  local info = cjson.decode(redis.call('HGET', agentsKey, r))
  return reserve_ramal(r, info)
end

if strategy == "leastrecent" then
  local bestR, bestTs, bestScore, bestInfo = nil, nil, nil, nil
  for _,r in ipairs(candidates) do
    local info = cjson.decode(redis.call('HGET', agentsKey, r))
    local ts = tonumber(info.last_call_ts or info.last_assigned or 0) or 0
    local sc = score(info)
    if (bestTs == nil) or (ts < bestTs)
       or (ts == bestTs and (bestScore == nil or sc > bestScore))
       or (ts == bestTs and sc == bestScore and tostring(r) < tostring(bestR)) then
      bestR, bestTs, bestScore, bestInfo = r, ts, sc, info
    end
  end
  if bestR then return reserve_ramal(bestR, bestInfo) end
  return nil
end

table.sort(candidates, sort_numeric)
do
  local r = candidates[1]
  local info = cjson.decode(redis.call('HGET', agentsKey, r))
  return reserve_ramal(r, info)
end
LUA;

        $res = $this->redis->eval(
            $script,
            1,
            $this->agentsKey,
            time(),
            $workerId,
            json_encode($criteria['preferred'] ?? []),
            json_encode($criteria['skills'] ?? []),
            (int)($criteria['min_priority'] ?? 0),
            (int)($criteria['strict_preferred'] ?? 0),
            (int)($criteria['reserve_ttl'] ?? 20),
            (string)($criteria['strategy'] ?? 'rrmemory')
        );

        if ($res) {
            $this->publish('agent:reserved', ['ramal' => (string)$res, 'worker' => $workerId]);
            return (string)$res;
        }

        return null;
    }


    /* =====================
     * Liberação + métricas
     * ===================== */
    public function releaseAgent(string $ramal, bool $ok): void
    {
        $raw = $this->redis->hget($this->agentsKey, $ramal);
        if (!$raw) return;

        $info = json_decode($raw, true) ?: [];

        // 🔓 LIBERA LOCK SEMPRE
        if (isset($info['reserved_by'])) {
            unset($info['reserved_by']);
        }

        // status NÃO é decidido aqui
        $info['updated'] = time();
        $info['last_update_by'] = 'worker_release';

        $this->redis->hset(
            $this->agentsKey,
            $ramal,
            json_encode($info, JSON_UNESCAPED_UNICODE)
        );
    }


    /* =====================
     * ARI helpers
     * ===================== */
    /*public function checkEndpointOnline(string $ramal): ?bool
    {
        if (!$this->ariHost || !$this->ariAuth) return null;

        try {
            $res = $this->http->get("ari/endpoints/PJSIP/{$ramal}", ['auth' => $this->ariAuth]);
            $data = json_decode((string)$res->getBody(), true);
            return in_array(strtolower($data['state'] ?? ''), ['online', 'reachable'], true);
        } catch (Throwable $e) {
            return false;
        }
    }*/

    public function checkEndpointOnline(string $ramal): ?bool
    {
        if (!$this->ariHost || !$this->ariAuth) return null;

        try {
            $res = $this->http->get("ari/endpoints/PJSIP/{$ramal}", [
                'auth'            => $this->ariAuth,
                'http_errors'     => false,
                'timeout'         => 2,
                'connect_timeout' => 1,
            ]);

            $code = (int)$res->getStatusCode();

            // não concluir offline
            if (in_array($code, [401, 403], true)) return null;
            if ($code >= 500) return null;
            if ($code !== 200) return null;

            $data = json_decode((string)$res->getBody(), true);
            if (!is_array($data)) return null;

            $state = strtolower(trim((string)($data['state'] ?? '')));
            $channelIds = $data['channel_ids'] ?? [];
            $contacts = $data['contacts'] ?? ($data['contact_info'] ?? []);
            $hasChannels = is_array($channelIds) && !empty($channelIds);
            $hasContacts = is_array($contacts) && !empty($contacts);

            if (in_array($state, ['online', 'reachable', 'available'], true)) return true;
            if (in_array($state, ['offline', 'unreachable'], true)) return false;
            if ($hasChannels || $hasContacts) return true;
            if ($state === '') return null;

            return null;

        } catch (\Throwable $e) {
            return null; // ✅ aqui é o ponto principal: falha ≠ offline
        }
    }

    /*public function checkEndpointOnline(string $ramal): ?bool
    {
        $monitor = new AsteriskErrorMonitor($this->redis);

        if (!$this->ariHost || !$this->ariAuth) {
            return null;
        }

        try {
            $res = $this->http->get("ari/endpoints/PJSIP/{$ramal}", [
                'auth'            => $this->ariAuth,
                'http_errors'     => false,
                'timeout'         => 2,
                'connect_timeout' => 1,
            ]);

            $code = (int) $res->getStatusCode();

            // 🔐 erro de autenticação → alerta global
            if (in_array($code, [401, 403], true)) {
                $this->monitor->recordHttpResult([
                    'ok'          => false,
                    'http_status' => $code,
                    'endpoint'    => "/ari/endpoints/PJSIP/{$ramal}",
                    'error'       => 'Unauthorized',
                    'host'        => $this->ariHost,
                ], [
                    'job_id' => null,
                    'call_id'=> 'healthcheck',
                    'phone'  => null,
                ]);
                return null;
            }


            // falhas não conclusivas
            if ($code >= 500) return null;
            if ($code !== 200) return null;

            $data = json_decode((string) $res->getBody(), true);
            if (!is_array($data)) return null;

            $state = strtolower(trim((string) ($data['state'] ?? '')));
            if ($state === '') return null;

            if (in_array($state, ['online', 'reachable'], true)) return true;
            if (in_array($state, ['offline', 'unreachable'], true)) return false;

            return null;

        } catch (\Throwable $e) {
            return null; // falha ≠ offline
        }
    }*/





}
