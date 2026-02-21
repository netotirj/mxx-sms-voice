<?php

namespace App\Http\Middleware;

use App\Session\User as SessionLogin;
use App\Model\Entity\UserAuthentication;
use Closure;

class AutoLogoutInactiveMiddleware
{
    /**
     * Tempo máximo de inatividade permitido em segundos.
     * Ajuste conforme desejado (ex: 3600 = 1 hora).
     */
    private const SESSION_EXPIRATION = 3600;

    /**
     * Executa o middleware.
     *
     * @param $request
     * @param Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (SessionLogin::isLogged()) {
            $userData = SessionLogin::getLogged();

            if ($userData) {
                $obUser = UserAuthentication::getUserByEmail($userData['email']);

                if ($obUser && $obUser->last_activity) {
                    $lastActivity = strtotime($obUser->last_activity);
                    $now = time();

                    if ($now - $lastActivity > self::SESSION_EXPIRATION) {
                        // Tempo excedido: logout seguro
                        UserAuthentication::setStatus($obUser->email, 'n');
                        SessionLogin::logout();
                        $request->getRouter()->redirect('/login');
                    } else {
                        // Atualiza last_activity no banco para manter ativo
                        UserAuthentication::updateLastActivity($obUser->email, date('Y-m-d H:i:s'));
                    }
                }
            }
        }

        return $next($request);
    }
}
