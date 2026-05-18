<?php

namespace App\Service;

class ModuleAccessMap
{
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
            'permissions' => ['/callcenter', '/callcenter/monitoring', '/callcenter/reports'],
            'menus' => ['voz.configuracao.callcenter', 'relatorios.callcenter'],
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
        foreach (self::routeRules() as $rule) {
            $type = (string)($rule['type'] ?? 'prefix');
            $match = (string)($rule['match'] ?? '');
            $features = $rule['features'] ?? [];

            if ($match === '') {
                continue;
            }

            if ($type === 'exact' && $routeName === $match) {
                return self::normalizeFeatureKeys($features);
            }

            if ($type === 'prefix' && str_starts_with($routeName, $match)) {
                return self::normalizeFeatureKeys($features);
            }
        }

        return [];
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
}
