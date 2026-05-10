<?php

namespace App\Model\Entity;

use App\Service\PlanRuntimeService;
use App\Session\User as SessionUser;
use WilliamCosta\DatabaseManager\Database;

class PlanCatalog
{
    private static array $columnCache = [];
    private static array $indexCache = [];
    private const SCHEMA_SESSION_KEY = 'plan_catalog.schema_verified_at';
    private const ROUTES_SESSION_KEY = 'plan_catalog.routes_verified_at';
    private const VERIFY_TTL_SECONDS = 43200;

    public static function ensureSchema(): void
    {
        static $verified = false;
        if ($verified || self::sessionVerificationFresh(self::SCHEMA_SESSION_KEY)) {
            $verified = true;
            return;
        }

        self::ensurePlanTable();
        self::ensureSnapshotTableShape();
        self::seedLegacyPlans();
        self::touchSessionVerification(self::SCHEMA_SESSION_KEY);
        $verified = true;
    }

    public static function ensureRouteCatalog(): void
    {
        static $verified = false;
        if ($verified || self::sessionVerificationFresh(self::ROUTES_SESSION_KEY)) {
            $verified = true;
            return;
        }

        $routes = [
            '/plans',
            '/plans/search',
            '/plans/save',
            '/plans/{id}',
            '/plans/{id}/status',
            '/plans/{id}/delete',
        ];

        foreach ($routes as $path) {
            (new Database())->execute(
                'INSERT IGNORE INTO sys_routes (module_name, route_path) VALUES (:module_name, :route_path)',
                [
                    ':module_name' => 'Administrativo: Planos',
                    ':route_path' => $path,
                ]
            );
        }

        self::touchSessionVerification(self::ROUTES_SESSION_KEY);
        $verified = true;
    }

    public static function all(array $filters = []): array
    {
        $where = ['deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = (string)$filters['status'];
        }

        if (!empty($filters['type_plan'])) {
            $where[] = 'type_plan = :type_plan';
            $params[':type_plan'] = (string)$filters['type_plan'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(name_plan LIKE :search OR slug LIKE :search OR description LIKE :search OR type_plan LIKE :search)';
            $params[':search'] = '%' . trim((string)$filters['search']) . '%';
        }

        $sql = 'SELECT * FROM mxx_plans WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC';
        $rows = (new Database())->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map([self::class, 'normalizeRow'], $rows);
    }

    public static function findById(int $id): ?array
    {
        $row = (new Database())->execute(
            'SELECT * FROM mxx_plans WHERE id = :id LIMIT 1',
            [':id' => $id]
        )->fetch(\PDO::FETCH_ASSOC);

        return $row ? self::normalizeRow($row) : null;
    }

    public static function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT 1 FROM mxx_plans WHERE slug = :slug AND deleted_at IS NULL';
        $params = [':slug' => $slug];

        if ($ignoreId !== null && $ignoreId > 0) {
            $sql .= ' AND id <> :ignore_id';
            $params[':ignore_id'] = $ignoreId;
        }

        return (bool)(new Database())->execute($sql . ' LIMIT 1', $params)->fetchColumn();
    }

    public static function save(array $payload, ?int $id = null): array
    {
        self::ensureSchema();

        $normalized = self::normalizePayload($payload, $id);
        $slug = (string)$normalized['slug'];

        if (self::slugExists($slug, $id)) {
            throw new \RuntimeException('Já existe um plano com este código interno.');
        }

        if ($id !== null && $id > 0) {
            $existing = self::findById($id);
            if (!$existing) {
                throw new \RuntimeException('Plano não encontrado para edição.');
            }

            $setParts = [];
            $params = [':id' => $id];
            foreach ($normalized as $field => $value) {
                $setParts[] = "{$field} = :{$field}";
                $params[":{$field}"] = $value;
            }
            $setParts[] = 'updated_at = NOW()';

            (new Database())->execute(
                'UPDATE mxx_plans SET ' . implode(', ', $setParts) . ' WHERE id = :id LIMIT 1',
                $params
            );

            $savedPlan = self::findById($id) ?? [];
            if ($savedPlan !== []) {
                self::syncActiveBalancesForPlan($savedPlan);
            }

            PlanRuntimeService::invalidateForPlanId($id);

            return $savedPlan;
        }

        $nextId = self::nextId();
        $normalized['id'] = $nextId;
        $normalized['created_at'] = date('Y-m-d H:i:s');
        $normalized['updated_at'] = date('Y-m-d H:i:s');

        (new Database('mxx_plans'))->insert($normalized);

        return self::findById($nextId) ?? [];
    }

