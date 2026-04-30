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
