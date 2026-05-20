<?php

namespace App\Controller\Pages;

use App\Config\TelephonyConfig;
use App\Http\Response;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\CallbackSms;
use App\Model\Entity\Rates;
use App\Model\Entity\RegisterTenancies;
use App\Model\Entity\UserPlans;
use App\Model\Entity\UserSearch;
use App\Model\Entity\CampaignBatch;
use DateTime;
use Exception;
use App\Service\FinancialHierarchyBillingService;
use App\Service\FinancialHierarchyResolver;
use App\Service\PlatformGlobalCostService;
use WilliamCosta\DatabaseManager\Database;

class WebStatusSms
{
    /**
     * Processa a cobrança consolidada do lote usando a cadeia financeira central.
     * @param int $actorUserId       -> usuario que consumiu/originou o lote
     * @param string $tenancyId
     * @param float $retailTotalCharge -> tarifa consolidada do ator
     * @param int $countDelivered
     * @param int $countSent
     * @param int $countUndelivered
     * @param int $countExpired
     * @param int $batchId
     * @param int $planId
     * @param string $descricao
     * @param float|null $resellerTotalCharge -> custo comercial do revendedor, se existir
     * @param float|null $adminTotalCharge -> custo upstream do owner admin, se existir
     */
    private static function processCharge(
        int $actorUserId,
        string $tenancyId,
        float $retailTotalCharge,
        int $countDelivered,
        int $countSent,
        int $countUndelivered,
        int $countExpired,
        int $batchId,
        int $planId,
        string $descricao,
        ?float $resellerTotalCharge = null,
        ?float $adminTotalCharge = null
    ): void {
        $totalTarifavel = $countDelivered + $countSent + $countUndelivered + $countExpired;

        if ($retailTotalCharge <= 0 || $totalTarifavel <= 0) {
            CampaignBatch::markAsCharged($batchId, 2); // tarifa inválida
            return;
        }

        $plan = FinancialHierarchyBillingService::buildDebitPlan([
            'actor_user_id' => $actorUserId,
            'tenancy_id' => $tenancyId,
            'module' => 'sms',
            'event' => 'batch_callback',
            'retail_amount' => round($retailTotalCharge, 4),
            'reseller_amount' => round((float)($resellerTotalCharge ?? 0), 4),
            'admin_amount' => round((float)($adminTotalCharge ?? 0), 4),
            'provider_reference' => (string)$batchId,
            'related_type' => 'sms_batch',
            'related_id' => (string)$batchId,
            'operation_key_base' => sprintf('sms:batch:%d:user:%d', $batchId, $actorUserId),
            'description_prefix' => $descricao,
            'metadata' => [
                'count_delivered' => $countDelivered,
                'count_sent' => $countSent,
                'count_undelivered' => $countUndelivered,
                'count_expired' => $countExpired,
                'plan_id' => $planId,
                'admin_upstream_total_charge' => round((float)($adminTotalCharge ?? 0), 4),
            ],
        ]);

        $result = FinancialHierarchyBillingService::debitPlan($plan);

        if (empty($result['ok'])) {
            CampaignBatch::markAsCharged($batchId, 2);
            return;
        }

        if ($countDelivered === 0 && $countSent === 0 && ($countUndelivered + $countExpired) > 0) {
            CampaignBatch::markAsCharged($batchId, 3); // sem envio real
        } else {
            CampaignBatch::markAsCharged($batchId, 1); // tarifado
        }
    }

