INSERT INTO sys_routes (module_name, route_path, access_scope, assignable_by)
SELECT 'Relatórios: Notificações', '/reports/notifications/users', 'platform', 'super_admin'
WHERE NOT EXISTS (
    SELECT 1
    FROM sys_routes
    WHERE route_path = '/reports/notifications/users'
);

INSERT INTO sys_routes (module_name, route_path, access_scope, assignable_by)
SELECT 'Relatórios: Notificações', '/reports/notifications/create', 'platform', 'super_admin'
WHERE NOT EXISTS (
    SELECT 1
    FROM sys_routes
    WHERE route_path = '/reports/notifications/create'
);

UPDATE sys_routes
SET module_name = 'Relatórios: Notificações',
    access_scope = 'platform',
    assignable_by = 'super_admin'
WHERE route_path IN (
    '/reports/notifications',
    '/reports/notifications-realtime',
    '/reports/notifications/users',
    '/reports/notifications/create'
);
