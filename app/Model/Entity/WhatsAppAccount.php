<?php

namespace App\Model\Entity;

use App\Config\TelephonyConfig;
use App\Service\SecretBox;
use App\Utils\TenancyHelper;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppAccount
{
    public static function create(array $data): int
    {
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
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function listForUser(array $user): array
    {
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
        $where = TenancyHelper::applySecurityFilter('id = :id AND status = :status', $user, 'user_id', 'whatsapp_accounts');
        $row = (new Database('whatsapp_accounts'))
            ->select($where, [':id' => $id, ':status' => 'active'], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return self::withOpenSecrets($row ?: null);
    }

    public static function getSupportVisibleForUser(int $id, array $user): ?array
    {
        [$where, $params] = self::supportScope($user, 'whatsapp_accounts');
        $where = "({$where}) AND id = :id AND status = :status";
        $params[':id'] = $id;
        $params[':status'] = 'active';

        $row = (new Database('whatsapp_accounts'))
            ->select($where, $params, '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return self::withOpenSecrets($row ?: null);
    }

    public static function getByPhoneNumberId(string $phoneNumberId): ?array
    {
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
    }

    public static function getById(int $id): ?array
    {
        $row = (new Database('whatsapp_accounts'))
            ->select('id = :id AND status = :status', [
                ':id' => $id,
                ':status' => 'active',
            ], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return self::withOpenSecrets($row ?: null);
    }

    public static function updateBusinessProfile(int $id, array $user, array $values): bool
    {
        if (!self::getForUser($id, $user)) {
            return false;
        }

        $values['updated_at'] = date('Y-m-d H:i:s');
        return (new Database('whatsapp_accounts'))->update('id = :id', $values, [':id' => $id]);
    }

    public static function getSupportAccount(): ?array
    {
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

        $row['access_token'] = SecretBox::decrypt($row['access_token'] ?? null);
        $row['app_secret'] = SecretBox::decrypt($row['app_secret'] ?? null);

        return $row;
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