    public static function setStatus(int $id, string $status): bool
    {
        $status = strtolower(trim($status)) === 'inactive' ? 'inactive' : 'active';
        $updated = (new Database())->execute(
            'UPDATE mxx_plans SET status = :status, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            [
                ':status' => $status,
                ':id' => $id,
            ]
        )->rowCount() > 0;

        if ($updated) {
            PlanRuntimeService::invalidateForPlanId($id);
        }

        return $updated;
    }

    public static function softDelete(int $id): array
    {
        $usage = self::usageSummary($id);
        $hasUsage = array_sum($usage) > 0;

        if ($hasUsage) {
            return [
                'allowed' => false,
                'message' => 'Este plano possui vínculos ativos ou históricos e não pode ser excluído.',
                'usage' => $usage,
            ];
        }

        $updated = (new Database())->execute(
            "UPDATE mxx_plans
             SET status = 'inactive',
                 deleted_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id
               AND deleted_at IS NULL
             LIMIT 1",
            [':id' => $id]
        )->rowCount() > 0;

        if ($updated) {
            PlanRuntimeService::invalidateForPlanId($id);
        }

        return [
            'allowed' => $updated,
            'message' => $updated ? 'Plano removido com segurança.' : 'Plano não encontrado ou já removido.',
            'usage' => $usage,
        ];
    }

    public static function usageSummary(int $id): array
    {
        $db = new Database();

        $counts = [
            'subscriptions' => (int)$db->execute(
                'SELECT COUNT(*) FROM mxx_user_plans WHERE plan_id = :id',
                [':id' => $id]
            )->fetchColumn(),
            'active_subscriptions' => (int)$db->execute(
                "SELECT COUNT(*) FROM mxx_user_plans WHERE plan_id = :id AND status = 'active' AND status_payment = 'confirmed'",
                [':id' => $id]
            )->fetchColumn(),
            'tenancies' => (int)$db->execute(
                'SELECT COUNT(*) FROM tenancies WHERE active_plan_id = :id',
                [':id' => $id]
            )->fetchColumn(),
            'payments' => (int)$db->execute(
                'SELECT COUNT(*)
                   FROM webhook_pix wp
                   INNER JOIN mxx_user_plans up ON up.id = wp.user_plain_id
                  WHERE up.plan_id = :id',
                [':id' => $id]
            )->fetchColumn(),
            'snapshots' => (int)$db->execute(
                'SELECT COUNT(*) FROM tenancy_balance WHERE plan_id = :id',
                [':id' => $id]
            )->fetchColumn(),
        ];

        return $counts;
    }

    public static function nextId(): int
    {
        $next = (int)(new Database())->execute(
            'SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM mxx_plans'
        )->fetchColumn();

        return max(1, $next);
    }

    public static function availableTypes(): array
    {
        $rows = (new Database())->execute(
            'SELECT DISTINCT type_plan FROM mxx_plans WHERE deleted_at IS NULL ORDER BY type_plan ASC'
        )->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        $types = array_values(array_filter(array_map('strval', $rows)));
        if ($types === []) {
            return ['sms', 'voice', 'torpedo', 'whatsapp', 'bootstrap'];
        }

        return $types;
    }

    public static function availableBillingCycles(): array
    {
        return ['monthly', 'quarterly', 'semiannual', 'annual', 'custom'];
    }

    public static function normalizeRow(array $row): array
    {
        $modules = self::parseModulesJson($row['modules_json'] ?? null);
        $defaults = self::defaultModulesForLegacyRow($row);
        $modules = array_replace($defaults, $modules);

        return [
            'id' => (int)($row['id'] ?? 0),
            'name_plan' => (string)($row['name_plan'] ?? ''),
            'slug' => (string)($row['slug'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'amount_plan' => (float)($row['amount_plan'] ?? 0),
            'billing_cycle' => (string)($row['billing_cycle'] ?? 'monthly'),
            'status' => (string)($row['status'] ?? 'inactive'),
            'type_plan' => (string)($row['type_plan'] ?? 'custom'),
            'payment_type' => (string)($row['payment_type'] ?? 'Pré-Pago'),
            'simultaneous_access' => (int)($row['simultaneous_access'] ?? 0),
            'users_create' => (string)($row['users_create'] ?? 'n'),
            'users_limit' => (int)($row['users_limit'] ?? 0),
            'sms_limit' => (int)($row['sms_limit'] ?? 0),
            'voice_limit' => (int)($row['voice_limit'] ?? 0),
            'campaigns_limit' => (int)($row['camp_qtd'] ?? 0),
            'trunks' => (int)($row['trunks'] ?? 0),
            'whatsapp_accounts' => (int)($row['whatsapp_accounts'] ?? 0),
            'templates_limit' => (int)($row['templates_limit'] ?? 0),
            'webrtc_enabled' => (int)($row['webrtc_enabled'] ?? 0) === 1,
            'internal_notes' => (string)($row['internal_notes'] ?? ''),
            'modules' => $modules,
            'modules_json' => json_encode($modules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'reports_label' => (string)($row['rports'] ?? ''),
            'service_fee' => (float)($row['service_fee'] ?? 0),
            'value_sms' => (float)($row['value_sms'] ?? 0),
            'value_voice' => (float)($row['value_voice'] ?? 0),
            'voice_open_rate' => (float)($row['voice_open_rate'] ?? 0),
            'voice_smart_rate' => (float)($row['voice_smart_rate'] ?? 0),
            'value_torpedo' => (float)($row['value_torpedo'] ?? 0),
            'value_whatsapp' => (float)($row['value_whatsapp'] ?? 0),
            'value_whatsapp_marketing' => (float)($row['value_whatsapp_marketing'] ?? ($row['value_whatsapp'] ?? 0)),
            'value_whatsapp_utility' => (float)($row['value_whatsapp_utility'] ?? ($row['value_whatsapp'] ?? 0)),
            'value_whatsapp_authentication' => (float)($row['value_whatsapp_authentication'] ?? ($row['value_whatsapp'] ?? 0)),
            'whatsapp_voice_enabled' => (int)($row['whatsapp_voice_enabled'] ?? 0) === 1,
            'whatsapp_voice_price_per_minute' => (float)($row['whatsapp_voice_price_per_minute'] ?? 0),
            'whatsapp_voice_markup_percent' => (float)($row['whatsapp_voice_markup_percent'] ?? 0),
            'whatsapp_voice_billing_pulse_seconds' => (int)($row['whatsapp_voice_billing_pulse_seconds'] ?? 6),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'deleted_at' => $row['deleted_at'] ?? null,
        ];
    }

    public static function buildSnapshotPayload(array $plan): array
    {
        $normalized = self::normalizeRow($plan);

        return [
            'catalog' => [
                'id' => $normalized['id'],
                'slug' => $normalized['slug'],
                'name_plan' => $normalized['name_plan'],
                'type_plan' => $normalized['type_plan'],
            ],
            'pricing' => [
                'amount_plan' => $normalized['amount_plan'],
                'billing_cycle' => $normalized['billing_cycle'],
                'service_fee' => $normalized['service_fee'],
                'value_sms' => $normalized['value_sms'],
                'value_voice' => $normalized['value_voice'],
                'voice_open_rate' => $normalized['voice_open_rate'],
                'voice_smart_rate' => $normalized['voice_smart_rate'],
                'value_torpedo' => $normalized['value_torpedo'],
                'value_whatsapp' => $normalized['value_whatsapp'],
                'value_whatsapp_marketing' => $normalized['value_whatsapp_marketing'],
                'value_whatsapp_utility' => $normalized['value_whatsapp_utility'],
                'value_whatsapp_authentication' => $normalized['value_whatsapp_authentication'],
                'whatsapp_voice_enabled' => $normalized['whatsapp_voice_enabled'],
                'whatsapp_voice_price_per_minute' => $normalized['whatsapp_voice_price_per_minute'],
                'whatsapp_voice_markup_percent' => $normalized['whatsapp_voice_markup_percent'],
                'whatsapp_voice_billing_pulse_seconds' => $normalized['whatsapp_voice_billing_pulse_seconds'],
            ],
            'limits' => [
                'simultaneous_access' => $normalized['simultaneous_access'],
                'users_limit' => $normalized['users_limit'],
                'sms_limit' => $normalized['sms_limit'],
                'voice_limit' => $normalized['voice_limit'],
                'campaigns_limit' => $normalized['campaigns_limit'],
                'trunks' => $normalized['trunks'],
                'whatsapp_accounts' => $normalized['whatsapp_accounts'],
                'templates_limit' => $normalized['templates_limit'],
            ],
            'features' => [
                'users_create' => $normalized['users_create'] === 'y',
                'webrtc_enabled' => $normalized['webrtc_enabled'],
                'modules' => $normalized['modules'],
            ],
            'description' => $normalized['description'],
            'reports_label' => $normalized['reports_label'],
            'captured_at' => date('c'),
        ];
    }

    private static function syncActiveBalancesForPlan(array $plan): void
    {
        $planId = (int)($plan['id'] ?? 0);
        if ($planId <= 0) {
            return;
        }

        $setParts = [
            'tb.value_sms = :value_sms',
            'tb.value_voice = :value_voice',
            'tb.value_torpedo = :value_torpedo',
            'tb.updated_at = NOW()',
        ];

        $params = [
            ':plan_id' => $planId,
            ':value_sms' => (float)($plan['value_sms'] ?? 0),
            ':value_voice' => (float)($plan['value_voice'] ?? 0),
            ':value_torpedo' => (float)($plan['value_torpedo'] ?? 0),
        ];

        if (self::columnExists('tenancy_balance', 'value_whatsapp')) {
            $setParts[] = 'tb.value_whatsapp = :value_whatsapp';
            $params[':value_whatsapp'] = (float)($plan['value_whatsapp'] ?? 0);
        }

        if (self::columnExists('tenancy_balance', 'voice_open_rate')) {
            $setParts[] = 'tb.voice_open_rate = :voice_open_rate';
            $params[':voice_open_rate'] = (float)($plan['voice_open_rate'] ?? $plan['value_voice'] ?? 0);
        }

        if (self::columnExists('tenancy_balance', 'voice_smart_rate')) {
            $setParts[] = 'tb.voice_smart_rate = :voice_smart_rate';
            $params[':voice_smart_rate'] = (float)($plan['voice_smart_rate'] ?? $plan['value_voice'] ?? 0);
        }

        if (self::columnExists('tenancy_balance', 'service_fee')) {
            $setParts[] = 'tb.service_fee = :service_fee';
            $params[':service_fee'] = (float)($plan['service_fee'] ?? 0);
        }

        if (self::columnExists('tenancy_balance', 'snapshot_json')) {
            $setParts[] = 'tb.snapshot_json = :snapshot_json';
            $params[':snapshot_json'] = json_encode(
                self::buildSnapshotPayload($plan),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        if (self::columnExists('tenancy_balance', 'applied_plan_name')) {
            $setParts[] = 'tb.applied_plan_name = :applied_plan_name';
            $params[':applied_plan_name'] = (string)($plan['name_plan'] ?? '');
        }

        if (self::columnExists('tenancy_balance', 'applied_billing_cycle')) {
            $setParts[] = 'tb.applied_billing_cycle = :applied_billing_cycle';
            $params[':applied_billing_cycle'] = (string)($plan['billing_cycle'] ?? 'monthly');
        }

        if (self::columnExists('tenancy_balance', 'applied_amount_plan')) {
            $setParts[] = 'tb.applied_amount_plan = :applied_amount_plan';
            $params[':applied_amount_plan'] = (float)($plan['amount_plan'] ?? 0);
        }

        (new Database())->execute(
            'UPDATE tenancy_balance tb
             INNER JOIN tenancies t ON t.id = tb.tenancy_id
             SET ' . implode(', ', $setParts) . '
             WHERE tb.plan_id = :plan_id
               AND t.active_plan_id = :plan_id',
            $params
        );
    }

    private static function normalizePayload(array $payload, ?int $id = null): array
    {
        $slug = self::slugify((string)($payload['slug'] ?? $payload['name_plan'] ?? ''));
        if ($slug === '') {
            throw new \RuntimeException('Informe um código interno válido para o plano.');
        }

        $modules = self::normalizeModulesInput($payload['modules'] ?? []);
        $valueVoiceSource = $payload['value_voice']
            ?? $payload['voice_smart_rate']
            ?? $payload['voice_open_rate']
            ?? 0;
        $valueVoice = round((float)$valueVoiceSource, 4);
        $voiceOpenRate = array_key_exists('voice_open_rate', $payload)
            ? round((float)($payload['voice_open_rate'] ?? 0), 4)
            : $valueVoice;
        $voiceSmartRate = array_key_exists('voice_smart_rate', $payload)
            ? round((float)($payload['voice_smart_rate'] ?? 0), 4)
            : $valueVoice;
        $whatsVoicePrice = round((float)($payload['whatsapp_voice_price_per_minute'] ?? 0), 4);
        $whatsVoiceEnabled = array_key_exists('whatsapp_voice_enabled', $payload)
            ? !empty($payload['whatsapp_voice_enabled'])
            : $whatsVoicePrice > 0;

        return [
            'name_plan' => trim((string)($payload['name_plan'] ?? '')),
            'slug' => $slug,
            'description' => trim((string)($payload['description'] ?? '')),
            'amount_plan' => round((float)($payload['amount_plan'] ?? 0), 2),
            'billing_cycle' => self::normalizeBillingCycle((string)($payload['billing_cycle'] ?? 'monthly')),
            'status' => self::normalizeStatus((string)($payload['status'] ?? 'active')),
            'type_plan' => trim((string)($payload['type_plan'] ?? 'custom')),
            'payment_type' => trim((string)($payload['payment_type'] ?? 'Pré-Pago')),
            'simultaneous_access' => (int)($payload['simultaneous_access'] ?? 0),
            'users_create' => (
                !empty($modules['users'])
                || !empty($modules['create_users'])
                || (!empty($payload['users_create']) && (string)$payload['users_create'] !== 'n')
            ) ? 'y' : 'n',
            'users_limit' => (int)($payload['users_limit'] ?? 0),
            'sms_limit' => (int)($payload['sms_limit'] ?? 0),
            'voice_limit' => (int)($payload['voice_limit'] ?? 0),
            'camp_qtd' => (int)($payload['campaigns_limit'] ?? 0),
            'trunks' => (int)($payload['trunks'] ?? 0),
            'whatsapp_accounts' => (int)($payload['whatsapp_accounts'] ?? 0),
            'templates_limit' => (int)($payload['templates_limit'] ?? 0),
            'webrtc_enabled' => !empty($payload['webrtc_enabled']) ? 1 : 0,
            'modules_json' => json_encode($modules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'internal_notes' => trim((string)($payload['internal_notes'] ?? '')),
            'rports' => !empty($payload['reports_label']) ? trim((string)$payload['reports_label']) : 'Relatórios em Tempo Real',
            'service_fee' => round((float)($payload['service_fee'] ?? 0), 4),
            'value_sms' => round((float)($payload['value_sms'] ?? 0), 4),
            'value_voice' => $valueVoice,
            'voice_open_rate' => $voiceOpenRate,
            'voice_smart_rate' => $voiceSmartRate,
            'value_torpedo' => round((float)($payload['value_torpedo'] ?? 0), 4),
            'value_whatsapp' => round((float)($payload['value_whatsapp'] ?? 0), 4),
            'value_whatsapp_marketing' => round((float)($payload['value_whatsapp_marketing'] ?? 0), 4),
            'value_whatsapp_utility' => round((float)($payload['value_whatsapp_utility'] ?? 0), 4),
            'value_whatsapp_authentication' => round((float)($payload['value_whatsapp_authentication'] ?? 0), 4),
            'whatsapp_voice_enabled' => $whatsVoiceEnabled ? 1 : 0,
            'whatsapp_voice_price_per_minute' => $whatsVoicePrice,
            'whatsapp_voice_markup_percent' => round((float)($payload['whatsapp_voice_markup_percent'] ?? 0), 4),
            'whatsapp_voice_billing_pulse_seconds' => max(1, (int)($payload['whatsapp_voice_billing_pulse_seconds'] ?? 6)),
        ];
    }

    private static function ensurePlanTable(): void
    {
        $missingColumns = [];

        $definitions = [
            'slug' => "ADD COLUMN slug VARCHAR(120) NULL AFTER name_plan",
            'billing_cycle' => "ADD COLUMN billing_cycle VARCHAR(30) NOT NULL DEFAULT 'monthly' AFTER amount_plan",
            'users_limit' => "ADD COLUMN users_limit INT NOT NULL DEFAULT 0 AFTER users_create",
            'sms_limit' => "ADD COLUMN sms_limit INT NOT NULL DEFAULT 0 AFTER users_limit",
            'voice_limit' => "ADD COLUMN voice_limit INT NOT NULL DEFAULT 0 AFTER sms_limit",
            'templates_limit' => "ADD COLUMN templates_limit INT NOT NULL DEFAULT 0 AFTER whatsapp_accounts",
            'webrtc_enabled' => "ADD COLUMN webrtc_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER templates_limit",
            'modules_json' => "ADD COLUMN modules_json LONGTEXT NULL AFTER webrtc_enabled",
            'internal_notes' => "ADD COLUMN internal_notes TEXT NULL AFTER modules_json",
            'whatsapp_voice_enabled' => "ADD COLUMN whatsapp_voice_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER value_whatsapp_authentication",
            'whatsapp_voice_price_per_minute' => "ADD COLUMN whatsapp_voice_price_per_minute DECIMAL(12,4) NOT NULL DEFAULT 0.0000 AFTER whatsapp_voice_enabled",
            'whatsapp_voice_markup_percent' => "ADD COLUMN whatsapp_voice_markup_percent DECIMAL(8,4) NOT NULL DEFAULT 0.0000 AFTER whatsapp_voice_price_per_minute",
            'whatsapp_voice_billing_pulse_seconds' => "ADD COLUMN whatsapp_voice_billing_pulse_seconds INT NOT NULL DEFAULT 6 AFTER whatsapp_voice_markup_percent",
            'deleted_at' => "ADD COLUMN deleted_at DATETIME NULL AFTER internal_notes",
            'created_at' => "ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER deleted_at",
            'updated_at' => "ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];

        foreach ($definitions as $column => $definition) {
            if (!self::columnExists('mxx_plans', $column)) {
                $missingColumns[] = $definition;
            }
        }

        if ($missingColumns !== []) {
            (new Database())->execute('ALTER TABLE mxx_plans ' . implode(', ', $missingColumns));
            self::$columnCache = [];
        }

        if (!self::indexExists('mxx_plans', 'unq_mxx_plans_slug')) {
            try {
                (new Database())->execute('ALTER TABLE mxx_plans ADD UNIQUE KEY unq_mxx_plans_slug (slug)');
                self::$indexCache = [];
            } catch (\Throwable) {
            }
        }
    }

    private static function ensureSnapshotTableShape(): void
    {
        $missingColumns = [];

        $definitions = [
            'snapshot_json' => "ADD COLUMN snapshot_json LONGTEXT NULL AFTER service_fee",
            'applied_plan_name' => "ADD COLUMN applied_plan_name VARCHAR(120) NULL AFTER snapshot_json",
            'applied_billing_cycle' => "ADD COLUMN applied_billing_cycle VARCHAR(30) NULL AFTER applied_plan_name",
            'applied_amount_plan' => "ADD COLUMN applied_amount_plan DECIMAL(10,2) NULL AFTER applied_billing_cycle",
        ];

        foreach ($definitions as $column => $definition) {
            if (!self::columnExists('tenancy_balance', $column)) {
                $missingColumns[] = $definition;
            }
        }

        if ($missingColumns !== []) {
            (new Database())->execute('ALTER TABLE tenancy_balance ' . implode(', ', $missingColumns));
            self::$columnCache = [];
        }
    }

    private static function seedLegacyPlans(): void
    {
        $rows = (new Database())->execute('SELECT * FROM mxx_plans ORDER BY id ASC')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $existingSlugs = [];

        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $slug = trim((string)($row['slug'] ?? ''));
            if ($slug === '') {
                $slug = self::uniqueLegacySlug($row, $existingSlugs);
            } else {
                $slug = self::slugify($slug);
                if ($slug === '') {
                    $slug = self::uniqueLegacySlug($row, $existingSlugs);
                }
            }
            $existingSlugs[$slug] = true;

            $billingCycle = trim((string)($row['billing_cycle'] ?? ''));
            if ($billingCycle === '') {
                $billingCycle = ((string)($row['type_plan'] ?? '') === 'bootstrap') ? 'custom' : 'monthly';
            } else {
                $billingCycle = self::normalizeBillingCycle($billingCycle);
            }

            $modulesJson = trim((string)($row['modules_json'] ?? ''));
            if ($modulesJson === '') {
                $modulesJson = json_encode(
                    self::defaultModulesForLegacyRow($row),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }

            $createdAt = trim((string)($row['created_at'] ?? '')) ?: date('Y-m-d H:i:s');
            $updatedAt = trim((string)($row['updated_at'] ?? '')) ?: date('Y-m-d H:i:s');

            $usersLimit = isset($row['users_limit']) ? (int)$row['users_limit'] : 0;
            if ($usersLimit === 0 && (string)($row['users_create'] ?? 'n') === 'y') {
                $usersLimit = max(1, (int)($row['simultaneous_access'] ?? 0));
            }

            $reports = trim((string)($row['rports'] ?? ''));
            if ($reports === '') {
                $reports = 'Relatórios em Tempo Real';
            }

            (new Database())->execute(
                "UPDATE mxx_plans
                 SET slug = :slug,
                     billing_cycle = :billing_cycle,
                     users_limit = :users_limit,
                     modules_json = :modules_json,
                     rports = :rports,
                     created_at = :created_at,
                     updated_at = :updated_at
                 WHERE id = :id",
                [
                    ':slug' => $slug,
                    ':billing_cycle' => $billingCycle,
                    ':users_limit' => $usersLimit,
                    ':modules_json' => $modulesJson,
                    ':rports' => $reports,
                    ':created_at' => $createdAt,
                    ':updated_at' => $updatedAt,
                    ':id' => $id,
                ]
            );
        }
    }

    private static function sessionVerificationFresh(string $key): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        SessionUser::ensureSessionStarted();
        $timestamp = (int)($_SESSION[$key] ?? 0);
        return $timestamp > 0 && (time() - $timestamp) < self::VERIFY_TTL_SECONDS;
    }

    private static function touchSessionVerification(string $key): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        SessionUser::ensureSessionStarted();
        $_SESSION[$key] = time();
    }

    private static function uniqueLegacySlug(array $row, array $existingSlugs): string
    {
        $base = self::slugify(
            trim((string)($row['name_plan'] ?? '')) . '-' . trim((string)($row['type_plan'] ?? 'custom'))
        );
        $base = $base !== '' ? $base : 'plano';
        $candidate = $base;
        $suffix = 1;

        while (isset($existingSlugs[$candidate]) || self::slugExists($candidate)) {
            $candidate = $base . '-' . ((int)($row['id'] ?? 0) ?: $suffix);
            $suffix++;
        }

        return $candidate;
    }

    private static function defaultModulesForLegacyRow(array $row): array
    {
        $type = strtolower(trim((string)($row['type_plan'] ?? '')));
        $canManageUsers = strtolower((string)($row['users_create'] ?? 'n')) === 'y';
        $reportsEnabled = trim((string)($row['rports'] ?? '')) !== '';
        $voiceEnabled = in_array($type, ['voice', 'bootstrap'], true);
        $whatsappEnabled = in_array($type, ['whatsapp', 'bootstrap'], true);
        $smsEnabled = in_array($type, ['sms', 'bootstrap'], true);

        return [
            'administrative' => true,
            'users' => $canManageUsers,
            'create_users' => $canManageUsers,
            'rates' => $canManageUsers,
            'permissions' => $canManageUsers,
            'reports' => $reportsEnabled,
            'campaigns' => $smsEnabled || (int)($row['camp_qtd'] ?? 0) !== 0,
            'sms' => $smsEnabled,
            'voice' => $voiceEnabled,
            'callcenter' => $voiceEnabled,
            'webrtc' => $voiceEnabled || (int)($row['webrtc_enabled'] ?? 0) === 1,
            'whatsapp' => $whatsappEnabled,
            'templates' => $whatsappEnabled,
            'trunks' => $voiceEnabled,
        ];
    }

    private static function normalizeModulesInput(mixed $modules): array
    {
        if (is_string($modules)) {
            $decoded = json_decode($modules, true);
            if (is_array($decoded)) {
                $modules = $decoded;
            } else {
                $modules = array_filter(array_map('trim', explode(',', $modules)));
            }
        }

        $normalized = [
            'administrative' => false,
            'users' => false,
            'create_users' => false,
            'rates' => false,
            'permissions' => false,
            'reports' => false,
            'campaigns' => false,
            'sms' => false,
            'voice' => false,
            'callcenter' => false,
            'webrtc' => false,
            'whatsapp' => false,
            'templates' => false,
            'trunks' => false,
        ];

        $providedKeys = [];

        if (is_array($modules)) {
            foreach ($modules as $key => $value) {
                if (is_int($key)) {
                    $name = trim((string)$value);
                    if ($name !== '' && array_key_exists($name, $normalized)) {
                        $normalized[$name] = true;
                        $providedKeys[$name] = true;
                    }
                    continue;
                }

                $name = trim((string)$key);
                if (array_key_exists($name, $normalized)) {
                    $normalized[$name] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    $providedKeys[$name] = true;
                }
            }
        }

        $normalized['create_users'] = $normalized['create_users'] || $normalized['users'];
        $normalized['campaigns'] = $normalized['campaigns'] || $normalized['sms'];

        if (!isset($providedKeys['callcenter']) && $normalized['voice']) {
            $normalized['callcenter'] = true;
        }

        if (!isset($providedKeys['administrative'])) {
            $normalized['administrative'] = true;
        }

        return $normalized;
    }

    private static function parseModulesJson(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? self::normalizeModulesInput($decoded) : [];
    }

    private static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return $status === 'inactive' ? 'inactive' : 'active';
    }

    private static function normalizeBillingCycle(string $billingCycle): string
    {
        $billingCycle = strtolower(trim($billingCycle));
        return in_array($billingCycle, self::availableBillingCycles(), true) ? $billingCycle : 'monthly';
    }

    private static function slugify(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';
        return trim($value, '-');
    }

    private static function columnExists(string $table, string $column): bool
    {
        $key = strtolower($table . '.' . $column);
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }

        $exists = (bool)(new Database())->execute(
            'SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column
             LIMIT 1',
            [
                ':table' => $table,
                ':column' => $column,
            ]
        )->fetchColumn();

        self::$columnCache[$key] = $exists;
        return $exists;
    }

    private static function indexExists(string $table, string $index): bool
    {
        $key = strtolower($table . '.' . $index);
        if (array_key_exists($key, self::$indexCache)) {
            return self::$indexCache[$key];
        }

        $exists = (bool)(new Database())->execute(
            'SELECT 1
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND INDEX_NAME = :index_name
             LIMIT 1',
            [
                ':table' => $table,
                ':index_name' => $index,
            ]
        )->fetchColumn();

        self::$indexCache[$key] = $exists;
        return $exists;
    }
}
