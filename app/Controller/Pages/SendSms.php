<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Model\Entity\CampaignSearch;
use App\Model\Entity\Rates;
use App\Model\Entity\UserSearch;
use App\Session\User as SessionUser;
use App\Model\Entity\CallbackSms;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\CampaignBatch;
use App\Model\Entity\PlanCatalog;
use App\Model\Entity\UserPlans;
use App\Service\CampaignSchedulerService;
use App\Service\FinancialHierarchyBillingService;
use App\Service\FinancialHierarchyResolver;
use App\Service\PlanLimitEnforcementService;
use App\Service\PlanRuntimeService;
use App\Service\PlatformGlobalCostService;
use App\Service\SmsCampaignPreparationService;
use App\Utils\View;


class SendSms extends ViewComponents
{
    private const ALLOWED_SERVICES = ['short', 'mkt'];
    private const ALLOWED_CODINGS = ['0', '8'];

    private static function activePlanId(string $tenancyId): int
    {
        $subscription = PlanRuntimeService::getActiveSubscription($tenancyId);
        return (int)($subscription['plan_id'] ?? 0);
    }

    private static function currentSmsRate(string $tenancyId): float
    {
        $planId = self::activePlanId($tenancyId);
        if ($planId <= 0) {
            return 0.0;
        }

        $plan = PlanCatalog::findById($planId);
        return (float)($plan['value_sms'] ?? 0);
    }

    public static function buildSmsPreflightPlan(int $userId, string $tenancyId, int $totalUnits, float $retailUnitRate): array
    {
        $context = FinancialHierarchyResolver::resolveContext($userId, $tenancyId);
        $retailAmount = round(max(0, $totalUnits) * max(0, $retailUnitRate), 4);
        $resellerAmount = 0.0;
        $adminAmount = 0.0;

        if ($context->reseller_id && (int)$context->reseller_id !== $userId) {
            $rateData = Rates::getLatestActiveRate($tenancyId, (int)$context->reseller_id);
            $resellerUnitRate = (float)($rateData['rate'] ?? 0);
            $resellerAmount = round($totalUnits * $resellerUnitRate, 4);
        }

        if ($context->owner_admin_id > 0 && (int)$context->owner_admin_id !== $userId) {
            $ownerBalance = BalanceSms::getBalanceSms((int)$context->owner_admin_id, $tenancyId);
            $adminUnitRate = PlatformGlobalCostService::resolveAmount(
                'SMS',
                ['provider' => CallbackSms::defaultSmsProvider()],
                (float)($ownerBalance->value_sms ?? 0)
            );
            $adminAmount = round($totalUnits * $adminUnitRate, 4);
        }

        return FinancialHierarchyBillingService::buildDebitPlan([
            'actor_user_id' => $userId,
            'tenancy_id' => $tenancyId,
            'module' => 'sms',
            'event' => 'preflight',
            'retail_amount' => $retailAmount,
            'reseller_amount' => $resellerAmount,
            'admin_amount' => $adminAmount,
            'related_type' => 'sms_dispatch',
            'related_id' => sprintf('%s:%d:%d', $tenancyId, $userId, $totalUnits),
            'operation_key_base' => sprintf('sms:preflight:%s:%d:%d', $tenancyId, $userId, $totalUnits),
            'description_prefix' => 'SMS PREFLIGHT',
            'metadata' => [
                'total_units' => $totalUnits,
                'retail_unit_rate' => round($retailUnitRate, 4),
                'admin_upstream_unit_cost' => round($adminAmount > 0 && $totalUnits > 0 ? ($adminAmount / $totalUnits) : 0, 6),
            ],
        ]);
    }

    private static function normalizePhone(string $phone): string
    {
        $phone = str_replace(',', '.', $phone);
        if (stripos($phone, 'e') !== false) {
            $phone = number_format((float)$phone, 0, '', '');
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 11) {
            $digits = '55' . $digits;
        }

        if (strlen($digits) === 13 && !str_starts_with($digits, '55')) {
            $digits = '55' . substr($digits, -11);
        }

        return $digits;
    }

    private static function isValidPhone(string $phone): bool
    {
        return str_starts_with($phone, '55') && strlen($phone) >= 12 && strlen($phone) <= 13;
    }

    private static function normalizeService(?string $service): string
    {
        $service = strtolower(trim((string)$service));

        if (in_array($service, ['protocolo', 'protocol'], true)) {
            return 'short';
        }

        return in_array($service, self::ALLOWED_SERVICES, true) ? $service : 'short';
    }

    private static function normalizeCoding(?string $coding): string
    {
        $coding = trim((string)$coding);
        return in_array($coding, self::ALLOWED_CODINGS, true) ? $coding : '0';
    }

