<?php

namespace App\Http\Middleware;

use App\Session\User as SessionLogin;
use App\Http\Response;
use App\Service\ModuleAccessMap;
use App\Service\PlanRuntimeService;
use App\Service\PermissionResolver;
use App\Service\PerformanceTelemetry;
use App\Utils\TenancyHelper;
use Closure;
use Throwable;

class PermissionMiddleware
{
    /**
     * Manipula a verificação de permissões e planos
     */
    public function handle($request, Closure $next)
    {
        // 1️⃣ Usuário logado
        $obUser     = SessionLogin::getLogged();
        $userId     = $obUser['id']          ?? null;
        $tenancyId  = $obUser['tenancy_id']  ?? null;

        $baseUrl = $this->resolveCurrentBaseUrl();

        if (!$userId || !$tenancyId) {
            return new Response(401, ['error' => 'Usuário não autenticado']);
        }

        // 2️⃣ Se for SUPER ADMIN → ignora todas as restrições
        if (TenancyHelper::isSuperAdmin($obUser)) {
            return $next($request);
        }

        // 3️⃣ Nome da rota atual
        $routeName = $request->getRouteName();
        if (!$routeName) {
            return new Response(400, ['error' => 'Rota não informada']);
        }

        // Normaliza rota (remove IDs numéricos)
        $routeName = $this->normalizeRouteName((string)preg_replace('/\/\d+/', '/{id}', $routeName));
        $routeContext = ModuleAccessMap::routeContext($routeName);

        // 4️⃣ Verifica permissão padrão do usuário (ACL básica)
        $routeAccess = $this->canAccessRoute($obUser, $routeName);
        if (empty($routeAccess['allowed'])) {
            PerformanceTelemetry::log('permission_middleware.denied', [
                'user_id' => $userId,
                'tenancy_id' => $tenancyId,
                'route' => $routeName,
                'reason' => 'route_permission',
            ]);
            return $this->denyRequest(
                $request,
                $baseUrl,
                (string)($routeAccess['message'] ?? ModuleAccessMap::routePermissionDeniedMessage()),
                $routeName,
                [
                    'denial_reason' => 'route_permission',
                    'module_key' => (string)($routeContext['module_key'] ?? ''),
                    'module_label' => (string)($routeContext['module_label'] ?? ''),
                ]
            );
        }

        // 5️⃣ Verifica se a rota é restrita por plano contratado
        $planAccess = $this->canAccessPlanModule((string)$tenancyId, $routeName);
        if (empty($planAccess['allowed'])) {
            return $this->denyRequest(
                $request,
                $baseUrl,
                (string)($planAccess['message'] ?? ModuleAccessMap::planDeniedMessageForRoute($routeName)),
                $routeName,
                [
                    'denial_reason' => 'plan_module',
                    'module_key' => (string)($planAccess['module_key'] ?? ''),
                    'module_label' => (string)($planAccess['module_label'] ?? ''),
                    'missing_features' => (array)($planAccess['missing_features'] ?? []),
                ]
            );
        }

        $this->releaseSessionLockForReadRequests($request);

        // 🔓 Tudo OK → segue para o controlador
        return $next($request);
    }

    private function resolvePlanFeatureKeys(string $routeName): array
    {
        return ModuleAccessMap::featureKeysForRoute($routeName);
    }

    private function canAccessRoute(array $user, string $routeName): array
    {
        if (!PermissionResolver::userCanAccessRoute($user, $routeName)) {
            return [
                'allowed' => false,
                'message' => ModuleAccessMap::routePermissionDeniedMessage(),
            ];
        }

        return ['allowed' => true];
    }

    private function canAccessPlanModule(string $tenancyId, string $routeName): array
    {
        $routeContext = ModuleAccessMap::routeContext($routeName);
        $featureKeys = $this->resolvePlanFeatureKeys($routeName);

        if ($featureKeys === []) {
            return [
                'allowed' => true,
                'module_key' => (string)($routeContext['module_key'] ?? ''),
                'module_label' => (string)($routeContext['module_label'] ?? ''),
            ];
        }

        foreach ($featureKeys as $featureKey) {
            $featureAccess = PlanRuntimeService::assertCanUseFeature($tenancyId, $featureKey);
            if (empty($featureAccess['allowed'])) {
                return [
                    'allowed' => false,
                    'message' => ModuleAccessMap::planDeniedMessageForRoute($routeName),
                    'module_key' => (string)($routeContext['module_key'] ?? ''),
                    'module_label' => (string)($routeContext['module_label'] ?? ''),
                    'missing_features' => [$featureKey],
                ];
            }
        }

        return [
            'allowed' => true,
            'module_key' => (string)($routeContext['module_key'] ?? ''),
            'module_label' => (string)($routeContext['module_label'] ?? ''),
        ];
    }

