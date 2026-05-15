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

$limit = max(1, (int)($argv[1] ?? 50));

$report = \App\Service\AsteriskBalanceSyncService::processPending($limit);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
