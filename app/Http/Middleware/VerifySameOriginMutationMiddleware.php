<?php

namespace App\Http\Middleware;

use App\Http\Response;
use App\Session\User as SessionLogin;
use Closure;

class VerifySameOriginMutationMiddleware
{
    public function handle($request, Closure $next)
    {
        if (PHP_SAPI === 'cli') {
            return $next($request);
        }

        $method = strtoupper((string)($request->getHttpMethod() ?? 'GET'));
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        if (!SessionLogin::isLogged()) {
            return $next($request);
        }

        $headers = is_object($request) && method_exists($request, 'getHeaders')
            ? (array)$request->getHeaders()
            : [];

        $origin = trim((string)($headers['Origin'] ?? $headers['origin'] ?? ''));
        $referer = trim((string)($headers['Referer'] ?? $headers['referer'] ?? ''));
        $secFetchSite = strtolower(trim((string)($headers['Sec-Fetch-Site'] ?? $headers['sec-fetch-site'] ?? '')));
        $currentHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? parse_url((string)(getenv('URL') ?: ''), PHP_URL_HOST) ?: ''));
        $currentHost = preg_replace('/:\d+$/', '', $currentHost);

        if ($currentHost === '') {
            return $next($request);
        }

        if ($origin !== '') {
            $originHost = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?: ''));
            $originHost = preg_replace('/:\d+$/', '', $originHost);
            if ($originHost === '' || $originHost !== $currentHost) {
                return new Response(403, [
                    'status' => 403,
                    'success' => false,
                    'message' => 'Origem da requisicao nao autorizada.',
                ], 'application/json');
            }

            return $next($request);
        }

        if ($referer !== '') {
            $refererHost = strtolower((string)(parse_url($referer, PHP_URL_HOST) ?: ''));
            $refererHost = preg_replace('/:\d+$/', '', $refererHost);
            if ($refererHost === '' || $refererHost !== $currentHost) {
                return new Response(403, [
                    'status' => 403,
                    'success' => false,
                    'message' => 'Referer da requisicao nao autorizado.',
                ], 'application/json');
            }

            return $next($request);
        }

        if ($secFetchSite !== '' && !in_array($secFetchSite, ['same-origin', 'same-site', 'none'], true)) {
            return new Response(403, [
                'status' => 403,
                'success' => false,
                'message' => 'Contexto da requisicao nao autorizado.',
            ], 'application/json');
        }

        return $next($request);
    }
}
