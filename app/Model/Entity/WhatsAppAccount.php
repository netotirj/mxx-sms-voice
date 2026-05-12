<?php

namespace App\Model\Entity;

use App\Config\TelephonyConfig;
use App\Service\SecretBox;
use App\Support\RequestCache;
use App\Utils\TenancyHelper;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppAccount
{
    public static function createOrUpdateConnection(array $data): array
    {
        self::ensureVoiceColumns();

        $phoneNumberId = trim((string)($data['phone_number_id'] ?? ''));
        if ($phoneNumberId === '') {
            throw new \InvalidArgumentException('ID do número Meta é obrigatório.');
        }

        $existing = (new Database('whatsapp_accounts'))
            ->select('phone_number_id = :phone_number_id', [
                ':phone_number_id' => $phoneNumberId,
            ], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ((string)($existing['tenancy_id'] ?? '') !== (string)($data['tenancy_id'] ?? '')) {
                throw new \RuntimeException('Este número já está conectado em outra tenancy do sistema.');
            }

            (new Database('whatsapp_accounts'))->update('id = :id', [
                'user_id' => (int)$data['user_id'],
                'label' => $data['label'],
                'waba_id' => $data['waba_id'] ?? null,
                'business_id' => $data['business_id'] ?? null,
                'display_phone_number' => $data['display_phone_number'],
                'access_token' => SecretBox::encrypt($data['access_token']),
                'app_secret' => SecretBox::encrypt($data['app_secret'] ?? null),
                'verify_token' => $data['verify_token'] ?? null,
                'status' => $data['status'] ?? 'active',
                'updated_at' => date('Y-m-d H:i:s'),
            ], [':id' => (int)$existing['id']]);

            return [
                'id' => (int)$existing['id'],
                'created' => false,
            ];
        }

        return [
            'id' => self::create($data),
            'created' => true,
        ];
    }

    public static function create(array $data): int
    {
        self::ensureVoiceColumns();

        return (int) (new Database('whatsapp_accounts'))->insert([
            'tenancy_id' => $data['tenancy_id'],
            'user_id' => $data['user_id'],
            'label' => $data['label'],
            'waba_id' => $data['waba_id'] ?? null,
            'business_id' => $data['business_id'] ?? null,
            'phone_number_id' => $data['phone_number_id'],
            'display_phone_number' => $data['display_phone_number'],
            'access_token' => SecretBox::encrypt($data['access_token']),
            'app_secret' => SecretBox::encrypt($data['app_secret'] ?? null),
            'verify_token' => $data['verify_token'] ?? null,
            'status' => $data['status'] ?? 'active',
            'voice_enabled' => !empty($data['voice_enabled']) ? 1 : 0,
            'voice_status' => $data['voice_status'] ?? 'inactive',
            'voice_activated_at' => $data['voice_activated_at'] ?? null,
            'voice_activation_payload' => $data['voice_activation_payload'] ?? null,
            'voice_activation_last_attempt_at' => $data['voice_activation_last_attempt_at'] ?? null,
            'voice_activation_requested_by_user_id' => $data['voice_activation_requested_by_user_id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function listForUser(array $user): array
    {
        self::ensureVoiceColumns();
        $where = TenancyHelper::applySecurityFilter('wa.status = :status', $user, 'user_id', 'wa');

        return (new Database('whatsapp_accounts wa LEFT JOIN whatsapp_numbers wn ON wn.whatsapp_account_id = wa.id AND wn.company_id = wa.tenancy_id'))
            ->select($where, [':status' => 'active'], 'wa.id DESC', '', [
                'wa.id',
                'wa.tenancy_id',
                'wa.user_id',
                'wa.label',
                'wa.display_phone_number',
                'wa.status',
                'wa.profile_picture_url',
                'wa.profile_about',
                'wa.profile_description',
                'wa.profile_email',
                'wa.profile_website',
                'wa.profile_address',
                'wa.profile_vertical',
                'wa.profile_updated_at',
                'wa.profile_last_error',
                'wa.voice_enabled',
                'wa.voice_status',
                'wa.voice_activated_at',
                'wa.voice_activation_last_attempt_at',
                'wa.created_at',
                'wa.updated_at',
                'wn.id AS number_id',
                'wn.internal_label',
                'wn.display_name_meta',
                'wn.display_name',
                'wn.display_name_status',
                'wn.status AS number_status',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function listSupportVisibleForUser(array $user): array
    {
        self::ensureVoiceColumns();
        [$where, $params] = self::supportScope($user, 'wa');
        $where = "({$where}) AND wa.status = :status";
        $params[':status'] = 'active';

        return (new Database('whatsapp_accounts wa LEFT JOIN whatsapp_numbers wn ON wn.whatsapp_account_id = wa.id AND wn.company_id = wa.tenancy_id'))
            ->select($where, $params, 'wa.id DESC', '', [
                'wa.id',
                'wa.tenancy_id',
                'wa.user_id',
                'wa.label',
                'wa.display_phone_number',
                'wa.status',
                'wa.profile_picture_url',
                'wa.profile_about',
                'wa.profile_description',
                'wa.profile_email',
                'wa.profile_website',
                'wa.profile_address',
                'wa.profile_vertical',
                'wa.profile_updated_at',
                'wa.profile_last_error',
                'wa.voice_enabled',
                'wa.voice_status',
                'wa.voice_activated_at',
                'wa.voice_activation_last_attempt_at',
                'wa.created_at',
                'wa.updated_at',
                'wn.id AS number_id',
                'wn.internal_label',
                'wn.display_name_meta',
                'wn.display_name',
                'wn.display_name_status',
                'wn.status AS number_status',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getForUser(int $id, array $user): ?array
    {
        self::ensureVoiceColumns();
        $cacheKey = 'whatsapp_account.user.' . (int)($user['id'] ?? 0) . '.' . (string)($user['tenancy_id'] ?? '') . '.' . $id;

        return RequestCache::remember($cacheKey, function () use ($id, $user): ?array {
            $where = TenancyHelper::applySecurityFilter('id = :id AND status = :status', $user, 'user_id', 'whatsapp_accounts');
            $row = (new Database('whatsapp_accounts'))
                ->select($where, [':id' => $id, ':status' => 'active'], '', '1')
                ->fetch(PDO::FETCH_ASSOC);

            return self::withOpenSecrets($row ?: null);
        });
    }

    public static function getSupportVisibleForUser(int $id, array $user): ?array
    {
        self::ensureVoiceColumns();
        $cacheKey = 'whatsapp_account.support_visible.' . (int)($user['id'] ?? 0) . '.' . (string)($user['tenancy_id'] ?? '') . '.' . $id;

        return RequestCache::remember($cacheKey, function () use ($id, $user): ?array {
            [$where, $params] = self::supportScope($user, 'whatsapp_accounts');
            $where = "({$where}) AND id = :id AND status = :status";
            $params[':id'] = $id;
            $params[':status'] = 'active';

            $row = (new Database('whatsapp_accounts'))
                ->select($where, $params, '', '1')
                ->fetch(PDO::FETCH_ASSOC);

            return self::withOpenSecrets($row ?: null);
        });
    }

    public static function getByPhoneNumberId(string $phoneNumberId): ?array
    {
        self::ensureVoiceColumns();
        $cacheKey = 'whatsapp_account.phone_number.' . $phoneNumberId;

        return RequestCache::remember($cacheKey, function () use ($phoneNumberId): ?array {
            $row = (new Database('whatsapp_accounts'))
                ->select(
                    'phone_number_id = :phone_number_id AND status = :status',
                    [
                        ':phone_number_id' => $phoneNumberId,
                        ':status' => 'active',
                    ],
                    '',
                    '1'
                )
                ->fetch(PDO::FETCH_ASSOC);

            return self::withOpenSecrets($row ?: null);
        });
    }

    public static function getById(int $id): ?array
    {
        self::ensureVoiceColumns();
        $cacheKey = 'whatsapp_account.id.' . $id;

        return RequestCache::remember($cacheKey, function () use ($id): ?array {
            $row = (new Database('whatsapp_accounts'))
                ->select('id = :id AND status = :status', [
                    ':id' => $id,
                    ':status' => 'active',
                ], '', '1')
                ->fetch(PDO::FETCH_ASSOC);

            return self::withOpenSecrets($row ?: null);
        });
    }

    public static function updateBusinessProfile(int $id, array $user, array $values): bool
    {
        self::ensureVoiceColumns();
        if (!self::getForUser($id, $user)) {
            return false;
        }

        $values['updated_at'] = date('Y-m-d H:i:s');
        return (new Database('whatsapp_accounts'))->update('id = :id', $values, [':id' => $id]);
    }

    public static function getSupportAccount(): ?array
    {
        self::ensureVoiceColumns();
        $configuredId = (int) TelephonyConfig::env('WHATSAPP_SUPPORT_ACCOUNT_ID', 0);
        if ($configuredId > 0) {
            $row = (new Database('whatsapp_accounts'))
                ->select('id = :id AND status = :status', [':id' => $configuredId, ':status' => 'active'], '', '1')
                ->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                return self::withOpenSecrets($row);
            }
        }

        $sql = "
            SELECT wa.*
            FROM whatsapp_accounts wa
            INNER JOIN users u ON u.id = wa.user_id
            WHERE wa.status = 'active'
              AND u.user_function = 'super_admin'
            ORDER BY wa.id ASC
            LIMIT 1
        ";

        $row = (new Database('whatsapp_accounts'))->execute($sql)->fetch(PDO::FETCH_ASSOC);
        return self::withOpenSecrets($row ?: null);
    }

    private static function withOpenSecrets(?array $row): ?array
    {
        if (!$row) {
            return null;
        }

        $row = self::withNumberContext($row);
        $row['access_token'] = SecretBox::decrypt($row['access_token'] ?? null);
        $row['app_secret'] = SecretBox::decrypt($row['app_secret'] ?? null);

        return $row;
    }

    private static function withNumberContext(array $row): array
    {
        $accountId = (int)($row['id'] ?? 0);
        $tenancyId = (string)($row['tenancy_id'] ?? '');
        if ($accountId <= 0 || $tenancyId === '') {
            return $row;
        }

        $number = (new Database('whatsapp_numbers'))
            ->select(
                'whatsapp_account_id = :account_id AND company_id = :tenancy_id',
                [
                    ':account_id' => $accountId,
                    ':tenancy_id' => $tenancyId,
                ],
                'id DESC',
                '1',
                [
                    'id AS number_id',
                    'internal_label',
                    'display_name_meta',
                    'display_name',
                    'display_name_status',
                    'status AS number_status',
                    'voice_requested',
                ]
            )
            ->fetch(PDO::FETCH_ASSOC);

        if (!$number) {
            return $row;
        }

        foreach ($number as $key => $value) {
            if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
                $row[$key] = $value;
            }
        }

        return $row;
    }

    public static function ensureVoiceColumns(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $db = new Database();
        $rows = $db->execute("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'whatsapp_accounts'
              AND COLUMN_NAME IN (
                'voice_enabled',
                'voice_status',
                'voice_activated_at',
                'voice_activation_payload',
                'voice_activation_last_attempt_at',
                'voice_activation_requested_by_user_id'
              )
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $missing = array_diff([
            'voice_enabled',
            'voice_status',
            'voice_activated_at',
            'voice_activation_payload',
            'voice_activation_last_attempt_at',
            'voice_activation_requested_by_user_id',
        ], $rows);

        if ($missing !== []) {
            $parts = [];
            if (in_array('voice_enabled', $missing, true)) {
                $parts[] = 'ADD COLUMN voice_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status';
            }
            if (in_array('voice_status', $missing, true)) {
                $parts[] = "ADD COLUMN voice_status VARCHAR(32) NOT NULL DEFAULT 'inactive' AFTER voice_enabled";
            }
            if (in_array('voice_activated_at', $missing, true)) {
                $parts[] = 'ADD COLUMN voice_activated_at DATETIME NULL AFTER voice_status';
            }
            if (in_array('voice_activation_payload', $missing, true)) {
                $parts[] = 'ADD COLUMN voice_activation_payload LONGTEXT NULL AFTER voice_activated_at';
            }
            if (in_array('voice_activation_last_attempt_at', $missing, true)) {
                $parts[] = 'ADD COLUMN voice_activation_last_attempt_at DATETIME NULL AFTER voice_activation_payload';
            }
            if (in_array('voice_activation_requested_by_user_id', $missing, true)) {
                $parts[] = 'ADD COLUMN voice_activation_requested_by_user_id INT UNSIGNED NULL AFTER voice_activation_last_attempt_at';
            }

            $db->execute('ALTER TABLE whatsapp_accounts ' . implode(', ', $parts));
        }

        $ensured = true;
    }

    private static function supportScope(array $user, string $alias = 'wa'): array
    {
        if (TenancyHelper::isSuperAdmin($user)) {
            return ['1=1', []];
        }

        $role = self::normalizedRole($user);
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

        return [
            TenancyHelper::applySecurityFilter('', $user, 'user_id', $alias),
            [],
        ];
    }

    private static function normalizedRole(array $user): string
    {
        return strtolower(trim((string)($user['user_function'] ?? $user['function'] ?? '')));
    }
}
