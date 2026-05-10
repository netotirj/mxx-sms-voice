ALTER TABLE webhook_pix
    ADD COLUMN asaas_payment_id VARCHAR(64) NULL AFTER transactionReceiptUrl,
    ADD COLUMN asaas_customer_id VARCHAR(64) NULL AFTER asaas_payment_id,
    ADD COLUMN invoice_url VARCHAR(255) NULL AFTER asaas_customer_id,
    ADD COLUMN pix_payload LONGTEXT NULL AFTER invoice_url,
    ADD COLUMN pix_encoded_image LONGTEXT NULL AFTER pix_payload,
    ADD COLUMN due_date DATE NULL AFTER pix_encoded_image,
    ADD COLUMN payment_date DATETIME NULL AFTER due_date,
    ADD COLUMN last_webhook_event VARCHAR(64) NULL AFTER payment_date,
    ADD COLUMN last_webhook_payload LONGTEXT NULL AFTER last_webhook_event,
    ADD COLUMN updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER confirmed_date;

CREATE TABLE IF NOT EXISTS pix_api_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    webhook_id INT NULL,
    user_id INT UNSIGNED NULL,
    tenancy_id CHAR(36) NULL,
    event_name VARCHAR(64) NOT NULL,
    level VARCHAR(16) NOT NULL DEFAULT 'info',
    payment_id VARCHAR(64) NULL,
    invoice_number VARCHAR(64) NULL,
    pix_qr_code_id VARCHAR(64) NULL,
    message TEXT NULL,
    context_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pix_api_logs_webhook (webhook_id),
    KEY idx_pix_api_logs_tenancy_user (tenancy_id, user_id),
    KEY idx_pix_api_logs_payment (payment_id, invoice_number, pix_qr_code_id),
    KEY idx_pix_api_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
