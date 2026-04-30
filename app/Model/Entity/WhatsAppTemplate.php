<?php

namespace App\Model\Entity;

use App\Utils\TenancyHelper;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppTemplate
{
    public static function create(array $data): int
    {
        return (int)(new Database('whatsapp_templates'))->insert([
            'tenancy_id' => $data['tenancy_id'],
            'user_id' => (int)$data['user_id'],
            'name' => $data['name'],
            'language' => $data['language'] ?? 'pt_BR',
            'category' => $data['category'] ?? 'MARKETING',
            'body' => $data['body'] ?? null,
            'components' => isset($data['components'])
                ? json_encode($data['components'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'status' => $data['status'] ?? 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function listForUser(array $user): array
    {
        $where = TenancyHelper::applySecurityFilter('', $user, 'user_id', 'whatsapp_templates');

        return (new Database('whatsapp_templates'))
            ->select($where, [], 'id DESC')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getForUser(int $id, array $user): ?array
    {
        $where = TenancyHelper::applySecurityFilter('id = :id', $user, 'user_id', 'whatsapp_templates');
        $row = (new Database('whatsapp_templates'))
            ->select($where, [':id' => $id], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function getByNameForUser(string $name, string $language, array $user): ?array
    {
        $where = TenancyHelper::applySecurityFilter(
            'name = :name AND language = :language',
            $user,
            'user_id',
            'whatsapp_templates'
        );

        $row = (new Database('whatsapp_templates'))
            ->select($where, [
                ':name' => $name,
                ':language' => $language,
            ], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function getByNameForTenant(string $name, string $language, string $tenancyId): ?array
    {
        $row = (new Database('whatsapp_templates'))
            ->select(
                'tenancy_id = :tenancy_id AND name = :name AND language = :language',
                [
                    ':tenancy_id' => $tenancyId,
                    ':name' => $name,
                    ':language' => $language,
                ],
                '',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function deleteForUser(int $id, array $user): bool
    {
        if (!self::getForUser($id, $user)) {
            return false;
        }

        return (new Database('whatsapp_templates'))->delete('id = :id', [':id' => $id]);
    }
}
