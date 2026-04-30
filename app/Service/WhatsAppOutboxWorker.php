<?php

namespace App\Service;

use App\Model\Entity\WhatsAppAccount;
use App\Model\Entity\WhatsAppCampaign;
use App\Model\Entity\WhatsAppConversation;
use App\Model\Entity\WhatsAppOutbox;
use App\Model\Entity\WhatsAppTemplate;

class WhatsAppOutboxWorker
{
    public function runOnce(int $limit = 50, ?string $cancelCategory = null): array
    {
        $requeued = WhatsAppOutbox::resetStaleSending();
        $rows = WhatsAppOutbox::nextDue($limit, $cancelCategory);
        $summary = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'requeued' => $requeued];

        foreach ($rows as $row) {
            $summary['processed']++;
            if ($this->processRow($row)) {
                $summary['sent']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
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
                    WhatsAppOutbox::markFailed($id, 'Template não encontrado', (int)$row['max_attempts'], (int)$row['max_attempts']);
                    $this->markCampaignRecipient($row, 'failed', null, 'Template não encontrado');
                    WhatsAppBilling::recordBlocked($row, 'Template não encontrado');
                    return false;
                }

                if ((string)$template['status'] !== 'approved') {
                    WhatsAppOutbox::markFailed($id, 'Template não aprovado pela Meta', (int)$row['max_attempts'], (int)$row['max_attempts']);
                    $this->markCampaignRecipient($row, 'failed', null, 'Template não aprovado pela Meta');
                    WhatsAppBilling::recordBlocked($row, 'Template não aprovado pela Meta');
                    return false;
                }

                $components = json_decode((string)($row['template_components'] ?? '[]'), true);
                $result = $api->sendTemplate(
                    (string)$account['access_token'],
                    (string)$account['phone_number_id'],
                    (string)$row['contact_phone'],
                    (string)$row['template_name'],
                    (string)($row['template_language'] ?: 'pt_BR'),
                    is_array($components) ? $components : []
                );
            } else {
                $result = $api->sendText(
                    (string)$account['access_token'],
                    (string)$account['phone_number_id'],
                    (string)$row['contact_phone'],
                    (string)$row['body']
                );
            }

            $wamid = $result['data']['messages'][0]['id'] ?? null;
            if (!$result['ok']) {
                $error = $result['error'] ?? 'Falha no envio pela Meta.';
                $attempts = (int)$row['attempts'] + 1;
                $maxAttempts = (int)$row['max_attempts'];
                WhatsAppOutbox::markFailed($id, $error, $attempts, $maxAttempts);
                WhatsAppBilling::recordFailed($row, $error);
                if ($attempts >= $maxAttempts) {
                    $this->markCampaignRecipient($row, 'failed', null, $error);
                }
                return false;
            }

            $conversationId = (int)($row['conversation_id'] ?: WhatsAppConversation::findOrCreate([
                'tenancy_id' => $row['tenancy_id'],
                'user_id' => (int)$row['user_id'],
                'account_id' => (int)$row['account_id'],
                'contact_phone' => (string)$row['contact_phone'],
                'contact_name' => $row['contact_name'] ?? null,
                'last_message' => (string)($row['body'] ?? ''),
                'last_direction' => 'outbound',
                'unread_count' => 0,
            ]));

            $payload = array_merge($result['data'] ?? [], [
                'billing' => [
                    'message_category' => strtolower((string)$billing['message_category']),
                    'billed' => false,
                    'outbox_id' => $id,
                ],
            ]);

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
                'status' => 'sent',
                'payload' => $payload,
            ]);

            $billed = WhatsAppBilling::billSent($row, $messageId, $wamid, $billing);
            WhatsAppOutbox::markSent($id, $wamid, $messageId);
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
}