    /**
     * Método auxiliar para disparar o JavaScript de erro e redirecionar
     */
    /**
     * Método auxiliar para disparar o JavaScript de erro e redirecionar
     */
    private function redirectWithFlash($baseUrl, $message)
    {
        $targetUrl = rtrim((string)$baseUrl, '/') . '/dashboard?flash_error=' . rawurlencode((string)$message);
        header('Location: ' . $targetUrl, true, 302);
        exit;
    }

    private function denyRequest($request, string $baseUrl, string $message, string $routeName, array $meta = []): Response
    {
        PerformanceTelemetry::log('permission_middleware.denied_response', [
            'route' => $routeName,
            'mode' => $this->requestMode($request),
            'reason' => (string)($meta['denial_reason'] ?? ''),
            'module_key' => (string)($meta['module_key'] ?? ''),
        ]);

        if ($this->isEventStreamRequest($request)) {
            return new Response(
                403,
                "event: error\n" .
                'data: ' . json_encode([
                    'status' => 403,
                    'message' => $message,
                    'route' => $routeName,
                    'denial_reason' => (string)($meta['denial_reason'] ?? ''),
                    'module_key' => (string)($meta['module_key'] ?? ''),
                    'module_label' => (string)($meta['module_label'] ?? ''),
                    'missing_features' => (array)($meta['missing_features'] ?? []),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n",
                'text/event-stream'
            );
        }

        if ($this->expectsJson($request)) {
            return new Response(403, [
                'status' => 403,
                'success' => false,
                'message' => $message,
                'route' => $routeName,
                'denial_reason' => (string)($meta['denial_reason'] ?? ''),
                'module_key' => (string)($meta['module_key'] ?? ''),
                'module_label' => (string)($meta['module_label'] ?? ''),
                'missing_features' => (array)($meta['missing_features'] ?? []),
            ], 'application/json');
        }

        if ($routeName === '/dashboard') {
            return new Response(403, '<h1>403</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>', 'text/html');
        }

        $this->redirectWithFlash($baseUrl, $message);
    }

    private function normalizeRouteName(string $routeName): string
    {
        $routeName = '/' . trim($routeName, '/');
        return preg_replace('#/+#', '/', $routeName) ?: '/';
    }

    private function expectsJson($request): bool
    {
        $headers = $this->requestHeaders($request);
        $accept = strtolower((string)($headers['Accept'] ?? $headers['accept'] ?? ''));
        $requestedWith = strtolower((string)($headers['X-Requested-With'] ?? $headers['x-requested-with'] ?? ''));
        $contentType = strtolower((string)($headers['Content-Type'] ?? $headers['content-type'] ?? ''));

        if ($requestedWith === 'xmlhttprequest') {
            return true;
        }

        return str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json');
    }

    private function isEventStreamRequest($request): bool
    {
        $headers = $this->requestHeaders($request);
        $accept = strtolower((string)($headers['Accept'] ?? $headers['accept'] ?? ''));
        return str_contains($accept, 'text/event-stream');
    }

    private function requestHeaders($request): array
    {
        try {
            if (is_object($request) && method_exists($request, 'getHeaders')) {
                $headers = $request->getHeaders();
                return is_array($headers) ? $headers : [];
            }
        } catch (Throwable) {
        }

        return function_exists('getallheaders') ? (getallheaders() ?: []) : [];
    }

    private function requestMode($request): string
    {
        if ($this->isEventStreamRequest($request)) {
            return 'event-stream';
        }

        if ($this->expectsJson($request)) {
            return 'json';
        }

        return 'redirect';
    }

    private function resolveCurrentBaseUrl(): string
    {
        $https = $_SERVER['HTTPS'] ?? '';
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $protocol = ($https === 'on' || $https === '1' || strtolower((string)$forwardedProto) === 'https')
            ? 'https'
            : 'http';

        $host = $_SERVER['HTTP_X_FORWARDED_HOST']
            ?? $_SERVER['HTTP_HOST']
            ?? $_SERVER['SERVER_NAME']
            ?? 'localhost';

        $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $basePath = str_replace('\\', '/', dirname($scriptName));
        $basePath = $basePath === '/' || $basePath === '.' ? '' : rtrim($basePath, '/');

        return $protocol . '://' . $host . $basePath;
    }

    private function releaseSessionLockForReadRequests($request): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $method = 'GET';
        try {
            if (is_object($request) && method_exists($request, 'getHttpMethod')) {
                $method = strtoupper((string)$request->getHttpMethod());
            }
        } catch (Throwable) {
        }

        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}
