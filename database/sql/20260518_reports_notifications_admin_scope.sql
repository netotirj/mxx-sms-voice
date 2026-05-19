UPDATE sys_routes
SET access_scope = 'tenant',
    assignable_by = 'admin'
WHERE route_path = '/reports/notifications'
   OR route_path LIKE '/reports/notifications/%'
   OR route_path LIKE '/reports/notifications-%';
