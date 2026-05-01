CREATE TABLE IF NOT EXISTS whatsapp_meta_rate_limit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(64) NOT NULL,
    account_id BIGINT UNSIGNED NULL,
    waba_id VARCHAR(128) NULL,
    phone_number_id VARCHAR(128) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    status_code INT NULL,
    error_code VARCHAR(32) NULL,
    retry_after INT NULL,
    backoff_seconds INT NOT NULL,
    blocked_until DATETIME NOT NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_whatsapp_meta_rate_phone_created (phone_number_id, created_at),
    KEY idx_whatsapp_meta_rate_tenant_created (tenant_id, created_at),
    KEY idx_whatsapp_meta_rate_waba_created (waba_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
