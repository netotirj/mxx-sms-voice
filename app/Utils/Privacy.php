<?php

namespace App\Utils;

class Privacy
{
    private const FULL_IP_ROLES = [
        'super_admin',
        'admin',
        'developer',
        'support_ticket_manager',
    ];

    public static function maskIp(?string $ipAddress): string
    {
        $ipAddress = trim((string)$ipAddress);
        if ($ipAddress === '') {
            return '';
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ipAddress);
            if (count($parts) === 4) {
                return $parts[0] . '.' . $parts[1] . '.xxx.xxx';
            }
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ipAddress);
            $visible = array_slice($parts, 0, 2);
            return implode(':', $visible) . ':xxxx:xxxx:xxxx:xxxx';
        }

        return 'xxx.xxx.xxx.xxx';
    }

    public static function canViewFullIp(?array $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));
        return in_array($role, self::FULL_IP_ROLES, true);
    }

    public static function disclosure(?string $ipAddress, ?array $user = null): array
    {
        $full = trim((string)$ipAddress);

        return [
            'ip_masked' => self::maskIp($full),
            'ip_full' => $full,
            'can_view_full_ip' => self::canViewFullIp($user),
        ];
    }
}
