ALTER TABLE whatsapp_messages
    ADD COLUMN IF NOT EXISTS phone_number_id VARCHAR(128) NULL AFTER account_id,
    ADD COLUMN IF NOT EXISTS waba_id VARCHAR(128) NULL AFTER phone_number_id,
    ADD COLUMN IF NOT EXISTS source VARCHAR(32) NULL AFTER direction,
    ADD COLUMN IF NOT EXISTS origin VARCHAR(32) NULL AFTER source,
    ADD COLUMN IF NOT EXISTS is_from_api TINYINT(1) NOT NULL DEFAULT 0 AFTER origin,
    ADD COLUMN IF NOT EXISTS is_from_app TINYINT(1) NOT NULL DEFAULT 0 AFTER is_from_api;

ALTER TABLE whatsapp_conversations
    ADD COLUMN IF NOT EXISTS phone_number_id VARCHAR(128) NULL AFTER account_id,
    ADD COLUMN IF NOT EXISTS waba_id VARCHAR(128) NULL AFTER phone_number_id,
    ADD COLUMN IF NOT EXISTS last_source VARCHAR(32) NULL AFTER last_direction,
    ADD COLUMN IF NOT EXISTS last_origin VARCHAR(32) NULL AFTER last_source;
