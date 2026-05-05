SET @add_support_ticket_id_to_sessions = (
    SELECT IF(
        NOT EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'whatsapp_support_sessions'
              AND COLUMN_NAME = 'support_ticket_id'
        ),
        'ALTER TABLE whatsapp_support_sessions ADD COLUMN support_ticket_id INT UNSIGNED NULL AFTER conversation_id',
        'SELECT 1'
    )
);
PREPARE stmt FROM @add_support_ticket_id_to_sessions;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_support_ticket_idx_to_sessions = (
    SELECT IF(
        NOT EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'whatsapp_support_sessions'
              AND INDEX_NAME = 'idx_wass_support_ticket'
        ),
        'ALTER TABLE whatsapp_support_sessions ADD KEY idx_wass_support_ticket (support_ticket_id)',
        'SELECT 1'
    )
);
PREPARE stmt FROM @add_support_ticket_idx_to_sessions;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