    private static function calculateSmsUnits(string $message, string $coding): int
    {
        $length = mb_strlen($message, 'UTF-8');
        if ($length === 0) {
            return 0;
        }

        if ($coding === '8') {
            if ($length > 1340) {
                return 0;
            }

            return $length <= 70 ? 1 : (int)ceil($length / 67);
        }

        if ($length > 1377) {
            return 0;
        }

        return $length <= 160 ? 1 : (int)ceil($length / 153);
    }

    public static function prepareContacts(array $contacts): array
    {
        $prepared = [];
        $seen = [];

        foreach ($contacts as $cont) {
            $phone = self::normalizePhone((string)($cont['phone'] ?? ''));
            $message = trim((string)($cont['message'] ?? ''));
            $service = self::normalizeService($cont['type_msg'] ?? null);
            $coding = self::normalizeCoding(isset($cont['charset_msg']) ? (string)$cont['charset_msg'] : '0');
            $units = self::calculateSmsUnits($message, $coding);

            if (!self::isValidPhone($phone) || $units < 1) {
                continue;
            }

            $key = $phone . '|' . sha1($message) . '|' . $service . '|' . $coding;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $prepared[] = [
                'phone' => $phone,
                'type_msg' => $service,
                'message' => $message,
                'charset_msg' => $coding,
                'name' => trim((string)($cont['name'] ?? '')),
                'sms_units' => $units
            ];
        }

        return $prepared;
    }

