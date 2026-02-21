<?php

namespace App\Controller\Pages;

use App\Model\Entity\BalanceSms;
use App\Model\Entity\CallbackSms;
use App\Model\Entity\Rates;
use App\Model\Entity\RegisterTenancies;
use App\Model\Entity\UserPlans;
use App\Model\Entity\UserSearch;
use App\Model\Entity\CampaignBatch;
use DateTime;
use Exception;

class WebStatusSms
{
    /**
     * Processa a cobrança de SMS para o owner da tenancy
     * @param int $userId            -> sempre o ID do owner da tenancy
     * @param string $tenancyId
     * @param float $valueSmsTenancy -> tarifa do tenancy (para débito real)
     * @param int $countDelivered
     * @param int $countSent
     * @param int $countUndelivered
     * @param int $countExpired
     * @param int $batchId
     * @param int $planId
     * @param string $descricao
     * @param float|null $valueSmsReseller -> tarifa do reseller (apenas para log)
     */
    private static function processCharge(
        int $userId,
        string $tenancyId,
        float $valueSmsTenancy,
        int $countDelivered,
        int $countSent,
        int $countUndelivered,
        int $countExpired,
        int $batchId,
        int $planId,
        string $descricao,
        ?float $valueSmsReseller = null
    ): void {
        $totalTarifavel = $countDelivered + $countSent + $countUndelivered + $countExpired;

        if ($valueSmsTenancy <= 0 || $totalTarifavel <= 0) {
            CampaignBatch::markAsCharged($batchId, 2); // tarifa inválida
            return;
        }

        $balanceData = BalanceSms::getBalanceSms($userId, $tenancyId);
        $currentBalance = $balanceData ? (float)$balanceData->balance : 0;
        $totalChargeTenancy = $valueSmsTenancy * $totalTarifavel;
        $totalChargeLog = ($valueSmsReseller ?? $valueSmsTenancy) * $totalTarifavel;

        if ($currentBalance >= $totalChargeTenancy) {
            $newBalance = $currentBalance - $totalChargeTenancy;

            // 💰 Atualiza o saldo do tenancy (débito real)
            BalanceSms::updateBalance($userId, $tenancyId, $planId, $newBalance);

            // 🧾 Registra log com valor do reseller (se houver)
            BalanceSms::insertBalanceLog([
                'user_id'    => $userId,
                'tenancy_id' => $tenancyId,
                'amount'     => -$totalChargeLog,
                'description'=> $descricao
            ]);

            if ($countDelivered === 0 && $countSent === 0 && ($countUndelivered + $countExpired) > 0) {
                CampaignBatch::markAsCharged($batchId, 3); // sem envio real
            } else {
                CampaignBatch::markAsCharged($batchId, 1); // tarifado
            }
            return;
        }

        CampaignBatch::markAsCharged($batchId, 2); // saldo insuficiente
    }

    /**
     * Callback principal da API Pro
     * @throws Exception
     */
    public static function getCallbackPro($request): void
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? '';

        // 🔐 Autenticação básica
        if (stripos($authHeader, 'Basic ') !== 0) {
            http_response_code(401);
            echo json_encode(['error' => 'Autenticação necessária']);
            return;
        }

        $decoded = base64_decode(substr($authHeader, 6));
        [$user, $pass] = explode(':', $decoded, 2);
        $expectedUser = getenv('WEBHOOK_PRO_USER');
        $expectedPass = getenv('WEBHOOK_PRO_PASS');

        if ($user !== $expectedUser || $pass !== $expectedPass) {
            http_response_code(403);
            echo json_encode(['error' => 'Credenciais inválidas']);
            return;
        }

