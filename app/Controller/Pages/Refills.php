<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Model\Entity\RefillsResellers;
use App\Model\Entity\UserSearch;
use App\Session\User as SessionUser;
use App\Utils\View;
use App\Model\Entity\UserPlans;
use App\Model\Entity\PixSearch;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\Notifications;
use App\Model\Entity\RegisterTenancies;
use Random\RandomException;

class Refills extends ViewComponents
{

    public static function getRefills($request): string
    {
        $content = View::render('refills/index', []);

        return parent::getComponentsRefills('Maxx Solutions - SMS | Recargas', $content);
    }

    public static function getComponentsPlains($request, $type): string
    {

        //echo "<pre>";
        //print_r($type);
        //echo "</pre>";exit;

        $plans = UserPlans::getAllActivePlans($type);


        $formatted = [];

        foreach ($plans as $p) {
            $formatted[] = [
                'id'          => $p->id,
                'name'        => $p->name_plan,
                'desc'        => $p->description,
                'price'       => number_format($p->amount_plan, 2, ',', '.'),
                'is_popular'  => (bool)$p->is_popular,
                'service_fee' => number_format($p->service_fee, 2, ',', '.'),
                'price_raw'   => $p->amount_plan, // Para o Modal
                'type'        => $p->type_plan,
                // Tarifas para o Modal
                'v_sms'       => number_format($p->value_sms, 4, ',', '.'),
                'v_voice'     => number_format($p->value_voice, 4, ',', '.'),
                'v_whatsapp'  => number_format($p->value_whatsapp, 4, ',', '.'),
                'v_whatsapp_marketing' => number_format((float)($p->value_whatsapp_marketing ?? $p->value_whatsapp), 4, ',', '.'),
                'v_whatsapp_utility' => number_format((float)($p->value_whatsapp_utility ?? $p->value_whatsapp), 4, ',', '.'),
                'v_whatsapp_authentication' => number_format((float)($p->value_whatsapp_authentication ?? $p->value_whatsapp), 4, ',', '.'),
                'v_torpedo'   => number_format($p->value_torpedo, 4, ',', '.'),
                // Detalhes Técnicos
                'users_y_n'   => ($p->users_create == 'y' ? 'Sim' : 'Não'),
                'access'      => ($p->simultaneous_access == -1 ? 'Ilimitados' : $p->simultaneous_access),
                'camp'        => ($p->camp_qtd == -1 ? 'Ilimitadas' : $p->camp_qtd),
                'reports'     => $p->rports,
            ];
        }


        return new Response(200, [
            'status' => 200,
            'plans' => $formatted
        ], 'application/json');


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
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        // Instancia a API (Certifique-se que o ASASURL no seu .env seja https://sandbox.asaas.com/api/v3)
        $obApiSaas = new AssasApiTest(getenv('ASASURLSANDBOX'), getenv('ASASSANDBOX'));

        $obPlan = UserPlans::getPlanById($id);
        if (!$obPlan instanceof UserPlans) {
            return new Response(404, [
                'status' => 404,
                'message' => 'Plano não encontrado.'
            ], 'application/json');
        }

        // Gerar um ID único de referência para o seu banco
        $externalReference = uniqid('ref_');
        // Pega a descrição do plano
        $description = $obPlan->description;

        // Limita a descrição para 30 caracteres (margem de segurança)
        // e remove caracteres especiais que podem bugar a string PIX
        $description = mb_substr($description, 0, 30);
        $description = preg_replace('/[^A-Za-z0-0 ]/', '', $description); // Remove acentos/especiais

        // Dados para requisição PIX conforme documentação Asaas
        $pixRequest = [
            'addressKey' => getenv('PIXKEYSANDBOX'), // Sua chave PIX cadastrada no Sandbox
            'description' => $description,
            'value' => floatval($obPlan->amount_plan),
            'format' => 'ALL', // Retorna Imagem Base64 + Payload
            'expirationSeconds' => 3600,
            'externalReference' => $externalReference
        ];

        // 1. Enviar requisição para gerar a cobrança/QR Code na Asaas
        $pixResponse = $obApiSaas->createCob($pixRequest);

        // Validar se a API retornou erro
        if (isset($pixResponse['error']) || isset($pixResponse['errors'])) {
            return new Response(500, [
                'status' => 500,
                'message' => 'Erro na API Asaas Sandbox.',
                'details' => $pixResponse
            ], 'application/json');
        }

        // Salvar no banco de dados com os dados REAIS vindos da API
        $pix = new PixSearch();
        $pix->user_id = $obUser['id'];
        $pix->tenancy_id = $obUser['tenancy_id'];
        $pix->user_plain_id = $obPlan->id;
        $pix->payment_status = 'PENDING'; // Status inicial do Asaas
        $pix->value = $obPlan->amount_plan;
        $pix->pixQrCodeId = $pixResponse['id'] ?? '';
        $pix->external_Reference = $externalReference;
        $pix->billingType = "PIX";
        $pix->invoiceNumber = $pixResponse['invoiceNumber'] ?? 0;
        $pix->transactionReceiptUrl = $pixResponse['transactionReceiptUrl'] ?? null;
        $pix->dateCreated = date('Y-m-d H:i:s');
        $pix->createPix();

        // Retornar resposta ao frontend (Agora com dados reais do Sandbox)
        return new Response(200, [
            'status' => 200,
            'message' => 'Cobrança Pix gerada no Sandbox.',
            'data' => [
                'id' => $pixResponse['id'],
                'encodedImage' => $pixResponse['encodedImage'], // Base64 real
                'payload' => $pixResponse['payload'],       // Copia e cola real
                'externalReference' => $externalReference
            ],
            'value' => $obPlan->amount_plan
        ], 'application/json');
    }


