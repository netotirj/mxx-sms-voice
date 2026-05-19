<?php

namespace App\Service;

use App\Controller\Pages\SendSms;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\CampaignSearch;
use App\Model\Entity\Rates;
use App\Model\Entity\UserPlans;
use App\Model\Entity\UserSearch;

class SmsCampaignPreparationService
{
    public static function prepare(array $user, ?int $campaignId = null, array|string $phones = [], ?string $message = null, ?string $service = null, ?string $coding = '0'): array
    {
        $isReseller = isset($user['function']) && $user['function'] === 'reseller';
        $currentPlan = self::activePlanId((string)$user['tenancy_id']);

        if ($currentPlan <= 0) {
            throw new \RuntimeException($isReseller
                ? 'Favor contactar administrador da conta. Plano inativo.'
                : 'Nenhum Plano habilitado ou plano inativo.');
        }

        $contacts = [];
        if ($campaignId) {
            $campaign = CampaignSearch::getCampaignByIdAndTenancy($campaignId, (string)$user['tenancy_id']);
            if (!$campaign) {
                throw new \RuntimeException('Campanha não encontrada.');
            }
            if (in_array((string)$campaign->status, ['f', 'n'], true)) {
                throw new \RuntimeException('Campanha finalizada ou inativa.');
            }

            $contacts = CampaignSearch::getContactsForSmsDispatch($campaignId, (string)$user['tenancy_id'], (int)$user['id']);
            if ($contacts === []) {
                throw new \RuntimeException('Nenhum contato encontrado para esta campanha.');
            }
        } else {
            if (!is_array($phones)) {
                $phones = str_contains((string)$phones, ',')
                    ? array_map('trim', explode(',', (string)$phones))
                    : [(string)$phones];
            }

            foreach ($phones as $phone) {
                $contacts[] = [
                    'phone' => $phone,
                    'type_msg' => $service,
                    'message' => $message,
                    'charset_msg' => $coding,
                    'name' => $user['email'] ?? '',
                ];
            }
        }

        $contacts = SendSms::prepareContacts($contacts);
        if ($contacts === []) {
            throw new \RuntimeException('Nenhum SMS válido para envio. Verifique telefones, mensagem, serviço e codificação.');
        }

        $totalMessages = count($contacts);
        $totalUnits = (int)array_sum(array_column($contacts, 'sms_units'));

        $limitAccess = PlanLimitEnforcementService::assertWithinLimitForUser($user, 'sms', $totalMessages, [
            'period' => 'current_month',
        ]);
        if (empty($limitAccess['allowed'])) {
            throw new \RuntimeException((string)($limitAccess['message'] ?? 'Limite de SMS do plano atingido.'));
        }

        if ($isReseller) {
            $resellerInfo = UserSearch::getResellers((string)$user['tenancy_id'], (int)$user['id']);
            $reseller = $resellerInfo[0] ?? null;
            if (!$reseller || (float)$reseller['reseller_balance'] <= 0) {
                throw new \RuntimeException('Saldo insuficiente.');
            }

            $rateData = Rates::getLatestActiveRate((string)$user['tenancy_id'], (int)$user['id']);
            if (!$rateData || !isset($rateData['rate'])) {
                throw new \RuntimeException('Nenhuma tarifa configurada.');
            }

            $unitRate = (float)$rateData['rate'];
            $availableBalance = (float)$reseller['reseller_balance'];
            $valueTotal = $totalUnits * $unitRate;

            if ($availableBalance < $valueTotal) {
                throw new \RuntimeException('Saldo insuficiente para enviar SMS.');
            }

            $balance = BalanceSms::getBalanceSms(null, (string)$user['tenancy_id'], $currentPlan);
            if (!$balance || (float)$balance->value_sms <= 0) {
                throw new \RuntimeException('Saldo ou tarifa da conta principal não configurados.');
            }
        } else {
            $balance = BalanceSms::getBalanceSms((int)$user['id'], (string)$user['tenancy_id'], $currentPlan);
            if (!$balance || (float)$balance->value_sms <= 0) {
                throw new \RuntimeException('Saldo ou tarifa SMS não configurados.');
            }

            $unitRate = (float)$balance->value_sms;
            $availableBalance = (float)$balance->balance;
            $valueTotal = $totalUnits * $unitRate;

            if ($availableBalance < (float)$balance->value_sms) {
                UserPlans::deactivatePlan($currentPlan, (string)$user['tenancy_id'], (int)$user['id']);
                throw new \RuntimeException('Saldo insuficiente. Plano foi desativado.');
            }

            if ($availableBalance < $valueTotal) {
                throw new \RuntimeException('Saldo insuficiente para enviar todos os SMS.');
            }
        }

        $preflightPlan = SendSms::buildSmsPreflightPlan(
            (int)$user['id'],
            (string)$user['tenancy_id'],
            $totalUnits,
            (float)$unitRate
        );
        FinancialHierarchyBillingService::assertSufficientBalance($preflightPlan);

        return [
            'campaign_id' => $campaignId,
            'contacts' => $contacts,
            'total_messages' => $totalMessages,
            'total_units' => $totalUnits,
            'unit_rate' => (float)$unitRate,
            'value_total' => round((float)$valueTotal, 4),
            'plan_id' => $currentPlan,
        ];
    }

    private static function activePlanId(string $tenancyId): int
    {
        $subscription = PlanRuntimeService::getActiveSubscription($tenancyId);
        return (int)($subscription['plan_id'] ?? 0);
    }
}
