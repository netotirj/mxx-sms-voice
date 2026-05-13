<?php

namespace App\Service;

use App\Model\Entity\PlanCatalog;
use App\Model\Entity\RegisterTenancies;
use App\RedisConn;
use App\Session\User as SessionUser;
use App\Support\RequestCache;
use Predis\Client as RedisClient;
use WilliamCosta\DatabaseManager\Database;

class PlanRuntimeService
{
    private const CACHE_TTL_SECONDS = 300;
    private const CACHE_PREFIX = 'plan_runtime:tenancy:';
    private const SESSION_ROOT = 'plan_runtime_cache';
    private const SESSION_TTL_SECONDS = 120;

    public static function getActivePlanByTenancy(string $tenancyId): ?array
    {
        $runtime = self::currentRuntime($tenancyId);
        return $runtime['plan'] ?? null;
    }

    public static function getActiveSubscription(string $tenancyId): ?array
    {
        $runtime = self::currentRuntime($tenancyId);
        return $runtime['subscription'] ?? null;
    }

    public static function getAppliedSnapshot(string $tenancyId): ?array
    {
        $runtime = self::currentRuntime($tenancyId);
        return $runtime['snapshot'] ?? null;
    }

    public static function getEffectiveLimits(string $tenancyId): array
    {
        $runtime = self::currentRuntime($tenancyId);
        return $runtime['limits'] ?? [];
    }

    public static function getDisplaySummary(string $tenancyId): ?object
    {
        $runtime = self::currentRuntime($tenancyId);
        if (!$runtime) {
            return null;
        }

        return (object)($runtime['summary'] ?? []);
    }

    public static function canUseFeature(string $tenancyId, string $featureKey): bool
    {
        $runtime = self::currentRuntime($tenancyId);
        if (!$runtime) {
            return false;
        }

        return !empty($runtime['features'][$featureKey]);
    }

    public static function assertCanUseFeature(string $tenancyId, string $featureKey): array
    {
        $runtime = self::currentRuntime($tenancyId);
        if (!$runtime) {
            return [
                'allowed' => false,
                'message' => 'Nenhum plano ativo foi encontrado para esta conta.',
            ];
        }

        if (!self::canUseFeature($tenancyId, $featureKey)) {
            return [
                'allowed' => false,
                'message' => self::featureDeniedMessage($featureKey),
                'feature' => $featureKey,
            ];
        }

        return [
            'allowed' => true,
            'feature' => $featureKey,
        ];
    }

    public static function assertLimitAvailable(string $tenancyId, string $limitKey, int $currentUsage): array
    {
        $runtime = self::currentRuntime($tenancyId);
        if (!$runtime) {
            return [
                'allowed' => false,
                'message' => 'Nenhum plano ativo foi encontrado para esta conta.',
            ];
        }

        $limit = self::normalizeLimit($runtime['limits'][$limitKey] ?? 0);

        if ($limit < 0) {
            return [
                'allowed' => true,
                'limit' => $limit,
                'current' => $currentUsage,
                'limit_key' => $limitKey,
            ];
        }

        if ($currentUsage >= $limit) {
            return [
                'allowed' => false,
                'message' => self::limitReachedMessage($limitKey),
                'limit' => $limit,
                'current' => $currentUsage,
                'limit_key' => $limitKey,
            ];
        }

        return [
            'allowed' => true,
            'limit' => $limit,
            'current' => $currentUsage,
            'limit_key' => $limitKey,
        ];
    }

    public static function invalidatePlanCache(string $tenancyId): void
    {
        if ($tenancyId === '') {
            return;
        }

        PerformanceTelemetry::log('plan_runtime.invalidate', [
            'tenancy_id' => $tenancyId,
        ]);

        RequestCache::forget('plan_runtime.current.' . $tenancyId);
        self::sessionForget($tenancyId);

        try {
            $redis = self::redis();
            if ($redis) {
                $redis->del([self::cacheKey($tenancyId)]);
            }
        } catch (\Throwable) {
        }
    }

    public static function invalidateForPlanId(int $planId): void
    {
        $rows = (new Database())->execute(
            'SELECT DISTINCT tenancy_id
             FROM mxx_user_plans
             WHERE plan_id = :plan_id',
            [':plan_id' => $planId]
        )->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        foreach ($rows as $tenancyId) {
            self::invalidatePlanCache((string)$tenancyId);
        }
    }

    public static function refreshPlanRuntime(string $tenancyId): ?array
    {
        self::invalidatePlanCache($tenancyId);
        return self::currentRuntime($tenancyId, true);
    }

