UPDATE whatsapp_meta_message_prices
SET active = 0, updated_at = NOW()
WHERE country_code = 'BR'
  AND message_type IN ('marketing', 'utility', 'authentication')
  AND active = 1
  AND (
      valid_from <> '2026-04-01'
      OR (message_type = 'marketing' AND price_usd <> 0.071880)
      OR (message_type IN ('utility', 'authentication') AND price_usd <> 0.007820)
  );

INSERT INTO whatsapp_meta_message_prices
    (country_code, message_type, price_usd, valid_from, active, created_at, updated_at)
VALUES
    ('BR', 'marketing', 0.071880, '2026-04-01', 1, NOW(), NOW()),
    ('BR', 'utility', 0.007820, '2026-04-01', 1, NOW(), NOW()),
    ('BR', 'authentication', 0.007820, '2026-04-01', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    price_usd = VALUES(price_usd),
    active = VALUES(active),
    updated_at = NOW();
