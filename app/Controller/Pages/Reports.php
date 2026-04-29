<?php

namespace App\Controller\Pages;


use App\Http\Response;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\CallbackSms;
use App\Model\Entity\CdrVoice;
use App\Model\Entity\NotificationsUsers;
use App\Model\Entity\PixSearch;
use App\Model\Entity\Rates;
use App\Model\Entity\RefillsResellers;
use App\Model\Entity\UserSearch;
use App\Session\User as SessionUser;
use App\Utils\View;
use DateTime;
use Exception;

class Reports extends ViewComponents
{

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
        $role      = strtolower($obUser['function']);
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
                'status'         => $row['payment_status'] ?? 'PENDING',
                'value'          => (float)($row['value'] ?? 0),
                'invoice_number' => $row['invoiceNumber'] ?? '-',
                'date'           => isset($row['confirmed_date']) ? (new DateTime($row['confirmed_date']))->format('d-m-Y H:i:s') : '-',
                'receipt_url'    => $row['transactionReceiptUrl'] ?? null,
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

    public static function getNotificationsStatusRealtime($request): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, ['message' => "Usuário não autenticado"], 'application/json');
        }

        $userId    = $obUser['id'];
        $role      = strtolower(trim($obUser['user_function'] ?? ''));
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
            $rWhatsApp = (float)($ratesAll['whatsapp'] ?? 0);
        } else {
            $obBalanceTariffs = BalanceSms::getBalanceSms($recharge->user_id, $obUser['tenancy_id']);
            $rVoice    = (float)($obBalanceTariffs->value_voice ?? 0);
            $rSms      = (float)($obBalanceTariffs->value_sms ?? 0);
            $rTorpedo  = (float)($obBalanceTariffs->value_torpedo ?? 0);
            $rWhatsApp = (float)($obBalanceTariffs->value_whatsapp ?? 0);
        }

        // 4. Monta o Array de Dados para o Novo Template
        // Note: Usei os nomes que seu template de "Cards" pediu
        $data = [
            'transaction_id' => $recharge->transaction_id,
            'tenancy_id'     => $obUser['tenancy_id'],
            'date'           => date('d/m/Y H:i', strtotime($recharge->created_at)),
            'name'           => $reseller['name'] . ' ' . ($reseller['last_name'] ?? ''),
            'email'          => $reseller['email'],
            'logo_url'       => 'http://localhost/sms/resources/assets/img/profile-mxx.png', // Caminho da sua imagem
            'company_name'   => 'Maxx Solutions',
            'amount'         => number_format($recharge->balance, 2, ',', '.'),
            'status'         => strtoupper($recharge->status),
            'method'         => strtoupper($recharge->type ?? 'PIX'),

            // Tarifas
            'rate_voice'     => number_format($rVoice, 4, ',', '.'),
            'rate_sms'       => number_format($rSms, 4, ',', '.'),
            'rate_torpedo'   => number_format($rTorpedo, 4, ',', '.'),
            'rate_whatsapp'  => number_format($rWhatsApp, 4, ',', '.'),

            // SALDO REAL DO REVENDEDOR (Vem do banco agora)
            'new_balance'    => number_format((float)($reseller['reseller_balance'] ?? 0), 2, ',', '.')
        ];

        // 5. Chama o visual novo
        self::renderInvoiceTemplate($data);
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
                                    <h4 style="font-size: 7px; color: #94a3b8;">WHATSAPP</h4>
                                    <p style="font-size: 11px; color: #2563eb;">R$ {$d['rate_whatsapp']}</p>
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
        $role     = strtolower($obUser['function']);
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
        $formatted = array_map(/**
         * @throws Exception
         */ function ($row) {
            return [
                'id'                => $row['id'],
                'channel_id'        => $row['channel_id'],
                'tenancy_id'        => $row['tenancy_id'],
                'user_id'           => $row['user_id'],
                'user_name'         => $row['user_name'] ?? null,
                'user_account_code' => $row['user_account_code'] ?? null,
                'number'            => $row['number'],
                'endpoints'         => $row['endpoints'],
                'destination'       => (strlen($row['destination']) === 13) ? substr($row['destination'], 2) : $row['destination'],
                'channelNumber'     => $row['number'],
                'type'              => $row['type'],
                'dialstatus'        => $row['dialstatus'],
                'cause'             => $row['cause'],
                'cause_txt'         => $row['cause_txt'],
                'taxa'              => $row['taxa_of_service'],
                'duration'          => (int)$row['duration'],
                'value'             => (float)$row['value'],
                'started'           => !empty($row['started']) ? (new DateTime($row['started']))->format('d-m-Y H:i:s') : '-',
                'answered'          => $row['answered'],
                'ended'             => $row['ended'],
                'created_at'        => $row['created_at'],
            ];
        }, $cdr);

        return new Response(200, [
            'success' => true,
            'total'   => count($formatted),
            'data'    => $formatted
        ], 'application/json');
    }



}
