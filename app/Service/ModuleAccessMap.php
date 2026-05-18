<?php

namespace App\Service;

class ModuleAccessMap
{
    private const ROUTE_PERMISSION_DENIED_MESSAGE = 'Você não possui permissão para acessar esta rota.';
    private const PLAN_MODULE_DENIED_FALLBACK_MESSAGE = 'Este módulo não faz parte do seu plano contratado.';

    private const DEFINITIONS = [
        'users' => [
            'label' => 'Usuarios',
            'features' => ['users'],
            'permissions' => ['/users'],
            'menus' => ['administrativo.gestao.usuarios'],
            'routes' => [
                ['type' => 'prefix', 'match' => '/users/profile', 'features' => []],
                ['type' => 'exact', 'match' => '/users', 'features' => ['users']],
                ['type' => 'exact', 'match' => '/users/new', 'features' => ['users', 'create_users']],
                ['type' => 'prefix', 'match' => '/users/search', 'features' => ['users']],
                ['type' => 'prefix', 'match' => '/users/{id}/edit', 'features' => ['users', 'create_users']],
                ['type' => 'prefix', 'match' => '/users/edit', 'features' => ['users', 'create_users']],
                ['type' => 'prefix', 'match' => '/users/{id}/delete', 'features' => ['users', 'create_users']],
                ['type' => 'prefix', 'match' => '/users/up-status', 'features' => ['users', 'create_users']],
                ['type' => 'prefix', 'match' => '/users/refills', 'features' => ['users', 'create_users']],
            ],
        ],
        'rates' => [
            'label' => 'Tarifas',
            'features' => ['rates'],
            'permissions' => ['/rates'],
            'menus' => ['tarifas'],
            'routes' => [
                ['type' => 'exact', 'match' => '/rates', 'features' => ['rates']],
                ['type' => 'prefix', 'match' => '/rates/', 'features' => ['rates']],
            ],
        ],
        'permissions' => [
            'label' => 'Permissoes',
            'features' => ['permissions'],
            'permissions' => ['/permissions'],
            'menus' => ['administrativo.configuracao.permissoes'],
            'routes' => [
                ['type' => 'exact', 'match' => '/permissions', 'features' => ['permissions']],
                ['type' => 'prefix', 'match' => '/permissions/', 'features' => ['permissions']],
            ],
        ],
        'reports' => [
            'label' => 'Relatorios',
            'features' => ['reports'],
            'permissions' => ['/reports', '/callcenter/reports'],
            'menus' => ['relatorios'],
            'routes' => [
                ['type' => 'exact', 'match' => '/callcenter/reports', 'features' => ['reports']],
                ['type' => 'exact', 'match' => '/reports', 'features' => ['reports']],
                ['type' => 'prefix', 'match' => '/reports/', 'features' => ['reports']],
            ],
        ],
        'administrative' => [
            'label' => 'Administrativo',
            'features' => ['administrative'],
            'permissions' => ['/refills', '/notifications', '/plans', '/support', '/system-updates', '/admin/services-monitor', '/global-costs', '/admin/platform-consumption'],
            'menus' => ['administrativo', 'recargas'],
            'routes' => [
                ['type' => 'exact', 'match' => '/refills', 'features' => ['administrative']],
                ['type' => 'prefix', 'match' => '/refills/', 'features' => ['administrative']],
                ['type' => 'exact', 'match' => '/notifications', 'features' => ['administrative']],
                ['type' => 'prefix', 'match' => '/notifications/', 'features' => ['administrative']],
                ['type' => 'exact', 'match' => '/plans', 'features' => ['administrative']],
                ['type' => 'prefix', 'match' => '/plans/', 'features' => ['administrative']],
                ['type' => 'exact', 'match' => '/support', 'features' => ['administrative']],
                ['type' => 'prefix', 'match' => '/support/', 'features' => ['administrative']],
                ['type' => 'exact', 'match' => '/system-updates', 'features' => ['administrative']],
                ['type' => 'prefix', 'match' => '/system-updates/', 'features' => ['administrative']],
                ['type' => 'exact', 'match' => '/admin/services-monitor', 'features' => ['administrative']],
                ['type' => 'prefix', 'match' => '/admin/services-monitor/', 'features' => ['administrative']],
                ['type' => 'exact', 'match' => '/global-costs', 'features' => ['administrative']],
                ['type' => 'prefix', 'match' => '/global-costs/', 'features' => ['administrative']],
                ['type' => 'exact', 'match' => '/admin/platform-consumption', 'features' => ['administrative']],
                ['type' => 'prefix', 'match' => '/admin/platform-consumption/', 'features' => ['administrative']],
                ['type' => 'exact', 'match' => '/site-tests', 'features' => ['administrative']],
                ['type' => 'prefix', 'match' => '/site-tests/', 'features' => ['administrative']],
            ],
        ],
        'dashboard' => [
            'label' => 'Dashboard',
            'features' => [],
            'permissions' => ['/dashboard'],
            'menus' => ['dashboard'],
            'routes' => [
                ['type' => 'exact', 'match' => '/dashboard', 'features' => []],
                ['type' => 'prefix', 'match' => '/dashboard/', 'features' => []],
            ],
        ],
        'voice' => [
            'label' => 'Voz',
            'features' => ['voice'],
            'permissions' => ['/campaign/voice'],
            'menus' => ['voz.operacao', 'voz.recursos'],
            'routes' => [
                ['type' => 'prefix', 'match' => '/campaign/voice/trunks', 'features' => ['voice', 'trunks']],
                ['type' => 'prefix', 'match' => '/campaign/voice/sip-trunks', 'features' => ['voice', 'trunks']],
                ['type' => 'exact', 'match' => '/campaign/voice', 'features' => ['voice']],
                ['type' => 'prefix', 'match' => '/campaign/voice/', 'features' => ['voice']],
            ],
        ],
        'callcenter' => [
            'label' => 'Call Center',
            'features' => ['callcenter'],
            'permissions' => ['/callcenter', '/callcenter/monitoring'],
            'menus' => ['voz.configuracao.callcenter'],
            'routes' => [
                ['type' => 'exact', 'match' => '/callcenter', 'features' => ['callcenter']],
                ['type' => 'prefix', 'match' => '/callcenter/', 'features' => ['callcenter']],
            ],
        ],
        'whatsapp' => [
            'label' => 'WhatsApp',
            'features' => ['whatsapp'],
            'permissions' => ['/campaign/whatsapp'],
            'menus' => ['whatsapp'],
            'routes' => [
                ['type' => 'exact', 'match' => '/campaign/whatsapp/templates', 'features' => ['whatsapp']],
                ['type' => 'prefix', 'match' => '/campaign/whatsapp/templates/library', 'features' => ['whatsapp']],
                ['type' => 'exact', 'match' => '/campaign/whatsapp/templates/create', 'features' => ['whatsapp', 'templates']],
                ['type' => 'exact', 'match' => '/campaign/whatsapp/templates/sync', 'features' => ['whatsapp', 'templates']],
                ['type' => 'prefix', 'match' => '/campaign/whatsapp/templates/{id}/sync', 'features' => ['whatsapp', 'templates']],
                ['type' => 'prefix', 'match' => '/campaign/whatsapp/templates/{id}/delete', 'features' => ['whatsapp', 'templates']],
                ['type' => 'prefix', 'match' => '/campaign/whatsapp/campaigns', 'features' => ['whatsapp']],
                ['type' => 'exact', 'match' => '/campaign/whatsapp', 'features' => ['whatsapp']],
                ['type' => 'prefix', 'match' => '/campaign/whatsapp/', 'features' => ['whatsapp']],
                ['type' => 'prefix', 'match' => '/whatsapp/', 'features' => ['whatsapp']],
            ],
        ],
        'movies' => [
            'label' => 'Ajuda',
            'features' => [],
            'permissions' => ['/movies'],
            'menus' => [],
            'routes' => [
                ['type' => 'exact', 'match' => '/movies', 'features' => []],
                ['type' => 'prefix', 'match' => '/movies/', 'features' => []],
            ],
        ],
        'sms' => [
            'label' => 'SMS',
            'features' => ['sms'],
            'permissions' => ['/campaign'],
            'menus' => ['mensageria'],
            'routes' => [
                ['type' => 'exact', 'match' => '/campaign', 'features' => ['sms']],
                ['type' => 'prefix', 'match' => '/campaign/', 'features' => ['sms']],
            ],
        ],
    ];

    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    public static function routeRules(): array
    {
        $rules = [];
        foreach (self::DEFINITIONS as $moduleKey => $definition) {
            foreach ((array)($definition['routes'] ?? []) as $rule) {
                $rules[] = $rule + ['module_key' => $moduleKey];
            }
        }

        return $rules;
    }