    public static function getStatusPix($request, $pixQrCodeId): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $obPix = PixSearch::getPixByQrCode($pixQrCodeId, $obUser['tenancy_id'], $obUser['id']);

        if (!$obPix instanceof PixSearch) {
            return new Response(404, [
                'status' => 404,
                'message' => 'Registro PIX não encontrado.'
            ], 'application/json');
        }

        function isValidDateString($date): bool
        {
            return isset($date) &&
                is_string($date) &&
                trim($date) !== '' &&
                strtotime($date) !== false;
        }

        if (strtoupper(trim($obPix->payment_status)) === 'PAYMENT_RECEIVED' && isValidDateString($obPix->confirmed_date)) {

            if (!empty($obPix->confirmed_date)) {

                // Buscar informações do plano e saldo
                $planInfo = UserPlans::getUserPlanInfo(
                    $obPix->user_plain_id,
                    $obPix->user_id,
                    $obPix->tenancy_id
                );

                if ($planInfo) {
                    // Atualizar saldo
                    BalanceSms::insertBalance(
                        $planInfo->user_id,
                        $planInfo->plan_id,
                        $planInfo->tenancy_id,
                        $planInfo->amount_plan,
                        $planInfo->value_sms,
                        //$planInfo->value_voice,
                        //$planInfo->value_torpedo,
                        //$planInfo->value_whatsapp,
                        $obPix->invoiceNumber
                    );

                    // Ativar plano
                    UserPlans::updateUserPlan($planInfo->user_plan_id, [
                        'status_payment' => 'confirmed'
                    ]);

                    RegisterTenancies::updateActivePlan($planInfo->tenancy_id, $planInfo->plan_id);

                }
                Notifications::insertNotifications(
                    $planInfo->tenancy_id,
                    $planInfo->user_id,
                    "Pagamento confirmado",
                    "O pagamento referente à fatura <b>#{$obPix->invoiceNumber}</b> foi confirmado, seu plano e saldo foram atualizados!.",
                    'notice'
                );

            }

            return new Response(200, [
                'status' => 200,
                'message' => 'Pagamento recebido com sucesso.',
                'data' => 'RECEIVED'
            ], 'application/json');
        }

