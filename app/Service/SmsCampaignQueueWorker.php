<?php

namespace App\Service;

use App\Controller\Pages\DisproClient;
use App\Model\Entity\CallbackSms;

class SmsCampaignQueueWorker
{
    public function runOnce(int $limit = 200, string $workerName = 'campaign-sms-worker'): array
    {
        CampaignDispatchSchema::ensureSchema();

        $items = CampaignDispatchRepository::leaseSmsQueueRows($workerName, $limit);
        $summary = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'retry_waiting' => 0,
            'schedules_completed' => 0,
        ];

        if ($items === []) {
            return $summary;
        }

        $client = new DisproClient();
        $scheduleTouched = [];

        foreach ($items as $item) {
            $summary['processed']++;
            $scheduleTouched[(int)$item['schedule_id']] = true;

            $messages = [[
                'numero' => (string)$item['contact_phone'],
                'servico' => (string)$item['service_type'],
                'mensagem' => (string)$item['message_body'],
                'parceiro_id' => (string)$item['partner_id'],
                'codificacao' => (string)$item['charset_msg'],
                'nome_campanha' => (string)($item['contact_name'] ?? ''),
            ]];

            $responseArray = $client->send($messages);
            if (!$responseArray || empty($responseArray['detail'])) {
                $attempts = (int)$item['attempts'] + 1;
                CampaignDispatchRepository::markSmsQueueFailed(
                    (int)$item['id'],
                    $client->getLastError() ?: 'Falha sem detalhe ao enviar SMS.',
                    $attempts,
                    (int)$item['max_attempts']
                );
                $summary[$attempts >= (int)$item['max_attempts'] ? 'failed' : 'retry_waiting']++;
                continue;
            }

            $details = is_array($responseArray['detail']) ? $responseArray['detail'] : [$responseArray['detail']];
            $detail = $details[0] ?? [];

            $callback = new CallbackSms();
            $callback->phone_sms = (string)($detail['numero'] ?? $item['contact_phone']);
            $callback->status_sms = (string)($detail['status'] ?? 'FAILED');
            $callback->camp_name = (string)($item['contact_name'] ?? '');
            $callback->id_partner = (string)$item['partner_id'];
            $callback->sms_provider = CallbackSms::defaultSmsProvider();
            $callback->user_id = (int)$item['user_id'];
            $callback->tenancy_id = (string)$item['tenancy_id'];
            $callback->campaign_id = $item['native_campaign_id'] !== null ? (int)$item['native_campaign_id'] : null;
            $callback->batch_id = $item['batch_id'] !== null ? (int)$item['batch_id'] : null;
            $callback->date_send = date('Y-m-d H:i:s');
            $callback->operator = 'UNKNOWN';
            $callback->value_sms = strtoupper((string)($detail['status'] ?? '')) === 'ACCEPTED'
                ? (string)round((int)$item['sms_units'] * 1.0, 4)
                : '0.00';
            $callback->insertStatus();

            $callbackId = (int)((new \WilliamCosta\DatabaseManager\Database())->run('SELECT LAST_INSERT_ID()')->fetchColumn() ?: 0);
            CampaignDispatchRepository::markSmsQueueSent((int)$item['id'], $callbackId, $detail);
            $summary['sent']++;
        }

        foreach (array_keys($scheduleTouched) as $scheduleId) {
            $stats = CampaignDispatchRepository::summarizeSmsSchedule((int)$scheduleId);
            if ($stats['pending_count'] === 0) {
                $status = $stats['failed_count'] > 0 && $stats['sent_count'] === 0 ? 'failed' : 'completed';
                CampaignDispatchRepository::releaseSchedule((int)$scheduleId, $status, $status === 'failed' ? 'Fila SMS finalizada sem envios aceitos.' : null);
                $summary['schedules_completed']++;
            } else {
                CampaignDispatchRepository::markScheduleQueued((int)$scheduleId);
            }
        }

        return $summary;
    }
}
