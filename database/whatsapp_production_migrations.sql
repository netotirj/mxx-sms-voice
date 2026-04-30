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
