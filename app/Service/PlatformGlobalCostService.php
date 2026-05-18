<?php

namespace App\Service;

use App\Session\User as SessionUser;
use WilliamCosta\DatabaseManager\Database;

class PlatformGlobalCostService
{
    public const TABLE = 'platform_global_costs';
    public const LOG_TABLE = 'platform_global_cost_logs';
    public const VOICE_ROUTE_OPEN = 'TARIFA_ABERTA';
    public const VOICE_ROUTE_SMART = 'TARIFA_INTELIGENTE';

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
            '/global-costs/data',
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

    public static function formBlueprint(): array
    {
        return [
            'modules' => [
                ['value' => 'SMS', 'label' => 'SMS'],
                ['value' => 'VOICE', 'label' => 'Voz'],
            ],
            'voice_routes' => [
                [
                    'value' => self::VOICE_ROUTE_OPEN,
                    'label' => 'CLI Aberta',
                    'aliases' => ['cli_aberta', 'open', 'aberta'],
                ],
                [
                    'value' => self::VOICE_ROUTE_SMART,
                    'label' => 'Bina Inteligente',
                    'aliases' => ['bina_inteligente', 'smart', 'inteligente', 'bina'],
                ],
            ],
        ];
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
        $routeKey = self::normalizedRouteKey($module, $criteria['route_key'] ?? null);
        $countryCode = self::nullableUpper($criteria['country_code'] ?? null);
        $prefixCode = self::nullableDigits($criteria['prefix_code'] ?? null);
        $provider = self::nullableLower($criteria['provider'] ?? null);
        $carrier = self::nullableLower($criteria['carrier'] ?? null);
        $providerToken = self::comparableToken($provider);
        $carrierToken = self::comparableToken($carrier);

        $rows = (new Database())->execute(
            "SELECT *
             FROM " . self::TABLE . "
             WHERE module = :module
               AND active = 1
               AND effective_date <= NOW()
               AND (route_key IS NULL OR route_key = '' OR route_key = :route_key)
               AND (country_code IS NULL OR country_code = '' OR country_code = :country_code)
               AND (prefix_code IS NULL OR prefix_code = '' OR prefix_code = :prefix_code)
             ORDER BY effective_date DESC, id DESC",
            [
                ':module' => $module,
                ':route_key' => $routeKey ?? '',
                ':country_code' => $countryCode ?? '',
                ':prefix_code' => $prefixCode ?? '',
            ]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $row = self::pickBestMatchingRow(
            $rows,
            [
                'route_key' => $routeKey,
                'country_code' => $countryCode,
                'prefix_code' => $prefixCode,
                'provider' => $provider,
                'carrier' => $carrier,
                'provider_token' => $providerToken,
                'carrier_token' => $carrierToken,
            ]
        );

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

    private static function pickBestMatchingRow(array $rows, array $criteria): ?array
    {
        $bestRow = null;
        $bestScore = -1;

        foreach ($rows as $row) {
            $score = self::matchScore($row, $criteria);
            if ($score < 0) {
                continue;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow = $row;
            }
        }

        return $bestRow;
    }

    private static function matchScore(array $row, array $criteria): int
    {
        $score = 0;

        $routeKey = self::nullableUpper($row['route_key'] ?? null);
        $countryCode = self::nullableUpper($row['country_code'] ?? null);
        $prefixCode = self::nullableDigits($row['prefix_code'] ?? null);
        $provider = self::nullableLower($row['provider'] ?? null);
        $carrier = self::nullableLower($row['carrier'] ?? null);
        $providerToken = self::comparableToken($provider);
        $carrierToken = self::comparableToken($carrier);

        foreach ([
            ['row' => $routeKey, 'target' => $criteria['route_key'] ?? null, 'weight' => 100],
            ['row' => $countryCode, 'target' => $criteria['country_code'] ?? null, 'weight' => 30],
            ['row' => $prefixCode, 'target' => $criteria['prefix_code'] ?? null, 'weight' => 30],
        ] as $rule) {
            if ($rule['row'] === null || $rule['row'] === '') {
                continue;
            }
            if (($rule['target'] ?? null) === null || $rule['target'] === '') {
                continue;
            }
            if ((string)$rule['row'] !== (string)$rule['target']) {
                return -1;
            }
            $score += $rule['weight'];
        }

        $score += self::tokenMatchScore($provider, $providerToken, $criteria['provider'] ?? null, $criteria['provider_token'] ?? null, 20);
        $score += self::tokenMatchScore($carrier, $carrierToken, $criteria['carrier'] ?? null, $criteria['carrier_token'] ?? null, 20);

        if (($row['effective_date'] ?? null) !== null) {
            $score += 1;
        }

        return $score;
    }

    private static function tokenMatchScore(?string $rowValue, ?string $rowToken, ?string $targetValue, ?string $targetToken, int $weight): int
    {
        if ($rowValue === null || $rowValue === '') {
            return 0;
        }

        if ($targetValue === null || $targetValue === '') {
            return 0;
        }

        if ($rowValue === $targetValue) {
            return $weight;
        }

        if ($rowToken !== null && $targetToken !== null && $rowToken === $targetToken) {
            return max(1, $weight - 2);
        }

        return -1000;
    }

    private static function comparableToken(?string $value): ?string
    {
        $value = strtolower(trim((string)($value ?? '')));
        if ($value === '') {
            return null;
        }

        $normalized = preg_replace('/[^a-z0-9]+/', '', $value) ?: '';
        return $normalized === '' ? null : $normalized;
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

        $voiceRows = array_values(array_filter($rows, static fn (array $row): bool => strtoupper((string)($row['module'] ?? '')) === 'VOICE'));
        $smsRows = array_values(array_filter($rows, static fn (array $row): bool => strtoupper((string)($row['module'] ?? '')) === 'SMS'));

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'total_rows' => count($rows),
            'active_rows' => count(array_filter($rows, static fn (array $row): bool => !empty($row['active']))),
            'by_module' => $byModule,
            'voice_routes' => [
                'cli_aberta' => count(array_filter($voiceRows, static fn (array $row): bool => (string)($row['route_key'] ?? '') === self::VOICE_ROUTE_OPEN)),
                'bina_inteligente' => count(array_filter($voiceRows, static fn (array $row): bool => (string)($row['route_key'] ?? '') === self::VOICE_ROUTE_SMART)),
            ],
            'sms_dimensions' => [
                'with_provider' => count(array_filter($smsRows, static fn (array $row): bool => trim((string)($row['provider'] ?? '')) !== '')),
                'with_carrier' => count(array_filter($smsRows, static fn (array $row): bool => trim((string)($row['carrier'] ?? '')) !== '')),
                'defaults' => count(array_filter($smsRows, static function (array $row): bool {
                    return trim((string)($row['provider'] ?? '')) === ''
                        && trim((string)($row['carrier'] ?? '')) === ''
                        && trim((string)($row['route_key'] ?? '')) === '';
                })),
            ],
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

        $routeKey = self::normalizedRouteKey($module, $payload['route_key'] ?? null);
        if ($module === 'VOICE' && $routeKey === null) {
            throw new \RuntimeException('Selecione o tipo de tarifacao da voz: CLI Aberta ou Bina Inteligente.');
        }

        return [
            'module' => $module,
            'route_key' => $routeKey,
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

    private static function normalizedRouteKey(string $module, mixed $value): ?string
    {
        $normalized = self::nullableUpper($value);
        if ($normalized === null) {
            return null;
        }

        if ($module !== 'VOICE') {
            return $normalized;
        }

        return match (strtolower($normalized)) {
            'tarifa_aberta', 'cli_aberta', 'cli_open', 'open', 'aberta' => self::VOICE_ROUTE_OPEN,
            'tarifa_inteligente', 'bina_inteligente', 'smart', 'inteligente', 'bina' => self::VOICE_ROUTE_SMART,
            default => throw new \RuntimeException('Tipo de tarifa de voz inválido. Use CLI Aberta ou Bina Inteligente.'),
        };
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
