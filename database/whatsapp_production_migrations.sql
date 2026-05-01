-- Migration unica para preparar o modulo WhatsApp antes do deploy.
-- Ordem obrigatoria: ownership -> onboarding_status -> safety -> billing.

ALTER TABLE whatsapp_numbers
    ADD COLUMN IF NOT EXISTS owner_type ENUM('admin', 'client', 'reseller') NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS owner_id INT UNSIGNED NULL AFTER owner_type,
    ADD COLUMN IF NOT EXISTS requested_by_user_id INT UNSIGNED NULL AFTER owner_id,
    ADD COLUMN IF NOT EXISTS active_owner_key VARCHAR(64)
        GENERATED ALWAYS AS (
            CASE
                WHEN status = 'active' AND owner_type IS NOT NULL AND owner_id IS NOT NULL
                THEN CONCAT(owner_type, ':', owner_id)
                ELSE NULL
            END
        ) STORED AFTER requested_by_user_id,
    ADD UNIQUE KEY IF NOT EXISTS uq_whatsapp_numbers_active_owner (active_owner_key),
    ADD KEY IF NOT EXISTS idx_whatsapp_numbers_owner (owner_type, owner_id),
    ADD KEY IF NOT EXISTS idx_whatsapp_numbers_requested_by (requested_by_user_id);

ALTER TABLE whatsapp_numbers
    MODIFY status ENUM(
        'available',
        'pending',
        'code_sent',
        'failed',
        'pending_verification',
        'pending_name_approval',
        'active',
        'blocked',
        'removing',
        'removed'
    ) NOT NULL DEFAULT 'pending_verification',
    ADD COLUMN IF NOT EXISTS internal_label VARCHAR(160) NULL AFTER phone_number,
    ADD COLUMN IF NOT EXISTS display_name_meta VARCHAR(128) NULL AFTER internal_label,
    ADD COLUMN IF NOT EXISTS display_name_status VARCHAR(32) NULL AFTER verification_method,
    ADD COLUMN IF NOT EXISTS display_name_submitted_at DATETIME NULL AFTER display_name_status,
    ADD COLUMN IF NOT EXISTS display_name_approved_at DATETIME NULL AFTER display_name_submitted_at,
    ADD COLUMN IF NOT EXISTS display_name_approval_seconds INT UNSIGNED NULL AFTER display_name_approved_at,
    ADD COLUMN IF NOT EXISTS display_name_last_checked_at DATETIME NULL AFTER display_name_approval_seconds,
    ADD COLUMN IF NOT EXISTS display_name_rejected_at DATETIME NULL AFTER display_name_last_checked_at,
    ADD COLUMN IF NOT EXISTS otp_confirmed_at DATETIME NULL AFTER display_name_rejected_at,
    ADD COLUMN IF NOT EXISTS connected_at DATETIME NULL AFTER otp_confirmed_at,
    ADD COLUMN IF NOT EXISTS last_meta_error TEXT NULL AFTER connected_at,
    ADD COLUMN IF NOT EXISTS last_meta_error_at DATETIME NULL AFTER last_meta_error;

