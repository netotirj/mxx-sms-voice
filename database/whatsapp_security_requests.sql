ALTER TABLE whatsapp_accounts
    MODIFY access_token TEXT NOT NULL,
    MODIFY app_secret TEXT NULL;

CREATE TABLE IF NOT EXISTS number_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id VARCHAR(64) NOT NULL,
    requested_by_user_id INT UNSIGNED NOT NULL,
    owner_type ENUM('admin', 'client', 'reseller') NOT NULL DEFAULT 'client',
    owner_id INT UNSIGNED NOT NULL,
    phone_number VARCHAR(32) NOT NULL,
    internal_label VARCHAR(160) NULL,
    display_name_meta VARCHAR(128) NULL,
    display_name VARCHAR(160) NULL,
    status ENUM('pending', 'meta_submitted', 'meta_failed', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    admin_notes TEXT NULL,
    whatsapp_number_id INT UNSIGNED NULL,
    support_ticket_id INT UNSIGNED NULL,
    reviewed_by_user_id INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    active_request_key VARCHAR(96)
        GENERATED ALWAYS AS (
            CASE
                WHEN status = 'pending' THEN CONCAT('pending:', phone_number)
                ELSE NULL
            END
        ) STORED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_number_requests_pending_phone (active_request_key),
    KEY idx_wnr_company_status (company_id, status, created_at),
    KEY idx_wnr_owner (owner_type, owner_id, status),
    KEY idx_wnr_requested_by (requested_by_user_id, status),
    KEY idx_wnr_phone (phone_number),
    KEY idx_wnr_support_ticket (support_ticket_id),
    CONSTRAINT fk_number_requests_requested_by
        FOREIGN KEY (requested_by_user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_number_requests_reviewed_by
        FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_number_requests_whatsapp_number
        FOREIGN KEY (whatsapp_number_id) REFERENCES whatsapp_numbers(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE number_requests
    MODIFY owner_type ENUM('admin', 'client', 'reseller') NOT NULL DEFAULT 'client',
    MODIFY status ENUM('pending', 'meta_submitted', 'meta_failed', 'approved', 'rejected') NOT NULL DEFAULT 'pending';

SET @add_support_ticket_id = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE number_requests ADD COLUMN support_ticket_id INT UNSIGNED NULL AFTER whatsapp_number_id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'number_requests'
      AND COLUMN_NAME = 'support_ticket_id'
);
PREPARE stmt FROM @add_support_ticket_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_support_ticket_idx = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE number_requests ADD KEY idx_wnr_support_ticket (support_ticket_id)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'number_requests'
      AND INDEX_NAME = 'idx_wnr_support_ticket'
);
PREPARE stmt FROM @add_support_ticket_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
