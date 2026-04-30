<?php

namespace App\Service;

use App\Model\Entity\WhatsAppAccount;
use App\Model\Entity\WhatsAppCampaign;
use App\Model\Entity\WhatsAppConversation;
use App\Model\Entity\WhatsAppOutbox;

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

        if (!WhatsAppOutbox::markSending($id)) {
            return false;
        }

        $safety = WhatsAppNumberSafety::checkBeforeSend($row, $account);
        if (!$safety['allowed']) {
            WhatsAppOutbox::postpone($id, (int)$safety['delay_seconds'], (string)$safety['reason']);
            return false;
        }

        try {
            $api = new MetaWhatsAppCloudApi();
            if ($row['message_type'] === 'template') {
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
                'pricing_estimate' => [
                    'country' => 'BR',
                    'currency' => 'USD',
                    'message_type' => $row['message_type'],
                    'template_category' => $row['template_category'],
                    'service_window_open' => (bool)$row['service_window_open'],
                    'billable_estimate' => (bool)$row['billable_estimate'],
                    'estimated_cost_usd' => (float)$row['estimated_cost_usd'],
                    'outbox_id' => $id,
                ],
            ]);

            $messageId = WhatsAppConversation::addMessage([
                'conversation_id' => $conversationId,
                'account_id' => (int)$row['account_id'],
                'wamid' => $wamid,
                'direction' => 'outbound',
                'message_type' => (string)$row['message_type'],
                'body' => (string)($row['body'] ?? ''),
                'status' => 'sent',
                'payload' => $payload,
            ]);

            WhatsAppOutbox::markSent($id, $wamid, $messageId);
            WhatsAppNumberSafety::recordSent((int)$row['account_id']);
            $this->markCampaignRecipient($row, 'sent', $wamid, null);
            return true;
        } catch (\Throwable $e) {
            $attempts = (int)$row['attempts'] + 1;
            $maxAttempts = (int)$row['max_attempts'];
            WhatsAppOutbox::markFailed($id, $e->getMessage(), $attempts, $maxAttempts);
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
