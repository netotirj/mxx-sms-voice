<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Model\Entity\CampaignSearch;
use App\Model\Entity\CampaignVoice;
use App\Model\Entity\CdrVoice;
use App\Model\Entity\Rates;
use App\Model\Entity\RegisterTenancies;
use App\Model\Entity\UserPlans;
use App\Model\Entity\UserSearch;
use App\Session\User as SessionUser;
use App\Utils\View;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\PixSearch;
use App\Model\Entity\CallbackSms;
use App\Model\Entity\RefillsResellers;

class Dashboard extends ViewComponents
{
    public static function getDashboard($request): string
    {
        $content = View::render('/dashboard/index', []);
        return parent::getComponentsDashboard('Maxx Solutions - SMS | Dashboard', $content);
    }

    private static function mergeCampaignCounts(array $sms, array $voiceRaw): array
    {
        // SMS (já vem normalizado)
        $ativaSms      = (int)($sms['ativa'] ?? 0);
        $finalizadaSms = (int)($sms['finalizada'] ?? 0);
        $inativaSms    = (int)($sms['inativa'] ?? 0); // SMS pode continuar tendo inativa

        // VOZ (enum y/p/n/f/c)
        $ativaVoz        = (int)($voiceRaw['y'] ?? 0);
        $processandoVoz  = (int)($voiceRaw['p'] ?? 0);
        $pausadaVoz      = (int)($voiceRaw['n'] ?? 0);
        $finalizadaVoz   = (int)($voiceRaw['f'] ?? 0);
        $canceladaVoz    = (int)($voiceRaw['c'] ?? 0);

        return [
            // 👇 cards principais
            'ativa'       => $ativaSms + $ativaVoz + $processandoVoz,
            'finalizada'  => $finalizadaSms + $finalizadaVoz,
            'inativa'     => $inativaSms, // ⚠️ só SMS tem inativa real

            // 👇 extras (se quiser usar depois)
            'processando' => $processandoVoz,
            'pausada'     => $pausadaVoz,
            'cancelada'   => $canceladaVoz,
        ];
    }



    public static function getDataViewDash($request)
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status'  => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $role = $obUser['function'] ?? null;
        $isReseller   = $role === 'reseller';
        $isAdmin      = $role === 'admin';
        $isSuperAdmin = $role === 'super_admin';

        $calcBalanceVariation = function(array $previousBalances, float $currentBalance): string {
            $gastos = array_map(fn($log) => abs((float)($log->gasto_mes ?? 0)), $previousBalances);
            if (count($gastos) === 0) return '+0%';

            $average = array_sum($gastos) / count($gastos);
            if ($average <= 0) return '+0%';

            $percent = (($currentBalance - $average) / $average) * 100;
            $percent = max(-100, min(100, $percent));

            return ($percent >= 0 ? '+' : '') . round($percent) . '%';
        };

