<?php

require __DIR__ . '/bootstrap/cli.php';

use App\Service\WhatsAppOutboxWorker;
use App\Model\Entity\WorkerHeartbeat;

ini_set('output_buffering', 'off');
ini_set('implicit_flush', 1);
ob_implicit_flush(true);

$limit = isset($argv[1]) ? max(1, (int)$argv[1]) : 50;
$cancelCategory = isset($argv[2]) && $argv[2] !== '' ? strtoupper((string)$argv[2]) : null;

$worker = new WhatsAppOutboxWorker();
$serviceName = getenv('WHATSAPP_WORKER_SERVICE_NAME') ?: 'maxx-whatsapp-worker.service';

try {
    $summary = $worker->runOnce($limit, $cancelCategory);

    WorkerHeartbeat::record(
        $serviceName,
        'whatsapp',
        'running',
        'Execução unitária do worker de WhatsApp.',
        (int)($summary['processed'] ?? 0),
        (int)($summary['failed'] ?? 0),
        round(memory_get_usage(true) / 1048576, 2)
    );

    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
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

    throw $e;
}