    /**
     * Callback principal da API Pro
     * @throws Exception
     */
    public static function getCallbackPro($request): Response
    {
        $authHeader = self::getAuthorizationHeader();

        // 🔐 Autenticação básica
        if (stripos($authHeader, 'Basic ') !== 0) {
            return self::json(401, ['error' => 'Autenticação necessária']);
        }

        $decoded = base64_decode(substr($authHeader, 6), true);
        if ($decoded === false || strpos($decoded, ':') === false) {
            return self::json(401, ['error' => 'Credenciais inválidas']);
        }

        [$user, $pass] = explode(':', $decoded, 2);
        $expectedUser = (string)getenv('WEBHOOK_PRO_USER');
        $expectedPass = (string)getenv('WEBHOOK_PRO_PASS');

        if ($expectedUser === '' || $expectedPass === '') {
            return self::json(500, ['error' => 'Webhook Basic Auth não configurado']);
        }

        if ($user !== $expectedUser || $pass !== $expectedPass) {
            return self::json(403, ['error' => 'Credenciais inválidas']);
        }

        $payload = file_get_contents('php://input');
        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::json(400, ['error' => 'JSON inválido']);
        }

        self::storeRawWebhookEvents($data);

        $items = $data['data']['after'] ?? [];
        if (!$items || !is_array($items)) {
            return self::json(400, ['error' => 'Payload inválido']);
        }

        $normalizeDate = function ($dateString) {
            if (!$dateString) return null;
            $date = DateTime::createFromFormat('d/m/Y H:i:s', $dateString)
                ?: DateTime::createFromFormat('Y-m-d H:i:s', $dateString);
            return $date ? $date->format('Y-m-d H:i:s') : null;
        };
        $rootAction = $data['action'] ?? $data['data']['action'] ?? $data['event'] ?? $data['type'] ?? null;
        $rootObject = $data['object'] ?? $data['data']['object'] ?? $data['resource'] ?? null;
        $rootCreated = $normalizeDate($data['created_at'] ?? $data['created'] ?? null);

        if (strtolower((string)$rootAction) === 'mo') {
            $count = self::processMoItems($items, $rootAction, $rootObject, $rootCreated, $normalizeDate);

            return self::json(200, [
                'status' => 200,
                'message' => "{$count} respostas MO processadas"
            ]);
        }

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

            if (str_starts_with((string)$partnerId, 'site-test-sms-')) {
                if (self::processSiteTestSmsCallback($item, (string)$partnerId, (string)$phone, $rootAction, $rootObject, $rootCreated, $normalizeDate)) {
                    $count++;
                }
                continue;
            }

            $obUser = UserSearch::getUserByPartnerId($partnerId);
            if (!$obUser) continue;

            $batchIdFromPartner = self::getBatchIdFromPartnerId((string)$partnerId);
            $batch = $batchIdFromPartner
                ? CampaignBatch::getByIdAndTenancy($batchIdFromPartner, $obUser->tenancy_id)
                : CampaignBatch::getLastBatchByUser($obUser->id, $obUser->tenancy_id);
            if (!$batch) continue;

            $planId = RegisterTenancies::getActivePlanId($obUser->tenancy_id);
            $planInfo = UserPlans::getUserPlanInfoByPlanId((int)$planId, (string)$obUser->tenancy_id);
            $valueSmsPlan = round((float)($planInfo->value_sms ?? 0), 4);

            $updateDate = $normalizeDate($item['data_atualizacao'] ?? null);
            $dateSend   = $normalizeDate($item['data_insercao'] ?? null);
            $callback = new CallbackSms();
            $callback->batch_id    = $batch->id;
            $callback->phone_sms   = $phone;
            $callback->status_sms  = $item['status'] ?? '';
            $callback->operator    = $item['sms_operator'] ?? '';
            $callback->id_partner  = $partnerId;
            $callback->sms_provider = CallbackSms::defaultSmsProvider();
            $callback->user_id     = $obUser->id;
            $callback->tenancy_id  = $obUser->tenancy_id;
            $callback->date_send   = $dateSend ?: null;
            $callback->update_date = $updateDate ?: null;
            $callback->codigo_status = $item['codigo_status'] ?? $item['status_code'] ?? $item['cod_status'] ?? null;
            $callback->codigo_detalhe = $item['codigo_detalhe'] ?? $item['detail_code'] ?? $item['cod_detalhe'] ?? null;
            $callback->descricao_detalhe = $item['descricao_detalhe'] ?? $item['detail_description'] ?? $item['descricao'] ?? $item['message'] ?? null;
            $callback->webhook_action = $item['webhook_action'] ?? $item['action'] ?? $rootAction;
            $callback->webhook_object = $item['webhook_object'] ?? $item['object'] ?? $rootObject;
            $callback->webhook_created = $normalizeDate($item['webhook_created'] ?? $item['created_at'] ?? null) ?: $rootCreated;
            $callback->response_text = $item['resposta'] ?? null;
            $callback->origin_id = $item['origin_id'] ?? $item['id'] ?? null;
            $callback->received_at = date('Y-m-d H:i:s');
            $callback->sms_reference_id = $item['sms_reference_id'] ?? $item['reference_id'] ?? $item['referencia'] ?? null;
            $callback->sms_customer_id = $item['sms_customer_id'] ?? $item['customer_id'] ?? null;
            $callback->sms_account_id = $item['sms_account_id'] ?? $item['account_id'] ?? null;
            $callback->sms_user_id = $item['sms_user_id'] ?? $item['user_id'] ?? null;

