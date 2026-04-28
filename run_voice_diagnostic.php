<?php

require __DIR__ . '/vendor/autoload.php';

use App\RedisConn;
use App\Utils\AsteriskEnv;
use GuzzleHttp\Client;
use WilliamCosta\DotEnv\Environment;

Environment::load(__DIR__);

ini_set('output_buffering', 'off');
ini_set('implicit_flush', '1');
ob_implicit_flush(true);

$args = array_slice($argv, 1);
$once = in_array('--once', $args, true);
$interval = 5;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--interval=')) {
        $interval = max(1, (int)substr($arg, strlen('--interval=')));
    }
}

echo "Voice Diagnostic Monitor\n";
echo "Modo: " . ($once ? 'uma leitura' : "continuo a cada {$interval}s") . "\n";
echo "Pressione Ctrl+C para parar.\n\n";

do {
    $snapshot = collectSnapshot();
    printSnapshot($snapshot);

    if ($once) {
        break;
    }

    sleep($interval);
} while (true);

function collectSnapshot(): array
{
    $now = time();
    $out = [
        'time' => date('Y-m-d H:i:s'),
        'env' => [
            'asterisk_host' => AsteriskEnv::host(),
            'ari_host' => AsteriskEnv::ariHost(),
            'ari_port' => AsteriskEnv::ariPort(),
            'ws_host' => AsteriskEnv::wsHost(),
            'ws_port' => AsteriskEnv::wsPort(),
            'redis_host' => getenv('REDIS_HOST') ?: AsteriskEnv::host(),
            'redis_port' => (int)(getenv('REDIS_PORT') ?: 6379),
        ],
        'checks' => [],
        'redis' => [],
        'ari' => [],
        'agents' => [
            'total' => 0,
            'online_livre' => 0,
            'offline' => 0,
            'pausa' => 0,
            'ocupado' => 0,
            'sample' => [],
        ],
        'calls' => [
            'active_count' => 0,
            'snapshot_age_s' => null,
            'sample' => [],
        ],
        'campaigns' => [],
        'errors' => [],
        'warnings' => [],
    ];

    $out['checks']['tcp_ari'] = tcpProbe(AsteriskEnv::ariHost(), AsteriskEnv::ariPort());
    $out['checks']['tcp_ws'] = tcpProbe(AsteriskEnv::wsHost(), AsteriskEnv::wsPort());

    try {
        $redis = RedisConn::get();

        $t0 = microtime(true);
        $pong = $redis->ping();
        $out['redis']['ping_ms'] = elapsedMs($t0);
        $out['redis']['pong'] = (string)$pong;

        $out['redis']['voice_queue'] = safeInt(fn() => $redis->llen('voice:queue'));
        $out['redis']['transfer_queue'] = safeInt(fn() => $redis->llen('voice:transfer_request'));
        $out['redis']['paused_keys'] = countKeys($redis, 'voice:paused:job:*');
        $out['redis']['call_context_keys'] = countKeys($redis, 'voice:call_context:*');
        $out['redis']['channel_context_keys'] = countKeys($redis, 'voice:channel_context:*');

        $activeRaw = $redis->get('asterisk:active_calls');
        $active = $activeRaw ? json_decode((string)$activeRaw, true) : null;
        if (is_array($active['chamadas'] ?? null)) {
            $activeCalls = $active['chamadas'];
        } elseif (is_array($active['calls'] ?? null)) {
            $activeCalls = $active['calls'];
        } else {
            $activeCalls = is_array($active) ? $active : [];
        }
        $serverNow = (int)($active['server_now'] ?? $active['ts'] ?? 0);

        $out['calls']['active_count'] = count($activeCalls);
        $out['calls']['snapshot_age_s'] = $serverNow > 0 ? max(0, $now - $serverNow) : null;
        $out['calls']['sample'] = array_slice(array_map(static function ($call): array {
            return [
                'id' => $call['id'] ?? null,
                'state' => $call['state'] ?? null,
                'caller' => $call['caller'] ?? null,
                'dest' => $call['dest'] ?? $call['destination'] ?? $call['number'] ?? null,
                'agente' => $call['agente'] ?? $call['vars']['AGENT_RAMAL'] ?? $call['vars']['__RAMAL'] ?? null,
                'duracao' => $call['duracao'] ?? $call['duration'] ?? null,
            ];
        }, $activeCalls), 0, 5);

        $agentsRaw = $redis->hgetall('discador:agentes');
        $agents = [];
        foreach ((array)$agentsRaw as $ramal => $json) {
            $info = json_decode((string)$json, true);
            if (!is_array($info)) {
                continue;
            }

            $status = strtoupper((string)($info['status'] ?? ''));
            $online = ($info['online'] ?? false) === true || ($info['online'] ?? null) === 1 || ($info['online'] ?? null) === '1';
            $agents[] = [
                'ramal' => (string)$ramal,
                'status' => $status ?: 'UNKNOWN',
                'status_name' => $info['status_name'] ?? null,
                'online' => $online,
                'reserved_by' => $info['reserved_by'] ?? null,
                'updated_age_s' => isset($info['updated']) ? max(0, $now - (int)$info['updated']) : null,
                'last_update_by' => $info['last_update_by'] ?? null,
            ];
        }

        $out['agents']['total'] = count($agents);
        $out['agents']['online_livre'] = count(array_filter($agents, fn($a) => $a['online'] && $a['status'] === 'LIVRE'));
        $out['agents']['offline'] = count(array_filter($agents, fn($a) => !$a['online']));
        $out['agents']['pausa'] = count(array_filter($agents, fn($a) => $a['status'] === 'PAUSA'));
        $out['agents']['ocupado'] = count(array_filter($agents, fn($a) => in_array($a['status'], ['OCUPADO', 'RESERVADO', 'BUSY', 'DIALING'], true)));
        $out['agents']['sample'] = array_slice($agents, 0, 10);

        $out['campaigns']['runtime'] = readCampaignRuntime($redis);
        $out['errors']['recent_http'] = readRecentRedisJsonList($redis, 'voice:errors:http', 5);
        $out['errors']['last_stasis'] = readRedisJson($redis, 'voice:stasis:last_error');

        if (($out['redis']['voice_queue'] ?? 0) > 0 && ($out['agents']['online_livre'] ?? 0) === 0) {
            $out['warnings'][] = 'Fila tem chamadas, mas nao ha agente online e LIVRE.';
        }

        if (($out['calls']['snapshot_age_s'] ?? 0) > 20) {
            $out['warnings'][] = 'Snapshot asterisk:active_calls esta velho; pode ser Stasis/monitoramento parado.';
        }
    } catch (Throwable $e) {
        $out['redis']['error'] = $e->getMessage();
        $out['warnings'][] = 'Falha lendo Redis; pode ser rede/host/senha/servico Redis.';
    }

    $out['ari'] = ariProbe();

    if (($out['checks']['tcp_ari']['ok'] ?? false) === false) {
        $out['warnings'][] = 'TCP ARI nao conecta; suspeita de rede, firewall ou Asterisk fora.';
    }

    if (($out['ari']['ok'] ?? false) === false) {
        $out['warnings'][] = 'ARI HTTP nao respondeu OK; suspeita de autenticacao, rede ou Asterisk.';
    }

    return $out;
}

