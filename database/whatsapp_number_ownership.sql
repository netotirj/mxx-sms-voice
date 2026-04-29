ALTER TABLE whatsapp_numbers
    ADD COLUMN owner_type ENUM('client', 'reseller') NULL AFTER user_id,
    ADD COLUMN owner_id INT UNSIGNED NULL AFTER owner_type,
    ADD COLUMN requested_by_user_id INT UNSIGNED NULL AFTER owner_id,
    ADD COLUMN active_owner_key VARCHAR(64)
        GENERATED ALWAYS AS (
            CASE
                WHEN status = 'active' AND owner_type IS NOT NULL AND owner_id IS NOT NULL
                THEN CONCAT(owner_type, ':', owner_id)
                ELSE NULL
            END
        ) STORED AFTER recommendation,
    ADD UNIQUE KEY uq_whatsapp_numbers_active_owner (active_owner_key),
    ADD KEY idx_whatsapp_numbers_owner (owner_type, owner_id),
    ADD KEY idx_whatsapp_numbers_requested_by (requested_by_user_id);
