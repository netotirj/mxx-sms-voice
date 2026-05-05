ALTER TABLE mxx_plans
    ADD COLUMN IF NOT EXISTS value_whatsapp_marketing DECIMAL(10,4) NULL AFTER value_whatsapp,
    ADD COLUMN IF NOT EXISTS value_whatsapp_utility DECIMAL(10,4) NULL AFTER value_whatsapp_marketing,
    ADD COLUMN IF NOT EXISTS value_whatsapp_authentication DECIMAL(10,4) NULL AFTER value_whatsapp_utility;

UPDATE mxx_plans
SET
    value_whatsapp_marketing = CASE
        WHEN value_whatsapp_marketing IS NULL OR value_whatsapp_marketing <= 0 THEN value_whatsapp
        ELSE value_whatsapp_marketing
    END,
    value_whatsapp_utility = CASE
        WHEN value_whatsapp_utility IS NULL OR value_whatsapp_utility <= 0 THEN value_whatsapp
        ELSE value_whatsapp_utility
    END,
    value_whatsapp_authentication = CASE
        WHEN value_whatsapp_authentication IS NULL OR value_whatsapp_authentication <= 0 THEN value_whatsapp
        ELSE value_whatsapp_authentication
    END
WHERE status = 'active';

INSERT INTO plan_whatsapp_pricing (plan_id, category, price_brl, created_at, updated_at)
SELECT id, 'marketing', value_whatsapp_marketing, NOW(), NOW()
FROM mxx_plans
WHERE status = 'active'
  AND value_whatsapp_marketing > 0
ON DUPLICATE KEY UPDATE price_brl = VALUES(price_brl), updated_at = NOW();

INSERT INTO plan_whatsapp_pricing (plan_id, category, price_brl, created_at, updated_at)
SELECT id, 'utility', value_whatsapp_utility, NOW(), NOW()
FROM mxx_plans
WHERE status = 'active'
  AND value_whatsapp_utility > 0
ON DUPLICATE KEY UPDATE price_brl = VALUES(price_brl), updated_at = NOW();

INSERT INTO plan_whatsapp_pricing (plan_id, category, price_brl, created_at, updated_at)
SELECT id, 'authentication', value_whatsapp_authentication, NOW(), NOW()
FROM mxx_plans
WHERE status = 'active'
  AND value_whatsapp_authentication > 0
ON DUPLICATE KEY UPDATE price_brl = VALUES(price_brl), updated_at = NOW();