        $payload = file_get_contents('php://input');
        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(['error' => 'JSON inválido']);
            return;
        }

        $items = $data['data']['after'] ?? [];
        if (!$items || !is_array($items)) {
            http_response_code(400);
            echo json_encode(['error' => 'Payload inválido']);
            return;
        }

        $normalizeDate = function ($dateString) {
            if (!$dateString) return null;
            $date = DateTime::createFromFormat('d/m/Y H:i:s', $dateString);
            return $date ? $date->format('Y-m-d H:i:s') : null;
        };

        $statusChargeable = ['SENT', 'DELIVERED', 'UNDELIVERABLE', 'EXPIRED'];
        $count = 0;
        $batchByUser = [];

        // ==================================================
        // 1️⃣ Processa cada item recebido no callback
        // ==================================================
        foreach ($items as $item) {
            $partnerId = $item['parceiro_id'] ?? null;
            $phone     = $item['destino'] ?? null;
            if (!$partnerId || !$phone) continue;

            $obUser = UserSearch::getUserByPartnerId($partnerId);
            if (!$obUser) continue;

            $batch = CampaignBatch::getLastBatchByUser($obUser->id, $obUser->tenancy_id);
            if (!$batch) continue;

            $planId = RegisterTenancies::getActivePlanId($obUser->tenancy_id);
            $planInfo = UserPlans::getUserPlanInfo($planId, $obUser->id, $obUser->tenancy_id);
            $valueSmsPlan = $planInfo->value_sms ?? 0.00;

            $updateDate = $normalizeDate($item['data_atualizacao'] ?? null);
            $dateSend   = $normalizeDate($item['data_insercao'] ?? null);

            $callback = new CallbackSms();
            $callback->batch_id    = $batch->id;
            $callback->phone_sms   = $phone;
            $callback->status_sms  = $item['status'] ?? '';
            $callback->operator    = $item['sms_operator'] ?? '';
            $callback->id_partner  = $partnerId;
            $callback->user_id     = $obUser->id;
            $callback->tenancy_id  = $obUser->tenancy_id;
            $callback->date_send   = $dateSend ?: null;
            $callback->update_date = $updateDate ?: null;

            $status = strtoupper(trim($callback->status_sms));
            $isReseller = (($obUser->user_function ?? '') === 'reseller');

            if (in_array($status, $statusChargeable, true)) {
                $callback->value_sms = $isReseller
                    ? (float)(Rates::getLatestActiveRate($obUser->tenancy_id, $obUser->id)['rate'] ?? 0)
                    : (float)$valueSmsPlan;
            } else {
                $callback->value_sms = 0.00;
            }

            $rowsUpdated = $callback->updateStatus();
            if ($rowsUpdated > 0) $count++;

            $batchByUser[$obUser->id . '-' . $obUser->tenancy_id] = [
                'batch' => $batch,
                'user'  => $obUser
            ];
        }

        // ==================================================
        // 2️⃣ Tarifação consolidada por batch
        // ==================================================
        $processedBatches = [];
        foreach ($batchByUser as $info) {
            $batch   = $info['batch'];
            $obUser  = $info['user'];
            $batchId = $batch->id;

            if (isset($processedBatches[$batchId])) continue;

            $countData = CallbackSms::countSentSms($obUser->id, $obUser->tenancy_id, $batchId);
            $countDelivered   = (int)$countData->delivered;
            $countSent        = (int)$countData->sent;
            $countUndelivered = (int)$countData->undeliverable;
            $countExpired     = (int)$countData->expired;
            $totalTarifavel   = $countDelivered + $countSent + $countUndelivered + $countExpired;

            if ($totalTarifavel <= 0 || !in_array($batch->charged, [0,2])) continue;

            // 🧩 Sempre debita do owner da tenancy
            $ownerId = RegisterTenancies::getTenancyOwnerUserId($obUser->tenancy_id);
            if (!$ownerId) {
                CampaignBatch::markAsCharged($batchId, 2);
                continue;
            }

            // 💰 Tarifa real (tenancy)
            $balanceData = BalanceSms::getBalanceSms($ownerId, $obUser->tenancy_id);
            $valueSmsTenancy = $balanceData ? (float)$balanceData->value_sms : 0;

            // 💵 Tarifa do reseller (para log)
            $rateData = Rates::getLatestActiveRate($obUser->tenancy_id, $obUser->id);
            $valueSmsReseller = $rateData ? (float)($rateData['rate'] ?? 0) : null;

            // 🔧 Chamada final da tarifação
            (new self())->processCharge(
                $ownerId,
                $obUser->tenancy_id,
                $valueSmsTenancy,   // débito real
                $countDelivered,
                $countSent,
                $countUndelivered,
                $countExpired,
                $batchId,
                $planId ?? 0,
                "Tarifa tenancy {$obUser->tenancy_id} ({$countDelivered} SMS enviados por user {$obUser->id}) #{$batchId}",
                $isReseller ? $valueSmsReseller : null // loga tarifa do reseller, se for o caso
            );

            $processedBatches[$batchId] = true;
        }

        // ==================================================
        // 3️⃣ Retorno HTTP
        // ==================================================
        $status = ($count > 0) ? 200 : 204;
        http_response_code($status);
        echo json_encode([
            'status'  => $status,
            'message' => "{$count} registros processados"
        ]);
    }
}

