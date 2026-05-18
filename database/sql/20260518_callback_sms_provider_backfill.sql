SET @callback_sms_provider_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'callback'
      AND COLUMN_NAME = 'sms_provider'
);

SET @callback_sms_provider_sql := IF(
    @callback_sms_provider_exists = 0,
    'ALTER TABLE callback ADD COLUMN sms_provider VARCHAR(80) NULL AFTER id_partner',
    'SELECT 1'
);

PREPARE callback_sms_provider_stmt FROM @callback_sms_provider_sql;
EXECUTE callback_sms_provider_stmt;
DEALLOCATE PREPARE callback_sms_provider_stmt;

UPDATE callback
SET sms_provider = LOWER(TRIM(id_partner))
WHERE (sms_provider IS NULL OR sms_provider = '')
  AND id_partner IS NOT NULL
  AND TRIM(id_partner) <> '';

UPDATE callback
SET sms_provider = 'disparopro'
WHERE (sms_provider IS NULL OR sms_provider = '');
