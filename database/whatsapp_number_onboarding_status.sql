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
    ) NOT NULL DEFAULT 'pending_verification';

SET @add_internal_label = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE whatsapp_numbers ADD COLUMN internal_label VARCHAR(160) NULL AFTER phone_number', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_numbers' AND COLUMN_NAME = 'internal_label'
);
PREPARE stmt FROM @add_internal_label;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_display_name_meta = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE whatsapp_numbers ADD COLUMN display_name_meta VARCHAR(128) NULL AFTER internal_label', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_numbers' AND COLUMN_NAME = 'display_name_meta'
);
PREPARE stmt FROM @add_display_name_meta;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_request_internal_label = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE number_requests ADD COLUMN internal_label VARCHAR(160) NULL AFTER phone_number', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'number_requests' AND COLUMN_NAME = 'internal_label'
);
PREPARE stmt FROM @add_request_internal_label;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_request_display_name_meta = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE number_requests ADD COLUMN display_name_meta VARCHAR(128) NULL AFTER internal_label', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'number_requests' AND COLUMN_NAME = 'display_name_meta'
);
PREPARE stmt FROM @add_request_display_name_meta;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_display_name_status = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE whatsapp_numbers ADD COLUMN display_name_status VARCHAR(32) NULL AFTER verification_method', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_numbers' AND COLUMN_NAME = 'display_name_status'
);
PREPARE stmt FROM @add_display_name_status;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_display_name_submitted_at = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE whatsapp_numbers ADD COLUMN display_name_submitted_at DATETIME NULL AFTER display_name_status', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_numbers' AND COLUMN_NAME = 'display_name_submitted_at'
);
PREPARE stmt FROM @add_display_name_submitted_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_display_name_approved_at = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE whatsapp_numbers ADD COLUMN display_name_approved_at DATETIME NULL AFTER display_name_submitted_at', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_numbers' AND COLUMN_NAME = 'display_name_approved_at'
);
PREPARE stmt FROM @add_display_name_approved_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_display_name_approval_seconds = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE whatsapp_numbers ADD COLUMN display_name_approval_seconds INT UNSIGNED NULL AFTER display_name_approved_at', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_numbers' AND COLUMN_NAME = 'display_name_approval_seconds'
);
PREPARE stmt FROM @add_display_name_approval_seconds;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_display_name_last_checked_at = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE whatsapp_numbers ADD COLUMN display_name_last_checked_at DATETIME NULL AFTER display_name_approval_seconds', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_numbers' AND COLUMN_NAME = 'display_name_last_checked_at'
);
PREPARE stmt FROM @add_display_name_last_checked_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_display_name_rejected_at = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE whatsapp_numbers ADD COLUMN display_name_rejected_at DATETIME NULL AFTER display_name_last_checked_at', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_numbers' AND COLUMN_NAME = 'display_name_rejected_at'
);
PREPARE stmt FROM @add_display_name_rejected_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_otp_confirmed_at = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE whatsapp_numbers ADD COLUMN otp_confirmed_at DATETIME NULL AFTER display_name_rejected_at', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_numbers' AND COLUMN_NAME = 'otp_confirmed_at'
);
PREPARE stmt FROM @add_otp_confirmed_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_connected_at = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE whatsapp_numbers ADD COLUMN connected_at DATETIME NULL AFTER otp_confirmed_at', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_numbers' AND COLUMN_NAME = 'connected_at'
);
PREPARE stmt FROM @add_connected_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_last_meta_error = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE whatsapp_numbers ADD COLUMN last_meta_error TEXT NULL AFTER connected_at', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_numbers' AND COLUMN_NAME = 'last_meta_error'
);
PREPARE stmt FROM @add_last_meta_error;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_last_meta_error_at = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE whatsapp_numbers ADD COLUMN last_meta_error_at DATETIME NULL AFTER last_meta_error', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_numbers' AND COLUMN_NAME = 'last_meta_error_at'
);
PREPARE stmt FROM @add_last_meta_error_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

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

UPDATE whatsapp_numbers
SET display_name_meta = COALESCE(display_name_meta, display_name),
    internal_label = COALESCE(internal_label, display_name)
WHERE display_name IS NOT NULL
  AND (display_name_meta IS NULL OR internal_label IS NULL);

UPDATE number_requests
SET display_name_meta = COALESCE(display_name_meta, display_name),
    internal_label = COALESCE(internal_label, display_name)
WHERE display_name IS NOT NULL
  AND (display_name_meta IS NULL OR internal_label IS NULL);

UPDATE whatsapp_numbers
SET status = 'pending_verification'
WHERE status IN ('pending', 'code_sent')
  AND verified_at IS NULL;

UPDATE whatsapp_numbers
SET status = 'pending_name_approval',
    display_name_status = COALESCE(display_name_status, 'PENDING_REVIEW')
WHERE status IN ('pending', 'code_sent')
  AND verified_at IS NOT NULL;

UPDATE whatsapp_numbers
SET status = 'blocked'
WHERE status = 'failed';

UPDATE whatsapp_numbers
SET status = 'active'
WHERE status = 'pending_name_approval'
  AND verified_at IS NOT NULL
  AND whatsapp_account_id IS NOT NULL;
