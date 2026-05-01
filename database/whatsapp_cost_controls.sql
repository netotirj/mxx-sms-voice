ALTER TABLE whatsapp_campaigns
    ADD COLUMN IF NOT EXISTS template_category ENUM('MARKETING', 'UTILITY', 'AUTHENTICATION') NULL AFTER template_language,
    ADD COLUMN IF NOT EXISTS scheduled_at DATETIME NULL AFTER template_components,
    ADD INDEX IF NOT EXISTS idx_whatsapp_campaigns_scheduled (status, scheduled_at);

ALTER TABLE whatsapp_messages
    ADD COLUMN IF NOT EXISTS template_name VARCHAR(160) NULL AFTER message_type,
    ADD COLUMN IF NOT EXISTS template_category ENUM('MARKETING', 'UTILITY', 'AUTHENTICATION') NULL AFTER template_name,
    ADD COLUMN IF NOT EXISTS service_window_open TINYINT(1) NOT NULL DEFAULT 0 AFTER template_category,
    ADD COLUMN IF NOT EXISTS message_category ENUM('marketing', 'utility', 'service') NULL AFTER service_window_open,
    ADD COLUMN IF NOT EXISTS price_brl DECIMAL(10, 4) NOT NULL DEFAULT 0 AFTER message_category,
    ADD COLUMN IF NOT EXISTS billed TINYINT(1) NOT NULL DEFAULT 0 AFTER price_brl,
    ADD INDEX IF NOT EXISTS idx_whatsapp_messages_billing (message_category, billed, created_at);

CREATE TABLE IF NOT EXISTS whatsapp_marketing_opt_outs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id INT UNSIGNED NOT NULL,
    contact_phone VARCHAR(32) NOT NULL,
    source_wamid VARCHAR(255) NULL,
    reason VARCHAR(64) NOT NULL DEFAULT 'keyword',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_opt_out_account_phone (account_id, contact_phone),
    KEY idx_whatsapp_opt_out_phone (contact_phone),
    CONSTRAINT fk_whatsapp_opt_out_account
        FOREIGN KEY (account_id) REFERENCES whatsapp_accounts(id)
        ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS whatsapp_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenancy_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    campaign_id INT UNSIGNED NULL,
    campaign_recipient_id INT UNSIGNED NULL,
    conversation_id INT UNSIGNED NULL,
    message_id INT UNSIGNED NULL,
    contact_phone VARCHAR(32) NOT NULL,
    contact_name VARCHAR(160) NULL,
    sequence SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    message_type ENUM('text', 'template') NOT NULL,
    body TEXT NULL,
    template_name VARCHAR(160) NULL,
    template_language VARCHAR(16) NULL,
    template_category ENUM('MARKETING', 'UTILITY', 'AUTHENTICATION') NULL,
    template_components JSON NULL,
    service_window_open TINYINT(1) NOT NULL DEFAULT 0,
    message_category ENUM('marketing', 'utility', 'service') NOT NULL,
    price_brl DECIMAL(10, 4) NOT NULL DEFAULT 0,
    billed TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('queued', 'sending', 'sent', 'failed', 'cancelled') NOT NULL DEFAULT 'queued',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    wamid VARCHAR(255) NULL,
    error_message TEXT NULL,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_whatsapp_outbox_due (status, available_at),
    KEY idx_whatsapp_outbox_campaign (campaign_id, template_category, status),
    KEY idx_whatsapp_outbox_account (account_id, status),
    KEY idx_whatsapp_outbox_billing (message_category, billed, status),
    KEY idx_whatsapp_outbox_phone (contact_phone),
    CONSTRAINT fk_whatsapp_outbox_account
        FOREIGN KEY (account_id) REFERENCES whatsapp_accounts(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_whatsapp_outbox_campaign
        FOREIGN KEY (campaign_id) REFERENCES whatsapp_campaigns(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_whatsapp_outbox_recipient
        FOREIGN KEY (campaign_recipient_id) REFERENCES whatsapp_campaign_recipients(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_whatsapp_outbox_conversation
        FOREIGN KEY (conversation_id) REFERENCES whatsapp_conversations(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_whatsapp_outbox_message
        FOREIGN KEY (message_id) REFERENCES whatsapp_messages(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
