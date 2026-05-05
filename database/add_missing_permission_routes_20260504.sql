INSERT INTO sys_routes (module_name, route_path) VALUES
    ('WhatsApp - Números', '/campaign/whatsapp/number-requests/{id}/send-meta'),
    ('WhatsApp - Números', '/campaign/whatsapp/number-requests/{id}/approve'),
    ('WhatsApp - Números', '/campaign/whatsapp/number-requests/{id}/reject'),
    ('WhatsApp - Números', '/campaign/whatsapp/number-requests/{id}/resend-code'),
    ('WhatsApp - Números', '/campaign/whatsapp/number-requests/{id}/confirm-code'),
    ('WhatsApp - Precificação', '/campaign/whatsapp/pricing/simulate'),
    ('WhatsApp - Templates', '/campaign/whatsapp/templates/library'),
    ('Planos e Recargas: Gestão Geral', '/refills/{type}/plans'),
    ('Sistema: Testes', '/site-tests'),
    ('Gestão de Usuários', '/users/{id}/edit')
ON DUPLICATE KEY UPDATE module_name = VALUES(module_name);

INSERT IGNORE INTO sys_role_permissions (tenancy_id, role_id, route_id)
SELECT rp.tenancy_id, rp.role_id, target.id
FROM sys_role_permissions rp
INNER JOIN sys_routes source ON source.id = rp.route_id
INNER JOIN sys_routes target ON target.route_path = CONCAT('/campaign/whatsapp/number-requests/{id}', SUBSTRING(source.route_path, LENGTH('/campaign/whatsapp/number-requests') + 1))
WHERE source.route_path IN (
    '/campaign/whatsapp/number-requests/send-meta',
    '/campaign/whatsapp/number-requests/approve',
    '/campaign/whatsapp/number-requests/reject',
    '/campaign/whatsapp/number-requests/resend-code',
    '/campaign/whatsapp/number-requests/confirm-code'
);

INSERT IGNORE INTO sys_role_permissions (tenancy_id, role_id, route_id)
SELECT rp.tenancy_id, rp.role_id, target.id
FROM sys_role_permissions rp
INNER JOIN sys_routes source ON source.id = rp.route_id AND source.route_path = '/campaign/whatsapp/templates'
INNER JOIN sys_routes target ON target.route_path = '/campaign/whatsapp/templates/library';

INSERT IGNORE INTO sys_role_permissions (tenancy_id, role_id, route_id)
SELECT rp.tenancy_id, rp.role_id, target.id
FROM sys_role_permissions rp
INNER JOIN sys_routes source ON source.id = rp.route_id AND source.route_path = '/campaign/whatsapp'
INNER JOIN sys_routes target ON target.route_path = '/campaign/whatsapp/pricing/simulate';

INSERT IGNORE INTO sys_role_permissions (tenancy_id, role_id, route_id)
SELECT rp.tenancy_id, rp.role_id, target.id
FROM sys_role_permissions rp
INNER JOIN sys_routes source ON source.id = rp.route_id AND source.route_path = '/refills'
INNER JOIN sys_routes target ON target.route_path = '/refills/{type}/plans';

INSERT IGNORE INTO sys_role_permissions (tenancy_id, role_id, route_id)
SELECT rp.tenancy_id, rp.role_id, target.id
FROM sys_role_permissions rp
INNER JOIN sys_routes source ON source.id = rp.route_id AND source.route_path = '/users'
INNER JOIN sys_routes target ON target.route_path = '/users/{id}/edit';

INSERT IGNORE INTO sys_role_permissions (tenancy_id, role_id, route_id)
SELECT rp.tenancy_id, rp.role_id, target.id
FROM sys_role_permissions rp
INNER JOIN sys_routes source ON source.id = rp.route_id AND source.route_path = '/permissions'
INNER JOIN sys_routes target ON target.route_path = '/site-tests';