            $status = strtoupper(trim($callback->status_sms));
            $isReseller = (($obUser->user_function ?? '') === 'reseller');
            $existingCallback = CallbackSms::findLatestOutboundContext(
                (string)$obUser->tenancy_id,
                (string)$partnerId,
                (string)$phone
            );
            $existingValueSms = round((float)($existingCallback['value_sms'] ?? 0), 4);

            if (in_array($status, $statusChargeable, true)) {
                if ($existingValueSms > 0) {
                    $callback->value_sms = $existingValueSms;
                } else {
                    $callback->value_sms = $isReseller
                        ? (float)(Rates::getLatestActiveRate($obUser->tenancy_id, $obUser->id)['rate'] ?? 0)
                        : (float)$valueSmsPlan;
                }
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

            $context = FinancialHierarchyResolver::resolveContext((int)$obUser->id, (string)$obUser->tenancy_id);
            $ownerId = (int)$context->owner_admin_id;
            if ($ownerId <= 0) {
                CampaignBatch::markAsCharged($batchId, 2);
                continue;
            }

            $retailTotalCharge = round((float)($countData->value_total ?? 0), 4);
            $balanceData = BalanceSms::getBalanceSms($ownerId, $obUser->tenancy_id);
            $fallbackUnitRate = $balanceData ? (float)$balanceData->value_sms : 0.0;
            $adminTotalCharge = self::resolveBatchAdminSmsTotalCharge($batchId, $obUser->tenancy_id, $fallbackUnitRate);

            $resellerTotalCharge = null;
            if (!empty($context->reseller_id)) {
                $rateData = Rates::getLatestActiveRate($obUser->tenancy_id, (int)$context->reseller_id);
                $resellerUnitRate = $rateData ? (float)($rateData['rate'] ?? 0) : 0.0;
                $resellerTotalCharge = round($resellerUnitRate * $totalTarifavel, 4);
            }

            // 🔧 Chamada final da tarifação
            (new self())->processCharge(
                (int)$obUser->id,
                $obUser->tenancy_id,
                $retailTotalCharge,
                $countDelivered,
                $countSent,
                $countUndelivered,
                $countExpired,
                $batchId,
                $planId ?? 0,
                "Tarifa tenancy {$obUser->tenancy_id} ({$countDelivered} SMS enviados por user {$obUser->id}) #{$batchId}",
                $resellerTotalCharge,
                $adminTotalCharge
            );

            $processedBatches[$batchId] = true;
        }

