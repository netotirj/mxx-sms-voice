<?php

namespace App\Model\Entity;

use WilliamCosta\DatabaseManager\Database;

class CampaignVoiceSchedule
{
    public static function create(array $data): int
    {
        return (int)(new Database('campaign_voice_schedules'))->insert([
            'user_id' => (int)$data['user_id'],
            'tenancy_id' => (string)$data['tenancy_id'],
            'name' => (string)$data['name'],
            'type' => (string)$data['type'],
            'total_contacts' => (int)$data['total_contacts'],
            'job_id' => (string)$data['job_id'],
            'queue_id' => $data['queue_id'] !== '' ? $data['queue_id'] : null,
            'status' => $data['status'] ?? 'pending',
            'total_calls' => (int)($data['total_calls'] ?? 0),
            'answered_calls' => (int)($data['answered_calls'] ?? 0),
            'failed_calls' => (int)($data['failed_calls'] ?? 0),
            'scheduled_at' => (string)$data['scheduled_at'],
            'campaign_id' => $data['campaign_id'] ?? null,
            'payload_json' => $data['payload_json'] ?? null,
            'note' => $data['note'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function getDuePending(int $limit = 20): array
    {
        return (new Database('campaign_voice_schedules'))
            ->select(
                'status = :status AND scheduled_at <= NOW()',
                [':status' => 'pending'],
                'scheduled_at ASC, id ASC',
                (string)$limit
            )
            ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function getPendingForList(?int $userId = null, ?string $tenancyId = null, ?int $limit = null): array
    {
        $where = 'status = :status';
        $params = [':status' => 'pending'];

        if ($tenancyId !== null && $tenancyId !== '') {
            $where .= ' AND tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $tenancyId;
        }

        if ($userId !== null) {
            $where .= ' AND user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        return (new Database('campaign_voice_schedules'))
            ->select(
                $where,
                $params,
                'scheduled_at DESC, id DESC',
                $limit ? (string)$limit : null
            )
            ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function countPending(
        ?string $tenancyId = null,
        ?int $userId = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): int
    {
        $where = 'status = :status';
        $params = [':status' => 'pending'];

        if ($tenancyId !== null && $tenancyId !== '') {
            $where .= ' AND tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $tenancyId;
        }

        if ($userId !== null) {
            $where .= ' AND user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        if ($startDate !== null && $endDate !== null) {
            $where .= ' AND scheduled_at BETWEEN :start_date AND :end_date';
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
        }

        $row = (new Database('campaign_voice_schedules'))
            ->select($where, $params, null, '1', 'COUNT(*) AS total')
            ->fetch(\PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    public static function addPendingToVoiceCounts(
        array $voiceCounts,
        ?string $tenancyId = null,
        ?int $userId = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): array
    {
        $voiceCounts['s'] = (int)($voiceCounts['s'] ?? 0) + self::countPending($tenancyId, $userId, $startDate, $endDate);
        return $voiceCounts;
    }

    public static function updateStatus(int $id, string $status, ?string $errorMessage = null, ?int $campaignId = null): bool
    {
        $data = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (in_array($status, ['done', 'failed', 'cancelled'], true)) {
            $data['processed_at'] = date('Y-m-d H:i:s');
        }

        if ($errorMessage !== null) {
            $data['error_message'] = $errorMessage;
        }

        if ($campaignId !== null) {
            $data['campaign_id'] = $campaignId;
        }

        return (new Database('campaign_voice_schedules'))->update(
            'id = :id',
            $data,
            [':id' => $id]
        );
    }
}
