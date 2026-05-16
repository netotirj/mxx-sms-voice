<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use App\Service\ManualTopupService;

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionDir = __DIR__ . '/../.tmp-sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0775, true);
    }
    if (is_dir($sessionDir)) {
        session_save_path($sessionDir);
    }
    session_start();
}

$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$superAdmin = ['id' => 1, 'tenancy_id' => 'root', 'user_function' => 'super_admin'];
$adminA = ['id' => 10, 'tenancy_id' => 'tenant-a', 'user_function' => 'admin'];
$adminB = ['id' => 11, 'tenancy_id' => 'tenant-b', 'user_function' => 'admin'];
$resellerA = ['id' => 20, 'tenancy_id' => 'tenant-a', 'user_function' => 'reseller'];
$resellerB = ['id' => 21, 'tenancy_id' => 'tenant-b', 'user_function' => 'reseller'];
$operatorA = ['id' => 30, 'tenancy_id' => 'tenant-a', 'user_function' => 'operator'];
$adminActor = ['id' => 2, 'tenancy_id' => 'tenant-a', 'user_function' => 'admin'];
$resellerActor = ['id' => 3, 'tenancy_id' => 'tenant-a', 'user_function' => 'reseller'];

$allowed = ManualTopupService::authorizeTopup($superAdmin, $adminA);
$assert(!empty($allowed['allowed']) && $allowed['flow'] === 'super_admin_admin', 'Super admin deveria conseguir recarregar admin.');

$deniedAdminToAdmin = ManualTopupService::authorizeTopup($adminActor, $adminB);
$assert(empty($deniedAdminToAdmin['allowed']), 'Admin não deveria conseguir recarregar admin.');

$allowedAdminToReseller = ManualTopupService::authorizeTopup($adminActor, $resellerA);
$assert(!empty($allowedAdminToReseller['allowed']) && $allowedAdminToReseller['flow'] === 'reseller', 'Admin deveria continuar conseguindo recarregar reseller da própria tenancy.');

$deniedAdminCrossTenantReseller = ManualTopupService::authorizeTopup($adminActor, $resellerB);
$assert(empty($deniedAdminCrossTenantReseller['allowed']), 'Admin não deveria recarregar reseller de outra tenancy.');

$deniedResellerToAdmin = ManualTopupService::authorizeTopup($resellerActor, $adminA);
$assert(empty($deniedResellerToAdmin['allowed']), 'Reseller não deveria conseguir recarregar admin.');

$deniedOperatorToReseller = ManualTopupService::authorizeTopup($operatorA, $resellerA);
$assert(empty($deniedOperatorToReseller['allowed']), 'Operador não deveria conseguir recarregar reseller.');

$csrf = ManualTopupService::issueCsrfToken();
$assert($csrf !== '', 'Token CSRF deveria ser gerado.');
$assert(ManualTopupService::isValidCsrfToken($csrf), 'Token CSRF gerado deveria validar.');
$assert(!ManualTopupService::isValidCsrfToken('token-invalido'), 'Token CSRF inválido não pode validar.');

echo "manual_topup_service_test: OK ({$assertions} assertions)\n";
