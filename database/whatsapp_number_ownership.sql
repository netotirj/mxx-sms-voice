ALTER TABLE whatsapp_numbers
    ADD COLUMN IF NOT EXISTS owner_type ENUM('admin', 'client', 'reseller') NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS owner_id INT UNSIGNED NULL AFTER owner_type,
    ADD COLUMN IF NOT EXISTS requested_by_user_id INT UNSIGNED NULL AFTER owner_id,
    ADD COLUMN IF NOT EXISTS active_owner_key VARCHAR(64)
        GENERATED ALWAYS AS (
            CASE
                WHEN status = 'active' AND owner_type IS NOT NULL AND owner_id IS NOT NULL
                THEN CONCAT(owner_type, ':', owner_id)
                ELSE NULL
            END
        ) STORED AFTER requested_by_user_id,
    ADD UNIQUE KEY IF NOT EXISTS uq_whatsapp_numbers_active_owner (active_owner_key),
    ADD KEY IF NOT EXISTS idx_whatsapp_numbers_owner (owner_type, owner_id),
    ADD KEY IF NOT EXISTS idx_whatsapp_numbers_requested_by (requested_by_user_id);

ALTER TABLE whatsapp_numbers
    MODIFY owner_type ENUM('admin', 'client', 'reseller') NULL;