        return new Response(200, [
            'status' => 200,
            'message' => 'Pagamento ainda não confirmado.',
            'data' => 'WAITING'
        ], 'application/json');

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

        // --- 🛡️ TRAVA DE SEGURANÇA POR CARGO ---
        // No PHP, usamos strtolower() e acessamos a chave direto do array
        $roleLogged = strtolower($obUser['user_function'] ?? $obUser['function'] ?? '');

        if (!in_array($roleLogged, ['admin', 'super_admin'])) {
            return new Response(403, [
                'status' => 403,
                'message' => 'Acesso negado: Você não tem permissão para realizar recargas.'
            ], 'application/json');
        }
        // ---------------------------------------

        $dataRefills = $request->getPostVars();

        $userId = intval($dataRefills["usuario_id"] ?? 0);
        $value = floatval($dataRefills["valor_recarga"] ?? 0);
        $notes = trim($dataRefills["anotacao"] ?? "");
        $email = trim($dataRefills["usuario_email"] ?? "");

        // Validação básica de valor para evitar recargas negativas ou zeradas
        if ($value <= 0) {
            return new Response(400, [
                'status' => 400,
                'message' => 'O valor da recarga deve ser maior que zero.'
            ], 'application/json');
        }

        // 🔹 Transaction ID randômico com 8 dígitos
        $transactionId = str_pad((string)random_int(0, 99999999), 8, "0", STR_PAD_LEFT);

        // 🔹 Captura do IP do cliente
        $clientIp = $_SERVER['HTTP_CLIENT_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';

        $refill = new RefillsResellers();
        $refill->user_id = $userId;
        $refill->tenancy_id = $obUser['tenancy_id'];
        $refill->email = $email;
        $refill->balance = $value;
        $refill->notes = $notes;
        $refill->type = "manual";
        $refill->transaction_id = $transactionId;
        $refill->client_ip = $clientIp;
        $refill->status = "completed";
        $refill->created_at = date('Y-m-d H:i:s');
        $refill->updated_at = date('Y-m-d H:i:s');

        $id = $refill->insertRefill();

        $reseller = new UserSearch();
        $reseller->id = $refill->user_id;
        $reseller->tenancy_id = $refill->tenancy_id;

        // Atualiza saldo no MySQL
        $updated = $reseller->updateRefillReseller($value);

        // Pega dados para o Asterisk
        $resellerData = UserSearch::getUserById($refill->tenancy_id, $refill->user_id);
        $roleReseller = $resellerData['user_function'] ?? 'reseller';

        $query = [
            'user_id' => $refill->user_id,
            'tenant_id' => $refill->tenancy_id,
        ];

        $payload = [
            'user_id' => $refill->user_id,
            'tenant_id' => $refill->tenancy_id,
            'balance_reseller' => $value,
            'role' => $roleReseller
        ];

        // 🔗 Sincroniza com Asterisk
        $asterisk = new AsteriskExtensionsSip();
        $responseAsterisk = $asterisk->updateBalance($query, $payload);

        if ($updated && $responseAsterisk) {
            Notifications::insertNotifications(
                $refill->tenancy_id,
                $refill->user_id,
                "Recarga Confirmada",
                "A recarga no valor de <b>R$ " . number_format($refill->balance, 2, ',', '.') . "</b> foi confirmada com sucesso. Seu saldo foi atualizado.",
                'notice'
            );

            return new Response(200, [
                'status' => 200,
                'message' => 'Recarga registrada e saldo atualizado com sucesso!',
                'transactionId' => $transactionId,
                'clientIp' => $clientIp
            ], 'application/json');
        } else {
            return new Response(500, [
                'status' => 500,
                'message' => 'Erro ao processar atualização de saldo.'
            ], 'application/json');
        }
    }




}
