UPDATE mxx_plans mp
JOIN (
    SELECT
        wmp.message_type,
        GREATEST(
            0.0500,
            CEIL((price_usd * effective_rate * (1 + margin_percent / 100)) * 100) / 100
        ) AS final_price_brl
    FROM whatsapp_meta_message_prices wmp
    JOIN currency_exchange_rates cer ON cer.currency = 'USD'
    JOIN whatsapp_pricing_margins wpm
      ON wpm.message_type = wmp.message_type
     AND wpm.customer_id = 0
     AND wpm.active = 1
    WHERE wmp.country_code = 'BR'
      AND wmp.active = 1
      AND wmp.valid_from <= CURDATE()
) calc ON calc.message_type = 'marketing'
SET mp.value_whatsapp_marketing = calc.final_price_brl
WHERE mp.status = 'active';

UPDATE mxx_plans mp
JOIN (
    SELECT
        wmp.message_type,
        GREATEST(
            0.0500,
            CEIL((price_usd * effective_rate * (1 + margin_percent / 100)) * 100) / 100
        ) AS final_price_brl
    FROM whatsapp_meta_message_prices wmp
    JOIN currency_exchange_rates cer ON cer.currency = 'USD'
    JOIN whatsapp_pricing_margins wpm
      ON wpm.message_type = wmp.message_type
     AND wpm.customer_id = 0
     AND wpm.active = 1
    WHERE wmp.country_code = 'BR'
      AND wmp.active = 1
      AND wmp.valid_from <= CURDATE()
) calc ON calc.message_type = 'utility'
SET mp.value_whatsapp_utility = calc.final_price_brl
WHERE mp.status = 'active';

UPDATE mxx_plans mp
JOIN (
    SELECT
        wmp.message_type,
        GREATEST(
            0.0500,
            CEIL((price_usd * effective_rate * (1 + margin_percent / 100)) * 100) / 100
        ) AS final_price_brl
    FROM whatsapp_meta_message_prices wmp
    JOIN currency_exchange_rates cer ON cer.currency = 'USD'
    JOIN whatsapp_pricing_margins wpm
      ON wpm.message_type = wmp.message_type
     AND wpm.customer_id = 0
     AND wpm.active = 1
    WHERE wmp.country_code = 'BR'
      AND wmp.active = 1
      AND wmp.valid_from <= CURDATE()
) calc ON calc.message_type = 'authentication'
SET mp.value_whatsapp_authentication = calc.final_price_brl
WHERE mp.status = 'active';

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
