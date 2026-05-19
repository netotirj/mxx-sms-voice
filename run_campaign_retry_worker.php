<?php

require __DIR__ . '/bootstrap/cli.php';

use App\Model\Entity\WorkerHeartbeat;
use App\Service\CampaignSchedulerService;

ini_set('output_buffering', 'off');
ini_set('implicit_flush', 1);
ob_implicit_flush(true);

$limit = isset($argv[1]) ? max(1, (int)$argv[1]) : 20;
$workerName = getenv('CAMPAIGN_RETRY_WORKER_SERVICE_NAME') ?: 'maxx-campaign-retry.service';

try {
    $summary = (new CampaignSchedulerService())->runRetryWorker($limit, $workerName);

    WorkerHeartbeat::record(
        $workerName,
        'campaign_retry',
        'running',
        'Execucao do worker de retry de campanhas.',
        (int)($summary['processed'] ?? 0),
        (int)($summary['failed'] ?? 0),
        round(memory_get_usage(true) / 1048576, 2)
    );

    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    WorkerHeartbeat::record(
        $workerName,
        'campaign_retry',
        'error',
        $e->getMessage(),
        0,
        1,
        round(memory_get_usage(true) / 1048576, 2)
    );

    throw $e;
}
