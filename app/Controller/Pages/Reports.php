<?php

namespace App\Controller\Pages;


use App\Config\AsaasConfig;
use App\Http\Response;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\CallbackSms;
use App\Model\Entity\CdrVoice;
use App\Model\Entity\Notifications;
use App\Model\Entity\NotificationsUsers;
use App\Model\Entity\PixSearch;
use App\Model\Entity\Rates;
use App\Model\Entity\RefillsResellers;
use App\Model\Entity\UserSearch;
use App\Service\PixService;
use App\Service\WhatsAppBilling;
use App\Service\WhatsAppCostPolicy;
use App\Session\User as SessionUser;
use App\Utils\View;
use DateTime;
use Exception;
use WilliamCosta\DatabaseManager\Database;

class Reports extends ViewComponents
{
    private static function resolveUserRole(array $user): string
    {
        return strtolower(trim((string)($user['user_function'] ?? $user['function'] ?? '')));
    }

    // ======================= Views =======================

    public static function getReportsStatus($request): array|bool|string
    {
        $content = View::render('/reports/sms', []);
        return parent::getComponentsReports('Maxx Solutions - SMS | Reports', $content);
    }

    public static function getNotificationsStatus($request): array|bool|string
    {
        $content = View::render('/reports/notifications', []);
        return parent::getComponentsReports('Maxx Solutions - SMS | Reports', $content);
    }

    public static function getTransactionsPix($request): array|bool|string
    {
        $content = View::render('/reports/pix', []);
        return parent::getComponentsReports('Maxx Solutions - PIX | Reports', $content);
    }

    public static function getTransactionsResellers($request): array|bool|string
    {
        $content = View::render('/reports/recharge', []);
        return parent::getComponentsReports('Maxx Solutions - SMS | Reports', $content);
    }

    public static function getStatusSmsView($request): array|bool|string
    {
        $content = View::render('/reports/sms', []);
        return parent::getComponentsReports('Maxx Solutions - SMS | Reports', $content);
    }

    public static function getWhatsAppReportView($request): array|bool|string
    {
        $queryParams = $request->getQueryParams();
        $reportType = strtolower(trim((string)($queryParams['report'] ?? 'message')));
        $pageTitle = $reportType === 'voice'
            ? 'Maxx Solutions - WhatsApp Voz | Reports'
            : 'Maxx Solutions - WhatsApp Mensagem | Reports';

        $content = View::render('/reports/whatsapp', []);
        return parent::getComponentsReports($pageTitle, $content);
    }

    public static function getCdrComponents($request): array|bool|string
    {
        $content = View::render('/reports/cdr', []);
        return parent::getComponentsReports('Maxx Solutions - SMS | Reports', $content);
    }


