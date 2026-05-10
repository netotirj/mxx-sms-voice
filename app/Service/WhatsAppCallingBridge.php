<?php

namespace App\Service;

use App\Config\WhatsAppConfig;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppCallingBridge
{
    private const STATUS_EVENTS = ['RINGING', 'ACCEPTED', 'REJECTED'];
    private const SESSION_EVENT_TYPES = ['CONNECT', 'PRE_ACCEPT', 'ACCEPT', 'REJECT', 'TERMINATE'];

    public static function isEnabled(): bool
    {
        return WhatsAppConfig::callingBridgeEnabled();
    }

    public static function isCallingStatus(array $status): bool
    {
        $statusName = strtoupper(trim((string)($status['status'] ?? '')));
        return $statusName !== '' && in_array($statusName, self::STATUS_EVENTS, true);
    }

    public static function handleStatusWebhook(array $account, array $status, array $value = []): bool
    {
        $callId = trim((string)($status['id'] ?? ''));
        if ($callId === '') {
            return false;
        }

        $statusName = strtoupper(trim((string)($status['status'] ?? 'UNKNOWN')));
        $payload = [
            'source' => 'meta.statuses',
            'account_id' => (int)($account['id'] ?? 0),
            'phone_number_id' => (string)($account['phone_number_id'] ?? ''),
            'call_id' => $callId,
            'status' => $statusName,
            'timestamp' => (string)($status['timestamp'] ?? ''),
            'recipient_id' => self::normalizePhone((string)($status['recipient_id'] ?? '')),
            'biz_opaque_callback_data' => (string)($status['biz_opaque_callback_data'] ?? ''),
            'raw_status' => $status,
            'metadata' => is_array($value['metadata'] ?? null) ? $value['metadata'] : [],
        ];

        self::persistSession($callId, [
            'call_id' => $callId,
            'account_id' => (int)($account['id'] ?? 0),
            'phone_number_id' => (string)($account['phone_number_id'] ?? ''),
            'direction' => 'BUSINESS_INITIATED',
            'status' => $statusName,
            'last_event' => $statusName,
            'recipient_id' => $payload['recipient_id'],
            'biz_opaque_callback_data' => $payload['biz_opaque_callback_data'],
            'updated_at' => date('c'),
        ]);

        WhatsAppCallCdrStore::upsert([
            'tenancy_id' => (string)($account['tenancy_id'] ?? ''),
            'customer_id' => (int)($account['user_id'] ?? 0) ?: null,
            'account_id' => (int)($account['id'] ?? 0),
            'call_id' => $callId,
            'direction' => 'outbound',
            'to_number' => $payload['recipient_id'],
            'status' => $statusName,
            'raw_payload' => [
                'account' => [
                    'id' => (int)($account['id'] ?? 0),
                    'user_id' => (int)($account['user_id'] ?? 0) ?: null,
                    'tenancy_id' => (string)($account['tenancy_id'] ?? ''),
                ],
                'value' => $value,
                'status' => $status,
            ],
        ]);

        self::emitSupportEvent((string)($account['tenancy_id'] ?? ''), 'call.meta.status', $payload);
        self::log('call.status_webhook', $payload);

        return true;
    }

    public static function handleCallWebhook(array $account, array $call, array $value = []): bool
    {
        $callId = trim((string)($call['id'] ?? ''));
        if ($callId === '') {
            return false;
        }

        $event = strtoupper(trim((string)($call['event'] ?? 'UNKNOWN')));
        $direction = strtoupper(trim((string)($call['direction'] ?? 'UNKNOWN')));
        $session = self::extractSession($call['session'] ?? null);
        $payload = [
            'source' => 'meta.calls',
            'account_id' => (int)($account['id'] ?? 0),
            'phone_number_id' => (string)($account['phone_number_id'] ?? ''),
            'call_id' => $callId,
            'event' => $event,
            'direction' => $direction,
            'from' => self::normalizePhone((string)($call['from'] ?? '')),
            'to' => self::normalizePhone((string)($call['to'] ?? '')),
            'timestamp' => (string)($call['timestamp'] ?? ''),
            'session' => $session,
            'raw_call' => $call,
            'metadata' => is_array($value['metadata'] ?? null) ? $value['metadata'] : [],
        ];

        self::persistSession($callId, [
            'call_id' => $callId,
            'account_id' => (int)($account['id'] ?? 0),
            'phone_number_id' => (string)($account['phone_number_id'] ?? ''),
            'direction' => $direction,
            'from' => $payload['from'],
            'to' => $payload['to'],
            'status' => $event,
            'last_event' => $event,
            'session' => $session,
            'updated_at' => date('c'),
        ]);

        WhatsAppVoiceBilling::handleCallWebhook($account, $call, $value);

        $eventType = $event === 'TERMINATE' ? 'call.meta.terminate' : 'call.meta.lifecycle';
        self::emitSupportEvent((string)($account['tenancy_id'] ?? ''), $eventType, $payload);
        self::log('call.lifecycle_webhook', $payload);

        return true;
    }

    public static function registerControlAction(array $account, string $action, array $requestPayload, array $result, array $context = []): void
    {
        $callId = trim((string)($requestPayload['call_id'] ?? ($result['data']['calls'][0]['id'] ?? '')));
        $session = self::extractSession($requestPayload['session'] ?? null);
        $normalizedAction = strtoupper(trim($action));
        $payload = [
            'source' => 'platform.control',
            'account_id' => (int)($account['id'] ?? 0),
            'phone_number_id' => (string)($account['phone_number_id'] ?? ''),
            'call_id' => $callId,
            'action' => $normalizedAction,
            'ok' => (bool)($result['ok'] ?? false),
            'http_status' => (int)($result['status'] ?? 0),
            'error' => $result['error'] ?? null,
            'context' => $context,
            'session' => $session,
        ];

        if ($callId !== '') {
            $sessionUpdate = [
                'call_id' => $callId,
                'account_id' => (int)($account['id'] ?? 0),
                'phone_number_id' => (string)($account['phone_number_id'] ?? ''),
                'last_control_action' => $normalizedAction,
                'status' => $normalizedAction,
                'updated_at' => date('c'),
            ];
            if ($session !== []) {
                $sessionUpdate['session'] = $session;
            }
            if (!empty($requestPayload['to'])) {
                $sessionUpdate['to'] = self::normalizePhone((string)$requestPayload['to']);
            }
            self::persistSession($callId, $sessionUpdate);
        }

        self::emitSupportEvent((string)($account['tenancy_id'] ?? ''), 'call.meta.control', $payload);
        self::log('call.control_action', $payload);
    }

    public static function getSession(string $callId): ?array
    {
        $callId = trim($callId);
        if ($callId === '') {
            return null;
        }

        $file = self::sessionFile($callId);
        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string)file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function listSessions(?int $accountId = null, int $limit = 100): array
    {
        $dir = self::sessionDirectory();
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        $sessions = [];

        foreach ($files as $file) {
            $decoded = json_decode((string)file_get_contents($file), true);
            if (!is_array($decoded)) {
                continue;
            }
            if ($accountId !== null && (int)($decoded['account_id'] ?? 0) !== $accountId) {
                continue;
            }
            $sessions[] = $decoded;
        }

        usort($sessions, static function (array $a, array $b): int {
            return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
        });

        return array_slice($sessions, 0, max(1, $limit));
    }

    public static function summarizeSessionPayload(?array $session): array
    {
        return self::extractSession($session);
    }

    private static function persistSession(string $callId, array $data): void
    {
        $current = self::getSession($callId) ?? [];
        $merged = array_merge($current, $data);

        if (!isset($merged['created_at'])) {
            $merged['created_at'] = date('c');
        }
        $merged['updated_at'] = date('c');

        $dir = self::sessionDirectory();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        @file_put_contents(
            self::sessionFile($callId),
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private static function emitSupportEvent(string $tenancyId, string $eventType, array $payload): void
    {
        if ($tenancyId === '') {
            return;
        }

        try {
            (new Database('whatsapp_support_events'))->insert([
                'tenancy_id' => $tenancyId,
                'event_type' => $eventType,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            self::log('call.support_event_failed', [
                'tenancy_id' => $tenancyId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function extractSession(mixed $session): array
    {
        if (!is_array($session)) {
            return [];
        }

        $sdp = (string)($session['sdp'] ?? '');

        return [
            'sdp_type' => strtolower(trim((string)($session['sdp_type'] ?? ''))),
            'sdp' => $sdp,
            'summary' => self::summarizeSdp($sdp),
        ];
    }

    private static function summarizeSdp(string $sdp): array
    {
        $trimmed = trim($sdp);
        if ($trimmed === '') {
            return [];
        }

        preg_match_all('/^m=([a-z]+)/mi', $trimmed, $mediaMatches);
        preg_match_all('/^a=rtpmap:\d+\s+([^\/\s]+)/mi', $trimmed, $codecMatches);

        $media = array_values(array_unique(array_map('strtolower', $mediaMatches[1] ?? [])));
        $codecs = array_values(array_unique(array_map('strtoupper', $codecMatches[1] ?? [])));
        sort($media);
        sort($codecs);

        return [
            'has_audio' => in_array('audio', $media, true),
            'has_video' => in_array('video', $media, true),
            'media' => $media,
            'codecs' => $codecs,
            'direction' => self::extractDirection($trimmed),
            'ice_candidates' => preg_match_all('/^a=candidate:/mi', $trimmed) ?: 0,
            'ice_lite' => str_contains(strtolower($trimmed), 'a=ice-lite'),
            'trickle' => str_contains(strtolower($trimmed), 'a=ice-options:trickle'),
        ];
    }

    private static function extractDirection(string $sdp): ?string
    {
        foreach (['sendrecv', 'sendonly', 'recvonly', 'inactive'] as $direction) {
            if (preg_match('/^a=' . preg_quote($direction, '/') . '$/mi', $sdp)) {
                return $direction;
            }
        }

        return null;
    }

    private static function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }

    private static function sessionDirectory(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'whatsapp-calling' . DIRECTORY_SEPARATOR . 'sessions';
    }

    private static function sessionFile(string $callId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $callId) ?: 'unknown_call';
        return self::sessionDirectory() . DIRECTORY_SEPARATOR . $safe . '.json';
    }

    private static function log(string $event, array $context = []): void
    {
        $payload = [
            'at' => date('c'),
            'event' => $event,
            'context' => $context,
        ];

        error_log('[meta_calling] ' . $event . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @file_put_contents(
            WhatsAppConfig::callingLogFile(),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND
        );
    }
}
