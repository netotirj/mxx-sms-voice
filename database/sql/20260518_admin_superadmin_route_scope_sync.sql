UPDATE sys_routes
SET access_scope = 'tenant',
    assignable_by = 'admin'
WHERE access_scope IS NULL
   OR access_scope = ''
   OR assignable_by IS NULL
   OR assignable_by = '';

UPDATE sys_routes
SET access_scope = 'platform',
    assignable_by = 'super_admin'
WHERE route_path IN (
        '/plans',
        '/global-costs',
        '/admin/platform-consumption',
        '/admin/services-monitor',
        '/site-tests',
        '/reports/notifications/create',
        '/reports/notifications/users',
        '/permissions/global-routes',
        'ticket.'
    )
   OR route_path LIKE '/plans/%'
   OR route_path LIKE '/plans-%'
   OR route_path LIKE '/global-costs/%'
   OR route_path LIKE '/global-costs-%'
   OR route_path LIKE '/admin/platform-consumption/%'
   OR route_path LIKE '/admin/platform-consumption-%'
   OR route_path LIKE '/admin/services-monitor/%'
   OR route_path LIKE '/admin/services-monitor-%'
   OR route_path LIKE '/site-tests/%'
   OR route_path LIKE '/site-tests-%'
   OR route_path LIKE '/reports/notifications/create/%'
   OR route_path LIKE '/reports/notifications/create-%'
   OR route_path LIKE '/reports/notifications/users/%'
   OR route_path LIKE '/reports/notifications/users-%'
   OR route_path LIKE '/permissions/global-routes/%'
   OR route_path LIKE '/permissions/global-routes-%'
   OR route_path LIKE 'ticket.%';

DELETE trp
FROM sys_role_template_permissions trp
INNER JOIN sys_role_templates tpl ON tpl.id = trp.template_id
INNER JOIN sys_routes sr ON sr.id = trp.route_id
WHERE tpl.name = 'admin'
  AND (
        sr.access_scope <> 'tenant'
        OR sr.assignable_by <> 'admin'
      );

DELETE rp
FROM sys_role_permissions rp
INNER JOIN sys_roles r ON r.id = rp.role_id AND r.tenancy_id = rp.tenancy_id
INNER JOIN sys_routes sr ON sr.id = rp.route_id
WHERE LOWER(TRIM(r.name)) <> 'super_admin'
  AND (
        sr.access_scope <> 'tenant'
        OR sr.assignable_by <> 'admin'
      );
