<?php

namespace App\Controller\Pages;

use App\Session\User as SessionLogin;
use App\Model\Entity\UserAuthentication;

class HeartbeatController extends ViewComponents
{
    private const int SESSION_EXPIRATION = 3600; // 1 hora

    public static function updateSession($request): void
    {
        header('Content-Type: application/json; charset=utf-8');

        SessionLogin::ensureSessionStarted();

        if (!isset($_SESSION['user'])) {
            http_response_code(401);
            self::jsonResponse([
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ]);
        }

        $lastActivity = $_SESSION['user']['timeSession'] ?? 0;
        $now = time();

        if (($now - $lastActivity) > self::SESSION_EXPIRATION) {
            // Sessão expirada
            $obStatusUser = new UserAuthentication();
            $obStatusUser->email = $_SESSION['user']['email'];
            $obStatusUser->status = 'n';
            $obStatusUser->updateStatusUser();

            SessionLogin::logout();

            http_response_code(419);
            self::jsonResponse([
                'status' => 419,
                'message' => 'Sessão expirada!'
            ]);
        }

        // Sessão válida, renova
        $_SESSION['user']['timeSession'] = $now;
        UserAuthentication::updateLastActivity(
            $_SESSION['user']['email'],
            date('Y-m-d H:i:s')
        );

        self::jsonResponse([
            'status' => 'OK',
            'message' => 'Sessão atualizada com sucesso.'
        ]);
    }
}
