<?php

namespace App\Service;

use App\Utils\TenancyHelper;
use WilliamCosta\DatabaseManager\Database;

class PlanLimitEnforcementService
{
    private const PERIOD_CURRENT_MONTH = 'current_month';

    public static function assertWithinLimitForUser(array $user, string $limitKey, int $increment = 1, array $options = []): array
    {
        if (TenancyHelper::isSuperAdmin($user)) {
            return [
                'allowed' => true,
                'bypass' => 'super_admin',
                'limit_key' => $limitKey,
                'usage' => 0,
                'projected_usage' => max(0, $increment),
            ];
        }

        $tenancyId = trim((string)($user['tenancy_id'] ?? ''));
        if ($tenancyId === '') {
            return [
                'allowed' => false,
                'message' => 'Tenant não identificado para validar o limite do plano.',
                'limit_key' => $limitKey,
            ];
        }

        $result = self::assertWithinLimit($tenancyId, $limitKey, $increment, $options);
        if (empty($result['allowed'])) {
            PerformanceTelemetry::log('plan_limit.denied', [
                'tenancy_id' => $tenancyId,
                'user_id' => (int)($user['id'] ?? 0),
                'limit_key' => $limitKey,
                'usage' => (int)($result['usage'] ?? 0),
                'projected_usage' => (int)($result['projected_usage'] ?? 0),
                'limit' => $result['limit'] ?? null,
            ]);
        }

        return $result;
    }

    public static function assertWithinLimit(string $tenancyId, string $limitKey, int $increment = 1, array $options = []): array
    {
        $tenancyId = trim($tenancyId);
        if ($tenancyId === '') {
            return [
                'allowed' => false,
                'message' => 'Tenant não identificado para validar o limite do plano.',
                'limit_key' => $limitKey,
            ];
        }

        $limits = PlanRuntimeService::getEffectiveLimits($tenancyId);
        if (!array_key_exists($limitKey, $limits)) {
            return [
                'allowed' => true,
                'limit_key' => $limitKey,
                'usage' => 0,
                'projected_usage' => max(0, $increment),
                'limit' => null,
                'enforced' => false,
            ];
        }

        $usage = self::getUsage($tenancyId, $limitKey, $options);
        $step = max(0, $increment);
        $comparisonUsage = $step > 0 ? max(0, $usage + $step - 1) : $usage;
        $projectedUsage = $usage + $step;
        $access = PlanRuntimeService::assertLimitAvailable($tenancyId, $limitKey, $comparisonUsage);

        return [
            'allowed' => !empty($access['allowed']),
            'message' => $access['message'] ?? null,
            'limit_key' => $limitKey,
            'limit' => $access['limit'] ?? ($limits[$limitKey] ?? null),
            'usage' => $usage,
            'increment' => $step,
            'projected_usage' => $projectedUsage,
            'enforced' => true,
            'period' => (string)($options['period'] ?? self::defaultPeriod($limitKey)),
        ];
    }

    public static function getLimit(string $tenancyId, string $limitKey): ?int
    {
        $limits = PlanRuntimeService::getEffectiveLimits($tenancyId);
        if (!array_key_exists($limitKey, $limits)) {
            return null;
        }

        return (int)$limits[$limitKey];
    }

    public static function getUsage(string $tenancyId, string $limitKey, array $options = []): int
    {
        return match ($limitKey) {
            'users' => self::countUsers($tenancyId),
            'sms' => self::countSmsUsage($tenancyId, (string)($options['period'] ?? self::PERIOD_CURRENT_MONTH)),
            'voice' => self::countVoiceUsage($tenancyId, (string)($options['period'] ?? self::PERIOD_CURRENT_MONTH)),
            'campaigns' => self::countActiveCampaigns($tenancyId),
            'templates' => self::countTemplates($tenancyId),
            default => 0,
        };
    }

