-- Plano de conferência e migração segura do módulo de Planos / Runtime
-- Não executa DELETE em massa.
-- Faça backup antes de qualquer alteração estrutural.

-- 1) Conferência de duplicidade de slug
SELECT slug, COUNT(*) AS total
FROM mxx_plans
WHERE slug IS NOT NULL AND slug <> ''
GROUP BY slug
HAVING COUNT(*) > 1;

-- 2) Conferência de mais de uma assinatura ativa por tenancy
SELECT tenancy_id, COUNT(*) AS total
FROM mxx_user_plans
WHERE status = 'active' AND status_payment = 'confirmed'
GROUP BY tenancy_id
HAVING COUNT(*) > 1;

-- 3) Conferência de divergência entre plano ativo e snapshot aplicado
SELECT
    t.id AS tenancy_id,
    t.active_plan_id,
    p.name_plan,
    p.amount_plan,
    p.service_fee,
    b.applied_amount_plan,
    b.service_fee AS applied_service_fee,
    b.applied_plan_name,
    b.applied_billing_cycle,
    b.updated_at
FROM tenancies t
LEFT JOIN mxx_plans p ON p.id = t.active_plan_id
LEFT JOIN tenancy_balance b ON b.tenancy_id = t.id AND b.plan_id = t.active_plan_id
WHERE t.active_plan_id IS NOT NULL;

-- 4) Backups sugeridos
-- CREATE TABLE backup_mxx_plans_20260507 AS SELECT * FROM mxx_plans;
-- CREATE TABLE backup_mxx_user_plans_20260507 AS SELECT * FROM mxx_user_plans;
-- CREATE TABLE backup_tenancy_balance_20260507 AS SELECT * FROM tenancy_balance;

-- 5) Estrutura mínima do catálogo de planos
-- Observação: NÃO altere mxx_plans.id para AUTO_INCREMENT se a tabela já estiver
-- referenciada por foreign keys. O módulo novo já calcula o próximo ID manualmente.
ALTER TABLE mxx_plans
    ADD COLUMN IF NOT EXISTS slug VARCHAR(120) NULL AFTER name_plan,
    ADD COLUMN IF NOT EXISTS billing_cycle VARCHAR(30) NOT NULL DEFAULT 'monthly' AFTER amount_plan,
    ADD COLUMN IF NOT EXISTS users_limit INT NOT NULL DEFAULT 0 AFTER users_create,
    ADD COLUMN IF NOT EXISTS sms_limit INT NOT NULL DEFAULT 0 AFTER users_limit,
    ADD COLUMN IF NOT EXISTS voice_limit INT NOT NULL DEFAULT 0 AFTER sms_limit,
    ADD COLUMN IF NOT EXISTS templates_limit INT NOT NULL DEFAULT 0 AFTER whatsapp_accounts,
    ADD COLUMN IF NOT EXISTS webrtc_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER templates_limit,
    ADD COLUMN IF NOT EXISTS modules_json LONGTEXT NULL AFTER webrtc_enabled,
    ADD COLUMN IF NOT EXISTS internal_notes TEXT NULL AFTER modules_json,
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER internal_notes,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER deleted_at,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- 6) Estrutura mínima do snapshot financeiro aplicado
ALTER TABLE tenancy_balance
    ADD COLUMN IF NOT EXISTS snapshot_json LONGTEXT NULL AFTER service_fee,
    ADD COLUMN IF NOT EXISTS applied_plan_name VARCHAR(255) NULL AFTER snapshot_json,
    ADD COLUMN IF NOT EXISTS applied_billing_cycle VARCHAR(30) NULL AFTER applied_plan_name,
    ADD COLUMN IF NOT EXISTS applied_amount_plan DECIMAL(10,2) NULL AFTER applied_billing_cycle;

-- 7) Índices recomendados
ALTER TABLE mxx_plans
    ADD UNIQUE KEY unq_mxx_plans_slug (slug);

ALTER TABLE sys_routes
    ADD UNIQUE KEY unq_sys_routes_path (route_path);

-- 8) Cadastro seguro das rotas do CRUD administrativo
INSERT IGNORE INTO sys_routes (module_name, route_path) VALUES
    ('Administrativo: Planos', '/plans'),
    ('Administrativo: Planos', '/plans/search'),
    ('Administrativo: Planos', '/plans/save'),
    ('Administrativo: Planos', '/plans/{id}'),
    ('Administrativo: Planos', '/plans/{id}/status'),
    ('Administrativo: Planos', '/plans/{id}/delete');
