<?php

namespace App\Service;

use App\Session\User as SessionUser;
use WilliamCosta\DatabaseManager\Database;

class PlatformGlobalCostService
{
    public const TABLE = 'platform_global_costs';
    public const LOG_TABLE = 'platform_global_cost_logs';

    private const MODULES = ['VOICE', 'SMS', 'WHATSAPP'];
    private static array $columnCache = [];

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        (new Database())->execute(
            "CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                module VARCHAR(32) NOT NULL,
                route_key VARCHAR(64) NULL,
                country_code VARCHAR(8) NULL,
                prefix_code VARCHAR(32) NULL,
                provider VARCHAR(120) NULL,
                carrier VARCHAR(120) NULL,
                cost_price DECIMAL(14,6) NOT NULL DEFAULT 0.000000,
                currency VARCHAR(8) NOT NULL DEFAULT 'BRL',
                active TINYINT(1) NOT NULL DEFAULT 1,
                effective_date DATETIME NOT NULL,
                notes TEXT NULL,
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_platform_global_cost_lookup (module, active, effective_date),
                KEY idx_platform_global_cost_route (module, route_key, country_code, prefix_code),
                KEY idx_platform_global_cost_provider (provider, carrier)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        (new Database())->execute(
            "CREATE TABLE IF NOT EXISTS " . self::LOG_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                global_cost_id BIGINT UNSIGNED NULL,
                action_name VARCHAR(32) NOT NULL,
                module VARCHAR(32) NOT NULL,
                route_key VARCHAR(64) NULL,
                country_code VARCHAR(8) NULL,
                prefix_code VARCHAR(32) NULL,
                provider VARCHAR(120) NULL,
                carrier VARCHAR(120) NULL,
                old_cost_price DECIMAL(14,6) NULL,
                new_cost_price DECIMAL(14,6) NULL,
                old_currency VARCHAR(8) NULL,
                new_currency VARCHAR(8) NULL,
                old_active TINYINT(1) NULL,
                new_active TINYINT(1) NULL,
                effective_date DATETIME NULL,
                notes TEXT NULL,
                impact_summary VARCHAR(255) NULL,
                payload_json LONGTEXT NULL,
                actor_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_platform_global_cost_log_cost (global_cost_id, created_at),
                KEY idx_platform_global_cost_log_module (module, created_at),
                KEY idx_platform_global_cost_log_actor (actor_user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function ensureRouteCatalog(): void
    {
        self::ensureSchema();

        foreach ([
            '/global-costs',
            '/global-costs/search',
            '/global-costs/history',
            '/global-costs/save',
            '/global-costs/{id}/status',
        ] as $path) {
            (new Database())->execute(
                'INSERT IGNORE INTO sys_routes (module_name, route_path) VALUES (:module_name, :route_path)',
                [
                    ':module_name' => 'Financeiro: Custos Globais',
                    ':route_path' => $path,
                ]
            );
        }
    }

    public static function currentRows(array $filters = []): array
    {
        self::ensureSchema();

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['module'])) {
            $where[] = 'c.module = :module';
            $params[':module'] = self::normalizeModule((string)$filters['module']);
        }

        if (array_key_exists('active', $filters) && $filters['active'] !== '' && $filters['active'] !== null) {
            $where[] = 'c.active = :active';
            $params[':active'] = !empty($filters['active']) ? 1 : 0;
        }

        if (!empty($filters['search'])) {
            $where[] = '(c.route_key LIKE :search OR c.country_code LIKE :search OR c.prefix_code LIKE :search OR c.provider LIKE :search OR c.carrier LIKE :search OR c.notes LIKE :search)';
            $params[':search'] = '%' . trim((string)$filters['search']) . '%';
        }

        return (new Database())->execute(
            "SELECT
                c.*,
                uc.name AS created_by_name,
                uu.name AS updated_by_name
             FROM " . self::TABLE . " c
             LEFT JOIN users uc ON uc.id = c.created_by
             LEFT JOIN users uu ON uu.id = c.updated_by
             WHERE " . implode(' AND ', $where) . "
             ORDER BY c.module ASC, c.route_key ASC, c.country_code ASC, c.prefix_code ASC, c.provider ASC, c.updated_at DESC",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function history(array $filters = []): array
    {
        self::ensureSchema();

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['module'])) {
            $where[] = 'l.module = :module';
            $params[':module'] = self::normalizeModule((string)$filters['module']);
        }

        if (!empty($filters['search'])) {
            $where[] = '(l.route_key LIKE :search OR l.country_code LIKE :search OR l.prefix_code LIKE :search OR l.provider LIKE :search OR l.carrier LIKE :search OR l.notes LIKE :search OR l.impact_summary LIKE :search)';
            $params[':search'] = '%' . trim((string)$filters['search']) . '%';
        }

        $limit = max(20, min(500, (int)($filters['limit'] ?? 120)));

        return (new Database())->execute(
            "SELECT
                l.*,
                u.name AS actor_name
             FROM " . self::LOG_TABLE . " l
             LEFT JOIN users u ON u.id = l.actor_user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT {$limit}",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function findById(int $id): ?array
    {
        self::ensureSchema();

        $row = (new Database(self::TABLE))
            ->select('id = :id', [':id' => $id], '', 1)
            ->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function save(array $payload, int $actorUserId): array
    {
        self::ensureSchema();

        $normalized = self::normalizePayload($payload);
        $id = isset($payload['id']) && $payload['id'] !== '' ? (int)$payload['id'] : null;
        $before = $id ? self::findById($id) : null;

        if ($id && !$before) {
            throw new \RuntimeException('Registro de custo global não encontrado.');
        }

        $db = new Database(self::TABLE);

        if ($before) {
            $fields = $normalized + [
                'updated_by' => $actorUserId,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $db->update('id = :id', $fields, [':id' => $id]);
            $after = self::findById($id) ?: ($before + $fields);
            self::logChange('update', $id, $before, $after, $actorUserId);
            return $after;
        }

        $insertId = $db->insert($normalized + [
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $after = self::findById((int)$insertId);
        if (!$after) {
            throw new \RuntimeException('Falha ao carregar o custo global salvo.');
        }

        self::logChange('create', (int)$insertId, null, $after, $actorUserId);
        return $after;
    }

    public static function updateStatus(int $id, bool $active, int $actorUserId): array
    {
        $before = self::findById($id);
        if (!$before) {
            throw new \RuntimeException('Registro de custo global não encontrado.');
        }

        (new Database(self::TABLE))->update(
            'id = :id',
            [
                'active' => $active ? 1 : 0,
                'updated_by' => $actorUserId,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':id' => $id]
        );

        $after = self::findById($id);
        if (!$after) {
            throw new \RuntimeException('Falha ao atualizar o status do custo global.');
        }

        self::logChange('status', $id, $before, $after, $actorUserId);
        return $after;
    }

    public static function resolve(string $module, array $criteria = [], ?array $fallback = null): array
    {
        self::ensureSchema();

        $module = self::normalizeModule($module);
        $routeKey = self::nullableUpper($criteria['route_key'] ?? null);
        $countryCode = self::nullableUpper($criteria['country_code'] ?? null);
        $prefixCode = self::nullableDigits($criteria['prefix_code'] ?? null);
        $provider = self::nullableLower($criteria['provider'] ?? null);
        $carrier = self::nullableLower($criteria['carrier'] ?? null);

        $row = (new Database())->execute(
            "SELECT *
             FROM " . self::TABLE . "
             WHERE module = :module
               AND active = 1
               AND effective_date <= NOW()
               AND (route_key IS NULL OR route_key = '' OR route_key = :route_key)
               AND (country_code IS NULL OR country_code = '' OR country_code = :country_code)
               AND (prefix_code IS NULL OR prefix_code = '' OR prefix_code = :prefix_code)
               AND (provider IS NULL OR provider = '' OR LOWER(provider) = :provider)
               AND (carrier IS NULL OR carrier = '' OR LOWER(carrier) = :carrier)
             ORDER BY
               CASE WHEN route_key = :route_key AND :route_key <> '' THEN 1 ELSE 0 END DESC,
               CASE WHEN country_code = :country_code AND :country_code <> '' THEN 1 ELSE 0 END DESC,
               CASE WHEN prefix_code = :prefix_code AND :prefix_code <> '' THEN 1 ELSE 0 END DESC,
               CASE WHEN LOWER(provider) = :provider AND :provider <> '' THEN 1 ELSE 0 END DESC,
               CASE WHEN LOWER(carrier) = :carrier AND :carrier <> '' THEN 1 ELSE 0 END DESC,
               effective_date DESC,
               id DESC
             LIMIT 1",
            [
                ':module' => $module,
                ':route_key' => $routeKey ?? '',
                ':country_code' => $countryCode ?? '',
                ':prefix_code' => $prefixCode ?? '',
                ':provider' => $provider ?? '',
                ':carrier' => $carrier ?? '',
            ]
        )->fetch(\PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'resolved' => true,
                'source' => 'platform_global_cost',
                'row' => $row,
                'module' => $module,
                'route_key' => $row['route_key'] ?? null,
                'country_code' => $row['country_code'] ?? null,
                'prefix_code' => $row['prefix_code'] ?? null,
                'provider' => $row['provider'] ?? null,
                'carrier' => $row['carrier'] ?? null,
                'cost_price' => round((float)($row['cost_price'] ?? 0), 6),
                'currency' => strtoupper((string)($row['currency'] ?? 'BRL')),
                'effective_date' => $row['effective_date'] ?? null,
                'notes' => $row['notes'] ?? null,
            ];
        }

        return [
            'resolved' => false,
            'source' => $fallback['source'] ?? 'fallback',
            'row' => null,
            'module' => $module,
            'route_key' => $routeKey,
            'country_code' => $countryCode,
            'prefix_code' => $prefixCode,
            'provider' => $provider,
            'carrier' => $carrier,
            'cost_price' => round((float)($fallback['cost_price'] ?? 0), 6),
            'currency' => strtoupper((string)($fallback['currency'] ?? 'BRL')),
            'effective_date' => $fallback['effective_date'] ?? null,
            'notes' => $fallback['notes'] ?? null,
        ];
    }

    public static function resolveAmount(string $module, array $criteria = [], float $fallback = 0.0): float
    {
        $resolved = self::resolve($module, $criteria, [
            'cost_price' => $fallback,
            'currency' => 'BRL',
            'source' => 'fallback_amount',
        ]);

        return round((float)($resolved['cost_price'] ?? 0), 6);
    }

    public static function auditSummary(): array
    {
        self::ensureSchema();

        $rows = self::currentRows();
        $byModule = [
            'VOICE' => 0,
            'SMS' => 0,
            'WHATSAPP' => 0,
        ];

        foreach ($rows as $row) {
            $module = strtoupper((string)($row['module'] ?? ''));
            if (isset($byModule[$module])) {
                $byModule[$module]++;
            }
        }

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'total_rows' => count($rows),
            'active_rows' => count(array_filter($rows, static fn (array $row): bool => !empty($row['active']))),
            'by_module' => $byModule,
        ];
    }

    private static function normalizePayload(array $payload): array
    {
        $module = self::normalizeModule((string)($payload['module'] ?? ''));
        $costPrice = round(max(0, (float)($payload['cost_price'] ?? 0)), 6);
        $currency = strtoupper(trim((string)($payload['currency'] ?? 'BRL')));
        if ($costPrice <= 0) {
            throw new \RuntimeException('Informe um custo válido maior que zero.');
        }
        if (!in_array($currency, ['BRL', 'USD', 'EUR'], true)) {
            throw new \RuntimeException('Moeda inválida para custo global.');
        }

        $effectiveDate = trim((string)($payload['effective_date'] ?? ''));
        if ($effectiveDate === '') {
            $effectiveDate = date('Y-m-d H:i:s');
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            $effectiveDate .= ' 00:00:00';
        }

        return [
            'module' => $module,
            'route_key' => self::nullableUpper($payload['route_key'] ?? null),
            'country_code' => self::nullableUpper($payload['country_code'] ?? null),
            'prefix_code' => self::nullableDigits($payload['prefix_code'] ?? null),
            'provider' => self::nullableString($payload['provider'] ?? null),
            'carrier' => self::nullableString($payload['carrier'] ?? null),
            'cost_price' => $costPrice,
            'currency' => $currency,
            'active' => !array_key_exists('active', $payload) || !empty($payload['active']) ? 1 : 0,
            'effective_date' => $effectiveDate,
            'notes' => self::nullableString($payload['notes'] ?? null),
        ];
    }

    private static function normalizeModule(string $module): string
    {
        $module = strtoupper(trim($module));
        if (!in_array($module, self::MODULES, true)) {
            throw new \RuntimeException('Módulo de custo global inválido.');
        }

        return $module;
    }

    private static function logChange(string $action, int $globalCostId, ?array $before, ?array $after, int $actorUserId): void
    {
        try {
            $target = $after ?: $before ?: [];
            (new Database(self::LOG_TABLE))->insert([
                'global_cost_id' => $globalCostId,
                'action_name' => $action,
                'module' => (string)($target['module'] ?? ''),
                'route_key' => $target['route_key'] ?? null,
                'country_code' => $target['country_code'] ?? null,
                'prefix_code' => $target['prefix_code'] ?? null,
                'provider' => $target['provider'] ?? null,
                'carrier' => $target['carrier'] ?? null,
                'old_cost_price' => $before !== null ? (float)($before['cost_price'] ?? 0) : null,
                'new_cost_price' => $after !== null ? (float)($after['cost_price'] ?? 0) : null,
                'old_currency' => $before['currency'] ?? null,
                'new_currency' => $after['currency'] ?? null,
                'old_active' => $before['active'] ?? null,
                'new_active' => $after['active'] ?? null,
                'effective_date' => $target['effective_date'] ?? null,
                'notes' => $target['notes'] ?? null,
                'impact_summary' => self::impactSummary($before, $after),
                'payload_json' => json_encode([
                    'before' => $before,
                    'after' => $after,
                    'session_user' => SessionUser::getLogged(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'actor_user_id' => $actorUserId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[platform_global_cost_log] ' . $e->getMessage());
        }
    }

    private static function impactSummary(?array $before, ?array $after): string
    {
        if ($before === null && $after !== null) {
            return 'Novo custo global publicado.';
        }

        if ($before !== null && $after !== null) {
            $parts = [];
            if ((float)($before['cost_price'] ?? 0) !== (float)($after['cost_price'] ?? 0)) {
                $parts[] = 'valor alterado';
            }
            if ((string)($before['currency'] ?? '') !== (string)($after['currency'] ?? '')) {
                $parts[] = 'moeda alterada';
            }
            if ((int)($before['active'] ?? 0) !== (int)($after['active'] ?? 0)) {
                $parts[] = !empty($after['active']) ? 'reativado' : 'desativado';
            }

            return $parts !== [] ? implode(', ', $parts) : 'Metadados do custo global atualizados.';
        }

        return 'Registro histórico de custo global.';
    }

    private static function nullableUpper(mixed $value): ?string
    {
        $value = strtoupper(trim((string)($value ?? '')));
        return $value === '' ? null : $value;
    }

    private static function nullableLower(mixed $value): ?string
    {
        $value = strtolower(trim((string)($value ?? '')));
        return $value === '' ? null : $value;
    }

    private static function nullableDigits(mixed $value): ?string
    {
        $value = preg_replace('/\D+/', '', (string)($value ?? '')) ?: '';
        return $value === '' ? null : $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }
}
