<?php

namespace App\Service;

use App\RedisConn;
use App\Session\User as SessionUser;
use App\Support\RequestCache;
use Throwable;
use WilliamCosta\DatabaseManager\Database;

class PermissionResolver
{
    private const CACHE_SCHEMA_VERSION = 6;
    private const USE_REDIS = false;
    private const SESSION_ROOT = 'permission_cache';
    private const SESSION_VERSION_TTL = 30;
    private const REDIS_TTL = 300;
    private const REDIS_PREFIX = 'permissions:cache:';
    private const TENANT_VERSION_PREFIX = 'permissions:version:tenant:';
    private const USER_VERSION_PREFIX = 'permissions:version:user:';
    private const DB_VERSION_TABLE = 'sys_permission_cache_versions';

    public static function resolveRoleId(array $user): int
    {
        $userId = (int)($user['id'] ?? 0);
        $tenancyId = (string)($user['tenancy_id'] ?? '');
        $sessionRoleId = (int)($user['role_id'] ?? 0);

        if ($userId <= 0 || $tenancyId === '') {
            return 0;
        }

        $requestKey = 'permissions.role.' . $tenancyId . '.' . $userId;
        return (int)RequestCache::remember($requestKey, function () use ($userId, $tenancyId, $sessionRoleId) {
            $cacheKey = self::userCacheKey('role', $tenancyId, $userId);
            $cached = self::sessionGet($cacheKey);
            if (is_numeric($cached)) {
                return (int)$cached;
            }

            $cached = self::redisGet($cacheKey);
            if (is_numeric($cached)) {
                self::sessionPut($cacheKey, (int)$cached);
                return (int)$cached;
            }

            $db = new Database();
            $roleId = (int)$db->execute(
                "SELECT role_id
                 FROM user_roles
                 WHERE user_id = :uid
                   AND tenancy_id = :tid
                 LIMIT 1",
                [
                    ':uid' => $userId,
                    ':tid' => $tenancyId,
                ]
            )->fetchColumn();

            if ($roleId <= 0) {
                $roleId = (int)$db->execute(
                    "SELECT role_id
                     FROM users
                     WHERE id = :uid
                       AND tenancy_id = :tid
                     LIMIT 1",
                    [
                        ':uid' => $userId,
                        ':tid' => $tenancyId,
                    ]
                )->fetchColumn();
            }

            if ($roleId <= 0) {
                $roleId = $sessionRoleId;
            }

            self::sessionPut($cacheKey, $roleId);
            self::redisPut($cacheKey, $roleId);

            return $roleId;
        });
    }

    public static function getRolePermissionNames(int $roleId, string $tenancyId = ''): array
    {
        if ($roleId <= 0) {
            return [];
        }

        $cacheKey = self::roleCacheKey('permission_names', $tenancyId, $roleId);
        return RequestCache::remember($cacheKey, function () use ($cacheKey, $roleId, $tenancyId) {
            $cached = self::sessionGet($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }

            $cached = self::redisGet($cacheKey);
            if (is_array($cached)) {
                self::sessionPut($cacheKey, $cached);
                return $cached;
            }

            $params = [':role_id' => $roleId];
            $where = 'srp.role_id = :role_id';
            if ($tenancyId !== '') {
                $where .= ' AND srp.tenancy_id = :tenancy_id';
                $params[':tenancy_id'] = $tenancyId;
            }

            $results = (new Database())->execute(
                "SELECT DISTINCT sr.route_path
                 FROM sys_role_permissions srp
                 INNER JOIN sys_routes sr ON sr.id = srp.route_id
                 WHERE {$where}",
                $params
            )->fetchAll(\PDO::FETCH_COLUMN) ?: [];

            $results = array_values(array_unique(array_filter(array_map(
                static fn ($route): string => trim((string)$route),
                $results
            ))));

            self::sessionPut($cacheKey, $results);
            self::redisPut($cacheKey, $results);

            return $results;
        });
    }

