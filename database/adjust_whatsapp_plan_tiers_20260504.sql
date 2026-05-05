UPDATE mxx_plans
SET
    amount_plan = CASE CAST(amount_plan AS DECIMAL(10,2))
        WHEN 50 THEN '200'
        WHEN 100 THEN '400'
        WHEN 150 THEN '600'
        WHEN 200 THEN '800'
        WHEN 300 THEN '900'
        WHEN 500 THEN '1000'
        ELSE amount_plan
    END,
    value_whatsapp_marketing = CASE CAST(amount_plan AS DECIMAL(10,2))
        WHEN 50 THEN 0.6000
        WHEN 100 THEN 0.5600
        WHEN 150 THEN 0.5200
        WHEN 200 THEN 0.4800
        WHEN 300 THEN 0.4400
        WHEN 500 THEN 0.4000
        ELSE value_whatsapp_marketing
    END,
    value_whatsapp_utility = CASE CAST(amount_plan AS DECIMAL(10,2))
        WHEN 50 THEN 0.1500
        WHEN 100 THEN 0.1400
        WHEN 150 THEN 0.1300
        WHEN 200 THEN 0.1200
        WHEN 300 THEN 0.1100
        WHEN 500 THEN 0.1000
        ELSE value_whatsapp_utility
    END,
    value_whatsapp_authentication = CASE CAST(amount_plan AS DECIMAL(10,2))
        WHEN 50 THEN 0.1500
        WHEN 100 THEN 0.1400
        WHEN 150 THEN 0.1300
        WHEN 200 THEN 0.1200
        WHEN 300 THEN 0.1100
        WHEN 500 THEN 0.1000
        ELSE value_whatsapp_authentication
    END,
    value_whatsapp = CASE CAST(amount_plan AS DECIMAL(10,2))
        WHEN 50 THEN 0.1500
        WHEN 100 THEN 0.1400
        WHEN 150 THEN 0.1300
        WHEN 200 THEN 0.1200
        WHEN 300 THEN 0.1100
        WHEN 500 THEN 0.1000
        ELSE value_whatsapp
    END,
    is_popular = CASE WHEN CAST(amount_plan AS DECIMAL(10,2)) = 500 THEN 1 ELSE 0 END
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

UPDATE mxx_plans
SET
    value_whatsapp_marketing = CASE CAST(amount_plan AS DECIMAL(10,2))
        WHEN 200 THEN 0.6000
        WHEN 400 THEN 0.5600
        WHEN 600 THEN 0.5200
        WHEN 800 THEN 0.4800
        WHEN 900 THEN 0.4400
        WHEN 1000 THEN 0.4000
        ELSE value_whatsapp_marketing
    END,
    value_whatsapp_utility = CASE CAST(amount_plan AS DECIMAL(10,2))
        WHEN 200 THEN 0.1500
        WHEN 400 THEN 0.1400
        WHEN 600 THEN 0.1300
        WHEN 800 THEN 0.1200
        WHEN 900 THEN 0.1100
        WHEN 1000 THEN 0.1000
        ELSE value_whatsapp_utility
    END,
    value_whatsapp_authentication = CASE CAST(amount_plan AS DECIMAL(10,2))
        WHEN 200 THEN 0.1500
        WHEN 400 THEN 0.1400
        WHEN 600 THEN 0.1300
        WHEN 800 THEN 0.1200
        WHEN 900 THEN 0.1100
        WHEN 1000 THEN 0.1000
        ELSE value_whatsapp_authentication
    END,
    value_whatsapp = CASE CAST(amount_plan AS DECIMAL(10,2))
        WHEN 200 THEN 0.1500
        WHEN 400 THEN 0.1400
        WHEN 600 THEN 0.1300
        WHEN 800 THEN 0.1200
        WHEN 900 THEN 0.1100
        WHEN 1000 THEN 0.1000
        ELSE value_whatsapp
    END,
    is_popular = CASE WHEN CAST(amount_plan AS DECIMAL(10,2)) = 1000 THEN 1 ELSE 0 END
WHERE status = 'active';
