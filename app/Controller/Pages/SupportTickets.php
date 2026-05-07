<?php

namespace App\Controller\Pages;

use App\Config\TelephonyConfig;
use App\Config\WhatsAppConfig;
use App\Http\Response;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\SupportTicket;
use App\Model\Entity\WhatsAppAccount;
use App\RedisConn;
use App\Session\User as SessionUser;
use App\Service\MetaWhatsAppCloudApi;
use App\Utils\View;
use GuzzleHttp\Client;

class SupportTickets extends ViewComponents
{
    public static function getComponentsSupportTickets(): Response|string
    {
        $user = SessionUser::getLogged();
        if (!$user) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/support/tickets', []);
        return parent::getComponentsUsers('Maxx Solutions - Tickets de Suporte', $content);
    }

    public static function createTicket(): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        $input = self::jsonInput();
        $message = trim((string)($input['message'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string)($input['requester_phone'] ?? ''));

        if (strlen($phone) < 8 || strlen($phone) > 15) {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe seu WhatsApp com DDI e DDD.',
            ]);
        }

        if ($message === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Conte rapidamente o que você precisa.',
            ]);
        }

        try {
            if (!SupportTicket::can($user, 'create')) {
                return self::json(403, [
                    'success' => false,
                    'message' => 'Sem permissão para abrir ticket.',
                ]);
            }

            $id = SupportTicket::create($user, [
                'department' => $input['department'] ?? 'support',
                'requester_name' => $input['requester_name'] ?? null,
                'requester_phone' => $phone,
                'subject' => $input['subject'] ?? null,
                'message' => $message,
            ]);

            SupportTicket::audit(
                $id,
                $user,
                'ticket_opened',
                'status',
                null,
                'open',
                self::clientIp()
            );

            return self::json(201, [
                'success' => true,
                'message' => 'Ticket aberto com sucesso.',
                'id' => $id,
            ]);
        } catch (\Throwable $e) {
            return self::json(500, [
                'success' => false,
                'message' => 'Falha ao abrir ticket de suporte.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function listTickets($request): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        $query = $request->getQueryParams();
        $status = isset($query['status']) ? (string)$query['status'] : null;
        $department = isset($query['department']) ? (string)$query['department'] : null;

        return self::json(200, [
            'success' => true,
            'data' => SupportTicket::listForUser($user, $status, $department),
            'permissions' => SupportTicket::capabilities($user),
        ]);
    }

    public static function listMessages($request, int|string $id): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        $ticket = SupportTicket::getForUser((int)$id, $user);
        if (!$ticket) {
            return self::json(404, [
                'success' => false,
                'message' => 'Ticket não encontrado.',
            ]);
        }

        return self::json(200, [
            'success' => true,
            'ticket' => $ticket,
            'data' => SupportTicket::listMessagesForUser((int)$id, $user),
            'audit' => SupportTicket::listAuditForUser((int)$id, $user),
            'permissions' => [
                'reply' => SupportTicket::can($user, 'reply', $ticket),
                'change_status' => SupportTicket::can($user, 'change_status', $ticket),
                'request_close' => SupportTicket::can($user, 'ticket.request_close', $ticket),
                'update' => SupportTicket::can($user, 'update', $ticket),
                'delete' => SupportTicket::can($user, 'delete', $ticket),
            ],
        ]);
    }

    public static function addMessage($request, int|string $id): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        $ticket = SupportTicket::getForUser((int)$id, $user);
        if (!$ticket) {
            return self::json(404, [
                'success' => false,
                'message' => 'Ticket não encontrado.',
            ]);
        }

        $input = self::jsonInput();
        $message = trim((string)($input['message'] ?? ''));
        if ($message === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Digite a mensagem.',
            ]);
        }

        if (!SupportTicket::can($user, 'reply', $ticket)) {
            return self::json(403, [
                'success' => false,
                'message' => 'Sem permissão para responder este ticket.',
            ]);
        }

        $senderType = SupportTicket::can($user, 'view_all') && (int)$ticket['user_id'] !== (int)$user['id']
            ? 'agent'
            : 'customer';
        $messageId = SupportTicket::addMessage((int)$id, $user, $senderType, $message);
        SupportTicket::audit(
            (int)$id,
            $user,
            $senderType === 'agent' ? 'ticket_replied_by_support' : 'ticket_replied_by_customer',
            'message',
            null,
            mb_substr($message, 0, 160),
            self::clientIp()
        );

        return self::json(201, [
            'success' => true,
            'message' => 'Mensagem registrada.',
            'id' => $messageId,
        ]);
    }

    public static function updateStatus($request, int|string $id): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        $ticket = SupportTicket::getForUser((int)$id, $user);
        if (!$ticket) {
            return self::json(404, [
                'success' => false,
                'message' => 'Ticket não encontrado.',
            ]);
        }

        $input = self::jsonInput();
        if (!SupportTicket::can($user, 'change_status', $ticket)) {
            return self::json(403, [
                'success' => false,
                'message' => 'Sem permissão para alterar status de ticket.',
            ]);
        }

        $oldStatus = (string)($ticket['status'] ?? '');
        $newStatus = SupportTicket::normalizeStatusValue((string)($input['status'] ?? 'open'));
        SupportTicket::updateStatus((int)$id, $newStatus, $user);
        SupportTicket::audit(
            (int)$id,
            $user,
            'status_changed',
            'status',
            $oldStatus,
            $newStatus,
            self::clientIp()
        );

        $explicitAction = match (true) {
            $oldStatus === 'closed' && $newStatus !== 'closed' => 'ticket_reopened',
            $newStatus === 'closed' => 'ticket_closed',
            $newStatus === 'in_progress' => 'ticket_taken_in_charge',
            $newStatus === 'waiting_customer' => 'ticket_waiting_customer',
            $newStatus === 'closure_requested' => 'ticket_marked_closure_requested',
            default => null,
        };

        if ($explicitAction !== null) {
            SupportTicket::audit(
                (int)$id,
                $user,
                $explicitAction,
                'status',
                $oldStatus,
                $newStatus,
                self::clientIp()
            );
        }

        return self::json(200, [
            'success' => true,
            'message' => 'Status atualizado.',
        ]);
    }

    public static function requestClosure($request, int|string $id): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        $ticket = SupportTicket::getForUser((int)$id, $user);
        if (!$ticket) {
            return self::json(404, [
                'success' => false,
                'message' => 'Ticket nao encontrado.',
            ]);
        }

        if (!SupportTicket::can($user, 'ticket.request_close', $ticket)) {
            return self::json(403, [
                'success' => false,
                'message' => 'Sem permissao para solicitar o encerramento deste ticket.',
            ]);
        }

        if (!empty($ticket['closure_requested_at'])) {
            return self::json(409, [
                'success' => false,
                'message' => 'O encerramento deste ticket ja foi solicitado.',
            ]);
        }

        SupportTicket::requestClosure((int)$id, $user);
        SupportTicket::audit(
            (int)$id,
            $user,
            'closure_requested',
            'closure_requested_at',
            null,
            date('Y-m-d H:i:s'),
            self::clientIp()
        );

        return self::json(200, [
            'success' => true,
            'message' => 'Solicitacao de encerramento enviada para analise do suporte.',
        ]);
    }

    public static function diagnostics(): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        $startedAt = microtime(true);
        $redis = self::checkRedis();
        $asterisk = self::checkAsterisk();
        $trunks = self::checkTrunks($user);
        $balance = self::checkBalance($user);
        $smsApi = self::checkSmsApi();
        $whatsAppApi = self::checkWhatsAppApi();
        $serverLatencyMs = max(1, (int)round((microtime(true) - $startedAt) * 1000));

        return self::json(200, [
            'success' => true,
            'checked_at' => date('H:i:s'),
            'summary' => self::buildDiagnosticsSummary($redis, $asterisk, $trunks, $balance, $smsApi, $whatsAppApi, $serverLatencyMs),
            'data' => [
                'server' => [
                    'ok' => true,
                    'label' => 'Servidor do painel',
                    'latency_ms' => $serverLatencyMs,
                    'detail' => $serverLatencyMs . ' ms',
                ],
                'redis' => $redis,
                'asterisk' => $asterisk,
                'trunks' => $trunks,
                'balance' => $balance,
                'sms_api' => $smsApi,
                'whatsapp_api' => $whatsAppApi,
            ],
        ]);
    }

    private static function checkRedis(): array
    {
        $startedAt = microtime(true);

        try {
            $redis = RedisConn::get();
            $redis->ping();
            $latencyMs = max(1, (int)round((microtime(true) - $startedAt) * 1000));

            return [
                'ok' => true,
                'label' => 'Redis',
                'latency_ms' => $latencyMs,
                'detail' => $latencyMs . ' ms',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'label' => 'Redis',
                'latency_ms' => null,
                'detail' => 'Offline ou sem resposta',
                'error' => $e->getMessage(),
            ];
        }
    }

    private static function checkAsterisk(): array
    {
        $startedAt = microtime(true);

        try {
            $client = new Client([
                'base_uri' => 'http://' . TelephonyConfig::ariHost() . ':' . TelephonyConfig::ariPort() . '/',
                'timeout' => 1.5,
                'connect_timeout' => 1.0,
                'http_errors' => false,
            ]);

            $response = $client->get('ari/asterisk/info', [
                'auth' => [TelephonyConfig::ariUser(), TelephonyConfig::ariPass()],
                'query' => ['only' => 'system'],
                'headers' => ['Accept' => 'application/json'],
            ]);

            $latencyMs = max(1, (int)round((microtime(true) - $startedAt) * 1000));
            $status = $response->getStatusCode();
            $ok = $status >= 200 && $status < 500;

            return [
                'ok' => $ok,
                'label' => 'Asterisk / ARI',
                'latency_ms' => $latencyMs,
                'detail' => $ok ? $latencyMs . ' ms' : 'HTTP ' . $status,
                'http_status' => $status,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'label' => 'Asterisk / ARI',
                'latency_ms' => null,
                'detail' => 'Offline ou sem resposta',
                'error' => $e->getMessage(),
            ];
        }
    }

    private static function checkTrunks(array $user): array
    {
        $startedAt = microtime(true);
        $role = strtolower((string)($user['function'] ?? ''));
        $query = ['role' => $role];

        if ($role !== 'super_admin') {
            $query['tenant_id'] = $user['tenancy_id'] ?? null;
        }

        try {
            $result = (new AsteriskExtensionsSip())->listTrunks($query);
            $latencyMs = max(1, (int)round((microtime(true) - $startedAt) * 1000));
            $rows = $result['data']['data'] ?? $result['data'] ?? [];
            $online = 0;

            foreach ($rows as $trunk) {
                if (is_array($trunk) && self::isTrunkOnline($trunk)) {
                    $online++;
                }
            }

            $total = count(is_array($rows) ? $rows : []);

            return [
                'ok' => !empty($result['ok']) && $online > 0,
                'label' => 'Troncos',
                'latency_ms' => $latencyMs,
                'online' => $online,
                'total' => $total,
                'detail' => $total > 0
                    ? $online . '/' . $total . ' online - ' . $latencyMs . ' ms'
                    : 'Nenhum tronco cadastrado - ' . $latencyMs . ' ms',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'label' => 'Troncos',
                'latency_ms' => null,
                'online' => 0,
                'total' => 0,
                'detail' => 'Falha ao consultar troncos',
                'error' => $e->getMessage(),
            ];
        }
    }

    private static function isTrunkOnline(array $trunk): bool
    {
        foreach (['online', 'is_online', 'asterisk_up'] as $field) {
            if (isset($trunk[$field]) && filter_var($trunk[$field], FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        $status = strtoupper((string)($trunk['sip_status'] ?? $trunk['sip_status_text'] ?? ''));
        return in_array($status, ['OK', 'ONLINE', 'UP', 'REGISTERED'], true);
    }

    private static function checkBalance(array $user): array
    {
        try {
            $userId = isset($user['id']) ? (int)$user['id'] : null;
            $tenancyId = (string)($user['tenancy_id'] ?? '');
            $role = strtolower((string)($user['function'] ?? $user['user_function'] ?? ''));

            $wallet = $role === 'super_admin'
                ? BalanceSms::getBalanceSms(null, null)
                : BalanceSms::getBalanceSms($userId, $tenancyId);

            $sumBalance = $role === 'super_admin' ? null : BalanceSms::getSumBalanceSms($userId, $tenancyId);
            $balance = $sumBalance !== null && $sumBalance > 0
                ? (float)$sumBalance
                : (float)($wallet->balance ?? 0);

            return [
                'ok' => $wallet !== null,
                'label' => 'Saldo',
                'balance' => $balance,
                'formatted' => 'R$ ' . number_format($balance, 2, ',', '.'),
                'detail' => $wallet !== null
                    ? 'Saldo: R$ ' . number_format($balance, 2, ',', '.')
                    : 'Saldo não encontrado',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'label' => 'Saldo',
                'balance' => null,
                'formatted' => 'indisponível',
                'detail' => 'Falha ao consultar saldo',
                'error' => $e->getMessage(),
            ];
        }
    }

    private static function checkSmsApi(): array
    {
        $startedAt = microtime(true);
        $apiUrl = trim((string)getenv('DISPROURLBALANCE')) ?: trim((string)getenv('DISPROURL')) ?: 'https://apihttp.disparopro.com.br:8433';
        $hasKey = trim((string)getenv('DISPROKEY')) !== '';

        if (!$hasKey) {
            return [
                'ok' => false,
                'label' => 'API SMS',
                'latency_ms' => null,
                'detail' => 'DISPROKEY não configurada',
                'endpoint' => $apiUrl,
            ];
        }

        try {
            $balance = DisproClient::getBalanceDISPRO();
            $latencyMs = max(1, (int)round((microtime(true) - $startedAt) * 1000));

            if ($balance === null) {
                return [
                    'ok' => false,
                    'label' => 'API SMS',
                    'latency_ms' => $latencyMs,
                    'detail' => 'Sem resposta da Disparo Pro',
                    'endpoint' => $apiUrl,
                ];
            }

            return [
                'ok' => true,
                'label' => 'API SMS',
                'latency_ms' => $latencyMs,
                'detail' => 'Saldo provedor OK - ' . $latencyMs . ' ms',
                'endpoint' => $apiUrl,
                'provider_balance' => $balance,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'label' => 'API SMS',
                'latency_ms' => null,
                'detail' => 'Falha ao consultar API SMS',
                'endpoint' => $apiUrl,
                'error' => $e->getMessage(),
            ];
        }
    }

    private static function checkWhatsAppApi(): array
    {
        $startedAt = microtime(true);
        $account = WhatsAppAccount::getSupportAccount();
        $accessToken = (string)($account['access_token'] ?? WhatsAppConfig::platformAccessToken());
        $wabaId = (string)($account['waba_id'] ?? WhatsAppConfig::platformWabaId());
        $phoneNumberId = (string)($account['phone_number_id'] ?? WhatsAppConfig::phoneNumberId());

        if ($accessToken === '' || $wabaId === '' || $phoneNumberId === '') {
            return [
                'ok' => false,
                'label' => 'API WhatsApp',
                'latency_ms' => null,
                'detail' => 'Token, WABA ou phone number ID ausente',
            ];
        }

        try {
            $api = new MetaWhatsAppCloudApi();
            $phoneNumbers = $api->listPhoneNumbers($accessToken, $wabaId);
            $latencyMs = max(1, (int)round((microtime(true) - $startedAt) * 1000));

            if (empty($phoneNumbers['ok'])) {
                return [
                    'ok' => false,
                    'label' => 'API WhatsApp',
                    'latency_ms' => $latencyMs,
                    'detail' => $phoneNumbers['error'] ?? 'Falha ao consultar números da Meta',
                ];
            }

            return [
                'ok' => true,
                'label' => 'API WhatsApp',
                'latency_ms' => $latencyMs,
                'detail' => 'Meta OK - ' . $latencyMs . ' ms',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'label' => 'API WhatsApp',
                'latency_ms' => null,
                'detail' => 'Falha ao consultar API WhatsApp',
                'error' => $e->getMessage(),
            ];
        }
    }

    private static function buildDiagnosticsSummary(array $redis, array $asterisk, array $trunks, array $balance, array $smsApi, array $whatsAppApi, int $serverLatencyMs): string
    {
        $issues = [];

        if (!$asterisk['ok']) {
            $issues[] = 'Asterisk sem resposta';
        }

        if (!$redis['ok']) {
            $issues[] = 'Redis sem resposta';
        }

        if (!$trunks['ok']) {
            $issues[] = 'sem tronco online';
        }

        if (!$balance['ok']) {
            $issues[] = 'saldo indisponível';
        }

        if (!$smsApi['ok']) {
            $issues[] = 'api sms com alerta';
        }

        if (!$whatsAppApi['ok']) {
            $issues[] = 'api whatsapp com alerta';
        }

        $balanceDetail = $balance['detail'] ?? 'saldo --';
        $smsDetail = $smsApi['detail'] ?? 'sms --';
        $whatsAppDetail = $whatsAppApi['detail'] ?? 'whatsapp --';

        if ($issues === []) {
            return 'Tudo parece OK. Servidor ' . $serverLatencyMs . ' ms, Asterisk ' . ($asterisk['detail'] ?? '--') . ', Redis ' . ($redis['detail'] ?? '--') . ', troncos ' . ($trunks['detail'] ?? '--') . ', ' . $balanceDetail . ', ' . $smsDetail . ', ' . $whatsAppDetail . '.';
        }

        return 'Encontrei alerta: ' . implode(', ', $issues) . '. Servidor ' . $serverLatencyMs . ' ms, Asterisk ' . ($asterisk['detail'] ?? '--') . ', Redis ' . ($redis['detail'] ?? '--') . ', troncos ' . ($trunks['detail'] ?? '--') . ', ' . $balanceDetail . ', ' . $smsDetail . ', ' . $whatsAppDetail . '.';
    }

    private static function requireUser(): array|Response
    {
        $user = SessionUser::getLogged();
        if (!$user) {
            return self::json(401, [
                'success' => false,
                'message' => 'Usuário não autenticado.',
            ]);
        }

        return $user;
    }

    private static function json(int $status, array $payload): Response
    {
        return new Response($status, $payload, 'application/json');
    }

    private static function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);

        return is_array($data) ? $data : ($_POST ?: []);
    }

    private static function clientIp(): ?string
    {
        $forwarded = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        return $_SERVER['REMOTE_ADDR'] ?? null;
    }
}
