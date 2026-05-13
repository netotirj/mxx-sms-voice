<?php

namespace App\Http\Middleware;

use App\Http\Response;
use App\Session\User as SessionLogin;
use Throwable;

class RequireSessionLogin
{
    public function handle($request, $next)
    {
        if (!SessionLogin::isLogged()) {
            if ($this->isEventStreamRequest($request)) {
                return new Response(
                    401,
                    "event: auth\n" .
                    'data: ' . json_encode([
                        'status' => 401,
                        'message' => 'Usuário não autenticado.',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n",
                    'text/event-stream'
                );
            }

            if ($this->expectsJson($request)) {
                return new Response(401, [
                    'status' => 401,
                    'success' => false,
                    'message' => 'Usuário não autenticado.',
                ], 'application/json');
            }

            $request->getRouter()->redirect('/login');
        }

        return $next($request);
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
}
