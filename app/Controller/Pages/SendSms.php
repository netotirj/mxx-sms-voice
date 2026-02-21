<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Model\Entity\CampaignSearch;
use App\Model\Entity\Rates;
use App\Model\Entity\RegisterTenancies;
use App\Model\Entity\UserSearch;
use App\Session\User as SessionUser;
use App\Model\Entity\CallbackSms;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\CampaignBatch;
use App\Model\Entity\UserPlans;
use App\Utils\View;


class SendSms extends ViewComponents
{

    public static function sendSms($obUser, ?int $campaignId = null, array $phones = [], ?string $message = null, ?string $service = null, ?string $coding = "0"): Response {
        $isReseller = isset($obUser['function']) && $obUser['function'] === 'reseller';
        $currentPlan = RegisterTenancies::getActivePlanId($obUser['tenancy_id']);


        if ($isReseller) {

            $obPlan = UserPlans::getActivePlanByTenancy($currentPlan, $obUser['tenancy_id']);
            if (!$obPlan || empty($obPlan->id) || $obPlan->status !== 'active') {
                return new Response(404, [
                    'status'  => 404,
                    'message' => 'Favor contactar administrador da conta. Plano inativo.'
                ], 'application/json');
            }

        } else {

            $obPlan = UserPlans::getActivePlanByUser($currentPlan, $obUser['tenancy_id'], $obUser['id']);
            if (!$obPlan || empty($obPlan->id) || $obPlan->status !== 'active') {
                return new Response(404, [
                    'status'  => 404,
                    'message' => 'Nenhum Plano habilitado ou plano inativo.'
                ], 'application/json');
            }
        }


        $contacts = [];
        if ($campaignId) {
            $obCountId = CampaignSearch::getCampaignByIdAndTenancy($campaignId, $obUser['tenancy_id']);

            if (!$obCountId) {
                return new Response(404, ['status'=>404,'message'=>'Campanha não encontrada.'], 'application/json');
            }

            if (in_array($obCountId->status, ['f', 'n'])) {
                return new Response(400, ['status'=>400,'message'=>'Campanha finalizada ou inativa.'], 'application/json');
            }

            $contacts = CampaignSearch::getContactsForSmsDispatch($campaignId, $obUser['tenancy_id'], $obUser['id']);
            if (empty($contacts)) {
                return new Response(404, ['status'=>404,'message'=>'Nenhum contato encontrado para esta campanha.'], 'application/json');
            }
        } else {
            if (!is_array($phones)) {
                $phones = str_contains($phones, ',') ? array_map('trim', explode(',', $phones)) : [$phones];
            }
            foreach ($phones as $phone) {
                $contacts[] = [
                    'phone'       => $phone,
                    'type_msg'    => $service,
                    'message'     => $message,
                    'charset_msg' => $coding,
                    'name'        => $obUser['email']
                ];
            }
        }

        $totalMessages = count($contacts);
        if ($isReseller) {

            $resellerInfo = UserSearch::getResellers($obUser['tenancy_id'], $obUser['id']);
            $reseller     = $resellerInfo[0] ?? null;

            if (!$reseller || (float)$reseller['reseller_balance'] <= 0) {
                return new Response(403, ['status'=>403,'message'=>'Saldo insuficiente.'], 'application/json');
            }

            $rateData = Rates::getLatestActiveRate($obUser['tenancy_id'], $obUser['id']);
            if (!$rateData || !isset($rateData['rate'])) {
                return new Response(404, ['status'=>404,'message'=>'Nenhuma tarifa configurada.'], 'application/json');
            }

            $valueSms         = (float)$rateData['rate'];
            $availableBalance = (float)$reseller['reseller_balance'];
            $valueTotal       = $totalMessages * $valueSms;

            if ($availableBalance < $valueTotal) {
                return new Response(403, [
                    'status'    => 403,
                    'message'   => 'Saldo insuficiente para enviar Sms.',
                    'balance'   => $availableBalance,
                    'necessary' => $valueTotal
                ], 'application/json');
            }

            $obBalance   = BalanceSms::getBalanceSms(null, $obUser['tenancy_id'], $currentPlan);
            $obSmsAccept = CallbackSms::countSentSmsAccept($obUser['id'], $obUser['tenancy_id']);

            $valueTotalTenant = $totalMessages * (float)$obBalance->value_sms;
            $valueAccept      = (float)($obSmsAccept->value_total ?? 0);
            $availableTenant  = (float)$obBalance->balance - $valueAccept;

            if ($availableTenant < $valueTotalTenant) {

                return new Response(403, [
                    'status'    => 403,
                    'message'   => 'Favor contactar administrador da conta.',
                    'balance'   => $availableTenant,
                    'necessary' => $valueTotalTenant
                ], 'application/json');
            }

        } else {
            // 🔹 Fluxo normal (somente plano)
            $obBalance   = BalanceSms::getBalanceSms($obUser['id'], $obUser['tenancy_id'], $currentPlan);
            $obSmsAccept = CallbackSms::countSentSmsAccept($obUser['id'], $obUser['tenancy_id']);
            $valueTotal       = $totalMessages * (float)$obBalance->value_sms;
            $valueAccept      = (float)($obSmsAccept->value_total ?? 0);
            $availableBalance = (float)$obBalance->balance - $valueAccept;


            // 🔹 Se não tem saldo nem para 1 SMS, desativa
            if ($availableBalance < (float)$obBalance->value_sms) {
                UserPlans::deactivatePlan($currentPlan, $obUser['tenancy_id'], $obUser['id']);
                return new Response(403, [
                    'status'    => 403,
                    'message'   => 'Saldo insuficiente. Plano foi desativado.',
                    'balance'   => $availableBalance,
                    'necessary' => (float)$obBalance->value_sms
                ], 'application/json');
            }
        }

        // 🔹 4) Cria batch
        $batchId = CampaignBatch::create([
            'campaign_id' => $campaignId,
            'user_id'     => $obUser['id'],
            'tenancy_id'  => $obUser['tenancy_id'],
        ]);

        $dispro    = new DisproClient();
        $totalSent = 0;

        // 🔹 5) Disparo SMS
        foreach ($contacts as $cont) {
            $messages = [[
                "numero"        => $cont['phone'],
                "servico"       => $cont['type_msg'],
                "mensagem"      => $cont['message'],
                "parceiro_id"   => $obUser['id'],
                "codificacao"   => isset($cont['charset_msg']) ? (string)$cont['charset_msg'] : "0",
                "nome_campanha" => $cont['name'] ?? ''
            ]];

            $responseArray = $dispro->send($messages);

            if (!$responseArray) continue;

            foreach ($responseArray['detail'] ?? [] as $smsResult) {
                $callback                 = new CallbackSms();
                $callback->phone_sms      = $smsResult['numero'] ?? '';
                $callback->status_sms     = $smsResult['status'] ?? '';
                $callback->camp_name      = $smsResult['nome_campanha'] ?? '';
                $callback->id_partner     = $smsResult['parceiro_id'] ?? '';
                $callback->user_id        = $obUser['id'];
                $callback->tenancy_id     = $obUser['tenancy_id'];
                $callback->campaign_id    = $campaignId;
                $callback->batch_id       = $batchId;
                $callback->date_send      = date('Y-m-d H:i:s');

                if (strtoupper($smsResult['status'] ?? '') === 'ACCEPTED') {
                    if ($isReseller) {
                        $callback->value_sms = $valueSms;
                        $availableBalance -= $valueSms;
                      BalanceSms::decrementResellerBalance($valueSms, $obUser['id'], $obUser['tenancy_id']);

                    } else {
                        $callback->value_sms = (float)$obBalance->value_sms;
                    }
                } else {
                    $callback->value_sms = 0.00;
                }

               $callback->insertStatus();
                $totalSent++;
            }
        }

        // 🔹 6) Atualiza campanha
        if ($campaignId && $totalSent > 0) {
           CampaignSearch::updateStatusCamp($campaignId, $obUser['tenancy_id'], 'f');
        }

        // 🔹 7) Retorno
        if ($totalSent > 0) {
            return new Response(200, [
                'status'     => 200,
                'message'    => 'SMS(s) enviado(s) com sucesso!',
                'total_sent' => $totalSent,
                'batch_id'   => $batchId
            ], 'application/json');
        }

        return new Response(400, [
            'status'=>400,
            'message'=>'Não foi possível enviar os SMS.'],
            'application/json');
    }

