<?php

require dirname(__DIR__) . '/bootstrap/cli.php';

$db = new WilliamCosta\DatabaseManager\Database('tenancies');

$db->execute(
    'INSERT IGNORE INTO tenancies (id, name, tenancy_phone, status, active_plan_id, account_code, timezone)
     VALUES (:id, :name, NULL, :status, NULL, :account_code, :timezone)',
    [
        ':id' => '00000000-0000-4000-8000-000000000001',
        ':name' => 'Site Tests',
        ':status' => 'active',
        ':account_code' => 'SITE_TEST',
        ':timezone' => 'America/Sao_Paulo',
    ]
);

$db->execute("ALTER TABLE cdr MODIFY campaign_type ENUM('torpedo','voice','test_voice') NULL");

echo "site test tenancy and cdr enum ok\n";
