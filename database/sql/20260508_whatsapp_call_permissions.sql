CREATE TABLE IF NOT EXISTS whatsapp_call_permissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenancy_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NULL,
    account_id INT UNSIGNED NOT NULL,
    contact_id INT UNSIGNED NULL,
    phone_number VARCHAR(32) NOT NULL,
    permission_status VARCHAR(32) NOT NULL DEFAULT 'no_permission',
    permission_requested_at DATETIME NULL,
    permission_approved_at DATETIME NULL,
    permission_expires_at DATETIME NULL,
    permission_request_wamid VARCHAR(128) NULL,
    permission_response_source VARCHAR(32) NULL,
    permission_context_id VARCHAR(128) NULL,
    is_permanent TINYINT(1) NOT NULL DEFAULT 0,
    last_error_code VARCHAR(32) NULL,
    last_error_message VARCHAR(255) NULL,
    meta_payload LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY unq_whatsapp_call_permissions_account_phone (account_id, phone_number),
    KEY idx_whatsapp_call_permissions_contact (contact_id),
    KEY idx_whatsapp_call_permissions_status (permission_status),
    KEY idx_whatsapp_call_permissions_tenancy (tenancy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback:
-- DROP TABLE IF EXISTS whatsapp_call_permissions;