    public static function currentRuntime(string $tenancyId, bool $forceRefresh = false): ?array
    {
        $tenancyId = trim($tenancyId);
        if ($tenancyId === '') {
            return null;
        }

        $requestKey = 'plan_runtime.current.' . $tenancyId . '.' . ($forceRefresh ? 'refresh' : 'cached');

        return RequestCache::remember($requestKey, function () use ($tenancyId, $forceRefresh): ?array {
            if (!$forceRefresh) {
                $sessionCached = self::sessionGet($tenancyId);
                if (is_array($sessionCached)) {
                    return $sessionCached;
                }

                $cached = self::loadCache($tenancyId);
                if (is_array($cached)) {
                    self::sessionPut($tenancyId, $cached);
                    return $cached;
                }
            }

            $subscription = self::resolveActiveSubscription($tenancyId);
            if (!$subscription) {
                self::storeCache($tenancyId, null);
                self::sessionForget($tenancyId);
                return null;
            }

            $plan = PlanCatalog::findById((int)($subscription['plan_id'] ?? 0));
            if (!$plan) {
                self::storeCache($tenancyId, null);
                self::sessionForget($tenancyId);
                return null;
            }

            $snapshot = self::resolveSnapshot($tenancyId, (int)$plan['id']);
            $effective = self::buildEffectivePlan($plan, $snapshot);
            $features = self::buildFeatureMap($effective);
            $limits = self::buildLimitsMap($effective);

            $runtime = [
                'subscription' => $subscription,
                'plan' => $plan,
                'snapshot' => $snapshot,
                'effective' => $effective,
                'features' => $features,
                'limits' => $limits,
                'summary' => self::buildSummary($effective),
            ];

            self::storeCache($tenancyId, $runtime);
            self::sessionPut($tenancyId, $runtime);
            return $runtime;
        });
    }

    public static function buildBalanceInsertPayload(array $plan): array
    {
        $snapshot = PlanCatalog::buildSnapshotPayload($plan);

        return [
            'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'applied_plan_name' => (string)($plan['name_plan'] ?? ''),
            'applied_billing_cycle' => (string)($plan['billing_cycle'] ?? 'monthly'),
            'applied_amount_plan' => (float)($plan['amount_plan'] ?? 0),
            'service_fee' => (float)($plan['service_fee'] ?? 0),
            'value_whatsapp' => (float)($plan['value_whatsapp'] ?? 0),
        ];
    }

    private static function resolveActiveSubscription(string $tenancyId): ?array
    {
        $activePlanId = RegisterTenancies::getActivePlanId($tenancyId);
        $params = [':tenancy_id' => $tenancyId];
        $where = "
            tenancy_id = :tenancy_id
            AND status_payment = 'confirmed'
            AND status = 'active'
        ";

        if ($activePlanId !== null && $activePlanId > 0) {
            $where .= ' AND plan_id = :plan_id';
            $params[':plan_id'] = $activePlanId;
        }

        $sql = "
            SELECT *
            FROM mxx_user_plans
            WHERE {$where}
            ORDER BY updated_at DESC, created_at DESC, id DESC
            LIMIT 1
        ";

        $row = (new Database())->execute($sql, $params)->fetch(\PDO::FETCH_ASSOC);

        if (!$row && $activePlanId !== null && $activePlanId > 0) {
            $row = (new Database())->execute(
                "SELECT *
                 FROM mxx_user_plans
                 WHERE tenancy_id = :tenancy_id
                   AND status_payment = 'confirmed'
                   AND status = 'active'
                 ORDER BY updated_at DESC, created_at DESC, id DESC
                 LIMIT 1",
                [':tenancy_id' => $tenancyId]
            )->fetch(\PDO::FETCH_ASSOC);
        }

        return $row ?: null;
    }