CREATE TABLE IF NOT EXISTS whatsapp_display_name_approval_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    whatsapp_number_id INT UNSIGNED NOT NULL,
    phone_number_id VARCHAR(64) NOT NULL,
    display_name VARCHAR(160) NULL,
    status VARCHAR(32) NOT NULL,
    approval_seconds INT UNSIGNED NULL,
    payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wdnae_number (whatsapp_number_id, created_at),
    KEY idx_wdnae_phone (phone_number_id, created_at),
    KEY idx_wdnae_status (status, created_at),
    CONSTRAINT fk_wdnae_number
        FOREIGN KEY (whatsapp_number_id) REFERENCES whatsapp_numbers(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE number_requests
    ADD COLUMN IF NOT EXISTS internal_label VARCHAR(160) NULL AFTER phone_number,
    ADD COLUMN IF NOT EXISTS display_name_meta VARCHAR(128) NULL AFTER internal_label;

ALTER TABLE whatsapp_numbers
    ADD COLUMN IF NOT EXISTS quality_status ENUM('high', 'medium', 'low', 'unknown') NOT NULL DEFAULT 'unknown' AFTER status,
    ADD COLUMN IF NOT EXISTS current_daily_limit INT UNSIGNED NOT NULL DEFAULT 50 AFTER quality_status,
    ADD COLUMN IF NOT EXISTS send_blocked_until DATETIME NULL AFTER current_daily_limit,
    ADD COLUMN IF NOT EXISTS recommendation VARCHAR(255) NULL AFTER send_blocked_until;

CREATE TABLE IF NOT EXISTS whatsapp_number_health (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id INT UNSIGNED NOT NULL,
    phone_number_id VARCHAR(64) NOT NULL,
    tenancy_id VARCHAR(64) NOT NULL,
    quality_status ENUM('high', 'medium', 'low', 'unknown') NOT NULL DEFAULT 'unknown',
    meta_quality_rating VARCHAR(32) NULL,
    messaging_limit_tier VARCHAR(64) NULL,
    current_daily_limit INT UNSIGNED NOT NULL DEFAULT 50,
    sent_today INT UNSIGNED NOT NULL DEFAULT 0,
    sent_last_minute INT UNSIGNED NOT NULL DEFAULT 0,
    sent_last_second INT UNSIGNED NOT NULL DEFAULT 0,
    blocked_until DATETIME NULL,
    recommendation VARCHAR(255) NULL,
    last_meta_sync_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wnh_account (account_id),
    UNIQUE KEY uq_wnh_phone_number_id (phone_number_id),
    KEY idx_wnh_tenancy (tenancy_id),
    KEY idx_wnh_quality (quality_status),
    CONSTRAINT fk_wnh_account
        FOREIGN KEY (account_id) REFERENCES whatsapp_accounts(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_number_quality_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id INT UNSIGNED NULL,
    phone_number_id VARCHAR(64) NOT NULL,
    quality_status ENUM('high', 'medium', 'low', 'unknown') NOT NULL DEFAULT 'unknown',
    meta_quality_rating VARCHAR(32) NULL,
    source ENUM('api', 'webhook', 'worker') NOT NULL DEFAULT 'api',
    payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wnqe_phone (phone_number_id, created_at),
    KEY idx_wnqe_account (account_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plan_whatsapp_pricing (
    plan_id INT UNSIGNED NOT NULL,
    category ENUM('marketing', 'utility') NOT NULL,
    price_brl DECIMAL(10, 4) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (plan_id, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO plan_whatsapp_pricing (plan_id, category, price_brl, created_at, updated_at)
SELECT DISTINCT plan_id, 'marketing', 0.3500, NOW(), NOW()
FROM tenancy_balance
WHERE plan_id IS NOT NULL
ON DUPLICATE KEY UPDATE price_brl = price_brl;

INSERT INTO plan_whatsapp_pricing (plan_id, category, price_brl, created_at, updated_at)
SELECT DISTINCT plan_id, 'utility', 0.0400, NOW(), NOW()
FROM tenancy_balance
WHERE plan_id IS NOT NULL
ON DUPLICATE KEY UPDATE price_brl = price_brl;

ALTER TABLE whatsapp_messages
    ADD COLUMN IF NOT EXISTS message_category ENUM('marketing', 'utility', 'service') NULL AFTER service_window_open,
    ADD COLUMN IF NOT EXISTS price_brl DECIMAL(10, 4) NOT NULL DEFAULT 0 AFTER message_category,
    ADD COLUMN IF NOT EXISTS billed TINYINT(1) NOT NULL DEFAULT 0 AFTER price_brl,
    ADD INDEX IF NOT EXISTS idx_whatsapp_messages_billing (message_category, billed, created_at);

ALTER TABLE whatsapp_outbox
    ADD COLUMN IF NOT EXISTS message_category ENUM('marketing', 'utility', 'service') NOT NULL DEFAULT 'service' AFTER service_window_open,
    ADD COLUMN IF NOT EXISTS price_brl DECIMAL(10, 4) NOT NULL DEFAULT 0 AFTER message_category,
    ADD COLUMN IF NOT EXISTS billed TINYINT(1) NOT NULL DEFAULT 0 AFTER price_brl,
    ADD INDEX IF NOT EXISTS idx_whatsapp_outbox_billing (message_category, billed, status);

CREATE TABLE IF NOT EXISTS whatsapp_message_cdr (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    tenancy_id VARCHAR(64) NOT NULL,
    type VARCHAR(32) NOT NULL DEFAULT 'whatsapp',
    phone_number VARCHAR(32) NOT NULL,
    message_category ENUM('marketing', 'utility', 'service') NOT NULL,
    template_name VARCHAR(160) NULL,
    direction ENUM('outbound', 'inbound') NOT NULL,
    price_brl DECIMAL(10, 4) NOT NULL DEFAULT 0,
    billed TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('sent', 'failed', 'blocked') NOT NULL,
    whatsapp_outbox_id BIGINT UNSIGNED NULL,
    whatsapp_message_id INT UNSIGNED NULL,
    wamid VARCHAR(255) NULL,
    error_message TEXT NULL,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_whatsapp_cdr_client (client_id, timestamp),
    KEY idx_whatsapp_cdr_tenancy (tenancy_id, timestamp),
    KEY idx_whatsapp_cdr_status (status, timestamp),
    KEY idx_whatsapp_cdr_category (message_category, billed, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE whatsapp_templates
    ADD COLUMN IF NOT EXISTS account_id INT UNSIGNED NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS waba_id VARCHAR(64) NULL AFTER account_id,
    ADD COLUMN IF NOT EXISTS meta_template_id VARCHAR(64) NULL AFTER waba_id,
    ADD COLUMN IF NOT EXISTS variable_map JSON NULL AFTER components,
    ADD COLUMN IF NOT EXISTS is_system_template TINYINT(1) NOT NULL DEFAULT 0 AFTER variable_map,
    ADD COLUMN IF NOT EXISTS template_type ENUM('system', 'tenant') NOT NULL DEFAULT 'tenant' AFTER is_system_template,
    MODIFY status ENUM('draft', 'pending', 'approved', 'rejected', 'paused', 'disabled') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS template_submitted_at DATETIME NULL AFTER status,
    ADD COLUMN IF NOT EXISTS template_approved_at DATETIME NULL AFTER template_submitted_at,
    ADD COLUMN IF NOT EXISTS template_rejected_at DATETIME NULL AFTER template_approved_at,
    ADD COLUMN IF NOT EXISTS template_last_sync_at DATETIME NULL AFTER template_rejected_at,
    ADD COLUMN IF NOT EXISTS template_last_error TEXT NULL AFTER template_last_sync_at,
    ADD COLUMN IF NOT EXISTS meta_payload JSON NULL AFTER template_last_error,
    ADD INDEX IF NOT EXISTS idx_whatsapp_templates_type (template_type, is_system_template),
    ADD INDEX IF NOT EXISTS idx_whatsapp_templates_account (account_id),
    ADD INDEX IF NOT EXISTS idx_whatsapp_templates_meta (waba_id, meta_template_id);

CREATE TABLE IF NOT EXISTS whatsapp_template_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    tenancy_id VARCHAR(64) NOT NULL,
    action VARCHAR(32) NOT NULL,
    template_type ENUM('system', 'tenant') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_whatsapp_template_audit_template (template_id, created_at),
    KEY idx_whatsapp_template_audit_user (user_id, created_at),
    KEY idx_whatsapp_template_audit_tenancy (tenancy_id, created_at),
    KEY idx_whatsapp_template_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE whatsapp_accounts
    ADD COLUMN IF NOT EXISTS profile_picture_url TEXT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS profile_picture_handle VARCHAR(255) NULL AFTER profile_picture_url,
    ADD COLUMN IF NOT EXISTS profile_about VARCHAR(139) NULL AFTER profile_picture_handle,
    ADD COLUMN IF NOT EXISTS profile_description TEXT NULL AFTER profile_about,
    ADD COLUMN IF NOT EXISTS profile_email VARCHAR(160) NULL AFTER profile_description,
    ADD COLUMN IF NOT EXISTS profile_website VARCHAR(520) NULL AFTER profile_email,
    ADD COLUMN IF NOT EXISTS profile_address VARCHAR(255) NULL AFTER profile_website,
    ADD COLUMN IF NOT EXISTS profile_vertical VARCHAR(64) NULL AFTER profile_address,
    ADD COLUMN IF NOT EXISTS profile_updated_at DATETIME NULL AFTER profile_vertical,
    ADD COLUMN IF NOT EXISTS profile_last_error TEXT NULL AFTER profile_updated_at;

INSERT INTO whatsapp_templates
    (tenancy_id, user_id, name, language, category, body, components, is_system_template, template_type, status, created_at, updated_at)
SELECT tb.tenancy_id, tb.user_id, seed.name, 'pt_BR', seed.category, seed.body, seed.components, 1, 'system', 'draft', NOW(), NOW()
FROM (
    SELECT 'confirmacao_atendimento' AS name, 'UTILITY' AS category,
           'Olá {{1}}, seu atendimento foi registrado com o protocolo {{2}}.' AS body,
           JSON_ARRAY(JSON_OBJECT('type','BODY','text','Olá {{1}}, seu atendimento foi registrado com o protocolo {{2}}.')) AS components
    UNION ALL SELECT 'aviso_protocolo', 'UTILITY', 'Seu protocolo {{1}} está em andamento. Retornaremos em breve.',
           JSON_ARRAY(JSON_OBJECT('type','BODY','text','Seu protocolo {{1}} está em andamento. Retornaremos em breve.'))
    UNION ALL SELECT 'lembrete_agendamento', 'UTILITY', 'Olá {{1}}, lembramos do seu agendamento em {{2}} às {{3}}.',
           JSON_ARRAY(JSON_OBJECT('type','BODY','text','Olá {{1}}, lembramos do seu agendamento em {{2}} às {{3}}.'))
    UNION ALL SELECT 'confirmacao_pagamento', 'UTILITY', 'Olá {{1}}, confirmamos o pagamento referente a {{2}}.',
           JSON_ARRAY(JSON_OBJECT('type','BODY','text','Olá {{1}}, confirmamos o pagamento referente a {{2}}.'))
    UNION ALL SELECT 'campanha_promocional', 'MARKETING', 'Olá {{1}}, temos uma oferta especial para você: {{2}}.',
           JSON_ARRAY(JSON_OBJECT('type','BODY','text','Olá {{1}}, temos uma oferta especial para você: {{2}}.'))
    UNION ALL SELECT 'reativacao_cliente', 'MARKETING', 'Olá {{1}}, sentimos sua falta. Veja esta novidade: {{2}}.',
           JSON_ARRAY(JSON_OBJECT('type','BODY','text','Olá {{1}}, sentimos sua falta. Veja esta novidade: {{2}}.'))
    UNION ALL SELECT 'campanha_informativa', 'MARKETING', 'Olá {{1}}, temos uma informação importante sobre {{2}}.',
           JSON_ARRAY(JSON_OBJECT('type','BODY','text','Olá {{1}}, temos uma informação importante sobre {{2}}.'))
    UNION ALL SELECT 'codigo_verificacao', 'AUTHENTICATION', 'Seu código de verificação é {{1}}.',
           JSON_ARRAY(JSON_OBJECT('type','BODY','text','Seu código de verificação é {{1}}.'))
) seed
INNER JOIN (
    SELECT tenancy_id, MIN(user_id) AS user_id
    FROM whatsapp_accounts
    GROUP BY tenancy_id
) tb ON 1 = 1
WHERE NOT EXISTS (
    SELECT 1 FROM whatsapp_templates wt
    WHERE wt.tenancy_id = tb.tenancy_id
      AND wt.name = seed.name
      AND wt.language = 'pt_BR'
);

UPDATE whatsapp_templates
SET is_system_template = 1,
    template_type = 'system',
    updated_at = NOW()
WHERE name IN (
    'boas_vindas_padrao',
    'confirmacao_atendimento',
    'aviso_protocolo',
    'lembrete_agendamento',
    'confirmacao_pagamento',
    'campanha_promocional',
    'reativacao_cliente',
    'campanha_informativa',
    'codigo_verificacao'
)
  AND language = 'pt_BR';

INSERT INTO whatsapp_templates
    (tenancy_id, user_id, account_id, waba_id, name, language, category, body, components, variable_map, is_system_template, template_type, status, meta_payload, created_at, updated_at)
SELECT tb.tenancy_id, tb.user_id, tb.account_id, tb.waba_id, seed.name, 'pt_BR', seed.category, seed.body, seed.components, seed.variable_map, 1, 'system', 'draft', seed.meta_payload, NOW(), NOW()
FROM (
    SELECT 'pedido_confirmado_padrao' AS name, 'UTILITY' AS category,
           'Olá {{1}}, seu pedido {{2}} foi registrado em nosso sistema. Esta é uma confirmação automática de recebimento.' AS body,
           JSON_ARRAY(JSON_OBJECT('type','BODY','text','Olá {{1}}, seu pedido {{2}} foi registrado em nosso sistema. Esta é uma confirmação automática de recebimento.','example',JSON_OBJECT('body_text',JSON_ARRAY(JSON_ARRAY('Maria','PED-12345'))))) AS components,
           JSON_OBJECT('1',JSON_OBJECT('key','nome_cliente','description','Nome do cliente','example','Maria'),'2',JSON_OBJECT('key','numero_pedido','description','Identificador do pedido','example','PED-12345')) AS variable_map,
           JSON_OBJECT('system_default_template',true,'approval_note','Confirmacao transacional de pedido ja registrado, sem chamada comercial.') AS meta_payload
    UNION ALL SELECT 'atualizacao_pedido_padrao', 'UTILITY',
           'Olá {{1}}, o status do pedido {{2}} foi atualizado para: {{3}}. Esta é uma notificação automática de acompanhamento.',
           JSON_ARRAY(JSON_OBJECT('type','BODY','text','Olá {{1}}, o status do pedido {{2}} foi atualizado para: {{3}}. Esta é uma notificação automática de acompanhamento.','example',JSON_OBJECT('body_text',JSON_ARRAY(JSON_ARRAY('Maria','PED-12345','em separação'))))),
           JSON_OBJECT('1',JSON_OBJECT('key','nome_cliente','description','Nome do cliente','example','Maria'),'2',JSON_OBJECT('key','numero_pedido','description','Identificador do pedido','example','PED-12345'),'3',JSON_OBJECT('key','status_pedido','description','Novo status do pedido','example','em separação')),
           JSON_OBJECT('system_default_template',true,'approval_note','Atualizacao operacional de status de pedido existente.')
    UNION ALL SELECT 'boleto_disponivel_padrao', 'UTILITY',
           'Olá {{1}}, o boleto referente à cobrança {{2}} está disponível. Consulte o documento no painel do cliente ou no canal oficial de atendimento.',
           JSON_ARRAY(JSON_OBJECT('type','BODY','text','Olá {{1}}, o boleto referente à cobrança {{2}} está disponível. Consulte o documento no painel do cliente ou no canal oficial de atendimento.','example',JSON_OBJECT('body_text',JSON_ARRAY(JSON_ARRAY('Maria','COB-98765'))))),
           JSON_OBJECT('1',JSON_OBJECT('key','nome_cliente','description','Nome do cliente','example','Maria'),'2',JSON_OBJECT('key','cobranca_id','description','Identificador da cobrança','example','COB-98765')),
           JSON_OBJECT('system_default_template',true,'approval_note','Aviso financeiro transacional sobre documento de cobranca existente.')
    UNION ALL SELECT 'lembrete_pagamento_padrao', 'UTILITY',
           'Olá {{1}}, identificamos uma cobrança pendente {{2}} com vencimento em {{3}}. Esta é uma notificação automática.',
           JSON_ARRAY(JSON_OBJECT('type','BODY','text','Olá {{1}}, identificamos uma cobrança pendente {{2}} com vencimento em {{3}}. Esta é uma notificação automática.','example',JSON_OBJECT('body_text',JSON_ARRAY(JSON_ARRAY('Maria','COB-98765','10/05/2026'))))),
           JSON_OBJECT('1',JSON_OBJECT('key','nome_cliente','description','Nome do cliente','example','Maria'),'2',JSON_OBJECT('key','cobranca_id','description','Identificador da cobrança','example','COB-98765'),'3',JSON_OBJECT('key','data_vencimento','description','Data de vencimento da cobrança','example','10/05/2026')),
           JSON_OBJECT('system_default_template',true,'approval_note','Lembrete factual de pagamento pendente, sem urgencia artificial ou incentivo comercial.')
    UNION ALL SELECT 'notificacao_sistema_padrao', 'UTILITY',
           'Olá {{1}}, há uma atualização relacionada ao registro {{2}}. Consulte os detalhes no painel do cliente ou no canal oficial de atendimento.',
           JSON_ARRAY(JSON_OBJECT('type','BODY','text','Olá {{1}}, há uma atualização relacionada ao registro {{2}}. Consulte os detalhes no painel do cliente ou no canal oficial de atendimento.','example',JSON_OBJECT('body_text',JSON_ARRAY(JSON_ARRAY('Maria','ATD-45678'))))),
           JSON_OBJECT('1',JSON_OBJECT('key','nome_cliente','description','Nome do cliente','example','Maria'),'2',JSON_OBJECT('key','referencia_id','description','Identificador do registro, atendimento, cadastro, pedido ou solicitação','example','ATD-45678')),
           JSON_OBJECT('system_default_template',true,'approval_note','Template coringa com referencia transacional identificavel.')
    UNION ALL SELECT 'codigo_verificacao_padrao', 'AUTHENTICATION',
           'Seu código de verificação é {{1}}. Use este código para concluir a autenticação. Não compartilhe este código com outras pessoas.',
           JSON_ARRAY(JSON_OBJECT('type','BODY','text','Seu código de verificação é {{1}}. Use este código para concluir a autenticação. Não compartilhe este código com outras pessoas.','example',JSON_OBJECT('body_text',JSON_ARRAY(JSON_ARRAY('123456')))),JSON_OBJECT('type','BUTTONS','buttons',JSON_ARRAY(JSON_OBJECT('type','OTP','otp_type','COPY_CODE','text','Copiar código')))),
           JSON_OBJECT('1',JSON_OBJECT('key','codigo_verificacao','description','Código temporário de autenticação','example','123456')),
           JSON_OBJECT('system_default_template',true,'approval_note','Template de autenticacao com OTP e orientacao de seguranca.')
) seed
INNER JOIN (
    SELECT tenancy_id, MIN(user_id) AS user_id, MIN(id) AS account_id, MIN(waba_id) AS waba_id
    FROM whatsapp_accounts
    GROUP BY tenancy_id
) tb ON 1 = 1
WHERE NOT EXISTS (
    SELECT 1 FROM whatsapp_templates wt
    WHERE wt.tenancy_id = tb.tenancy_id
      AND wt.name = seed.name
      AND wt.language = 'pt_BR'
);
