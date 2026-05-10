<?php

namespace App\Http\Middleware;

use App\Session\User as SessionLogin;
use App\Model\Entity\UserAuthentication;
use Closure;

class AutoLogoutInactiveMiddleware
{
    /**
     * Tempo máximo de inatividade permitido em segundos.
     * Mantido em sincronia com o heartbeat do frontend.
     */
    private const int SESSION_EXPIRATION = 1800;
    private const int LAST_ACTIVITY_FLUSH_INTERVAL = 60;

    /**
     * Executa o middleware.
     *
     * @param $request
     * @param Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next): mixed
    {
        if (SessionLogin::isLogged()) {
            $userData = SessionLogin::getLogged();

            if ($userData) {
                $now = time();
                $lastSeenAt = (int)($userData['timeSession'] ?? 0);

                if ($lastSeenAt > 0 && ($now - $lastSeenAt) > self::SESSION_EXPIRATION) {
                    UserAuthentication::setStatus((string)$userData['email'], 'n');
                    SessionLogin::logout();
                    $request->getRouter()->redirect('/login');
                }

                $lastDbFlush = (int)($_SESSION['user']['last_activity_flush_at'] ?? 0);
                if (($now - $lastDbFlush) >= self::LAST_ACTIVITY_FLUSH_INTERVAL) {
                    UserAuthentication::updateLastActivity((string)$userData['email'], date('Y-m-d H:i:s', $now));
                    $_SESSION['user']['last_activity_flush_at'] = $now;
                }
            }
        }

        return $next($request);
    }
}
