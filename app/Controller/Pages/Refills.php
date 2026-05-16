<?php

namespace App\Controller\Pages;

use App\Config\AsaasConfig;
use App\Http\Response;
use App\Model\Entity\RefillsResellers;
use App\Model\Entity\PlanCatalog;
use App\Model\Entity\UserSearch;
use App\Session\User as SessionUser;
use App\Utils\View;
use App\Model\Entity\UserPlans;
use App\Model\Entity\PixSearch;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\Notifications;
use App\Model\Entity\RegisterTenancies;
use App\Service\AsteriskBalanceSyncService;
use App\Service\ManualTopupService;
use App\Service\PixService;
use Random\RandomException;

class Refills extends ViewComponents
{

    public static function getRefills($request): string
    {
        $content = View::render('refills/index', []);

        return parent::getComponentsRefills('Maxx Solutions - SMS | Recargas', $content);
    }

    public static function getComponentsPlains($request, $type): Response
    {
        PlanCatalog::ensureSchema();
        $normalizedType = strtolower(trim((string)$type));
        $plans = in_array($normalizedType, ['all', '*', 'todos', 'todas', 'catalog'], true)
            ? UserPlans::getAllActivePlans(null)
            : UserPlans::getAllActivePlans($normalizedType);


        $formatted = [];

        foreach ($plans as $p) {
            $planRow = PlanCatalog::normalizeRow(get_object_vars($p));
            $planName = strtolower(trim((string)($p->name_plan ?? '')));
            $planSlug = strtolower(trim((string)($p->slug ?? '')));

            if ($planName === 'bootstrap' || $planSlug === 'bootstrap-admin-bootstrap') {
                continue;
            }

            $campaignsLimit = $p->campaigns_limit ?? $p->camp_qtd ?? 0;
            $reportsLabel = $p->reports_label ?? $p->rports ?? '';
            $enabledModules = self::enabledPlanModules((array)($planRow['modules'] ?? []));

            $formatted[] = [
                'id'          => $p->id,
                'name'        => $p->name_plan,
                'desc'        => $p->description,
                'price'       => number_format($p->amount_plan, 2, ',', '.'),
                'is_popular'  => (bool)$p->is_popular,
                'service_fee' => number_format((float)($p->service_fee ?? 0), 2, ',', '.'),
                'route_fee'   => number_format((float)($p->service_fee ?? 0), 4, ',', '.'),
                'price_raw'   => $p->amount_plan, // Para o Modal
                'type'        => $p->type_plan,
                'payment_type' => $p->payment_type ?? '',
                // Tarifas para o Modal
                'v_sms'       => number_format($p->value_sms, 4, ',', '.'),
                'v_voice'     => number_format((float)($p->voice_smart_rate ?? $p->value_voice), 4, ',', '.'),
                'v_voice_bina' => number_format((float)($p->voice_smart_rate ?? $p->value_voice), 4, ',', '.'),
                'v_voice_cli' => number_format((float)($p->voice_open_rate ?? $p->value_voice), 4, ',', '.'),
                'v_voice_open' => number_format((float)($p->voice_open_rate ?? $p->value_voice), 4, ',', '.'),
                'v_voice_smart' => number_format((float)($p->voice_smart_rate ?? $p->value_voice), 4, ',', '.'),
                'v_whatsapp'  => number_format($p->value_whatsapp, 4, ',', '.'),
                'v_whatsapp_marketing' => number_format((float)($p->value_whatsapp_marketing ?? $p->value_whatsapp), 4, ',', '.'),
                'v_whatsapp_utility' => number_format((float)($p->value_whatsapp_utility ?? $p->value_whatsapp), 4, ',', '.'),
                'v_whatsapp_authentication' => number_format((float)($p->value_whatsapp_authentication ?? $p->value_whatsapp), 4, ',', '.'),
                'v_whatsapp_voice' => number_format((float)($p->whatsapp_voice_price_per_minute ?? 0), 4, ',', '.'),
                'v_torpedo'   => number_format($p->value_torpedo, 4, ',', '.'),
                // Detalhes Técnicos
                'users_y_n'   => ($p->users_create == 'y' ? 'Sim' : 'Não'),
                'access'      => ($p->simultaneous_access == -1 ? 'Ilimitado' : $p->simultaneous_access),
                'camp'        => ((int)$campaignsLimit === -1 ? 'Ilimitado' : (int)$campaignsLimit),
                'trunks'      => ((int)($p->trunks ?? 0) === -1 ? 'Ilimitado' : (int)($p->trunks ?? 0)),
                'whatsapp_accounts' => ((int)($p->whatsapp_accounts ?? 0) === -1 ? 'Ilimitado' : (int)($p->whatsapp_accounts ?? 0)),
                'reports'     => $reportsLabel,
                'modules_count' => count($enabledModules),
                'modules_labels' => array_values($enabledModules),
                'users_limit' => self::formatLimit((int)($planRow['users_limit'] ?? 0)),
                'sms_limit' => self::formatLimit((int)($planRow['sms_limit'] ?? 0)),
                'voice_limit' => self::formatLimit((int)($planRow['voice_limit'] ?? 0)),
                'templates_limit' => self::formatLimit((int)($planRow['templates_limit'] ?? 0)),
            ];
        }


        $response = PixService::success(['plans' => $formatted], 'Planos encontrados.');
        $response['plans'] = $formatted;

        return new Response(200, $response, 'application/json');


    }