    public static function getUserAccessContext(array $user): array
    {
        $userId = (int)($user['id'] ?? 0);
        $tenancyId = (string)($user['tenancy_id'] ?? '');

        if ($userId <= 0 || $tenancyId === '') {
            return [
                'user_id' => $userId,
                'tenancy_id' => $tenancyId,
                'role_id' => 0,
                'allowed_routes' => [],
                'allowed_lookup' => [],
            ];
        }

        $requestKey = 'permissions.context.' . $tenancyId . '.' . $userId;

        return (array)RequestCache::remember($requestKey, function () use ($user, $userId, $tenancyId): array {
            $version = self::versionSnapshot($tenancyId, $userId);
            $sessionContextKey = 'permissions.context_state.' . $tenancyId . '.' . $userId;
            $cachedContext = self::sessionGet($sessionContextKey);
            if (
                is_array($cachedContext)
                && (int)($cachedContext['tenant_version'] ?? 0) === (int)($version['tenant'] ?? 0)
                && (int)($cachedContext['user_version'] ?? 0) === (int)($version['user'] ?? 0)
                && is_array($cachedContext['context'] ?? null)
            ) {
                return (array)$cachedContext['context'];
            }

            $roleId = self::resolveRoleId($user);
            $allowedRoutes = $roleId > 0
                ? self::getRolePermissionNames($roleId, $tenancyId)
                : [];

            $context = [
                'user_id' => $userId,
                'tenancy_id' => $tenancyId,
                'role_id' => $roleId,
                'allowed_routes' => $allowedRoutes,
                'allowed_lookup' => $allowedRoutes !== [] ? array_fill_keys($allowedRoutes, true) : [],
            ];

            self::sessionPut($sessionContextKey, [
                'tenant_version' => (int)($version['tenant'] ?? 1),
                'user_version' => (int)($version['user'] ?? 1),
                'context' => $context,
            ]);

            return $context;
        });
    }

    public static function userCanAccessRoute(array $user, string $routeName): bool
    {
        $userId = (int)($user['id'] ?? 0);
        $tenancyId = (string)($user['tenancy_id'] ?? '');

        if ($userId <= 0 || $tenancyId === '') {
            return false;
        }

        $normalizedRoute = self::normalizeRoutePath($routeName);
        $cacheKey = 'permissions.route_access.' . $tenancyId . '.' . $userId . '.' . md5($normalizedRoute);

        return (bool)RequestCache::remember($cacheKey, function () use ($user, $userId, $tenancyId, $normalizedRoute) {
            $context = self::getUserAccessContext($user);
            $roleId = (int)($context['role_id'] ?? 0);
            if ($roleId <= 0) {
                PerformanceTelemetry::log('permissions.denied', [
                    'reason' => 'no_role',
                    'user_id' => $userId,
                    'tenancy_id' => $tenancyId,
                    'route' => $normalizedRoute,
                ]);
                return false;
            }

            $allowedLookup = (array)($context['allowed_lookup'] ?? []);
            if (self::routeExists($normalizedRoute)) {
                if (isset($allowedLookup[$normalizedRoute])) {
                    return true;
                }

                if (!self::canInheritFromParentRoute($normalizedRoute)) {
                    PerformanceTelemetry::log('permissions.denied', [
                        'reason' => 'missing_exact_route_permission',
                        'user_id' => $userId,
                        'tenancy_id' => $tenancyId,
                        'role_id' => $roleId,
                        'route' => $normalizedRoute,
                    ]);

                    return false;
                }
            }

            $candidates = self::buildRouteCandidates($normalizedRoute, self::routeExists($normalizedRoute));

            foreach ($candidates as $candidate) {
                if (isset($allowedLookup[$candidate])) {
                    return true;
                }
            }

            PerformanceTelemetry::log('permissions.denied', [
                'reason' => 'missing_route_permission',
                'user_id' => $userId,
                'tenancy_id' => $tenancyId,
                'role_id' => $roleId,
                'route' => $normalizedRoute,
                'candidates' => $candidates,
            ]);

            return false;
        });
    }

    public static function userHasPermission(array $user, string $permission): bool
    {
        $permission = trim($permission);
        if ($permission === '') {
            return false;
        }

        $context = self::getUserAccessContext($user);
        $allowedLookup = (array)($context['allowed_lookup'] ?? []);

        return isset($allowedLookup[$permission]);
    }

