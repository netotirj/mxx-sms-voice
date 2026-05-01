START TRANSACTION;

CREATE TABLE IF NOT EXISTS whatsapp_templates_backup_keep_meta_approved_20260501 AS
SELECT * FROM whatsapp_templates;

DELETE FROM whatsapp_templates
WHERE tenancy_id = '31948c42-58de-476b-bf4a-4f5098130aaa'
  AND account_id = 14
  AND waba_id = '2695577467490595'
  AND status <> 'approved';

COMMIT;
