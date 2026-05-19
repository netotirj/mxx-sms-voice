<?php

require __DIR__ . '/bootstrap/cli.php';

use App\Model\Entity\WorkerHeartbeat;
use App\Service\CampaignDispatchRepository;
use App\Service\CampaignDispatchSchema;

ini_set('output_buffering', 'off');
ini_set('implicit_flush', 1);
ob_implicit_flush(true);

$logsRetention = isset($argv[1]) ? max(1, (int)$argv[1]) : 30;
$runsRetention = isset($argv[2]) ? max(1, (int)$argv[2]) : 30;
$smsRetention = isset($argv[3]) ? max(1, (int)$argv[3]) : 15;
$workerName = getenv('CAMPAIGN_CLEANUP_SERVICE_NAME') ?: 'maxx-campaign-cleanup.service';

try {
    CampaignDispatchSchema::ensureSchema();
    $summary = CampaignDispatchRepository::purgeOldData($logsRetention, $runsRetention, $smsRetention);

    WorkerHeartbeat::record(
        $workerName,
        'campaign_cleanup',
        'running',
        'Execucao da limpeza de logs, runs e fila de SMS.',
        (int)array_sum(array_map('intval', $summary)),
        0,
        round(memory_get_usage(true) / 1048576, 2)
    );

    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    WorkerHeartbeat::record(
        $workerName,
        'campaign_cleanup',
        'error',
        $e->getMessage(),
        0,
        1,
        round(memory_get_usage(true) / 1048576, 2)
    );

    throw $e;
}
