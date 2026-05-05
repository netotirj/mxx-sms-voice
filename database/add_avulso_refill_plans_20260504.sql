-- Planos avulsos abaixo do Basic.
-- Mantem o checkout atual por plan_id e evita valor livre sem auditoria.

DELETE FROM mxx_plans WHERE id BETWEEN 25 AND 36;

INSERT INTO mxx_plans (
    id,
    name_plan,
    description,
    `desc`,
    amount_plan,
    value_sms,
    value_whatsapp,
    value_whatsapp_marketing,
    value_whatsapp_utility,
    value_whatsapp_authentication,
    value_torpedo,
    value_voice,
    simultaneous_access,
    users_create,
    camp_qtd,
    rports,
    type_plan,
    payment_type,
    status,
    is_popular,
    service_fee
) VALUES
    (25, 'Avulso 50',  'Recarga avulsa de R$ 50',  'Recarga avulsa de R$ 50',  '50',  '0.2500', '0.7500', '0.7500', '0.2000', '0.2000', '0.3000', '0.1300', 1, 'n', 1, 'Relatórios em Tempo Real', 'sms',      'Pré-Pago', 'active', 0, '0.0000'),
    (26, 'Avulso 50',  'Recarga avulsa de R$ 50',  'Recarga avulsa de R$ 50',  '50',  '0.2500', '0.7500', '0.7500', '0.2000', '0.2000', '0.3000', '0.1300', 1, 'n', 1, 'Relatórios em Tempo Real', 'voice',    'Pré-Pago', 'active', 0, '0.0000'),
    (27, 'Avulso 50',  'Recarga avulsa de R$ 50',  'Recarga avulsa de R$ 50',  '50',  '0.2500', '0.7500', '0.7500', '0.2000', '0.2000', '0.3000', '0.1300', 1, 'n', 1, 'Relatórios em Tempo Real', 'torpedo',  'Pré-Pago', 'active', 0, '0.0000'),
    (28, 'Avulso 50',  'Recarga avulsa de R$ 50',  'Recarga avulsa de R$ 50',  '50',  '0.2500', '0.7500', '0.7500', '0.2000', '0.2000', '0.3000', '0.1300', 1, 'n', 1, 'Relatórios em Tempo Real', 'whatsapp', 'Pré-Pago', 'active', 0, '0.0000'),

    (29, 'Avulso 100', 'Recarga avulsa de R$ 100', 'Recarga avulsa de R$ 100', '100', '0.2400', '0.7000', '0.7000', '0.1800', '0.1800', '0.2800', '0.1200', 1, 'n', 3, 'Relatórios em Tempo Real', 'sms',      'Pré-Pago', 'active', 0, '0.0000'),
    (30, 'Avulso 100', 'Recarga avulsa de R$ 100', 'Recarga avulsa de R$ 100', '100', '0.2400', '0.7000', '0.7000', '0.1800', '0.1800', '0.2800', '0.1200', 1, 'n', 3, 'Relatórios em Tempo Real', 'voice',    'Pré-Pago', 'active', 0, '0.0000'),
    (31, 'Avulso 100', 'Recarga avulsa de R$ 100', 'Recarga avulsa de R$ 100', '100', '0.2400', '0.7000', '0.7000', '0.1800', '0.1800', '0.2800', '0.1200', 1, 'n', 3, 'Relatórios em Tempo Real', 'torpedo',  'Pré-Pago', 'active', 0, '0.0000'),
    (32, 'Avulso 100', 'Recarga avulsa de R$ 100', 'Recarga avulsa de R$ 100', '100', '0.2400', '0.7000', '0.7000', '0.1800', '0.1800', '0.2800', '0.1200', 1, 'n', 3, 'Relatórios em Tempo Real', 'whatsapp', 'Pré-Pago', 'active', 0, '0.0000'),

    (33, 'Avulso 150', 'Recarga avulsa de R$ 150', 'Recarga avulsa de R$ 150', '150', '0.2300', '0.6500', '0.6500', '0.1600', '0.1600', '0.2600', '0.1100', 1, 'n', 5, 'Relatórios em Tempo Real', 'sms',      'Pré-Pago', 'active', 0, '0.0000'),
    (34, 'Avulso 150', 'Recarga avulsa de R$ 150', 'Recarga avulsa de R$ 150', '150', '0.2300', '0.6500', '0.6500', '0.1600', '0.1600', '0.2600', '0.1100', 1, 'n', 5, 'Relatórios em Tempo Real', 'voice',    'Pré-Pago', 'active', 0, '0.0000'),
    (35, 'Avulso 150', 'Recarga avulsa de R$ 150', 'Recarga avulsa de R$ 150', '150', '0.2300', '0.6500', '0.6500', '0.1600', '0.1600', '0.2600', '0.1100', 1, 'n', 5, 'Relatórios em Tempo Real', 'torpedo',  'Pré-Pago', 'active', 0, '0.0000'),
    (36, 'Avulso 150', 'Recarga avulsa de R$ 150', 'Recarga avulsa de R$ 150', '150', '0.2300', '0.6500', '0.6500', '0.1600', '0.1600', '0.2600', '0.1100', 1, 'n', 5, 'Relatórios em Tempo Real', 'whatsapp', 'Pré-Pago', 'active', 0, '0.0000');

DELETE FROM plan_whatsapp_pricing WHERE plan_id BETWEEN 25 AND 36;

INSERT INTO plan_whatsapp_pricing (
    plan_id,
    category,
    price_brl,
    created_at,
    updated_at
)
SELECT id, 'marketing', value_whatsapp_marketing, NOW(), NOW()
FROM mxx_plans
WHERE id BETWEEN 25 AND 36
UNION ALL
SELECT id, 'utility', value_whatsapp_utility, NOW(), NOW()
FROM mxx_plans
WHERE id BETWEEN 25 AND 36
UNION ALL
SELECT id, 'authentication', value_whatsapp_authentication, NOW(), NOW()
FROM mxx_plans
WHERE id BETWEEN 25 AND 36;
