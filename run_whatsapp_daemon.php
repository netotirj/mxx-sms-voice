<?php

require __DIR__ . '/bootstrap/cli.php';

use App\Service\WhatsAppOutboxWorker;
use App\Model\Entity\WorkerHeartbeat;

ini_set('output_buffering', 'off');
ini_set('implicit_flush', '1');
ob_implicit_flush(true);

$options = getopt('', [
    'limit::',
    'sleep::',
    'max-loops::',
    'cancel-category::',
]);

$limit = max(1, (int)($options['limit'] ?? 50));
$sleepSeconds = max(5, (int)($options['sleep'] ?? 10));
$maxLoops = max(0, (int)($options['max-loops'] ?? 0));
$cancelCategory = isset($options['cancel-category']) && $options['cancel-category'] !== ''
    ? strtoupper((string)$options['cancel-category'])
    : null;

$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running): void {
        $running = false;
    });
}

$worker = new WhatsAppOutboxWorker();
$loop = 0;
$serviceName = getenv('WHATSAPP_WORKER_SERVICE_NAME') ?: 'maxx-whatsapp-worker.service';

WorkerHeartbeat::record($serviceName, 'whatsapp', 'starting', 'Daemon de WhatsApp iniciado.', 0, 0, round(memory_get_usage(true) / 1048576, 2));

daemonLog('started', [
    'limit' => $limit,
    'sleep' => $sleepSeconds,
    'max_loops' => $maxLoops,
    'cancel_category' => $cancelCategory,
]);

while ($running) {
    $loop++;
    $startedAt = microtime(true);

    try {
        $summary = $worker->runOnce($limit, $cancelCategory);
        WorkerHeartbeat::record(
            $serviceName,
            'whatsapp',
            'running',
            'Daemon tick processado.',
            (int)($summary['processed'] ?? 0),
            (int)($summary['failed'] ?? 0),
            round(memory_get_usage(true) / 1048576, 2)
        );
        daemonLog('tick', [
            'loop' => $loop,
            'processed' => $summary['processed'] ?? 0,
            'sent' => $summary['sent'] ?? 0,
            'failed' => $summary['failed'] ?? 0,
            'locked' => $summary['locked'] ?? false,
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'memory_mb' => round(memory_get_usage(true) / 1048576, 2),
        ]);
    } catch (Throwable $e) {
        WorkerHeartbeat::record(
            $serviceName,
            'whatsapp',
            'error',
            $e->getMessage(),
            0,
            1,
            round(memory_get_usage(true) / 1048576, 2)
        );
        daemonLog('error', [
            'loop' => $loop,
            'message' => $e->getMessage(),
        ]);
        sleep(min(30, $sleepSeconds * 2));
    }

    if ($maxLoops > 0 && $loop >= $maxLoops) {
        break;
    }

    sleep($sleepSeconds);
}

WorkerHeartbeat::record($serviceName, 'whatsapp', 'stopped', 'Daemon de WhatsApp finalizado.', 0, 0, round(memory_get_usage(true) / 1048576, 2));
daemonLog('stopped', ['loops' => $loop]);

function daemonLog(string $event, array $context = []): void
{
    echo json_encode(
        array_merge([
            'time' => date('Y-m-d H:i:s'),
            'event' => $event,
        ], $context),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
}
