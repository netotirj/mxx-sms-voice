<?php

namespace App\Http\Middleware;

use App\Session\User as SessionLogin;
use App\Http\Request;
use Closure;

/**
 * Middleware que impede acesso a rotas públicas caso o usuário esteja logado.
 */
class RequireSessionLogout
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (SessionLogin::isLogged()) {
            $request->getRouter()->redirect('/dashboard');
            exit;
        }

        return $next($request);
    }
}
