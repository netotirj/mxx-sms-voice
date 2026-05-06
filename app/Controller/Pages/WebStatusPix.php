<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Service\PixService;

class WebStatusPix
{
    public static function getCallbackAsaas($request): Response
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $secretToken = (string)getenv('ASAAS_WEBHOOK_SECRET');
        $receivedToken = (string)($headers['Asaas-Access-Token'] ?? $headers['asaas-access-token'] ?? '');

        if ($secretToken === '' || !hash_equals($secretToken, $receivedToken)) {
            PixService::log('webhook_unauthorized', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'has_token' => $receivedToken !== '',
            ]);

            return new Response(403, PixService::error('Webhook nao autorizado.', 403), 'application/json');
        }

        $body = file_get_contents('php://input') ?: '';
        $bodyArray = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($bodyArray)) {
            PixService::log('webhook_invalid_json', [
                'json_error' => json_last_error_msg(),
            ]);

            return new Response(400, PixService::error('JSON do webhook invalido.', 400), 'application/json');
        }

        $event = PixService::normalizeEvent($bodyArray['event'] ?? null);
        $payment = $bodyArray['payment'] ?? null;

        if ($event === '' || !is_array($payment)) {
            return new Response(400, PixService::error('Payload do webhook incompleto.', 400), 'application/json');
        }

        $result = PixService::processWebhookPayment($event, $payment);
        $status = (int)($result['status'] ?? ($result['success'] ? 200 : 400));

        return new Response($status, $result, 'application/json');
    }
}
