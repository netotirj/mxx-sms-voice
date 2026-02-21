<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Model\Entity\CampaignSearch;
use App\Model\Entity\Rates;
use App\Model\Entity\RegisterTenancies;
use App\Model\Entity\UserSearch;
use App\Session\User as SessionUser;
use App\Utils\View;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\PixSearch;
use App\Model\Entity\CallbackSms;
use App\Model\Entity\RefillsResellers;

class DashboardOld extends ViewComponents
{
    public static function getDashboard($request):string
    {
        $content = View::render('/dashboard/index', []);
        return parent::getComponentsDashboard('Maxx Solutions - SMS | Dasboard', $content);
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

        $isReseller = ($obUser['function'] ?? null) === 'reseller';
        $isAdmin    = ($obUser['function'] ?? null) === 'admin';

        // ======================================================
        // 🔹 Função auxiliar para calcular média de gastos/variação
        // ======================================================
        $calcBalanceVariation = function(array $previousBalances, float $currentBalance): string {
            if (empty($previousBalances)) {
                return '+0%';
            }

            $total = 0;
            foreach ($previousBalances as $balanceLog) {
                $gasto = isset($balanceLog->gasto_mes) ? (float)$balanceLog->gasto_mes : 0;
                $total += abs($gasto);
            }

            $averageMonthlyExpense = $total / count($previousBalances);
            if ($averageMonthlyExpense <= 0) {
                return '+0%';
            }

            $percentChange = (($currentBalance - $averageMonthlyExpense) / $averageMonthlyExpense) * 100;
            $percentChangeDisplay = max(-100, min(100, $percentChange));

            return ($percentChangeDisplay >= 0 ? '+' : '') . round($percentChangeDisplay) . '%';
        };

        // ======================================================
        // 🔹 RESELLER
        // ======================================================
        if ($isReseller) {
            $resellerBalance     = BalanceSms::getBalanceSms($obUser['id'], $obUser['tenancy_id']);
            $currentBalance      = (float)($resellerBalance->balance ?? 0);
            $previousBalances    = BalanceSms::getBalanceSmsForPreviousMonths($obUser['id'], $obUser['tenancy_id']);
            $percentChange       = $calcBalanceVariation($previousBalances, $currentBalance);

            $rateData  = Rates::getLatestActiveRate($obUser['tenancy_id'], $obUser['id']);
            $valueSms  = (float)($rateData['rate'] ?? 0);

            $dataSms   = CallbackSms::countSentSms($obUser['id'], $obUser['tenancy_id']);
            $currentSms = $dataSms->qtd ?? 0;
            $dataValue  = $currentSms * $valueSms;

            $dataCampaignCount = CampaignSearch::countCampaignsByStatus($obUser['tenancy_id'], $obUser['id']);
            $totalCampaigns    = array_sum($dataCampaignCount);

            $dataPix = RefillsResellers::getLastRefill($obUser['id'], $obUser['tenancy_id']);
            $currentPix  = $dataPix && isset($dataPix->balance)
                ? number_format((float)$dataPix->balance, 2, ',', '')
                : '00,00';
            $currentData = (!empty($dataPix->created_at) && strtotime($dataPix->created_at))
                ? date('d/m/Y H:i', strtotime($dataPix->created_at))
                : '--/--/---- --:--';


            $data = [
                'saldoAtual'     => $currentBalance,
                'saldoVariacao'  => $percentChange,
                'smsEnviados'    => $currentSms,
                'smsTarifados'   => $currentSms,
                'smsCusto'       => 'R$ ' . number_format($dataValue, 2, ',', '.'),
                'ultimoPixValor' => $currentPix,
                'ultimoPixData'  => $currentData,
                'campanhasHoje'  => $totalCampaigns,
                'campanhas'      => $dataCampaignCount,
            ];
        }
        // ======================================================
        // 🔹 ADMIN ou USUÁRIO NORMAL
        // ======================================================
        else {
            $currentBalance   = BalanceSms::getSumBalanceSms($obUser['id'], $obUser['tenancy_id']);

            //echo "<pre>";
            //print_r($currentBalance);
            //echo "</pre>";exit;



            $previousBalances = BalanceSms::getBalanceSmsForPreviousMonths($obUser['id'], $obUser['tenancy_id']);
            $percentChange    = $calcBalanceVariation($previousBalances, $currentBalance);

            $dataPix = PixSearch::getPixLast($obUser['id'], $obUser['tenancy_id']);
            $currentPix  = $dataPix && isset($dataPix->value)
                ? str_replace('.', ',', sprintf("%05.2f", (float)$dataPix->value))
                : '00,00';
            $currentData = (!empty($dataPix->confirmed_date) && strtotime($dataPix->confirmed_date))
                ? date('d/m/Y H:i', strtotime($dataPix->confirmed_date))
                : '--/--/---- --:--';

            // 🔹 Se admin → traz todos SMS da tenancy
            $dataSms = CallbackSms::countSentSms($isAdmin ? null : $obUser['id'], $obUser['tenancy_id']);
            $currentSms = $dataSms->qtd;
            $valueSms   = (float)(BalanceSms::getBalanceSms($obUser['id'], $obUser['tenancy_id'])->value_sms ?? 0);
            $dataValue  = $currentSms * $valueSms;

            $dataCampaignCount = CampaignSearch::countCampaignsByStatus($obUser['tenancy_id'], $obUser['id']);
            $totalCampaigns    = array_sum($dataCampaignCount);

            $data = [
                'saldoAtual'     => $currentBalance,
                'saldoVariacao'  => $percentChange,
                'smsEnviados'    => $currentSms,
                'smsTarifados'   => $currentSms,
                'smsCusto'       => 'R$ ' . number_format($dataValue, 2, ',', '.'),
                'ultimoPixValor' => $currentPix,
                'ultimoPixData'  => $currentData,
                'campanhasHoje'  => $totalCampaigns,
                'campanhas'      => $dataCampaignCount,
            ];
        }

        // ======================================================
        // 🔴 Resposta SSE
        // ======================================================
        echo "data: " . json_encode($data) . "\n\n";
        flush();
        exit;
    }


