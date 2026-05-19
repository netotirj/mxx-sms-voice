<?php

namespace App\Service;

use App\Model\Entity\WhatsAppAccount;
use App\Model\Entity\WhatsAppCampaign;
use App\Model\Entity\WhatsAppConversation;
use App\Model\Entity\WhatsAppOutbox;
use App\Model\Entity\WhatsAppTemplate;

class WhatsAppCampaignDispatchService
{
    public static function enqueueCampaign(array $user, int $campaignId): array
    {
        $campaign = WhatsAppCampaign::getForUser($campaignId, $user);
        if (!$campaign) {
            throw new \RuntimeException('Campanha WhatsApp não encontrada.');
        }

        $campaignStatus = strtolower((string)($campaign['status'] ?? 'draft'));
        if (in_array($campaignStatus, ['queued', 'sending', 'finished', 'sent', 'cancelled'], true)) {
            throw new \RuntimeException('Esta campanha já foi enviada ou está em processamento.');
        }

        $account = WhatsAppAccount::getForUser((int)$campaign['account_id'], $user);
        if (!$account) {
            throw new \RuntimeException('Número WhatsApp da campanha não encontrado.');
        }

        WhatsAppNumberSafety::assertVerifiedForUse($account);

        $recipients = WhatsAppCampaign::getPendingRecipients((int)$campaign['id']);
        if ($recipients === []) {
            return ['queued' => 0, 'failed' => 0, 'errors' => []];
        }

        $templateCategory = null;
        $template = null;

        if ($campaign['message_type'] === 'template') {
            $template = WhatsAppTemplate::getByNameForUser(
                (string)$campaign['template_name'],
                (string)($campaign['template_language'] ?: 'pt_BR'),
                $user
            );
            if (!$template) {
                throw new \RuntimeException('Template não encontrado.');
            }
            if ((string)$template['status'] !== 'approved') {
                throw new \RuntimeException('Template ainda não aprovado pela Meta.');
            }
            $templateCategory = WhatsAppCostPolicy::normalizeCategory($template['category'] ?? 'MARKETING');
        }

        WhatsAppCampaign::markStatus((int)$campaign['id'], 'queued');

        $queued = 0;
        $failed = 0;
        $errors = [];

        foreach ($recipients as $recipient) {
            try {
                $recipientPhone = (string)$recipient['phone'];
                $lastInboundAt = WhatsAppConversation::getLastInboundAt((int)$account['id'], $recipientPhone);
                $serviceWindowOpen = WhatsAppCostPolicy::isServiceWindowOpen($lastInboundAt);

                if ($campaign['message_type'] === 'text' && !$serviceWindowOpen) {
                    $failed++;
                    $error = 'Este contato está fora da janela de 24 horas. Para iniciar uma nova conversa, utilize um template aprovado.';
                    WhatsAppCampaign::updateRecipientResult((int)$recipient['id'], 'failed', null, $error);
                    $errors[] = ['phone' => $recipientPhone, 'error' => $error];
                    continue;
                }

                if (
                    $campaign['message_type'] === 'template'
                    && $templateCategory === WhatsAppCostPolicy::CATEGORY_MARKETING
                    && WhatsAppConversation::hasMarketingOptOut((int)$account['id'], $recipientPhone)
                ) {
                    $failed++;
                    $error = 'Marketing bloqueado: destinatário solicitou descadastro.';
                    WhatsAppCampaign::updateRecipientResult((int)$recipient['id'], 'failed', null, $error);
                    $errors[] = ['phone' => $recipientPhone, 'error' => $error];
                    continue;
                }

                $variables = json_decode((string)($campaign['template_components'] ?? '[]'), true);
                if (!is_array($variables)) {
                    $variables = [];
                }
                $recipientVariables = json_decode((string)($recipient['template_variables'] ?? '[]'), true);
                if (is_array($recipientVariables) && $recipientVariables !== []) {
                    $variables = array_replace_recursive($variables, $recipientVariables);
                }

                $templateComponents = [];
                $resolvedTemplate = ['components' => [], 'preview_body' => $campaign['message_body'] ?? null, 'resolved' => []];
                if ($campaign['message_type'] === 'template') {
                    $resolvedTemplate = WhatsAppTemplateVariableResolver::buildSendComponents($template, [
                        'tenancy_id' => (string)$user['tenancy_id'],
                        'tenant_name' => self::tenantName((string)$user['tenancy_id']),
                        'contact_name' => self::nullableString($recipient['name'] ?? null),
                        'contact_name_fallback' => 'Cliente',
                        'contact_phone' => $recipientPhone,
                    ], $variables);
                    $templateComponents = $resolvedTemplate['components'];
                }

                $plannedMessages = $campaign['message_type'] === 'template'
                    ? WhatsAppMessagePlanner::planTemplate(
                        (string)$campaign['template_name'],
                        (string)($campaign['template_language'] ?: 'pt_BR'),
                        $templateCategory,
                        $templateComponents,
                        $resolvedTemplate['preview_body'] ?? ($template['body'] ?? null)
                    )
                    : WhatsAppMessagePlanner::planText((string)$campaign['message_body'], $serviceWindowOpen);

                if ($campaign['message_type'] === 'template' && isset($plannedMessages[0])) {
                    $plannedMessages[0]['preview_body'] = $resolvedTemplate['preview_body'] ?? $plannedMessages[0]['body'];
                    $plannedMessages[0]['template_variables'] = $resolvedTemplate['resolved'] ?? [];
                }

                foreach ($plannedMessages as $planned) {
                    $billable = self::withBilling(
                        [
                            'tenancy_id' => (string)$user['tenancy_id'],
                            'user_id' => (int)$user['id'],
                            'account_id' => (int)$account['id'],
                            'campaign_id' => (int)$campaign['id'],
                            'campaign_recipient_id' => (int)$recipient['id'],
                            'contact_phone' => $recipientPhone,
                            'contact_name' => $recipient['name'] ?? null,
                        ],
                        $planned,
                        $serviceWindowOpen
                    );

                    WhatsAppBilling::assertCanSend(
                        (int)$user['id'],
                        (string)$user['tenancy_id'],
                        (string)$billable['message_category'],
                        (float)$billable['price_brl']
                    );

                    WhatsAppOutbox::enqueue($billable);
                    WhatsAppCampaign::updateRecipientResult((int)$recipient['id'], 'queued', null, null);
                    $queued++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['phone' => $recipient['phone'], 'error' => $e->getMessage()];
                WhatsAppCampaign::updateRecipientResult((int)$recipient['id'], 'failed', null, $e->getMessage());
            }
        }

        WhatsAppCampaign::updateCounters((int)$campaign['id']);

        return [
            'queued' => $queued,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    private static function withBilling(array $base, array $planned, bool $serviceWindowOpen): array
    {
        $category = $planned['template_category'] ?? null;
        $messageCategory = WhatsAppBilling::resolveCategory(
            (string)$planned['message_type'],
            $category,
            $serviceWindowOpen
        );

        $freeByMetaPolicy = WhatsAppCostPolicy::isFreeByMetaPolicy(
            (string)$planned['message_type'],
            $category,
            $serviceWindowOpen
        );

        if ($freeByMetaPolicy) {
            $priceBrl = 0.0;
            $pricingSnapshot = WhatsAppBilling::freeMetaPolicySnapshot(
                $messageCategory,
                (string)($base['contact_phone'] ?? ''),
                $messageCategory === WhatsAppCostPolicy::CATEGORY_UTILITY
                    ? 'utility_template_customer_service_window'
                    : 'customer_service_window'
            );
        } else {
            $priceBrl = WhatsAppBilling::priceForUser(
                (int)$base['user_id'],
                (string)$base['tenancy_id'],
                $messageCategory,
                WhatsAppDynamicPricing::countryCodeFromPhone((string)($base['contact_phone'] ?? ''))
            );
            $pricingSnapshot = WhatsAppBilling::pricingSnapshot(
                (int)$base['user_id'],
                (string)$base['tenancy_id'],
                $messageCategory,
                (string)($base['contact_phone'] ?? ''),
                $priceBrl
            );
        }

        return array_merge($base, [
            'sequence' => $planned['sequence'],
            'message_type' => $planned['message_type'],
            'body' => $planned['body'],
            'preview_body' => $planned['preview_body'] ?? $planned['body'],
            'template_name' => $planned['template_name'],
            'template_language' => $planned['template_language'] ?? 'pt_BR',
            'template_category' => $category,
            'template_components' => $planned['template_components'] ?? [],
            'template_variables' => $planned['template_variables'] ?? [],
            'service_window_open' => $serviceWindowOpen ? 1 : 0,
            'message_category' => $messageCategory,
            'price_brl' => $priceBrl,
            'pricing_snapshot' => $pricingSnapshot,
            'billed' => 0,
        ]);
    }

    private static function tenantName(string $tenancyId): string
    {
        try {
            $row = (new \WilliamCosta\DatabaseManager\Database('tenancies'))
                ->select('id = :id', [':id' => $tenancyId], '', '1', ['name'])
                ->fetch(\PDO::FETCH_ASSOC);
            return (string)($row['name'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }
}
