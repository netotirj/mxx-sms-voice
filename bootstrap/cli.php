<?php

require __DIR__ . '/../vendor/autoload.php';

use WilliamCosta\DotEnv\Environment;
use WilliamCosta\DatabaseManager\Database;

Environment::load(__DIR__ . '/../');

Database::config(
    getenv('DB_HOST'),
    getenv('DB_NAME'),
    getenv('DB_USER'),
    getenv('DB_PASS'),
    (int)getenv('DB_PORT')
);

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');

$offset = date('P');
(new Database())->execute("SET time_zone = '{$offset}'");
