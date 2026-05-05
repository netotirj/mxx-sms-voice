CREATE TABLE IF NOT EXISTS currency_exchange_rates (
    currency CHAR(3) NOT NULL,
    rate_brl DECIMAL(14, 6) NOT NULL,
    safety_margin_percent DECIMAL(7, 4) NOT NULL DEFAULT 5.0000,
    effective_rate DECIMAL(14, 6) NOT NULL,
    daily_change_percent DECIMAL(9, 4) NOT NULL DEFAULT 0,
    alert_flag TINYINT(1) NOT NULL DEFAULT 0,
    source VARCHAR(80) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (currency),
    KEY idx_currency_exchange_alert (alert_flag, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS currency_exchange_alerts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    currency CHAR(3) NOT NULL,
    previous_rate_brl DECIMAL(14, 6) NULL,
    new_rate_brl DECIMAL(14, 6) NOT NULL,
    change_percent DECIMAL(9, 4) NOT NULL,
    threshold_percent DECIMAL(7, 4) NOT NULL,
    message VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_currency_exchange_alerts_currency (currency, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_meta_message_prices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    country_code CHAR(2) NOT NULL,
    message_type ENUM('marketing', 'utility', 'authentication') NOT NULL,
    price_usd DECIMAL(12, 6) NOT NULL,
    valid_from DATE NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_meta_price_active_date (country_code, message_type, valid_from),
    KEY idx_whatsapp_meta_price_lookup (country_code, message_type, active, valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_pricing_margins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id INT UNSIGNED NOT NULL DEFAULT 0,
    message_type ENUM('marketing', 'utility', 'authentication') NOT NULL,
    margin_percent DECIMAL(8, 4) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_pricing_margin_customer_type (customer_id, message_type),
    KEY idx_whatsapp_pricing_margin_lookup (message_type, customer_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_pricing_price_floors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    country_code CHAR(2) NOT NULL,
    message_type ENUM('marketing', 'utility', 'authentication') NOT NULL,
    customer_id INT UNSIGNED NOT NULL DEFAULT 0,
    final_price_brl DECIMAL(10, 4) NOT NULL,
    locked_until DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_pricing_floor (country_code, message_type, customer_id),
    KEY idx_whatsapp_pricing_floor_locked (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO currency_exchange_rates
    (currency, rate_brl, safety_margin_percent, effective_rate, daily_change_percent, alert_flag, source, updated_at)
VALUES
    ('USD', 5.000000, 5.0000, 5.250000, 0, 0, 'initial_seed', NOW())
ON DUPLICATE KEY UPDATE currency = currency;

INSERT INTO whatsapp_meta_message_prices
    (country_code, message_type, price_usd, valid_from, active, created_at, updated_at)
VALUES
    ('BR', 'marketing', 0.070000, CURDATE(), 1, NOW(), NOW()),
    ('BR', 'utility', 0.008000, CURDATE(), 1, NOW(), NOW()),
    ('BR', 'authentication', 0.008000, CURDATE(), 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE price_usd = price_usd;

INSERT INTO whatsapp_pricing_margins
    (customer_id, message_type, margin_percent, active, created_at, updated_at)
VALUES
    (0, 'marketing', 85.0000, 1, NOW(), NOW()),
    (0, 'utility', 180.0000, 1, NOW(), NOW()),
    (0, 'authentication', 180.0000, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE margin_percent = margin_percent;

ALTER TABLE plan_whatsapp_pricing
    MODIFY category ENUM('marketing', 'utility', 'authentication') NOT NULL;

ALTER TABLE whatsapp_messages
    MODIFY template_category ENUM('MARKETING', 'UTILITY', 'AUTHENTICATION') NULL,
    MODIFY message_category ENUM('marketing', 'utility', 'authentication', 'service') NULL;

ALTER TABLE whatsapp_outbox
    MODIFY template_category ENUM('MARKETING', 'UTILITY', 'AUTHENTICATION') NULL,
    MODIFY message_category ENUM('marketing', 'utility', 'authentication', 'service') NOT NULL;

ALTER TABLE whatsapp_message_cdr
    MODIFY message_category ENUM('marketing', 'utility', 'authentication', 'service') NOT NULL,
    ADD COLUMN IF NOT EXISTS country_code CHAR(2) NULL AFTER message_category,
    ADD COLUMN IF NOT EXISTS cost_usd DECIMAL(12, 6) NULL AFTER country_code,
    ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(14, 6) NULL AFTER cost_usd,
    ADD COLUMN IF NOT EXISTS effective_rate DECIMAL(14, 6) NULL AFTER exchange_rate,
    ADD COLUMN IF NOT EXISTS cost_brl DECIMAL(12, 6) NULL AFTER effective_rate,
    ADD COLUMN IF NOT EXISTS margin_percent DECIMAL(8, 4) NULL AFTER cost_brl,
    ADD COLUMN IF NOT EXISTS final_price_brl DECIMAL(10, 4) NULL AFTER margin_percent,
    ADD COLUMN IF NOT EXISTS delivered_at DATETIME NULL AFTER final_price_brl,
    ADD COLUMN IF NOT EXISTS pricing_payload JSON NULL AFTER delivered_at,
    ADD INDEX IF NOT EXISTS idx_whatsapp_cdr_pricing_country (country_code, message_category, timestamp);