        // ============================
        // SUPER ADMIN
        // ============================
        if ($isSuperAdmin) {

            $currentBalance = DisproClient::getBalanceDISPRO() ?: 0;

            $dataPix = PixSearch::getPixLast(null, null);
            $currentPix  = $dataPix && isset($dataPix->value)
                ? str_replace('.', ',', sprintf("%05.2f", (float)$dataPix->value))
                : '00,00';
            $currentData = (!empty($dataPix->confirmed_date) && strtotime($dataPix->confirmed_date))
                ? date('d/m/Y H:i', strtotime($dataPix->confirmed_date))
                : '--/--/---- --:--';

            $dataSms = CallbackSms::countSentSms(null, null);
            $currentSms = $dataSms->qtd ?? 0;
            $valueSms   = (float)(BalanceSms::getBalanceSms(null, null)->value_sms ?? 0);
            $dataValue  = $currentSms * $valueSms;

            // CDR (já sem ramais, conforme seu ajuste no countCdrVoice)
            $cdr = CdrVoice::countCdrVoice(null, null);
            $cdrDisposition = $cdr->answer ?? 0;

            $cdrValue = (float)($cdr->value_total ?? 0);
            $cdrTaxa  = (float)($cdr->taxa_total ?? 0); // ok manter aqui
            $cdrTotal = $cdrValue + $cdrTaxa;

            // Campanhas SMS + VOZ
            $smsCampaignsRaw   = CampaignSearch::countCampaignsByStatus(null, null); // ativa/finalizada/inativa
            $voiceCampaignsRaw = CampaignVoice::countVoiceCampaignsByStatus(null, null);  // y/p/n/f/c

            $mergedCampaigns = self::mergeCampaignCounts($smsCampaignsRaw, $voiceCampaignsRaw);

            $totalCampaigns = array_sum($smsCampaignsRaw) + array_sum($voiceCampaignsRaw);
            $totalConsumo   = $dataValue + $cdrTotal;

            $data = [
                'saldoAtual'     => number_format($currentBalance, 4, ',', '.'),
                'saldoVariacao'  => '',
                'smsEnviados'    => $currentSms,
                'smsTarifados'   => $currentSms,
                'smsCusto'       => 'R$ ' . number_format($dataValue, 4, ',', '.'),
                'ultimoPixValor' => $currentPix,
                'ultimoPixData'  => $currentData,

                'campanhasHoje'  => $totalCampaigns,
                'campanhas'      => $mergedCampaigns,

                'totalConsumo'   => 'R$ ' . number_format($totalConsumo, 4, ',', '.'),

                'disposition'    => $cdrDisposition,
                'cdrTaxa'        => 'R$ ' . number_format($cdrTaxa, 4, ',', '.'),
                'cdrValue'       => 'R$ ' . number_format($cdrTotal, 4, ',', '.'),
            ];
        }

        // ============================
        // RESELLER
        // ============================
        elseif ($isReseller) {

            $resellerBalance  = BalanceSms::getBalanceSms($obUser['id'], $obUser['tenancy_id']);
            $currentBalance   = (float)($resellerBalance->balance ?? 0);
            $previousBalances = BalanceSms::getBalanceSmsForPreviousMonths($obUser['id'], $obUser['tenancy_id']);
            $percentChange    = $calcBalanceVariation($previousBalances, $currentBalance);

            $rateData  = Rates::getLatestActiveRate($obUser['tenancy_id'], $obUser['id']);
            $valueSms  = (float)($rateData['rate'] ?? 0);

            $dataSms   = CallbackSms::countSentSms($obUser['id'], $obUser['tenancy_id']);
            $currentSms = $dataSms->qtd ?? 0;
            $dataValue  = $currentSms * $valueSms;

            $cdr = CdrVoice::countCdrVoice($obUser['id'], $obUser['tenancy_id']);
            $cdrDisposition = $cdr->answer ?? 0;

            $cdrValue = (float)($cdr->value_total ?? 0);
            $cdrTaxa  = (float)($cdr->taxa_total ?? 0); // taxa do reseller/owner
            $cdrTotal = $cdrValue + $cdrTaxa;

            $smsCampaignsRaw   = CampaignSearch::countCampaignsByStatus($obUser['tenancy_id'], $obUser['id']);
            $voiceCampaignsRaw = CampaignVoice::countVoiceCampaignsByStatus($obUser['tenancy_id'], $obUser['id']);

            $mergedCampaigns = self::mergeCampaignCounts($smsCampaignsRaw, $voiceCampaignsRaw);
            $totalCampaigns  = array_sum($smsCampaignsRaw) + array_sum($voiceCampaignsRaw);

            $totalConsumo = $dataValue + $cdrTotal;

            $dataPix = RefillsResellers::getLastRefill($obUser['id'], $obUser['tenancy_id']);
            $currentPix  = $dataPix && isset($dataPix->balance)
                ? number_format((float)$dataPix->balance, 2, ',', '')
                : '00,00';
            $currentData = (!empty($dataPix->created_at) && strtotime($dataPix->created_at))
                ? date('d/m/Y H:i', strtotime($dataPix->created_at))
                : '--/--/---- --:--';

            $data = [
                'saldoAtual'     => number_format($currentBalance, 4, ',', '.'),
                'saldoVariacao'  => $percentChange,
                'smsEnviados'    => $currentSms,
                'smsTarifados'   => $currentSms,
                'smsCusto'       => 'R$ ' . number_format($dataValue, 4, ',', '.'),
                'ultimoPixValor' => $currentPix,
                'ultimoPixData'  => $currentData,

                'campanhasHoje'  => $totalCampaigns,
                'campanhas'      => $mergedCampaigns,

                'totalConsumo'   => 'R$ ' . number_format($totalConsumo, 4, ',', '.'),

                'disposition'    => $cdrDisposition,
                'cdrTaxa'        => 'R$ ' . number_format($cdrTaxa, 4, ',', '.'),
                'cdrValue'       => 'R$ ' . number_format($cdrTotal, 4, ',', '.'),
            ];
        }

