ALTER TABLE whatsapp_templates
    ADD COLUMN IF NOT EXISTS components_json JSON NULL AFTER components,
    ADD COLUMN IF NOT EXISTS rejected_reason TEXT NULL AFTER components_json,
    ADD COLUMN IF NOT EXISTS created_by INT UNSIGNED NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS updated_by INT UNSIGNED NULL AFTER created_by,
    ADD INDEX IF NOT EXISTS idx_whatsapp_templates_waba_tenant_status (tenancy_id, waba_id, status),
    ADD INDEX IF NOT EXISTS idx_whatsapp_templates_created_by (created_by),
    ADD INDEX IF NOT EXISTS idx_whatsapp_templates_updated_by (updated_by);

UPDATE whatsapp_templates
SET components_json = components
WHERE components_json IS NULL
  AND components IS NOT NULL;

UPDATE whatsapp_templates
SET created_by = user_id
WHERE created_by IS NULL;

UPDATE whatsapp_templates
SET updated_by = user_id
WHERE updated_by IS NULL;

CREATE TABLE IF NOT EXISTS whatsapp_template_event_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event VARCHAR(80) NOT NULL,
    tenancy_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NULL,
    account_id INT UNSIGNED NULL,
    conversation_id INT UNSIGNED NULL,
    contact_phone VARCHAR(32) NULL,
    template_name VARCHAR(160) NULL,
    payload_json JSON NULL,
    response_json JSON NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_whatsapp_template_event_tenancy (tenancy_id, created_at),
    KEY idx_whatsapp_template_event_template (template_name, created_at),
    KEY idx_whatsapp_template_event_account (account_id, created_at),
    KEY idx_whatsapp_template_event_event (event, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
