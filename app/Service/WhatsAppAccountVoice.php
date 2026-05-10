<?php

namespace App\Service;

use App\Model\Entity\WhatsAppAccount;
use Throwable;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppAccountVoice
{
    public static function ensureSchema(): void
    {
        WhatsAppAccount::ensureVoiceColumns();
    }

    public static function syncStatus(array $account, ?int $requestedByUserId = null): array
    {
        self::ensureSchema();

        $response = (new MetaWhatsAppCloudApi())->getCallingSettings(
            (string)($account['access_token'] ?? ''),
            (string)($account['phone_number_id'] ?? '')
        );

        self::persistMetaResponse($account, $response, $requestedByUserId, false);

        return $response;
    }

    public static function activateIfRequested(array $account, ?int $requestedByUserId = null): array
    {
        self::ensureSchema();

        if (!empty($account['voice_enabled']) && self::normalizeLocalStatus((string)($account['voice_status'] ?? '')) === 'active') {
            return [
                'ok' => true,
                'status' => 200,
                'data' => [
                    'skipped' => true,
                    'reason' => 'already_active',
                ],
                'error' => null,
            ];
        }

        $latest = self::syncStatus($account, $requestedByUserId);
        if ($latest['ok'] && self::normalizeMetaSettingsStatus($latest['data'] ?? []) === 'active') {
            return $latest;
        }

        $response = (new MetaWhatsAppCloudApi())->updateCallingSettings(
            (string)($account['access_token'] ?? ''),
            (string)($account['phone_number_id'] ?? ''),
            [
                'status' => 'ENABLED',
                'call_icon_visibility' => 'DEFAULT',
                'callback_permission_status' => 'ENABLED',
            ]
        );

        self::persistMetaResponse($account, $response, $requestedByUserId, true);

        if ($response['ok']) {
            try {
                $fresh = WhatsAppAccount::getById((int)($account['id'] ?? 0)) ?: $account;
                return self::syncStatus($fresh, $requestedByUserId);
            } catch (Throwable $e) {
                self::log('voice.sync_after_activation_failed', [
                    'account_id' => (int)($account['id'] ?? 0),
                    'phone_number_id' => (string)($account['phone_number_id'] ?? ''),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $response;
    }

    public static function friendlyMetaMessage(array $response, string $fallback = 'Não foi possível ativar as chamadas de voz deste número agora.'): string
    {
        $error = trim((string)($response['error'] ?? ''));
        $errorLower = strtolower($error);
        $errorCode = (string)($response['error_code'] ?? '');
        $errorSubcode = (string)($response['error_subcode'] ?? '');

        if (
            $errorCode === '138000'
            || $errorSubcode === '138000'
            || str_contains($errorLower, 'calling api not enabled')
            || str_contains($errorLower, 'calling not enabled')
        ) {
            return 'A Meta ainda está com a opção "Permitir ligações de voz" desativada para este número. Ative esse recurso no painel do WhatsApp Manager e tente novamente.';
        }

        if (
            str_contains($errorLower, 'permission')
            || str_contains($errorLower, 'authorization')
            || str_contains($errorLower, 'advanced access')
        ) {
            return 'A conta da Meta usada neste número ainda não tem permissão para ativar chamadas de voz. Revise as permissões do app e tente novamente.';
        }

        if (
            str_contains($errorLower, 'not eligible')
            || str_contains($errorLower, 'not available')
            || str_contains($errorLower, 'cannot be enabled')
        ) {
            return 'Este número ainda não está elegível para chamadas de voz na Meta. Confirme a disponibilidade do recurso para essa linha e tente novamente depois.';
        }

        if (str_contains($errorLower, 'webhook')) {
            return 'As chamadas de voz não puderam ser ativadas porque a configuração do webhook deste número ainda não está pronta. Revise a integração e tente novamente.';
        }

        return $fallback;
    }

    public static function updateLocalLabel(int $accountId, array $user, string $label): bool
    {
        self::ensureSchema();

        $account = WhatsAppAccount::getForUser($accountId, $user);
        if (!$account) {
            return false;
        }

        $label = trim($label);
        if ($label === '') {
            return false;
        }

        (new Database('whatsapp_accounts'))->update('id = :id', [
            'label' => $label,
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $accountId]);

        if (!empty($account['number_id'])) {
            (new Database('whatsapp_numbers'))->update('id = :id', [
                'internal_label' => $label,
                'updated_at' => date('Y-m-d H:i:s'),
            ], [':id' => (int)$account['number_id']]);
        }

        return true;
    }

    public static function updateMetaDisplayName(int $accountId, array $user, string $displayName): bool
    {
        self::ensureSchema();

        $account = WhatsAppAccount::getForUser($accountId, $user);
        if (!$account) {
            return false;
        }

        $displayName = trim($displayName);
        if ($displayName === '') {
            return false;
        }

        $api = new MetaWhatsAppCloudApi();
        $result = $api->updatePhoneNumberDisplayName(
            (string)($account['access_token'] ?? ''),
            (string)($account['phone_number_id'] ?? ''),
            $displayName
        );

        if (!$result['ok']) {
            throw new \RuntimeException($result['error'] ?: 'A Meta não aceitou a atualização do nome exibido.');
        }

        if (!empty($account['number_id'])) {
            (new Database('whatsapp_numbers'))->update('id = :id', [
                'display_name_meta' => $displayName,
                'display_name' => $displayName,
                'display_name_status' => 'PENDING_REVIEW',
                'display_name_submitted_at' => date('Y-m-d H:i:s'),
                'display_name_last_checked_at' => date('Y-m-d H:i:s'),
                'last_error' => 'Nome comercial enviado para análise da Meta.',
                'updated_at' => date('Y-m-d H:i:s'),
            ], [':id' => (int)$account['number_id']]);
        }

        self::log('meta_display_name.updated', [
            'account_id' => $accountId,
            'phone_number_id' => (string)($account['phone_number_id'] ?? ''),
            'display_name' => $displayName,
            'requested_by_user_id' => (int)($user['id'] ?? 0),
        ]);

        return true;
    }

    private static function persistMetaResponse(array $account, array $response, ?int $requestedByUserId, bool $attemptedActivation): void
    {
        $status = $response['ok']
            ? self::normalizeMetaSettingsStatus($response['data'] ?? [])
            : self::classifyErrorStatus($response);

        $now = date('Y-m-d H:i:s');
        $isActive = $status === 'active';
        $payload = [
            'attempted_activation' => $attemptedActivation,
            'meta' => $response['data'] ?? [],
            'ok' => (bool)($response['ok'] ?? false),
            'http_status' => (int)($response['status'] ?? 0),
            'error' => $response['error'] ?? null,
            'error_code' => $response['error_code'] ?? null,
            'error_subcode' => $response['error_subcode'] ?? null,
            'saved_at' => $now,
        ];

        (new Database('whatsapp_accounts'))->update('id = :id', [
            'voice_enabled' => $isActive ? 1 : 0,
            'voice_status' => $status,
            'voice_activated_at' => $isActive ? ($account['voice_activated_at'] ?? $now) : null,
            'voice_activation_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'voice_activation_last_attempt_at' => $now,
            'voice_activation_requested_by_user_id' => $requestedByUserId ?: null,
            'updated_at' => $now,
        ], [':id' => (int)($account['id'] ?? 0)]);

        self::log('voice.status.persisted', [
            'account_id' => (int)($account['id'] ?? 0),
            'phone_number_id' => (string)($account['phone_number_id'] ?? ''),
            'voice_status' => $status,
            'voice_enabled' => $isActive,
            'requested_by_user_id' => $requestedByUserId,
            'attempted_activation' => $attemptedActivation,
            'meta_status' => $response['status'] ?? 0,
            'meta_ok' => $response['ok'] ?? false,
            'meta_error' => $response['error'] ?? null,
        ]);
    }

    private static function normalizeMetaSettingsStatus(array $data): string
    {
        $calling = is_array($data['calling'] ?? null) ? $data['calling'] : $data;
        $status = strtoupper(trim((string)($calling['status'] ?? '')));
        if ($status === 'ENABLED') {
            return 'active';
        }
        if ($status === 'DISABLED') {
            return 'inactive';
        }

        return 'pending';
    }

    private static function classifyErrorStatus(array $response): string
    {
        $error = strtolower(trim((string)($response['error'] ?? '')));
        $errorCode = (string)($response['error_code'] ?? '');
        $errorSubcode = (string)($response['error_subcode'] ?? '');

        if (
            str_contains($error, 'calling cannot be enabled')
            || str_contains($error, 'calling not enabled')
            || str_contains($error, 'not eligible')
            || str_contains($error, 'not available')
            || str_contains($error, 'webhook')
            || $errorCode === '138000'
            || $errorSubcode === '138000'
        ) {
            return 'unavailable';
        }

        if (
            str_contains($error, 'permission')
            || str_contains($error, 'authorization')
            || str_contains($error, 'advanced access')
        ) {
            return 'unavailable';
        }

        return 'error';
    }

    private static function normalizeLocalStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return $status !== '' ? $status : 'inactive';
    }

    private static function log(string $event, array $context): void
    {
        error_log(json_encode([
            'event' => 'whatsapp_account_voice',
            'action' => $event,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
