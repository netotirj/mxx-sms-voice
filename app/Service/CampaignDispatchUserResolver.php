<?php

namespace App\Service;

use App\Model\Entity\UserSearch;

class CampaignDispatchUserResolver
{
    public static function resolve(string $tenancyId, int $userId): ?array
    {
        $user = UserSearch::getUserById($tenancyId, $userId);
        if (!$user) {
            return null;
        }

        return [
            'id' => (int)$user['id'],
            'tenancy_id' => (string)$user['tenancy_id'],
            'email' => (string)($user['email'] ?? ''),
            'name' => (string)($user['name'] ?? ''),
            'function' => (string)($user['user_function'] ?? ''),
            'user_function' => (string)($user['user_function'] ?? ''),
            'role_id' => (int)($user['role_id'] ?? 0),
            'account_code' => $user['account_code'] ?? null,
        ];
    }
}
