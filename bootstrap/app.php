<?php
require __DIR__.'/../vendor/autoload.php';

use App\Utils\View;
use WilliamCosta\DotEnv\Environment;
use WilliamCosta\DatabaseManager\Database;
use App\Http\Middleware\Queue as MiddlewareQueue;

// Carrega variáveis de ambiente
Environment::load(__DIR__.'/../');

// Define URL global com fallback
define('URL', getenv('URL') ?: 'http://localhost');

// Configuração do banco de dados
Database::config(
    getenv('DB_HOST'),
    getenv('DB_NAME'),
    getenv('DB_USER'),
    getenv('DB_PASS'),
    getenv('DB_PORT')
);

// Inicializa variáveis globais nas views
View::init([
    'URL' => URL
]);

// Mapeamento de middlewares disponíveis
MiddlewareQueue::setMap([
    'maintenance'            => \App\Http\Middleware\Maintenance::class,
    'require-session-logout' => \App\Http\Middleware\RequireSessionLogout::class,
    'require-session-login'  => \App\Http\Middleware\RequireSessionLogin::class,
    'auto-logout-inactive'   => \App\Http\Middleware\AutoLogoutInactiveMiddleware::class,
    'require-permissions-tenancies' => \App\Http\Middleware\PermissionMiddleware::class,
]);

// Middlewares padrão
MiddlewareQueue::setDefault([
    'maintenance',
    'auto-logout-inactive',
]);
