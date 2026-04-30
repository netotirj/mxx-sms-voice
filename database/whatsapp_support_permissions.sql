-- Catálogo de permissões granulares para WhatsApp e Suporte.
-- O bloco final libera as rotas de WhatsApp para os papéis que já têm acesso
-- ao módulo base /campaign/whatsapp.

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Visualização', '/campaign/whatsapp'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Visualização', '/campaign/whatsapp/accounts'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/accounts');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Visualização', '/campaign/whatsapp/numbers'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/numbers');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Visualização', '/campaign/whatsapp/numbers/health'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/numbers/health');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Visualização', '/campaign/whatsapp/campaigns'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/campaigns');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Visualização', '/campaign/whatsapp/templates'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/templates');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Visualização', '/campaign/whatsapp/conversations'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/conversations');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Visualização', '/campaign/whatsapp/conversations/{id}/messages'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/conversations/{id}/messages');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Configuração', '/campaign/whatsapp/accounts/create'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/accounts/create');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Configuração', '/campaign/whatsapp/accounts/{id}/test'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/accounts/{id}/test');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Números', '/campaign/whatsapp/numbers/health/sync'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/numbers/health/sync');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Números', '/campaign/whatsapp/numbers/client'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/numbers/client');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Números', '/campaign/whatsapp/number-requests'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/number-requests');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Números', '/campaign/whatsapp/number-requests/send-meta'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/number-requests/send-meta');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Números', '/campaign/whatsapp/number-requests/approve'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/number-requests/approve');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Números', '/campaign/whatsapp/number-requests/reject'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/number-requests/reject');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Números', '/campaign/whatsapp/number-requests/resend-code'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/number-requests/resend-code');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Números', '/campaign/whatsapp/number-requests/confirm-code'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/number-requests/confirm-code');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Números', '/campaign/whatsapp/numbers/platform'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/numbers/platform');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Números', '/campaign/whatsapp/numbers/platform/{id}/assign'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/numbers/platform/{id}/assign');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Números', '/campaign/whatsapp/numbers/{id}/send-code'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/numbers/{id}/send-code');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Números', '/campaign/whatsapp/numbers/{id}/confirm-code'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/numbers/{id}/confirm-code');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Números', '/campaign/whatsapp/numbers/{id}/remove'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/numbers/{id}/remove');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Templates', '/campaign/whatsapp/templates/create'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/templates/create');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Templates', '/campaign/whatsapp/templates/{id}/delete'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/templates/{id}/delete');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Campanhas', '/campaign/whatsapp/campaigns/create'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/campaigns/create');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Campanhas', '/campaign/whatsapp/campaigns/{id}/send'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/campaigns/{id}/send');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Campanhas', '/campaign/whatsapp/campaigns/{id}/cancel-category'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/campaigns/{id}/cancel-category');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Atendimento', '/campaign/whatsapp/messages/send'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/messages/send');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Atendimento', '/campaign/whatsapp/conversations/{id}/read'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/conversations/{id}/read');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Atendimento', '/campaign/whatsapp/conversations/{id}/unread'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/conversations/{id}/unread');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Atendimento', '/campaign/whatsapp/conversations/{id}/delete'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/conversations/{id}/delete');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Suporte Central', '/campaign/whatsapp/support/send'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/support/send');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Suporte Central', '/campaign/whatsapp/support/queues'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/support/queues');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Suporte Central', '/campaign/whatsapp/support/queues/create'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/support/queues/create');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Suporte Central', '/campaign/whatsapp/support/queues/{id}/agents'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/support/queues/{id}/agents');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Suporte Central', '/campaign/whatsapp/support/agents/status'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/support/agents/status');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Suporte Central', '/campaign/whatsapp/support/dashboard'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/support/dashboard');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Suporte Central', '/campaign/whatsapp/support/events'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/support/events');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Suporte Central', '/campaign/whatsapp/support/sessions/{id}/finish'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/support/sessions/{id}/finish');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Suporte Central', '/campaign/whatsapp/support/sessions/{id}/transfer'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/support/sessions/{id}/transfer');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Suporte - Visualização', '/support'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/support');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Suporte - Visualização', '/support/tickets'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/support/tickets');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Suporte - Diagnóstico', '/support/diagnostics'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/support/diagnostics');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Suporte - Atendimento', '/support/tickets/create'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/support/tickets/create');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Suporte - Atendimento', '/support/tickets/{id}/messages'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/support/tickets/{id}/messages');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Suporte - Atendimento', '/support/tickets/{id}/messages/create'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/support/tickets/{id}/messages/create');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'Suporte - Atendimento', '/support/tickets/{id}/status'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/support/tickets/{id}/status');

INSERT IGNORE INTO sys_role_permissions (tenancy_id, role_id, route_id)
SELECT DISTINCT base_perm.tenancy_id, base_perm.role_id, whatsapp_routes.id
FROM sys_role_permissions base_perm
INNER JOIN sys_routes base_route
    ON base_route.id = base_perm.route_id
    AND base_route.route_path = '/campaign/whatsapp'
INNER JOIN sys_routes whatsapp_routes
    ON whatsapp_routes.route_path LIKE '/campaign/whatsapp%';