function printSnapshot(array $snapshot): void
{
    echo str_repeat('=', 78) . "\n";
    echo "[{$snapshot['time']}]\n";
    echo "Hosts: ARI {$snapshot['env']['ari_host']}:{$snapshot['env']['ari_port']} | WS {$snapshot['env']['ws_host']}:{$snapshot['env']['ws_port']} | Redis {$snapshot['env']['redis_host']}:{$snapshot['env']['redis_port']}\n";

    echo "Rede: ARI TCP " . formatCheck($snapshot['checks']['tcp_ari'] ?? []) .
        " | WS TCP " . formatCheck($snapshot['checks']['tcp_ws'] ?? []) . "\n";

    if (isset($snapshot['redis']['error'])) {
        echo "Redis: ERRO {$snapshot['redis']['error']}\n";
    } else {
        echo "Redis: ping {$snapshot['redis']['ping_ms']}ms | voice:queue {$snapshot['redis']['voice_queue']} | transfer {$snapshot['redis']['transfer_queue']} | ctx {$snapshot['redis']['call_context_keys']}\n";
    }

    echo "ARI: " . (($snapshot['ari']['ok'] ?? false) ? 'OK' : 'FALHA') .
        " | http " . ($snapshot['ari']['http_status'] ?? '-') .
        " | {$snapshot['ari']['latency_ms']}ms" .
        (!empty($snapshot['ari']['error']) ? " | {$snapshot['ari']['error']}" : '') . "\n";

    echo "Agentes: total {$snapshot['agents']['total']} | online+livre {$snapshot['agents']['online_livre']} | pausa {$snapshot['agents']['pausa']} | ocupado {$snapshot['agents']['ocupado']} | offline {$snapshot['agents']['offline']}\n";
    foreach (($snapshot['agents']['sample'] ?? []) as $agent) {
        $online = $agent['online'] ? 'online' : 'offline';
        $detail = $agent['status_name'] ? " ({$agent['status_name']})" : '';
        $reserved = $agent['reserved_by'] ? " reserved={$agent['reserved_by']}" : '';
        echo "  - {$agent['ramal']} {$online} {$agent['status']}{$detail} age={$agent['updated_age_s']}s{$reserved}\n";
    }

    echo "Chamadas ativas: {$snapshot['calls']['active_count']} | snapshot age " . ($snapshot['calls']['snapshot_age_s'] ?? '-') . "s\n";
    foreach (($snapshot['calls']['sample'] ?? []) as $call) {
        echo "  - {$call['id']} {$call['state']} caller={$call['caller']} dest={$call['dest']} agente={$call['agente']} dur={$call['duracao']}\n";
    }

    if (!empty($snapshot['campaigns']['runtime'])) {
        echo "Campanhas runtime:\n";
        foreach ($snapshot['campaigns']['runtime'] as $campaign) {
            echo "  - {$campaign['job']} {$campaign['code']} {$campaign['msg']} age={$campaign['age_s']}s\n";
        }
    }

    if (!empty($snapshot['errors']['recent_http'])) {
        echo "Erros HTTP recentes:\n";
        foreach ($snapshot['errors']['recent_http'] as $err) {
            echo "  - {$err['class']} status=" . ($err['http']['status'] ?? '-') . " call=" . ($err['call_id'] ?? '-') . " job=" . ($err['job_id'] ?? '-') . "\n";
        }
    }

    if (!empty($snapshot['warnings'])) {
        echo "Alertas:\n";
        foreach ($snapshot['warnings'] as $warning) {
            echo "  ! {$warning}\n";
        }
    }

    echo "\n";
}

