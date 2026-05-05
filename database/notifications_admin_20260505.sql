INSERT INTO sys_routes (module_name, route_path)
SELECT 'Sistema: Notificações e Alertas', '/reports/notifications/users'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/reports/notifications/users');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Sistema: Notificações e Alertas', '/reports/notifications/create'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/reports/notifications/create');

INSERT IGNORE INTO sys_role_permissions (tenancy_id, role_id, route_id)
SELECT rp.tenancy_id, rp.role_id, target.id
FROM sys_role_permissions rp
INNER JOIN sys_routes source ON source.id = rp.route_id AND source.route_path = '/reports/notifications'
INNER JOIN sys_routes target ON target.route_path IN (
    '/reports/notifications/users',
    '/reports/notifications/create'
);