    public static function getCountStatusSms($request): Response
    {
        if (session_status() == PHP_SESSION_ACTIVE) session_write_close();

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            echo "event: error\n";
            echo "data: " . json_encode(['status' => 401, 'message' => 'Usuário não autenticado.']) . "\n\n";
            //ob_flush();
            flush();
            exit;
        }

        try {
            $isReseller = isset($obUser['function']) && $obUser['function'] === 'reseller';
            $isAdmin    = isset($obUser['function']) && $obUser['function'] === 'admin';

            $data = [
                'statusMes'      => [],
                'statusDia'      => [],
                'totalMesAtual'  => 0,
                'totalMesAnterior'=> 0,
                'totalDiaAtual'  => 0,
                'totalDiaAnterior'=> 0
            ];

            $dataOperator = [];
            $dataValuesPix = [];


            if ($isAdmin) {
                // 🔹 Admin: agrega todos os dados da tenancy, incluindo resellers
                $data = CallbackSms::fetchStatusCountsWithDay($obUser['tenancy_id'], null, null);
                $dataOperator = CallbackSms::countGroupedByOperatorAllStatus($obUser['tenancy_id'], null, null);

                $resellers = UserSearch::getResellers($obUser['tenancy_id']); // função que retorna todos os resellers

                $dataValuesPix = PixSearch::getValuesPixCurrentMonth($obUser['id'], $obUser['tenancy_id']);



                $dataValuesPix = array_map(function($item) {
                    return [
                        'data'  => date('d/m', strtotime($item->confirmed_date)),
                        'value' => (float)$item->value
                    ];
                }, $dataValuesPix);



            } elseif ($isReseller) {
                // 🔹 Reseller: dados do próprio reseller
                $data = CallbackSms::fetchStatusCountsWithDay($obUser['tenancy_id'], null, $obUser['id']);
                $dataOperator = CallbackSms::countGroupedByOperatorAllStatus($obUser['tenancy_id'], $obUser['id'], null);

                $dataValuesPix = RefillsResellers::getValuesRefillCurrentMonth($obUser['id'], $obUser['tenancy_id']);

                $dataValuesPix = array_map(function($item) {
                    return [
                        'data'  => date('d/m', strtotime($item->created_at)),
                        'value' => (float)$item->balance
                    ];
                }, $dataValuesPix);

            }

            // Monta resposta para gráficos
            $response = [
                'statusMapMes'      => $data['statusMes'],
                'statusMapDia'      => $data['statusDia'],
                'totalMesAtual'     => $data['totalMesAtual'],
                'totalMesAnterior'  => $data['totalMesAnterior'],
                'totalDiaAtual'     => $data['totalDiaAtual'],
                'totalDiaAnterior'  => $data['totalDiaAnterior'],
                'totalOperator'     => $dataOperator,
                'totalPixValueMonth'=> $dataValuesPix
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

    public static function setUpdatePlan($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new \App\Http\Response(401, [
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
            'message' => 'Plano Alterado com sucesso!'
        ]), 'application/json');
    }

}