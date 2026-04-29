CREATE TABLE IF NOT EXISTS whatsapp_numbers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    whatsapp_account_id INT UNSIGNED NULL,
    phone_number VARCHAR(32) NOT NULL,
    display_name VARCHAR(160) NULL,
    origin ENUM('client', 'platform') NOT NULL DEFAULT 'client',
    status ENUM('available', 'pending', 'code_sent', 'active', 'removing', 'removed', 'failed') NOT NULL DEFAULT 'pending',
    meta_id VARCHAR(64) NULL,
    waba_id VARCHAR(64) NULL,
    verification_method ENUM('SMS', 'VOICE') NULL,
    verified_at DATETIME NULL,
    removed_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_numbers_phone (phone_number),
    UNIQUE KEY uq_whatsapp_numbers_meta_id (meta_id),
    KEY idx_whatsapp_numbers_company (company_id),
    KEY idx_whatsapp_numbers_status (status),
    KEY idx_whatsapp_numbers_origin (origin),
    CONSTRAINT fk_whatsapp_numbers_account
        FOREIGN KEY (whatsapp_account_id) REFERENCES whatsapp_accounts(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
