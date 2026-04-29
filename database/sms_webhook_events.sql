CREATE TABLE IF NOT EXISTS sms_webhook_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    webhook_object VARCHAR(50) NULL,
    webhook_action VARCHAR(50) NULL,
    status_sms VARCHAR(50) NULL,
    phone_sms VARCHAR(30) NULL,
    id_partner VARCHAR(80) NULL,
    origin_id VARCHAR(120) NULL,
    raw_item JSON NULL,
    raw_payload JSON NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_received_at (received_at),
    KEY idx_action_object (webhook_object, webhook_action),
    KEY idx_partner_phone (id_partner, phone_sms)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
