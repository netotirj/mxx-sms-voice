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
        $where = TenancyHelper::applySecurityFilter('', $user, 'user_id', 'whatsapp_accounts');

        return (new Database('whatsapp_accounts'))
            ->select($where, [], 'id DESC', '', [
                'id',
                'tenancy_id',
                'user_id',
                'label',
                'waba_id',
                'business_id',
                'phone_number_id',
                'display_phone_number',
                'status',
                'created_at',
                'updated_at',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getForUser(int $id, array $user): ?array
    {
        $where = TenancyHelper::applySecurityFilter('id = :id', $user, 'user_id', 'whatsapp_accounts');
        $row = (new Database('whatsapp_accounts'))
            ->select($where, [':id' => $id], '', '1')
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
}
