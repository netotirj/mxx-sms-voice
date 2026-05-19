<?php

namespace App\Service;

use App\Model\Entity\CampaignVoice;
use App\Model\Entity\CampaignVoiceSchedule;
use App\RedisConn;

class VoiceCampaignDispatchService
{
    public static function dispatchSchedule(array $schedule, array $payload): array
    {
        $redis = RedisConn::get();
        $jobId = (string)($payload['job_id'] ?? '');
        $tenantId = (string)($payload['tenant_id'] ?? $schedule['tenancy_id'] ?? '');
        $userId = (int)($payload['user_id'] ?? $schedule['user_id'] ?? 0);
        $contacts = is_array($payload['contacts'] ?? null) ? array_values($payload['contacts']) : [];

        if ($jobId === '' || $tenantId === '' || $userId <= 0 || $contacts === []) {
            throw new \RuntimeException('Payload de voz agendada incompleto.');
        }

        $name = trim((string)($payload['campaign_name'] ?? $schedule['schedule_name'] ?? 'Campanha agendada'));
        $queueId = (string)($payload['queue_id'] ?? '');
        $campaignType = (string)($payload['campaign_type'] ?? 'voice');
        $status = 'y';

        $redis->hMSet("campaign:{$jobId}", [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'type' => 'voice',
            'created' => time(),
            'total' => count($contacts),
            'processed' => 0,
            'status' => 'pending',
            'campaign_name' => $name,
        ]);
        $redis->expire("campaign:{$jobId}", 86400);

        $campaign = new CampaignVoice();
        $campaign->user_id = $userId;
        $campaign->tenancy_id = $tenantId;
        $campaign->name = $name;
        $campaign->type = $campaignType;
        $campaign->job_id = $jobId;
        $campaign->queue_id = $queueId;
        $campaign->total_contacts = count($contacts);
        $campaign->status = $status;

        if (!$campaign->create()) {
            throw new \RuntimeException('Erro ao criar campanha de voz a partir do agendamento.');
        }

        $campaignId = (int)$campaign->id;
        $payloadBackupKey = "campaign:{$jobId}:payload_backup";
        $payloadBackupTtl = 86400 * 7;
        $redis->del($payloadBackupKey);

        foreach ($contacts as $dest) {
            $callId = 'call:' . bin2hex(random_bytes(12));
            $rowPayload = [
                'job_id' => $jobId,
                'call_id' => $callId,
                'voice_list_id' => $payload['voice_list_id'] ?? null,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'campaign_id' => $campaignId,
                'campaign_type' => $campaignType,
                'queue_id' => $queueId,
                'record_calls' => (int)($payload['record_calls'] ?? 0),
                'phone' => $dest,
                'extension' => $dest,
                'role' => $payload['role'] ?? null,
                'trunk' => $payload['sip_trunk'] ?? null,
                'trunk_id' => $payload['trunk_id'] ?? null,
                'trunk_name' => $payload['trunk_name'] ?? null,
                'tech_prefix' => $payload['tech_prefix'] ?? null,
                'strategy' => $payload['dial_strategy'] ?? 'rrmemory',
                'call_minute_cost' => $payload['call_minute_cost'] ?? 0,
                'torpedo_cost' => $payload['torpedo_cost'] ?? 0,
                'taxa_of_service' => $payload['taxa_of_service'] ?? 0,
                'sms_cost' => $payload['sms_cost'] ?? 0,
                'trunk_billing_type' => $payload['trunk_billing_type'] ?? null,
                'plan_id' => $payload['plan_id'] ?? null,
                'tariff_used' => $payload['tariff_used'] ?? null,
                'variable_type' => $payload['variable_type'] ?? 'voice',
                'rate' => $payload['rate'] ?? 1,
                'caller_id' => $payload['caller_id'] ?? null,
                'caller_id_name' => $payload['caller_id_name'] ?? null,
                'audio' => $payload['audio'] ?? ['main' => null, 'dtmf' => []],
                'sms' => $payload['sms'] ?? null,
                'action' => $payload['action'] ?? null,
                'endpoints' => is_array($payload['endpoints'] ?? null) ? $payload['endpoints'] : [],
                'timestamp' => time(),
            ];

            $rawPayload = json_encode($rowPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $redis->rPush('voice:queue', $rawPayload);
            $redis->rPush("queue:originate:{$jobId}", $dest);
            $redis->rPush($payloadBackupKey, $rawPayload);
        }

        $redis->expire($payloadBackupKey, $payloadBackupTtl);

        if (!empty($schedule['legacy_schedule_id'])) {
            CampaignVoiceSchedule::updateStatus((int)$schedule['legacy_schedule_id'], 'done', null, $campaignId);
        }

        return [
            'campaign_id' => $campaignId,
            'queued_count' => count($contacts),
            'job_id' => $jobId,
        ];
    }
}