        // ============================
        // ADMIN / USER
        // ============================
        else {

            $currentBalance   = BalanceSms::getSumBalanceSms($obUser['id'], $obUser['tenancy_id']);
            $previousBalances = BalanceSms::getBalanceSmsForPreviousMonths($obUser['id'], $obUser['tenancy_id']);
            $percentChange    = $calcBalanceVariation($previousBalances, $currentBalance);

            $dataPix = PixSearch::getPixLast($obUser['id'], $obUser['tenancy_id']);
            $currentPix  = $dataPix && isset($dataPix->value)
                ? str_replace('.', ',', sprintf("%05.2f", (float)$dataPix->value))
                : '00,00';
            $currentData = (!empty($dataPix->confirmed_date) && strtotime($dataPix->confirmed_date))
                ? date('d/m/Y H:i', strtotime($dataPix->confirmed_date))
                : '--/--/---- --:--';

            $dataSms = CallbackSms::countSentSms($isAdmin ? null : $obUser['id'], $obUser['tenancy_id']);
            $currentSms = $dataSms->qtd ?? 0;
            $valueSms   = (float)(BalanceSms::getBalanceSms($obUser['id'], $obUser['tenancy_id'])->value_sms ?? 0);
            $dataValue  = $currentSms * $valueSms;

            $cdr = CdrVoice::countCdrVoice(null, $obUser['tenancy_id']);
            $cdrDisposition = $cdr->answer ?? 0;

            $cdrValue = (float)($cdr->value_total ?? 0);

            // ✅ taxa do ADMIN vem dos logs
            $cdrTaxa  = BalanceSms::sumAdminServiceFeeFromLogs($obUser['tenancy_id'], (int)$obUser['id']);

            $cdrTotal = $cdrValue + $cdrTaxa;
            $totalConsumo = $dataValue + $cdrTotal;

            $smsCampaignsRaw   = CampaignSearch::countCampaignsByStatus($obUser['tenancy_id'], null);
            $voiceCampaignsRaw = CampaignVoice::countVoiceCampaignsByStatus($obUser['tenancy_id'], null);

            $mergedCampaigns = self::mergeCampaignCounts($smsCampaignsRaw, $voiceCampaignsRaw);
            $totalCampaigns  = array_sum($smsCampaignsRaw) + array_sum($voiceCampaignsRaw);

            $data = [
                'saldoAtual'     => number_format($currentBalance, 4, ',', '.'),
                'saldoVariacao'  => $percentChange,
                'smsEnviados'    => $currentSms,
                'smsTarifados'   => $currentSms,
                'smsCusto'       => 'R$ ' . number_format($dataValue, 4, ',', '.'),
                'ultimoPixValor' => $currentPix,
                'ultimoPixData'  => $currentData,

                'campanhasHoje'  => $totalCampaigns,
                'campanhas'      => $mergedCampaigns,

                'totalConsumo'   => 'R$ ' . number_format($totalConsumo, 4, ',', '.'),

                'disposition'    => $cdrDisposition,
                'cdrTaxa'        => 'R$ ' . number_format($cdrTaxa, 4, ',', '.'),
                'cdrValue'       => 'R$ ' . number_format($cdrTotal, 4, ',', '.'),
            ];
        }

