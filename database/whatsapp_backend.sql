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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_accounts_phone_number_id (phone_number_id),
    KEY idx_whatsapp_accounts_tenancy (tenancy_id),
    KEY idx_whatsapp_accounts_user (user_id)
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
    template_components JSON NULL,
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
    CONSTRAINT fk_whatsapp_campaigns_account
        FOREIGN KEY (account_id) REFERENCES whatsapp_accounts(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenancy_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    language VARCHAR(16) NOT NULL DEFAULT 'pt_BR',
    category ENUM('MARKETING', 'UTILITY', 'AUTHENTICATION') NOT NULL DEFAULT 'MARKETING',
    body TEXT NULL,
    components JSON NULL,
    status ENUM('draft', 'pending', 'approved', 'rejected', 'paused') NOT NULL DEFAULT 'approved',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_templates_name_language_tenancy (tenancy_id, name, language),
    KEY idx_whatsapp_templates_tenancy (tenancy_id),
    KEY idx_whatsapp_templates_user (user_id),
    KEY idx_whatsapp_templates_status (status)
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
    CONSTRAINT fk_whatsapp_messages_conversation
        FOREIGN KEY (conversation_id) REFERENCES whatsapp_conversations(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_whatsapp_messages_account
        FOREIGN KEY (account_id) REFERENCES whatsapp_accounts(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
