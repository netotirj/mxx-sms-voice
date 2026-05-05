CREATE TABLE IF NOT EXISTS reseller_whatsapp_pricing (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reseller_id INT UNSIGNED NOT NULL,
    tenancy_id CHAR(36) NOT NULL,
    category ENUM('marketing','utility','authentication') NOT NULL,
    price_brl DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reseller_whatsapp_category (reseller_id, tenancy_id, category),
    KEY idx_reseller_whatsapp_lookup (tenancy_id, reseller_id, status, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO reseller_whatsapp_pricing
    (reseller_id, tenancy_id, category, price_brl, status, created_at, updated_at)
SELECT
    rr.user_id,
    rr.tenancy_id,
    categories.category,
    rr.rate,
    rr.status,
    COALESCE(rr.created_at, NOW()),
    COALESCE(rr.updated_at, NOW())
FROM reseller_rates rr
JOIN (
    SELECT 'marketing' AS category
    UNION ALL SELECT 'utility'
    UNION ALL SELECT 'authentication'
) categories
WHERE rr.type = 'whatsapp'
ON DUPLICATE KEY UPDATE
    price_brl = VALUES(price_brl),
    status = VALUES(status),
    updated_at = VALUES(updated_at);
