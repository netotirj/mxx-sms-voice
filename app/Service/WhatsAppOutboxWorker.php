<?php

namespace App\Service;

use App\Model\Entity\WhatsAppAccount;
use App\Model\Entity\WhatsAppCampaign;
use App\Model\Entity\WhatsAppConversation;
use App\Model\Entity\WhatsAppOutbox;
use App\Model\Entity\WhatsAppTemplate;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppOutboxWorker
{
    public function runOnce(int $limit = 50, ?string $cancelCategory = null): array
    {
        $lock = $this->acquireLock();
        if (!$lock['acquired']) {
            return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'requeued' => 0, 'locked' => true];
        }

        try {
            $requeued = WhatsAppOutbox::resetStaleSending();
            $rows = WhatsAppOutbox::nextDue($limit, $cancelCategory);
            $summary = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'requeued' => $requeued, 'locked' => false];

            foreach ($rows as $row) {
                $summary['processed']++;
                if ($this->processRow($row)) {
                    $summary['sent']++;
                } else {
                    $summary['failed']++;
                }
            }

            return $summary;
        } finally {
            $this->releaseLock($lock['connection']);
        }
    }

    public function runOutboxIds(array $ids): array
    {
        $lock = $this->acquireLock();
        if (!$lock['acquired']) {
            return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'requeued' => 0, 'locked' => true];
        }

        try {
            $rows = WhatsAppOutbox::dueByIds($ids);
            $summary = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'requeued' => 0, 'locked' => false];

            foreach ($rows as $row) {
                $summary['processed']++;
                if ($this->processRow($row)) {
                    $summary['sent']++;
                } else {
                    $summary['failed']++;
                }
            }

            return $summary;
        } finally {
            $this->releaseLock($lock['connection']);
        }
    }

    private function processRow(array $row): bool
    {
        $id = (int)$row['id'];
        $account = WhatsAppAccount::getById((int)$row['account_id']);
        if (!$account) {
            WhatsAppOutbox::markFailed($id, 'Conta WhatsApp nao encontrada.', (int)$row['attempts'] + 1, (int)$row['max_attempts']);
            return false;
        }

        if ((string)($row['tenancy_id'] ?? '') !== (string)($account['tenancy_id'] ?? '')) {
            WhatsAppOutbox::markFailed($id, 'Envio bloqueado: conta WhatsApp pertence a outro tenant.', (int)$row['attempts'] + 1, (int)$row['max_attempts']);
            return false;
        }

        if (!WhatsAppOutbox::markSending($id)) {
            return false;
        }

        $safety = WhatsAppNumberSafety::checkBeforeSend($row, $account);
        if (!$safety['allowed']) {
            WhatsAppOutbox::postpone($id, (int)$safety['delay_seconds'], (string)$safety['reason']);
            return false;
        }

        try {
            $billing = WhatsAppBilling::authorizeOutbox($row);
            $api = new MetaWhatsAppCloudApi();
            if ($row['message_type'] === 'template') {
                $template = WhatsAppTemplate::getByNameForTenant(
                    (string)$row['template_name'],
                    (string)($row['template_language'] ?: 'pt_BR'),
                    (string)$row['tenancy_id']
                );
                if (!$template) {
                    WhatsAppOutbox::markFailed($id, 'Template não encontrado.', (int)$row['max_attempts'], (int)$row['max_attempts']);
                    $this->markCampaignRecipient($row, 'failed', null, 'Template não encontrado.');
                    WhatsAppBilling::recordBlocked($row, 'Template não encontrado.');
                    return false;
                }

                if (!$this->templateMatchesAccount($template, $account)) {
                    WhatsAppOutbox::markFailed($id, 'Template pertence a outra WABA.', (int)$row['max_attempts'], (int)$row['max_attempts']);
                    $this->markCampaignRecipient($row, 'failed', null, 'Template pertence a outra WABA.');
                    WhatsAppBilling::recordBlocked($row, 'Template pertence a outra WABA.');
                    return false;
                }

                if ((string)$template['status'] !== 'approved') {
                    WhatsAppOutbox::markFailed($id, 'Template ainda não aprovado pela Meta.', (int)$row['max_attempts'], (int)$row['max_attempts']);
                    $this->markCampaignRecipient($row, 'failed', null, 'Template ainda não aprovado pela Meta.');
                    WhatsAppBilling::recordBlocked($row, 'Template ainda não aprovado pela Meta.');
                    return false;
                }

                $components = json_decode((string)($row['template_components'] ?? '[]'), true);
                $this->logTemplateSendAttempt($row, is_array($components) ? $components : []);
                $result = $api->sendTemplate(
                    (string)$account['access_token'],
                    (string)$account['phone_number_id'],
                    (string)$row['contact_phone'],
                    (string)$row['template_name'],
                    (string)($row['template_language'] ?: 'pt_BR'),
                    is_array($components) ? $components : []
                );
                $this->logMetaSendResult($row, $result);
            } else {
                $result = $api->sendText(
                    (string)$account['access_token'],
                    (string)$account['phone_number_id'],
                    (string)$row['contact_phone'],
                    (string)$row['body']
                );
                $this->logMetaSendResult($row, $result);
            }

            $wamid = $result['data']['messages'][0]['id'] ?? null;
            if (!$result['ok']) {
                $error = $result['error'] ?? 'Falha no envio pela Meta.';
                $attempts = (int)$row['attempts'] + 1;
                $maxAttempts = (int)$row['max_attempts'];
                $rateLimit = WhatsAppMetaRateLimitGuard::handleMetaResult($result, $account);
                if ($rateLimit) {
                    WhatsAppOutbox::postpone($id, (int)$rateLimit['delay_seconds'], $rateLimit['reason']);
                } else {
                    WhatsAppOutbox::markFailed($id, $error, $attempts, $maxAttempts);
                }
                WhatsAppBilling::recordFailed($row, $error);
                if (!$rateLimit && $attempts >= $maxAttempts) {
                    $this->markCampaignRecipient($row, 'failed', null, $error);
                }
                return false;
            }

            $resolvedPhone = $this->metaRecipientPhone($result, (string)($row['contact_phone'] ?? ''));
            $conversationId = !empty($row['conversation_id'])
                ? WhatsAppConversation::reconcileContactPhone(
                    (int)$row['conversation_id'],
                    (int)$row['account_id'],
                    $resolvedPhone,
                    $row['contact_name'] ?? null
                )
                : WhatsAppConversation::findOrCreate([
                    'tenancy_id' => $row['tenancy_id'],
                    'user_id' => (int)$row['user_id'],
                    'account_id' => (int)$row['account_id'],
                    'contact_phone' => $resolvedPhone,
                    'contact_name' => $row['contact_name'] ?? null,
                    'last_message' => (string)($row['body'] ?? ''),
                    'last_direction' => 'outbound',
                    'unread_count' => 0,
                ]);

            $pricingSnapshot = $this->jsonColumnToArray($row['pricing_snapshot'] ?? null) ?: [];
            $protocolTracking = is_array($pricingSnapshot['pricing_payload']['protocol_tracking'] ?? null)
                ? $pricingSnapshot['pricing_payload']['protocol_tracking']
                : null;

            $payload = array_merge($result['data'] ?? [], [
                'billing' => [
                    'message_category' => strtolower((string)$billing['message_category']),
                    'billed' => false,
                    'outbox_id' => $id,
                    'pricing_snapshot' => $billing['pricing_snapshot'] ?? null,
                ],
                'audit' => [
                    'preview_body' => (string)($row['preview_body'] ?? $row['body'] ?? ''),
                    'template_variables' => $this->jsonColumnToArray($row['template_variables'] ?? null),
                    'pricing_snapshot' => $billing['pricing_snapshot'] ?? null,
                ],
            ]);
            if ($protocolTracking) {
                $payload['protocol_tracking'] = $protocolTracking;
            }

            $messageId = WhatsAppConversation::addMessage([
                'conversation_id' => $conversationId,
                'account_id' => (int)$row['account_id'],
                'wamid' => $wamid,
                'direction' => 'outbound',
                'message_type' => (string)$row['message_type'],
                'template_name' => $row['template_name'] ?? null,
                'template_category' => $row['template_category'] ?? null,
                'service_window_open' => (int)($row['service_window_open'] ?? 0),
                'message_category' => $billing['message_category'],
                'price_brl' => (float)$billing['price_brl'],
                'body' => (string)($row['body'] ?? ''),
                'preview_body' => (string)($row['preview_body'] ?? $row['body'] ?? ''),
                'template_variables' => $this->jsonColumnToArray($row['template_variables'] ?? null),
                'pricing_snapshot' => $billing['pricing_snapshot'] ?? null,
                'status' => 'sent',
                'payload' => $payload,
            ]);

            $billed = WhatsAppBilling::billSent($row, $messageId, $wamid, $billing);
            WhatsAppOutbox::markSent($id, $wamid, $messageId);
            if ($protocolTracking && !empty($protocolTracking['reference'])) {
                WhatsAppConversation::markConversationProtocolSent($conversationId, (string)$protocolTracking['reference']);
            }
            if ($billed) {
                WhatsAppOutbox::markBilled($id);
                WhatsAppConversation::markMessageBilled($messageId);
                WhatsAppConversation::updateMessageStatusByWamid((string)$wamid, 'sent', null, [
                    'billing' => array_merge($payload['billing'], ['billed' => true]),
                ]);
            }
            WhatsAppNumberSafety::recordSent((int)$row['account_id']);
            $this->markCampaignRecipient($row, 'sent', $wamid, null);
            return true;
        } catch (\Throwable $e) {
            $attempts = (int)$row['attempts'] + 1;
            $maxAttempts = (int)$row['max_attempts'];
            if ($e->getMessage() === WhatsAppBilling::ERROR_INSUFFICIENT_BALANCE) {
                $attempts = $maxAttempts;
            }
            WhatsAppOutbox::markFailed($id, $e->getMessage(), $attempts, $maxAttempts);
            if ($e->getMessage() === WhatsAppBilling::ERROR_INSUFFICIENT_BALANCE) {
                WhatsAppBilling::recordBlocked($row, $e->getMessage());
            }
            if ($attempts >= $maxAttempts) {
                $this->markCampaignRecipient($row, 'failed', null, $e->getMessage());
            }
            return false;
        }
    }

    private function acquireLock(): array
    {
        $connection = new Database();
        $acquired = (int)$connection
            ->execute("SELECT GET_LOCK('maxx_whatsapp_outbox_worker', 0) AS acquired")
            ->fetchColumn() === 1;

        return [
            'acquired' => $acquired,
            'connection' => $connection,
        ];
    }

    private function releaseLock(Database $connection): void
    {
        $connection->execute("SELECT RELEASE_LOCK('maxx_whatsapp_outbox_worker')");
    }

    private function markCampaignRecipient(array $row, string $status, ?string $wamid, ?string $error): void
    {
        if (empty($row['campaign_recipient_id'])) {
            return;
        }

        WhatsAppCampaign::updateRecipientResult((int)$row['campaign_recipient_id'], $status, $wamid, $error);
        if (!empty($row['campaign_id'])) {
            WhatsAppCampaign::updateCounters((int)$row['campaign_id']);
        }
    }

    private function logTemplateSendAttempt(array $row, array $components): void
    {
        error_log(json_encode([
            'event' => 'whatsapp_template_send_attempt',
            'outbox_id' => (int)$row['id'],
            'tenancy_id' => (string)($row['tenancy_id'] ?? ''),
            'account_id' => (int)($row['account_id'] ?? 0),
            'template_name' => (string)($row['template_name'] ?? ''),
            'template_language' => (string)($row['template_language'] ?? 'pt_BR'),
            'contact' => [
                'name' => $row['contact_name'] ?? null,
                'phone' => $this->maskPhoneForLog((string)($row['contact_phone'] ?? '')),
            ],
            'parameters' => $this->templateParametersForLog($components),
            'payload_template_components' => $components,
            'preview_body' => $row['preview_body'] ?? $row['body'] ?? null,
            'template_variables' => $this->jsonColumnToArray($row['template_variables'] ?? null),
            'pricing_snapshot' => $this->jsonColumnToArray($row['pricing_snapshot'] ?? null),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function logMetaSendResult(array $row, array $result): void
    {
        error_log(json_encode([
            'event' => 'whatsapp_meta_send_result',
            'outbox_id' => (int)$row['id'],
            'conversation_id' => $row['conversation_id'] ?? null,
            'client_id' => $row['user_id'] ?? null,
            'contact_phone' => $this->maskPhoneForLog((string)($row['contact_phone'] ?? '')),
            'template_name' => $row['template_name'] ?? null,
            'attempted_at' => date('Y-m-d H:i:s'),
            'status' => $result['status'] ?? null,
            'ok' => $result['ok'] ?? false,
            'message_id' => $result['data']['messages'][0]['id'] ?? null,
            'error' => $result['error'] ?? null,
            'meta_response' => $result['data'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function templateParametersForLog(array $components): array
    {
        $parameters = [];
        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }
            foreach (($component['parameters'] ?? []) as $parameter) {
                if (!is_array($parameter)) {
                    continue;
                }
                $parameters[] = [
                    'component' => $component['type'] ?? null,
                    'type' => $parameter['type'] ?? null,
                    'text' => $parameter['text'] ?? null,
                    'payload' => $parameter['payload'] ?? null,
                ];
            }
        }

        return $parameters;
    }

    private function jsonColumnToArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function templateMatchesAccount(array $template, array $account): bool
    {
        if ((string)($template['tenancy_id'] ?? '') !== (string)($account['tenancy_id'] ?? '')) {
            return false;
        }

        $templateWaba = trim((string)($template['waba_id'] ?? ''));
        $accountWaba = trim((string)($account['waba_id'] ?? ''));
        if ($templateWaba !== '' && $accountWaba !== '' && $templateWaba !== $accountWaba) {
            return false;
        }

        $templateAccountId = (int)($template['account_id'] ?? 0);
        return $templateAccountId <= 0 || $templateAccountId === (int)($account['id'] ?? 0);
    }

    private function maskPhoneForLog(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone) ?: '';
        if (strlen($phone) <= 6) {
            return '***';
        }

        return substr($phone, 0, 4) . '***' . substr($phone, -2);
    }

    private function metaRecipientPhone(array $result, string $fallback): string
    {
        $candidates = [
            $result['data']['contacts'][0]['wa_id'] ?? null,
            $result['data']['recipient_id'] ?? null,
            $fallback,
        ];

        foreach ($candidates as $candidate) {
            $digits = preg_replace('/\D+/', '', (string)$candidate) ?: '';
            if ($digits !== '') {
                return $digits;
            }
        }

        return '';
    }
}