    public static function featureKeysForRoute(string $routeName): array
    {
        return self::routeContext($routeName)['features'];
    }

    public static function routeContext(string $routeName): array
    {
        $normalizedRouteName = self::normalizeRouteName($routeName);

        foreach (self::routeRules() as $rule) {
            $type = (string)($rule['type'] ?? 'prefix');
            $match = self::normalizeRouteName((string)($rule['match'] ?? ''));
            $features = self::normalizeFeatureKeys($rule['features'] ?? []);
            $moduleKey = (string)($rule['module_key'] ?? '');

            if ($match === '') {
                continue;
            }

            if ($type === 'exact' && $normalizedRouteName === $match) {
                return self::buildRouteContext($moduleKey, $normalizedRouteName, $match, $type, $features);
            }

            if ($type === 'prefix' && str_starts_with($normalizedRouteName, $match)) {
                return self::buildRouteContext($moduleKey, $normalizedRouteName, $match, $type, $features);
            }
        }

        return [
            'route_name' => $normalizedRouteName,
            'module_key' => '',
            'module_label' => '',
            'features' => [],
            'permissions' => [],
            'menus' => [],
            'requires_plan' => false,
            'route_permission_message' => self::routePermissionDeniedMessage(),
            'plan_message' => self::PLAN_MODULE_DENIED_FALLBACK_MESSAGE,
            'matched_by' => '',
            'matched_rule' => '',
        ];
    }