function tcpProbe(string $host, int $port, float $timeout = 2.0): array
{
    $t0 = microtime(true);
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $ms = elapsedMs($t0);

    if (is_resource($fp)) {
        fclose($fp);
        return ['ok' => true, 'latency_ms' => $ms];
    }

    return ['ok' => false, 'latency_ms' => $ms, 'error' => trim($errstr) ?: "errno {$errno}"];
}

function ariProbe(): array
{
    $t0 = microtime(true);

    try {
        $client = new Client([
            'base_uri' => 'http://' . AsteriskEnv::ariHost() . ':' . AsteriskEnv::ariPort() . '/',
            'timeout' => 3.0,
            'connect_timeout' => 2.0,
            'http_errors' => false,
            'auth' => AsteriskEnv::ariAuth(),
        ]);

        $response = $client->get('ari/asterisk/info');
        $status = $response->getStatusCode();

        return [
            'ok' => $status >= 200 && $status < 300,
            'http_status' => $status,
            'latency_ms' => elapsedMs($t0),
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'http_status' => null,
            'latency_ms' => elapsedMs($t0),
            'error' => $e->getMessage(),
        ];
    }
}

function readCampaignRuntime($redis): array
{
    $items = [];
    $keys = [];

    try {
        $keys = $redis->keys('campaign:*:runtime_status') ?: [];
    } catch (Throwable) {
        return [];
    }

    foreach (array_slice($keys, 0, 10) as $key) {
        $raw = $redis->get($key);
        $data = $raw ? json_decode((string)$raw, true) : null;
        if (!is_array($data)) {
            continue;
        }

        $items[] = [
            'job' => str_replace(['campaign:', ':runtime_status'], '', (string)$key),
            'code' => (string)($data['code'] ?? '-'),
            'msg' => (string)($data['msg'] ?? '-'),
            'age_s' => isset($data['ts']) ? max(0, time() - (int)$data['ts']) : null,
        ];
    }

    return $items;
}

function readRecentRedisJsonList($redis, string $key, int $limit): array
{
    $out = [];

    try {
        $rows = $redis->lrange($key, 0, $limit - 1) ?: [];
    } catch (Throwable) {
        return [];
    }

    foreach ($rows as $row) {
        $decoded = json_decode((string)$row, true);
        if (is_array($decoded)) {
            $out[] = $decoded;
        }
    }

    return $out;
}

function readRedisJson($redis, string $key): ?array
{
    try {
        $raw = $redis->get($key);
        $decoded = $raw ? json_decode((string)$raw, true) : null;
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable) {
        return null;
    }
}

function countKeys($redis, string $pattern): int
{
    try {
        return count($redis->keys($pattern) ?: []);
    } catch (Throwable) {
        return 0;
    }
}

function safeInt(callable $callback): int
{
    try {
        return (int)$callback();
    } catch (Throwable) {
        return 0;
    }
}

function elapsedMs(float $start): int
{
    return (int)round((microtime(true) - $start) * 1000);
}

function formatCheck(array $check): string
{
    if (($check['ok'] ?? false) === true) {
        return "OK {$check['latency_ms']}ms";
    }

    return "FALHA {$check['latency_ms']}ms" . (!empty($check['error']) ? " ({$check['error']})" : '');
}