    private static function enabledPlanModules(array $modules): array
    {
        $labels = [
            'administrative' => 'Administrativo',
            'users' => 'Usuarios',
            'permissions' => 'Permissoes',
            'rates' => 'Tarifas',
            'reports' => 'Relatorios',
            'sms' => 'SMS',
            'voice' => 'Voz',
            'callcenter' => 'Call Center',
            'whatsapp' => 'WhatsApp',
            'templates' => 'Templates',
            'trunks' => 'Trunks',
        ];

        $enabled = [];
        foreach ($labels as $key => $label) {
            if (!empty($modules[$key])) {
                $enabled[$key] = $label;
            }
        }

        return $enabled;
    }

    private static function formatLimit(int $value): string
    {
        if ($value === -1) {
            return 'Ilimitado';
        }

        return (string)$value;
    }


    /*public static function getQrCodePix($request, $id): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $obApiSaas = new AssasApi(getenv('ASASURL'), getenv('ASASKEY'));

        $obPlan = UserPlans::getPlanById($id);
        if (!$obPlan instanceof UserPlans) {
            return new Response(404, [
                'status' => 404,
                'message' => 'Plano não encontrado.'
            ], 'application/json');
        }

        $userPlanId = UserPlans::createUserPlan([
            'user_id' => $obUser['id'],
            'plan_id' => $id,
            'tenancy_id' => $obUser['tenancy_id'],
            'status' => 'pending'
        ]);

        // Dados para requisição PIX
        $description = $obPlan->description;
        $total = $obPlan->amount_plan;

        $pixRequest = [
            'addressKey' => getenv('PIXKEY'),
            'description' => $description,
            'value' => floatval($total),
            'format' => 'ALL',
            'expirationDate' => '',
            'expirationSeconds' => 3600,
            'allowsMultiplePayments' => false,
            'externalReference' => uniqid('ref_')
        ];

        // Enviar requisição para gerar QR Code
        $pixResponse = $obApiSaas->createCob($pixRequest);

        // Validar resposta
        if (isset($pixResponse['error'])) {
            return new Response(500, [
                'status' => 500,
                'message' => 'Erro na geração do QR Code PIX.',
                'error' => $pixResponse['error']
            ], 'application/json');
        }

        if (!isset($pixResponse['encodedImage'], $pixResponse['payload'])) {
            return new Response(500, [
                'status' => 500,
                'message' => 'Resposta incompleta da API PIX.',
                'debug' => $pixResponse
            ], 'application/json');
        }

        // Salvar PIX no banco de dados
        $pix = new PixSearch();
        $pix->user_id = $obUser['id'];
        $pix->tenancy_id = $obUser['tenancy_id'];
        $pix->user_plain_id = $obPlan->id;
        $pix->payment_status = 'PAYMENT_CREATED';
        $pix->value = $total;
        $pix->pixQrCodeId = $pixResponse['id'] ?? ''; // ID retornado da API
        $pix->external_Reference = $pixRequest['externalReference'];
        $pix->billingType = "PIX";
        $pix->invoiceNumber = $pixResponse['invoiceNumber'] ?? 0;
        $pix->transactionReceiptUrl = $pixResponse['transactionReceiptUrl'] ?? null;
        $pix->dateCreated = date('Y-m-d H:i:s');;
        $pix->createPix();

        // Retornar resposta ao frontend
        return new Response(200, [
            'status' => 200,
            'message' => 'Cobrança Pix gerada com sucesso.',
            'data' => $pixResponse,
            'value' => $total
        ], 'application/json');
    }*/

