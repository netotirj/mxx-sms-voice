<?php

namespace App\Service;

use App\Model\Entity\RegisterTenancies;
use App\Model\Entity\UserPlans;
use App\Model\Entity\UserSearch;
use App\Session\User as SessionUser;
use App\Support\RequestCache;
use App\Utils\TenancyHelper;

class AuthContext
{
    private const SESSION_ROOT = 'auth_context_cache';
    private const SESSION_TTL_SECONDS = 90;

    public static function current(bool $withPlanData = false): array
    {
        $variant = $withPlanData ? 'full' : 'light';

        return RequestCache::remember('auth_context.current.' . $variant, function () use ($withPlanData, $variant) {
            $sessionUser = SessionUser::getLogged() ?? [];
            if ($sessionUser === []) {
                return [];
            }

            $version = PermissionResolver::versionForUser($sessionUser);
            $cached = self::sessionGet($sessionUser, $variant);
            if (
                is_array($cached)
                && (string)($cached['_permission_version_key'] ?? '') === (string)($version['key'] ?? '')
            ) {
                return $cached;
            }

            $tenancyId = (string)($sessionUser['tenancy_id'] ?? '');
            $userId = (int)($sessionUser['id'] ?? 0);
            $isSuperAdmin = TenancyHelper::isSuperAdmin($sessionUser);
            $permissionContext = $isSuperAdmin
                ? [
                    'role_id' => (int)($sessionUser['role_id'] ?? 0),
                    'allowed_routes' => ['SUPERADMIN'],
                ]
                : PermissionResolver::getUserAccessContext($sessionUser);
            $profile = ($tenancyId !== '' && $userId > 0)
                ? (UserSearch::getUserById($tenancyId, $userId) ?? [])
                : [];
            $userBase = $profile ?: $sessionUser;
            $roleId = (int)($permissionContext['role_id'] ?? 0);
            $permissions = $isSuperAdmin
                ? ['SUPERADMIN']
                : (array)($permissionContext['allowed_routes'] ?? []);

            $canSwitchPlan = in_array(
                strtolower(trim((string)($userBase['user_function'] ?? $userBase['function'] ?? ''))),
                ['admin', 'super_admin'],
                true
            );

            $currentPlan = null;
            $userPlans = [];
            $planSummary = null;

            if ($withPlanData && $canSwitchPlan && $tenancyId !== '') {
                $userPlans = UserPlans::getAllActivePlansByUser($userId, $tenancyId);
                $planSummary = PlanRuntimeService::getDisplaySummary($tenancyId);
                $currentPlan = (int)($planSummary->plan_id ?? 0) ?: RegisterTenancies::getActivePlanId($tenancyId);
            }

            $context = [
                'session_user' => $sessionUser,
                'profile' => $profile,
                'user' => $userBase,
                'role_id' => $roleId,
                'permissions' => $permissions,
                'permission_versions' => $version,
                '_permission_version_key' => (string)($version['key'] ?? ''),
                'is_super_admin' => $isSuperAdmin,
                'can_switch_plan' => $canSwitchPlan,
                'current_plan_id' => $currentPlan,
                'user_plans' => $userPlans,
                'plan_summary' => $planSummary,
            ];

            self::sessionPut($sessionUser, $context, $variant);

            return $context;
        });
    }

    public static function invalidate(?string $tenancyId = null, ?int $userId = null): void
    {
        RequestCache::forget('auth_context');
        self::sessionForget();
        PermissionResolver::invalidate($tenancyId, $userId);
    }

    private static function sessionGet(array $sessionUser, string $variant): ?array
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }

        SessionUser::ensureSessionStarted();
        $root = $_SESSION[self::SESSION_ROOT] ?? null;
        $payload = is_array($root) ? ($root[$variant] ?? null) : null;
        if (!is_array($payload)) {
            return null;
        }

        $expiresAt = (int)($payload['expires_at'] ?? 0);
        $cacheUserId = (int)($payload['user_id'] ?? 0);
        $cacheTenancyId = (string)($payload['tenancy_id'] ?? '');

        if (
            $expiresAt < time()
            || $cacheUserId !== (int)($sessionUser['id'] ?? 0)
            || $cacheTenancyId !== (string)($sessionUser['tenancy_id'] ?? '')
        ) {
            unset($_SESSION[self::SESSION_ROOT]);
            return null;
        }

        $context = $payload['context'] ?? null;
        return is_array($context) ? $context : null;
    }

    private static function sessionPut(array $sessionUser, array $context, string $variant): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        SessionUser::ensureSessionStarted();
        $_SESSION[self::SESSION_ROOT][$variant] = [
            'user_id' => (int)($sessionUser['id'] ?? 0),
            'tenancy_id' => (string)($sessionUser['tenancy_id'] ?? ''),
            'expires_at' => time() + self::SESSION_TTL_SECONDS,
            'context' => $context,
        ];
    }

    private static function sessionForget(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        SessionUser::ensureSessionStarted();
        unset($_SESSION[self::SESSION_ROOT]);
    }
}