    public static function getTransactionsPixRealtime(): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, ['message' => "Usuário não autenticado"], 'application/json');
        }

        $userId    = $obUser['id'];
        $role      = self::resolveUserRole($obUser);
        $tenancyId = $obUser['tenancy_id'];

        // ==============================
        // 🔎 MONTAR FILTROS (Padrão CDR)
        // ==============================
        $filters = [];

        if ($role === 'super_admin') {
            $filters = []; // Vê tudo global
        } elseif ($role === 'admin') {
            $filters['tenancy_id'] = $tenancyId;
        } elseif ($role === 'reseller') {
            $filters['tenancy_id'] = $tenancyId;
            $filters['reseller_id'] = $userId; // No Pix, filtramos pelo dono da recarga
        } else {
            $filters['tenancy_id'] = $tenancyId;
            $filters['user_id']    = $userId;
        }

        // ==============================
        // 🔽 BUSCA NO BANCO (Model)
        // ==============================
        try {
            // Se for reseller, buscamos na RefillsResellers, se for Admin, na PixSearch (Webhooks)
            if ($role === 'reseller') {
                $data = RefillsResellers::getTransactions($filters, "created_at DESC");
                $userType = 'reseller';
            } else {
                $data = PixSearch::getWebhookAsaas($filters, "confirmed_date DESC");
                $userType = 'admin';
            }
        } catch (\Exception $e) {
            return new Response(500, [
                'message' => "Erro ao consultar transações Pix",
                'error'   => $e->getMessage()
            ], 'application/json');
        }

        // ==============================
        // 🔄 Formatando os dados (Uniforme)
        // ==============================
        $formatted = array_map(function ($row) use ($userType) {
            // Normalização para o Front-end não quebrar independente de quem loga
            if ($userType === 'reseller') {
                return [
                    'id'             => $row['id'],
                    'transaction_id' => $row['transaction_id'] ?? '-',
                    'email'          => $row['email'] ?? '-',
                    'value'          => (float)($row['balance'] ?? 0),
                    'type'           => $row['type'] ?? 'recarga',
                    'status'         => $row['status'] ?? 'pending',
                    'date'           => isset($row['created_at']) ? (new DateTime($row['created_at']))->format('d-m-Y H:i:s') : '-',
                ];
            }

            return [
                'id'             => $row['webhook_id'] ?? $row['id'],
                'webhook_id'     => $row['webhook_id'] ?? $row['id'],
                'user_id'        => isset($row['user_id']) ? (int)$row['user_id'] : null,
                'tenancy_id'     => $row['tenancy_id'] ?? null,
                'plan_id'        => isset($row['user_plain_id']) ? (int)$row['user_plain_id'] : null,
                'status'         => $row['payment_status'] ?? 'PENDING',
                'value'          => (float)($row['value'] ?? 0),
                'email'          => $row['email'] ?? null,
                'pix_qr_code_id' => $row['pixQrCodeId'] ?? null,
                'payment_id'     => $row['asaas_payment_id'] ?? null,
                'invoice_number' => $row['invoiceNumber'] ?? '-',
                'invoice_url'    => $row['invoice_url'] ?? null,
                'date'           => isset($row['confirmed_date']) ? (new DateTime($row['confirmed_date']))->format('d-m-Y H:i:s') : '-',
                'date_iso'       => $row['confirmed_date'] ?? $row['payment_date'] ?? $row['updated_at'] ?? null,
                'receipt_url'    => $row['transactionReceiptUrl'] ?? null,
                'can_refund'     => in_array((string)($row['payment_status'] ?? ''), ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'], true),
            ];
        }, $data);

        // ==============================
        // ✅ RETORNO PARA O FRONTEND
        // ==============================
        return new Response(200, [
            'success'   => true,
            'user_type' => $userType,
            'total'     => count($formatted),
            'data'      => $formatted
        ], 'application/json');
    }

    public static function refundTransactionPix($request, $id): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, ['success' => false, 'message' => 'Usuário não autenticado.'], 'application/json');
        }

        $role = self::resolveUserRole($obUser);
        if (!in_array($role, ['admin', 'super_admin'], true)) {
            return new Response(403, ['success' => false, 'message' => 'Sem permissão para estornar cobranças.'], 'application/json');
        }

        $webhookId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$webhookId) {
            return new Response(422, ['success' => false, 'message' => 'Transação Pix inválida.'], 'application/json');
        }

        $pix = PixSearch::getByWebhookId((int)$webhookId);
        if (!$pix) {
            return new Response(404, ['success' => false, 'message' => 'Transação Pix não encontrada.'], 'application/json');
        }

        if ($role !== 'super_admin' && (string)$pix->tenancy_id !== (string)($obUser['tenancy_id'] ?? '')) {
            return new Response(403, ['success' => false, 'message' => 'Você não pode estornar transações de outra empresa.'], 'application/json');
        }

        $currentStatus = strtoupper(trim((string)($pix->payment_status ?? '')));
        if ($currentStatus === 'PAYMENT_REFUNDED') {
            return new Response(409, ['success' => false, 'message' => 'Esta transação já foi estornada.'], 'application/json');
        }

        if (!in_array($currentStatus, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'], true)) {
            return new Response(409, ['success' => false, 'message' => 'Somente cobranças pagas podem ser estornadas.'], 'application/json');
        }

        if (!AsaasConfig::isConfigured()) {
            return new Response(500, ['success' => false, 'message' => 'Configuração do Asaas incompleta para processar estorno.'], 'application/json');
        }

        try {
            $paymentId = trim((string)($pix->asaas_payment_id ?? ''));
            if ($paymentId === '' && !empty($pix->pixQrCodeId)) {
                $providerPayment = self::resolveProviderPaymentForPix((string)$pix->pixQrCodeId, $pix);
                if ($providerPayment !== null) {
                    $syncResult = PixService::syncProviderStatusPayment($pix, $providerPayment);
                    if (!$syncResult['success']) {
                        return new Response(
                            (int)($syncResult['status'] ?? 422),
                            [
                                'success' => false,
                                'message' => $syncResult['error'] ?? 'Falha ao sincronizar pagamento antes do estorno.',
                            ],
                            'application/json'
                        );
                    }

                    $refreshedPix = PixSearch::getByWebhookId((int)$webhookId);
                    if ($refreshedPix) {
                        $pix = $refreshedPix;
                        $paymentId = trim((string)($pix->asaas_payment_id ?? ''));
                    }
                }
            }

            if ($paymentId === '') {
                return new Response(422, ['success' => false, 'message' => 'Não foi possível identificar o pagamento no Asaas para estorno.'], 'application/json');
            }

            $client = AsaasConfig::createClient();
            $refundResponse = $client->refundPayment(
                $paymentId,
                round((float)$pix->value, 2),
                'Estorno solicitado pelo administrativo'
            );

            if (isset($refundResponse['error']) || isset($refundResponse['errors'])) {
                return new Response(424, [
                    'success' => false,
                    'message' => 'O Asaas recusou o estorno desta cobrança.',
                    'errors' => $refundResponse['errors'] ?? null,
                ], 'application/json');
            }

            $providerPayment = null;
            if (!empty($pix->pixQrCodeId)) {
                $providerPayment = self::resolveProviderPaymentForPix((string)$pix->pixQrCodeId, $pix);
            }

            $refundStatus = strtoupper(trim((string)($refundResponse['status'] ?? '')));
            if ($refundStatus === '') {
                $refundStatus = strtoupper(trim((string)($refundResponse['refunds'][0]['status'] ?? '')));
            }

            $providerPaymentStatus = strtoupper(trim((string)($providerPayment['status'] ?? '')));
            $canProcessLocalRefund = $providerPaymentStatus === 'REFUNDED' || $refundStatus === 'DONE';

            if (!$canProcessLocalRefund) {
                return new Response(202, [
                    'success' => true,
                    'message' => 'Pedido de estorno enviado ao Asaas. A reversão local será concluída quando o webhook confirmar o estorno.',
                ], 'application/json');
            }

            if ($providerPayment === null) {
                $providerPayment = [
                    'id' => $paymentId,
                    'status' => 'REFUNDED',
                    'value' => (float)$pix->value,
                    'invoiceNumber' => $pix->invoiceNumber,
                    'pixQrCodeId' => $pix->pixQrCodeId,
                    'externalReference' => $pix->external_Reference,
                    'paymentDate' => date('Y-m-d H:i:s'),
                    'confirmedDate' => date('Y-m-d H:i:s'),
                ];
            }

            $result = PixService::processWebhookPayment('PAYMENT_REFUNDED', $providerPayment);
            if (!$result['success']) {
                return new Response(
                    (int)($result['status'] ?? 422),
                    [
                        'success' => false,
                        'message' => $result['error'] ?? 'Falha ao aplicar o estorno localmente.',
                    ],
                    'application/json'
                );
            }

            return new Response(200, [
                'success' => true,
                'message' => 'Estorno realizado com sucesso.',
                'data' => $result['data'] ?? [],
            ], 'application/json');
        } catch (\Throwable $e) {
            return new Response(500, [
                'success' => false,
                'message' => 'Falha ao solicitar estorno da cobrança.',
                'error' => $e->getMessage(),
            ], 'application/json');
        }
    }

    public static function getNotificationsStatusRealtime($request): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, ['message' => "Usuário não autenticado"], 'application/json');
        }

        $userId    = $obUser['id'];
        $role      = self::resolveUserRole($obUser);
        $tenancyId = $obUser['tenancy_id'];

        // ==========================================
        // 🔎 MONTAR FILTROS (Inspirado no padrão Pix)
        // ==========================================
        $filters = [];

        if ($role === 'super_admin') {
            $filters = []; // Vê tudo global (opcional: filtrar por tenancy se preferir)
        } elseif ($role === 'admin') {
            $filters['tenancy_id'] = $tenancyId;
        } elseif ($role === 'reseller') {
            $filters['tenancy_id'] = $tenancyId;
            $filters['user_id']    = $userId; // 🛡️ Trava: Só vê as próprias notificações
        } else {
            $filters['tenancy_id'] = $tenancyId;
            $filters['user_id']    = $userId;
        }

        // ==============================
        // 🔽 BUSCA NO BANCO (Model)
        // ==============================
        try {
            // Usando o método que aceita o array de filtros igual ao Pix
            $data = NotificationsUsers::getNotificationsForRealtime($filters, "created_at DESC");
        } catch (\Exception $e) {
            return new Response(500, [
                'message' => "Erro ao consultar notificações",
                'error'   => $e->getMessage()
            ], 'application/json');
        }

        // ==============================
        // 🔄 Formatando os dados (Uniforme)
        // ==============================
        $formatted = array_map(function ($row) {
            // Garantimos que trabalhamos com array
            $row = (array)$row;

            return [
                'id'         => $row['id'],
                'type'       => $row['type'] ?? 'info',
                'title'      => $row['title'] ?? 'Sem título',
                'message'    => $row['message'] ?? '',
                'read_at'    => $row['read_at'] ? (new DateTime($row['read_at']))->format('d/m/Y H:i') : null,
                'created_at' => $row['created_at'] ? (new DateTime($row['created_at']))->format('d/m/Y H:i') : '-',
            ];
        }, $data);

        // ==============================
        // ✅ RETORNO PARA O FRONTEND
        // ==============================
        return new Response(200, [
            'success'   => true,
            'user_type' => $role,
            'total'     => count($formatted),
            'data'      => $formatted
        ], 'application/json');
    }

    public static function getNotificationTargetUsers($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, ['success' => false, 'message' => 'Usuário não autenticado'], 'application/json');
        }

        $role = strtolower((string)($obUser['user_function'] ?? $obUser['function'] ?? ''));
        $where = '1=1';
        $params = [];

        if ($role !== 'super_admin') {
            $where = 'u.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = (string)$obUser['tenancy_id'];
        }

        $users = (new Database('users u LEFT JOIN tenancies t ON t.id = u.tenancy_id'))
            ->select($where, $params, 'u.name ASC, u.id ASC', '', [
                'u.id',
                'u.name',
                'u.last_name',
                'u.email',
                'u.tenancy_id',
                'u.user_function',
                't.name AS tenancy_name',
            ])
            ->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return new Response(200, [
            'success' => true,
            'data' => $users,
        ], 'application/json');
    }

    public static function createNotification($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, ['success' => false, 'message' => 'Usuário não autenticado'], 'application/json');
        }

        $data = json_decode(file_get_contents('php://input') ?: '', true);
        $data = is_array($data) ? $data : ($_POST ?: []);

        $title = trim((string)($data['title'] ?? ''));
        $message = trim((string)($data['message'] ?? ''));
        $type = self::normalizeNotificationType((string)($data['type'] ?? 'info'));
        $scope = (string)($data['scope'] ?? 'global');
        $targetUserId = (int)($data['target_user_id'] ?? 0);

        if ($title === '' || $message === '') {
            return new Response(422, ['success' => false, 'message' => 'Informe título e mensagem.'], 'application/json');
        }

        if ($scope === 'user' && $targetUserId <= 0) {
            return new Response(422, ['success' => false, 'message' => 'Selecione o usuário que receberá a notificação.'], 'application/json');
        }

        try {
            $targets = self::resolveNotificationTargets($obUser, $scope, $targetUserId);
            if (!$targets) {
                return new Response(422, ['success' => false, 'message' => 'Nenhum usuário encontrado para receber a notificação.'], 'application/json');
            }

            $created = 0;
            foreach ($targets as $target) {
                $id = Notifications::insertNotifications(
                    (string)$target['tenancy_id'],
                    (int)$target['id'],
                    mb_substr($title, 0, 160),
                    $message,
                    $type
                );
                if ($id !== false) {
                    $created++;
                }
            }

            return new Response(201, [
                'success' => true,
                'created' => $created,
                'message' => $created === 1 ? 'Notificação enviada.' : "{$created} notificações enviadas.",
            ], 'application/json');
        } catch (\Throwable $e) {
            return new Response(500, [
                'success' => false,
                'message' => 'Falha ao enviar notificação.',
                'error' => $e->getMessage(),
            ], 'application/json');
        }
    }

    private static function resolveNotificationTargets(array $obUser, string $scope, int $targetUserId): array
    {
        $role = strtolower((string)($obUser['user_function'] ?? $obUser['function'] ?? ''));
        $params = [];

        if ($scope === 'user') {
            $where = 'u.id = :id';
            $params[':id'] = $targetUserId;

            if ($role !== 'super_admin') {
                $where .= ' AND u.tenancy_id = :tenancy_id';
                $params[':tenancy_id'] = (string)$obUser['tenancy_id'];
            }

            return (new Database('users u'))
                ->select($where, $params, 'u.id ASC', '', ['u.id', 'u.tenancy_id'])
                ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        $where = '1=1';
        if ($role !== 'super_admin') {
            $where = 'u.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = (string)$obUser['tenancy_id'];
        }

        return (new Database('users u'))
            ->select($where, $params, 'u.id ASC', '', ['u.id', 'u.tenancy_id'])
            ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function normalizeNotificationType(string $type): string
    {
        return in_array($type, ['info', 'notice', 'warning', 'error', 'success'], true) ? $type : 'info';
    }

    private static function resolveProviderPaymentForPix(string $pixQrCodeId, PixSearch $pix): ?array
    {
        $response = AsaasConfig::createClient()->listPaymentsByPixQrCodeId($pixQrCodeId);
        $items = is_array($response['data'] ?? null) ? $response['data'] : [];

        if ($items === []) {
            return null;
        }

        foreach ($items as $item) {
            $sameInvoice = !empty($pix->invoiceNumber) && (string)($item['invoiceNumber'] ?? '') === (string)$pix->invoiceNumber;
            $samePayment = !empty($pix->asaas_payment_id) && (string)($item['id'] ?? '') === (string)$pix->asaas_payment_id;
            if ($sameInvoice || $samePayment) {
                $item['pixQrCodeId'] = $item['pixQrCodeId'] ?? $pix->pixQrCodeId;
                $item['invoiceNumber'] = $item['invoiceNumber'] ?? $pix->invoiceNumber;
                $item['externalReference'] = $item['externalReference'] ?? $pix->external_Reference;
                $item['value'] = $item['value'] ?? $pix->value;
                return $item;
            }
        }

        $item = $items[0];
        $item['pixQrCodeId'] = $item['pixQrCodeId'] ?? $pix->pixQrCodeId;
        $item['invoiceNumber'] = $item['invoiceNumber'] ?? $pix->invoiceNumber;
        $item['externalReference'] = $item['externalReference'] ?? $pix->external_Reference;
        $item['value'] = $item['value'] ?? $pix->value;

        return $item;
    }

    public static function getStatusSmsViewRealtime($request): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, ['message' => "Usuário não autenticado"], 'application/json');
        }

        $userId    = $obUser['id'];
        $role      = strtolower(trim($obUser['user_function'] ?? $obUser['function'] ?? ''));
        $tenancyId = $obUser['tenancy_id'];
        $queryParams = $request->getQueryParams();

        // ==========================================
        // 🔎 MONTAR FILTROS (Padrão Notifications/Pix)
        // ==========================================
        $filters = [];

        if ($role === 'super_admin') {
            $filters = []; // Acesso global
        } elseif ($role === 'admin') {
            $filters['tenancy_id'] = $tenancyId;
        } elseif ($role === 'reseller') {
            $filters['tenancy_id'] = $tenancyId;
            $filters['reseller_id'] = $userId; // Revendedor vê os próprios envios e usuários vinculados
        } else {
            $filters['tenancy_id'] = $tenancyId;
            $filters['user_id']    = $userId;
        }

        $period = strtolower(trim((string)($queryParams['period'] ?? 'day')));
        if (in_array($period, ['day', 'week', 'month', 'custom'], true)) {
            $filters['period'] = $period;
        }

        if (!empty($queryParams['date_from'])) {
            $filters['date_from'] = (string)$queryParams['date_from'];
        }

        if (!empty($queryParams['date_to'])) {
            $filters['date_to'] = (string)$queryParams['date_to'];
        }

        if (!empty($queryParams['status'])) {
            $filters['status_sms'] = (string)$queryParams['status'];
        }

        // ==============================
        // 🔽 BUSCA NO BANCO (Model)
        // ==============================
        try {
            // Chamando o método que aceita o array de filtros (padrão que você definiu)
            $data = CallbackSms::getSmsForRealtime($filters, "event_date DESC");
        } catch (\Exception $e) {
            return new Response(500, [
                'message' => "Erro ao consultar relatório de SMS",
                'error'   => $e->getMessage()
            ], 'application/json');
        }

        // ==============================
        // 🔄 Formatando os dados (Uniforme)
        // ==============================
        $formatted = array_map(/**
         * @throws Exception
         */ function ($row) {
            $row = (array)$row;

            return [
                'id'          => $row['id'],
                'phone_sms'   => $row['phone_sms'] ?? 'N/A',
                'value_sms'   => (float)($row['value_sms'] ?? 0),
                'operator'    => $row['operator'] ?? 'NI',
                'status_sms'  => $row['status_sms'] ?? 'UNKNOWN',
                'date_send'   => !empty($row['date_send']) ? (new DateTime($row['date_send']))->format('d/m/Y H:i') : '-',
                'update_date' => !empty($row['event_date']) ? (new DateTime($row['event_date']))->format('d/m/Y H:i') : '-',
                'camp_name'   => $row['camp_name'] ?? '-',
                'webhook_action' => $row['webhook_action'] ?? null,
                'response_text' => $row['response_text'] ?? null,
            ];
        }, $data);

        // ==============================
        // ✅ RETORNO PARA O FRONTEND
        // ==============================
        return new Response(200, [
            'success'   => true,
            'user_type' => $role,
            'period'    => $filters['period'] ?? 'all',
            'total'     => count($formatted),
            'data'      => $formatted
        ], 'application/json');
    }

    public static function getWhatsAppReportRealtime($request): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, ['message' => "Usuário não autenticado"], 'application/json');
        }

        $queryParams = $request->getQueryParams();
        $reportType = strtolower(trim((string)($queryParams['report'] ?? 'message')));

        if ($reportType === 'voice') {
            Voice::processCdrFromRedis(false);

            if (strtolower(trim((string)($queryParams['mode'] ?? ''))) === 'queues') {
                return self::getWhatsAppVoiceQueueReportRealtime($obUser, $queryParams);
            }

            return self::getWhatsAppVoiceReportRealtime($obUser, $queryParams);
        }

        if (strtolower(trim((string)($queryParams['mode'] ?? ''))) === 'queues') {
            return self::getWhatsAppQueueReportRealtime($obUser, $queryParams);
        }

        $role = strtolower(trim($obUser['user_function'] ?? $obUser['function'] ?? ''));
        $period = strtolower(trim((string)($queryParams['period'] ?? 'day')));
        $status = strtolower(trim((string)($queryParams['status'] ?? 'charged')));

        $where = ['1=1'];
        $params = [];

        if ($role !== 'super_admin') {
            $where[] = 'c.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = (string)$obUser['tenancy_id'];
        }

        if (in_array($role, ['agent', 'support_l1'], true)) {
            $where[] = 'c.client_id = :client_id';
            $params[':client_id'] = (int)$obUser['id'];
        } elseif ($role === 'reseller') {
            $where[] = "(
                c.client_id = :reseller_id
                OR c.client_id IN (
                    SELECT u.id
                    FROM users u
                    WHERE u.user_id = :reseller_id
                      AND u.tenancy_id = :reseller_tenancy_id
                )
            )";
            $params[':reseller_id'] = (int)$obUser['id'];
            $params[':reseller_tenancy_id'] = (string)$obUser['tenancy_id'];
        }

        $dateColumn = 'COALESCE(c.delivered_at, c.timestamp, c.created_at)';
        if (!empty($queryParams['date_from'])) {
            $where[] = "{$dateColumn} >= :date_from";
            $params[':date_from'] = (string)$queryParams['date_from'] . ' 00:00:00';
        }

        if (!empty($queryParams['date_to'])) {
            $where[] = "{$dateColumn} <= :date_to";
            $params[':date_to'] = (string)$queryParams['date_to'] . ' 23:59:59';
        }

        if (empty($queryParams['date_from']) && empty($queryParams['date_to'])) {
            if ($period === 'day') {
                $where[] = "DATE({$dateColumn}) = CURDATE()";
            } elseif ($period === 'week') {
                $where[] = "{$dateColumn} >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
                    AND {$dateColumn} < DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)";
            } elseif ($period === 'month') {
                $where[] = "YEAR({$dateColumn}) = YEAR(CURDATE()) AND MONTH({$dateColumn}) = MONTH(CURDATE())";
            }
        }

        if ($status === 'charged') {
            $where[] = 'c.billed = 1';
        } elseif ($status === 'delivered') {
            $where[] = "COALESCE(wm.status, IF(c.delivered_at IS NULL, c.status, 'delivered')) IN ('delivered', 'read')";
        } elseif (in_array($status, ['sent', 'read', 'failed', 'blocked'], true)) {
            $where[] = "COALESCE(wm.status, c.status) = :status";
            $params[':status'] = $status;
        }

        $sql = "SELECT
                c.id,
                c.client_id,
                u.name AS client_name,
                u.last_name AS client_last_name,
                u.account_code AS client_account_code,
                c.phone_number,
                c.message_category,
                c.template_name,
                c.price_brl,
                c.final_price_brl,
                c.cost_brl,
                c.billed,
                c.status AS cdr_status,
                c.timestamp,
                c.delivered_at,
                c.wamid,
                c.error_message,
                wm.status AS message_status,
                wm.updated_at AS message_updated_at,
                COALESCE(wm.preview_body, wm.body, wo.preview_body, wo.body) AS message_preview,
                wa.id AS whatsapp_account_id,
                wa.label AS whatsapp_account_label,
                wa.display_phone_number AS whatsapp_account_number,
                wo.campaign_id,
                camp.name AS campaign_name
            FROM whatsapp_message_cdr c
            LEFT JOIN whatsapp_messages wm ON wm.wamid = c.wamid
            LEFT JOIN whatsapp_outbox wo ON wo.id = c.whatsapp_outbox_id
            LEFT JOIN whatsapp_accounts wa ON wa.id = COALESCE(wo.account_id, wm.account_id) AND wa.tenancy_id = c.tenancy_id
            LEFT JOIN whatsapp_campaigns camp ON camp.id = wo.campaign_id
            LEFT JOIN users u ON u.id = c.client_id AND u.tenancy_id = c.tenancy_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$dateColumn} DESC, c.id DESC
            LIMIT 5000";

        try {
            $rows = (new Database())->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return new Response(500, [
                'message' => 'Erro ao consultar relatório de WhatsApp',
                'error' => $e->getMessage(),
            ], 'application/json');
        }

        $canSeeInternalPricing = $role === 'super_admin';

        $formatted = array_map(static function (array $row) use ($canSeeInternalPricing): array {
            $messageStatus = strtolower((string)($row['message_status'] ?: $row['cdr_status'] ?: 'sent'));
            if (!empty($row['delivered_at']) && $messageStatus === 'sent') {
                $messageStatus = 'delivered';
            }

            $price = (float)($row['price_brl'] ?? $row['final_price_brl'] ?? 0);
            $billed = (int)($row['billed'] ?? 0) === 1;

            $formattedRow = [
                'id' => (int)$row['id'],
                'client_id' => (int)$row['client_id'],
                'client_name' => trim((string)($row['client_name'] ?? '')) ?: '-',
                'client_account_code' => $row['client_account_code'] ?? '-',
                'whatsapp_account_id' => $row['whatsapp_account_id'] ? (int)$row['whatsapp_account_id'] : null,
                'whatsapp_account_label' => $row['whatsapp_account_label'] ?? '-',
                'whatsapp_account_number' => $row['whatsapp_account_number'] ?? '-',
                'destination' => $row['phone_number'] ?? '-',
                'category' => strtolower((string)($row['message_category'] ?? '-')),
                'template_name' => $row['template_name'] ?? '-',
                'campaign_name' => $row['campaign_name'] ?? '-',
                'message_preview' => $row['message_preview'] ?? '',
                'status' => $messageStatus,
                'billed' => $billed,
                'price_brl' => $price,
                'charged_value' => $billed ? $price : 0.0,
                'wamid' => $row['wamid'] ?? null,
                'error_message' => $row['error_message'] ?? null,
                'sent_at' => !empty($row['timestamp']) ? (new DateTime($row['timestamp']))->format('d/m/Y H:i') : '-',
                'delivered_at' => !empty($row['delivered_at']) ? (new DateTime($row['delivered_at']))->format('d/m/Y H:i') : '-',
            ];

            if ($canSeeInternalPricing) {
                $formattedRow['cost_brl'] = (float)($row['cost_brl'] ?? 0);
            }

            return $formattedRow;
        }, $rows);
        $categorySummary = [];
        foreach ($formatted as $row) {
            $category = strtolower((string)($row['category'] ?? 'marketing'));
            if (!isset($categorySummary[$category])) {
                $categorySummary[$category] = [
                    'category' => $category,
                    'quantity' => 0,
                    'charged_count' => 0,
                    'charged_total' => 0.0,
                ];

                if ($canSeeInternalPricing) {
                    $categorySummary[$category]['cost_total'] = 0.0;
                }
            }

            $categorySummary[$category]['quantity']++;
            if ($canSeeInternalPricing) {
                $categorySummary[$category]['cost_total'] += (float)($row['cost_brl'] ?? 0);
            }
            if (!empty($row['billed'])) {
                $categorySummary[$category]['charged_count']++;
                $categorySummary[$category]['charged_total'] += (float)($row['charged_value'] ?? 0);
            }
        }
        foreach ($categorySummary as &$summary) {
            $summary['charged_total'] = round((float)$summary['charged_total'], 4);
            if ($canSeeInternalPricing) {
                $summary['cost_total'] = round((float)$summary['cost_total'], 4);
            }
        }
        unset($summary);

        return new Response(200, [
            'success' => true,
            'user_type' => $role,
            'period' => $period,
            'total' => count($formatted),
            'charged_total' => array_sum(array_column($formatted, 'charged_value')),
            'charged_count' => count(array_filter($formatted, static fn ($row) => !empty($row['billed']))),
            'category_summary' => array_values($categorySummary),
            'data' => $formatted,
        ], 'application/json');
    }

    private static function getWhatsAppQueueReportRealtime(array $obUser, array $queryParams): Response
    {
        $role = strtolower(trim((string)($obUser['user_function'] ?? $obUser['function'] ?? '')));
        $period = strtolower(trim((string)($queryParams['period'] ?? 'day')));
        $status = strtolower(trim((string)($queryParams['status'] ?? '')));
        $queueId = (int)($queryParams['queue_id'] ?? 0);
        $agentId = (int)($queryParams['agent_id'] ?? 0);

        $where = ['1=1'];
        $params = [];

        if ($role !== 'super_admin') {
            $where[] = 's.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = (string)$obUser['tenancy_id'];
        }

        if (in_array($role, ['agent', 'support_l1'], true)) {
            $where[] = "(
                s.assigned_agent_user_id = :agent_user_id
                OR EXISTS (
                    SELECT 1
                    FROM whatsapp_support_queue_agents qa
                    WHERE qa.queue_id = s.queue_id
                      AND qa.agent_user_id = :agent_user_id
                      AND qa.tenancy_id = s.tenancy_id
                )
            )";
            $params[':agent_user_id'] = (int)$obUser['id'];
        } elseif ($role === 'reseller') {
            $where[] = "(
                s.user_id = :reseller_id
                OR s.user_id IN (
                    SELECT u.id
                    FROM users u
                    WHERE u.user_id = :reseller_id
                      AND u.tenancy_id = :reseller_tenancy_id
                )
            )";
            $params[':reseller_id'] = (int)$obUser['id'];
            $params[':reseller_tenancy_id'] = (string)$obUser['tenancy_id'];
        }

        if ($queueId > 0) {
            $where[] = 's.queue_id = :queue_id';
            $params[':queue_id'] = $queueId;
        }

        if ($agentId > 0) {
            $where[] = 's.assigned_agent_user_id = :filter_agent_id';
            $params[':filter_agent_id'] = $agentId;
        }

        if (in_array($status, ['waiting', 'active', 'finished'], true)) {
            $where[] = 's.state = :state';
            $params[':state'] = $status;
        }

        $dateColumn = 'COALESCE(s.queued_at, s.created_at)';
        if (!empty($queryParams['date_from'])) {
            $where[] = "{$dateColumn} >= :date_from";
            $params[':date_from'] = (string)$queryParams['date_from'] . ' 00:00:00';
        }

        if (!empty($queryParams['date_to'])) {
            $where[] = "{$dateColumn} <= :date_to";
            $params[':date_to'] = (string)$queryParams['date_to'] . ' 23:59:59';
        }

        if (empty($queryParams['date_from']) && empty($queryParams['date_to'])) {
            if ($period === 'day') {
                $where[] = "DATE({$dateColumn}) = CURDATE()";
            } elseif ($period === 'week') {
                $where[] = "{$dateColumn} >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
                    AND {$dateColumn} < DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)";
            } elseif ($period === 'month') {
                $where[] = "YEAR({$dateColumn}) = YEAR(CURDATE()) AND MONTH({$dateColumn}) = MONTH(CURDATE())";
            }
        }

        $sql = "SELECT
                s.id,
                s.tenancy_id,
                s.user_id,
                s.account_id,
                s.conversation_id,
                s.support_ticket_id,
                s.queue_id,
                s.assigned_agent_user_id,
                s.state,
                s.priority,
                s.is_vip,
                s.queued_at,
                s.started_at,
                s.finished_at,
                s.last_customer_message_at,
                s.created_at,
                wc.contact_name,
                wc.contact_phone,
                wc.last_message,
                wc.unread_count,
                wa.label AS account_label,
                wa.display_phone_number AS account_phone,
                q.name AS queue_name,
                u.name AS agent_name,
                CASE
                    WHEN s.queued_at IS NULL THEN 0
                    WHEN s.started_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, s.queued_at, s.started_at)
                    ELSE TIMESTAMPDIFF(SECOND, s.queued_at, NOW())
                END AS wait_seconds,
                CASE
                    WHEN s.started_at IS NULL THEN 0
                    WHEN s.finished_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, s.started_at, s.finished_at)
                    ELSE TIMESTAMPDIFF(SECOND, s.started_at, NOW())
                END AS service_seconds
            FROM whatsapp_support_sessions s
            INNER JOIN whatsapp_conversations wc ON wc.id = s.conversation_id AND wc.tenancy_id = s.tenancy_id
            LEFT JOIN whatsapp_support_queues q ON q.id = s.queue_id AND q.tenancy_id = s.tenancy_id
            LEFT JOIN users u ON u.id = s.assigned_agent_user_id AND u.tenancy_id = s.tenancy_id
            LEFT JOIN whatsapp_accounts wa ON wa.id = s.account_id AND wa.tenancy_id = s.tenancy_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$dateColumn} DESC, s.id DESC
            LIMIT 5000";

        try {
            $rows = (new Database())->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return new Response(500, [
                'message' => 'Erro ao consultar relatório operacional do WhatsApp',
                'error' => $e->getMessage(),
            ], 'application/json');
        }

        $formatted = array_map(static function (array $row): array {
            $contactName = trim((string)($row['contact_name'] ?? ''));
            $contactPhone = (string)($row['contact_phone'] ?? '-');

            return [
                'id' => (int)$row['id'],
                'conversation_id' => (int)$row['conversation_id'],
                'support_ticket_id' => !empty($row['support_ticket_id']) ? (int)$row['support_ticket_id'] : null,
                'queue_id' => !empty($row['queue_id']) ? (int)$row['queue_id'] : null,
                'queue_name' => $row['queue_name'] ?? 'Sem fila',
                'account_id' => !empty($row['account_id']) ? (int)$row['account_id'] : null,
                'account_label' => $row['account_label'] ?? '-',
                'account_phone' => $row['account_phone'] ?? '-',
                'assigned_agent_user_id' => !empty($row['assigned_agent_user_id']) ? (int)$row['assigned_agent_user_id'] : null,
                'agent_name' => $row['agent_name'] ?? null,
                'contact_name' => $contactName !== '' ? $contactName : $contactPhone,
                'contact_phone' => $contactPhone,
                'last_message' => $row['last_message'] ?? '',
                'unread_count' => (int)($row['unread_count'] ?? 0),
                'state' => strtolower((string)($row['state'] ?? 'waiting')),
                'priority' => (int)($row['priority'] ?? 0),
                'is_vip' => (int)($row['is_vip'] ?? 0) === 1,
                'wait_seconds' => max(0, (int)($row['wait_seconds'] ?? 0)),
                'service_seconds' => max(0, (int)($row['service_seconds'] ?? 0)),
                'queued_at' => !empty($row['queued_at']) ? (new DateTime($row['queued_at']))->format('d/m/Y H:i') : '-',
                'started_at' => !empty($row['started_at']) ? (new DateTime($row['started_at']))->format('d/m/Y H:i') : '-',
                'finished_at' => !empty($row['finished_at']) ? (new DateTime($row['finished_at']))->format('d/m/Y H:i') : '-',
                'last_customer_message_at' => !empty($row['last_customer_message_at']) ? (new DateTime($row['last_customer_message_at']))->format('d/m/Y H:i') : '-',
            ];
        }, $rows);

        $waitSamples = array_values(array_filter(array_map(static fn(array $row) => (int)($row['wait_seconds'] ?? 0), $formatted), static fn(int $value) => $value > 0));
        $serviceSamples = array_values(array_filter(array_map(static fn(array $row) => (int)($row['service_seconds'] ?? 0), $formatted), static fn(int $value) => $value > 0));

        return new Response(200, [
            'success' => true,
            'mode' => 'queues',
            'user_type' => $role,
            'period' => $period,
            'total' => count($formatted),
            'stats' => [
                'waiting' => count(array_filter($formatted, static fn(array $row) => ($row['state'] ?? '') === 'waiting')),
                'active' => count(array_filter($formatted, static fn(array $row) => ($row['state'] ?? '') === 'active')),
                'finished' => count(array_filter($formatted, static fn(array $row) => ($row['state'] ?? '') === 'finished')),
                'avg_wait_seconds' => $waitSamples ? (int)round(array_sum($waitSamples) / count($waitSamples)) : 0,
                'avg_service_seconds' => $serviceSamples ? (int)round(array_sum($serviceSamples) / count($serviceSamples)) : 0,
            ],
            'data' => $formatted,
        ], 'application/json');
    }

    private static function getWhatsAppVoiceReportRealtime(array $obUser, array $queryParams): Response
    {
        $role = strtolower(trim((string)($obUser['user_function'] ?? $obUser['function'] ?? '')));
        $period = strtolower(trim((string)($queryParams['period'] ?? 'day')));
        $status = strtolower(trim((string)($queryParams['status'] ?? '')));

        [$metaWhere, $metaParams] = self::buildWhatsAppMetaVoiceWhere($obUser, $queryParams);
        self::applyVoiceStatusFilter($status, $metaWhere, $metaParams, 'meta');

        try {
            $sourceMode = 'whatsapp_meta';
            $rows = self::queryWhatsAppMetaVoiceRows($metaWhere, $metaParams);
        } catch (\Throwable $e) {
            return new Response(500, [
                'message' => 'Erro ao consultar relatório de voz do WhatsApp',
                'error' => $e->getMessage(),
            ], 'application/json');
        }

        $formatted = array_map(static function (array $row): array {
            $direction = self::normalizeVoiceReportDirection((string)($row['direction'] ?? 'outbound'));
            $dialstatus = self::normalizeVoiceReportStatus((string)($row['dialstatus'] ?? 'UNKNOWN'));
            $price = self::resolveVoiceReportPrice($row);
            $billed = $price > 0;
            $startedAt = self::formatReportDateTime($row['started'] ?? null);
            $answeredAt = self::formatReportDateTime($row['answered'] ?? null);
            $endedAt = self::formatReportDateTime($row['ended'] ?? null);
            $sourceKey = 'whatsapp';

            return [
                'id' => (int)($row['id'] ?? 0),
                'call_id' => trim((string)($row['call_id'] ?? '')) ?: null,
                'client_id' => !empty($row['user_id']) ? (int)$row['user_id'] : null,
                'client_name' => trim((string)($row['user_name'] ?? '')) ?: '-',
                'client_account_code' => trim((string)($row['user_account_code'] ?? '')) ?: '-',
                'source_number' => trim((string)($row['number'] ?? $row['channel_number'] ?? '')) ?: '-',
                'destination' => trim((string)($row['destination'] ?? '')) ?: '-',
                'source_origin' => $sourceKey,
                'source_origin_label' => self::voiceSourceLabel($sourceKey),
                'direction' => $direction,
                'category' => $direction,
                'template_name' => $dialstatus,
                'campaign_name' => trim((string)($row['queue_name'] ?? '')) ?: '-',
                'message_preview' => trim((string)($row['cause_txt'] ?? '')) ?: '',
                'status' => strtolower($dialstatus),
                'dialstatus' => $dialstatus,
                'billed' => $billed,
                'price_brl' => $price,
                'charged_value' => $billed ? $price : 0.0,
                'taxa_of_service' => (float)($row['taxa_of_service'] ?? 0),
                'duration_seconds' => max(0, (int)($row['duration_seconds'] ?? $row['duration'] ?? 0)),
                'queue_id' => trim((string)($row['queue_id'] ?? '')) ?: null,
                'queue_name' => trim((string)($row['queue_name'] ?? '')) ?: 'Sem fila',
                'application' => trim((string)($row['application'] ?? '')) ?: null,
                'sent_at' => $startedAt,
                'answered_at' => $answeredAt,
                'delivered_at' => $endedAt,
            ];
        }, $rows);

        $directionSummary = [
            'inbound' => ['category' => 'inbound', 'quantity' => 0, 'charged_count' => 0, 'charged_total' => 0.0],
            'outbound' => ['category' => 'outbound', 'quantity' => 0, 'charged_count' => 0, 'charged_total' => 0.0],
            'answered' => ['category' => 'answered', 'quantity' => 0, 'charged_count' => 0, 'charged_total' => 0.0],
            'unanswered' => ['category' => 'unanswered', 'quantity' => 0, 'charged_count' => 0, 'charged_total' => 0.0],
        ];

        foreach ($formatted as $row) {
            $direction = in_array($row['direction'], ['inbound', 'outbound'], true) ? $row['direction'] : 'outbound';
            $directionSummary[$direction]['quantity']++;
            if (!empty($row['billed'])) {
                $directionSummary[$direction]['charged_count']++;
                $directionSummary[$direction]['charged_total'] += (float)($row['charged_value'] ?? 0);
            }

            $qualityKey = self::isVoiceAnsweredStatus((string)($row['dialstatus'] ?? '')) ? 'answered' : 'unanswered';
            $directionSummary[$qualityKey]['quantity']++;
            if (!empty($row['billed'])) {
                $directionSummary[$qualityKey]['charged_count']++;
                $directionSummary[$qualityKey]['charged_total'] += (float)($row['charged_value'] ?? 0);
            }
        }

        return new Response(200, [
            'success' => true,
            'report_type' => 'voice',
            'source_mode' => $sourceMode,
            'user_type' => $role,
            'period' => $period,
            'total' => count($formatted),
            'charged_total' => array_sum(array_column($formatted, 'charged_value')),
            'charged_count' => count(array_filter($formatted, static fn(array $row) => !empty($row['billed']))),
            'category_summary' => array_values($directionSummary),
            'data' => $formatted,
        ], 'application/json');
    }

    private static function getWhatsAppVoiceQueueReportRealtime(array $obUser, array $queryParams): Response
    {
        $role = strtolower(trim((string)($obUser['user_function'] ?? $obUser['function'] ?? '')));
        $period = strtolower(trim((string)($queryParams['period'] ?? 'day')));

        [$metaWhere, $metaParams] = self::buildWhatsAppMetaVoiceWhere($obUser, $queryParams);

        try {
            $sourceMode = 'whatsapp_meta';
            $rows = self::queryWhatsAppMetaVoiceQueueRows($metaWhere, $metaParams);
        } catch (\Throwable $e) {
            return new Response(500, [
                'message' => 'Erro ao consultar relatório por fila da voz do WhatsApp',
                'error' => $e->getMessage(),
            ], 'application/json');
        }

        $formatted = array_map(static function (array $row): array {
            $dialstatus = self::normalizeVoiceReportStatus((string)($row['dialstatus'] ?? 'UNKNOWN'));
            $started = self::formatReportDateTime($row['started'] ?? null);
            $answered = self::formatReportDateTime($row['answered'] ?? null);
            $ended = self::formatReportDateTime($row['ended'] ?? null);
            $isAnswered = self::isVoiceAnsweredStatus($dialstatus);
            $statusKey = strtolower($dialstatus ?: 'unknown');
            $sourceKey = 'whatsapp';

            return [
                'id' => (int)($row['id'] ?? 0),
                'call_id' => trim((string)($row['call_id'] ?? '')) ?: null,
                'queue_id' => trim((string)($row['queue_id'] ?? '')) ?: null,
                'queue_name' => trim((string)($row['queue_name'] ?? '')) ?: 'Sem fila',
                'account_label' => trim((string)($row['channel_number'] ?? $row['number'] ?? '')) ?: '-',
                'assigned_agent_user_id' => !empty($row['user_id']) ? (int)$row['user_id'] : null,
                'agent_name' => trim((string)($row['user_name'] ?? '')) ?: 'Sem atendente',
                'contact_name' => trim((string)($row['number'] ?? '')) ?: trim((string)($row['destination'] ?? '')) ?: '-',
                'contact_phone' => trim((string)($row['destination'] ?? '')) ?: '-',
                'last_message' => trim((string)($row['cause_txt'] ?? '')) ?: '',
                'state' => $isAnswered ? 'finished' : 'waiting',
                'call_status_key' => $statusKey,
                'call_status_label' => self::voiceDialStatusLabel($dialstatus),
                'source_origin' => $sourceKey,
                'source_origin_label' => self::voiceSourceLabel($sourceKey),
                'direction' => self::normalizeVoiceReportDirection((string)($row['direction'] ?? 'outbound')),
                'is_vip' => false,
                'wait_seconds' => max(0, (int)($row['wait_seconds'] ?? 0)),
                'service_seconds' => max(0, (int)($row['service_seconds'] ?? 0)),
                'queued_at' => $started,
                'started_at' => $answered,
                'finished_at' => $ended,
                'last_customer_message_at' => $ended,
            ];
        }, $rows);

        $waitSamples = array_values(array_filter(array_map(static fn(array $row) => (int)($row['wait_seconds'] ?? 0), $formatted), static fn(int $value) => $value > 0));
        $serviceSamples = array_values(array_filter(array_map(static fn(array $row) => (int)($row['service_seconds'] ?? 0), $formatted), static fn(int $value) => $value > 0));

        return new Response(200, [
            'success' => true,
            'report_type' => 'voice',
            'source_mode' => $sourceMode,
            'mode' => 'queues',
            'user_type' => $role,
            'period' => $period,
            'total' => count($formatted),
            'stats' => [
                'waiting' => count(array_filter($formatted, static fn(array $row) => ($row['call_status_key'] ?? '') !== 'answer')),
                'active' => 0,
                'finished' => count(array_filter($formatted, static fn(array $row) => ($row['call_status_key'] ?? '') === 'answer')),
                'avg_wait_seconds' => $waitSamples ? (int)round(array_sum($waitSamples) / count($waitSamples)) : 0,
                'avg_service_seconds' => $serviceSamples ? (int)round(array_sum($serviceSamples) / count($serviceSamples)) : 0,
            ],
            'data' => $formatted,
        ], 'application/json');
    }

    private static function buildWhatsAppVoiceWhere(array $obUser, array $queryParams): array
    {
        $role = strtolower(trim((string)($obUser['user_function'] ?? $obUser['function'] ?? '')));
        $period = strtolower(trim((string)($queryParams['period'] ?? 'day')));

        $where = [
            "COALESCE(c.destination, '') <> ''",
            "COALESCE(c.number, c.channel_number, '') <> ''",
        ];
        $params = [];

        if ($role !== 'super_admin') {
            $where[] = 'c.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = (string)($obUser['tenancy_id'] ?? '');
        }

        if (in_array($role, ['agent', 'support_l1'], true)) {
            $where[] = 'c.user_id = :user_id';
            $params[':user_id'] = (int)($obUser['id'] ?? 0);
        } elseif ($role === 'reseller') {
            $where[] = "(
                c.user_id = :reseller_id
                OR c.user_id IN (
                    SELECT u.id
                    FROM users u
                    WHERE u.user_id = :reseller_id
                      AND u.tenancy_id = :reseller_tenancy_id
                )
            )";
            $params[':reseller_id'] = (int)($obUser['id'] ?? 0);
            $params[':reseller_tenancy_id'] = (string)($obUser['tenancy_id'] ?? '');
        }

        $dateColumn = "COALESCE(NULLIF(c.started, '0000-00-00 00:00:00'), NULLIF(c.answered, '0000-00-00 00:00:00'), c.created_at)";
        if (!empty($queryParams['date_from'])) {
            $where[] = "{$dateColumn} >= :date_from";
            $params[':date_from'] = (string)$queryParams['date_from'] . ' 00:00:00';
        }

        if (!empty($queryParams['date_to'])) {
            $where[] = "{$dateColumn} <= :date_to";
            $params[':date_to'] = (string)$queryParams['date_to'] . ' 23:59:59';
        }

        if (empty($queryParams['date_from']) && empty($queryParams['date_to'])) {
            if ($period === 'day') {
                $where[] = "DATE({$dateColumn}) = CURDATE()";
            } elseif ($period === 'week') {
                $where[] = "{$dateColumn} >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
                    AND {$dateColumn} < DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)";
            } elseif ($period === 'month') {
                $where[] = "YEAR({$dateColumn}) = YEAR(CURDATE()) AND MONTH({$dateColumn}) = MONTH(CURDATE())";
            }
        }

        return [$where, $params];
    }

    private static function buildWhatsAppMetaVoiceWhere(array $obUser, array $queryParams): array
    {
        $role = strtolower(trim((string)($obUser['user_function'] ?? $obUser['function'] ?? '')));
        $period = strtolower(trim((string)($queryParams['period'] ?? 'day')));

        $where = ['1=1'];
        $params = [];

        if ($role !== 'super_admin') {
            $where[] = 'wc.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = (string)($obUser['tenancy_id'] ?? '');
        }

        if (in_array($role, ['agent', 'support_l1'], true)) {
            $where[] = 'wa.user_id = :user_id';
            $params[':user_id'] = (int)($obUser['id'] ?? 0);
        } elseif ($role === 'reseller') {
            $where[] = "(
                wa.user_id = :reseller_id
                OR wa.user_id IN (
                    SELECT u.id
                    FROM users u
                    WHERE u.user_id = :reseller_id
                      AND u.tenancy_id = :reseller_tenancy_id
                )
            )";
            $params[':reseller_id'] = (int)($obUser['id'] ?? 0);
            $params[':reseller_tenancy_id'] = (string)($obUser['tenancy_id'] ?? '');
        }

        $dateColumn = "COALESCE(wc.started_at, wc.answered_at, wc.created_at)";
        if (!empty($queryParams['date_from'])) {
            $where[] = "{$dateColumn} >= :date_from";
            $params[':date_from'] = (string)$queryParams['date_from'] . ' 00:00:00';
        }

        if (!empty($queryParams['date_to'])) {
            $where[] = "{$dateColumn} <= :date_to";
            $params[':date_to'] = (string)$queryParams['date_to'] . ' 23:59:59';
        }

        if (empty($queryParams['date_from']) && empty($queryParams['date_to'])) {
            if ($period === 'day') {
                $where[] = "DATE({$dateColumn}) = CURDATE()";
            } elseif ($period === 'week') {
                $where[] = "{$dateColumn} >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
                    AND {$dateColumn} < DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)";
            } elseif ($period === 'month') {
                $where[] = "YEAR({$dateColumn}) = YEAR(CURDATE()) AND MONTH({$dateColumn}) = MONTH(CURDATE())";
            }
        }

        return [$where, $params];
    }

    private static function applyVoiceStatusFilter(string $status, array &$where, array &$params, string $source): void
    {
        if ($status === '') {
            return;
        }

        if ($source === 'meta') {
            if ($status === 'charged') {
                $where[] = '(COALESCE(wc.final_price, 0) > 0)';
            } elseif ($status === 'answered') {
                $where[] = "UPPER(COALESCE(wc.status, '')) IN ('COMPLETED', 'ANSWER', 'ACCEPTED')";
            } elseif ($status === 'noanswer') {
                $where[] = "UPPER(COALESCE(wc.status, '')) IN ('NOANSWER', 'NOT_ANSWERED')";
            } elseif ($status === 'busy') {
                $where[] = "UPPER(COALESCE(wc.status, '')) = 'BUSY'";
            } elseif ($status === 'failed') {
                $where[] = "UPPER(COALESCE(wc.status, '')) IN ('FAILED', 'FAILURE')";
            } elseif ($status === 'cancel') {
                $where[] = "UPPER(COALESCE(wc.status, '')) IN ('CANCEL', 'CANCELLED', 'REJECTED')";
            } elseif ($status === 'inbound') {
                $where[] = "LOWER(COALESCE(wc.direction, '')) = 'inbound'";
            } elseif ($status === 'outbound') {
                $where[] = "LOWER(COALESCE(wc.direction, '')) = 'outbound'";
            }
            return;
        }

        if ($status === 'charged') {
            $where[] = '(COALESCE(c.final_price, 0) > 0 OR COALESCE(c.value, 0) > 0)';
        } elseif ($status === 'answered') {
            $where[] = "UPPER(COALESCE(c.dialstatus, '')) = 'ANSWER'";
        } elseif ($status === 'noanswer') {
            $where[] = "UPPER(COALESCE(c.dialstatus, '')) = 'NOANSWER'";
        } elseif ($status === 'busy') {
            $where[] = "UPPER(COALESCE(c.dialstatus, '')) = 'BUSY'";
        } elseif ($status === 'failed') {
            $where[] = "UPPER(COALESCE(c.dialstatus, '')) = 'FAILED'";
        } elseif ($status === 'cancel') {
            $where[] = "UPPER(COALESCE(c.dialstatus, '')) = 'CANCEL'";
        } elseif (in_array($status, ['inbound', 'outbound'], true)) {
            $where[] = "LOWER(COALESCE(c.direction, '')) = :direction_status";
            $params[':direction_status'] = $status;
        }
    }

    private static function queryWhatsAppVoiceCdrRows(array $where, array $params, bool $strictSource): array
    {
        $queryWhere = $where;
        if ($strictSource) {
            $queryWhere[] = self::whatsAppVoiceSourceClause('c');
        }

        $sql = "SELECT
                c.id,
                c.call_id,
                c.user_id,
                c.user_name,
                c.user_account_code,
                c.channel_number,
                c.number,
                c.destination,
                c.direction,
                c.type,
                c.dialstatus,
                c.cause_txt,
                c.duration,
                c.duration_seconds,
                c.value,
                c.final_price,
                c.taxa_of_service,
                c.started,
                c.answered,
                c.ended,
                c.campaign_type,
                c.application,
                cv.queue_id,
                COALESCE(NULLIF(q.name, ''), NULLIF(qa.queue_name, ''), NULLIF(cv.queue_id, ''), NULLIF(qa.queue_id, '')) AS queue_name
            FROM cdr c
            LEFT JOIN campaign_voice cv ON cv.id = c.campaign_id AND cv.tenancy_id = c.tenancy_id
            LEFT JOIN queues_config q ON q.queue_id = cv.queue_id AND q.tenancy_id = c.tenancy_id
            LEFT JOIN (
                SELECT
                    qm.tenancy_id,
                    qm.agent_ramal,
                    MIN(qm.queue_id) AS queue_id,
                    MIN(qc.name) AS queue_name
                FROM queue_members qm
                LEFT JOIN queues_config qc ON qc.queue_id = qm.queue_id AND qc.tenancy_id = qm.tenancy_id
                GROUP BY qm.tenancy_id, qm.agent_ramal
            ) qa ON qa.tenancy_id = c.tenancy_id AND qa.agent_ramal = c.channel_number
            WHERE " . implode(' AND ', $queryWhere) . "
            ORDER BY COALESCE(NULLIF(c.started, '0000-00-00 00:00:00'), c.created_at) DESC, c.id DESC
            LIMIT 5000";

        return (new Database())->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function buildWhatsAppVoiceCdrSelect(array $where, bool $strictSource): string
    {
        $queryWhere = $where;
        if ($strictSource) {
            $queryWhere[] = self::whatsAppVoiceSourceClause('c');
        }

        return "SELECT
                c.id,
                c.call_id,
                c.user_id,
                c.user_name,
                c.user_account_code,
                c.channel_number,
                c.number,
                c.destination,
                c.direction,
                c.type,
                c.dialstatus,
                c.cause_txt,
                c.duration,
                c.duration_seconds,
                c.value,
                c.final_price,
                c.taxa_of_service,
                c.started,
                c.answered,
                c.ended,
                c.campaign_type,
                c.application,
                cv.queue_id,
                COALESCE(NULLIF(q.name, ''), NULLIF(qa.queue_name, ''), NULLIF(cv.queue_id, ''), NULLIF(qa.queue_id, '')) AS queue_name,
                COALESCE(NULLIF(c.started, '0000-00-00 00:00:00'), c.created_at) AS sort_at
            FROM cdr c
            LEFT JOIN campaign_voice cv ON cv.id = c.campaign_id AND cv.tenancy_id = c.tenancy_id
            LEFT JOIN queues_config q ON q.queue_id = cv.queue_id AND q.tenancy_id = c.tenancy_id
            LEFT JOIN (
                SELECT
                    qm.tenancy_id,
                    qm.agent_ramal,
                    MIN(qm.queue_id) AS queue_id,
                    MIN(qc.name) AS queue_name
                FROM queue_members qm
                LEFT JOIN queues_config qc ON qc.queue_id = qm.queue_id AND qc.tenancy_id = qm.tenancy_id
                GROUP BY qm.tenancy_id, qm.agent_ramal
            ) qa ON qa.tenancy_id = c.tenancy_id AND qa.agent_ramal = c.channel_number
            WHERE " . implode(' AND ', $queryWhere);
    }

    private static function queryWhatsAppVoiceQueueRows(array $where, array $params, bool $strictSource): array
    {
        $queryWhere = $where;
        if ($strictSource) {
            $queryWhere[] = self::whatsAppVoiceSourceClause('c');
        }

        $sql = "SELECT
                c.id,
                c.call_id,
                c.user_id,
                c.user_name,
                c.channel_number,
                c.number,
                c.destination,
                c.direction,
                c.dialstatus,
                c.cause_txt,
                c.started,
                c.answered,
                c.ended,
                COALESCE(NULLIF(cv.queue_id, ''), NULLIF(qa.queue_id, '')) AS queue_id,
                COALESCE(NULLIF(q.name, ''), NULLIF(qa.queue_name, ''), NULLIF(cv.queue_id, ''), NULLIF(qa.queue_id, '')) AS queue_name,
                CASE
                    WHEN c.started IS NULL OR c.answered IS NULL THEN 0
                    ELSE GREATEST(TIMESTAMPDIFF(SECOND, c.started, c.answered), 0)
                END AS wait_seconds,
                CASE
                    WHEN c.answered IS NULL OR c.ended IS NULL THEN 0
                    ELSE GREATEST(TIMESTAMPDIFF(SECOND, c.answered, c.ended), 0)
                END AS service_seconds
            FROM cdr c
            LEFT JOIN campaign_voice cv ON cv.id = c.campaign_id AND cv.tenancy_id = c.tenancy_id
            LEFT JOIN queues_config q ON q.queue_id = cv.queue_id AND q.tenancy_id = c.tenancy_id
            LEFT JOIN (
                SELECT
                    qm.tenancy_id,
                    qm.agent_ramal,
                    MIN(qm.queue_id) AS queue_id,
                    MIN(qc.name) AS queue_name
                FROM queue_members qm
                LEFT JOIN queues_config qc ON qc.queue_id = qm.queue_id AND qc.tenancy_id = qm.tenancy_id
                GROUP BY qm.tenancy_id, qm.agent_ramal
            ) qa ON qa.tenancy_id = c.tenancy_id AND qa.agent_ramal = c.channel_number
            WHERE " . implode(' AND ', $queryWhere) . "
            ORDER BY COALESCE(NULLIF(c.started, '0000-00-00 00:00:00'), c.created_at) DESC, c.id DESC
            LIMIT 5000";

        return (new Database())->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function buildWhatsAppVoiceQueueCdrSelect(array $where, bool $strictSource): string
    {
        $queryWhere = $where;
        if ($strictSource) {
            $queryWhere[] = self::whatsAppVoiceSourceClause('c');
        }

        return "SELECT
                c.id,
                c.call_id,
                c.user_id,
                c.user_name,
                c.channel_number,
                c.number,
                c.destination,
                c.direction,
                c.dialstatus,
                c.cause_txt,
                c.started,
                c.answered,
                c.ended,
                COALESCE(NULLIF(cv.queue_id, ''), NULLIF(qa.queue_id, '')) AS queue_id,
                COALESCE(NULLIF(q.name, ''), NULLIF(qa.queue_name, ''), NULLIF(cv.queue_id, ''), NULLIF(qa.queue_id, '')) AS queue_name,
                CASE
                    WHEN c.started IS NULL OR c.answered IS NULL THEN 0
                    ELSE GREATEST(TIMESTAMPDIFF(SECOND, c.started, c.answered), 0)
                END AS wait_seconds,
                CASE
                    WHEN c.answered IS NULL OR c.ended IS NULL THEN 0
                    ELSE GREATEST(TIMESTAMPDIFF(SECOND, c.answered, c.ended), 0)
                END AS service_seconds,
                c.campaign_type,
                c.application,
                c.type,
                COALESCE(NULLIF(c.started, '0000-00-00 00:00:00'), c.created_at) AS sort_at
            FROM cdr c
            LEFT JOIN campaign_voice cv ON cv.id = c.campaign_id AND cv.tenancy_id = c.tenancy_id
            LEFT JOIN queues_config q ON q.queue_id = cv.queue_id AND q.tenancy_id = c.tenancy_id
            LEFT JOIN (
                SELECT
                    qm.tenancy_id,
                    qm.agent_ramal,
                    MIN(qm.queue_id) AS queue_id,
                    MIN(qc.name) AS queue_name
                FROM queue_members qm
                LEFT JOIN queues_config qc ON qc.queue_id = qm.queue_id AND qc.tenancy_id = qm.tenancy_id
                GROUP BY qm.tenancy_id, qm.agent_ramal
            ) qa ON qa.tenancy_id = c.tenancy_id AND qa.agent_ramal = c.channel_number
            WHERE " . implode(' AND ', $queryWhere);
    }

    private static function queryWhatsAppMetaVoiceRows(array $where, array $params): array
    {
        $sql = "SELECT
                wc.id,
                wc.call_id,
                wa.user_id,
                u.name AS user_name,
                u.account_code AS user_account_code,
                wa.display_phone_number AS channel_number,
                wc.from_number AS number,
                wc.to_number AS destination,
                wc.direction,
                'whatsapp_meta' AS type,
                wc.status AS dialstatus,
                '' AS cause_txt,
                wc.duration_seconds AS duration,
                wc.duration_seconds,
                wc.final_price AS value,
                wc.final_price AS final_price,
                0 AS taxa_of_service,
                wc.started_at AS started,
                wc.answered_at AS answered,
                wc.ended_at AS ended,
                'whatsapp_meta' AS campaign_type,
                'whatsapp_meta_calling' AS application,
                NULL AS queue_id,
                'Sem fila' AS queue_name
            FROM whatsapp_call_cdr wc
            LEFT JOIN whatsapp_accounts wa ON wa.id = wc.account_id
            LEFT JOIN users u ON u.id = wa.user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY COALESCE(wc.started_at, wc.answered_at, wc.created_at) DESC, wc.id DESC
            LIMIT 5000";

        return (new Database())->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function buildWhatsAppMetaVoiceSelect(array $where): string
    {
        return "SELECT
                wc.id,
                wc.call_id,
                wa.user_id,
                u.name AS user_name,
                u.account_code AS user_account_code,
                wa.display_phone_number AS channel_number,
                wc.from_number AS number,
                wc.to_number AS destination,
                wc.direction,
                'whatsapp_meta' AS type,
                wc.status AS dialstatus,
                '' AS cause_txt,
                wc.duration_seconds AS duration,
                wc.duration_seconds,
                wc.final_price AS value,
                wc.final_price AS final_price,
                0 AS taxa_of_service,
                wc.started_at AS started,
                wc.answered_at AS answered,
                wc.ended_at AS ended,
                'whatsapp_meta' AS campaign_type,
                'whatsapp_meta_calling' AS application,
                NULL AS queue_id,
                'Sem fila' AS queue_name,
                COALESCE(wc.started_at, wc.answered_at, wc.created_at) AS sort_at
            FROM whatsapp_call_cdr wc
            LEFT JOIN whatsapp_accounts wa ON wa.id = wc.account_id
            LEFT JOIN users u ON u.id = wa.user_id
            WHERE " . implode(' AND ', $where);
    }

    private static function queryWhatsAppMetaVoiceQueueRows(array $where, array $params): array
    {
        $sql = "SELECT
                wc.id,
                wc.call_id,
                wa.user_id,
                u.name AS user_name,
                wa.display_phone_number AS channel_number,
                wc.from_number AS number,
                wc.to_number AS destination,
                wc.direction,
                wc.status AS dialstatus,
                '' AS cause_txt,
                wc.started_at AS started,
                wc.answered_at AS answered,
                wc.ended_at AS ended,
                NULL AS queue_id,
                'Sem fila' AS queue_name,
                0 AS wait_seconds,
                COALESCE(wc.duration_seconds, 0) AS service_seconds,
                'whatsapp_meta' AS campaign_type,
                'whatsapp_meta_calling' AS application,
                'whatsapp_meta' AS type
            FROM whatsapp_call_cdr wc
            LEFT JOIN whatsapp_accounts wa ON wa.id = wc.account_id
            LEFT JOIN users u ON u.id = wa.user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY COALESCE(wc.started_at, wc.answered_at, wc.created_at) DESC, wc.id DESC
            LIMIT 5000";

        return (new Database())->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function buildWhatsAppMetaVoiceQueueSelect(array $where): string
    {
        return "SELECT
                wc.id,
                wc.call_id,
                wa.user_id,
                u.name AS user_name,
                wa.display_phone_number AS channel_number,
                wc.from_number AS number,
                wc.to_number AS destination,
                wc.direction,
                wc.status AS dialstatus,
                '' AS cause_txt,
                wc.started_at AS started,
                wc.answered_at AS answered,
                wc.ended_at AS ended,
                NULL AS queue_id,
                'Sem fila' AS queue_name,
                0 AS wait_seconds,
                COALESCE(wc.duration_seconds, 0) AS service_seconds,
                'whatsapp_meta' AS campaign_type,
                'whatsapp_meta_calling' AS application,
                'whatsapp_meta' AS type,
                COALESCE(wc.started_at, wc.answered_at, wc.created_at) AS sort_at
            FROM whatsapp_call_cdr wc
            LEFT JOIN whatsapp_accounts wa ON wa.id = wc.account_id
            LEFT JOIN users u ON u.id = wa.user_id
            WHERE " . implode(' AND ', $where);
    }

    private static function queryUnifiedWhatsAppVoiceRows(array $cdrWhere, array $cdrParams, array $metaWhere, array $metaParams, bool $strictSource): array
    {
        [$cdrSql, $prefixedCdrParams] = self::prefixSqlParams(self::buildWhatsAppVoiceCdrSelect($cdrWhere, $strictSource), $cdrParams, 'cdr_');
        [$metaSql, $prefixedMetaParams] = self::prefixSqlParams(self::buildWhatsAppMetaVoiceSelect($metaWhere), $metaParams, 'meta_');

        $sql = "SELECT * FROM (
                    {$cdrSql}
                    UNION ALL
                    {$metaSql}
                ) voice_rows
                ORDER BY voice_rows.sort_at DESC, voice_rows.id DESC
                LIMIT 5000";

        return (new Database())->execute($sql, array_merge($prefixedCdrParams, $prefixedMetaParams))->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function queryUnifiedWhatsAppVoiceQueueRows(array $cdrWhere, array $cdrParams, array $metaWhere, array $metaParams, bool $strictSource): array
    {
        [$cdrSql, $prefixedCdrParams] = self::prefixSqlParams(self::buildWhatsAppVoiceQueueCdrSelect($cdrWhere, $strictSource), $cdrParams, 'cdrq_');
        [$metaSql, $prefixedMetaParams] = self::prefixSqlParams(self::buildWhatsAppMetaVoiceQueueSelect($metaWhere), $metaParams, 'metaq_');

        $sql = "SELECT * FROM (
                    {$cdrSql}
                    UNION ALL
                    {$metaSql}
                ) voice_queue_rows
                ORDER BY voice_queue_rows.sort_at DESC, voice_queue_rows.id DESC
                LIMIT 5000";

        return (new Database())->execute($sql, array_merge($prefixedCdrParams, $prefixedMetaParams))->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function prefixSqlParams(string $sql, array $params, string $prefix): array
    {
        if ($params === []) {
            return [$sql, []];
        }

        $prefixed = [];
        foreach ($params as $name => $value) {
            $cleanName = ltrim((string)$name, ':');
            $newName = ':' . $prefix . $cleanName;
            $sql = str_replace($name, $newName, $sql);
            $prefixed[$newName] = $value;
        }

        return [$sql, $prefixed];
    }

    private static function whatsAppVoiceSourceClause(string $alias): string
    {
        return "(
            LOWER(COALESCE({$alias}.application, '')) LIKE '%whatsapp%'
            OR LOWER(COALESCE({$alias}.campaign_type, '')) LIKE '%whatsapp%'
            OR LOWER(COALESCE({$alias}.type, '')) LIKE '%whatsapp%'
        )";
    }

    private static function detectVoiceSource(array $row): string
    {
        $application = strtolower(trim((string)($row['application'] ?? '')));
        $campaignType = strtolower(trim((string)($row['campaign_type'] ?? '')));
        $type = strtolower(trim((string)($row['type'] ?? '')));
        $callId = trim((string)($row['call_id'] ?? ''));

        if (
            str_contains($application, 'whatsapp')
            || str_contains($campaignType, 'whatsapp')
            || str_contains($type, 'whatsapp')
        ) {
            return 'whatsapp';
        }

        if ($application === 'app-asterisk' || in_array($type, ['normal', 'outbound', 'inbound'], true)) {
            return 'webrtc';
        }

        if ($callId !== '' && $callId !== '-') {
            return 'whatsapp';
        }

        return 'webrtc';
    }

    private static function voiceSourceLabel(string $source): string
    {
        return match (strtolower(trim($source))) {
            'whatsapp' => 'WhatsApp',
            'webrtc' => 'WebRTC',
            default => 'Indefinido',
        };
    }

    private static function normalizeVoiceReportDirection(string $direction): string
    {
        return match (strtoupper(trim($direction))) {
            'USER_INITIATED', 'INBOUND' => 'inbound',
            'BUSINESS_INITIATED', 'OUTBOUND' => 'outbound',
            default => strtolower(trim($direction)) ?: 'outbound',
        };
    }

    private static function normalizeVoiceReportStatus(string $status): string
    {
        return match (strtoupper(trim($status))) {
            'COMPLETED' => 'ANSWER',
            'ACCEPTED' => 'ANSWER',
            'REJECTED' => 'CANCEL',
            default => strtoupper(trim($status)) ?: 'UNKNOWN',
        };
    }

    private static function isVoiceAnsweredStatus(string $status): bool
    {
        return self::normalizeVoiceReportStatus($status) === 'ANSWER';
    }

    private static function resolveVoiceReportPrice(array $row): float
    {
        $finalPrice = (float)($row['final_price'] ?? 0);
        $value = (float)($row['value'] ?? 0);

        if ($finalPrice > 0) {
            return $finalPrice;
        }

        if ($value > 0) {
            return $value;
        }

        return max($finalPrice, $value, 0);
    }

    private static function voiceDialStatusLabel(string $status): string
    {
        return match (strtoupper(trim($status))) {
            'ANSWER' => 'Atendida',
            'NOANSWER' => 'Não atendida',
            'BUSY' => 'Ocupado',
            'FAILED' => 'Falhou',
            'CANCEL' => 'Cancelada',
            default => trim($status) !== '' ? ucfirst(strtolower($status)) : 'Sem status',
        };
    }

    private static function formatReportDateTime(?string $value): string
    {
        if (empty($value) || $value === '0000-00-00 00:00:00') {
            return '-';
        }

        try {
            return (new DateTime($value))->format('d/m/Y H:i');
        } catch (\Throwable) {
            return '-';
        }
    }

    private static function reportRowTimestamp(array $row, array $fields = ['sent_at', 'delivered_at', 'answered_at']): int
    {
        foreach ($fields as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value === '' || $value === '-') {
                continue;
            }

            $date = \DateTime::createFromFormat('d/m/Y H:i', $value);
            if ($date instanceof \DateTime) {
                return $date->getTimestamp();
            }
        }

        return 0;
    }

    public static function getRechargeResellers($request): Response
    {
        // 1. Pega o usuário logado e sua Tenancy
        $obUser = SessionUser::getLogged();
        $tenancyId = $obUser['tenancy_id'];

        // 2. Prepara os filtros para o Model
        // Como é o painel de histórico, filtramos apenas pela Tenancy
        $filters = [
            'tenancy_id' => $tenancyId
        ];

        // 3. Busca os dados usando o seu novo método do Model
        $data = RefillsResellers::getTransactions($filters);

        // 4. Retorna a resposta no padrão Pix (JSON limpo para o JS)
        return new Response(200, [
            'status'  => 200,
            'success' => true,
            'data'    => $data
        ], 'application/json');
    }

    public static function downloadRechargePDF($request, int $id): void
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            http_response_code(401);
            exit;
        }

        // 1. Busca dados da recarga (Exatamente como estava na sua)
        $recharge = RefillsResellers::getById($id);
        if (!$recharge) {
            http_response_code(404);
            echo "Comprovante não encontrado";
            exit;
        }

        // 2. Busca dados do favorecido (Quem recebeu a recarga)
        // Usamos o tenancy_id do admin logado para buscar o usuário que recebeu
        $resellers = UserSearch::getResellers($obUser['tenancy_id'], $recharge->user_id);
        $reseller = $resellers[0] ?? null;

        //echo "<pre>";
        //print_r($reseller);
        //echo "</pre>";exit();

        if (!$reseller) {
            http_response_code(404);
            echo "Favorecido não encontrado";
            exit;
        }

        // 3. Lógica de Tarifas (Baseada no seu código de Rates/BalanceSms)
        $isReseller = (strtolower(trim($reseller['user_function'] ?? '')) === 'reseller');

        if ($isReseller) {
            $ratesAll = Rates::getActiveRatesByUser($obUser['tenancy_id'], $recharge->user_id);
            $rVoice    = (float)($ratesAll['voice'] ?? 0);
            $rSms      = (float)($ratesAll['sms'] ?? 0);
            $rTorpedo  = (float)($ratesAll['torpedo'] ?? 0);
        } else {
            $obBalanceTariffs = BalanceSms::getBalanceSms($recharge->user_id, $obUser['tenancy_id']);
            $rVoice    = (float)($obBalanceTariffs->value_voice ?? 0);
            $rSms      = (float)($obBalanceTariffs->value_sms ?? 0);
            $rTorpedo  = (float)($obBalanceTariffs->value_torpedo ?? 0);
        }
        $rWhatsApp = self::whatsAppCategoryTariffs((int)$recharge->user_id, (string)$obUser['tenancy_id']);

        // 4. Monta o Array de Dados para o Novo Template
        // Note: Usei os nomes que seu template de "Cards" pediu
        $data = [
            'transaction_id' => $recharge->transaction_id,
            'tenancy_id'     => $obUser['tenancy_id'],
            'date'           => date('d/m/Y H:i', strtotime($recharge->created_at)),
            'name'           => $reseller['name'] . ' ' . ($reseller['last_name'] ?? ''),
            'email'          => $reseller['email'],
            'logo_url'       => rtrim((defined('VIEW_URL') ? VIEW_URL : URL), '/') . '/resources/assets/img/profile-mxx.png',
            'company_name'   => 'Maxx Solutions',
            'amount'         => number_format($recharge->balance, 2, ',', '.'),
            'status'         => strtoupper($recharge->status),
            'method'         => strtoupper($recharge->type ?? 'PIX'),

            // Tarifas
            'rate_voice'     => number_format($rVoice, 4, ',', '.'),
            'rate_sms'       => number_format($rSms, 4, ',', '.'),
            'rate_torpedo'   => number_format($rTorpedo, 4, ',', '.'),
            'rate_whatsapp_marketing' => number_format($rWhatsApp['marketing'], 4, ',', '.'),
            'rate_whatsapp_utility' => number_format($rWhatsApp['utility'], 4, ',', '.'),
            'rate_whatsapp_authentication' => number_format($rWhatsApp['authentication'], 4, ',', '.'),
            'rate_whatsapp_service' => number_format($rWhatsApp['service'], 4, ',', '.'),

            // SALDO REAL DO REVENDEDOR (Vem do banco agora)
            'new_balance'    => number_format((float)($reseller['reseller_balance'] ?? 0), 2, ',', '.')
        ];

        // 5. Chama o visual novo
        self::renderInvoiceTemplate($data);
    }

    private static function whatsAppCategoryTariffs(int $userId, string $tenancyId): array
    {
        try {
            return WhatsAppBilling::categoryPricesForUser($userId, $tenancyId);
        } catch (\Throwable $e) {
            return [
                'marketing' => WhatsAppCostPolicy::defaultPriceBrl(WhatsAppCostPolicy::CATEGORY_MARKETING),
                'utility' => WhatsAppCostPolicy::defaultPriceBrl(WhatsAppCostPolicy::CATEGORY_UTILITY),
                'authentication' => WhatsAppCostPolicy::defaultPriceBrl(WhatsAppCostPolicy::CATEGORY_AUTHENTICATION),
                'service' => 0.0,
            ];
        }
    }

    private static function renderInvoiceTemplate($d): void
    {
        // Definindo a cor e o texto do status dinamicamente
        $statusColor = '#059669'; // Emerald (Default)
        $statusBg = '#ecfdf5';

        if ($d['status'] === 'PENDING') {
            $statusColor = '#d97706'; // Amber
            $statusBg = '#fffbeb';
        } elseif (in_array($d['status'], ['FAILED', 'CANCELLED'])) {
            $statusColor = '#e11d48'; // Rose
            $statusBg = '#fff1f2';
        }

        // URL da Logo (Se não tiver no banco, usa um placeholder ou texto)
        $logoUrl = $d['logo_url'] ?? 'https://via.placeholder.com/150x50?text=SISTEMA+VOIP';

        echo <<<HTML
            <!DOCTYPE html>
            <html lang="pt-br">
            <head>
                <meta charset="UTF-8">
                <title>Comprovante_{$d['transaction_id']}</title>
                <style>
                    body { font-family: 'Inter', system-ui, sans-serif; color: #334155; margin: 0; padding: 0; background: #f1f5f9; }
                    
                    /* Marca d'água */
                    .watermark {
                        position: absolute;
                        top: 50%;
                        left: 50%;
                        transform: translate(-50%, -50%) rotate(-45deg);
                        font-size: 80px;
                        font-weight: 900;
                        color: rgba(0, 0, 0, 0.03);
                        white-space: nowrap;
                        pointer-events: none;
                        text-transform: uppercase;
                        z-index: 0;
                    }
        
                    .invoice-box { 
                        max-width: 700px; 
                        margin: 40px auto; 
                        padding: 50px; 
                        background: #fff; 
                        border-radius: 24px; 
                        position: relative;
                        box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
                        overflow: hidden;
                    }
        
                    .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px; position: relative; z-index: 1; }
                    .logo-img { max-height: 45px; filter: grayscale(10%) contrast(110%); }
                    
                    .status-badge { 
                        background: {$statusBg}; 
                        color: {$statusColor}; 
                        padding: 8px 16px; 
                        border-radius: 12px; 
                        font-size: 11px; 
                        font-weight: 800; 
                        letter-spacing: 0.5px;
                    }
        
                    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px; position: relative; z-index: 1; }
                    
                    h4 { font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1.5px; margin: 0 0 8px 0; }
                    p { font-size: 14px; font-weight: 600; margin: 0; color: #1e293b; }
        
                    .amount-card { 
                        background: #f8fafc; 
                        padding: 30px; 
                        border-radius: 20px; 
                        text-align: center; 
                        border: 1px solid #f1f5f9;
                        margin: 20px 0;
                        position: relative; z-index: 1;
                    }
                    .amount-card h1 { font-size: 42px; margin: 5px 0; color: #0f172a; letter-spacing: -1px; }
        
                    .qr-section { 
                        display: flex; 
                        justify-content: space-between; 
                        align-items: flex-end; 
                        margin-top: 40px; 
                        padding-top: 30px; 
                        border-top: 2px dashed #f1f5f9;
                        position: relative; z-index: 1;
                    }
        
                    .footer { text-align: center; margin-top: 50px; font-size: 11px; color: #cbd5e1; }
        
                    @media print {
                        body { background: #fff; }
                        .no-print { display: none; }
                        .invoice-box { box-shadow: none; border: none; margin: 0; width: 100%; }
                    }
                </style>
            </head>
            <body>
                <div class="no-print" style="text-align:center; padding: 30px;">
                    <button onclick="window.print()" style="padding: 12px 24px; background: #0f172a; color: #fff; border: none; border-radius: 12px; cursor: pointer; font-weight: bold; transition: 0.3s;">
                        🖨️ Imprimir Comprovante
                    </button>
                </div>
        
                <div class="invoice-box">
                    <div class="watermark">{$d['status']}</div>
        
                    <div class="header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px;">
                        <div class="logo">
                            <img src="{$d['logo_url']}" alt="{$d['company_name']}" style="max-height: 60px; width: auto; border-radius: 8px;">
                        </div>
                        <div class="status-badge" style="background: #ecfdf5; color: #059669; padding: 8px 16px; border-radius: 12px; font-size: 11px; font-weight: 800;">
                            ● {$d['status']}
                        </div>
                    </div>
        
                    <div class="grid">
                        <div>
                            <h4>ID DA TRANSAÇÃO</h4>
                            <p>#{$d['transaction_id']}</p>
                        </div>
                        <div style="text-align: right;">
                            <h4>DATA E HORA</h4>
                            <p>{$d['date']}</p>
                        </div>
                    </div>
        
                    <div class="grid">
                        <div>
                            <h4>CLIENTE / BENEFICIÁRIO</h4>
                            <p>{$d['name']}</p>
                            <span style="font-size: 12px; color: #64748b;">{$d['email']}</span>
                        </div>
                        <div style="text-align: right;">
                            <h4>MÉTODO</h4>
                            <p>{$d['method']}</p>
                        </div>
                    </div>
        
                    <div class="amount-card">
                        <h4>VALOR RECARREGADO</h4>
                        <h1>R$ {$d['amount']}</h1>
                        
                        <div style="margin-top: 25px; padding-top: 20px; border-top: 1px dashed #e2e8f0;">
                            <h4 style="font-size: 9px; margin-bottom: 15px; text-align: center;">Tarifas Atuais do seu Plano</h4>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div style="background: #fff; border: 1px solid #f1f5f9; padding: 10px; border-radius: 12px; text-align: center;">
                                    <h4 style="font-size: 7px; color: #94a3b8;">SMS</h4>
                                    <p style="font-size: 11px; color: #2563eb;">R$ {$d['rate_sms']}</p>
                                </div>
                                <div style="background: #fff; border: 1px solid #f1f5f9; padding: 10px; border-radius: 12px; text-align: center;">
                                    <h4 style="font-size: 7px; color: #94a3b8;">WHATSAPP MARKETING</h4>
                                    <p style="font-size: 11px; color: #2563eb;">R$ {$d['rate_whatsapp_marketing']}</p>
                                </div>
                                <div style="background: #fff; border: 1px solid #f1f5f9; padding: 10px; border-radius: 12px; text-align: center;">
                                    <h4 style="font-size: 7px; color: #94a3b8;">WHATSAPP UTILITARIO</h4>
                                    <p style="font-size: 11px; color: #2563eb;">R$ {$d['rate_whatsapp_utility']}</p>
                                </div>
                                <div style="background: #fff; border: 1px solid #f1f5f9; padding: 10px; border-radius: 12px; text-align: center;">
                                    <h4 style="font-size: 7px; color: #94a3b8;">WHATSAPP AUTENTICACAO</h4>
                                    <p style="font-size: 11px; color: #2563eb;">R$ {$d['rate_whatsapp_authentication']}</p>
                                </div>
                                <div style="background: #fff; border: 1px solid #f1f5f9; padding: 10px; border-radius: 12px; text-align: center;">
                                    <h4 style="font-size: 7px; color: #94a3b8;">WHATSAPP ATENDIMENTO</h4>
                                    <p style="font-size: 11px; color: #2563eb;">R$ {$d['rate_whatsapp_service']}</p>
                                </div>
                                <div style="background: #fff; border: 1px solid #f1f5f9; padding: 10px; border-radius: 12px; text-align: center;">
                                    <h4 style="font-size: 7px; color: #94a3b8;">VOZ</h4>
                                    <p style="font-size: 11px; color: #2563eb;">R$ {$d['rate_voice']}</p>
                                </div>
                                <div style="background: #fff; border: 1px solid #f1f5f9; padding: 10px; border-radius: 12px; text-align: center;">
                                    <h4 style="font-size: 7px; color: #94a3b8;">TORPEDO</h4>
                                    <p style="font-size: 11px; color: #2563eb;">R$ {$d['rate_torpedo']}</p>
                                </div>
                            </div>
                        </div>
                    
                        <div style="margin-top: 20px; background: #0f172a; padding: 15px; border-radius: 14px; display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 10px; color: #94a3b8; font-weight: bold; text-transform: uppercase;">Novo Saldo Unificado</span>
                            <span style="font-size: 18px; color: #22c55e; font-weight: 800;">R$ {$d['new_balance']}</span>
                        </div>
                    </div>
        
                    <div class="qr-section">
                        <div style="max-width: 60%;">
                            <h4>AUTENTICAÇÃO</h4>
                            <p style="font-size: 10px; color: #94a3b8; font-family: monospace; word-break: break-all;">
                                SEC-AUTH-{$d['transaction_id']}-TEN-{$d['tenancy_id']}
                            </p>
                        </div>
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=TRANS_{$d['transaction_id']}" style="border-radius: 12px; border: 4px solid #f8fafc;">
                    </div>
        
                    <div class="footer">
                        Este documento é uma representação digital de uma transação financeira interna.<br>
                        Gerado de forma segura pelo Sistema VoIP.
                    </div>
                </div>
            </body>
            </html>
        HTML;
    }

    public static function getCdrCallsAnalysis(): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, ['message' => "Usuário não autenticado"], 'application/json');
        }

        $userId   = $obUser['id'];
        $role     = self::resolveUserRole($obUser);
        $tenancy  = $obUser['tenancy_id'];

        // ============================================================
        // 🛡️ PREPARAÇÃO DOS FILTROS (ENVIANDO A FUNÇÃO CORRETA)
        // ============================================================
        $filters = [
            'tenancy_id'    => $tenancy,
            'user_function' => $role
        ];

        // 🚀 O PULO DO GATO:
        // Se NÃO for admin/gerencial, aí sim fixamos o user_id.
        // Se for admin, não mandamos user_id para o Model trazer tudo da Tenancy.
        if (!in_array($role, ['admin', 'super_admin', 'manager', 'supervisor', 'financial', 'rh'])) {
            $filters['user_id'] = $userId;
        }

        // Processa o Redis para garantir que a chamada que acabou de cair apareça
        Voice::processCdrFromRedis();

        try {
            // Chamada ao Model que já corrigimos (usando user_id como 3º parâmetro no Helper)
            $cdr = CdrVoice::getCdrVoice($filters, "c.created_at DESC");
        } catch (\Exception $e) {
            return new Response(500, [
                'message' => "Erro ao consultar CDR",
                'error'   => $e->getMessage()
            ], 'application/json');
        }

        // ==============================
        // 🔄 FORMATAÇÃO (Sua lógica original)
        // ==============================
        $formatted = self::formatVoiceCdrRows($cdr);

        return new Response(200, [
            'success' => true,
            'total'   => count($formatted),
            'data'    => $formatted
        ], 'application/json');
    }

    private static function normalizeVoiceCdrDisplayNumber(mixed $value): string
    {
        $raw = trim((string)($value ?? ''));
        if ($raw === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $raw) ?: '';
        if ($digits === '') {
            return $raw;
        }

        if (str_starts_with($digits, '55')) {
            $national = substr($digits, 2);
            if (preg_match('/^\d{10,11}$/', $national)) {
                return $national;
            }
        }

        return $digits;
    }

    private static function formatVoiceCdrRows(array $rows): array
    {
        $formatted = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $cid = self::resolveVoiceCdrRowCid($row);
            $destination = self::resolveVoiceCdrRowDestination($row);
            $channelNumber = self::resolveVoiceCdrRowSipUser($row);

            $formatted[] = [
                'id'                => $row['id'] ?? null,
                'channel_id'        => $row['channel_id'] ?? null,
                'tenancy_id'        => $row['tenancy_id'] ?? null,
                'user_id'           => $row['user_id'] ?? null,
                'user_name'         => trim((string)($row['user_name'] ?? '')) ?: null,
                'user_account_code' => trim((string)($row['user_account_code'] ?? '')) ?: null,
                'number'            => self::normalizeVoiceCdrDisplayNumber($cid),
                'endpoints'         => trim((string)($row['endpoints'] ?? '')) ?: '',
                'destination'       => self::normalizeVoiceCdrDisplayNumber($destination),
                'channelNumber'     => self::normalizeVoiceCdrDisplayNumber($channelNumber),
                'type'              => $row['type'] ?? null,
                'dialstatus'        => $row['dialstatus'] ?? null,
                'cause'             => $row['cause'] ?? null,
                'cause_txt'         => $row['cause_txt'] ?? null,
                'taxa'              => (float)($row['taxa_of_service'] ?? 0),
                'duration'          => (int)($row['duration'] ?? 0),
                'value'             => (float)($row['value'] ?? 0),
                'started'           => !empty($row['started']) ? (new DateTime($row['started']))->format('d-m-Y H:i:s') : '-',
                'answered'          => $row['answered'] ?? null,
                'ended'             => $row['ended'] ?? null,
                'created_at'        => $row['created_at'] ?? null,
                '_sort_ts'          => self::voiceCdrSortTimestamp([$row]),
            ];
        }

        usort($formatted, static function (array $a, array $b): int {
            $sortA = (int)($a['_sort_ts'] ?? 0);
            $sortB = (int)($b['_sort_ts'] ?? 0);

            if ($sortA === $sortB) {
                return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
            }

            return $sortB <=> $sortA;
        });

        return array_map(static function (array $row): array {
            unset($row['_sort_ts']);
            return $row;
        }, $formatted);
    }

    private static function resolveVoiceCdrRowCid(array $row): string
    {
        $candidates = [
            $row['number'] ?? null,
            $row['callerid_num'] ?? null,
            $row['destination'] ?? null,
            $row['channel_number'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = self::normalizeVoiceCdrDisplayNumber($candidate);
            if ($normalized === '' || self::isVoiceCdrExtensionValue($normalized)) {
                continue;
            }

            return $normalized;
        }

        foreach ($candidates as $candidate) {
            $normalized = self::normalizeVoiceCdrDisplayNumber($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private static function resolveVoiceCdrRowDestination(array $row): string
    {
        $candidates = [
            $row['destination'] ?? null,
            $row['channel_number'] ?? null,
            $row['callerid_num'] ?? null,
            $row['number'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = self::normalizeVoiceCdrDisplayNumber($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private static function resolveVoiceCdrRowSipUser(array $row): string
    {
        $candidates = [
            $row['channel_number'] ?? null,
            $row['destination'] ?? null,
            $row['callerid_num'] ?? null,
            $row['endpoints'] ?? null,
            $row['number'] ?? null,
        ];

        $fallback = '';

        foreach ($candidates as $candidate) {
            $normalized = self::normalizeVoiceCdrDisplayNumber($candidate);
            if ($normalized === '') {
                continue;
            }

            if (self::isVoiceCdrExtensionValue($normalized)) {
                return $normalized;
            }

            if ($fallback === '') {
                $fallback = $normalized;
            }
        }

        return $fallback;
    }

    private static function aggregateVoiceCdrRows(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $callKey = trim((string)($row['call_id'] ?? ''));
            if ($callKey === '') {
                $callKey = trim((string)($row['channel_id'] ?? ''));
            }
            if ($callKey === '') {
                $callKey = 'row:' . (string)($row['id'] ?? uniqid('', true));
            }

            $grouped[$callKey][] = $row;
        }

        $formatted = [];
        foreach ($grouped as $callRows) {
            $base = self::pickVoiceCdrRepresentativeRow($callRows);

            $cid = self::pickVoiceCdrValue($callRows, 'callerid_num', false);
            if ($cid === '') {
                $cid = self::pickVoiceCdrValue($callRows, 'number', false);
            }
            $destination = self::pickVoiceCdrValue($callRows, 'destination', false);
            $channelNumber = self::pickVoiceCdrValue($callRows, 'channel_number', true);
            $endpoints = self::pickVoiceCdrValue($callRows, 'endpoints', false);
            $userName = self::pickAnyVoiceCdrValue($callRows, 'user_name');
            $userAccountCode = self::pickAnyVoiceCdrValue($callRows, 'user_account_code');

            $formatted[] = [
                'id'                => $base['id'],
                'channel_id'        => $base['channel_id'],
                'tenancy_id'        => $base['tenancy_id'],
                'user_id'           => $base['user_id'],
                'user_name'         => $userName,
                'user_account_code' => $userAccountCode,
                'number'            => self::normalizeVoiceCdrDisplayNumber($cid),
                'endpoints'         => $endpoints,
                'destination'       => self::normalizeVoiceCdrDisplayNumber($destination),
                'channelNumber'     => self::normalizeVoiceCdrDisplayNumber($channelNumber),
                'type'              => $base['type'],
                'dialstatus'        => $base['dialstatus'],
                'cause'             => $base['cause'],
                'cause_txt'         => $base['cause_txt'],
                'taxa'              => (float)($base['taxa_of_service'] ?? 0),
                'duration'          => (int)($base['duration'] ?? 0),
                'value'             => (float)($base['value'] ?? 0),
                'started'           => !empty($base['started']) ? (new DateTime($base['started']))->format('d-m-Y H:i:s') : '-',
                'answered'          => $base['answered'],
                'ended'             => $base['ended'],
                'created_at'        => $base['created_at'],
                '_sort_ts'          => self::voiceCdrSortTimestamp($callRows),
            ];
        }

        usort($formatted, static function (array $a, array $b): int {
            $sortA = (int)($a['_sort_ts'] ?? 0);
            $sortB = (int)($b['_sort_ts'] ?? 0);

            if ($sortA === $sortB) {
                return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
            }

            return $sortB <=> $sortA;
        });

        return array_map(static function (array $row): array {
            unset($row['_sort_ts']);
            return $row;
        }, $formatted);
    }

    private static function pickVoiceCdrRepresentativeRow(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $scoreA = self::voiceCdrRowScore($a);
            $scoreB = self::voiceCdrRowScore($b);

            if ($scoreA === $scoreB) {
                return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
            }

            return $scoreB <=> $scoreA;
        });

        return $rows[0];
    }

    private static function voiceCdrRowScore(array $row): int
    {
        $score = 0;

        if (strtoupper((string)($row['dialstatus'] ?? '')) === 'ANSWER') {
            $score += 100;
        }

        if ((float)($row['value'] ?? 0) > 0) {
            $score += 80;
        }

        if ((int)($row['duration'] ?? 0) > 0) {
            $score += 40;
        }

        if (!self::isVoiceCdrExtensionValue($row['destination'] ?? null)) {
            $score += 20;
        }

        if (!self::isVoiceCdrExtensionValue($row['number'] ?? null)) {
            $score += 10;
        }

        return $score;
    }

    private static function pickVoiceCdrValue(array $rows, string $field, bool $preferExtension): string
    {
        $fallback = '';

        foreach ($rows as $row) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value === '') {
                continue;
            }

            $normalized = self::normalizeVoiceCdrDisplayNumber($value);
            if ($normalized === '') {
                continue;
            }

            $isExtension = self::isVoiceCdrExtensionValue($normalized);
            if ($preferExtension === $isExtension) {
                return $normalized;
            }

            if ($fallback === '') {
                $fallback = $normalized;
            }
        }

        return $fallback;
    }

    private static function pickAnyVoiceCdrValue(array $rows, string $field): ?string
    {
        foreach ($rows as $row) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function isVoiceCdrExtensionValue(mixed $value): bool
    {
        $digits = preg_replace('/\D+/', '', (string)($value ?? '')) ?: '';
        if ($digits === '') {
            return false;
        }

        $normalized = ltrim($digits, '0');
        if ($normalized === '') {
            $normalized = '0';
        }

        $len = strlen($normalized);
        return $len >= 3 && $len <= 8;
    }

    private static function voiceCdrSortTimestamp(array $rows): int
    {
        $best = 0;

        foreach ($rows as $row) {
            foreach (['ended', 'answered', 'started', 'created_at'] as $field) {
                $raw = trim((string)($row[$field] ?? ''));
                if ($raw === '' || $raw === '0000-00-00 00:00:00') {
                    continue;
                }

                $timestamp = strtotime($raw);
                if ($timestamp !== false && $timestamp > $best) {
                    $best = $timestamp;
                }
            }
        }

        return $best;
    }



}
