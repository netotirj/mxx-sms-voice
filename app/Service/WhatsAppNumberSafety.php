<?php

namespace App\Service;

use App\Model\Entity\WhatsAppAccount;
use App\Utils\TenancyHelper;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppNumberSafety
{
    public static function listForUser(array $user): array
    {
        $where = TenancyHelper::applySecurityFilter('', $user, 'user_id', 'wa');

        $rows = (new Database('whatsapp_accounts wa LEFT JOIN whatsapp_number_health h ON h.account_id = wa.id'))
            ->select($where, [], 'wa.id DESC', '', [
                'wa.id AS account_id',
                'wa.label',
                'wa.display_phone_number',
                'wa.phone_number_id',
                'wa.tenancy_id',
                'wa.status AS account_status',
                'COALESCE(h.quality_status, "unknown") AS quality_status',
                'h.meta_quality_rating',
                'h.messaging_limit_tier',
                'COALESCE(h.current_daily_limit, 50) AS current_daily_limit',
                'COALESCE(h.sent_today, 0) AS sent_today',
                'COALESCE(h.sent_last_minute, 0) AS sent_last_minute',
                'COALESCE(h.sent_last_second, 0) AS sent_last_second',
                'h.blocked_until',
                'h.recommendation',
                'h.last_meta_sync_at',
                'wa.created_at AS activated_at',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $snapshot = self::snapshotForAccountRow($row);
            $row = array_merge($row, $snapshot);
        }
        unset($row);

        return $rows;
    }

    public static function checkBeforeSend(array $outboxRow, array $account): array
    {
        $accountId = (int)$account['id'];
        $health = self::ensureHealth($account);
        $snapshot = self::calculateSnapshot($account, $health);
        self::persistSnapshot($account, $snapshot);

        if (!empty($snapshot['blocked_until']) && strtotime((string)$snapshot['blocked_until']) > time()) {
            return [
                'allowed' => false,
                'delay_seconds' => max(60, strtotime((string)$snapshot['blocked_until']) - time()),
                'reason' => 'Envio pausado temporariamente para proteger a qualidade do número.',
                'snapshot' => $snapshot,
            ];
        }

        if ($snapshot['quality_status'] === 'low') {
            return [
                'allowed' => false,
                'delay_seconds' => 3600,
                'reason' => 'Qualidade baixa: envios pausados por segurança.',
                'snapshot' => $snapshot,
            ];
        }

        if ($snapshot['sent_today'] >= $snapshot['current_daily_limit']) {
            return [
                'allowed' => false,
                'delay_seconds' => self::secondsUntilTomorrow(),
                'reason' => 'Limite diário do número atingido.',
                'snapshot' => $snapshot,
            ];
        }

        if ($snapshot['sent_last_second'] >= $snapshot['per_second_limit']) {
            return [
                'allowed' => false,
                'delay_seconds' => 2,
                'reason' => 'Controle de velocidade por segundo.',
                'snapshot' => $snapshot,
            ];
        }

        if ($snapshot['sent_last_minute'] >= $snapshot['per_minute_limit']) {
            return [
                'allowed' => false,
                'delay_seconds' => 30,
                'reason' => 'Controle de velocidade por minuto.',
                'snapshot' => $snapshot,
            ];
        }

        $reservation = WhatsAppRedisRateLimiter::reserve(
            $accountId,
            (int)$snapshot['current_daily_limit'],
            (int)$snapshot['per_minute_limit'],
            (int)$snapshot['per_second_limit']
        );

        if (!$reservation['allowed']) {
            return [
                'allowed' => false,
                'delay_seconds' => (int)$reservation['delay_seconds'],
                'reason' => (string)$reservation['reason'],
                'snapshot' => $snapshot,
            ];
        }

        return [
            'allowed' => true,
            'delay_seconds' => 0,
            'reason' => null,
            'snapshot' => $snapshot,
        ];
    }

    public static function recordSent(int $accountId): void
    {
        $account = WhatsAppAccount::getById($accountId);
        if (!$account) {
            return;
        }

        $health = self::ensureHealth($account);
        self::persistSnapshot($account, self::calculateSnapshot($account, $health));
    }

    public static function syncFromMeta(array $account): array
    {
        $result = (new MetaWhatsAppCloudApi())->getPhoneNumber(
            (string)$account['access_token'],
            (string)$account['phone_number_id']
        );

        if (!$result['ok']) {
            throw new \RuntimeException($result['error'] ?: 'Falha ao consultar qualidade do número.');
        }

        return self::updateFromMetaPayload($account, $result['data'], 'api');
    }

    public static function syncAllForUser(array $user): array
    {
        $where = TenancyHelper::applySecurityFilter("status = 'active'", $user, 'user_id', 'whatsapp_accounts');
        $accounts = (new Database('whatsapp_accounts'))
            ->select($where)
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $summary = ['synced' => 0, 'failed' => 0, 'errors' => []];
        foreach ($accounts as $account) {
            try {
                self::syncFromMeta($account);
                $summary['synced']++;
            } catch (\Throwable $e) {
                $summary['failed']++;
                $summary['errors'][] = [
                    'account_id' => (int)$account['id'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $summary;
    }

    public static function handleMetaWebhook(array $value): void
    {
        $phoneNumberId = (string)(
            $value['phone_number_id']
            ?? $value['metadata']['phone_number_id']
            ?? $value['display_phone_number_id']
            ?? ''
        );

        if ($phoneNumberId === '') {
            return;
        }

        $account = WhatsAppAccount::getByPhoneNumberId($phoneNumberId);
        if (!$account) {
            return;
        }

        self::updateFromMetaPayload($account, $value, 'webhook');
    }

    private static function updateFromMetaPayload(array $account, array $payload, string $source): array
    {
        $qualityRating = (string)(
            $payload['quality_rating']
            ?? $payload['event']
            ?? $payload['current_quality_update_event']
            ?? ''
        );
        $quality = self::normalizeQuality($qualityRating);
        $messagingLimit = (string)(
            $payload['messaging_limit_tier']
            ?? $payload['messaging_limit']
            ?? $payload['current_limit']
            ?? ''
        );

        $health = self::ensureHealth($account);
        $health['quality_status'] = $quality;
        $health['meta_quality_rating'] = $qualityRating ?: null;
        $health['messaging_limit_tier'] = $messagingLimit ?: ($health['messaging_limit_tier'] ?? null);
        $snapshot = self::calculateSnapshot($account, $health);
        $snapshot['last_meta_sync_at'] = date('Y-m-d H:i:s');

        self::persistSnapshot($account, $snapshot);
        self::recordQualityEvent(
            (int)$account['id'],
            (string)$account['phone_number_id'],
            $quality,
            $qualityRating,
            $source,
            $payload
        );

        return $snapshot;
    }

    private static function ensureHealth(array $account): array
    {
        $row = (new Database('whatsapp_number_health'))
            ->select('account_id = :account_id', [':account_id' => (int)$account['id']], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return $row;
        }

        $snapshot = self::calculateSnapshot($account, [
            'quality_status' => 'unknown',
            'meta_quality_rating' => null,
            'messaging_limit_tier' => null,
            'blocked_until' => null,
        ]);

        (new Database('whatsapp_number_health'))->insert([
            'account_id' => (int)$account['id'],
            'phone_number_id' => (string)$account['phone_number_id'],
            'tenancy_id' => (string)$account['tenancy_id'],
            'quality_status' => $snapshot['quality_status'],
            'meta_quality_rating' => null,
            'messaging_limit_tier' => null,
            'current_daily_limit' => $snapshot['current_daily_limit'],
            'sent_today' => $snapshot['sent_today'],
            'sent_last_minute' => $snapshot['sent_last_minute'],
            'sent_last_second' => $snapshot['sent_last_second'],
            'blocked_until' => $snapshot['blocked_until'],
            'recommendation' => $snapshot['recommendation'],
            'last_meta_sync_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return (new Database('whatsapp_number_health'))
            ->select('account_id = :account_id', [':account_id' => (int)$account['id']], '', '1')
            ->fetch(PDO::FETCH_ASSOC) ?: $snapshot;
    }

    private static function calculateSnapshot(array $account, array $health): array
    {
        $quality = self::normalizeQuality((string)($health['quality_status'] ?? $health['meta_quality_rating'] ?? 'unknown'));
        $activatedAt = self::activationDate((int)$account['id'], (string)($account['created_at'] ?? date('Y-m-d H:i:s')));
        $ageDays = max(1, (int)floor((time() - strtotime($activatedAt)) / 86400) + 1);
        $warmupLimit = self::warmupLimit($ageDays);
        $dailyLimit = self::applyQualityFactor($warmupLimit, $quality);
        $metaLimit = self::parseMetaLimit((string)($health['messaging_limit_tier'] ?? ''));
        if ($metaLimit !== null) {
            $dailyLimit = min($dailyLimit, $metaLimit);
        }

        $sentToday = self::countSent((int)$account['id'], "sent_at >= CURDATE()");
        $sentLastMinute = self::countSent((int)$account['id'], "sent_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
        $sentLastSecond = self::countSent((int)$account['id'], "sent_at >= DATE_SUB(NOW(), INTERVAL 1 SECOND)");
        $rate = self::rateLimits($quality);

        $blockedUntil = $health['blocked_until'] ?? null;
        if ($quality === 'low' && (empty($blockedUntil) || strtotime((string)$blockedUntil) < time())) {
            $blockedUntil = date('Y-m-d H:i:s', time() + 3600);
        } elseif ($quality !== 'low') {
            $blockedUntil = null;
        }

        return [
            'quality_status' => $quality,
            'meta_quality_rating' => $health['meta_quality_rating'] ?? null,
            'messaging_limit_tier' => $health['messaging_limit_tier'] ?? null,
            'current_daily_limit' => $dailyLimit,
            'sent_today' => $sentToday,
            'sent_last_minute' => $sentLastMinute,
            'sent_last_second' => $sentLastSecond,
            'per_minute_limit' => $rate['minute'],
            'per_second_limit' => $rate['second'],
            'blocked_until' => $blockedUntil,
            'recommendation' => self::recommendation($quality, $sentToday, $dailyLimit, $ageDays),
            'activated_at' => $activatedAt,
            'warmup_day' => $ageDays,
            'remaining_today' => max(0, $dailyLimit - $sentToday),
            'last_meta_sync_at' => $health['last_meta_sync_at'] ?? null,
        ];
    }

    private static function snapshotForAccountRow(array $row): array
    {
        return self::calculateSnapshot([
            'id' => (int)$row['account_id'],
            'phone_number_id' => (string)$row['phone_number_id'],
            'tenancy_id' => $row['tenancy_id'] ?? '',
            'created_at' => $row['activated_at'] ?? date('Y-m-d H:i:s'),
        ], $row);
    }

    private static function persistSnapshot(array $account, array $snapshot): void
    {
        $values = [
            'quality_status' => $snapshot['quality_status'],
            'meta_quality_rating' => $snapshot['meta_quality_rating'],
            'messaging_limit_tier' => $snapshot['messaging_limit_tier'],
            'current_daily_limit' => $snapshot['current_daily_limit'],
            'sent_today' => $snapshot['sent_today'],
            'sent_last_minute' => $snapshot['sent_last_minute'],
            'sent_last_second' => $snapshot['sent_last_second'],
            'blocked_until' => $snapshot['blocked_until'],
            'recommendation' => $snapshot['recommendation'],
            'last_meta_sync_at' => $snapshot['last_meta_sync_at'],
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        (new Database('whatsapp_number_health'))->update(
            'account_id = :account_id',
            $values,
            [':account_id' => (int)$account['id']]
        );

        (new Database('whatsapp_numbers'))->update(
            'meta_id = :meta_id',
            [
                'quality_status' => $snapshot['quality_status'],
                'current_daily_limit' => $snapshot['current_daily_limit'],
                'send_blocked_until' => $snapshot['blocked_until'],
                'recommendation' => $snapshot['recommendation'],
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':meta_id' => (string)$account['phone_number_id']]
        );
    }

    private static function recordQualityEvent(int $accountId, string $phoneNumberId, string $quality, string $rating, string $source, array $payload): void
    {
        (new Database('whatsapp_number_quality_events'))->insert([
            'account_id' => $accountId,
            'phone_number_id' => $phoneNumberId,
            'quality_status' => $quality,
            'meta_quality_rating' => $rating ?: null,
            'source' => $source,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function activationDate(int $accountId, string $fallback): string
    {
        $row = (new Database('whatsapp_numbers'))
            ->select('whatsapp_account_id = :account_id', [':account_id' => $accountId], '', '1', [
                'COALESCE(verified_at, created_at) AS activated_at',
            ])
            ->fetch(PDO::FETCH_ASSOC);

        return (string)($row['activated_at'] ?? $fallback);
    }

    private static function countSent(int $accountId, string $condition): int
    {
        $sql = "SELECT COUNT(*) AS total FROM whatsapp_outbox WHERE account_id = :account_id AND status = 'sent' AND {$condition}";
        $row = (new Database('whatsapp_outbox'))->execute($sql, [':account_id' => $accountId])->fetch(PDO::FETCH_ASSOC);
        return (int)($row['total'] ?? 0);
    }

    private static function normalizeQuality(string $quality): string
    {
        $normalized = strtolower(trim($quality));
        return match ($normalized) {
            'green', 'high', 'quality_high' => 'high',
            'yellow', 'medium', 'quality_medium' => 'medium',
            'red', 'low', 'quality_low', 'flagged' => 'low',
            default => 'unknown',
        };
    }

    private static function warmupLimit(int $ageDays): int
    {
        return match (true) {
            $ageDays <= 1 => 50,
            $ageDays === 2 => 100,
            $ageDays === 3 => 200,
            $ageDays <= 5 => 400,
            $ageDays <= 7 => 800,
            default => 1000,
        };
    }

    private static function applyQualityFactor(int $limit, string $quality): int
    {
        return match ($quality) {
            'high' => $limit,
            'medium' => max(25, (int)floor($limit * 0.5)),
            'low' => 0,
            default => max(25, (int)floor($limit * 0.3)),
        };
    }

    private static function rateLimits(string $quality): array
    {
        return match ($quality) {
            'high' => ['second' => 2, 'minute' => 60],
            'medium' => ['second' => 1, 'minute' => 30],
            'low' => ['second' => 0, 'minute' => 0],
            default => ['second' => 1, 'minute' => 15],
        };
    }

    private static function parseMetaLimit(string $tier): ?int
    {
        $normalized = strtoupper(trim($tier));
        $known = [
            'TIER_50' => 50,
            'TIER_250' => 250,
            'TIER_1K' => 1000,
            'TIER_10K' => 10000,
            'TIER_100K' => 100000,
            'TIER_UNLIMITED' => null,
        ];

        if (array_key_exists($normalized, $known)) {
            return $known[$normalized];
        }

        if (preg_match('/(\d+)/', $tier, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    private static function recommendation(string $quality, int $sentToday, int $dailyLimit, int $ageDays): string
    {
        if ($quality === 'low') {
            return 'Envios pausados. Aguarde a recuperação da qualidade e evite campanhas frias.';
        }

        if ($sentToday >= $dailyLimit) {
            return 'Limite diário atingido. As próximas mensagens serão enviadas automaticamente amanhã.';
        }

        if ($quality === 'medium') {
            return 'Reduza o volume e priorize contatos recentes ou que já interagiram.';
        }

        if ($ageDays <= 3) {
            return 'Número em aquecimento. O limite aumenta automaticamente mantendo boa qualidade.';
        }

        return 'Qualidade saudável. Continue enviando para bases com opt-in.';
    }

    private static function secondsUntilTomorrow(): int
    {
        return max(60, strtotime('tomorrow 00:05:00') - time());
    }
}