    public static function routePermissionDeniedMessage(): string
    {
        return self::ROUTE_PERMISSION_DENIED_MESSAGE;
    }

    public static function planDeniedMessageForRoute(string $routeName): string
    {
        return self::PLAN_MODULE_DENIED_FALLBACK_MESSAGE;
    }

    public static function matrixRows(): array
    {
        $rows = [];
        foreach (self::DEFINITIONS as $moduleKey => $definition) {
            $rows[] = [
                'module_key' => $moduleKey,
                'label' => (string)($definition['label'] ?? $moduleKey),
                'features' => self::normalizeFeatureKeys($definition['features'] ?? []),
                'permissions' => array_values(array_filter(array_map('strval', (array)($definition['permissions'] ?? [])))),
                'menus' => array_values(array_filter(array_map('strval', (array)($definition['menus'] ?? [])))),
                'routes' => array_values(array_map(static function (array $rule): array {
                    return [
                        'type' => (string)($rule['type'] ?? 'prefix'),
                        'match' => (string)($rule['match'] ?? ''),
                        'features' => self::normalizeFeatureKeys($rule['features'] ?? []),
                    ];
                }, (array)($definition['routes'] ?? []))),
            ];
        }

        return $rows;
    }

    private static function normalizeFeatureKeys(mixed $features): array
    {
        if (is_string($features)) {
            $features = [$features];
        }

        if (!is_array($features)) {
            return [];
        }

        $normalized = [];
        foreach ($features as $feature) {
            $value = trim((string)$feature);
            if ($value === '') {
                continue;
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    private static function buildRouteContext(
        string $moduleKey,
        string $routeName,
        string $matchedRule,
        string $matchedBy,
        array $features
    ): array {
        $definition = self::DEFINITIONS[$moduleKey] ?? [];
        $moduleLabel = trim((string)($definition['label'] ?? $moduleKey));

        return [
            'route_name' => $routeName,
            'module_key' => $moduleKey,
            'module_label' => $moduleLabel,
            'features' => $features,
            'permissions' => array_values(array_filter(array_map('strval', (array)($definition['permissions'] ?? [])))),
            'menus' => array_values(array_filter(array_map('strval', (array)($definition['menus'] ?? [])))),
            'requires_plan' => $features !== [],
            'route_permission_message' => self::routePermissionDeniedMessage(),
            'plan_message' => self::PLAN_MODULE_DENIED_FALLBACK_MESSAGE,
            'matched_by' => $matchedBy,
            'matched_rule' => $matchedRule,
        ];
    }

    private static function normalizeRouteName(string $routeName): string
    {
        $routeName = '/' . trim($routeName, '/');
        return preg_replace('#/+#', '/', $routeName) ?: '/';
    }
}
