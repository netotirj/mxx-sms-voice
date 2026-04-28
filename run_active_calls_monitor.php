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

$intervalMs = 1000;
$args = array_slice($argv, 1);
$once = in_array('--once', $args, true);

foreach ($args as $arg) {
    if (str_starts_with($arg, '--interval-ms=')) {
        $intervalMs = max(250, (int)substr($arg, strlen('--interval-ms=')));
    }
}

$redis = RedisConn::get();
$http = new Client([
    'base_uri' => 'http://' . AsteriskEnv::ariHost() . ':' . AsteriskEnv::ariPort() . '/',
    'timeout' => 3.0,
    'connect_timeout' => 2.0,
    'http_errors' => false,
    'auth' => AsteriskEnv::ariAuth(),
]);

echo "Active Calls Monitor iniciado\n";
echo "ARI: " . AsteriskEnv::ariHost() . ':' . AsteriskEnv::ariPort() . "\n";
echo "Intervalo: {$intervalMs}ms\n";
echo $once ? "Modo: uma leitura\n\n" : "Pressione Ctrl+C para parar.\n\n";

while (true) {
    $started = microtime(true);

    try {
        $channels = fetchChannels($http);
        $existingCalls = existingCallsById($redis);
        $payload = buildPayload($http, $channels, $existingCalls);

        $redis->set('asterisk:active_calls', json_encode($payload, JSON_UNESCAPED_UNICODE));
        $redis->setex('voice:stasis:heartbeat', 10, json_encode([
            'ts' => time(),
            'source' => 'run_active_calls_monitor',
            'pid' => getmypid(),
            'host' => gethostname(),
            'app' => AsteriskEnv::stasisApp(),
        ], JSON_UNESCAPED_UNICODE));

        echo '[' . date('H:i:s') . "] active_calls={$payload['totalChamadas']} updated\n";
    } catch (Throwable $e) {
        $redis->setex('voice:stasis:last_error', 3600, json_encode([
            'ts' => time(),
            'source' => 'run_active_calls_monitor',
            'msg' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE));

        echo '[' . date('H:i:s') . '] ERRO ' . $e->getMessage() . "\n";
    }

    if ($once) {
        break;
    }

    $elapsedUs = (int)round((microtime(true) - $started) * 1_000_000);
    $sleepUs = max(0, ($intervalMs * 1000) - $elapsedUs);
    usleep($sleepUs);
}

function fetchChannels(Client $http): array
{
    $response = $http->get('ari/channels');

    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
        throw new RuntimeException('ARI /channels HTTP ' . $response->getStatusCode());
    }

    $channels = json_decode((string)$response->getBody(), true);
    return is_array($channels) ? $channels : [];
}

function existingCallsById($redis): array
{
    $raw = $redis->get('asterisk:active_calls');
    $payload = $raw ? json_decode((string)$raw, true) : null;
    $calls = is_array($payload) ? ($payload['chamadas'] ?? []) : [];
    $indexed = [];

    foreach ($calls as $call) {
        if (!is_array($call)) {
            continue;
        }

        $id = (string)($call['id'] ?? '');
        if ($id !== '') {
            $indexed[$id] = $call;
        }
    }

    return $indexed;
}

function buildPayload(Client $http, array $channels, array $existingCalls = []): array
{
    $now = time();
    $calls = [];

    foreach ($channels as $channel) {
        if (!is_array($channel)) {
            continue;
        }

        $id = (string)($channel['id'] ?? '');
        if ($id === '') {
            continue;
        }

        $vars = fetchChannelVars($http, $id, [
            'OWNER_ID',
            'TENANT_ID',
            'ROLE',
            'CAMPAIGN_ID',
            'CAMPAIGN_TYPE',
            'VOICE_LIST_ID',
            'JOB_ID',
            'CALL_ID',
            'EXTENSION',
            'DEST_NUMBER',
            'CALLERID_NUM',
            'CALLERID_NUMBER',
            'CALLERID(num)',
            'AGENT_RAMAL',
            '__RAMAL',
            'TRUNK',
            'TECHPREFIX',
            'DIAL_PREFIX',
        ]);

        $callerNumber = (string)($channel['caller']['number'] ?? $vars['CALLERID_NUM'] ?? $vars['CALLERID_NUMBER'] ?? '');
        $connectedNumber = (string)($channel['connected']['number'] ?? '');
        $dialplanExten = (string)($channel['dialplan']['exten'] ?? '');
        $destination = (string)($vars['DEST_NUMBER'] ?? $vars['EXTENSION'] ?? $connectedNumber ?: $dialplanExten);

        $createdRaw = (string)($channel['creationtime'] ?? '');
        $started = $createdRaw !== '' ? strtotime($createdRaw) : $now;
        if (!$started) {
            $started = $now;
        }

        $state = (string)($channel['state'] ?? '');
        $answered = strtolower($state) === 'up' ? $started : null;

        $previous = $existingCalls[$id] ?? [];
        $previousVars = is_array($previous['vars'] ?? null) ? $previous['vars'] : [];
        $vars = array_merge($previousVars, $vars);

        $calls[] = [
            'id' => $id,
            'name' => (string)($channel['name'] ?? ''),
            'number' => $destination,
            'caller' => $callerNumber,
            'destination' => $destination,
            'status' => $state,
            'state' => $state,
            'duration' => gmdate('i:s', max(0, $now - $started)),
            'started' => $started,
            'answered' => $answered,
            'ended' => null,
            'dtmf' => $previous['dtmf'] ?? $previous['last_dtmf'] ?? null,
            'in_bridge' => !empty($channel['bridge_id']),
            'peer' => $previous['peer'] ?? null,
            'owner_id' => $vars['OWNER_ID'] ?? null,
            'tenant_id' => $vars['TENANT_ID'] ?? null,
            'role' => $vars['ROLE'] ?? null,
            'trunk' => $vars['TRUNK'] ?? null,
            'extension' => $vars['EXTENSION'] ?? null,
            'sms_cost' => $vars['SMS_COST'] ?? ($previous['sms_cost'] ?? null),
            'call_minute_cost' => $vars['CALL_MINUTE_COST'] ?? ($previous['call_minute_cost'] ?? null),
            'variable_type' => $vars['CAMPAIGN_TYPE'] ?? ($vars['VARIABLE_TYPE'] ?? ($previous['variable_type'] ?? null)),
            'vars' => $vars,
        ];
    }

    return [
        'statusGeral' => 'OK',
        'totalChamadas' => count($calls),
        'chamadas' => $calls,
        'timestamp' => date('Y-m-d H:i:s'),
        'server_now' => $now,
        'source' => 'run_active_calls_monitor',
    ];
}

function fetchChannelVars(Client $http, string $channelId, array $names): array
{
    $vars = [];

    foreach ($names as $name) {
        try {
            $response = $http->get('ari/channels/' . rawurlencode($channelId) . '/variable', [
                'query' => ['variable' => $name],
            ]);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                continue;
            }

            $body = json_decode((string)$response->getBody(), true);
            $value = $body['value'] ?? null;

            if ($value !== null && $value !== '') {
                $vars[$name] = $value;
            }
        } catch (Throwable) {
            continue;
        }
    }

    return $vars;
}