    // Campanha
    public static function sendCampaignSms($request, $id): Response {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, ['status'=>401,'message'=>'Usuário não autenticado.'], 'application/json');
        }
        return self::sendSms($obUser, $id);
    }

    public static function getCampaignSmsSingle(): Response|string
    {
        // 1️⃣ Verifica usuário logado
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status'  => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        // 2️⃣ Busca o plano ativo
        $obPlanId = RegisterTenancies::getActivePlanId($obUser['tenancy_id']);

        $valueSms = 0.0;
        if ($obPlanId) {
            $obPlan = UserPlans::getUserPlanInfoByPlanId($obPlanId, $obUser['tenancy_id']);
            if ($obPlan && isset($obPlan->value_sms)) {
                $valueSms = (float)$obPlan->value_sms;
            }
        }

        // 3️⃣ Renderiza view com valor default
        $content = View::render('/campaign/single', [
            'value_sms' => $valueSms,
        ]);

        return parent::getComponentsCampaign('Maxx Solutions - SMS | Campaign', $content);
    }
        // Avulso

    public static function sendCampaignSmsSingle($request): Response {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $postVars = $request->getPostVars();

        $phones = $postVars['phones'] ?? [];
        $message = $postVars['message'] ?? null;

        // Se não vier optionGroup1/2, assume null
        $optionGroup1 = $postVars['optionGroup1'] ?? null;
        $optionGroup2 = $postVars['optionGroup2'] ?? "0";

        return self::sendSms(
            $obUser,
            null,
            $phones,
            $message,
            $optionGroup1,
            $optionGroup2
        );
    }


}