    public static function getQrCodePix($request, $id): Response
    {
        PixSearch::ensureSchema();
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, PixService::error('Usuário não autenticado.', 401), 'application/json');
        }

        $planId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$planId) {
            return new Response(400, PixService::error('Plano inválido.', 400), 'application/json');
        }

        if (!AsaasConfig::isConfigured()) {
            PixService::log('asaas_config_missing', AsaasConfig::publicContext());
            return new Response(500, PixService::error('Configuração da API PIX incompleta.', 500), 'application/json');
        }

        $obApiSaas = AsaasConfig::createClient();

        $obPlan = UserPlans::getPlanById((int)$planId);
        if (!$obPlan instanceof UserPlans) {
            return new Response(404, PixService::error('Plano não encontrado.', 404), 'application/json');
        }

        $amount = round((float)$obPlan->amount_plan, 2);
        if ($amount <= 0) {
            return new Response(422, PixService::error('Valor do plano inválido para cobrança PIX.', 422), 'application/json');
        }

        $externalReference = 'pix_' . bin2hex(random_bytes(16));
        $description = preg_replace('/[^A-Za-z0-9 ]/', '', (string)$obPlan->description);
        $description = trim((string)preg_replace('/\s+/', ' ', $description));
        $description = mb_substr($description !== '' ? $description : 'Recarga Maxx Solutions', 0, 37);

        $pixRequest = [
            'addressKey' => AsaasConfig::pixKey(),
            'description' => $description,
            'value' => $amount,
            'format' => 'ALL',
            'expirationSeconds' => 3600,
            'allowsMultiplePayments' => false,
            'externalReference' => $externalReference
        ];

        PixService::log('charge_create_requested', [
            'user_id' => $obUser['id'],
            'tenancy_id' => $obUser['tenancy_id'],
            'plan_id' => $planId,
            'amount' => $amount,
            'sandbox' => AsaasConfig::isSandbox(),
            'externalReference' => $externalReference,
            'webhookUrl' => AsaasConfig::webhookUrl(),
        ]);

        $pixResponse = $obApiSaas->createCob($pixRequest);

        if (isset($pixResponse['error']) || isset($pixResponse['errors'])) {
            PixService::log('charge_provider_error', [
                'externalReference' => $externalReference,
                'provider_error' => $pixResponse['error'] ?? $pixResponse['errors'] ?? null,
            ]);
            return new Response(502, PixService::error('Erro ao gerar cobrança no provedor PIX.', 502), 'application/json');
        }

        if (empty($pixResponse['id']) || empty($pixResponse['encodedImage']) || empty($pixResponse['payload'])) {
            PixService::log('charge_provider_incomplete_response', [
                'externalReference' => $externalReference,
                'keys' => array_keys((array)$pixResponse),
            ]);
            return new Response(502, PixService::error('Resposta incompleta do provedor PIX.', 502), 'application/json');
        }

        UserPlans::createUserPlan([
            'user_id' => (int)$obUser['id'],
            'plan_id' => (int)$planId,
            'tenancy_id' => (string)$obUser['tenancy_id'],
            'status' => 'pending'
        ]);

        $pix = new PixSearch();
        $pix->user_id = (int)$obUser['id'];
        $pix->tenancy_id = (string)$obUser['tenancy_id'];
        $pix->user_plain_id = (int)$obPlan->id;
        $pix->payment_status = 'PAYMENT_CREATED';
        $pix->value = (string)$amount;
        $pix->pixQrCodeId = $pixResponse['id'] ?? '';
        $pix->external_Reference = $externalReference;
        $pix->billingType = "PIX";
        $pix->invoiceNumber = $pixResponse['invoiceNumber'] ?? 0;
        $pix->transactionReceiptUrl = $pixResponse['transactionReceiptUrl'] ?? null;
        $pix->invoice_url = $pixResponse['invoiceUrl'] ?? null;
        $pix->pix_payload = $pixResponse['payload'] ?? null;
        $pix->pix_encoded_image = $pixResponse['encodedImage'] ?? null;
        $pix->due_date = null;
        $pix->payment_date = null;
        $pix->last_webhook_event = 'PAYMENT_CREATED';
        $pix->last_webhook_payload = null;
        $pix->dateCreated = date('Y-m-d H:i:s');
        $pix->createPix();

        PixService::log('charge_created', [
            'invoiceNumber' => $pix->invoiceNumber,
            'pixQrCodeId' => $pix->pixQrCodeId,
            'externalReference' => $externalReference,
            'user_id' => $pix->user_id,
            'tenancy_id' => $pix->tenancy_id,
        ]);

        $response = PixService::success([
            'id' => $pixResponse['id'],
            'encodedImage' => $pixResponse['encodedImage'],
            'payload' => $pixResponse['payload'],
            'externalReference' => $externalReference,
            'payment' => [
                'id' => $pixResponse['id'],
                'encodedImage' => $pixResponse['encodedImage'],
                'payload' => $pixResponse['payload'],
                'externalReference' => $externalReference
            ],
            'value' => $amount,
            'invoiceNumber' => $pix->invoiceNumber,
            'invoiceUrl' => $pix->invoice_url,
            'transactionReceiptUrl' => $pix->transactionReceiptUrl,
        ], 'Cobrança Pix gerada com sucesso.');
        $response['value'] = $amount;

        return new Response(200, $response, 'application/json');
    }


    public static function getStatusPix($request, $pixQrCodeId): Response
    {
        PixSearch::ensureSchema();
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, PixService::error('Usuário não autenticado.', 401), 'application/json');
        }

        $obPix = PixSearch::getPixByQrCode($pixQrCodeId, $obUser['tenancy_id'], $obUser['id']);

        if (!$obPix instanceof PixSearch) {
            return new Response(404, PixService::error('Registro PIX não encontrado.', 404), 'application/json');
        }

        self::syncPixStatusFromProvider($obPix);

        $freshPix = PixSearch::getPixByQrCode($pixQrCodeId, $obUser['tenancy_id'], (int)$obUser['id']);
        if ($freshPix instanceof PixSearch) {
            $obPix = $freshPix;
        }

        $status = strtoupper(trim($obPix->payment_status));
        return new Response(200, PixService::success([
            'status' => $status,
            'invoiceNumber' => $obPix->invoiceNumber,
            'paymentId' => $obPix->asaas_payment_id,
            'customerId' => $obPix->asaas_customer_id,
            'paymentDate' => $obPix->payment_date,
            'dueDate' => $obPix->due_date,
            'invoiceUrl' => $obPix->invoice_url,
            'confirmed' => PixService::isPaidEvent($status) && !empty($obPix->confirmed_date),
        ], PixService::isPaidEvent($status) ? 'Pagamento confirmado.' : 'Pagamento ainda não confirmado.'), 'application/json');

    }

    private static function syncPixStatusFromProvider(PixSearch $pix): void
    {
        $currentStatus = strtoupper(trim((string)$pix->payment_status));
        if (PixService::isPaidEvent($currentStatus) || $currentStatus === 'PAYMENT_REFUNDED') {
            return;
        }

        if (!AsaasConfig::isConfigured() || empty($pix->pixQrCodeId)) {
            return;
        }

        try {
            $response = AsaasConfig::createClient()->listPaymentsByPixQrCodeId((string)$pix->pixQrCodeId);
            $items = is_array($response['data'] ?? null) ? $response['data'] : [];

            if ($items === []) {
                PixService::log('status_sync_no_provider_payment', [
                    'webhook_id' => $pix->webhook_id,
                    'pixQrCodeId' => $pix->pixQrCodeId,
                    'invoiceNumber' => $pix->invoiceNumber,
                ]);
                return;
            }

            usort($items, static function (array $left, array $right): int {
                $leftDate = strtotime((string)($left['paymentDate'] ?? $left['clientPaymentDate'] ?? $left['confirmedDate'] ?? $left['dateCreated'] ?? '')) ?: 0;
                $rightDate = strtotime((string)($right['paymentDate'] ?? $right['clientPaymentDate'] ?? $right['confirmedDate'] ?? $right['dateCreated'] ?? '')) ?: 0;
                return $rightDate <=> $leftDate;
            });

            $payment = $items[0];
            $payment['pixQrCodeId'] = $payment['pixQrCodeId'] ?? $pix->pixQrCodeId;
            $payment['invoiceNumber'] = $payment['invoiceNumber'] ?? $pix->invoiceNumber;
            $payment['externalReference'] = $payment['externalReference'] ?? $pix->external_Reference;
            $payment['value'] = $payment['value'] ?? $pix->value;

            $syncResult = PixService::syncProviderStatusPayment($pix, $payment);
            if (!$syncResult['success']) {
                PixService::log('status_sync_provider_rejected', [
                    'webhook_id' => $pix->webhook_id,
                    'pixQrCodeId' => $pix->pixQrCodeId,
                    'invoiceNumber' => $pix->invoiceNumber,
                    'error' => $syncResult['error'] ?? null,
                    'status' => $syncResult['status'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            PixService::log('status_sync_provider_failed', [
                'webhook_id' => $pix->webhook_id,
                'pixQrCodeId' => $pix->pixQrCodeId,
                'invoiceNumber' => $pix->invoiceNumber,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @throws RandomException
     */
    public static function setRefillsResellers($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $dataRefills = $request->getPostVars();
        $result = ManualTopupService::process($obUser, $dataRefills, $_SERVER);

        return new Response(
            (int)($result['status_code'] ?? 500),
            $result['payload'] ?? [
                'status' => 500,
                'message' => 'Erro ao processar atualização de saldo.'
            ],
            'application/json'
        );
    }




}
