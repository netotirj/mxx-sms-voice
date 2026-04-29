-- Catálogo de permissões granulares para WhatsApp e Suporte.
-- Este script apenas cadastra as rotas em sys_routes; ele não libera acesso para papéis.

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Visualização', '/campaign/whatsapp'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp');

INSERT INTO sys_routes (module_name, route_path)
SELECT 'WhatsApp - Visualização', '/campaign/whatsapp/accounts'
WHERE NOT EXISTS (SELECT 1 FROM sys_routes WHERE route_path = '/campaign/whatsapp/accounts');

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
