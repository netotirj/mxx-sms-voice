<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Model\Entity\ServiceMonitorAudit;
use App\Service\ServicesMonitorService;
use App\Session\User as SessionUser;
use App\Utils\View;

class ServicesMonitor extends ViewComponents
{
    public static function getComponentsServicesMonitor(): Response|string
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!self::canManage($user)) {
            return new Response(403, 'Sem permissão para acessar o monitoramento de serviços.');
        }

        ServicesMonitorService::ensureRouteCatalog();

        $content = View::render('/services-monitor/index', []);
        return parent::getComponentsUsers('Maxx Solutions - Monitoramento de Serviços', $content);
    }

    public static function status($request): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!self::canManage($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        ServicesMonitorService::ensureRouteCatalog();

        return self::json(200, [
            'success' => true,
            'data' => ServicesMonitorService::statusSnapshot(),
        ]);
    }

    public static function logs($request): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!self::canManage($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        ServicesMonitorService::ensureRouteCatalog();

        try {
            $service = trim((string)($request->getQueryParams()['service'] ?? ''));
            $lines = (int)($request->getQueryParams()['lines'] ?? 100);
            $logs = ServicesMonitorService::recentLogs($service, $lines);

            ServiceMonitorAudit::record(
                $user,
                'view_logs',
                $service,
                $_SERVER['REMOTE_ADDR'] ?? null,
                ['lines' => $lines]
            );

            return self::json(200, [
                'success' => true,
                'data' => $logs,
            ]);
        } catch (\Throwable $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private static function canManage(array $user): bool
    {
        $role = strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));
        return in_array($role, ['super_admin', 'admin', 'developer'], true);
    }

    private static function requireUser(): array|Response
    {
        $user = SessionUser::getLogged();
        if (!$user) {
            return self::json(401, ['success' => false, 'message' => 'Usuário não autenticado.']);
        }

        return $user;
    }

    private static function json(int $status, array $payload): Response
    {
        return new Response($status, $payload, 'application/json');
    }
}
