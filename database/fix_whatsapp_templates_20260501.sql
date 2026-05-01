START TRANSACTION;

CREATE TABLE IF NOT EXISTS whatsapp_templates_backup_20260501_codex AS
SELECT * FROM whatsapp_templates;

DELETE FROM whatsapp_templates
WHERE tenancy_id = '31948c42-58de-476b-bf4a-4f5098130aaa'
  AND account_id = 14
  AND waba_id = '2695577467490595'
  AND status <> 'approved'
  AND name IN (
      'site_test_notification',
      'util_site_teste_20260501',
      'util_pedido_status_20260501',
      'util_boleto_disponivel_20260501',
      'util_pagamento_pendente_20260501'
  );

INSERT INTO whatsapp_templates
(
    tenancy_id,
    user_id,
    account_id,
    waba_id,
    meta_template_id,
    name,
    language,
    category,
    body,
    components,
    variable_map,
    is_system_template,
    template_type,
    status,
    template_submitted_at,
    template_approved_at,
    template_rejected_at,
    template_last_sync_at,
    template_last_error,
    meta_payload,
    created_at,
    updated_at
)
VALUES
(
    '31948c42-58de-476b-bf4a-4f5098130aaa',
    10,
    14,
    '2695577467490595',
    NULL,
    'site_test_notification',
    'pt_BR',
    'MARKETING',
    'Olá {{1}}, este é um teste da plataforma Maxx Solutions.',
    JSON_ARRAY(
        JSON_OBJECT(
            'type', 'BODY',
            'text', 'Olá {{1}}, este é um teste da plataforma Maxx Solutions.',
            'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Maxx Solutions')))
        )
    ),
    JSON_OBJECT(
        '1', JSON_OBJECT(
            'key', 'nome_teste',
            'example', 'Maxx Solutions',
            'description', 'Nome exibido na mensagem de teste solicitada pelo visitante'
        )
    ),
    1,
    'system',
    'draft',
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    JSON_OBJECT(
        'system_default_template', true,
        'approval_note', 'Template de teste solicitado pelo visitante no site publico.',
        'semantic_variable_map', JSON_OBJECT(
            '1', JSON_OBJECT(
                'key', 'nome_teste',
                'example', 'Maxx Solutions',
                'description', 'Nome exibido na mensagem de teste solicitada pelo visitante'
            )
        ),
        'meta_payload_template', JSON_OBJECT(
            'name', 'site_test_notification',
            'language', 'pt_BR',
            'category', 'MARKETING',
            'components', JSON_ARRAY(
                JSON_OBJECT(
                    'type', 'BODY',
                    'text', 'Olá {{1}}, este é um teste da plataforma Maxx Solutions.',
                    'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Maxx Solutions')))
                )
            )
        ),
        'submitted', false,
        'reason', 'Enviar para aprovação Meta para habilitar o teste publico do site.'
    ),
    NOW(),
    NOW()
),
(
    '31948c42-58de-476b-bf4a-4f5098130aaa',
    10,
    14,
    '2695577467490595',
    NULL,
    'util_pedido_status_20260501',
    'pt_BR',
    'UTILITY',
    'Olá {{1}}, o status do pedido {{2}} foi atualizado para {{3}}. Esta é uma notificação automática de acompanhamento.',
    JSON_ARRAY(
        JSON_OBJECT(
            'type', 'BODY',
            'text', 'Olá {{1}}, o status do pedido {{2}} foi atualizado para {{3}}. Esta é uma notificação automática de acompanhamento.',
            'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Maria', 'PED-12345', 'em separação')))
        )
    ),
    JSON_OBJECT(
        '1', JSON_OBJECT('key', 'nome_cliente', 'example', 'Maria', 'description', 'Nome do cliente'),
        '2', JSON_OBJECT('key', 'numero_pedido', 'example', 'PED-12345', 'description', 'Identificador do pedido'),
        '3', JSON_OBJECT('key', 'status_pedido', 'example', 'em separação', 'description', 'Novo status do pedido')
    ),
    1,
    'system',
    'draft',
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    JSON_OBJECT(
        'system_default_template', true,
        'approval_note', 'Atualizacao operacional de status de pedido existente.',
        'submitted', false,
        'reason', 'Template utility transacional para atualização de pedido.'
    ),
    NOW(),
    NOW()
),
(
    '31948c42-58de-476b-bf4a-4f5098130aaa',
    10,
    14,
    '2695577467490595',
    NULL,
    'util_boleto_disponivel_20260501',
    'pt_BR',
    'UTILITY',
    'Olá {{1}}, o boleto referente à cobrança {{2}} está disponível. Consulte o documento no painel do cliente ou no canal oficial de atendimento.',
    JSON_ARRAY(
        JSON_OBJECT(
            'type', 'BODY',
            'text', 'Olá {{1}}, o boleto referente à cobrança {{2}} está disponível. Consulte o documento no painel do cliente ou no canal oficial de atendimento.',
            'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Maria', 'COB-98765')))
        )
    ),
    JSON_OBJECT(
        '1', JSON_OBJECT('key', 'nome_cliente', 'example', 'Maria', 'description', 'Nome do cliente'),
        '2', JSON_OBJECT('key', 'cobranca_id', 'example', 'COB-98765', 'description', 'Identificador da cobrança')
    ),
    1,
    'system',
    'draft',
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    JSON_OBJECT(
        'system_default_template', true,
        'approval_note', 'Aviso financeiro transacional sobre documento de cobranca existente.',
        'submitted', false,
        'reason', 'Template utility para boleto disponível.'
    ),
    NOW(),
    NOW()
),
(
    '31948c42-58de-476b-bf4a-4f5098130aaa',
    10,
    14,
    '2695577467490595',
    NULL,
    'util_pagamento_pendente_20260501',
    'pt_BR',
    'UTILITY',
    'Olá {{1}}, identificamos uma cobrança pendente {{2}} com vencimento em {{3}}. Esta é uma notificação automática.',
    JSON_ARRAY(
        JSON_OBJECT(
            'type', 'BODY',
            'text', 'Olá {{1}}, identificamos uma cobrança pendente {{2}} com vencimento em {{3}}. Esta é uma notificação automática.',
            'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Maria', 'COB-98765', '10/05/2026')))
        )
    ),
    JSON_OBJECT(
        '1', JSON_OBJECT('key', 'nome_cliente', 'example', 'Maria', 'description', 'Nome do cliente'),
        '2', JSON_OBJECT('key', 'cobranca_id', 'example', 'COB-98765', 'description', 'Identificador da cobrança'),
        '3', JSON_OBJECT('key', 'data_vencimento', 'example', '10/05/2026', 'description', 'Data de vencimento da cobrança')
    ),
    1,
    'system',
    'draft',
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    JSON_OBJECT(
        'system_default_template', true,
        'approval_note', 'Lembrete factual de pagamento pendente, sem urgencia artificial ou incentivo comercial.',
        'submitted', false,
        'reason', 'Template utility neutro para cobrança pendente.'
    ),
    NOW(),
    NOW()
);

COMMIT;
