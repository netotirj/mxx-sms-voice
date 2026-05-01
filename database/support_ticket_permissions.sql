-- Permissões granulares do módulo de tickets.
-- Não usa suporte genérico. Operadores internos devem usar ticket_support
-- ou support_ticket_manager, com ações sensíveis liberadas por permissão explícita.

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Tickets - Permissão', 'ticket.view_own'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = 'ticket.view_own');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Tickets - Permissão', 'ticket.view_all'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = 'ticket.view_all');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Tickets - Permissão', 'ticket.create'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = 'ticket.create');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Tickets - Permissão', 'ticket.reply'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = 'ticket.reply');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Tickets - Permissão', 'ticket.update'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = 'ticket.update');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Tickets - Permissão', 'ticket.change_status'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = 'ticket.change_status');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Tickets - Permissão', 'ticket.assign'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = 'ticket.assign');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Tickets - Permissão', 'ticket.change_priority'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = 'ticket.change_priority');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Tickets - Permissão', 'ticket.delete'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = 'ticket.delete');

CREATE TABLE IF NOT EXISTS support_ticket_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    tenancy_id VARCHAR(64) NOT NULL,
    action VARCHAR(64) NOT NULL,
    field VARCHAR(64) NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    ip_address VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_support_ticket_audit_ticket (ticket_id, created_at),
    KEY idx_support_ticket_audit_user (user_id, created_at),
    KEY idx_support_ticket_audit_tenancy (tenancy_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dá acesso de navegação ao módulo para os papéis específicos de tickets.
-- A autorização fina continua no backend por ticket.*.
INSERT INTO sys_role_permissions (tenancy_id, role_id, route_id)
SELECT r.tenancy_id, r.id, sr.id
FROM sys_roles r
INNER JOIN sys_routes sr ON sr.route_path IN (
    '/support',
    '/support/tickets',
    '/support/tickets/create',
    '/support/tickets/{id}/messages',
    '/support/tickets/{id}/messages/create',
    '/support/tickets/{id}/status',
    'ticket.view_all',
    'ticket.reply'
)
WHERE r.name IN ('ticket_support', 'support_ticket_manager')
  AND NOT EXISTS (
      SELECT 1
      FROM sys_role_permissions existing
      WHERE existing.role_id = r.id
        AND existing.route_id = sr.id
  );
