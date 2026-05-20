<?php

namespace App\Service;

use App\Model\Entity\CampaignBatch;

class SmsCampaignQueueService
{
    public static function enqueueSchedule(array $schedule, array $user): array
    {
        $payload = self::payload($schedule);
        $campaignId = isset($payload['campaign_id']) ? (int)$payload['campaign_id'] : null;
        $phones = $payload['phones'] ?? [];
        $message = $payload['message'] ?? null;
        $service = $payload['service'] ?? null;
        $coding = $payload['coding'] ?? '0';

        $prepared = SmsCampaignPreparationService::prepare(
            $user,
            $campaignId,
            $phones,
            $message,
            $service,
            $coding
        );

        $batchId = CampaignBatch::create([
            'campaign_id' => $campaignId,
            'user_id' => (int)$user['id'],
            'tenancy_id' => (string)$user['tenancy_id'],
        ]);

        $rows = [];
        foreach ($prepared['contacts'] as $index => $contact) {
            $partnerId = substr(sprintf('u%s-b%s-%s', $user['id'], $batchId, $index + 1), 0, 100);
            $dedupeKey = hash('sha256', implode('|', [
                (string)$schedule['id'],
                (string)$contact['phone'],
                (string)$contact['type_msg'],
                (string)$contact['charset_msg'],
                sha1((string)$contact['message']),
            ]));

            $rows[] = [
                'schedule_id' => (int)$schedule['id'],
                'native_campaign_id' => $campaignId,
                'batch_id' => $batchId,
                'tenancy_id' => (string)$user['tenancy_id'],
                'user_id' => (int)$user['id'],
                'contact_phone' => (string)$contact['phone'],
                'contact_name' => (string)($contact['name'] ?? ''),
                'service_type' => (string)$contact['type_msg'],
                'charset_msg' => (string)$contact['charset_msg'],
                'message_body' => (string)$contact['message'],
                'sms_units' => (int)$contact['sms_units'],
                'unit_rate' => round((float)$prepared['unit_rate'], 4),
                'partner_id' => $partnerId,
                'dedupe_key' => $dedupeKey,
                'status' => 'pending',
                'available_at' => date('Y-m-d H:i:s'),
                'attempts' => 0,
                'max_attempts' => 5,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
        }

        $queued = CampaignDispatchRepository::enqueueSmsRows($rows);

        CampaignDispatchLogger::log(
            'sms',
            'sms_queue_seeded',
            'Campanha SMS expandida para fila persistente.',
            [
                'schedule_id' => (int)$schedule['id'],
                'campaign_id' => $campaignId,
                'batch_id' => $batchId,
                'queued_count' => $queued,
                'total_units' => $prepared['total_units'],
                'value_total' => $prepared['value_total'],
            ],
            (int)$schedule['id'],
            null,
            (string)$user['tenancy_id'],
            (int)$user['id']
        );

        return [
            'queued_count' => $queued,
            'batch_id' => $batchId,
            'total_units' => (int)$prepared['total_units'],
            'campaign_id' => $campaignId,
        ];
    }

    public static function payload(array $schedule): array
    {
        $decoded = json_decode((string)($schedule['payload_json'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }
}