    public static function warmUserAccess(array $user): void
    {
        $userId = (int)($user['id'] ?? 0);
        $tenancyId = (string)($user['tenancy_id'] ?? '');

        if ($userId <= 0 || $tenancyId === '') {
            return;
        }

        self::getUserAccessContext($user);
    }

    public static function versionForUser(array $user): array
    {
        $tenancyId = (string)($user['tenancy_id'] ?? '');
        $userId = (int)($user['id'] ?? 0);

        if ($tenancyId === '' || $userId <= 0) {
            return [
                'tenant' => 1,
                'user' => 1,
                'key' => '1:1',
            ];
        }

        $snapshot = self::versionSnapshot($tenancyId, $userId);

        return [
            'tenant' => max(1, (int)($snapshot['tenant'] ?? 1)),
            'user' => max(1, (int)($snapshot['user'] ?? 1)),
            'key' => max(1, (int)($snapshot['tenant'] ?? 1)) . ':' . max(1, (int)($snapshot['user'] ?? 1)),
        ];
    }

    public static function invalidate(?string $tenancyId = null, ?int $userId = null): void
    {
        PerformanceTelemetry::log('permissions.invalidate', [
            'tenancy_id' => $tenancyId ?? '',
            'user_id' => $userId ?? 0,
        ]);

        if ($tenancyId !== null && $tenancyId !== '') {
            self::bumpTenantVersion($tenancyId);
            self::sessionForgetByPrefix('.' . $tenancyId . '.');
            self::sessionForgetByPrefix('permissions.context_state.' . $tenancyId . '.');
            self::sessionForgetByPrefix('permissions.version_state.' . $tenancyId . '.');
        }

        if ($tenancyId !== null && $tenancyId !== '' && $userId !== null && $userId > 0) {
            self::bumpUserVersion($tenancyId, $userId);
        }

        SessionUser::clearRuntimeCaches();
        RequestCache::forget('permissions');
        RequestCache::forget('auth_context');
    }

    private static function normalizeRoutePath(string $routeName): string
    {
        $routeName = '/' . trim($routeName, '/');
        return preg_replace('#/+#', '/', $routeName) ?: '/';
    }

    private static function buildRouteCandidates(string $routeName, bool $dropExactWhenCatalogued = false): array
    {
        $normalized = self::normalizeRoutePath($routeName);

        return (array)RequestCache::remember('permissions.route_candidates.' . md5($normalized . '|' . ($dropExactWhenCatalogued ? '1' : '0')), static function () use ($normalized, $dropExactWhenCatalogued): array {
            $candidates = [$normalized];
            $segments = array_values(array_filter(explode('/', trim($normalized, '/')), 'strlen'));

            while (count($segments) > 1) {
                array_pop($segments);
                $candidates[] = '/' . implode('/', $segments);
            }

            if ($dropExactWhenCatalogued) {
                array_shift($candidates);
            }

            return array_values(array_unique($candidates));
        });
    }

    private static function routeExists(string $routePath): bool
    {
        $normalized = self::normalizeRoutePath($routePath);

        return (bool)RequestCache::remember('permissions.route_exists.' . md5($normalized), static function () use ($normalized): bool {
            $cacheKey = 'permissions.route_catalog.exists.' . md5($normalized);
            $cached = self::sessionGet($cacheKey);
            if (is_bool($cached)) {
                return $cached;
            }

            $cached = self::redisGet($cacheKey);
            if (is_bool($cached)) {
                self::sessionPut($cacheKey, $cached);
                return $cached;
            }

            $exists = (bool)(new Database())->execute(
                "SELECT 1
                 FROM sys_routes
                 WHERE route_path = :route_path
                 LIMIT 1",
                [':route_path' => $normalized]
            )->fetchColumn();

            self::sessionPut($cacheKey, $exists);
            self::redisPut($cacheKey, $exists);

            return $exists;
        });
    }

