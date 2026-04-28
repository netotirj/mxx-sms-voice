<?php

require __DIR__ . '/bootstrap/app.php';

use App\Controller\Pages\Voice;
use App\Utils\AsteriskEnv;
use Predis\Client as RedisClient;

ini_set('output_buffering', 'off');
ini_set('implicit_flush', '1');
ob_implicit_flush(true);

echo "Initializing CDR Worker...\n";
echo "Listening Redis queue: asterisk:tarifacoes\n";
echo "Press Ctrl+C to stop.\n";

$lastHeartbeat = time();
$redis = new RedisClient([
    'scheme' => 'tcp',
    'host' => getenv('REDIS_HOST') ?: AsteriskEnv::host(),
    'port' => (int)(getenv('REDIS_PORT') ?: 6379),
    'password' => getenv('REDIS_PASSWORD') ?: 'RedisPwd081092!',
]);

function cdrWorkerDiagnostics(RedisClient $redis): string
{
    $queueSize = (int)$redis->llen('asterisk:tarifacoes');
    $pendingFailures = 0;
    $cursor = 0;

    do {
        $scan = $redis->scan($cursor, [
            'match' => 'cdr-falha-pendente:*',
            'count' => 100,
        ]);

        $cursor = (int)($scan[0] ?? 0);
        $keys = $scan[1] ?? [];
        $pendingFailures += is_array($keys) ? count($keys) : 0;
    } while ($cursor !== 0);

    return "queue={$queueSize} pending_failures={$pendingFailures}";
}

while (true) {
    Voice::processCdrFromRedis();

    if (time() - $lastHeartbeat >= 30) {
        try {
            $diagnostics = cdrWorkerDiagnostics($redis);
        } catch (Throwable $e) {
            $diagnostics = 'redis_diagnostics_error=' . $e->getMessage();
        }

        echo '[' . date('Y-m-d H:i:s') . "] CDR Worker alive, waiting for payloads... {$diagnostics}\n";
        $lastHeartbeat = time();
    }

    usleep(500000);
}