    private static function countUsers(string $tenancyId): int
    {
        $row = (new Database('users'))
            ->select(
                'tenancy_id = :tenancy_id',
                [':tenancy_id' => $tenancyId],
                '',
                '1',
                'COUNT(*) AS total'
            )
            ->fetch(\PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    private static function countSmsUsage(string $tenancyId, string $period): int
    {
        [$start, $end] = self::periodBounds($period);

        $row = (new Database('callback'))
            ->select(
                'tenancy_id = :tenancy_id
                 AND date_send >= :start_at
                 AND date_send < :end_at
                 AND status_sms IN ("ACCEPTED", "SENT", "DELIVERED", "UNDELIVERABLE", "EXPIRED")',
                [
                    ':tenancy_id' => $tenancyId,
                    ':start_at' => $start,
                    ':end_at' => $end,
                ],
                '',
                '1',
                'COUNT(*) AS total'
            )
            ->fetch(\PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    private static function countVoiceUsage(string $tenancyId, string $period): int
    {
        [$start, $end] = self::periodBounds($period);

        $row = (new Database())->execute(
            '
                SELECT COUNT(*) AS total
                FROM (
                    SELECT DISTINCT COALESCE(
                        NULLIF(call_id, \'\'),
                        CONCAT_WS(
                            \'|\',
                            COALESCE(job_id, \'\'),
                            COALESCE(channel_id, \'\'),
                            COALESCE(`number`, \'\'),
                            COALESCE(destination, \'\'),
                            DATE_FORMAT(COALESCE(started, cdr_timestamp, charged_at), \'%Y-%m-%d %H:%i:%s\')
                        )
                    ) AS usage_key
                    FROM cdr
                    WHERE tenancy_id = :tenancy_id
                      AND COALESCE(type, \'\') <> \'service_fee\'
                      AND COALESCE(direction, \'outbound\') = \'outbound\'
                      AND COALESCE(started, cdr_timestamp, charged_at) >= :start_at
                      AND COALESCE(started, cdr_timestamp, charged_at) < :end_at
                ) usage_rows
            ',
            [
                ':tenancy_id' => $tenancyId,
                ':start_at' => $start,
                ':end_at' => $end,
            ]
        )->fetch(\PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    private static function countActiveCampaigns(string $tenancyId): int
    {
        $db = new Database();

        $sms = (int)$db->execute(
            "
                SELECT COUNT(*) AS total
                FROM campaign
                WHERE tenancy_id = :tenancy_id
                  AND COALESCE(status, '') NOT IN ('f', 'c')
            ",
            [':tenancy_id' => $tenancyId]
        )->fetchColumn();

        $voice = (int)$db->execute(
            "
                SELECT COUNT(*) AS total
                FROM campaign_voice
                WHERE tenancy_id = :tenancy_id
                  AND COALESCE(status, '') NOT IN ('f', 'c', 'n')
            ",
            [':tenancy_id' => $tenancyId]
        )->fetchColumn();

        $voiceSchedules = (int)$db->execute(
            "
                SELECT COUNT(*) AS total
                FROM campaign_voice_schedules
                WHERE tenancy_id = :tenancy_id
                  AND COALESCE(status, '') NOT IN ('done', 'failed', 'cancelled')
            ",
            [':tenancy_id' => $tenancyId]
        )->fetchColumn();

        $whatsApp = (int)$db->execute(
            "
                SELECT COUNT(*) AS total
                FROM whatsapp_campaigns
                WHERE tenancy_id = :tenancy_id
                  AND COALESCE(status, '') NOT IN ('finished', 'cancelled', 'deleted')
            ",
            [':tenancy_id' => $tenancyId]
        )->fetchColumn();

        $marketing = (int)$db->execute(
            "
                SELECT COUNT(*) AS total
                FROM marketing_campaigns
                WHERE tenancy_id = :tenancy_id
                  AND deleted_at IS NULL
                  AND COALESCE(status, '') NOT IN ('archived', 'deleted')
            ",
            [':tenancy_id' => $tenancyId]
        )->fetchColumn();

        return $sms + $voice + $voiceSchedules + $whatsApp + $marketing;
    }

    private static function countTemplates(string $tenancyId): int
    {
        $row = (new Database('whatsapp_templates'))
            ->select(
                "tenancy_id = :tenancy_id
                 AND COALESCE(is_system_template, 0) = 0
                 AND COALESCE(template_type, 'tenant') <> 'system'
                 AND COALESCE(status, '') <> 'disabled'",
                [':tenancy_id' => $tenancyId],
                '',
                '1',
                'COUNT(*) AS total'
            )
            ->fetch(\PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    private static function defaultPeriod(string $limitKey): string
    {
        return match ($limitKey) {
            'sms', 'voice' => self::PERIOD_CURRENT_MONTH,
            default => '',
        };
    }

    private static function periodBounds(string $period): array
    {
        $period = strtolower(trim($period));

        if ($period !== self::PERIOD_CURRENT_MONTH) {
            $period = self::PERIOD_CURRENT_MONTH;
        }

        $start = date('Y-m-01 00:00:00');
        $end = date('Y-m-01 00:00:00', strtotime('+1 month', strtotime($start)));

        return [$start, $end];
    }
}
