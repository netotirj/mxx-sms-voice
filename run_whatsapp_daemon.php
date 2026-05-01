<?php

require __DIR__ . '/bootstrap/cli.php';

use App\Service\WhatsAppOutboxWorker;

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
