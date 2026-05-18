SELECT
    p.id AS plan_id,
    p.name_plan,
    p.slug,
    p.status,
    p.modules_json
FROM mxx_plans p
WHERE p.deleted_at IS NULL
ORDER BY p.id;


SELECT
    t.id AS tenancy_id,
    t.name AS tenancy_name,
    t.active_plan_id,
    p.name_plan,
    p.slug,
    p.modules_json
FROM tenancies t
LEFT JOIN mxx_plans p ON p.id = t.active_plan_id
ORDER BY t.name;


SELECT
    sr.module_name,
    COUNT(*) AS total_routes
FROM sys_routes sr
GROUP BY sr.module_name
ORDER BY sr.module_name;


SELECT
    sr.id,
    sr.module_name,
    sr.route_path,
    sr.access_scope,
    sr.assignable_by
FROM sys_routes sr
WHERE sr.module_name IN (
    'Dashboard: Geral',
    'SMS: Campanhas',
    'Voz: Geral',
    'Voz: Troncos',
    'Voz: Ramais',
    'Voz: Áudios',
    'Voz: Tarifas',
    'Voz: Monitoramento',
    'Call Center: Operação',
    'Call Center: Filas',
    'Call Center: Pausas',
    'Call Center: Agentes',
    'Call Center: Monitoramento',
    'WhatsApp: Geral',
    'WhatsApp: Atendimento',
    'WhatsApp: Campanhas',
    'WhatsApp: Números',
    'WhatsApp: Proteção',
    'WhatsApp: Tarifação',
    'WhatsApp: Mensagens',
    'Templates: WhatsApp',
    'Administrativo: Suporte',
    'Administrativo: Atualizações',
    'Administrativo: Notificações',
    'Administrativo: Planos',
    'Administrativo: Monitoramento de Serviços',
    'Administrativo: Consumo Global',
    'Financeiro: Custos Globais',
    'Permissões: Gestão',
    'Usuários: Gestão',
    'Tarifas: Gestão',
    'Relatórios: Geral',
    'Relatórios: Call Center',
    'Relatórios: Notificações'
)
ORDER BY sr.module_name, sr.route_path;
