CREATE TABLE IF NOT EXISTS whatsapp_template_blueprints (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    blueprint_key VARCHAR(120) NOT NULL,
    name VARCHAR(120) NOT NULL,
    category ENUM('MARKETING', 'UTILITY', 'AUTHENTICATION') NOT NULL,
    language VARCHAR(10) NOT NULL DEFAULT 'pt_BR',
    body TEXT NOT NULL,
    variables_json JSON NULL,
    components JSON NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_template_blueprint_key (blueprint_key),
    KEY idx_whatsapp_template_blueprints_lookup (active, category, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
