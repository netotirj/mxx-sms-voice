<?php

namespace App\Controller\Pages;

use App\Model\Entity\NotificationsUsers as EntityNotifications;
use App\Session\User as SessionLogin;
use App\Http\Response;
use JetBrains\PhpStorm\NoReturn;

class UsersNotifications
{
    /**
     * SSE para notificações do usuário logado
     * @param $request
     * @return void
     */

    #[NoReturn] public static function getNotifications($request): void
{
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // 🔹 desativa buffering no Nginx

        // Apache apenas, no Nginx isso não existe
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        ob_implicit_flush(true);
    }


    // Recupera usuário logado
    $obUser = SessionLogin::getLogged();

    if (!$obUser) {
        $payload = [
            'status' => 401,
            'message' => 'Usuário não autenticado'
        ];

        echo "event: auth\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        ob_flush();
        flush();
        exit;
    }

    $tenancyId = $obUser['tenancy_id'];
    $userId = $obUser['id'];

    $qtdNew = EntityNotifications::countNewNotifications($tenancyId, $userId);

    $notifications = EntityNotifications::getNotifications(
        "tenancy_id = '{$tenancyId}' AND user_id = '{$userId}'",
        'created_at DESC',
        '5',
        ['id', 'title', 'message', 'type', 'created_at', 'read_at']
    );

    $payload = [
        'status' => 200,
        'qtd_new' => $qtdNew,
        'notifications' => $notifications
    ];

    // Envia SSE
    echo "event: notifications\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    ob_flush();
    flush();

    // NÃO retorna nada para evitar conflito de headers
    exit;
}


    public static function markRead($request): Response
    {        // Verifica autenticação
        $obUser = SessionLogin::getLogged();
        if (!$obUser) {
            return new Response(401, ['status' => 401, 'message' => 'Usuário não autenticado'], 'application/json');
        }

         $inputData = json_decode(file_get_contents("php://input"), true);
        $notificationId = $inputData['id'] ?? null;

        if (!$notificationId) {
            return new Response(400, ['status' => 400, 'message' => 'ID de notificação não fornecido'], 'application/json');
        }

        // Marca como lida se pertencer ao usuário e tenancy logados
        $updated = EntityNotifications::markNotificationAsRead(
            $notificationId,
            $obUser['id'],
            $obUser['tenancy_id']
        );

        if ($updated) {
            return new Response(200, ['status' => 200, 'message' => 'Notificação marcada como lida'], 'application/json');
        } else {
            return new Response(404, ['status' => 404, 'message' => 'Notificação não encontrada ou não pertence ao usuário'], 'application/json');
        }
    }

    /**
     * Rota: POST /notifications/mark-all-read
     */
    public static function markAllAsRead($request): Response
    {
        $obUser = SessionLogin::getLogged();
        if (!$obUser) return new Response(401, ['message' => 'Não autorizado'], 'application/json');

        // Chama o Model acima
        $success = EntityNotifications::markAllNotificationsAsRead(
            (int)$obUser['id'],
            (string)$obUser['tenancy_id']
        );

        return new Response(200, ['success' => $success], 'application/json');
    }

    /**
     * Rota: POST /notifications/delete-all
     */
    public static function deleteAllNotifications($request): Response
    {
        $obUser = SessionLogin::getLogged();
        if (!$obUser) return new Response(401, ['message' => 'Não autorizado'], 'application/json');

        // Chama o Model acima
        $success = EntityNotifications::deleteAllNotificationsByUser(
            (int)$obUser['id'],
            (string)$obUser['tenancy_id']
        );

        return new Response(200, ['success' => $success], 'application/json');
    }
}
