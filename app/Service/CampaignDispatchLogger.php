<?php

namespace App\Service;

use WilliamCosta\DatabaseManager\Database;

class CampaignDispatchLogger
{
    public static function log(
        string $channel,
        string $eventName,
        string $message,
        array $context = [],
        ?int $scheduleId = null,
        ?int $runId = null,
        ?string $tenancyId = null,
        ?int $userId = null,
        string $level = 'info'
    ): void {
        CampaignDispatchSchema::ensureSchema();

        $payload = [
            'schedule_id' => $scheduleId,
            'run_id' => $runId,
            'tenancy_id' => trim((string)$tenancyId) !== '' ? (string)$tenancyId : (string)($context['tenancy_id'] ?? ''),
            'user_id' => $userId ?: (isset($context['user_id']) ? (int)$context['user_id'] : null),
            'channel' => $channel,
            'level' => $level,
            'event_name' => $eventName,
            'message' => mb_substr($message, 0, 255),
            'context_json' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        (new Database('campaign_dispatch_logs'))->insert($payload);
    }
}
