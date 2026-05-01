INSERT IGNORE INTO tenancies (
    id,
    name,
    tenancy_phone,
    status,
    active_plan_id,
    account_code,
    timezone
) VALUES (
    '00000000-0000-4000-8000-000000000001',
    'Site Tests',
    NULL,
    'active',
    NULL,
    'SITE_TEST',
    'America/Sao_Paulo'
);

ALTER TABLE cdr
    MODIFY campaign_type ENUM('torpedo','voice','test_voice') NULL;
