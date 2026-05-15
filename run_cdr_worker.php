<?php

require __DIR__ . '/bootstrap/app.php';

use App\Controller\Pages\Voice;
use App\Model\Entity\WorkerHeartbeat;
use App\RedisConn;
use Predis\Client as RedisClient;

ini_set('output_buffering', 'off');
ini_set('implicit_flush', '1');
ob_implicit_flush(true);

echo "Initializing CDR Worker...\n";
echo "Listening Redis queue: asterisk:tarifacoes\n";
echo "Press Ctrl+C to stop.\n";

$lastHeartbeat = time();
$serviceName = getenv('CDR_WORKER_SERVICE_NAME') ?: 'maxx-cdr-worker.service';
WorkerHeartbeat::record($serviceName, 'cdr', 'starting', 'CDR worker inicializado.', 0, 0, round(memory_get_usage(true) / 1048576, 2));
$redis = RedisConn::get();

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
    try {
        Voice::processCdrFromRedis();
    } catch (Throwable $e) {
        WorkerHeartbeat::record(
            $serviceName,
            'cdr',
            'error',
            'Falha no loop do CDR worker: ' . $e->getMessage(),
            0,
            1,
            round(memory_get_usage(true) / 1048576, 2)
        );
        echo '[' . date('Y-m-d H:i:s') . '] ERRO ' . $e->getMessage() . "\n";
        usleep(500000);
        continue;
    }

    if (time() - $lastHeartbeat >= 30) {
        try {
            $diagnostics = cdrWorkerDiagnostics($redis);
        } catch (Throwable $e) {
            $diagnostics = 'redis_diagnostics_error=' . $e->getMessage();
        }

        echo '[' . date('Y-m-d H:i:s') . "] CDR Worker alive, waiting for payloads... {$diagnostics}\n";
        WorkerHeartbeat::record(
            $serviceName,
            'cdr',
            'running',
            "CDR Worker alive. {$diagnostics}",
            0,
            0,
            round(memory_get_usage(true) / 1048576, 2)
        );
        $lastHeartbeat = time();
    }

    usleep(500000);
}
