-- Auditoria somente leitura do modulo de permissoes.
-- Objetivo: diagnosticar inconsistencias sem alterar dados.

-- 1) Catalogo de rotas com governanca
SELECT
    sr.id,
    sr.module_name,
    sr.route_path,
    sr.access_scope,
    sr.assignable_by
FROM sys_routes sr
ORDER BY sr.module_name, sr.route_path;

-- 2) Rotas duplicadas no catalogo
SELECT
    sr.route_path,
    COUNT(*) AS total
FROM sys_routes sr
GROUP BY sr.route_path
HAVING COUNT(*) > 1
ORDER BY total DESC, sr.route_path;

-- 3) Permissoes de papel apontando para rotas inexistentes
SELECT
    srp.id,
    srp.tenancy_id,
    srp.role_id,
    srp.route_id
FROM sys_role_permissions srp
LEFT JOIN sys_routes sr ON sr.id = srp.route_id
WHERE sr.id IS NULL
ORDER BY srp.tenancy_id, srp.role_id, srp.route_id;

-- 4) Permissoes de template apontando para rotas inexistentes
SELECT
    strp.id,
    strp.template_id,
    strp.route_id
FROM sys_role_template_permissions strp
LEFT JOIN sys_routes sr ON sr.id = strp.route_id
WHERE sr.id IS NULL
ORDER BY strp.template_id, strp.route_id;

-- 5) Papeis com rotas superadmin-only persistidas
SELECT
    r.tenancy_id,
    r.id AS role_id,
    r.name AS role_name,
    sr.id AS route_id,
    sr.route_path,
    sr.access_scope,
    sr.assignable_by
FROM sys_role_permissions srp
INNER JOIN sys_roles r ON r.id = srp.role_id AND r.tenancy_id = srp.tenancy_id
INNER JOIN sys_routes sr ON sr.id = srp.route_id
WHERE sr.access_scope <> 'tenant'
   OR sr.assignable_by <> 'admin'
ORDER BY r.tenancy_id, r.name, sr.route_path;

-- 6) Templates com rotas fora da governanca tenant/admin
SELECT
    rt.id AS template_id,
    rt.name AS template_name,
    sr.id AS route_id,
    sr.route_path,
    sr.access_scope,
    sr.assignable_by
FROM sys_role_template_permissions strp
INNER JOIN sys_role_templates rt ON rt.id = strp.template_id
INNER JOIN sys_routes sr ON sr.id = strp.route_id
WHERE sr.access_scope <> 'tenant'
   OR sr.assignable_by <> 'admin'
ORDER BY rt.name, sr.route_path;

-- 7) Divergencia entre users.role_id e user_roles.role_id
SELECT
    u.id AS user_id,
    u.tenancy_id,
    u.role_id AS users_role_id,
    ur.role_id AS pivot_role_id,
    u.user_function
FROM users u
LEFT JOIN user_roles ur
    ON ur.user_id = u.id
   AND ur.tenancy_id = u.tenancy_id
WHERE COALESCE(u.role_id, 0) <> COALESCE(ur.role_id, 0)
ORDER BY u.tenancy_id, u.id;

-- 8) Usuarios sem role no users e com role no pivot, ou vice-versa
SELECT
    u.id AS user_id,
    u.tenancy_id,
    u.role_id AS users_role_id,
    ur.role_id AS pivot_role_id,
    u.user_function
FROM users u
LEFT JOIN user_roles ur
    ON ur.user_id = u.id
   AND ur.tenancy_id = u.tenancy_id
WHERE COALESCE(u.role_id, 0) = 0
   OR COALESCE(ur.role_id, 0) = 0
ORDER BY u.tenancy_id, u.id;

-- 9) Papeis protegidos do sistema por tenancy
SELECT
    r.tenancy_id,
    r.id,
    r.name,
    r.label,
    r.template_id,
    r.inherits_template,
    r.status
FROM sys_roles r
WHERE LOWER(TRIM(r.name)) IN ('super_admin', 'admin')
ORDER BY r.tenancy_id, r.name, r.id;

-- 10) Cobertura critica do modulo de notificacoes
SELECT
    sr.id,
    sr.module_name,
    sr.route_path,
    sr.access_scope,
    sr.assignable_by
FROM sys_routes sr
WHERE sr.route_path IN (
    '/reports/notifications',
    '/reports/notifications-realtime',
    '/reports/notifications/users',
    '/reports/notifications/create'
)
ORDER BY sr.route_path;

-- 11) Totais de rotas por governanca
SELECT
    sr.access_scope,
    sr.assignable_by,
    COUNT(*) AS total_routes
FROM sys_routes sr
GROUP BY sr.access_scope, sr.assignable_by
ORDER BY sr.access_scope, sr.assignable_by;

-- 12) Totais de permissoes por papel
SELECT
    r.tenancy_id,
    r.id AS role_id,
    r.name AS role_name,
    COUNT(srp.id) AS total_permissions
FROM sys_roles r
LEFT JOIN sys_role_permissions srp
    ON srp.role_id = r.id
   AND srp.tenancy_id = r.tenancy_id
GROUP BY r.tenancy_id, r.id, r.name
ORDER BY r.tenancy_id, r.name;

-- 13) Totais de permissoes por template
SELECT
    rt.id AS template_id,
    rt.name AS template_name,
    COUNT(strp.id) AS total_permissions
FROM sys_role_templates rt
LEFT JOIN sys_role_template_permissions strp
    ON strp.template_id = rt.id
GROUP BY rt.id, rt.name
ORDER BY rt.name;

-- 14) Cache versionado por tenancy e usuario
SELECT
    scope_type,
    tenancy_id,
    user_id,
    version,
    updated_at
FROM sys_permission_cache_versions
ORDER BY scope_type, tenancy_id, user_id;
