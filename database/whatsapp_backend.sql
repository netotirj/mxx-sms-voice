CREATE TABLE IF NOT EXISTS whatsapp_accounts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenancy_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    waba_id VARCHAR(64) NULL,
    business_id VARCHAR(64) NULL,
    phone_number_id VARCHAR(64) NOT NULL,
    display_phone_number VARCHAR(32) NOT NULL,
    access_token TEXT NOT NULL,
    app_secret VARCHAR(255) NULL,
    verify_token VARCHAR(255) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    profile_picture_url TEXT NULL,
    profile_picture_handle VARCHAR(255) NULL,
    profile_about VARCHAR(139) NULL,
    profile_description TEXT NULL,
    profile_email VARCHAR(160) NULL,
    profile_website VARCHAR(520) NULL,
    profile_address VARCHAR(255) NULL,
    profile_vertical VARCHAR(64) NULL,
    profile_updated_at DATETIME NULL,
    profile_last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_accounts_phone_number_id (phone_number_id),
    KEY idx_whatsapp_accounts_tenancy (tenancy_id),
    KEY idx_whatsapp_accounts_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plan_whatsapp_pricing (
    plan_id INT UNSIGNED NOT NULL,
    category ENUM('marketing', 'utility', 'authentication') NOT NULL,
    price_brl DECIMAL(10, 4) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (plan_id, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_message_cdr (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    tenancy_id VARCHAR(64) NOT NULL,
    type VARCHAR(32) NOT NULL DEFAULT 'whatsapp',
    phone_number VARCHAR(32) NOT NULL,
    message_category ENUM('marketing', 'utility', 'authentication', 'service') NOT NULL,
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

CREATE TABLE IF NOT EXISTS whatsapp_campaigns (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenancy_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    message_type ENUM('text', 'template') NOT NULL DEFAULT 'template',
    message_body TEXT NULL,
    template_name VARCHAR(160) NULL,
    template_language VARCHAR(16) NULL,
    template_category ENUM('MARKETING', 'UTILITY', 'AUTHENTICATION') NULL,
    template_components JSON NULL,
    scheduled_at DATETIME NULL,
    total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('draft', 'queued', 'sending', 'finished', 'failed', 'cancelled') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_whatsapp_campaigns_tenancy (tenancy_id),
    KEY idx_whatsapp_campaigns_user (user_id),
    KEY idx_whatsapp_campaigns_account (account_id),
    KEY idx_whatsapp_campaigns_scheduled (status, scheduled_at),
    CONSTRAINT fk_whatsapp_campaigns_account
        FOREIGN KEY (account_id) REFERENCES whatsapp_accounts(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenancy_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NULL,
    waba_id VARCHAR(64) NULL,
    meta_template_id VARCHAR(64) NULL,
    name VARCHAR(160) NOT NULL,
    language VARCHAR(16) NOT NULL DEFAULT 'pt_BR',
    category ENUM('MARKETING', 'UTILITY', 'AUTHENTICATION') NOT NULL DEFAULT 'MARKETING',
    body TEXT NULL,
    components JSON NULL,
    variable_map JSON NULL,
    is_system_template TINYINT(1) NOT NULL DEFAULT 0,
    template_type ENUM('system', 'tenant') NOT NULL DEFAULT 'tenant',
    status ENUM('draft', 'pending', 'approved', 'rejected', 'paused', 'disabled') NOT NULL DEFAULT 'pending',
    template_submitted_at DATETIME NULL,
    template_approved_at DATETIME NULL,
    template_rejected_at DATETIME NULL,
    template_last_sync_at DATETIME NULL,
    template_last_error TEXT NULL,
    meta_payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_templates_name_language_tenancy (tenancy_id, name, language),
    KEY idx_whatsapp_templates_tenancy (tenancy_id),
    KEY idx_whatsapp_templates_user (user_id),
    KEY idx_whatsapp_templates_type (template_type, is_system_template),
    KEY idx_whatsapp_templates_account (account_id),
    KEY idx_whatsapp_templates_meta (waba_id, meta_template_id),
    KEY idx_whatsapp_templates_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS whatsapp_campaign_recipients (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id INT UNSIGNED NOT NULL,
    phone VARCHAR(32) NOT NULL,
    name VARCHAR(160) NULL,
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    wamid VARCHAR(255) NULL,
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_whatsapp_recipients_campaign (campaign_id),
    KEY idx_whatsapp_recipients_phone (phone),
    CONSTRAINT fk_whatsapp_recipients_campaign
        FOREIGN KEY (campaign_id) REFERENCES whatsapp_campaigns(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_conversations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenancy_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    contact_phone VARCHAR(32) NOT NULL,
    contact_name VARCHAR(160) NULL,
    last_message TEXT NULL,
    last_direction ENUM('inbound', 'outbound') NULL,
    last_message_at DATETIME NULL,
    unread_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_conversations_contact (account_id, contact_phone),
    KEY idx_whatsapp_conversations_tenancy (tenancy_id),
    KEY idx_whatsapp_conversations_user (user_id),
    KEY idx_whatsapp_conversations_account (account_id),
    KEY idx_whatsapp_conversations_last_message (last_message_at),
    CONSTRAINT fk_whatsapp_conversations_account
        FOREIGN KEY (account_id) REFERENCES whatsapp_accounts(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    wamid VARCHAR(255) NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    message_type VARCHAR(32) NOT NULL DEFAULT 'text',
    template_name VARCHAR(160) NULL,
    template_category ENUM('MARKETING', 'UTILITY', 'AUTHENTICATION') NULL,
    service_window_open TINYINT(1) NOT NULL DEFAULT 0,
    message_category ENUM('marketing', 'utility', 'authentication', 'service') NULL,
    price_brl DECIMAL(10, 4) NOT NULL DEFAULT 0,
    billed TINYINT(1) NOT NULL DEFAULT 0,
    body TEXT NULL,
    status ENUM('pending', 'sent', 'delivered', 'read', 'failed', 'received') NOT NULL DEFAULT 'sent',
    error_message TEXT NULL,
    payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_messages_wamid (wamid),
    KEY idx_whatsapp_messages_conversation (conversation_id),
    KEY idx_whatsapp_messages_account (account_id),
    KEY idx_whatsapp_messages_billing (message_category, billed, created_at),
    CONSTRAINT fk_whatsapp_messages_conversation
        FOREIGN KEY (conversation_id) REFERENCES whatsapp_conversations(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_whatsapp_messages_account
        FOREIGN KEY (account_id) REFERENCES whatsapp_accounts(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    message_category ENUM('marketing', 'utility', 'authentication', 'service') NOT NULL,
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
