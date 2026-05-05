<?php

require __DIR__ . '/bootstrap/cli.php';

use App\Service\WhatsAppDynamicPricing;

if (in_array('--debug', $argv, true) || in_array('debug', $argv, true)) {
    echo json_encode(
        WhatsAppDynamicPricing::diagnoseExchangeApis(),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    ) . PHP_EOL;
    exit;
}

$manualRate = isset($argv[1]) && is_numeric($argv[1]) ? (float)$argv[1] : null;
$source = $manualRate !== null ? 'manual_cli' : 'exchange_api';

$result = WhatsAppDynamicPricing::updateUsdRate($manualRate, $source);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
