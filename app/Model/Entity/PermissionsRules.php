<?php
namespace App\Model\Entity;

use App\Service\PermissionResolver;
use App\Support\RequestCache;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class PermissionsRules {
    private const COLUMN_ACCESS_SCOPE = 'access_scope';
    private const COLUMN_ASSIGNABLE_BY = 'assignable_by';
    private const COLUMN_ROLE_TEMPLATE_ID = 'template_id';
    private const COLUMN_INHERITS_TEMPLATE = 'inherits_template';
    private const SYSTEM_MANAGED_ROLE_NAMES = [
        'admin',
        'rh',
        'financial',
        'reception',
        'manager',
        'supervisor',
        'agent',
        'monitor',
        'support_l1',
        'support_l2',
        'ticket_support',
        'support_ticket_manager',
        'reseller',
    ];
    private const SUPERADMIN_ONLY_ROUTE_PREFIXES = [
        '/plans',
        '/global-costs',
        '/admin/platform-consumption',
        '/admin/services-monitor',
        'ticket.',
        '/site-tests',
        '/permissions/global-routes',
    ];
    private const ADMINISTRATIVE_MODULE_GROUPS = [
        '/campaign/whatsapp/templates' => 'Templates: WhatsApp',
        '/campaign/whatsapp/campaigns' => 'WhatsApp: Campanhas',
        '/campaign/whatsapp/support' => 'WhatsApp: Atendimento',
        '/campaign/whatsapp/conversations' => 'WhatsApp: Atendimento',
        '/campaign/whatsapp/numbers/health' => 'WhatsApp: Proteção',
        '/campaign/whatsapp/numbers' => 'WhatsApp: Números',
        '/campaign/whatsapp/number-requests' => 'WhatsApp: Números',
        '/campaign/whatsapp/accounts' => 'WhatsApp: Contas',
        '/campaign/whatsapp/pricing' => 'WhatsApp: Tarifação',
        '/campaign/whatsapp/messages' => 'WhatsApp: Mensagens',
        '/campaign/whatsapp' => 'WhatsApp: Geral',
        '/webhooks/meta/whatsapp' => 'WhatsApp: Webhooks',
        '/whatsapp' => 'WhatsApp: Compatibilidade',

        '/callcenter/reports' => 'Relatórios: Call Center',
        '/callcenter/monitoring' => 'Call Center: Monitoramento',
        '/callcenter/agent-panel' => 'Call Center: Painel do Agente',
        '/callcenter/agents' => 'Call Center: Agentes',
        '/callcenter/queues' => 'Call Center: Filas',
        '/callcenter/breaks' => 'Call Center: Pausas',
        '/callcenter' => 'Call Center: Operação',

        '/campaign/voice/sip-trunks' => 'Voz: Troncos',
        '/campaign/voice/trunks' => 'Voz: Troncos',
        '/campaign/voice/sip-devices' => 'Voz: Ramais',
        '/campaign/voice/sip' => 'Voz: Ramais',
        '/campaign/voice/prices' => 'Voz: Tarifas',
        '/campaign/voice/audios' => 'Voz: Áudios',
        '/campaign/voice/audio' => 'Voz: Áudios',
        '/campaign/voice/live-calls' => 'Voz: Monitoramento',
        '/campaign/voice/listening' => 'Voz: Monitoramento',
        '/campaign/voice/stop-listening' => 'Voz: Monitoramento',
        '/campaign/voice/calls-view' => 'Voz: Monitoramento',
        '/campaign/voice/make-call' => 'Voz: Operação',
        '/campaign/voice/send-voice' => 'Voz: Operação',
        '/campaign/voice' => 'Voz: Geral',

        '/campaign/single-shot' => 'SMS: Envio Único',
        '/campaign' => 'SMS: Campanhas',

        '/reports/notifications' => 'Relatórios: Notificações',
        '/notifications' => 'Administrativo: Notificações',
        '/support' => 'Administrativo: Suporte',
        'ticket.' => 'Administrativo: Suporte',
        '/plans' => 'Administrativo: Planos',
        '/refills' => 'Administrativo: Recargas',
        '/site-tests' => 'Administrativo: Testes',
        '/system-updates' => 'Administrativo: Atualizações',
        '/help' => 'Administrativo: Ajuda',

        '/users' => 'Administrativo: Usuários',
        '/permissions' => 'Administrativo: Permissões',
        '/rates' => 'Tarifas: Gestão',
        '/reports' => 'Relatórios: Geral',
        '/dashboard' => 'Dashboard: Geral',
    ];
    private const SYSTEM_ROLE_ROUTE_SEEDS = [
        'admin' => [
            'mode' => 'governance',
        ],
        'agent' => [
            'mode' => 'routes',
            'routes' => [
                '/dashboard',
                '/dashboard/cards',
                '/dashboard/charts',
                '/users/profile',
                '/users/profile/reset-pass',
                '/users/profile/upload-images',
                '/campaign/single-shot',
                '/campaign/single-shot-send',
                '/callcenter/agent-panel',
                '/callcenter/agent-login',
                '/callcenter/agent-set-status',
                '/callcenter/active-breaks',
                '/campaign/whatsapp',
                '/campaign/whatsapp/accounts',
                '/campaign/whatsapp/conversations',
                '/campaign/whatsapp/conversations/{id}/messages',
                '/campaign/whatsapp/conversations/{id}/read',
                '/campaign/whatsapp/conversations/{id}/unread',
                '/campaign/whatsapp/conversations/{id}/queue',
                '/campaign/whatsapp/conversations/{id}/claim',
                '/campaign/whatsapp/messages/send',
                '/campaign/whatsapp/templates',
                '/campaign/whatsapp/support/queues',
                '/campaign/whatsapp/support/assignable-users',
                '/campaign/whatsapp/support/events',
                '/campaign/whatsapp/support/sessions/{id}/finish',
                '/campaign/whatsapp/calls/permissions',
                '/campaign/whatsapp/calls/permissions/request',
                '/campaign/whatsapp/calls/connect',
                '/campaign/whatsapp/calls/{id}/pre-accept',
                '/campaign/whatsapp/calls/{id}/accept',
                '/campaign/whatsapp/calls/{id}/reject',
                '/campaign/whatsapp/calls/{id}/terminate',
            ],
        ],
        'support_l1' => [
            'mode' => 'routes',
            'routes' => [
                '/dashboard',
                '/dashboard/cards',
                '/dashboard/charts',
                '/help/movies',
                '/users/profile',
                '/users/profile/reset-pass',
                '/users/profile/upload-images',
                '/support',
                '/support/diagnostics',
                '/support/tickets',
                '/support/tickets/create',
                '/support/tickets/{id}/messages',
                '/support/tickets/{id}/messages/create',
                '/support/tickets/{id}/status',
            ],
        ],
        'support_l2' => [
            'mode' => 'routes',
            'routes' => [
                '/dashboard',
                '/dashboard/cards',
                '/dashboard/charts',
                '/help/movies',
                '/users/profile',
                '/users/profile/reset-pass',
                '/users/profile/upload-images',
                '/support',
                '/support/diagnostics',
                '/support/tickets',
                '/support/tickets/create',
                '/support/tickets/{id}/messages',
                '/support/tickets/{id}/messages/create',
                '/support/tickets/{id}/status',
            ],
        ],
        'ticket_support' => [
            'mode' => 'routes',
            'routes' => [
                '/dashboard',
                '/dashboard/cards',
                '/dashboard/charts',
                '/help/movies',
                '/users/profile',
                '/users/profile/reset-pass',
                '/users/profile/upload-images',
                '/support',
                '/support/tickets',
                '/support/tickets/create',
                '/support/tickets/{id}/messages',
                '/support/tickets/{id}/messages/create',
            ],
        ],
        'support_ticket_manager' => [
            'mode' => 'routes',
            'routes' => [
                '/dashboard',
                '/dashboard/cards',
                '/dashboard/charts',
                '/help/movies',
                '/users/profile',
                '/users/profile/reset-pass',
                '/users/profile/upload-images',
                '/support',
                '/support/diagnostics',
                '/support/tickets',
                '/support/tickets/create',
                '/support/tickets/{id}/messages',
                '/support/tickets/{id}/messages/create',
                '/support/tickets/{id}/status',
            ],
        ],
    ];

    /**
     * Busca os papéis (roles) do Tenancy ou Globais
     */
    public static function getRoles(string $tenancyId, ?int $userId = null): array
    {
        self::ensureRoleTemplateSchema();

        $requestKey = 'permissions.rules.roles.' . $tenancyId . '.' . ($userId ?? 0);

        return RequestCache::remember($requestKey, function () use ($tenancyId, $userId): array {
        // 🛡️ Filtra pela empresa E garante que se for um papel customizado, seja do criador correto
        $where = 'r.tenancy_id = :tenancy_id';
        $params = [
            ':tenancy_id' => $tenancyId,
            ':count_tenancy_id' => $tenancyId,
        ];

        if ($userId !== null) {
            // Se passar o ID, garante que ele veja os cargos globais (NULL) OU os dele
            $where = "(r.tenancy_id = :tenancy_id AND (r.user_id = :user_id OR r.user_id IS NULL))";
            $params[':user_id'] = $userId;
        }

        $sql = "
            SELECT
                r.id,
                r.name,
                r.description,
                r.status,
                r.tenancy_id,
                r.user_id,
                r." . self::COLUMN_ROLE_TEMPLATE_ID . " AS template_id,
                r." . self::COLUMN_INHERITS_TEMPLATE . " AS inherits_template,
                tpl.name AS template_name,
                t.name AS tenancy_name,
                t.account_code,
                CASE WHEN r.user_id IS NULL THEN 'system' ELSE 'custom' END AS type,
                tr.total_routes_system,
                COALESCE(uc.total_users, 0) AS total_users
            FROM sys_roles r
            INNER JOIN tenancies t
                ON t.id = r.tenancy_id
            LEFT JOIN sys_role_templates tpl
                ON tpl.id = r." . self::COLUMN_ROLE_TEMPLATE_ID . "
            CROSS JOIN (
                SELECT COUNT(*) AS total_routes_system
                FROM sys_routes
                WHERE " . self::COLUMN_ACCESS_SCOPE . " = 'tenant'
                  AND " . self::COLUMN_ASSIGNABLE_BY . " = 'admin'
            ) tr
            LEFT JOIN (
                SELECT role_id, tenancy_id, COUNT(*) AS total_users
                FROM user_roles
                WHERE tenancy_id = :count_tenancy_id
                GROUP BY role_id, tenancy_id
            ) uc
                ON uc.role_id = r.id
               AND uc.tenancy_id = r.tenancy_id
            WHERE {$where}
            ORDER BY r.id DESC
        ";

        return (new Database())->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        });
    }

    public static function getRolesGlobal(): array
    {
        self::ensureRoleTemplateSchema();

        return RequestCache::remember('permissions.rules.roles.global', static function (): array {
            $sql = "
                SELECT
                    r.id,
                    r.name,
                    r.description,
                    r.status,
                    r.tenancy_id,
                    r.user_id,
                    r." . self::COLUMN_ROLE_TEMPLATE_ID . " AS template_id,
                    r." . self::COLUMN_INHERITS_TEMPLATE . " AS inherits_template,
                    tpl.name AS template_name,
                    t.name AS tenancy_name,
                    t.account_code,
                    CASE WHEN r.user_id IS NULL THEN 'system' ELSE 'custom' END AS type,
                    CASE
                        WHEN LOWER(TRIM(r.name)) = 'super_admin'
                          OR EXISTS (
                                SELECT 1
                                FROM users su
                                WHERE su.tenancy_id = r.tenancy_id
                                  AND su.role_id = r.id
                                  AND LOWER(TRIM(COALESCE(su.user_function, ''))) = 'super_admin'
                                LIMIT 1
                            )
                        THEN tr.total_routes_system
                        ELSE tr.total_routes_admin
                    END AS total_routes_system,
                    COALESCE(uc.total_users, 0) AS total_users
                FROM sys_roles r
                INNER JOIN tenancies t
                    ON t.id = r.tenancy_id
                LEFT JOIN sys_role_templates tpl
                    ON tpl.id = r." . self::COLUMN_ROLE_TEMPLATE_ID . "
                CROSS JOIN (
                    SELECT
                        COUNT(*) AS total_routes_system,
                        SUM(
                            CASE
                                WHEN " . self::COLUMN_ACCESS_SCOPE . " = 'tenant'
                                 AND " . self::COLUMN_ASSIGNABLE_BY . " = 'admin'
                                THEN 1
                                ELSE 0
                            END
                        ) AS total_routes_admin
                    FROM sys_routes
                ) tr
                LEFT JOIN (
                    SELECT role_id, tenancy_id, COUNT(*) AS total_users
                    FROM user_roles
                    GROUP BY role_id, tenancy_id
                ) uc
                    ON uc.role_id = r.id
                   AND uc.tenancy_id = r.tenancy_id
                ORDER BY r.id DESC
            ";

            return (new Database())->execute($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        });
    }

    public static function getSystemRoleTemplatesForListing(): array
    {
        self::ensureRoleTemplateSchema();

        return RequestCache::remember('permissions.rules.system_templates', static function (): array {
        $db = new Database();
        $totalRoutesCount = (int)$db->execute("SELECT COUNT(*) FROM sys_routes")->fetchColumn();

        $templates = $db->execute(
            "SELECT id, name, label, description, status
             FROM sys_role_templates
             ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $templateUsage = $db->execute(
            "SELECT
                r." . self::COLUMN_ROLE_TEMPLATE_ID . " AS template_id,
                COUNT(*) AS total_users
             FROM user_roles ur
             INNER JOIN sys_roles r
                ON r.id = ur.role_id
               AND r.tenancy_id = ur.tenancy_id
             WHERE r." . self::COLUMN_INHERITS_TEMPLATE . " = 'y'
               AND r." . self::COLUMN_ROLE_TEMPLATE_ID . " IS NOT NULL
             GROUP BY r." . self::COLUMN_ROLE_TEMPLATE_ID
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $usageByTemplateId = [];
        foreach ($templateUsage as $usage) {
            $usageByTemplateId[(int)($usage['template_id'] ?? 0)] = (int)($usage['total_users'] ?? 0);
        }

        $items = [];
        foreach ($templates as $template) {
            $templateId = (int)($template['id'] ?? 0);
            if ($templateId <= 0) {
                continue;
            }

            $totalUsers = $usageByTemplateId[$templateId] ?? 0;

            $items[] = [
                'id' => -$templateId,
                'name' => (string)($template['name'] ?? ''),
                'display_name' => self::getTemplateDisplayName((string)($template['name'] ?? '')),
                'description' => (string)($template['description'] ?? ''),
                'label' => (string)($template['label'] ?? ''),
                'status' => (string)($template['status'] ?? 'y'),
                'type' => 'system',
                'scope_kind' => 'template',
                'template_id' => $templateId,
                'tenancy_id' => '',
                'tenancy_name' => 'Sistema Global',
                'account_code' => 'PADRAO',
                'total_users' => $totalUsers,
                'total_routes_system' => $totalRoutesCount,
            ];
        }

        return $items;
        });
    }


    /**
     * Busca os dados de um papel pelo nome (Ex: 'reseller')
     */
    public static function getRoleByName($name) {
        // O execute() retorna o statement do PDO. Nele sim podemos dar o fetch.
        $query = "SELECT * FROM sys_roles WHERE name = :name LIMIT 1";
        $statement = (new Database())->execute($query, [':name' => $name]);

        return $statement->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Cadastra rotas no catálogo global (sys_routes)
     */
    public static function registerGlobalRoute(string $name, string $label, array $routes): void
    {
        self::ensureRoleTemplateSchema();

        $db = new Database('sys_routes');
        foreach ($routes as $path) {
            $normalizedPath = self::normalizeGovernancePrefix(trim((string)$path));
            if ($normalizedPath === '') {
                continue;
            }

            $governance = self::resolveGovernanceForRoutePath($normalizedPath);
            $moduleName = self::resolveModuleNameForRoute($normalizedPath, (string)$label);

            $existingRouteId = (int)(new Database())->execute(
                "SELECT id
                 FROM sys_routes
                 WHERE route_path = :route_path
                 LIMIT 1",
                [':route_path' => $normalizedPath]
            )->fetchColumn();

            if ($existingRouteId > 0) {
                $db->update(
                    'id = ' . $existingRouteId,
                    [
                        'module_name' => $moduleName,
                        'route_path' => $normalizedPath,
                        self::COLUMN_ACCESS_SCOPE => $governance['access_scope'],
                        self::COLUMN_ASSIGNABLE_BY => $governance['assignable_by'],
                    ]
                );
                self::syncRouteIntoDefaultAdminTemplate($existingRouteId, $governance);
                continue;
            }

            $routeId = (int)$db->insert([
                'module_name' => $moduleName,
                'route_path'  => $normalizedPath,
                self::COLUMN_ACCESS_SCOPE => $governance['access_scope'],
                self::COLUMN_ASSIGNABLE_BY => $governance['assignable_by'],
            ]);
            self::syncRouteIntoDefaultAdminTemplate($routeId, $governance);
        }
        self::invalidatePermissionCaches();
    }

    /**
     * Busca permissões cruzadas para o Modal de Switches
     */
    public static function getCombinedPermissions(int $roleId, string $tenancyId): array
    {
        $db = new Database();
        // 🛡️ Adicionamos a trava de segurança direto no JOIN para garantir que
        // as permissões pertençam à Tenancy correta
        $query = "SELECT 
            sr.id as route_id,
            sr.module_name as label,
            sr.route_path as name,
            IF(srp.id IS NULL, 0, 1) as enabled,
            sr." . self::COLUMN_ACCESS_SCOPE . " as access_scope,
            sr." . self::COLUMN_ASSIGNABLE_BY . " as assignable_by
          FROM sys_routes sr
          LEFT JOIN sys_role_permissions srp 
            ON srp.route_id = sr.id 
            AND srp.role_id = :role_id 
            AND srp.tenancy_id = :tenancy_id
          ORDER BY sr.module_name ASC, sr.route_path ASC";

        $rows = $db->execute($query, [
            ':role_id'    => $roleId,
            ':tenancy_id' => $tenancyId
        ])->fetchAll(PDO::FETCH_ASSOC);

        $roleName = self::resolveRoleNameById($roleId, $tenancyId);

        return self::filterPermissionMatrixByAdminBaseline(
            is_array($rows) ? $rows : [],
            $roleName,
            self::roleShouldBypassAdminBaseline($roleId, $tenancyId, $roleName)
        );
    }

    public static function getCombinedTemplatePermissions(int $templateId): array
    {
        self::ensureRoleTemplateSchema();

        $db = new Database();

        $query = "SELECT
            sr.id as route_id,
            sr.module_name as label,
            sr.route_path as name,
            IF(strp.id IS NULL, 0, 1) as enabled,
            sr." . self::COLUMN_ACCESS_SCOPE . " as access_scope,
            sr." . self::COLUMN_ASSIGNABLE_BY . " as assignable_by
          FROM sys_routes sr
          LEFT JOIN sys_role_template_permissions strp
            ON strp.route_id = sr.id
           AND strp.template_id = :template_id
          ORDER BY sr.module_name ASC, sr.route_path ASC";

        $rows = $db->execute($query, [
            ':template_id' => $templateId,
        ])->fetchAll(PDO::FETCH_ASSOC);

        return self::filterPermissionMatrixByAdminBaseline(
            is_array($rows) ? $rows : [],
            self::resolveTemplateNameById($templateId)
        );
    }

    public static function getRoleByNameAndTenancy($name, $tenancyId) {
        $query = "SELECT * FROM sys_roles WHERE name = :name AND tenancy_id = :tenancy_id LIMIT 1";
        $statement = (new Database())->execute($query, [
            ':name' => $name,
            ':tenancy_id' => $tenancyId,
        ]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $roleId = (int)($row['id'] ?? 0);
        $roleName = strtolower(trim((string)($row['name'] ?? $name)));
        $inheritsTemplate = strtolower(trim((string)($row[self::COLUMN_INHERITS_TEMPLATE] ?? 'n'))) === 'y';

        if (
            $roleId > 0
            && $inheritsTemplate
            && self::shouldUseTenantTemplateSnapshot($roleName)
        ) {
            self::applyRoleTemplateSnapshotByName($roleId, (string)$tenancyId, $roleName, false);
            $row[self::COLUMN_INHERITS_TEMPLATE] = 'n';
        }

        $role = new self();
        foreach ($row as $property => $value) {
            $role->{$property} = $value;
        }

        return $role;
    }

    public static function getRoleById(int $roleId, string $tenancyId): ?array
    {
        $query = "SELECT * FROM sys_roles WHERE id = :id AND tenancy_id = :tenancy_id LIMIT 1";
        $statement = (new Database())->execute($query, [
            ':id' => $roleId,
            ':tenancy_id' => $tenancyId,
        ]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function getRoleByIdAny(int $roleId): ?array
    {
        $query = "SELECT * FROM sys_roles WHERE id = :id LIMIT 1";
        $statement = (new Database())->execute($query, [
            ':id' => $roleId,
        ]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function getRouteById(int $routeId): ?array
    {
        $query = "SELECT * FROM sys_routes WHERE id = :id LIMIT 1";
        $statement = (new Database())->execute($query, [':id' => $routeId]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function getRoleTemplateById(int $templateId): ?array
    {
        self::ensureRoleTemplateSchema();

        $query = "SELECT * FROM sys_role_templates WHERE id = :id LIMIT 1";
        $statement = (new Database())->execute($query, [
            ':id' => $templateId,
        ]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function getRoleTemplateByName(string $templateName): ?array
    {
        self::ensureRoleTemplateSchema();

        $query = "SELECT * FROM sys_role_templates WHERE name = :name LIMIT 1";
        $statement = (new Database())->execute($query, [
            ':name' => strtolower(trim($templateName)),
        ]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }


    /**
     * Salva ou Atualiza o papel
     */
    public static function registerRole($name, $label, $status, $tenancyId, $userId = null, $id = null): int
    {
        self::ensureRoleTemplateSchema();

        $db = new Database('sys_roles');

        $values = [
            'tenancy_id'  => $tenancyId,
            'name'        => $name,
            'label'       => $label,
            'description' => $label,
            'status'      => $status
        ];

        if (!empty($userId)) {
            $values['user_id'] = (int)$userId;
        }

        if ($id && is_numeric($id)) {
            unset($values['user_id']); // mantém o dono original no update
            $db->update('id = ' . (int)$id . ' AND tenancy_id = "' . $tenancyId . '"', $values);
            self::invalidatePermissionCaches((string)$tenancyId);
            return (int)$id;
        }

        $values['created_at'] = date('Y-m-d H:i:s');
        $roleId = (int)$db->insert($values);

        if ($roleId > 0 && self::isSystemManagedRoleName((string)$name)) {
            self::applyRoleTemplateSnapshotByName($roleId, (string)$tenancyId, (string)$name, false);
        }

        self::invalidatePermissionCaches((string)$tenancyId);

        return $roleId;
    }

    /**
     * Liga ou Desliga a permissão no banco
     */
    public static function toggleRolePermission(string $tenancyId, int $roleId, int $routeId, int $active): void
    {
        self::detachRoleFromTemplate($roleId, $tenancyId);

        $db = new Database('sys_role_permissions');
        if ($active === 1) {
            $db->execute("INSERT IGNORE INTO sys_role_permissions (tenancy_id, role_id, route_id) VALUES (:tid, :rid, :roid)", [
                ':tid'  => $tenancyId,
                ':rid'  => $roleId,
                ':roid' => $routeId
            ]);
        } else {
            $db->execute("DELETE FROM sys_role_permissions WHERE tenancy_id = :tid AND role_id = :rid AND route_id = :roid", [
                ':tid'  => $tenancyId,
                ':rid'  => $roleId,
                ':roid' => $routeId
            ]);
        }

        self::sanitizeRolePermissions($roleId, $tenancyId);
        self::invalidatePermissionCaches($tenancyId);
    }

    public static function clearRolePermissions(string $tenancyId, int $roleId): void
    {
        if ($roleId <= 0 || trim($tenancyId) === '') {
            return;
        }

        self::detachRoleFromTemplate($roleId, $tenancyId);

        (new Database())->execute(
            "DELETE FROM sys_role_permissions
             WHERE tenancy_id = :tenancy_id
               AND role_id = :role_id",
            [
                ':tenancy_id' => $tenancyId,
                ':role_id' => $roleId,
            ]
        );

        self::invalidatePermissionCaches($tenancyId);
    }

    public static function bulkToggleRolePermissions(string $tenancyId, int $roleId, array $routeIds, int $active): void
    {
        if ($roleId <= 0 || trim($tenancyId) === '') {
            return;
        }

        $routeIds = array_values(array_unique(array_filter(array_map('intval', $routeIds), static fn (int $id): bool => $id > 0)));
        if ($routeIds === []) {
            return;
        }

        self::detachRoleFromTemplate($roleId, $tenancyId);

        $idsSql = implode(',', $routeIds);
        $db = new Database();

        if ($active === 1) {
            $db->execute(
                "INSERT IGNORE INTO sys_role_permissions (tenancy_id, role_id, route_id)
                 SELECT :tenancy_id, :role_id, sr.id
                 FROM sys_routes sr
                 WHERE sr.id IN (" . $idsSql . ")",
                [
                    ':tenancy_id' => $tenancyId,
                    ':role_id' => $roleId,
                ]
            );
        } else {
            $db->execute(
                "DELETE FROM sys_role_permissions
                 WHERE tenancy_id = :tenancy_id
                   AND role_id = :role_id
                   AND route_id IN (" . $idsSql . ")",
                [
                    ':tenancy_id' => $tenancyId,
                    ':role_id' => $roleId,
                ]
            );
        }

        self::sanitizeRolePermissions($roleId, $tenancyId);
        self::invalidatePermissionCaches($tenancyId);
    }

    private static function detachRoleFromTemplate(int $roleId, string $tenancyId): void
    {
        if ($roleId <= 0 || trim($tenancyId) === '') {
            return;
        }

        (new Database())->execute(
            "UPDATE sys_roles
             SET " . self::COLUMN_INHERITS_TEMPLATE . " = 'n'
             WHERE id = :id
               AND tenancy_id = :tenancy_id
               AND " . self::COLUMN_INHERITS_TEMPLATE . " = 'y'",
            [
                ':id' => $roleId,
                ':tenancy_id' => $tenancyId,
            ]
        );
    }

    /**
     * A verificação que o seu Middleware usa (CRÍTICO)
     */
    // Dentro da PermissionsRules.php
    public static function userCanAccessRoute(int $userId, string $routeName, string $tenancyId): bool
    {
        return PermissionResolver::userCanAccessRoute([
            'id' => $userId,
            'tenancy_id' => $tenancyId,
        ], $routeName);
    }

    private static function normalizeRoutePath(string $routeName): string
    {
        $routeName = '/' . trim($routeName, '/');
        return preg_replace('#/+#', '/', $routeName) ?: '/';
    }

    private static function buildRouteCandidates(string $routeName): array
    {
        $normalized = self::normalizeRoutePath($routeName);
        $candidates = [$normalized];
        $segments = array_values(array_filter(explode('/', trim($normalized, '/')), 'strlen'));

        while (count($segments) > 1) {
            array_pop($segments);
            $candidates[] = '/' . implode('/', $segments);
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Vincula o usuário ao papel (Usado no getSetNewUsers)
     */
    public static function assignRoleToUser(int $userId, string $tenancyId, int $roleId): void
    {
        $db = new Database('user_roles');

        // PRIMEIRO: Remove qualquer papel anterior deste usuário nesta tenancy
        // Isso evita que ele acumule permissões de "Admin" e "Financeiro" ao mesmo tempo
        $db->execute("DELETE FROM user_roles WHERE user_id = :uid AND tenancy_id = :tid", [
            ':uid' => $userId,
            ':tid' => $tenancyId
        ]);

        // SEGUNDO: Insere o novo vínculo (se o roleId for maior que 0)
        if ($roleId > 0) {
            $db->insert([
                'user_id'    => $userId,
                'tenancy_id' => $tenancyId,
                'role_id'    => $roleId
            ]);
        }

        self::invalidatePermissionCaches($tenancyId, $userId);
    }

    /**
     * Lista as rotas padrão que um admin de tenancy pode receber na criação.
     */
    private static function getDefaultAdminRouteIds(): array
    {
        $db = new Database();

        $routes = $db->execute(
            "SELECT id
             FROM sys_routes
             WHERE " . self::COLUMN_ACCESS_SCOPE . " = 'tenant'
               AND " . self::COLUMN_ASSIGNABLE_BY . " = 'admin'"
        )->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $route): int => (int)($route['id'] ?? 0), $routes);
    }

    public static function getAssignableRouteCount(): int
    {
        return RequestCache::remember('permissions.rules.assignable_route_count', static function (): int {
            return (int)(new Database())->execute(
                "SELECT COUNT(*)
                 FROM sys_routes
                 WHERE " . self::COLUMN_ACCESS_SCOPE . " = 'tenant'
                   AND " . self::COLUMN_ASSIGNABLE_BY . " = 'admin'"
            )->fetchColumn();
        });
    }

    private static function syncRouteIntoDefaultAdminTemplate(int $routeId, array $governance): void
    {
        return;
    }

    /**
     * Inicializa as permissões padrão de um Admin da tenancy.
     */
    public static function initAdminPermissions(string $tenancyId, int $roleId): void
    {
        self::ensureRoleTemplateSchema();
        self::bindRoleToTemplateByName($roleId, $tenancyId, 'admin');
    }

    public static function getRolePermissionsNames(int $roleId): array
    {
        return PermissionResolver::getRolePermissionNames($roleId);
    }

    /**
     * Remove o vínculo entre usuário e papel na tabela intermediária
     */
    public static function removeRoleFromUser(int $userId, string $tenancyId, int $roleId): void
    {
        $db = new Database('user_roles');
        $db->execute("DELETE FROM user_roles WHERE user_id = :uid AND tenancy_id = :tid AND role_id = :rid", [
            ':uid' => $userId,
            ':tid' => $tenancyId,
            ':rid' => $roleId
        ]);

        self::invalidatePermissionCaches($tenancyId, $userId);
    }

    private static function ensureRoutesGovernanceSchema(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        self::ensureRouteColumn(self::COLUMN_ACCESS_SCOPE, "ALTER TABLE sys_routes ADD COLUMN " . self::COLUMN_ACCESS_SCOPE . " VARCHAR(20) NOT NULL DEFAULT 'tenant' AFTER route_path");
        self::ensureRouteColumn(self::COLUMN_ASSIGNABLE_BY, "ALTER TABLE sys_routes ADD COLUMN " . self::COLUMN_ASSIGNABLE_BY . " VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER " . self::COLUMN_ACCESS_SCOPE);

        (new Database())->execute(
            "UPDATE sys_routes
             SET " . self::COLUMN_ACCESS_SCOPE . " = 'tenant',
                 " . self::COLUMN_ASSIGNABLE_BY . " = 'admin'
             WHERE " . self::COLUMN_ACCESS_SCOPE . " IS NULL
                OR " . self::COLUMN_ACCESS_SCOPE . " = ''
                OR " . self::COLUMN_ASSIGNABLE_BY . " IS NULL
                OR " . self::COLUMN_ASSIGNABLE_BY . " = ''"
        );

        foreach (self::SUPERADMIN_ONLY_ROUTE_PREFIXES as $prefix) {
            $normalizedPrefix = self::normalizeGovernancePrefix($prefix);
            $patterns = self::governancePrefixSqlPatterns($normalizedPrefix);
            (new Database())->execute(
                "UPDATE sys_routes
                 SET " . self::COLUMN_ACCESS_SCOPE . " = 'platform',
                     " . self::COLUMN_ASSIGNABLE_BY . " = 'super_admin'
                 WHERE route_path = :prefix
                    OR route_path LIKE :prefix_like_slash
                    OR route_path LIKE :prefix_like_dash",
                [
                    ':prefix' => $normalizedPrefix,
                    ':prefix_like_slash' => $patterns['slash'],
                    ':prefix_like_dash' => $patterns['dash'],
                ]
            );
        }

        self::ensureAdministrativeRouteGrouping();
        $ensured = true;
    }

    public static function isSystemManagedRoleName(string $roleName): bool
    {
        return in_array(strtolower(trim($roleName)), self::SYSTEM_MANAGED_ROLE_NAMES, true);
    }

    public static function syncSystemManagedRolePermission(string $roleName, int $routeId, int $active): void
    {
        self::ensureRoleTemplateSchema();

        $normalizedRoleName = strtolower(trim($roleName));
        if (!self::isSystemManagedRoleName($normalizedRoleName)) {
            return;
        }

        $templateId = self::getOrCreateRoleTemplateId($normalizedRoleName);
        if ($templateId <= 0) {
            return;
        }

        $db = new Database('sys_role_template_permissions');
        if ($active === 1) {
            $db->execute(
                "INSERT IGNORE INTO sys_role_template_permissions (template_id, route_id)
                 VALUES (:template_id, :route_id)",
                [
                    ':template_id' => $templateId,
                    ':route_id' => $routeId,
                ]
            );
        } else {
            $db->execute(
                "DELETE FROM sys_role_template_permissions
                 WHERE template_id = :template_id
                   AND route_id = :route_id",
                [
                    ':template_id' => $templateId,
                    ':route_id' => $routeId,
                ]
            );
        }

        self::syncAllRolesBoundToTemplate($templateId);
        self::invalidatePermissionCaches();
    }

    private static function reconcileSystemManagedTemplate(string $roleName): void
    {
        self::ensureRoleTemplateSchema();

        $normalizedRoleName = strtolower(trim($roleName));
        if (!self::isSystemManagedRoleName($normalizedRoleName)) {
            return;
        }

        $templateId = self::getOrCreateRoleTemplateId($normalizedRoleName);
        if ($templateId <= 0) {
            return;
        }

        if ($normalizedRoleName !== 'admin') {
            self::syncAllRolesBoundToTemplate($templateId);
            self::invalidatePermissionCaches();
            return;
        }

        self::sanitizeTemplatePermissions($templateId);
        self::syncAllRolesBoundToTemplate($templateId);
        self::invalidatePermissionCaches();
    }

    public static function syncRoleTemplatePermission(int $templateId, int $routeId, int $active): void
    {
        self::ensureRoleTemplateSchema();

        if ($templateId <= 0) {
            return;
        }

        $db = new Database('sys_role_template_permissions');
        if ($active === 1) {
            $db->execute(
                "INSERT IGNORE INTO sys_role_template_permissions (template_id, route_id)
                 VALUES (:template_id, :route_id)",
                [
                    ':template_id' => $templateId,
                    ':route_id' => $routeId,
                ]
            );
        } else {
            $db->execute(
                "DELETE FROM sys_role_template_permissions
                 WHERE template_id = :template_id
                   AND route_id = :route_id",
                [
                    ':template_id' => $templateId,
                    ':route_id' => $routeId,
                ]
            );
        }

        self::sanitizeTemplatePermissions($templateId);
        self::syncAllRolesBoundToTemplate($templateId);
        self::invalidatePermissionCaches();
    }

    public static function clearTemplatePermissions(int $templateId): void
    {
        self::ensureRoleTemplateSchema();

        if ($templateId <= 0) {
            return;
        }

        (new Database())->execute(
            "DELETE FROM sys_role_template_permissions
             WHERE template_id = :template_id",
            [
                ':template_id' => $templateId,
            ]
        );

        self::sanitizeTemplatePermissions($templateId);
        self::syncAllRolesBoundToTemplate($templateId);
        self::invalidatePermissionCaches();
    }

    public static function bulkToggleTemplatePermissions(int $templateId, array $routeIds, int $active): void
    {
        self::ensureRoleTemplateSchema();

        if ($templateId <= 0) {
            return;
        }

        $routeIds = array_values(array_unique(array_filter(array_map('intval', $routeIds), static fn (int $id): bool => $id > 0)));
        if ($routeIds === []) {
            return;
        }

        $idsSql = implode(',', $routeIds);
        $db = new Database();

        if ($active === 1) {
            $db->execute(
                "INSERT IGNORE INTO sys_role_template_permissions (template_id, route_id)
                 SELECT :template_id, sr.id
                 FROM sys_routes sr
                 WHERE sr.id IN (" . $idsSql . ")",
                [
                    ':template_id' => $templateId,
                ]
            );
        } else {
            $db->execute(
                "DELETE FROM sys_role_template_permissions
                 WHERE template_id = :template_id
                   AND route_id IN (" . $idsSql . ")",
                [
                    ':template_id' => $templateId,
                ]
            );
        }

        self::syncAllRolesBoundToTemplate($templateId);
        self::invalidatePermissionCaches();
    }

    public static function bindRoleToTemplateByName(int $roleId, string $tenancyId, string $templateName): void
    {
        self::applyRoleTemplateSnapshotByName($roleId, $tenancyId, $templateName, true);
    }

    public static function syncRoleFromTemplateSnapshot(int $roleId, string $tenancyId, ?string $templateName = null): void
    {
        if ($roleId <= 0 || trim($tenancyId) === '') {
            return;
        }

        if ($templateName !== null && trim($templateName) !== '') {
            self::applyRoleTemplateSnapshotByName($roleId, $tenancyId, $templateName, false);
            return;
        }

        $role = self::getRoleById($roleId, $tenancyId);
        if (!$role) {
            return;
        }

        $templateId = (int)($role[self::COLUMN_ROLE_TEMPLATE_ID] ?? 0);
        if ($templateId <= 0) {
            return;
        }

        $template = self::getRoleTemplateById($templateId);
        $templateName = strtolower(trim((string)($template['name'] ?? '')));
        if ($templateName === '') {
            return;
        }

        self::applyRoleTemplateSnapshotByName($roleId, $tenancyId, $templateName, false);
    }

    private static function applyRoleTemplateSnapshotByName(int $roleId, string $tenancyId, string $templateName, bool $inheritTemplate): void
    {
        $normalizedTemplateName = strtolower(trim($templateName));
        $templateId = self::getOrCreateRoleTemplateId($normalizedTemplateName);
        if ($templateId <= 0) {
            return;
        }

        (new Database('sys_roles'))->update(
            'id = :id AND tenancy_id = :tenancy_id',
            [
                self::COLUMN_ROLE_TEMPLATE_ID => $templateId,
                self::COLUMN_INHERITS_TEMPLATE => $inheritTemplate ? 'y' : 'n',
            ],
            [
                ':id' => $roleId,
                ':tenancy_id' => $tenancyId,
            ]
        );

        self::syncRolePermissionsFromTemplate($roleId, $tenancyId, $templateId);
        self::invalidatePermissionCaches($tenancyId);
    }

    private static function syncRolePermissionsFromTemplate(int $roleId, string $tenancyId, ?int $templateId = null): void
    {
        if ($templateId === null) {
            $templateId = (int)(new Database())->execute(
                "SELECT " . self::COLUMN_ROLE_TEMPLATE_ID . "
                 FROM sys_roles
                 WHERE id = :id
                   AND tenancy_id = :tenancy_id
                 LIMIT 1",
                [
                    ':id' => $roleId,
                    ':tenancy_id' => $tenancyId,
                ]
            )->fetchColumn();
        }

        if ($templateId <= 0) {
            return;
        }

        $db = new Database();
        $db->execute(
            "DELETE FROM sys_role_permissions
             WHERE tenancy_id = :tenancy_id
               AND role_id = :role_id",
            [
                ':tenancy_id' => $tenancyId,
                ':role_id' => $roleId,
            ]
        );

        $db->execute(
            "INSERT IGNORE INTO sys_role_permissions (tenancy_id, role_id, route_id)
             SELECT :tenancy_id, :role_id, trp.route_id
             FROM sys_role_template_permissions trp
             WHERE trp.template_id = :template_id",
            [
                ':tenancy_id' => $tenancyId,
                ':role_id' => $roleId,
                ':template_id' => $templateId,
            ]
        );

        self::sanitizeRolePermissions($roleId, $tenancyId);
        self::invalidatePermissionCaches($tenancyId);
    }

    private static function syncAllRolesBoundToTemplate(int $templateId): void
    {
        $roles = (new Database())->execute(
            "SELECT id, tenancy_id
             FROM sys_roles
             WHERE " . self::COLUMN_ROLE_TEMPLATE_ID . " = :template_id
               AND " . self::COLUMN_INHERITS_TEMPLATE . " = 'y'",
            [':template_id' => $templateId]
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($roles as $role) {
            $roleId = (int)($role['id'] ?? 0);
            $tenancyId = (string)($role['tenancy_id'] ?? '');

            if ($roleId <= 0 || $tenancyId === '') {
                continue;
            }

            self::syncRolePermissionsFromTemplate($roleId, $tenancyId, $templateId);
        }
    }

    private static function ensureRoleTemplateSchema(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        self::ensureRoutesGovernanceSchema();

        $db = new Database();
        $db->execute("
            CREATE TABLE IF NOT EXISTS sys_role_templates (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                label VARCHAR(150) NOT NULL,
                description VARCHAR(255) NULL,
                status CHAR(1) NOT NULL DEFAULT 'y',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_sys_role_templates_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->execute("
            CREATE TABLE IF NOT EXISTS sys_role_template_permissions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                template_id INT UNSIGNED NOT NULL,
                route_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_sys_role_template_permissions (template_id, route_id),
                KEY idx_sys_role_template_permissions_route (route_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        self::ensureSysRolesColumn(
            self::COLUMN_ROLE_TEMPLATE_ID,
            "ALTER TABLE sys_roles ADD COLUMN " . self::COLUMN_ROLE_TEMPLATE_ID . " INT UNSIGNED NULL AFTER user_id"
        );
        self::ensureSysRolesColumn(
            self::COLUMN_INHERITS_TEMPLATE,
            "ALTER TABLE sys_roles ADD COLUMN " . self::COLUMN_INHERITS_TEMPLATE . " CHAR(1) NOT NULL DEFAULT 'n' AFTER " . self::COLUMN_ROLE_TEMPLATE_ID
        );

        $ensured = true;
        self::seedRoleTemplates();
    }

    public static function seedRoleTemplates(): void
    {
        foreach (array_keys(self::getSystemRoleTemplateDefinitions()) as $roleName) {
            $templateId = self::getOrCreateRoleTemplateId($roleName);
            self::sanitizeTemplatePermissions($templateId);
        }
    }

    private static function getOrCreateRoleTemplateId(string $name): int
    {
        $normalizedName = strtolower(trim($name));
        $db = new Database();

        $templateId = (int)$db->execute(
            "SELECT id
             FROM sys_role_templates
             WHERE name = :name
             LIMIT 1",
            [':name' => $normalizedName]
        )->fetchColumn();

        if ($templateId > 0) {
            return $templateId;
        }

        $definitions = self::getSystemRoleTemplateDefinitions();
        $meta = $definitions[$normalizedName] ?? [ucfirst($normalizedName), 'Papel padrao do sistema'];

        $templateId = (int)(new Database('sys_role_templates'))->insert([
            'name' => $normalizedName,
            'label' => $meta[0],
            'description' => $meta[1],
            'status' => 'y',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        self::seedTemplatePermissionsIfEmpty($templateId, $normalizedName);

        return $templateId;
    }

    private static function seedTemplatePermissionsIfEmpty(int $templateId, string $templateName): void
    {
        $existing = (int)(new Database())->execute(
            "SELECT COUNT(*)
             FROM sys_role_template_permissions
             WHERE template_id = :template_id",
            [':template_id' => $templateId]
        )->fetchColumn();

        if ($existing > 0) {
            return;
        }

        $routeIds = self::getDefaultTemplateRouteIds($templateName);
        if ($routeIds === []) {
            return;
        }

        $db = new Database('sys_role_template_permissions');
        foreach ($routeIds as $routeId) {
            if ($routeId <= 0) {
                continue;
            }

            $db->insert([
                'template_id' => $templateId,
                'route_id' => $routeId,
            ]);
        }

        self::sanitizeTemplatePermissions($templateId);
    }

    private static function sanitizeTemplatePermissions(int $templateId): void
    {
        if ($templateId <= 0) {
            return;
        }

        $templateName = strtolower(trim((string)(new Database())->execute(
            "SELECT name
             FROM sys_role_templates
             WHERE id = :id
             LIMIT 1",
            [':id' => $templateId]
        )->fetchColumn()));

        if ($templateName === 'admin') {
            return;
        }

        if (self::templateUsesAppendOnlyGovernance($templateName)) {
            (new Database())->execute(
                "DELETE trp
                 FROM sys_role_template_permissions trp
                 INNER JOIN sys_routes sr ON sr.id = trp.route_id
                 WHERE trp.template_id = :template_id
                   AND (
                        sr." . self::COLUMN_ACCESS_SCOPE . " <> 'tenant'
                        OR sr." . self::COLUMN_ASSIGNABLE_BY . " <> 'admin'
                   )",
                [':template_id' => $templateId]
            );
            return;
        }

        $expectedRouteIds = self::getDefaultTemplateRouteIds($templateName);
        if ($expectedRouteIds !== []) {
            $expectedLookup = array_fill_keys(array_map('intval', $expectedRouteIds), true);
            $currentRouteIds = (new Database())->execute(
                "SELECT route_id
                 FROM sys_role_template_permissions
                 WHERE template_id = :template_id",
                [':template_id' => $templateId]
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];

            foreach ($currentRouteIds as $routeId) {
                $routeId = (int)$routeId;
                if ($routeId <= 0 || isset($expectedLookup[$routeId])) {
                    continue;
                }

                (new Database())->execute(
                    "DELETE FROM sys_role_template_permissions
                     WHERE template_id = :template_id
                       AND route_id = :route_id",
                    [
                        ':template_id' => $templateId,
                        ':route_id' => $routeId,
                    ]
                );
            }

            foreach ($expectedRouteIds as $routeId) {
                $routeId = (int)$routeId;
                if ($routeId <= 0) {
                    continue;
                }

                (new Database())->execute(
                    "INSERT IGNORE INTO sys_role_template_permissions (template_id, route_id)
                     VALUES (:template_id, :route_id)",
                    [
                        ':template_id' => $templateId,
                        ':route_id' => $routeId,
                    ]
                );
            }

            return;
        }

    }

    private static function templateUsesAppendOnlyGovernance(string $templateName): bool
    {
        return false;
    }

    private static function sanitizeRolePermissions(int $roleId, string $tenancyId): void
    {
        if ($roleId <= 0 || trim($tenancyId) === '') {
            return;
        }
    }

    private static function filterPermissionMatrixByAdminBaseline(array $rows, string $roleName, bool $bypass = false): array
    {
        return $rows;
    }

    private static function getAdminGovernedRouteIds(): array
    {
        return RequestCache::remember('permissions.rules.admin_governed_route_ids', static function (): array {
            $rows = (new Database())->execute(
                "SELECT id
                 FROM sys_routes
                 WHERE " . self::COLUMN_ACCESS_SCOPE . " = 'tenant'
                   AND " . self::COLUMN_ASSIGNABLE_BY . " = 'admin'"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];

            return array_values(array_unique(array_filter(array_map('intval', $rows), static fn (int $id): bool => $id > 0)));
        });
    }

    private static function getAdminTemplatePermissionRouteIds(): array
    {
        return RequestCache::remember('permissions.rules.admin_template_route_ids', static function (): array {
            $templateId = self::getOrCreateRoleTemplateId('admin');
            if ($templateId <= 0) {
                return [];
            }

            $rows = (new Database())->execute(
                "SELECT trp.route_id
                 FROM sys_role_template_permissions trp
                 INNER JOIN sys_routes sr ON sr.id = trp.route_id
                 WHERE trp.template_id = :template_id
                   AND sr." . self::COLUMN_ACCESS_SCOPE . " = 'tenant'
                   AND sr." . self::COLUMN_ASSIGNABLE_BY . " = 'admin'",
                [':template_id' => $templateId]
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];

            return array_values(array_unique(array_filter(array_map('intval', $rows), static fn (int $id): bool => $id > 0)));
        });
    }

    private static function resolveRoleNameById(int $roleId, string $tenancyId): string
    {
        if ($roleId <= 0 || trim($tenancyId) === '') {
            return '';
        }

        return strtolower(trim((string)(new Database())->execute(
            "SELECT name
             FROM sys_roles
             WHERE id = :id
               AND tenancy_id = :tenancy_id
             LIMIT 1",
            [
                ':id' => $roleId,
                ':tenancy_id' => $tenancyId,
            ]
        )->fetchColumn()));
    }

    private static function resolveTemplateNameById(int $templateId): string
    {
        if ($templateId <= 0) {
            return '';
        }

        return strtolower(trim((string)(new Database())->execute(
            "SELECT name
             FROM sys_role_templates
             WHERE id = :id
             LIMIT 1",
            [':id' => $templateId]
        )->fetchColumn()));
    }

    private static function isAdminBaselineExemptRole(string $roleName): bool
    {
        return strtolower(trim($roleName)) === 'super_admin';
    }

    private static function roleShouldBypassAdminBaseline(int $roleId, string $tenancyId, string $roleName = ''): bool
    {
        if ($roleId <= 0 || trim($tenancyId) === '') {
            return false;
        }

        if (self::isAdminBaselineExemptRole($roleName)) {
            return true;
        }

        return self::roleRepresentsSuperAdmin($roleId, $tenancyId);
    }

    private static function roleRepresentsSuperAdmin(int $roleId, string $tenancyId): bool
    {
        if ($roleId <= 0 || trim($tenancyId) === '') {
            return false;
        }

        return RequestCache::remember(
            'permissions.rules.role_represents_super_admin.' . $tenancyId . '.' . $roleId,
            static function () use ($roleId, $tenancyId): bool {
                return (bool)(new Database())->execute(
                    "SELECT 1
                     FROM users
                     WHERE tenancy_id = :tenancy_id
                       AND role_id = :role_id
                       AND LOWER(TRIM(COALESCE(user_function, ''))) = 'super_admin'
                     LIMIT 1",
                    [
                        ':tenancy_id' => $tenancyId,
                        ':role_id' => $roleId,
                    ]
                )->fetchColumn();
            }
        );
    }

    private static function shouldUseTenantTemplateSnapshot(string $roleName): bool
    {
        $roleName = strtolower(trim($roleName));
        return self::isSystemManagedRoleName($roleName) && !in_array($roleName, ['admin', 'super_admin'], true);
    }

    private static function getDefaultTemplateRouteIds(string $templateName): array
    {
        $templateName = strtolower(trim($templateName));
        $seed = self::SYSTEM_ROLE_ROUTE_SEEDS[$templateName] ?? null;
        if (!is_array($seed)) {
            return [];
        }

        $mode = strtolower(trim((string)($seed['mode'] ?? 'routes')));
        if ($mode === 'governance') {
            return self::getDefaultAdminRouteIds();
        }

        $routePaths = array_values(array_unique(array_filter(array_map(
            static fn ($route): string => self::normalizeRoutePath((string)$route),
            (array)($seed['routes'] ?? [])
        ))));

        if ($routePaths === []) {
            return [];
        }

        $ids = [];
        foreach ($routePaths as $routePath) {
            $routeId = (int)(new Database())->execute(
                "SELECT id
                 FROM sys_routes
                 WHERE route_path = :route_path
                   AND " . self::COLUMN_ACCESS_SCOPE . " = 'tenant'
                   AND " . self::COLUMN_ASSIGNABLE_BY . " = 'admin'
                 LIMIT 1",
                [':route_path' => $routePath]
            )->fetchColumn();

            if ($routeId > 0) {
                $ids[] = $routeId;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function getTemplateDisplayName(string $templateName): string
    {
        return strtolower(trim($templateName)) . '_padrao_sistema';
    }

    private static function getSystemRoleTemplateDefinitions(): array
    {
        return [
            'admin' => ['Administrador', 'Papel padrao do sistema para administradores da tenancy'],
            'rh' => ['Recursos Humanos', 'Papel padrao do sistema para recursos humanos'],
            'financial' => ['Financeiro', 'Papel padrao do sistema para financeiro'],
            'reception' => ['Recepcao', 'Papel padrao do sistema para recepcao'],
            'manager' => ['Gerente de Operacoes', 'Papel padrao do sistema para gerente de operacoes'],
            'supervisor' => ['Supervisor', 'Papel padrao do sistema para supervisor'],
            'agent' => ['Agente / Operador', 'Papel padrao do sistema para agente'],
            'monitor' => ['Monitor de Qualidade', 'Papel padrao do sistema para monitor'],
            'support_l1' => ['Suporte Nivel 1', 'Papel padrao do sistema para suporte nivel 1'],
            'support_l2' => ['Suporte Nivel 2', 'Papel padrao do sistema para suporte nivel 2'],
            'ticket_support' => ['Atendimento de Tickets', 'Papel padrao do sistema para atendimento de tickets'],
            'support_ticket_manager' => ['Gestor de Tickets', 'Papel padrao do sistema para gestor de tickets'],
            'reseller' => ['Revendedor', 'Papel padrao do sistema para revendedor'],
        ];
    }

    public static function columnAccessScope(): string
    {
        return self::COLUMN_ACCESS_SCOPE;
    }

    public static function columnAssignableBy(): string
    {
        return self::COLUMN_ASSIGNABLE_BY;
    }

    public static function columnRoleTemplateId(): string
    {
        return self::COLUMN_ROLE_TEMPLATE_ID;
    }

    public static function columnInheritsTemplate(): string
    {
        return self::COLUMN_INHERITS_TEMPLATE;
    }

    public static function superAdminOnlyRoutePrefixes(): array
    {
        return self::SUPERADMIN_ONLY_ROUTE_PREFIXES;
    }

    public static function administrativeModuleGroups(): array
    {
        return self::ADMINISTRATIVE_MODULE_GROUPS;
    }

    private static function ensureSysRolesColumn(string $column, string $sql): void
    {
        $exists = (bool)(new Database())->execute(
            'SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column
             LIMIT 1',
            [
                ':table' => 'sys_roles',
                ':column' => $column,
            ]
        )->fetchColumn();

        if (!$exists) {
            (new Database())->execute($sql);
        }
    }

    private static function ensureRouteColumn(string $column, string $sql): void
    {
        $exists = (bool)(new Database())->execute(
            'SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column
             LIMIT 1',
            [
                ':table' => 'sys_routes',
                ':column' => $column,
            ]
        )->fetchColumn();

        if (!$exists) {
            (new Database())->execute($sql);
        }
    }

    public static function syncRouteModuleGrouping(): void
    {
        foreach (array_reverse(self::ADMINISTRATIVE_MODULE_GROUPS, true) as $prefix => $moduleName) {
            $normalizedPrefix = self::normalizeGovernancePrefix($prefix);
            $patterns = self::governancePrefixSqlPatterns($normalizedPrefix);
            (new Database())->execute(
                "UPDATE sys_routes
                 SET module_name = :module_name
                 WHERE route_path = :prefix
                    OR route_path LIKE :prefix_like_slash
                    OR route_path LIKE :prefix_like_dash",
                [
                    ':module_name' => $moduleName,
                    ':prefix' => $normalizedPrefix,
                    ':prefix_like_slash' => $patterns['slash'],
                    ':prefix_like_dash' => $patterns['dash'],
                ]
            );
        }

        self::invalidatePermissionCaches();
    }

    private static function ensureAdministrativeRouteGrouping(): void
    {
        self::syncRouteModuleGrouping();
    }

    private static function resolveGovernanceForRoutePath(string $routePath): array
    {
        $normalizedRoutePath = self::normalizeGovernancePrefix($routePath);

        foreach (self::SUPERADMIN_ONLY_ROUTE_PREFIXES as $prefix) {
            $normalizedPrefix = self::normalizeGovernancePrefix($prefix);

            if (self::routeMatchesGovernancePrefix($normalizedRoutePath, $normalizedPrefix)) {
                return [
                    'access_scope' => 'platform',
                    'assignable_by' => 'super_admin',
                ];
            }
        }

        return [
            'access_scope' => 'tenant',
            'assignable_by' => 'admin',
        ];
    }

    private static function resolveModuleNameForRoute(string $routePath, string $fallbackLabel): string
    {
        $normalizedRoutePath = self::normalizeGovernancePrefix($routePath);

        foreach (self::ADMINISTRATIVE_MODULE_GROUPS as $prefix => $moduleName) {
            $normalizedPrefix = self::normalizeGovernancePrefix($prefix);

            if (self::routeMatchesGovernancePrefix($normalizedRoutePath, $normalizedPrefix)) {
                return $moduleName;
            }
        }

        return trim($fallbackLabel) !== '' ? $fallbackLabel : 'Sem Rótulo';
    }

    private static function normalizeGovernancePrefix(string $prefix): string
    {
        if (str_starts_with($prefix, 'ticket.')) {
            return $prefix;
        }

        return '/' . trim($prefix, '/');
    }

    public static function normalizeGovernancePrefixPublic(string $prefix): string
    {
        return self::normalizeGovernancePrefix($prefix);
    }

    public static function governancePrefixSqlPatternsPublic(string $prefix): array
    {
        return self::governancePrefixSqlPatterns(self::normalizeGovernancePrefix($prefix));
    }

    private static function routeMatchesGovernancePrefix(string $routePath, string $prefix): bool
    {
        if (str_starts_with($prefix, 'ticket.')) {
            return $routePath === $prefix || str_starts_with($routePath, $prefix);
        }

        return $routePath === $prefix
            || str_starts_with($routePath, $prefix . '/')
            || str_starts_with($routePath, $prefix . '-');
    }

    private static function governancePrefixSqlPatterns(string $prefix): array
    {
        if (str_starts_with($prefix, 'ticket.')) {
            return [
                'slash' => $prefix . '%',
                'dash' => '',
            ];
        }

        return [
            'slash' => $prefix . '/%',
            'dash' => $prefix . '-%',
        ];
    }

    public static function invalidatePermissionCaches(?string $tenancyId = null, ?int $userId = null): void
    {
        PermissionResolver::invalidate($tenancyId, $userId);
    }
}
