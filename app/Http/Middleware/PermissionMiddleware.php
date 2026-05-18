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
        $routeName = preg_replace('/\/\d+/', '/{id}', $routeName);

        // 4️⃣ Verifica permissão padrão do usuário (ACL básica)
        if (!PermissionResolver::userCanAccessRoute($obUser, $routeName)) {
            PerformanceTelemetry::log('permission_middleware.denied', [
                'user_id' => $userId,
                'tenancy_id' => $tenancyId,
                'route' => $routeName,
            ]);
            return $this->denyRequest($request, $baseUrl, 'Permissão de acesso negada!', $routeName);
        }

        // 5️⃣ Verifica se a rota é restrita por plano contratado
        $featureKeys = $this->resolvePlanFeatureKeys($routeName);
        foreach ($featureKeys as $featureKey) {
            $featureAccess = PlanRuntimeService::assertCanUseFeature((string)$tenancyId, $featureKey);
            if (empty($featureAccess['allowed'])) {
                return $this->denyRequest($request, $baseUrl, (string)($featureAccess['message'] ?? 'Este modulo nao esta disponivel no plano contratado.'), $routeName);
            }
        }

        $this->releaseSessionLockForReadRequests($request);

        // 🔓 Tudo OK → segue para o controlador
        return $next($request);
    }

    private function resolvePlanFeatureKeys(string $routeName): array
    {
        return ModuleAccessMap::featureKeysForRoute($routeName);
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

    private function denyRequest($request, string $baseUrl, string $message, string $routeName): Response
    {
        PerformanceTelemetry::log('permission_middleware.denied_response', [
            'route' => $routeName,
            'mode' => $this->requestMode($request),
        ]);

        if ($this->isEventStreamRequest($request)) {
            return new Response(
                403,
                "event: error\n" .
                'data: ' . json_encode([
                    'status' => 403,
                    'message' => $message,
                    'route' => $routeName,
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
            ], 'application/json');
        }

        if ($routeName === '/dashboard') {
            return new Response(403, '<h1>403</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>', 'text/html');
        }

        $this->redirectWithFlash($baseUrl, $message);
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
