<?php

namespace App\Service;

use App\Model\Entity\WhatsAppAccount;
use App\Model\Entity\WhatsAppConversation;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class CallPermissionService
{
    private const TABLE = 'whatsapp_call_permissions';
    private const STATUS_GRANTED = 'granted';
    private const STATUS_TEMPORARY = 'temporary';
    private const STATUS_PENDING = 'pending';
    private const STATUS_DENIED = 'denied';
    private const STATUS_EXPIRED = 'expired';
    private const STATUS_NO_PERMISSION = 'no_permission';

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            (new Database())->execute("
                CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    tenancy_id VARCHAR(64) NOT NULL,
                    user_id INT UNSIGNED NULL,
                    account_id INT UNSIGNED NOT NULL,
                    contact_id INT UNSIGNED NULL,
                    phone_number VARCHAR(32) NOT NULL,
                    permission_status VARCHAR(32) NOT NULL DEFAULT 'no_permission',
                    permission_requested_at DATETIME NULL,
                    permission_approved_at DATETIME NULL,
                    permission_expires_at DATETIME NULL,
                    permission_request_wamid VARCHAR(128) NULL,
                    permission_response_source VARCHAR(32) NULL,
                    permission_context_id VARCHAR(128) NULL,
                    is_permanent TINYINT(1) NOT NULL DEFAULT 0,
                    last_error_code VARCHAR(32) NULL,
                    last_error_message VARCHAR(255) NULL,
                    meta_payload LONGTEXT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY unq_whatsapp_call_permissions_account_phone (account_id, phone_number),
                    KEY idx_whatsapp_call_permissions_contact (contact_id),
                    KEY idx_whatsapp_call_permissions_status (permission_status),
                    KEY idx_whatsapp_call_permissions_tenancy (tenancy_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Throwable $e) {
            error_log('[call_permission_schema] ' . $e->getMessage());
        }
    }

    public static function requestCallPermission(array $account, int $contactId, string $phoneNumber, ?string $body = null): array
    {
        self::ensureSchema();

        $normalizedPhone = self::normalizePhone($phoneNumber);
        if ($normalizedPhone === '') {
            return self::errorResult('Número do contato inválido para solicitar permissão de chamada.');
        }

        $result = (new MetaWhatsAppCloudApi())->sendCallPermissionRequest(
            (string)$account['access_token'],
            (string)$account['phone_number_id'],
            $normalizedPhone,
            $body
        );

        $wamid = (string)($result['data']['messages'][0]['id'] ?? '');
        if ($result['ok']) {
            self::saveCallPermissionStatus(
                $contactId,
                $normalizedPhone,
                self::STATUS_PENDING,
                null,
                array_merge($result['data'] ?? [], [
                    'request_body' => $body,
                    'request_wamid' => $wamid,
                ]),
                [
                    'tenancy_id' => (string)($account['tenancy_id'] ?? ''),
                    'user_id' => (int)($account['user_id'] ?? 0),
                    'account_id' => (int)($account['id'] ?? 0),
                    'requested_at' => date('Y-m-d H:i:s'),
                    'permission_request_wamid' => $wamid !== '' ? $wamid : null,
                ]
            );
            self::log('permission.request.sent', [
                'account_id' => (int)($account['id'] ?? 0),
                'contact_id' => $contactId,
                'phone_number' => $normalizedPhone,
                'wamid' => $wamid,
                'response' => $result['data'] ?? [],
            ]);
        } else {
            self::saveCallPermissionStatus(
                $contactId,
                $normalizedPhone,
                self::STATUS_NO_PERMISSION,
                null,
                $result['data'] ?? [],
                [
                    'tenancy_id' => (string)($account['tenancy_id'] ?? ''),
                    'user_id' => (int)($account['user_id'] ?? 0),
                    'account_id' => (int)($account['id'] ?? 0),
                    'last_error_code' => (string)($result['error_subcode'] ?: $result['error_code'] ?: ''),
                    'last_error_message' => (string)($result['error'] ?? ''),
                ]
            );
            self::log('permission.request.failed', [
                'account_id' => (int)($account['id'] ?? 0),
                'contact_id' => $contactId,
                'phone_number' => $normalizedPhone,
                'status' => $result['status'] ?? 0,
                'error' => $result['error'] ?? null,
                'error_code' => $result['error_code'] ?? null,
                'error_subcode' => $result['error_subcode'] ?? null,
                'response' => $result['data'] ?? [],
            ]);
        }

        return $result;
    }

    public static function hasApprovedCallPermission(int $contactId): bool
    {
        self::ensureSchema();
        $record = self::getByContactId($contactId);
        if (!$record) {
            return false;
        }

        return self::recordAllowsCall($record);
    }

    public static function saveCallPermissionStatus(
        int $contactId,
        string $phoneNumber,
        string $status,
        ?string $expiresAt,
        array $metaPayload = [],
        array $extra = []
    ): void {
        self::ensureSchema();

        $status = self::normalizeStatus($status);
        $phoneNumber = self::normalizePhone($phoneNumber);
        if ($phoneNumber === '') {
            return;
        }

        $existing = self::findRecord((int)($extra['account_id'] ?? 0), $phoneNumber);
        $now = date('Y-m-d H:i:s');

        $values = [
            'tenancy_id' => (string)($extra['tenancy_id'] ?? ($existing['tenancy_id'] ?? '')),
            'user_id' => isset($extra['user_id']) ? (int)$extra['user_id'] : (isset($existing['user_id']) ? (int)$existing['user_id'] : null),
            'account_id' => (int)($extra['account_id'] ?? ($existing['account_id'] ?? 0)),
            'contact_id' => $contactId > 0 ? $contactId : (int)($existing['contact_id'] ?? 0),
            'phone_number' => $phoneNumber,
            'permission_status' => $status,
            'permission_requested_at' => $extra['requested_at'] ?? ($existing['permission_requested_at'] ?? null),
            'permission_approved_at' => $extra['approved_at'] ?? ($existing['permission_approved_at'] ?? null),
            'permission_expires_at' => $expiresAt,
            'permission_request_wamid' => array_key_exists('permission_request_wamid', $extra)
                ? $extra['permission_request_wamid']
                : ($existing['permission_request_wamid'] ?? null),
            'permission_response_source' => $extra['permission_response_source'] ?? ($existing['permission_response_source'] ?? null),
            'permission_context_id' => $extra['permission_context_id'] ?? ($existing['permission_context_id'] ?? null),
            'is_permanent' => !empty($extra['is_permanent']) ? 1 : 0,
            'last_error_code' => self::nullableString($extra['last_error_code'] ?? ($existing['last_error_code'] ?? null)),
            'last_error_message' => self::nullableString($extra['last_error_message'] ?? ($existing['last_error_message'] ?? null)),
            'meta_payload' => $metaPayload !== [] ? json_encode($metaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ($existing['meta_payload'] ?? null),
            'updated_at' => $now,
        ];

        if (in_array($status, [self::STATUS_GRANTED, self::STATUS_TEMPORARY], true) && empty($values['permission_approved_at'])) {
            $values['permission_approved_at'] = $now;
        }
        if ($status === self::STATUS_PENDING && empty($values['permission_requested_at'])) {
            $values['permission_requested_at'] = $now;
        }
        if (!$existing) {
            $values['created_at'] = $now;
            (new Database(self::TABLE))->insert($values);
        } else {
            unset($values['created_at']);
            (new Database(self::TABLE))->update('id = :id', $values, [':id' => (int)$existing['id']]);
        }

        self::emitSupportEvent((string)$values['tenancy_id'], [
            'account_id' => (int)$values['account_id'],
            'contact_id' => (int)$values['contact_id'],
            'phone_number' => $phoneNumber,
            'permission_status' => $status,
            'permission_requested_at' => $values['permission_requested_at'],
            'permission_approved_at' => $values['permission_approved_at'],
            'permission_expires_at' => $values['permission_expires_at'],
            'is_permanent' => (int)$values['is_permanent'],
            'last_error_code' => $values['last_error_code'],
            'last_error_message' => $values['last_error_message'],
        ]);

        self::log('permission.status.saved', [
            'account_id' => $values['account_id'],
            'contact_id' => $values['contact_id'],
            'phone_number' => $phoneNumber,
            'permission_status' => $status,
            'permission_expires_at' => $expiresAt,
        ]);
    }

    public static function handleCallPermissionWebhook(array $account, array $payload): bool
    {
        self::ensureSchema();

        $phoneNumber = self::normalizePhone((string)($payload['customer_phone_number'] ?? $payload['phone_number'] ?? $payload['from'] ?? ''));
        if ($phoneNumber === '') {
            self::log('permission.webhook.ignored', [
                'reason' => 'missing_phone_number',
                'payload' => $payload,
            ]);
            return false;
        }

        $response = strtolower(trim((string)($payload['response'] ?? '')));
        $source = strtolower(trim((string)($payload['response_source'] ?? '')));
        $isPermanent = !empty($payload['is_permanent']);
        $expiresAt = self::parseExpirationTimestamp($payload['expiration_timestamp'] ?? null);
        $status = self::STATUS_PENDING;

        if ($response === 'accept') {
            $status = $isPermanent ? self::STATUS_GRANTED : self::STATUS_TEMPORARY;
        } elseif ($response === 'reject') {
            $status = self::STATUS_DENIED;
            if ($source === 'automatic') {
                $status = self::STATUS_EXPIRED;
            }
        }

        $conversation = self::findConversationByAccountAndPhone((int)($account['id'] ?? 0), $phoneNumber);
        self::saveCallPermissionStatus(
            (int)($conversation['id'] ?? 0),
            $phoneNumber,
            $status,
            $expiresAt,
            $payload,
            [
                'tenancy_id' => (string)($account['tenancy_id'] ?? ''),
                'user_id' => (int)($account['user_id'] ?? 0),
                'account_id' => (int)($account['id'] ?? 0),
                'approved_at' => $response === 'accept' ? date('Y-m-d H:i:s') : null,
                'permission_response_source' => $source,
                'permission_context_id' => (string)($payload['context']['id'] ?? $payload['context_id'] ?? ''),
                'is_permanent' => $isPermanent,
                'last_error_code' => null,
                'last_error_message' => null,
            ]
        );

        self::log('permission.webhook.received', [
            'account_id' => (int)($account['id'] ?? 0),
            'contact_id' => (int)($conversation['id'] ?? 0),
            'phone_number' => $phoneNumber,
            'status' => $status,
            'response' => $response,
            'source' => $source,
            'is_permanent' => $isPermanent,
            'expires_at' => $expiresAt,
            'payload' => $payload,
        ]);

        return true;
    }

    public static function extractWebhookEntries(array $value): array
    {
        $entries = [];
        $candidates = [
            $value['user_preferences'] ?? null,
            $value['call_permissions'] ?? null,
            $value['permissions'] ?? null,
            $value['calls'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            foreach ($candidate as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (isset($item['customer_phone_number']) || isset($item['response']) || isset($item['expiration_timestamp'])) {
                    $entries[] = $item;
                }
            }
        }

        $messages = is_array($value['messages'] ?? null) ? $value['messages'] : [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $interactive = is_array($message['interactive'] ?? null) ? $message['interactive'] : [];
            $reply = is_array($interactive['call_permission_reply'] ?? null) ? $interactive['call_permission_reply'] : [];
            if (($interactive['type'] ?? null) !== 'call_permission_reply' || $reply === []) {
                continue;
            }

            $entries[] = [
                'customer_phone_number' => $message['from'] ?? '',
                'phone_number' => $message['from'] ?? '',
                'response' => $reply['response'] ?? '',
                'is_permanent' => !empty($reply['is_permanent']),
                'response_source' => $reply['response_source'] ?? '',
                'expiration_timestamp' => $reply['expiration_timestamp'] ?? null,
                'context' => is_array($message['context'] ?? null) ? $message['context'] : [],
                'context_id' => $message['context']['id'] ?? null,
                'meta_message_id' => $message['id'] ?? null,
                'raw_message' => $message,
            ];
        }

        if ($entries === [] && (isset($value['customer_phone_number']) || isset($value['response']))) {
            $entries[] = $value;
        }

        return $entries;
    }

    public static function syncPermissionState(array $account, int $contactId, string $phoneNumber): array
    {
        self::ensureSchema();

        $normalizedPhone = self::normalizePhone($phoneNumber);
        if ($normalizedPhone === '') {
            return self::errorResult('Número do contato inválido para consultar permissão de chamada.');
        }

        $result = (new MetaWhatsAppCloudApi())->getCallPermissions(
            (string)$account['access_token'],
            (string)$account['phone_number_id'],
            $normalizedPhone
        );

        $status = self::normalizeMetaPermissionState($result['data'] ?? []);
        $expiresAt = self::extractMetaExpiration($result['data'] ?? []);

        self::saveCallPermissionStatus(
            $contactId,
            $normalizedPhone,
            $status,
            $expiresAt,
            $result['data'] ?? [],
            [
                'tenancy_id' => (string)($account['tenancy_id'] ?? ''),
                'user_id' => (int)($account['user_id'] ?? 0),
                'account_id' => (int)($account['id'] ?? 0),
                'approved_at' => in_array($status, [self::STATUS_GRANTED, self::STATUS_TEMPORARY], true) ? date('Y-m-d H:i:s') : null,
                'is_permanent' => $status === self::STATUS_GRANTED,
                'last_error_code' => (string)($result['error_subcode'] ?: $result['error_code'] ?: ''),
                'last_error_message' => (string)($result['error'] ?? ''),
            ]
        );

        self::log('permission.state.synced', [
            'account_id' => (int)($account['id'] ?? 0),
            'contact_id' => $contactId,
            'phone_number' => $normalizedPhone,
            'status' => $status,
            'expires_at' => $expiresAt,
            'response' => $result['data'] ?? [],
        ]);

        return $result;
    }

    public static function ensureValidPermissionForOutbound(array $account, int $contactId, string $phoneNumber): array
    {
        self::ensureSchema();

        $record = $contactId > 0 ? self::getByContactId($contactId) : null;
        if ($record && self::recordAllowsCall($record)) {
            return [
                'allowed' => true,
                'status' => (string)$record['permission_status'],
                'record' => $record,
                'message' => 'Permissão de chamada aprovada.',
            ];
        }

        $sync = self::syncPermissionState($account, $contactId, $phoneNumber);
        $record = $contactId > 0 ? self::getByContactId($contactId) : self::findRecord((int)$account['id'], self::normalizePhone($phoneNumber));

        if ($record && self::recordAllowsCall($record)) {
            return [
                'allowed' => true,
                'status' => (string)$record['permission_status'],
                'record' => $record,
                'message' => 'Permissão de chamada aprovada.',
                'meta' => $sync,
            ];
        }

        return [
            'allowed' => false,
            'status' => (string)($record['permission_status'] ?? self::STATUS_NO_PERMISSION),
            'record' => $record,
            'message' => self::describeStatus((string)($record['permission_status'] ?? self::STATUS_NO_PERMISSION)),
            'meta' => $sync,
        ];
    }

    public static function markNoPermissionFromCallError(array $account, int $contactId, string $phoneNumber, array $result): void
    {
        self::saveCallPermissionStatus(
            $contactId,
            $phoneNumber,
            self::STATUS_NO_PERMISSION,
            null,
            $result['data'] ?? [],
            [
                'tenancy_id' => (string)($account['tenancy_id'] ?? ''),
                'user_id' => (int)($account['user_id'] ?? 0),
                'account_id' => (int)($account['id'] ?? 0),
                'last_error_code' => (string)($result['error_subcode'] ?: $result['error_code'] ?: ''),
                'last_error_message' => (string)($result['error'] ?? ''),
            ]
        );
    }

    public static function getByContactId(int $contactId): ?array
    {
        self::ensureSchema();
        if ($contactId <= 0) {
            return null;
        }

        $row = (new Database(self::TABLE))
            ->select('contact_id = :contact_id', [':contact_id' => $contactId], 'updated_at DESC', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function describeStatus(string $status): string
    {
        return match (self::normalizeStatus($status)) {
            self::STATUS_GRANTED => 'Permissão de chamada aprovada permanentemente.',
            self::STATUS_TEMPORARY => 'Permissão temporária de chamada aprovada.',
            self::STATUS_PENDING => 'Aguardando aprovação do cliente para chamadas.',
            self::STATUS_DENIED => 'O cliente negou a permissão de chamada.',
            self::STATUS_EXPIRED => 'A permissão de chamada expirou.',
            default => 'Este contato ainda não possui permissão de chamada aprovada.',
        };
    }

    private static function findRecord(int $accountId, string $phoneNumber): ?array
    {
        if ($accountId <= 0 || $phoneNumber === '') {
            return null;
        }

        $row = (new Database(self::TABLE))
            ->select(
                'account_id = :account_id AND phone_number = :phone_number',
                [
                    ':account_id' => $accountId,
                    ':phone_number' => $phoneNumber,
                ],
                '',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function findConversationByAccountAndPhone(int $accountId, string $phoneNumber): ?array
    {
        if ($accountId <= 0 || $phoneNumber === '') {
            return null;
        }

        return (new Database('whatsapp_conversations'))
            ->select(
                'account_id = :account_id AND contact_phone = :phone_number',
                [
                    ':account_id' => $accountId,
                    ':phone_number' => $phoneNumber,
                ],
                'updated_at DESC',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function normalizeMetaPermissionState(array $data): string
    {
        $status = strtolower(trim((string)($data['permission']['status'] ?? $data['status'] ?? '')));
        if ($status !== '') {
            return self::normalizeStatus($status);
        }

        $actions = is_array($data['actions'] ?? null) ? $data['actions'] : [];
        foreach ($actions as $action) {
            if (
                strtolower(trim((string)($action['action_name'] ?? ''))) === 'start_call'
                && !empty($action['can_perform_action'])
            ) {
                return self::STATUS_GRANTED;
            }
        }

        return self::STATUS_NO_PERMISSION;
    }

    private static function extractMetaExpiration(array $data): ?string
    {
        $timestamp = $data['permission']['expiration_time']
            ?? $data['permission']['expiration_timestamp']
            ?? $data['expiration_timestamp']
            ?? null;

        return self::parseExpirationTimestamp($timestamp);
    }

    private static function parseExpirationTimestamp(mixed $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        if (is_numeric($timestamp)) {
            $value = (int)$timestamp;
            if ($value > 0) {
                return date('Y-m-d H:i:s', $value);
            }
        }

        $text = trim((string)$timestamp);
        return $text !== '' ? $text : null;
    }

    private static function recordAllowsCall(array $record): bool
    {
        $status = self::normalizeStatus((string)($record['permission_status'] ?? ''));
        if (!in_array($status, [self::STATUS_GRANTED, self::STATUS_TEMPORARY], true)) {
            return false;
        }

        if ($status === self::STATUS_TEMPORARY) {
            $expiresAt = (string)($record['permission_expires_at'] ?? '');
            if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
                return false;
            }
        }

        return true;
    }

    private static function normalizeStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        return match ($normalized) {
            'granted', 'approved', 'permanent' => self::STATUS_GRANTED,
            'temporary', 'temporary_permission' => self::STATUS_TEMPORARY,
            'pending' => self::STATUS_PENDING,
            'denied', 'reject', 'rejected' => self::STATUS_DENIED,
            'expired' => self::STATUS_EXPIRED,
            default => self::STATUS_NO_PERMISSION,
        };
    }

    private static function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string)$value);
        return $text !== '' ? $text : null;
    }

    private static function errorResult(string $message): array
    {
        return [
            'ok' => false,
            'status' => 0,
            'data' => [],
            'error' => $message,
        ];
    }

    private static function log(string $event, array $context = []): void
    {
        error_log('[call_permission] ' . $event . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function emitSupportEvent(string $tenancyId, array $payload): void
    {
        if ($tenancyId === '') {
            return;
        }

        try {
            (new Database('whatsapp_support_events'))->insert([
                'tenancy_id' => $tenancyId,
                'event_type' => 'call.permission.updated',
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            self::log('permission.support_event_failed', [
                'tenancy_id' => $tenancyId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
