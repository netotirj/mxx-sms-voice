<?php

require __DIR__ . '/vendor/autoload.php';

$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');
$dbPort = getenv('DB_PORT');

if ($dbHost === false || $dbName === false || $dbUser === false || $dbPass === false || $dbPort === false) {
    $dotenv = __DIR__ . '/.env';
    if (is_file($dotenv)) {
        \WilliamCosta\DotEnv\Environment::load(__DIR__);
    }
}

\WilliamCosta\DatabaseManager\Database::config(
    getenv('DB_HOST'),
    getenv('DB_NAME'),
    getenv('DB_USER'),
    getenv('DB_PASS'),
    (int)getenv('DB_PORT')
);

$scope = strtolower(trim((string)($argv[1] ?? 'all')));
$limit = max(1, (int)($argv[2] ?? 200));

$service = \App\Service\FinancialReconciliationService::class;

$report = match ($scope) {
    'balance' => $service::auditBalanceConsistency($limit),
    'voice' => $service::auditVoiceBilling($limit),
    'sms' => $service::auditSmsBilling($limit),
    'whatsapp' => $service::auditWhatsAppBilling($limit),
    'active_calls' => $service::auditOrphanActiveCalls($limit),
    'all' => [
        'generated_at' => date('Y-m-d H:i:s'),
        'balance' => $service::auditBalanceConsistency($limit),
        'voice' => $service::auditVoiceBilling($limit),
        'sms' => $service::auditSmsBilling($limit),
        'whatsapp' => $service::auditWhatsAppBilling($limit),
        'active_calls' => $service::auditOrphanActiveCalls($limit),
    ],
    default => [
        'error' => 'Escopo invalido. Use: all, balance, voice, sms, whatsapp, active_calls',
    ],
};

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
