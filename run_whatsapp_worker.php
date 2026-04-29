<?php

require __DIR__ . '/bootstrap/cli.php';

use App\Service\WhatsAppOutboxWorker;

ini_set('output_buffering', 'off');
ini_set('implicit_flush', 1);
ob_implicit_flush(true);

$limit = isset($argv[1]) ? max(1, (int)$argv[1]) : 50;
$cancelCategory = isset($argv[2]) && $argv[2] !== '' ? strtoupper((string)$argv[2]) : null;

$worker = new WhatsAppOutboxWorker();
$summary = $worker->runOnce($limit, $cancelCategory);

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
