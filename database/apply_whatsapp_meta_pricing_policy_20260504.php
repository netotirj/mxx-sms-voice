<?php

require __DIR__ . '/../bootstrap/cli.php';

use WilliamCosta\DatabaseManager\Database;

$db = new Database();

$db->execute(
    "UPDATE whatsapp_meta_message_prices
     SET active = 0, updated_at = NOW()
     WHERE country_code = 'BR'
       AND message_type IN ('marketing', 'utility', 'authentication')
       AND active = 1
       AND (
           valid_from <> '2026-04-01'
           OR (message_type = 'marketing' AND price_usd <> 0.071880)
           OR (message_type IN ('utility', 'authentication') AND price_usd <> 0.007820)
       )"
);

$prices = [
    'marketing' => 0.071880,
    'utility' => 0.007820,
    'authentication' => 0.007820,
];

foreach ($prices as $messageType => $priceUsd) {
    $db->execute(
        "INSERT INTO whatsapp_meta_message_prices
            (country_code, message_type, price_usd, valid_from, active, created_at, updated_at)
         VALUES ('BR', :message_type, :price_usd, '2026-04-01', 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            price_usd = VALUES(price_usd),
            active = VALUES(active),
            updated_at = NOW()",
        [
            ':message_type' => $messageType,
            ':price_usd' => $priceUsd,
        ]
    );
}

echo "whatsapp_meta_pricing_policy_ok\n";
