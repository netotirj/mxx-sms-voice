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
    /*public static function getRefills($request): string
    {
        $plans = UserPlans::getAllActivePlans();

        //echo "<pre>";
        //print_r($plans);
        //echo "</pre>";exit;

        // Abre o container flex antes do loop
        $cards = '<div class="flex flex-wrap justify-center gap-6">';

        foreach ($plans as $plan) {
            // Calcula total de SMS
            $total_sms = (int)($plan->amount_plan / $plan->value_sms);
            $total = number_format($total_sms, 0, ',', '.');

            $badge = htmlspecialchars($plan->name_plan); // exemplo: Basic, Pro, etc.
            $title = htmlspecialchars($plan->title_plan ?? $plan->name_plan); // título do card
            $description = htmlspecialchars($plan->description ?? 'Perfect for individuals and small teams.');
            $price = number_format((float)$plan->amount_plan, 0, ',', '.');
            $sms_info = $total . ' SMS';
            // Renderização dinâmica de users_create (Y/N)
            $users_create_icon = $plan->users_create === 'y'
                ? '<svg class="h-6 w-6 text-green-400 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
               </svg>'
                : '<svg class="h-6 w-6 text-red-500 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
               </svg>';

            $users_create_text = $plan->users_create === 'y' ? 'Criação de usuários' : 'Não permite criar usuários';
            $access_qtd = ($plan->simultaneous_access == -1)
                ? 'Acesso Ilimitado'
                : $plan->simultaneous_access . ' Acesso por usuário';

            $usersCamp = ($plan->camp_qtd == -1)
                ? 'Campanhas Ilimitadas'
                : 'Até ' . $plan->camp_qtd . ' campanhas por usuário';

            $cards .= <<<HTML
        <!-- Card -->
        <div class="bg-purple-700 rounded-xl shadow-lg p-6 relative overflow-hidden w-full sm:w-80">
            <div class="absolute top-0 right-0 m-4">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-purple-100 text-purple-800 animate-bounce">
                    {$badge}
                </span>
            </div>                            
            <div class="mb-8">
                <h3 class="text-2xl font-semibold text-white">{$title} </h3>
                <p class="mt-4 text-purple-200">{$description} </p>
            </div>                            
            <div class="mb-8">
                <span class="text-5xl font-extrabold text-white">R\$ {$price}</span>
                <span class="text-xs font-medium text-purple-200">/ {$plan->value_sms} </span>
            </div>                            
            <ul class="mb-8 space-y-4 text-purple-200">
                <li class="flex items-center">
                    <svg class="h-6 w-6 text-green-400 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span>Aproximado {$sms_info} </span>
                </li>
                <li class="flex items-center">
                    <svg class="h-6 w-6 text-green-400 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span> {$access_qtd}</span>
                </li>
                
                 <li class="flex items-center">
                    {$users_create_icon}
                    <span>{$users_create_text}</span>
                </li>
                
                <li class="flex items-center">
                    <svg class="h-6 w-6 text-green-400 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span>{$usersCamp} </span>
                </li>
                
                <li class="flex items-center">
                    <svg class="h-6 w-6 text-green-400 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span>{$plan->rports} </span>
                </li>
                 <li class="flex items-center">
                    <svg class="h-6 w-6 text-green-400 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span>{$plan->type_plan} </span>
                </li>
                
                 <li class="flex items-center">
                    <svg class="h-6 w-6 text-green-400 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span>{$plan->payment_type} </span>
                </li>
            </ul>                            
         <button type="button"
                id="buyButton_{$plan->id}"
                onclick="handleBuyClick(this, '{$plan->id}')"
                class="relative block w-full py-3 px-6 text-center rounded-md text-white font-medium bg-gradient-to-r from-purple-500 to-indigo-600 hover:from-purple-600 hover:to-indigo-700">
            Comprar
        </button>



        </div>                        
HTML;
        }

        $cards .= '</div>'; // Fecha o container após o loop

        // Renderiza a view
        $content = View::render('refills/index', [
            'cards' => $cards
        ]);

        return parent::getComponentsRefills('Maxx Solutions - SMS | Recargas', $content);
    }*/

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

            // Seleciona o valor correto conforme o tipo do plano
            $unitCost = match ($p->type_plan) {
                'sms'      => $p->value_sms,
                'voice'    => $p->value_voice,
                'torpedo'  => $p->value_torpedo,
                'whatsapp' => $p->value_whatsapp,
                default    => 0
            };

            // Calcula quantidade aproximada
            $qtdEstimated = $unitCost > 0
                ? floor($p->amount_plan / $unitCost)
                : 0;

            // Ícones
            $iconCheck = '<svg class="h-5 w-5 text-green-400 mr-2" fill="none" stroke="currentColor"
                    viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round"
                    stroke-width="2" d="M5 13l4 4L19 7" /></svg>';

            $iconX = '<svg class="h-5 w-5 text-red-500 mr-2" fill="none" stroke="currentColor"
                viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round"
                stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>';

            $formatted[] = [
                'id' => $p->id,
                'title' => $p->name_plan,
                'description' => $p->description,
                'price' => $p->amount_plan,
                'unit' => number_format($unitCost, 4, '.', ''),
                'features' => [
                    [
                        'text' => "Aprox. {$qtdEstimated} {$p->type_plan}",
                        'ok' => true
                    ],
                    [
                        'text' => $p->simultaneous_access == -1
                            ? "Acesso simultâneo ilimitado"
                            : "{$p->simultaneous_access} acesso(s)",
                        'ok' => true
                    ],
                    [
                        'text' => $p->users_create == 'y'
                            ? "Permite criar usuários"
                            : "Não permite criar usuários",
                        'ok' => ($p->users_create == 'y')
                    ],
                    [
                        'text' => $p->camp_qtd == -1
                            ? "Campanhas ilimitadas"
                            : "{$p->camp_qtd} campanhas",
                        'ok' => true
                    ],
                    [
                        'text' => $p->rports,
                        'ok' => true
                    ],
                    [
                        'text' => "Tipo: {$p->type_plan}",
                        'ok' => true
                    ],
                    [
                        'text' => "Pagamento: {$p->payment_type}",
                        'ok' => true
                    ]
                ]
            ];
        }


        return new Response(200, [
            'status' => 200,
            'plans' => $formatted
        ], 'application/json');


    }


    public static function getQrCodePix($request, $id): Response
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
    public static function setRefillsResellers(): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $dataRefills = json_decode(file_get_contents("php://input"), true);

        $userId = intval($dataRefills["usuario_id"] ?? 0);
        $value = floatval($dataRefills["valor_recarga"] ?? 0);
        $notes = trim($dataRefills["anotacao"] ?? "");
        $email = trim($dataRefills["usuario_email"] ?? "");


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
        $refill->status = "completed"; // ou "pending"
        $refill->created_at = date('Y-m-d H:i:s');
        $refill->updated_at = date('Y-m-d H:i:s');

        $id = $refill->insertRefill();

        $reseller = new UserSearch();
        $reseller->id = $refill->user_id;
        $reseller->tenancy_id = $refill->tenancy_id;

        $updated = $reseller->updateRefillReseller($value);

        $resellerBalanceAsterisk = UserSearch::getUserById($obUser['tenancy_id'], $obUser['id']);
        $role = $resellerBalanceAsterisk['user_function'];

        $query = [
            'user_id' => $obUser['id'],
            'tenant_id' => $obUser['tenancy_id'],
        ];

        // 🧱 Monta payload para atualização
        $payload = [
            'user_id' => $refill->user_id,
            'tenant_id' => $refill->tenancy_id,
            'balance_reseller' => $value,
            'role' => $role
        ];

        // 🔗 Chama a API Asterisk (rota update_extension)
        $asterisk = new AsteriskExtensionsSip();
        $response = $asterisk->updateBalance($query, $payload);

        if ($updated && $response) {

            Notifications::insertNotifications(
                $refill->tenancy_id,        // Tenancy do reseller
                $refill->user_id,           // ID do reseller
                "Recarga Confirmada",       // Título da notificação
                "A recarga no valor de <b>R$ " . number_format($refill->balance, 2, ',', '.') . "</b> foi confirmada com sucesso. Seu saldo foi atualizado.", // Mensagem
                'notice'                   // Tipo de notificação
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
                'message' => 'Recarga registrada, mas falha ao atualizar saldo do revendedor.'
            ], 'application/json');
        }
    }


}
