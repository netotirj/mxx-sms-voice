ALTER TABLE whatsapp_outbox
    ADD COLUMN idempotency_key CHAR(64) NULL AFTER id,
    ADD COLUMN preview_body TEXT NULL AFTER body,
    ADD COLUMN template_variables JSON NULL AFTER template_components,
    ADD COLUMN pricing_snapshot JSON NULL AFTER price_brl;

ALTER TABLE whatsapp_outbox
    ADD UNIQUE KEY uq_whatsapp_outbox_idempotency (idempotency_key),
    ADD INDEX idx_whatsapp_outbox_template_dedupe (
        tenancy_id,
        account_id,
        contact_phone,
        template_name,
        status,
        created_at
    );

ALTER TABLE whatsapp_campaign_recipients
    ADD COLUMN template_variables JSON NULL AFTER name,
    MODIFY status ENUM('pending', 'queued', 'sent', 'failed') NOT NULL DEFAULT 'pending';

ALTER TABLE whatsapp_messages
    ADD COLUMN preview_body TEXT NULL AFTER body,
    ADD COLUMN template_variables JSON NULL AFTER payload,
    ADD COLUMN pricing_snapshot JSON NULL AFTER template_variables;

ALTER TABLE whatsapp_message_cdr
    MODIFY message_category ENUM('marketing', 'utility', 'authentication', 'service') NOT NULL,
    ADD COLUMN country_code CHAR(2) NULL AFTER message_category,
    ADD COLUMN cost_usd DECIMAL(12, 6) NULL AFTER country_code,
    ADD COLUMN exchange_rate DECIMAL(14, 6) NULL AFTER cost_usd,
    ADD COLUMN effective_rate DECIMAL(14, 6) NULL AFTER exchange_rate,
    ADD COLUMN cost_brl DECIMAL(12, 6) NULL AFTER effective_rate,
    ADD COLUMN margin_percent DECIMAL(8, 4) NULL AFTER cost_brl,
    ADD COLUMN final_price_brl DECIMAL(10, 4) NULL AFTER margin_percent,
    ADD COLUMN delivered_at DATETIME NULL AFTER final_price_brl,
    ADD COLUMN pricing_payload JSON NULL AFTER delivered_at;

ALTER TABLE whatsapp_message_cdr
    ADD INDEX idx_whatsapp_cdr_pricing_country (country_code, message_category, timestamp);