    private static function resolveSnapshot(string $tenancyId, int $planId): ?array
    {
        $row = (new Database())->execute(
            "SELECT *
             FROM tenancy_balance
             WHERE tenancy_id = :tenancy_id
               AND plan_id = :plan_id
             ORDER BY updated_at DESC, id DESC
             LIMIT 1",
            [
                ':tenancy_id' => $tenancyId,
                ':plan_id' => $planId,
            ]
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $snapshotJson = trim((string)($row['snapshot_json'] ?? ''));
        $decoded = $snapshotJson !== '' ? json_decode($snapshotJson, true) : null;
        $snapshot = is_array($decoded) ? $decoded : [];

        $snapshot['balance_row'] = [
            'id' => (int)($row['id'] ?? 0),
            'balance' => (float)($row['balance'] ?? 0),
            'value_sms' => (float)($row['value_sms'] ?? 0),
            'value_voice' => (float)($row['value_voice'] ?? 0),
            'voice_open_rate' => (float)($row['voice_open_rate'] ?? ($row['value_voice'] ?? 0)),
            'voice_smart_rate' => (float)($row['voice_smart_rate'] ?? ($row['value_voice'] ?? 0)),
            'value_torpedo' => (float)($row['value_torpedo'] ?? 0),
            'value_whatsapp' => (float)($row['value_whatsapp'] ?? 0),
            'service_fee' => (float)($row['service_fee'] ?? 0),
            'payment_invoice' => (string)($row['payment_invoice'] ?? ''),
            'applied_plan_name' => (string)($row['applied_plan_name'] ?? ''),
            'applied_billing_cycle' => (string)($row['applied_billing_cycle'] ?? ''),
            'applied_amount_plan' => (float)($row['applied_amount_plan'] ?? 0),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];

        return $snapshot;
    }

    private static function buildEffectivePlan(array $plan, ?array $snapshot): array
    {
        $effective = $plan;

        if (!$snapshot) {
            return $effective;
        }

        $pricing = is_array($snapshot['pricing'] ?? null) ? $snapshot['pricing'] : [];
        $limits = is_array($snapshot['limits'] ?? null) ? $snapshot['limits'] : [];
        $features = is_array($snapshot['features'] ?? null) ? $snapshot['features'] : [];
        $balanceRow = is_array($snapshot['balance_row'] ?? null) ? $snapshot['balance_row'] : [];

        $effective['amount_plan'] = (float)($pricing['amount_plan'] ?? $balanceRow['applied_amount_plan'] ?? $plan['amount_plan']);
        $effective['billing_cycle'] = (string)($pricing['billing_cycle'] ?? $balanceRow['applied_billing_cycle'] ?? $plan['billing_cycle']);
        $effective['service_fee'] = (float)($pricing['service_fee'] ?? $balanceRow['service_fee'] ?? $plan['service_fee']);
        $effective['value_sms'] = (float)($pricing['value_sms'] ?? $balanceRow['value_sms'] ?? $plan['value_sms']);
        $effective['value_voice'] = (float)($pricing['value_voice'] ?? $balanceRow['value_voice'] ?? $plan['value_voice']);
        $effective['voice_open_rate'] = (float)($pricing['voice_open_rate'] ?? $balanceRow['voice_open_rate'] ?? $plan['voice_open_rate']);
        $effective['voice_smart_rate'] = (float)($pricing['voice_smart_rate'] ?? $balanceRow['voice_smart_rate'] ?? $plan['voice_smart_rate']);
        $effective['value_torpedo'] = (float)($pricing['value_torpedo'] ?? $balanceRow['value_torpedo'] ?? $plan['value_torpedo']);
        $effective['value_whatsapp'] = (float)($pricing['value_whatsapp'] ?? $balanceRow['value_whatsapp'] ?? $plan['value_whatsapp']);
        // Tarifas WhatsApp acompanham o cambio; priorizamos o valor atual do plano sincronizado
        // antes do snapshot aplicado no saldo para evitar exibir preco congelado.
        $effective['value_whatsapp_marketing'] = (float)($plan['value_whatsapp_marketing'] ?? $pricing['value_whatsapp_marketing'] ?? 0);
        $effective['value_whatsapp_utility'] = (float)($plan['value_whatsapp_utility'] ?? $pricing['value_whatsapp_utility'] ?? 0);
        $effective['value_whatsapp_authentication'] = (float)($plan['value_whatsapp_authentication'] ?? $pricing['value_whatsapp_authentication'] ?? 0);
        $effective['whatsapp_voice_price_per_minute'] = (float)($plan['whatsapp_voice_price_per_minute'] ?? $pricing['whatsapp_voice_price_per_minute'] ?? 0);
        $effective['whatsapp_voice_enabled'] = !empty($pricing['whatsapp_voice_enabled'] ?? $plan['whatsapp_voice_enabled'])
            || $effective['whatsapp_voice_price_per_minute'] > 0;
        $effective['whatsapp_voice_markup_percent'] = (float)($pricing['whatsapp_voice_markup_percent'] ?? $plan['whatsapp_voice_markup_percent'] ?? 0);
        $effective['whatsapp_voice_billing_pulse_seconds'] = max(1, (int)($pricing['whatsapp_voice_billing_pulse_seconds'] ?? $plan['whatsapp_voice_billing_pulse_seconds'] ?? 6));

        foreach ($limits as $key => $value) {
            if ($key === 'campaigns_limit') {
                $effective['campaigns_limit'] = (int)$value;
                continue;
            }

            $effective[$key] = is_numeric($value) ? (int)$value : $value;
        }

        if (isset($features['users_create'])) {
            $effective['users_create'] = !empty($features['users_create']) ? 'y' : 'n';
        }

        if (isset($features['webrtc_enabled'])) {
            $effective['webrtc_enabled'] = !empty($features['webrtc_enabled']);
        }

        if (isset($features['modules']) && is_array($features['modules'])) {
            $effective['modules'] = array_replace($effective['modules'] ?? [], $features['modules']);
        }

        return $effective;
    }

    private static function buildFeatureMap(array $effective): array
    {
        $modules = $effective['modules'] ?? [];
        if (!is_array($modules)) {
            $modules = [];
        }

        $legacyUsersCreate = ($effective['users_create'] ?? 'n') === 'y';
        $usersModuleExplicit = array_key_exists('users', $modules);
        $createUsersExplicit = array_key_exists('create_users', $modules);
        $ratesExplicit = array_key_exists('rates', $modules);
        $permissionsExplicit = array_key_exists('permissions', $modules);

        $usersEnabled = $usersModuleExplicit
            ? !empty($modules['users'])
            : $legacyUsersCreate;

        $createUsersEnabled = $createUsersExplicit
            ? (!empty($modules['create_users']) && $usersEnabled)
            : ($legacyUsersCreate && $usersEnabled);

        $ratesEnabled = $ratesExplicit
            ? !empty($modules['rates'])
            : $legacyUsersCreate;

        $permissionsEnabled = $permissionsExplicit
            ? !empty($modules['permissions'])
            : $legacyUsersCreate;

        return [
            'administrative' => !empty($modules['administrative']),
            'users' => $usersEnabled,
            'create_users' => $createUsersEnabled,
            'rates' => $ratesEnabled,
            'permissions' => $permissionsEnabled,
            'reports' => !empty($modules['reports']) || trim((string)($effective['reports_label'] ?? '')) !== '',
            'campaigns' => !empty($modules['campaigns']) || !empty($modules['sms']) || (int)($effective['campaigns_limit'] ?? $effective['camp_qtd'] ?? 0) !== 0,
            'sms' => !empty($modules['sms']),
            'voice' => !empty($modules['voice']),
            'callcenter' => !empty($modules['callcenter']),
            'webrtc' => !empty($modules['webrtc']) || !empty($effective['webrtc_enabled']),
            'whatsapp' => !empty($modules['whatsapp']),
            'templates' => !empty($modules['templates']),
            'trunks' => !empty($modules['trunks']),
            'plans_catalog' => false,
        ];
    }

    private static function buildLimitsMap(array $effective): array
    {
        return [
            'simultaneous_access' => self::normalizeLimit($effective['simultaneous_access'] ?? 0),
            'users' => self::normalizeLimit($effective['users_limit'] ?? 0),
            'sms' => self::normalizeLimit($effective['sms_limit'] ?? 0),
            'voice' => self::normalizeLimit($effective['voice_limit'] ?? 0),
            'campaigns' => self::normalizeLimit($effective['campaigns_limit'] ?? $effective['camp_qtd'] ?? 0),
            'trunks' => self::normalizeLimit($effective['trunks'] ?? 0),
            'whatsapp_accounts' => self::normalizeLimit($effective['whatsapp_accounts'] ?? 0),
            'templates' => self::normalizeLimit($effective['templates_limit'] ?? 0),
        ];
    }

    private static function buildSummary(array $effective): array
    {
        return [
            'plan_id' => (int)($effective['id'] ?? 0),
            'name_plan' => (string)($effective['name_plan'] ?? ''),
            'description' => (string)($effective['description'] ?? ''),
            'amount_plan' => (float)($effective['amount_plan'] ?? 0),
            'trunks' => (int)($effective['trunks'] ?? 0),
            'whatsapp_accounts' => (int)($effective['whatsapp_accounts'] ?? 0),
            'value_sms' => (float)($effective['value_sms'] ?? 0),
            'value_voice' => (float)($effective['value_voice'] ?? 0),
            'voice_open_rate' => (float)($effective['voice_open_rate'] ?? 0),
            'voice_smart_rate' => (float)($effective['voice_smart_rate'] ?? 0),
            'value_torpedo' => (float)($effective['value_torpedo'] ?? 0),
            'service_fee' => (float)($effective['service_fee'] ?? 0),
            'value_whatsapp_marketing' => (float)($effective['value_whatsapp_marketing'] ?? $effective['value_whatsapp'] ?? 0),
            'value_whatsapp_utility' => (float)($effective['value_whatsapp_utility'] ?? $effective['value_whatsapp'] ?? 0),
            'value_whatsapp_authentication' => (float)($effective['value_whatsapp_authentication'] ?? $effective['value_whatsapp'] ?? 0),
            'whatsapp_voice_enabled' => !empty($effective['whatsapp_voice_enabled']),
            'whatsapp_voice_price_per_minute' => (float)($effective['whatsapp_voice_price_per_minute'] ?? 0),
            'whatsapp_voice_markup_percent' => (float)($effective['whatsapp_voice_markup_percent'] ?? 0),
            'whatsapp_voice_billing_pulse_seconds' => max(1, (int)($effective['whatsapp_voice_billing_pulse_seconds'] ?? 6)),
        ];
    }

    private static function normalizeLimit(mixed $value): int
    {
        $limit = (int)$value;
        if ($limit < 0) {
            return -1;
        }

        return max(0, $limit);
    }

    private static function cacheKey(string $tenancyId): string
    {
        return self::CACHE_PREFIX . $tenancyId;
    }

    private static function loadCache(string $tenancyId): ?array
    {
        try {
            $redis = self::redis();
            if (!$redis) {
                return null;
            }

            $value = $redis->get(self::cacheKey($tenancyId));
            if (!is_string($value) || trim($value) === '') {
                return null;
            }

            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function storeCache(string $tenancyId, ?array $runtime): void
    {
        try {
            $redis = self::redis();
            if (!$redis) {
                return;
            }

            if ($runtime === null) {
                $redis->setex(self::cacheKey($tenancyId), self::CACHE_TTL_SECONDS, json_encode([]));
                return;
            }

            $redis->setex(
                self::cacheKey($tenancyId),
                self::CACHE_TTL_SECONDS,
                json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        } catch (\Throwable) {
        }
    }

    private static function redis(): ?RedisClient
    {
        try {
            return RedisConn::app();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function sessionGet(string $tenancyId): ?array
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }

        SessionUser::ensureSessionStarted();
        $payload = $_SESSION[self::SESSION_ROOT][$tenancyId] ?? null;
        if (!is_array($payload)) {
            return null;
        }

        if ((int)($payload['expires_at'] ?? 0) < time()) {
            unset($_SESSION[self::SESSION_ROOT][$tenancyId]);
            return null;
        }

        $runtime = $payload['runtime'] ?? null;
        return is_array($runtime) ? $runtime : null;
    }

    private static function sessionPut(string $tenancyId, array $runtime): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        SessionUser::ensureSessionStarted();
        $_SESSION[self::SESSION_ROOT][$tenancyId] = [
            'expires_at' => time() + self::SESSION_TTL_SECONDS,
            'runtime' => $runtime,
        ];
    }

    private static function sessionForget(string $tenancyId): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        SessionUser::ensureSessionStarted();
        unset($_SESSION[self::SESSION_ROOT][$tenancyId]);
    }

    private static function featureDeniedMessage(string $featureKey): string
    {
        return match ($featureKey) {
            'administrative' => 'Seu plano atual não permite acessar o módulo administrativo.',
            'create_users', 'users' => 'Seu plano atual não permite gerenciar usuários.',
            'rates' => 'Seu plano atual não permite acessar tarifas.',
            'permissions' => 'Seu plano atual não permite acessar permissões.',
            'reports' => 'Seu plano atual não permite acessar relatórios.',
            'sms', 'campaigns' => 'Seu plano atual não permite usar o módulo SMS.',
            'whatsapp' => 'Seu plano atual não permite usar o módulo WhatsApp.',
            'templates' => 'Seu plano atual não permite usar templates.',
            'callcenter' => 'Seu plano atual não permite usar o módulo Call Center.',
            'voice', 'webrtc', 'trunks' => 'Seu plano atual não permite usar recursos de voz.',
            default => 'Seu plano atual não permite esta operação.',
        };
    }

    private static function limitReachedMessage(string $limitKey): string
    {
        return match ($limitKey) {
            'trunks' => 'Limite de trunks do plano atingido. Por favor, contate o administrador da conta.',
            'whatsapp_accounts' => 'Limite de contas WhatsApp atingido. Por favor, contate o administrador da conta.',
            'users' => 'Limite de usuários do plano atingido.',
            'campaigns' => 'Limite de campanhas do plano atingido.',
            'sms' => 'Limite de SMS do plano atingido.',
            'voice' => 'Limite de voz do plano atingido.',
            'templates' => 'Limite de templates do plano atingido.',
            default => 'Limite do plano atingido.',
        };
    }
}
