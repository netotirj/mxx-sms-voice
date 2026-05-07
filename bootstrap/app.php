<?php
require __DIR__.'/../vendor/autoload.php';

use App\Session\User as SessionUser;
use App\Utils\View;
use WilliamCosta\DotEnv\Environment;
use WilliamCosta\DatabaseManager\Database;
use App\Http\Middleware\Queue as MiddlewareQueue;

// 1. Carrega o Ambiente
Environment::load(__DIR__.'/../');

// 2. Define a URL
define('URL', getenv('URL') ?: 'http://localhost');
define('VIEW_URL', buildCurrentViewUrl(URL));

// 3. CONFIGURA O BANCO PRIMEIRO (Obrigatório antes de ler sessão) 🚀
Database::config(
    getenv('DB_HOST'),
    getenv('DB_NAME'),
    getenv('DB_USER'),
    getenv('DB_PASS'),
    getenv('DB_PORT')
);

// 4. Agora sim, busca o usuário logado
$obUser = SessionUser::getLogged();

if ($obUser) {
    // Calibra o fuso do PHP
    $timezone = $obUser['timezone'] ?? 'America/Sao_Paulo';
    date_default_timezone_set($timezone);

    // 🌍 CALIBRA O FUSO DO MYSQL (Para o BI e as Queries baterem com o seu relógio)
    $offset = date('P');
    (new Database())->execute("SET time_zone = '$offset'");
}

// 5. Inicializa Views e Middlewares
$asteriskWsHost = getenv('ASTERISK_WS_HOST')
    ?: (getenv('ASTERISK_PUBLIC_DOMAIN') ?: 'mxx-sip.maxxsolutions.com.br');

if (preg_match('#^https?://#i', $asteriskWsHost)) {
    $asteriskWsHost = parse_url($asteriskWsHost, PHP_URL_HOST) ?: $asteriskWsHost;
}

View::init([
    'URL' => VIEW_URL,
    'SOCIAL_URL' => URL,
    'ASTERISK_WS_HOST' => $asteriskWsHost,
    'ASTERISK_WS_PORT' => getenv('ASTERISK_WS_PORT') ?: '8089',
    'SUPPORT_WHATSAPP_PHONE' => getenv('SUPPORT_WHATSAPP_PHONE') ?: '5568992024512',
    'SUPPORT_TICKET_URL' => VIEW_URL . '/support',
    'SUPPORT_TICKETS_URL' => VIEW_URL . '/support',
]);

MiddlewareQueue::setMap([
    'maintenance'            => \App\Http\Middleware\Maintenance::class,
    'require-session-logout' => \App\Http\Middleware\RequireSessionLogout::class,
    'require-session-login'  => \App\Http\Middleware\RequireSessionLogin::class,
    'auto-logout-inactive'   => \App\Http\Middleware\AutoLogoutInactiveMiddleware::class,
    'require-permissions-tenancies' => \App\Http\Middleware\PermissionMiddleware::class,
]);

MiddlewareQueue::setDefault([
    'maintenance',
    'auto-logout-inactive',
]);

function buildCurrentViewUrl(string $fallbackUrl): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return rtrim($fallbackUrl, '/');
    }

    $fallbackPath = parse_url($fallbackUrl, PHP_URL_PATH) ?: '';
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    );

    $scheme = $isHttps ? 'https' : 'http';

    return rtrim($scheme . '://' . $host . $fallbackPath, '/');
}