        // ==================================================
        // 3️⃣ Retorno HTTP
        // ==================================================
        $status = 200;
        return self::json($status, [
            'status'  => $status,
            'message' => "{$count} registros processados"
        ]);
    }

    private static function json(int $status, array $payload): Response
    {
        return new Response($status, $payload, 'application/json');
    }

    private static function processSiteTestSmsCallback(
        array $item,
        string $partnerId,
        string $phone,
        ?string $rootAction,
        ?string $rootObject,
        ?string $rootCreated,
        callable $normalizeDate
    ): bool {
        $updateDate = $normalizeDate($item['data_atualizacao'] ?? null) ?: date('Y-m-d H:i:s');
        $status = strtoupper(trim((string)($item['status'] ?? '')));

        $updated = (new Database())->execute("
            UPDATE callback
               SET status_sms = :status_sms,
                   value_sms = 0,
                   operator = :operator,
                   update_date = :update_date,
                   codigo_status = :codigo_status,
                   codigo_detalhe = :codigo_detalhe,
                   descricao_detalhe = :descricao_detalhe,
                   webhook_action = :webhook_action,
                   webhook_object = :webhook_object,
                   webhook_created = :webhook_created,
                   response_text = COALESCE(:response_text, response_text),
                   origin_id = :origin_id,
                   received_at = :received_at,
                   sms_reference_id = :sms_reference_id,
                   sms_customer_id = :sms_customer_id,
                   sms_account_id = :sms_account_id,
                   sms_user_id = :sms_user_id
             WHERE id_partner = :id_partner
               AND tenancy_id = :tenancy_id
        ", [
            ':status_sms' => $status,
            ':operator' => $item['sms_operator'] ?? '',
            ':update_date' => $updateDate,
            ':codigo_status' => $item['codigo_status'] ?? $item['status_code'] ?? $item['cod_status'] ?? null,
            ':codigo_detalhe' => $item['codigo_detalhe'] ?? $item['detail_code'] ?? $item['cod_detalhe'] ?? null,
            ':descricao_detalhe' => $item['descricao_detalhe'] ?? $item['detail_description'] ?? $item['descricao'] ?? $item['message'] ?? null,
            ':webhook_action' => $item['webhook_action'] ?? $item['action'] ?? $rootAction,
            ':webhook_object' => $item['webhook_object'] ?? $item['object'] ?? $rootObject,
            ':webhook_created' => $normalizeDate($item['webhook_created'] ?? $item['created_at'] ?? null) ?: $rootCreated,
            ':response_text' => $item['resposta'] ?? null,
            ':origin_id' => $item['origin_id'] ?? $item['id'] ?? null,
            ':received_at' => date('Y-m-d H:i:s'),
            ':sms_reference_id' => $item['sms_reference_id'] ?? $item['reference_id'] ?? $item['referencia'] ?? null,
            ':sms_customer_id' => $item['sms_customer_id'] ?? $item['customer_id'] ?? null,
            ':sms_account_id' => $item['sms_account_id'] ?? $item['account_id'] ?? null,
            ':sms_user_id' => $item['sms_user_id'] ?? $item['user_id'] ?? null,
            ':id_partner' => $partnerId,
            ':tenancy_id' => (string)TelephonyConfig::env('SITE_TEST_TENANCY_ID', '00000000-0000-4000-8000-000000000001'),
        ])->rowCount();

        if (preg_match('/^site-test-sms-(\d+)-/', $partnerId, $matches)) {
            (new Database())->execute("
                UPDATE site_service_tests
                   SET status = :status,
                       provider = 'disparopro',
                       provider_message_id = :provider_message_id,
                       provider_response = :provider_response,
                       error_message = :error_message,
                       sent_at = COALESCE(sent_at, :sent_at),
                       updated_at = NOW()
                 WHERE id = :id
            ", [
                ':status' => in_array($status, ['SENT', 'DELIVERED', 'ACCEPTED'], true) ? 'sent' : 'failed',
                ':provider_message_id' => $item['sms_reference_id'] ?? $item['reference_id'] ?? $partnerId,
                ':provider_response' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':error_message' => in_array($status, ['SENT', 'DELIVERED', 'ACCEPTED'], true) ? null : ($item['descricao_detalhe'] ?? $item['message'] ?? $status),
                ':sent_at' => date('Y-m-d H:i:s'),
                ':id' => (int)$matches[1],
            ]);
        }

        return $updated > 0;
    }

    private static function getAuthorizationHeader(): string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        foreach ($headers as $key => $value) {
            if (strtolower((string)$key) === 'authorization') {
                return trim((string)$value);
            }
        }

        return trim((string)(
            $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? $_SERVER['Authorization']
            ?? ''
        ));
    }

    private static function processMoItems(
        array $items,
        ?string $rootAction,
        ?string $rootObject,
        ?string $rootCreated,
        callable $normalizeDate
    ): int {
        $count = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $partnerId = $item['parceiro_id'] ?? null;
            $phone = $item['origem'] ?? null;

            if (!$partnerId || !$phone) {
                continue;
            }

            $obUser = UserSearch::getUserByPartnerId((string)$partnerId);
            if (!$obUser) {
                continue;
            }

            $batchIdFromPartner = self::getBatchIdFromPartnerId((string)$partnerId);
            $batch = $batchIdFromPartner
                ? CampaignBatch::getByIdAndTenancy($batchIdFromPartner, $obUser->tenancy_id)
                : CampaignBatch::getLastBatchByUser($obUser->id, $obUser->tenancy_id);
            $outboundContext = CallbackSms::findLatestOutboundContext(
                (string)$obUser->tenancy_id,
                (string)$partnerId,
                (string)$phone
            );
            $receivedAt = $normalizeDate($item['data_recebimento'] ?? null) ?: date('Y-m-d H:i:s');

            $callback = new CallbackSms();
            $callback->batch_id = $batch->id ?? ($outboundContext['batch_id'] ?? null);
            $callback->phone_sms = (string)$phone;
            $callback->status_sms = 'MO';
            $callback->operator = CallbackSms::pickInboundMoOperator(
                $item['sms_operator'] ?? $item['operadora'] ?? null,
                $outboundContext['operator'] ?? null
            );
            $callback->value_sms = 0.00;
            $callback->camp_name = $batch->camp_name ?? ($outboundContext['camp_name'] ?? '');
            $callback->id_partner = (string)$partnerId;
            $callback->sms_provider = CallbackSms::defaultSmsProvider();
            $callback->user_id = (int)$obUser->id;
            $callback->tenancy_id = (string)$obUser->tenancy_id;
            $callback->campaign_id = $batch->campaign_id ?? ($outboundContext['campaign_id'] ?? null);
            $callback->date_send = $receivedAt;
            $callback->update_date = $receivedAt;
            $callback->webhook_action = $item['action'] ?? $rootAction;
            $callback->webhook_object = $item['object'] ?? $rootObject;
            $callback->webhook_created = $normalizeDate($item['created'] ?? null) ?: $rootCreated;
            $callback->response_text = $item['resposta'] ?? null;
            $callback->origin_id = isset($item['origem_id']) ? (string)$item['origem_id'] : null;
            $callback->received_at = $receivedAt;
            $callback->sms_reference_id = isset($item['id']) ? (string)$item['id'] : null;
            $callback->sms_customer_id = isset($item['sms_cus_id']) ? (string)$item['sms_cus_id'] : null;
            $callback->sms_account_id = isset($item['sms_acc_id']) ? (string)$item['sms_acc_id'] : null;
            $callback->sms_user_id = isset($item['sms_use_id']) ? (string)$item['sms_use_id'] : null;

            $callback->updateMoResponse();

            $hasInboundMo = CallbackSms::inboundMoExists(
                (string)$callback->tenancy_id,
                (int)$callback->user_id,
                $callback->sms_reference_id,
                $callback->origin_id,
                $callback->id_partner,
                $callback->phone_sms
            );

            if (!$hasInboundMo) {
                $callback->insertInboundMo();
            }

            $count++;
        }

        return $count;
    }

    private static function storeRawWebhookEvents(array $data): void
    {
        try {
            self::ensureWebhookEventsTable();

            $items = $data['data']['after'] ?? [];
            if (!$items || !is_array($items)) {
                $items = [$data];
            }

            $rootAction = $data['action'] ?? $data['data']['action'] ?? $data['event'] ?? $data['type'] ?? null;
            $rootObject = $data['object'] ?? $data['data']['object'] ?? $data['resource'] ?? null;
            $rootPayload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $itemPayload = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                (new Database())->execute(
                    "INSERT INTO sms_webhook_events (
                        webhook_object,
                        webhook_action,
                        status_sms,
                        phone_sms,
                        id_partner,
                        origin_id,
                        raw_item,
                        raw_payload,
                        received_at
                    ) VALUES (
                        :webhook_object,
                        :webhook_action,
                        :status_sms,
                        :phone_sms,
                        :id_partner,
                        :origin_id,
                        :raw_item,
                        :raw_payload,
                        NOW()
                    )",
                    [
                        ':webhook_object' => $item['webhook_object'] ?? $item['object'] ?? $rootObject,
                        ':webhook_action' => $item['webhook_action'] ?? $item['action'] ?? $rootAction,
                        ':status_sms' => $item['status'] ?? null,
                        ':phone_sms' => $item['destino'] ?? $item['origem'] ?? $item['phone'] ?? $item['numero'] ?? null,
                        ':id_partner' => $item['parceiro_id'] ?? $item['partner_id'] ?? null,
                        ':origin_id' => $item['origin_id'] ?? $item['id'] ?? null,
                        ':raw_item' => $itemPayload !== false ? $itemPayload : null,
                        ':raw_payload' => $rootPayload !== false ? $rootPayload : null,
                    ]
                );
            }
        } catch (Exception $e) {
            error_log('Falha ao gravar webhook SMS bruto: ' . $e->getMessage());
        }
    }

    private static function getBatchIdFromPartnerId(string $partnerId): ?int
    {
        if (preg_match('/-b(\d+)-/i', $partnerId, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    private static function ensureWebhookEventsTable(): void
    {
        (new Database())->execute(
            "CREATE TABLE IF NOT EXISTS sms_webhook_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                webhook_object VARCHAR(50) NULL,
                webhook_action VARCHAR(50) NULL,
                status_sms VARCHAR(50) NULL,
                phone_sms VARCHAR(30) NULL,
                id_partner VARCHAR(80) NULL,
                origin_id VARCHAR(120) NULL,
                raw_item JSON NULL,
                raw_payload JSON NULL,
                received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_received_at (received_at),
                KEY idx_action_object (webhook_object, webhook_action),
                KEY idx_partner_phone (id_partner, phone_sms)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function resolveBatchAdminSmsTotalCharge(int $batchId, string $tenancyId, float $fallbackUnitRate): float
    {
        $rows = (new Database())->execute(
            "SELECT
                COALESCE(NULLIF(LOWER(TRIM(sms_provider)), ''), :default_provider) AS sms_provider,
                COALESCE(NULLIF(TRIM(operator), ''), 'UNKNOWN') AS operator_name,
                COUNT(*) AS total_messages
             FROM callback
             WHERE batch_id = :batch_id
               AND tenancy_id = :tenancy_id
               AND UPPER(COALESCE(status_sms, '')) IN ('SENT', 'DELIVERED', 'UNDELIVERABLE', 'EXPIRED')
             GROUP BY COALESCE(NULLIF(LOWER(TRIM(sms_provider)), ''), :default_provider), COALESCE(NULLIF(TRIM(operator), ''), 'UNKNOWN')",
            [
                ':batch_id' => $batchId,
                ':tenancy_id' => $tenancyId,
                ':default_provider' => CallbackSms::defaultSmsProvider(),
            ]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $total = 0.0;
        foreach ($rows as $row) {
            $unitCost = PlatformGlobalCostService::resolveAmount('SMS', [
                'provider' => (string)($row['sms_provider'] ?? ''),
                'carrier' => CallbackSms::normalizeOperatorForDashboard((string)($row['operator_name'] ?? '')),
            ], $fallbackUnitRate);

            $total += round($unitCost * (int)($row['total_messages'] ?? 0), 4);
        }

        return round($total, 4);
    }
}
