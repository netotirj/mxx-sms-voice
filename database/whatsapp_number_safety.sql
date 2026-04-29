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