    public static function sendSms($obUser, ?int $campaignId = null, array $phones = [], ?string $message = null, ?string $service = null, ?string $coding = "0"): Response {
        $isReseller = isset($obUser['function']) && $obUser['function'] === 'reseller';
        $currentPlan = self::activePlanId((string)$obUser['tenancy_id']);

        if ($currentPlan <= 0) {
            return new Response(404, [
                'status'  => 404,
                'message' => $isReseller
                    ? 'Favor contactar administrador da conta. Plano inativo.'
                    : 'Nenhum Plano habilitado ou plano inativo.'
            ], 'application/json');
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

        $contacts = self::prepareContacts($contacts);
        if (empty($contacts)) {
            return new Response(422, [
                'status' => 422,
                'message' => 'Nenhum SMS válido para envio. Verifique telefones, mensagem, serviço e codificação.'
            ], 'application/json');
        }

        $totalMessages = count($contacts);
        $totalUnits = array_sum(array_column($contacts, 'sms_units'));

        $limitAccess = PlanLimitEnforcementService::assertWithinLimitForUser($obUser, 'sms', $totalMessages, [
            'period' => 'current_month',
        ]);
        if (empty($limitAccess['allowed'])) {
            return new Response(403, [
                'status' => 403,
                'message' => $limitAccess['message'] ?? 'Limite de SMS do plano atingido.',
                'meta' => [
                    'usage' => (int)($limitAccess['usage'] ?? 0),
                    'projected_usage' => (int)($limitAccess['projected_usage'] ?? 0),
                    'limit' => $limitAccess['limit'] ?? null,
                    'period' => (string)($limitAccess['period'] ?? 'current_month'),
                ],
            ], 'application/json');
        }

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
            $valueTotal       = $totalUnits * $valueSms;

            if ($availableBalance < $valueTotal) {
                return new Response(403, [
                    'status'    => 403,
                    'message'   => 'Saldo insuficiente para enviar Sms.',
                    'balance'   => $availableBalance,
                    'necessary' => $valueTotal
                ], 'application/json');
            }

            $obBalance   = BalanceSms::getBalanceSms(null, $obUser['tenancy_id'], $currentPlan);
            $planSmsRate = self::currentSmsRate((string)$obUser['tenancy_id']);
            if (!$obBalance || $planSmsRate <= 0) {
                return new Response(404, ['status'=>404,'message'=>'Saldo ou tarifa da conta principal não configurados.'], 'application/json');
            }

        } else {
            // 🔹 Fluxo normal (somente plano)
            $obBalance   = BalanceSms::getBalanceSms($obUser['id'], $obUser['tenancy_id'], $currentPlan);
            $planSmsRate = self::currentSmsRate((string)$obUser['tenancy_id']);
            if (!$obBalance || $planSmsRate <= 0) {
                return new Response(404, ['status'=>404,'message'=>'Saldo ou tarifa SMS não configurados.'], 'application/json');
            }
            $valueTotal       = $totalUnits * $planSmsRate;
            $availableBalance = (float)$obBalance->balance;

            // 🔹 Se não tem saldo nem para 1 SMS, desativa
            if ($availableBalance < $planSmsRate) {
                UserPlans::deactivatePlan($currentPlan, $obUser['tenancy_id'], $obUser['id']);
                return new Response(403, [
                    'status'    => 403,
                    'message'   => 'Saldo insuficiente. Plano foi desativado.',
                    'balance'   => $availableBalance,
                    'necessary' => $planSmsRate
                ], 'application/json');
            }

            if ($availableBalance < $valueTotal) {
                return new Response(403, [
                    'status'    => 403,
                    'message'   => 'Saldo insuficiente para enviar todos os SMS.',
                    'balance'   => $availableBalance,
                    'necessary' => $valueTotal
                ], 'application/json');
            }
        }

        try {
            $preflightPlan = self::buildSmsPreflightPlan(
                (int)$obUser['id'],
                (string)$obUser['tenancy_id'],
                (int)$totalUnits,
                $isReseller ? (float)$valueSms : $planSmsRate
            );
            FinancialHierarchyBillingService::assertSufficientBalance($preflightPlan);
        } catch (\Throwable $e) {
            return new Response(403, [
                'status' => 403,
                'message' => $isReseller
                    ? 'Saldo insuficiente na cadeia financeira do revendedor.'
                    : 'Saldo insuficiente na cadeia financeira da conta.',
                'error' => $e->getMessage(),
            ], 'application/json');
        }

        // 🔹 4) Cria batch
        $batchId = CampaignBatch::create([
            'campaign_id' => $campaignId,
            'user_id'     => $obUser['id'],
            'tenancy_id'  => $obUser['tenancy_id'],
        ]);

        $dispro    = new DisproClient();
        $totalAccepted = 0;
        $totalProcessed = 0;
        $totalFailed = 0;
        $apiErrors = [];

        // 🔹 5) Disparo SMS
        foreach ($contacts as $index => $cont) {
            $partnerId = substr(sprintf('u%s-b%s-%s', $obUser['id'], $batchId, $index + 1), 0, 100);
            $messages = [[
                "numero"        => $cont['phone'],
                "servico"       => $cont['type_msg'],
                "mensagem"      => $cont['message'],
                "parceiro_id"   => $partnerId,
                "codificacao"   => (string)$cont['charset_msg'],
                "nome_campanha" => $cont['name'] ?? ''
            ]];

            $responseArray = $dispro->send($messages);

            if (!$responseArray) {
                $apiErrors[] = $dispro->getLastError() ?: 'Falha sem detalhe ao enviar SMS.';
                $totalFailed++;
                continue;
            }

            $details = $responseArray['detail'] ?? [];
            if (empty($details)) {
                $apiErrors[] = 'API DisparoPro não retornou detalhes para o SMS.';
                $totalFailed++;
                continue;
            }

            foreach ($details as $smsResult) {
                if (!is_array($smsResult)) {
                    $apiErrors[] = (string)$smsResult;
                    $totalFailed++;
                    continue;
                }

                $callback                 = new CallbackSms();
                $callback->phone_sms      = $smsResult['numero'] ?? '';
                $callback->status_sms     = $smsResult['status'] ?? '';
                $callback->camp_name      = $smsResult['nome_campanha'] ?? '';
                $callback->id_partner     = $smsResult['parceiro_id'] ?? '';
                $callback->sms_provider   = CallbackSms::defaultSmsProvider();
                $callback->user_id        = $obUser['id'];
                $callback->tenancy_id     = $obUser['tenancy_id'];
                $callback->campaign_id    = $campaignId;
                $callback->batch_id       = $batchId;
                $callback->date_send      = date('Y-m-d H:i:s');

                if (strtoupper($smsResult['status'] ?? '') === 'ACCEPTED') {
                    $chargeValue = ((int)$cont['sms_units']) * ($isReseller ? (float)$valueSms : $planSmsRate);
                    $callback->value_sms = $chargeValue;
                    $totalAccepted++;
                } else {
                    $callback->value_sms = 0.00;
                    $totalFailed++;
                }

               $callback->insertStatus();
                $totalProcessed++;
            }
        }

        // 🔹 6) Atualiza campanha
        if ($campaignId && $totalProcessed > 0) {
           CampaignSearch::updateStatusCamp($campaignId, $obUser['tenancy_id'], 'f');
        }

        // 🔹 7) Retorno
        if ($totalAccepted > 0) {
            return new Response(200, [
                'status'     => 200,
                'message'    => 'SMS(s) enviado(s) com sucesso!',
                'total_sent' => $totalAccepted,
                'total_processed' => $totalProcessed,
                'total_failed' => $totalFailed,
                'sms_units' => $totalUnits,
                'batch_id'   => $batchId
            ], 'application/json');
        }

        return new Response(400, [
            'status'=>400,
            'message'=>'Não foi possível enviar os SMS.',
            'errors' => array_values(array_unique(array_filter($apiErrors)))
        ],
            'application/json');
    }