        echo "data: " . json_encode($data) . "\n\n";
        flush();
        exit;
    }


    public static function getDataChartsDashboard($request): Response
    {
        if (session_status() == PHP_SESSION_ACTIVE) session_write_close();

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            echo "event: error\n";
            echo "data: " . json_encode(['status' => 401, 'message' => 'Usuário não autenticado.']) . "\n\n";
            flush();
            exit;
        }

        Voice::processCdrFromRedis();

        try {
            $role = $obUser['function'] ?? null;
            $isReseller   = $role === 'reseller';
            $isAdmin      = $role === 'admin';
            $isSuperAdmin = $role === 'super_admin';

            $data = [
                'statusMes'       => [],
                'statusDia'       => [],
                'totalMesAtual'   => 0,
                'totalMesAnterior'=> 0,
                'totalDiaAtual'   => 0,
                'totalDiaAnterior'=> 0,

                'statusMesVoice'       => [],
                'statusDiaVoice'       => [],
                'totalMesAtualVoice'   => 0,
                'totalMesAnteriorVoice'=> 0,
                'totalDiaAtualVoice'   => 0,
                'totalDiaAnteriorVoice'=> 0
            ];

            $dataOperator = [];
            $dataValuesPix = [];
            $dataValuesPixRefill = [];


            // ======================================================
            // 🔹 SUPER ADMIN — sem filtros
            // ======================================================
            if ($isSuperAdmin) {
                $data = CallbackSms::fetchStatusCountsWithDay(null, null, null);
                $dataOperator = CallbackSms::countGroupedByOperatorAllStatus(null, null, null);
                $dataValuesPix = PixSearch::getValuesPixCurrentMonth(null, null);
                $dataValuesPixRefill = RefillsResellers::getValuesRefillCurrentMonth(null, null);
                $dataVoice = CdrVoice::fetchVoiceStatusCountsWithDay(null, null, null);

                //echo "<pre>";
                //print_r($dataVoice);
                //echo "</pre>";

                // Normalizar PIX (já faz)
                $dataValuesPix = array_map(function($item) {
                    return [
                        'data'  => date('d/m', strtotime($item->confirmed_date)),
                        'value' => (float)$item->value
                    ];
                }, $dataValuesPix);

                // Normalizar REFILLS
                $dataValuesPixRefill = array_map(function($item) {
                    return [
                        'data'  => date('d/m', strtotime($item->created_at)),
                        'value' => (float)$item->balance
                    ];
                }, $dataValuesPixRefill);
            }

            // ======================================================
            // 🔹 ADMIN
            // ======================================================
            elseif ($isAdmin) {
                $data = CallbackSms::fetchStatusCountsWithDay($obUser['tenancy_id'], null, null);
                $dataOperator = CallbackSms::countGroupedByOperatorAllStatus($obUser['tenancy_id'], null, null);
                $dataValuesPix = PixSearch::getValuesPixCurrentMonth($obUser['id'], $obUser['tenancy_id']);
                $dataValuesPixRefill = RefillsResellers::getValuesRefillCurrentMonth(null, $obUser['tenancy_id']);
                $dataVoice = CdrVoice::fetchVoiceStatusCountsWithDay( $obUser['tenancy_id'], null
                );
                // Normalizar PIX (já faz)
                $dataValuesPix = array_map(function($item) {
                    return [
                        'data'  => date('d/m', strtotime($item->confirmed_date)),
                        'value' => (float)$item->value
                    ];
                }, $dataValuesPix);

                // Normalizar REFILLS
                $dataValuesPixRefill = array_map(function($item) {
                    return [
                        'data'  => date('d/m', strtotime($item->created_at)),
                        'value' => (float)$item->balance
                    ];
                }, $dataValuesPixRefill);
            }

            // ======================================================
            // 🔹 RESELLER
            // ======================================================
            elseif ($isReseller) {
                $data = CallbackSms::fetchStatusCountsWithDay($obUser['tenancy_id'], null, $obUser['id']);
                $dataOperator = CallbackSms::countGroupedByOperatorAllStatus($obUser['tenancy_id'], $obUser['id'], null);
                $dataValuesPix = RefillsResellers::getValuesRefillCurrentMonth($obUser['id'], $obUser['tenancy_id']);
                $dataVoice = CdrVoice::fetchVoiceStatusCountsWithDay($obUser['tenancy_id'], $obUser['id'], $obUser['id']);

                $dataValuesPix = array_map(function($item) {
                    return [
                        'data'  => date('d/m', strtotime($item->created_at)),
                        'value' => (float)$item->balance
                    ];
                }, $dataValuesPix);
            }

            $response = [
                'statusMapMes'      => $data['statusMes'],
                'statusMapDia'      => $data['statusDia'],
                'totalMesAtual'     => $data['totalMesAtual'],
                'totalMesAnterior'  => $data['totalMesAnterior'],
                'totalDiaAtual'     => $data['totalDiaAtual'],
                'totalDiaAnterior'  => $data['totalDiaAnterior'],

                'statusMapMesVoice'      => $dataVoice['statusMes'],
                'statusMapDiaVoice'      => $dataVoice['statusDia'],
                'totalMesAtualVoice'     => $dataVoice['totalMesAtual'],
                'totalMesAnteriorVoice'  => $dataVoice['totalMesAnterior'],
                'totalDiaAtualVoice'     => $dataVoice['totalDiaAtual'],
                'totalDiaAnteriorVoice'  => $dataVoice['totalDiaAnterior'],

                'totalOperator'     => $dataOperator,
                'totalPixValueMonth'=> $dataValuesPix,
                // 🔹 PIX
                'pixValues'         => $dataValuesPix,

                // 🔹 RECARGA
                'refillValues'      => $dataValuesPixRefill
            ];

            echo "data: " . json_encode($response) . "\n\n";
            flush();

        } catch (\Exception $e) {
            error_log("Erro ao buscar status SMS: " . $e->getMessage());
            echo "event: error\n";
            echo "data: " . json_encode(['error' => 'Erro ao buscar status SMS']) . "\n\n";
            flush();
        }

        exit;
    }

    /*public static function setUpdatePlan($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status'  => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $raw = file_get_contents('php://input');
        $params = json_decode($raw, true);
        $tenancyId = $obUser['tenancy_id'] ?? null;
        $planId    = $params['plan_id'] ?? null;
        if (!$tenancyId || !$planId) {
            return new Response(400, [
                'status' => 400,
                'error'  => 'Dados inválidos'
            ], 'application/json');
        }
        RegisterTenancies::updateActivePlan($tenancyId, $planId);

        return new Response(200, json_encode([
            'status' => 200,
            'message' => 'Plano alterado com sucesso!'
        ]), 'application/json');
    }*/

    /*public static function setUpdatePlan($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'success' => false,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $input = json_decode(file_get_contents('php://input'), true);

        $tenancyId = $obUser['tenancy_id'] ?? null;
        $planId    = $input['plan_id'] ?? null;

        if (!$tenancyId || !$planId) {
            return new Response(400, [
                'success' => false,
                'message' => 'Dados inválidos'
            ], 'application/json');
        }

        // 1) Atualiza plano ativo no painel
        RegisterTenancies::updateActivePlan($tenancyId, $planId);

        try {
            $asterisk = new AsteriskExtensionsSip();

            // 2) ADMIN (saldo e tarifa do painel)
            $adminId = (int)$obUser['id'];

            $panelAdmin = BalanceSms::getBalanceSms($adminId, $tenancyId, $planId);
            if (!$panelAdmin) {
                return new Response(404, [
                    'success' => false,
                    'message' => 'Saldo do admin não encontrado.'
                ], 'application/json');
            }

            $adminBalance = (float)$panelAdmin->balance;
            $adminTariff  = (float)$panelAdmin->value_voice;

            // 3) Resellers (saldo painel + tarifa via Rates)
            $resellers = UserSearch::getResellers($tenancyId) ?? [];
            $resellerPayload = [];

            foreach ($resellers as $r) {
                $rid = (int)($r['id'] ?? 0);
                if ($rid <= 0) continue;

                $ratesAll = Rates::getActiveRatesByUser($tenancyId, $rid, 'voice');
                $tariff   = (float)($ratesAll['voice'] ?? 0);

                $resellerPayload[] = [
                    'user_id'          => $rid,
                    'balance_admin'    => $adminBalance,
                    'balance_reseller' => (float)($r['reseller_balance'] ?? 0),
                    'call_minute_cost' => $tariff,
                ];
            }

            // ✅ Query padrão (igual trunk)
            $query = [
                'user_id'   => $adminId,
                'tenant_id' => $tenancyId,
            ];

            // ✅ Payload único “reajusta tudo”
            $payload = [
                'tenant_id' => $tenancyId,

                // ADMIN
                'admin' => [
                    'user_id'          => $adminId,
                    'balance_admin'    => $adminBalance,  // SET absoluto
                    'call_minute_cost' => $adminTariff,
                ],

                // RESELLERS
                'resellers' => $resellerPayload,
            ];

            $resp = $asterisk->updateTariff($query, $payload);

            if (!empty($resp['ok'])) {
                return new Response(200, [
                    'success' => true,
                    'message' => 'Plano alterado e Asterisk sincronizado com sucesso!',
                    'data'    => $resp['data'] ?? []
                ], 'application/json');
            }

            return new Response(500, [
                'success' => false,
                'message' => $resp['error'] ?? 'Falha ao sincronizar Asterisk.'
            ], 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, [
                'success' => false,
                'message' => $e->getMessage()
            ], 'application/json');
        }
    }*/

    public static function setUpdatePlan($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'success' => false,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $input = json_decode(file_get_contents('php://input'), true);

        $tenancyId = $obUser['tenancy_id'] ?? null;
        $planId    = $input['plan_id'] ?? null;

        if (!$tenancyId || !$planId) {
            return new Response(400, [
                'success' => false,
                'message' => 'Dados inválidos'
            ], 'application/json');
        }

        // 1) Atualiza plano ativo no painel
        RegisterTenancies::updateActivePlan($tenancyId, $planId);

        try {
            $asterisk = new AsteriskExtensionsSip();

            // 2) ADMIN (saldo e tarifa do painel)
            $adminId = (int)($obUser['id']);

            $panelAdmin = BalanceSms::getBalanceSms($adminId, $tenancyId, $planId);
            if (!$panelAdmin) {
                return new Response(404, [
                    'success' => false,
                    'message' => 'Saldo do admin não encontrado.'
                ], 'application/json');
            }

            $adminBalance    = (float)($panelAdmin->balance ?? 0);
            $adminTariff     = (float)($panelAdmin->value_voice ?? 0);
            $adminServiceFee = (float)($panelAdmin->service_fee ?? 0); // ✅ NOVO

            // 3) Resellers (saldo painel + tarifa via Rates)
            $resellers = UserSearch::getResellers($tenancyId) ?? [];
            $resellerPayload = [];

            foreach ($resellers as $r) {
                $rid = (int)($r['id'] ?? 0);
                if ($rid <= 0) continue;

                // ⚠️ Se seu método retorna array com voice + service_fee, melhor pegar tudo:
                $ratesAll = Rates::getActiveRatesByUser($tenancyId, $rid);

                $tariff      = (float)($ratesAll['voice'] ?? 0);
                $serviceFee  = (float)($ratesAll['service_fee'] ?? 0);

                $resellerPayload[] = [
                    'user_id'          => $rid,
                    'balance_admin'    => $adminBalance,
                    'balance_reseller' => (float)($r['reseller_balance'] ?? 0),
                    'call_minute_cost' => $tariff,
                    'service_fee'      => $serviceFee, // ✅ NOVO
                ];
            }

            // ✅ Query padrão (igual trunk)
            $query = [
                'user_id'   => $adminId,
                'tenant_id' => $tenancyId,
            ];

            // ✅ Payload único “reajusta tudo”
            $payload = [
                'tenant_id' => $tenancyId,

                // ADMIN
                'admin' => [
                    'user_id'          => $adminId,
                    'balance_admin'    => $adminBalance,
                    'call_minute_cost' => $adminTariff,
                    'service_fee'      => $adminServiceFee, // ✅ NOVO
                ],

                // RESELLERS
                'resellers' => $resellerPayload,
            ];

            $resp = $asterisk->updateTariff($query, $payload);

            if (!empty($resp['ok'])) {
                return new Response(200, [
                    'success' => true,
                    'message' => 'Plano alterado e Asterisk sincronizado com sucesso!',
                    'data'    => $resp['data'] ?? []
                ], 'application/json');
            }

            return new Response(500, [
                'success' => false,
                'message' => $resp['error'] ?? 'Falha ao sincronizar Asterisk.'
            ], 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, [
                'success' => false,
                'message' => $e->getMessage()
            ], 'application/json');
        }
    }

}
