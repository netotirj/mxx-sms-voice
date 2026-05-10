<?php

namespace App\Service;

use App\Model\Entity\WhatsAppAccount;
use App\Utils\TenancyHelper;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppNumberSafety
{
    private const CHANNEL_MESSAGE = 'message';
    private const CHANNEL_VOICE = 'voice';
    private const DEFAULT_VOICE_DAILY_LIMIT = 100;
    private const MESSAGE_STATUS_DELIVERED = ['delivered', 'read'];
    private const MESSAGE_STATUS_FAILED = ['failed'];
    private const VOICE_STATUS_ANSWERED = ['ACCEPTED', 'ANSWERED', 'COMPLETED', 'ANSWER'];
    private const VOICE_STATUS_REFUSED = ['BUSY', 'CANCEL', 'CANCELLED', 'REJECTED', 'DECLINED'];
    private const VOICE_STATUS_IGNORED = ['NOANSWER', 'NOT_ANSWERED'];
    private const VOICE_STATUS_FAILED = ['FAILED', 'FAILURE'];

    public static function listForUser(array $user): array
    {
        [$where, $params] = self::visibilityScope($user, 'wa');

        $rows = (new Database('whatsapp_accounts wa LEFT JOIN whatsapp_number_health h ON ' . self::healthJoinClause('wa', 'h') . ' LEFT JOIN whatsapp_numbers wn ON wn.whatsapp_account_id = wa.id AND wn.company_id = wa.tenancy_id'))
            ->select($where, $params, 'wa.id DESC', '', [
                'wa.id AS account_id',
                'wa.label',
                'wa.display_phone_number',
                'wa.phone_number_id',
                'wa.tenancy_id',
                'wa.status AS account_status',
                'wa.voice_enabled',
                'wa.voice_status',
                'wn.status AS number_status',
                'wn.display_name_meta',
                'wn.display_name',
                'wn.display_name_status',
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

        $metaPause = WhatsAppMetaRateLimitGuard::currentPause($account);
        if ($metaPause) {
            return [
                'allowed' => false,
                'delay_seconds' => (int)$metaPause['delay_seconds'],
                'reason' => 'Envio pausado por rate limit da Meta até ' . $metaPause['blocked_until'] . '.',
                'snapshot' => self::calculateSnapshot($account, $health),
            ];
        }

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
        $verification = self::fetchVerificationStatus(
            (string)$account['access_token'],
            (string)$account['phone_number_id']
        );

        $snapshot = self::updateFromMetaPayload($account, $verification['payload'], 'api');
        if (!$verification['verified']) {
            self::demoteUnverifiedAccount($account, $verification['status']);
        }

        return $snapshot;
    }

    public static function assertVerifiedForUse(array $account): array
    {
        $verification = self::syncVerificationStatus($account);
        if (!$verification['verified']) {
            throw new \RuntimeException(self::unverifiedMessage($verification['status']));
        }

        return $verification['payload'];
    }

    public static function syncVerificationStatus(array $account): array
    {
        $verification = self::fetchVerificationStatus(
            (string)$account['access_token'],
            (string)$account['phone_number_id']
        );

        self::updateFromMetaPayload($account, $verification['payload'], 'verification_check');
        if (!$verification['verified']) {
            self::demoteUnverifiedAccount($account, $verification['status']);
        }

        self::syncDisplayNameStatusForAccount($account, $verification);

        return $verification;
    }

    public static function fetchVerificationStatus(string $accessToken, string $phoneNumberId): array
    {
        $result = (new MetaWhatsAppCloudApi())->getPhoneNumber($accessToken, $phoneNumberId);

        if (!$result['ok']) {
            throw new \RuntimeException($result['error'] ?: 'Falha ao consultar status de verificação do número na Meta.');
        }

        $status = strtoupper((string)($result['data']['code_verification_status'] ?? ''));
        $nameStatus = strtoupper((string)($result['data']['name_status'] ?? ''));
        $newNameStatus = strtoupper((string)($result['data']['new_name_status'] ?? ''));

        return [
            'verified' => $status === 'VERIFIED',
            'status' => $status !== '' ? $status : 'UNKNOWN',
            'name_status' => $nameStatus !== '' ? $nameStatus : 'UNKNOWN',
            'new_name_status' => $newNameStatus !== '' ? $newNameStatus : 'UNKNOWN',
            'payload' => $result['data'],
        ];
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
                try {
                    WhatsAppAccountVoice::syncStatus($account, (int)($user['id'] ?? 0));
                    $account = WhatsAppAccount::getById((int)($account['id'] ?? 0)) ?: $account;
                } catch (\Throwable $voiceError) {
                    error_log('[whatsapp_voice_sync] ' . $voiceError->getMessage());
                }

                self::syncVerificationStatus($account);
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

    private static function isDisplayNameApproved(array $verification): bool
    {
        $status = self::currentDisplayNameStatus($verification);
        return in_array($status, ['APPROVED', 'AVAILABLE_WITHOUT_REVIEW'], true);
    }

    private static function isDisplayNameRejected(array $verification): bool
    {
        $status = self::currentDisplayNameStatus($verification);
        return in_array($status, ['REJECTED', 'DECLINED'], true);
    }

    private static function currentDisplayNameStatus(array $verification): string
    {
        $newStatus = strtoupper((string)($verification['new_name_status'] ?? ''));
        if ($newStatus !== '' && $newStatus !== 'UNKNOWN') {
            return $newStatus;
        }

        $status = strtoupper((string)($verification['name_status'] ?? ''));
        return $status !== '' ? $status : 'UNKNOWN';
    }

    private static function syncDisplayNameStatusForAccount(array $account, array $verification): void
    {
        $accountId = (int)($account['id'] ?? 0);
        $phoneNumberId = (string)($account['phone_number_id'] ?? '');
        if ($accountId <= 0 || $phoneNumberId === '') {
            return;
        }

        $displayNameStatus = self::currentDisplayNameStatus($verification);
        $lastError = null;
        if (self::isDisplayNameRejected($verification)) {
            $lastError = 'Número conectado, mas o nome comercial foi rejeitado pela Meta. Corrija o nome exibido.';
        } elseif (!self::isDisplayNameApproved($verification)) {
            $lastError = 'Seu número já está conectado e pode ser utilizado. O nome comercial ainda está em análise pela Meta e será exibido após aprovação.';
        }

        (new Database('whatsapp_numbers'))->execute(
            "UPDATE whatsapp_numbers
             SET status = 'active',
                 display_name_status = :display_name_status,
                 display_name_rejected_at = IF(:is_rejected = 1, NOW(), display_name_rejected_at),
                 display_name_last_checked_at = NOW(),
                 last_error = :last_error,
                 last_meta_error = :last_meta_error,
                 last_meta_error_at = IF(:has_last_meta_error = 1, NOW(), NULL),
                 updated_at = NOW()
             WHERE whatsapp_account_id = :account_id
                OR (meta_id = :phone_number_id AND company_id = :tenancy_id)",
            [
                ':display_name_status' => $displayNameStatus,
                ':last_error' => $lastError,
                ':last_meta_error' => $lastError,
                ':has_last_meta_error' => $lastError !== null ? 1 : 0,
                ':is_rejected' => self::isDisplayNameRejected($verification) ? 1 : 0,
                ':account_id' => $accountId,
                ':phone_number_id' => $phoneNumberId,
                ':tenancy_id' => (string)$account['tenancy_id'],
            ]
        );
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

    private static function unverifiedMessage(string $status): string
    {
        return 'Número WhatsApp bloqueado: status de verificação na Meta é '
            . ($status !== '' ? $status : 'UNKNOWN')
            . '. Confirme o código na Meta antes de usar este número.';
    }

    private static function demoteUnverifiedAccount(array $account, string $status): void
    {
        $accountId = (int)($account['id'] ?? 0);
        $phoneNumberId = (string)($account['phone_number_id'] ?? '');
        if ($accountId <= 0 || $phoneNumberId === '') {
            return;
        }

        $isNamePending = $status === 'PENDING_NAME_APPROVAL';
        $isNameRejected = $status === 'DISPLAY_NAME_REJECTED';
        $message = $isNamePending
            ? 'Seu número já está conectado e pode ser utilizado. O nome comercial ainda está em análise pela Meta e será exibido após aprovação.'
            : ($isNameRejected ? 'Nome exibido rejeitado pela Meta. Corrija o nome antes de usar este número.' : self::unverifiedMessage($status));
        $numberStatus = $isNamePending ? 'pending_name_approval' : ($isNameRejected ? 'blocked' : 'pending_verification');

        (new Database('whatsapp_accounts'))->update('id = :id', [
            'status' => 'inactive',
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $accountId]);

        (new Database('whatsapp_numbers'))->execute(
            "UPDATE whatsapp_numbers
             SET whatsapp_account_id = NULL,
                 status = :number_status,
                 verified_at = IF(:keep_verified = 1, verified_at, NULL),
                 display_name_rejected_at = IF(:is_name_rejected = 1, NOW(), display_name_rejected_at),
                 last_error = :last_error,
                 last_meta_error = :last_meta_error,
                 last_meta_error_at = NOW(),
                 updated_at = NOW()
             WHERE whatsapp_account_id = :account_id
                OR (meta_id = :phone_number_id AND company_id = :tenancy_id)",
            [
                ':last_error' => $message,
                ':last_meta_error' => $message,
                ':number_status' => $numberStatus,
                ':keep_verified' => $numberStatus === 'pending_verification' ? 0 : 1,
                ':is_name_rejected' => $isNameRejected ? 1 : 0,
                ':account_id' => $accountId,
                ':phone_number_id' => $phoneNumberId,
                ':tenancy_id' => (string)$account['tenancy_id'],
            ]
        );

        (new Database('whatsapp_outbox'))->execute(
            "UPDATE whatsapp_outbox
             SET status = 'failed',
                 error_message = :error_message,
                 updated_at = NOW()
             WHERE account_id = :account_id
               AND status IN ('queued', 'sending')",
            [
                ':error_message' => $message,
                ':account_id' => $accountId,
            ]
        );
    }

    private static function ensureHealth(array $account): array
    {
        [$lookupWhere, $lookupParams] = self::healthLookupWhere($account);
        $row = (new Database('whatsapp_number_health'))
            ->select($lookupWhere, $lookupParams, '', '1')
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

        self::insertHealthRow($account, $snapshot);

        return (new Database('whatsapp_number_health'))
            ->select($lookupWhere, $lookupParams, '', '1')
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

        $messageChannel = self::buildMessageChannelSnapshot(
            $account,
            $quality,
            $dailyLimit,
            $ageDays,
            $sentToday,
            $sentLastMinute,
            $sentLastSecond,
            $rate,
            $blockedUntil
        );
        $voiceChannel = self::buildVoiceChannelSnapshot($account, $health);
        $flattenedChannelMetrics = self::flattenChannelMetrics($messageChannel, $voiceChannel);

        return array_merge([
            'quality_status' => $quality,
            'meta_quality_rating' => $health['meta_quality_rating'] ?? null,
            'messaging_limit_tier' => $health['messaging_limit_tier'] ?? null,
            'current_daily_limit' => $messageChannel['daily_limit'],
            'sent_today' => $messageChannel['sent_today'],
            'sent_last_minute' => $messageChannel['sent_last_minute'],
            'sent_last_second' => $messageChannel['sent_last_second'],
            'per_minute_limit' => $messageChannel['per_minute_limit'],
            'per_second_limit' => $messageChannel['per_second_limit'],
            'blocked_until' => $blockedUntil,
            'recommendation' => $messageChannel['recommendation'],
            'activated_at' => $activatedAt,
            'warmup_day' => $ageDays,
            'remaining_today' => $messageChannel['remaining_today'],
            'voice_daily_limit' => $voiceChannel['daily_limit'],
            'voice_calls_today' => $voiceChannel['calls_today'],
            'voice_answered_today' => $voiceChannel['answered_today'],
            'voice_remaining_today' => $voiceChannel['remaining_today'],
            'voice_status' => $voiceChannel['voice_status'],
            'channel_temperatures' => [
                self::CHANNEL_MESSAGE => $messageChannel,
                self::CHANNEL_VOICE => $voiceChannel,
            ],
            'message_temperature' => $messageChannel,
            'voice_temperature' => $voiceChannel,
            'last_meta_sync_at' => $health['last_meta_sync_at'] ?? null,
        ], $flattenedChannelMetrics);
    }

    private static function flattenChannelMetrics(array $messageChannel, array $voiceChannel): array
    {
        return [
            'delivered_today' => $messageChannel['delivered_today'],
            'failed_today' => $messageChannel['failed_today'],
            'delivery_rate' => $messageChannel['delivery_rate'],
            'message_daily_limit' => $messageChannel['daily_limit'],
            'message_remaining_today' => $messageChannel['remaining_today'],
            'message_behavior_label' => $messageChannel['behavior_label'],
            'message_recommendation' => $messageChannel['recommendation'],
            'voice_calls_today' => $voiceChannel['calls_today'],
            'voice_answered_today' => $voiceChannel['answered_today'],
            'voice_refused_today' => $voiceChannel['refused_today'],
            'voice_ignored_today' => $voiceChannel['ignored_today'],
            'voice_failed_today' => $voiceChannel['failed_today'],
            'voice_answer_rate' => $voiceChannel['answer_rate'],
            'voice_average_duration_seconds' => $voiceChannel['average_duration_seconds'],
            'voice_behavior_label' => $voiceChannel['behavior_label'],
        ];
    }

    private static function buildMessageChannelSnapshot(
        array $account,
        string $quality,
        int $dailyLimit,
        int $ageDays,
        int $sentToday,
        int $sentLastMinute,
        int $sentLastSecond,
        array $rate,
        ?string $blockedUntil
    ): array {
        $accountId = (int)($account['id'] ?? 0);
        $deliveredToday = self::countDeliveredMessages($account, self::messageCdrDateExpression('c') . " >= CURDATE()");
        $failedToday = self::countFailedMessages($accountId, "updated_at >= CURDATE()");
        $deliveryRate = self::safeRate($deliveredToday, max($sentToday, $deliveredToday + $failedToday));
        $loadPercent = $dailyLimit > 0 ? min(100.0, ($sentToday / $dailyLimit) * 100) : 0.0;
        $temperatureStatus = self::messageTemperatureStatus($quality, $deliveryRate, $failedToday, $sentToday, $blockedUntil);

        return [
            'channel' => self::CHANNEL_MESSAGE,
            'status' => $temperatureStatus,
            'quality_status' => $quality,
            'daily_limit' => $dailyLimit,
            'remaining_today' => max(0, $dailyLimit - $sentToday),
            'sent_today' => $sentToday,
            'delivered_today' => $deliveredToday,
            'failed_today' => $failedToday,
            'delivery_rate' => $deliveryRate,
            'sent_last_minute' => $sentLastMinute,
            'sent_last_second' => $sentLastSecond,
            'per_minute_limit' => (int)($rate['minute'] ?? 0),
            'per_second_limit' => (int)($rate['second'] ?? 0),
            'load_percent' => $loadPercent,
            'blocked_until' => $blockedUntil,
            'warmup_day' => $ageDays,
            'behavior_label' => self::messageBehaviorLabel($loadPercent),
            'recommendation' => self::recommendation($quality, $sentToday, $dailyLimit, $ageDays),
        ];
    }

    private static function buildVoiceChannelSnapshot(array $account, array $health): array
    {
        $accountId = (int)($account['id'] ?? 0);
        $callsToday = self::countVoiceCalls($accountId, "started_at >= CURDATE()");
        $answeredToday = self::countVoiceStatus($accountId, self::VOICE_STATUS_ANSWERED, "started_at >= CURDATE()");
        $refusedToday = self::countVoiceStatus($accountId, self::VOICE_STATUS_REFUSED, "started_at >= CURDATE()");
        $ignoredToday = self::countVoiceStatus($accountId, self::VOICE_STATUS_IGNORED, "started_at >= CURDATE()");
        $failedToday = self::countVoiceStatus($accountId, self::VOICE_STATUS_FAILED, "started_at >= CURDATE()");
        $averageDurationSeconds = self::averageAnsweredVoiceDuration($accountId, "started_at >= CURDATE()");
        $voiceStatus = self::voiceStatus($account, [
            'calls_today' => $callsToday,
            'answered_today' => $answeredToday,
            'refused_today' => $refusedToday,
            'ignored_today' => $ignoredToday,
            'failed_today' => $failedToday,
        ]);
        $dailyLimit = self::voiceDailyLimit($account, $health, $voiceStatus);
        $answerRate = self::safeRate($answeredToday, $callsToday);
        $loadPercent = $dailyLimit > 0 ? min(100.0, ($callsToday / $dailyLimit) * 100) : 0.0;

        return [
            'channel' => self::CHANNEL_VOICE,
            'status' => self::voiceTemperatureStatus(
                $voiceStatus,
                $callsToday,
                $answeredToday,
                $refusedToday,
                $ignoredToday,
                $failedToday,
                $averageDurationSeconds
            ),
            'voice_status' => $voiceStatus,
            'daily_limit' => $dailyLimit,
            'remaining_today' => max(0, $dailyLimit - $callsToday),
            'calls_today' => $callsToday,
            'answered_today' => $answeredToday,
            'refused_today' => $refusedToday,
            'ignored_today' => $ignoredToday,
            'failed_today' => $failedToday,
            'answer_rate' => $answerRate,
            'average_duration_seconds' => $averageDurationSeconds,
            'load_percent' => $loadPercent,
            'behavior_label' => self::voiceBehaviorLabel($answerRate, $ignoredToday, $callsToday),
        ];
    }

    private static function snapshotForAccountRow(array $row): array
    {
        return self::calculateSnapshot([
            'id' => (int)$row['account_id'],
            'phone_number_id' => (string)$row['phone_number_id'],
            'tenancy_id' => $row['tenancy_id'] ?? '',
            'created_at' => $row['activated_at'] ?? date('Y-m-d H:i:s'),
            'voice_enabled' => $row['voice_enabled'] ?? 0,
            'voice_status' => $row['voice_status'] ?? '',
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

        self::updateHealthRow($account, $values);

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
        $sql = "SELECT COUNT(*) AS total
                FROM whatsapp_outbox
                WHERE account_id = :account_id
                  AND status = 'sent'
                  AND sent_at IS NOT NULL
                  AND sent_at <= NOW()
                  AND {$condition}";
        $row = (new Database('whatsapp_outbox'))->execute($sql, [':account_id' => $accountId])->fetch(PDO::FETCH_ASSOC);
        return (int)($row['total'] ?? 0);
    }

    private static function countVoiceCalls(int $accountId, string $condition): int
    {
        WhatsAppCallCdrStore::ensureSchema();

        $sql = "SELECT COUNT(DISTINCT call_id) AS total
                FROM whatsapp_call_cdr
                WHERE account_id = :account_id
                  AND call_id IS NOT NULL
                  AND call_id <> ''
                  AND started_at IS NOT NULL
                  AND {$condition}";
        $row = (new Database('whatsapp_call_cdr'))->execute($sql, [':account_id' => $accountId])->fetch(PDO::FETCH_ASSOC);
        return (int)($row['total'] ?? 0);
    }

    private static function countAnsweredVoiceCalls(int $accountId, string $condition): int
    {
        WhatsAppCallCdrStore::ensureSchema();

        $sql = "SELECT COUNT(DISTINCT call_id) AS total
                FROM whatsapp_call_cdr
                WHERE account_id = :account_id
                  AND call_id IS NOT NULL
                  AND call_id <> ''
                  AND started_at IS NOT NULL
                  AND {$condition}
                  AND (
                      answered_at IS NOT NULL
                      OR COALESCE(final_price, 0) > 0
                      OR UPPER(COALESCE(status, '')) IN ('ACCEPTED', 'ANSWERED', 'COMPLETED')
                  )";
        $row = (new Database('whatsapp_call_cdr'))->execute($sql, [':account_id' => $accountId])->fetch(PDO::FETCH_ASSOC);
        return (int)($row['total'] ?? 0);
    }

    private static function countDeliveredMessages(array $account, string $condition): int
    {
        $statuses = self::quoteStringList(self::MESSAGE_STATUS_DELIVERED);
        $params = [];

        if (self::tableHasColumn('whatsapp_message_cdr', 'account_id')) {
            $sql = "SELECT COUNT(*) AS total
                    FROM whatsapp_message_cdr
                    WHERE account_id = :account_id
                      AND UPPER(COALESCE(status, '')) IN ({$statuses})
                      AND {$condition}";
            $params[':account_id'] = (int)($account['id'] ?? 0);
        } elseif (self::tableHasColumn('whatsapp_message_cdr', 'whatsapp_outbox_id')) {
            $sql = "SELECT COUNT(*) AS total
                    FROM whatsapp_message_cdr c
                    INNER JOIN whatsapp_outbox wo ON wo.id = c.whatsapp_outbox_id
                    WHERE wo.account_id = :account_id
                      AND UPPER(COALESCE(c.status, '')) IN ({$statuses})
                      AND {$condition}";
            $params[':account_id'] = (int)($account['id'] ?? 0);
        } else {
            return 0;
        }

        $row = (new Database('whatsapp_message_cdr'))->execute($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return (int)($row['total'] ?? 0);
    }

    private static function countFailedMessages(int $accountId, string $condition): int
    {
        $statuses = self::quoteStringList(self::MESSAGE_STATUS_FAILED);
        $sql = "SELECT COUNT(*) AS total
                FROM whatsapp_outbox
                WHERE account_id = :account_id
                  AND UPPER(COALESCE(status, '')) IN ({$statuses})
                  AND {$condition}";
        $row = (new Database('whatsapp_outbox'))->execute($sql, [':account_id' => $accountId])->fetch(PDO::FETCH_ASSOC);
        return (int)($row['total'] ?? 0);
    }

    private static function countVoiceStatus(int $accountId, array $statuses, string $condition): int
    {
        WhatsAppCallCdrStore::ensureSchema();

        $sql = "SELECT COUNT(DISTINCT call_id) AS total
                FROM whatsapp_call_cdr
                WHERE account_id = :account_id
                  AND call_id IS NOT NULL
                  AND call_id <> ''
                  AND started_at IS NOT NULL
                  AND {$condition}
                  AND UPPER(COALESCE(status, '')) IN (" . self::quoteStringList($statuses) . ")";
        $row = (new Database('whatsapp_call_cdr'))->execute($sql, [':account_id' => $accountId])->fetch(PDO::FETCH_ASSOC);
        return (int)($row['total'] ?? 0);
    }

    private static function averageAnsweredVoiceDuration(int $accountId, string $condition): int
    {
        WhatsAppCallCdrStore::ensureSchema();

        $sql = "SELECT AVG(COALESCE(duration_seconds, 0)) AS average_seconds
                FROM whatsapp_call_cdr
                WHERE account_id = :account_id
                  AND call_id IS NOT NULL
                  AND call_id <> ''
                  AND started_at IS NOT NULL
                  AND {$condition}
                  AND UPPER(COALESCE(status, '')) IN (" . self::quoteStringList(self::VOICE_STATUS_ANSWERED) . ")";
        $row = (new Database('whatsapp_call_cdr'))->execute($sql, [':account_id' => $accountId])->fetch(PDO::FETCH_ASSOC);
        return max(0, (int)round((float)($row['average_seconds'] ?? 0)));
    }

    private static function voiceDailyLimit(array $account, array $health, ?string $effectiveStatus = null): int
    {
        $status = strtolower((string)($effectiveStatus ?? $account['voice_status'] ?? $health['voice_status'] ?? ''));
        $enabled = !empty($account['voice_enabled']) || $status === 'active';
        return $enabled ? self::DEFAULT_VOICE_DAILY_LIMIT : 0;
    }

    private static function voiceStatus(array $account, array $metrics = []): string
    {
        $status = strtolower(trim((string)($account['voice_status'] ?? '')));
        $hasVoiceTraffic = max(
            0,
            (int)($metrics['calls_today'] ?? 0),
            (int)($metrics['answered_today'] ?? 0),
            (int)($metrics['refused_today'] ?? 0),
            (int)($metrics['ignored_today'] ?? 0),
            (int)($metrics['failed_today'] ?? 0)
        ) > 0;

        if ((!empty($account['voice_enabled']) || $hasVoiceTraffic) && !in_array($status, ['unavailable'], true)) {
            return 'active';
        }

        if ($status !== '') {
            return $status;
        }

        return !empty($account['voice_enabled']) ? 'active' : 'inactive';
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

    private static function quoteStringList(array $values): string
    {
        $normalized = array_values(array_filter(array_map(static function ($value): string {
            return strtoupper(trim((string)$value));
        }, $values), static fn(string $value): bool => $value !== ''));

        if ($normalized === []) {
            return "''";
        }

        return implode(', ', array_map(static fn(string $value): string => "'" . addslashes($value) . "'", $normalized));
    }

    private static function visibilityScope(array $user, string $alias = 'wa'): array
    {
        if (TenancyHelper::isSuperAdmin($user)) {
            return ['1=1', []];
        }

        $role = strtolower(trim((string)($user['user_function'] ?? $user['function'] ?? '')));
        $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';
        $params = [
            ':scope_tenancy_id' => (string)($user['tenancy_id'] ?? ''),
        ];
        $where = "{$prefix}tenancy_id = :scope_tenancy_id";

        if (in_array($role, ['admin', 'manager', 'supervisor', 'monitor', 'support_l2', 'support_ticket_manager'], true)) {
            return [$where, $params];
        }

        if (in_array($role, ['agent', 'support_l1', 'operator', 'o', 'ticket_support'], true)) {
            $where .= " AND EXISTS (
                SELECT 1
                FROM whatsapp_support_queues scope_q
                INNER JOIN whatsapp_support_queue_agents scope_qa
                    ON scope_qa.queue_id = scope_q.id
                   AND scope_qa.tenancy_id = scope_q.tenancy_id
                WHERE scope_q.tenancy_id = {$prefix}tenancy_id
                  AND scope_qa.agent_user_id = :scope_agent_user_id
                  AND (
                      scope_q.account_id = {$prefix}id
                      OR scope_q.account_id IS NULL
                  )
            )";
            $params[':scope_agent_user_id'] = (int)($user['id'] ?? 0);
            return [$where, $params];
        }

        return [TenancyHelper::applySecurityFilter('', $user, 'user_id', $alias), []];
    }

    private static function healthJoinClause(string $accountAlias, string $healthAlias): string
    {
        if (self::healthTableHasColumn('account_id')) {
            return "{$healthAlias}.account_id = {$accountAlias}.id";
        }

        return "{$healthAlias}.phone_number_id = {$accountAlias}.phone_number_id AND {$healthAlias}.tenancy_id = {$accountAlias}.tenancy_id";
    }

    private static function healthLookupWhere(array $account): array
    {
        if (self::healthTableHasColumn('account_id')) {
            return [
                'account_id = :account_id',
                [':account_id' => (int)($account['id'] ?? 0)],
            ];
        }

        return [
            'phone_number_id = :phone_number_id AND tenancy_id = :tenancy_id',
            [
                ':phone_number_id' => (string)($account['phone_number_id'] ?? ''),
                ':tenancy_id' => (string)($account['tenancy_id'] ?? ''),
            ],
        ];
    }

    private static function insertHealthRow(array $account, array $snapshot): void
    {
        $values = [
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
        ];

        if (self::healthTableHasColumn('account_id')) {
            $values['account_id'] = (int)$account['id'];
        }

        (new Database('whatsapp_number_health'))->insert($values);
    }

    private static function updateHealthRow(array $account, array $values): void
    {
        [$where, $params] = self::healthLookupWhere($account);
        (new Database('whatsapp_number_health'))->update($where, $values, $params);
    }

    private static function healthTableHasColumn(string $column): bool
    {
        return self::tableHasColumn('whatsapp_number_health', $column, $column !== 'account_id');
    }

    private static function tableHasColumn(string $table, string $column, bool $fallback = false): bool
    {
        static $cache = [];
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        try {
            $result = (new Database())->execute(
                "SELECT COUNT(*) AS total
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table
                   AND COLUMN_NAME = :column",
                [
                    ':table' => $table,
                    ':column' => $column,
                ]
            )->fetch(PDO::FETCH_ASSOC);

            $cache[$cacheKey] = (int)($result['total'] ?? 0) > 0;
        } catch (\Throwable $e) {
            $cache[$cacheKey] = $fallback;
        }

        return $cache[$cacheKey];
    }

    private static function messageCdrDateExpression(string $alias = 'c'): string
    {
        $parts = [];

        if (self::tableHasColumn('whatsapp_message_cdr', 'delivered_at')) {
            $parts[] = "{$alias}.delivered_at";
        }

        if (self::tableHasColumn('whatsapp_message_cdr', 'timestamp')) {
            $parts[] = "{$alias}.timestamp";
        }

        if (self::tableHasColumn('whatsapp_message_cdr', 'created_at', true)) {
            $parts[] = "{$alias}.created_at";
        }

        if ($parts === []) {
            return 'NOW()';
        }

        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private static function safeRate(int $part, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($part / $total) * 100, 2);
    }

    private static function messageTemperatureStatus(string $quality, float $deliveryRate, int $failedToday, int $sentToday, ?string $blockedUntil): string
    {
        $isBlocked = !empty($blockedUntil) && strtotime((string)$blockedUntil) > time();
        if ($isBlocked || $quality === 'low') {
            return 'low';
        }

        if ($quality === 'medium') {
            return ($deliveryRate > 0 && $deliveryRate < 70) || $failedToday >= max(2, (int)ceil($sentToday * 0.12))
                ? 'low'
                : 'medium';
        }

        if ($quality === 'high') {
            if (($deliveryRate > 0 && $deliveryRate < 80) || $failedToday >= max(3, (int)ceil($sentToday * 0.15))) {
                return 'medium';
            }

            return 'high';
        }

        if ($sentToday === 0 && $failedToday === 0) {
            return 'unknown';
        }

        return $deliveryRate >= 80 && $failedToday === 0 ? 'medium' : 'low';
    }

    private static function voiceTemperatureStatus(
        string $voiceStatus,
        int $callsToday,
        int $answeredToday,
        int $refusedToday,
        int $ignoredToday,
        int $failedToday,
        int $averageDurationSeconds
    ): string {
        if ($voiceStatus !== 'active') {
            return 'unknown';
        }

        if ($callsToday === 0) {
            return 'unknown';
        }

        $answerRate = self::safeRate($answeredToday, $callsToday);
        if ($answerRate < 25 || $ignoredToday >= max(2, $answeredToday) || $failedToday >= max(2, (int)ceil($callsToday * 0.2))) {
            return 'low';
        }

        if ($answerRate < 55 || $refusedToday > $answeredToday || ($answeredToday > 0 && $averageDurationSeconds < 20)) {
            return 'medium';
        }

        return 'high';
    }

    private static function messageBehaviorLabel(float $loadPercent): string
    {
        if ($loadPercent >= 90) {
            return 'em ritmo forte';
        }

        if ($loadPercent >= 60) {
            return 'janela aquecendo';
        }

        return 'janela controlada';
    }

    private static function voiceBehaviorLabel(float $answerRate, int $ignoredToday, int $callsToday): string
    {
        if ($callsToday === 0) {
            return 'sem chamadas hoje';
        }

        if ($answerRate >= 60 && $ignoredToday === 0) {
            return 'chamadas bem respondidas';
        }

        if ($answerRate >= 35) {
            return 'cadência moderada';
        }

        return 'atenção ao comportamento';
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