    // Campanha
    public static function sendCampaignSms($request, $id): Response {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, ['status'=>401,'message'=>'Usuário não autenticado.'], 'application/json');
        }

        $postVars = $request->getPostVars();
        $scheduleMode = strtolower(trim((string)($postVars['schedule_mode'] ?? 'now')));
        $scheduledAtInput = trim((string)($postVars['scheduled_at'] ?? ''));

        if ($scheduleMode === 'scheduled' || $scheduledAtInput !== '') {
            try {
                $scheduledAt = self::parseScheduledAt($scheduledAtInput);
                $prepared = SmsCampaignPreparationService::prepare($obUser, (int)$id);
                $scheduleId = CampaignSchedulerService::schedule(
                    $obUser,
                    'sms',
                    'SMS Campaign #' . (int)$id,
                    $scheduledAt,
                    [
                        'campaign_id' => (int)$id,
                        'message_count' => (int)($prepared['total_messages'] ?? 0),
                        'total_units' => (int)($prepared['total_units'] ?? 0),
                        'created_via' => 'campaign_send_route',
                    ],
                    [
                        'native_table' => 'campaign',
                        'native_id' => (int)$id,
                        'dispatch_mode' => 'persistent_queue',
                        'timezone' => trim((string)($postVars['timezone'] ?? '')) ?: ($obUser['timezone'] ?? getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo'),
                    ]
                );

                return new Response(200, [
                    'status' => 200,
                    'scheduled' => true,
                    'schedule_id' => $scheduleId,
                    'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                    'message' => 'Campanha SMS agendada com sucesso.',
                ], 'application/json');
            } catch (\Throwable $e) {
                return new Response(422, [
                    'status' => 422,
                    'message' => $e->getMessage(),
                ], 'application/json');
            }
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

        $valueSms = self::currentSmsRate((string)$obUser['tenancy_id']);

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

        $phones = $postVars['phones'] ?? $postVars['phone'] ?? $postVars['numero'] ?? $postVars['number'] ?? [];
        if (!is_array($phones) && trim((string)$phones) !== '') {
            $phones = str_contains((string)$phones, ',')
                ? array_map('trim', explode(',', (string)$phones))
                : [(string)$phones];
        }

        $message = $postVars['message'] ?? null;

        // Se nao vier optionGroup1/2, assume envio curto com codificacao normal.
        $optionGroup1 = $postVars['optionGroup1'] ?? null;
        $optionGroup2 = $postVars['optionGroup2'] ?? "0";

        $scheduleMode = strtolower(trim((string)($postVars['schedule_mode'] ?? 'now')));
        $scheduledAtInput = trim((string)($postVars['scheduled_at'] ?? ''));

        if ($scheduleMode === 'scheduled' || $scheduledAtInput !== '') {
            try {
                $scheduledAt = self::parseScheduledAt($scheduledAtInput);
                $scheduleId = CampaignSchedulerService::schedule(
                    $obUser,
                    'sms',
                    'SMS Single Shot',
                    $scheduledAt,
                    [
                        'phones' => array_values($phones),
                        'message' => $message,
                        'service' => $optionGroup1,
                        'coding' => $optionGroup2,
                        'created_via' => 'single_shot_send',
                    ],
                    [
                        'dispatch_mode' => 'persistent_queue',
                        'timezone' => trim((string)($postVars['timezone'] ?? '')) ?: ($obUser['timezone'] ?? getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo'),
                    ]
                );

                return new Response(200, [
                    'status' => 200,
                    'scheduled' => true,
                    'schedule_id' => $scheduleId,
                    'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                    'message' => 'SMS agendado com sucesso.',
                ], 'application/json');
            } catch (\Throwable $e) {
                return new Response(422, [
                    'status' => 422,
                    'message' => $e->getMessage(),
                ], 'application/json');
            }
        }

        return self::sendSms(
            $obUser,
            null,
            $phones,
            $message,
            $optionGroup1,
            $optionGroup2
        );
    }

    private static function parseScheduledAt(string $scheduledAtInput): \DateTimeImmutable
    {
        if ($scheduledAtInput === '') {
            throw new \RuntimeException('Informe a data e hora do agendamento.');
        }

        try {
            $scheduledAt = new \DateTimeImmutable($scheduledAtInput);
        } catch (\Throwable) {
            throw new \RuntimeException('Data de agendamento inválida.');
        }

        if ($scheduledAt <= new \DateTimeImmutable('now')) {
            throw new \RuntimeException('O agendamento precisa ser para uma data futura.');
        }

        return $scheduledAt;
    }


}