    private static function canInheritFromParentRoute(string $routePath): bool
    {
        $normalized = self::normalizeRoutePath($routePath);

        if (in_array($normalized, ['/dashboard/cards', '/dashboard/charts'], true)) {
            return true;
        }

        if (preg_match('#^/dashboard/(cards|charts)/#i', $normalized)) {
            return true;
        }

        return false;
    }

    private static function sessionGet(string $key): mixed
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }

        SessionUser::ensureSessionStarted();
        return $_SESSION[self::SESSION_ROOT][$key] ?? null;
    }

    private static function sessionPut(string $key, mixed $value): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        SessionUser::ensureSessionStarted();
        $_SESSION[self::SESSION_ROOT][$key] = $value;
    }

    private static function sessionForgetByPrefix(string $prefix): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        SessionUser::ensureSessionStarted();
        $items = $_SESSION[self::SESSION_ROOT] ?? [];
        foreach (array_keys($items) as $key) {
            if (str_contains($key, $prefix)) {
                unset($_SESSION[self::SESSION_ROOT][$key]);
            }
        }
    }

    private static function roleCacheKey(string $kind, string $tenancyId, int $roleId): string
    {
        return 'permissions.v' . self::CACHE_SCHEMA_VERSION . '.role.' . $kind . '.' . ($tenancyId !== '' ? $tenancyId : 'global') . '.' . $roleId . '.v' . self::tenantVersion($tenancyId);
    }

    private static function userCacheKey(string $kind, string $tenancyId, int $userId): string
    {
        return 'permissions.v' . self::CACHE_SCHEMA_VERSION . '.' . $tenancyId . '.user.' . $kind . '.' . $userId
            . '.tv' . self::tenantVersion($tenancyId)
            . '.uv' . self::userVersion($tenancyId, $userId);
    }

    private static function tenantVersion(string $tenancyId): int
    {
        if ($tenancyId === '') {
            return 1;
        }

        return (int)(self::versionSnapshot($tenancyId)['tenant'] ?? 1);
    }

    private static function userVersion(string $tenancyId, int $userId): int
    {
        return (int)(self::versionSnapshot($tenancyId, $userId)['user'] ?? 1);
    }

    private static function bumpTenantVersion(string $tenancyId): void
    {
        self::bumpDbVersion('tenant', $tenancyId);

        if (!self::USE_REDIS) {
            return;
        }

        $versionKey = self::TENANT_VERSION_PREFIX . $tenancyId;
        try {
            $redis = RedisConn::app();
            $redis->incr($versionKey);
            $redis->expire($versionKey, 86400 * 30);
        } catch (Throwable) {
        }
    }

    private static function bumpUserVersion(string $tenancyId, int $userId): void
    {
        self::bumpDbVersion('user', $tenancyId, $userId);

        if (!self::USE_REDIS) {
            return;
        }

        $versionKey = self::USER_VERSION_PREFIX . $tenancyId . ':' . $userId;
        try {
            $redis = RedisConn::app();
            $redis->incr($versionKey);
            $redis->expire($versionKey, 86400 * 30);
        } catch (Throwable) {
        }
    }

    private static function redisScalar(string $key, mixed $default = null): mixed
    {
        if (!self::USE_REDIS) {
            return $default;
        }

        try {
            $redis = RedisConn::app();
            $value = $redis->get($key);
            return $value !== null ? $value : $default;
        } catch (Throwable) {
            return $default;
        }
    }

    private static function redisGet(string $key): mixed
    {
        if (!self::USE_REDIS) {
            return null;
        }

        try {
            $redis = RedisConn::app();
            $raw = $redis->get(self::REDIS_PREFIX . $key);
            if ($raw === null || $raw === '') {
                return null;
            }

            $decoded = json_decode((string)$raw, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function redisPut(string $key, mixed $value): void
    {
        if (!self::USE_REDIS) {
            return;
        }

        try {
            $redis = RedisConn::app();
            $redis->setex(
                self::REDIS_PREFIX . $key,
                self::REDIS_TTL,
                json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        } catch (Throwable) {
        }
    }

    private static function versionSnapshot(string $tenancyId, ?int $userId = null): array
    {
        if ($tenancyId === '') {
            return ['tenant' => 1, 'user' => 1];
        }

        $requestKey = 'permissions.version_snapshot.' . $tenancyId . '.' . (int)($userId ?? 0);
        return (array)RequestCache::remember($requestKey, function () use ($tenancyId, $userId): array {
            if (!self::USE_REDIS) {
                return [
                    'tenant' => self::dbVersionValue('tenant', $tenancyId),
                    'user' => $userId !== null && $userId > 0
                        ? self::dbVersionValue('user', $tenancyId, $userId)
                        : 1,
                ];
            }

            $sessionKey = 'permissions.version_state.' . $tenancyId . '.' . (int)($userId ?? 0);
            $cached = self::sessionGet($sessionKey);
            if (
                is_array($cached)
                && (int)($cached['expires_at'] ?? 0) >= time()
                && isset($cached['tenant'], $cached['user'])
            ) {
                return [
                    'tenant' => max(1, (int)$cached['tenant']),
                    'user' => max(1, (int)$cached['user']),
                ];
            }

            $tenantVersion = max(1, (int)self::redisScalar(self::TENANT_VERSION_PREFIX . $tenancyId, 1));
            $userVersion = $userId !== null && $userId > 0
                ? max(1, (int)self::redisScalar(self::USER_VERSION_PREFIX . $tenancyId . ':' . $userId, 1))
                : 1;

            self::sessionPut($sessionKey, [
                'tenant' => $tenantVersion,
                'user' => $userVersion,
                'expires_at' => time() + self::SESSION_VERSION_TTL,
            ]);

            return [
                'tenant' => $tenantVersion,
                'user' => $userVersion,
            ];
        });
    }

    private static function ensureDbVersionSchema(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        (new Database())->execute("
            CREATE TABLE IF NOT EXISTS " . self::DB_VERSION_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                scope_type VARCHAR(20) NOT NULL,
                tenancy_id VARCHAR(191) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                version BIGINT UNSIGNED NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_permission_cache_versions_scope (scope_type, tenancy_id, user_id),
                KEY idx_permission_cache_versions_tenancy (tenancy_id),
                KEY idx_permission_cache_versions_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $ensured = true;
    }

    private static function dbVersionValue(string $scopeType, string $tenancyId, ?int $userId = null): int
    {
        self::ensureDbVersionSchema();

        $normalizedUserId = $scopeType === 'user' ? max(0, (int)$userId) : 0;
        $version = (int)(new Database())->execute(
            "SELECT version
             FROM " . self::DB_VERSION_TABLE . "
             WHERE scope_type = :scope_type
               AND tenancy_id = :tenancy_id
               AND user_id = :user_id
             LIMIT 1",
            [
                ':scope_type' => $scopeType,
                ':tenancy_id' => $tenancyId,
                ':user_id' => $normalizedUserId,
            ]
        )->fetchColumn();

        if ($version > 0) {
            return $version;
        }

        (new Database())->execute(
            "INSERT INTO " . self::DB_VERSION_TABLE . " (scope_type, tenancy_id, user_id, version, updated_at)
             VALUES (:scope_type, :tenancy_id, :user_id, 1, NOW())
             ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
            [
                ':scope_type' => $scopeType,
                ':tenancy_id' => $tenancyId,
                ':user_id' => $normalizedUserId,
            ]
        );

        return 1;
    }

    private static function bumpDbVersion(string $scopeType, string $tenancyId, ?int $userId = null): void
    {
        if ($tenancyId === '') {
            return;
        }

        self::ensureDbVersionSchema();
        $normalizedUserId = $scopeType === 'user' ? max(0, (int)$userId) : 0;

        (new Database())->execute(
            "INSERT INTO " . self::DB_VERSION_TABLE . " (scope_type, tenancy_id, user_id, version, updated_at)
             VALUES (:scope_type, :tenancy_id, :user_id, 2, NOW())
             ON DUPLICATE KEY UPDATE
                version = version + 1,
                updated_at = NOW()",
            [
                ':scope_type' => $scopeType,
                ':tenancy_id' => $tenancyId,
                ':user_id' => $normalizedUserId,
            ]
        );
    }
}
