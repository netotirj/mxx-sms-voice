<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Service\SipMonitorSessionService;
use App\Session\User as SessionUser;
use App\Utils\View;

class SipMonitor extends ViewComponents
{
    public static function index(): Response|string
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!self::canManage($user)) {
            return new Response(403, 'Sem permissão para acessar o Monitor SIP.');
        }

        SipMonitorSessionService::ensureRouteCatalog();

        $content = View::render('/sip-monitor/index', []);
        return parent::getComponentsUsers('Maxx Solutions - Monitor SIP / SNGREP', $content);
    }

    public static function status($request = null): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!self::canManage($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        SipMonitorSessionService::ensureRouteCatalog();
        $forceRefresh = false;
        try {
            if (is_object($request) && method_exists($request, 'getQueryParams')) {
                $params = (array)$request->getQueryParams();
                $forceRefresh = !empty($params['refresh']);
            }
        } catch (\Throwable) {
        }

        return self::json(200, [
            'success' => true,
            'data' => SipMonitorSessionService::statusForUser($user, $forceRefresh),
        ]);
    }

    public static function testConnection(): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!self::canManage($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        try {
            return self::json(200, [
                'success' => true,
                'message' => 'Teste de conexão executado.',
                'data' => SipMonitorSessionService::testConnection(),
            ]);
        } catch (\Throwable $e) {
            return self::json(422, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public static function start($request): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!self::canManage($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        try {
            $payload = json_decode(file_get_contents('php://input'), true) ?: [];

            return self::json(200, [
                'success' => true,
                'message' => 'Captura SIP iniciada com sucesso.',
                'data' => SipMonitorSessionService::start($user, $payload),
            ]);
        } catch (\Throwable $e) {
            return self::json(422, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public static function stop($request): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!self::canManage($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        try {
            $payload = json_decode(file_get_contents('php://input'), true) ?: [];
            $sessionId = (int)($payload['session_id'] ?? 0);
            if ($sessionId <= 0) {
                throw new \RuntimeException('Sessão inválida.');
            }

            return self::json(200, [
                'success' => true,
                'message' => 'Captura SIP encerrada.',
                'data' => SipMonitorSessionService::stop($sessionId, $user, 'stopped'),
            ]);
        } catch (\Throwable $e) {
            return self::json(422, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public static function stream($request): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!self::canManage($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        try {
            $sessionId = (int)($request->getQueryParams()['session_id'] ?? 0);
            if ($sessionId <= 0) {
                throw new \RuntimeException('Sessão inválida.');
            }

            return self::json(200, [
                'success' => true,
                'data' => SipMonitorSessionService::stream($sessionId),
            ]);
        } catch (\Throwable $e) {
            return self::json(422, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public static function emergencyStop(): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        try {
            return self::json(200, SipMonitorSessionService::emergencyStop($user));
        } catch (\Throwable $e) {
            return self::json(422, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function canManage(array $user): bool
    {
        $role = strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));
        return in_array($role, ['super_admin', 'admin', 'developer', 'support_l1', 'support_l2'], true);
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
