START TRANSACTION;

CREATE TABLE IF NOT EXISTS template_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_id INT UNSIGNED NOT NULL,
    antes TEXT NULL,
    depois TEXT NULL,
    motivo_correcao TEXT NOT NULL,
    score TINYINT UNSIGNED NOT NULL DEFAULT 0,
    data DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_template_logs_template (template_id, data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE whatsapp_templates
    MODIFY status ENUM(
        'draft',
        'pending',
        'approved',
        'rejected',
        'paused',
        'disabled',
        'READY_TO_SUBMIT',
        'REVIEW_MANUAL'
    ) NOT NULL DEFAULT 'pending';

INSERT INTO template_logs (template_id, antes, depois, motivo_correcao, score, data)
SELECT
    id,
    body,
    CASE name
        WHEN 'util_confirmacao_atendimento_01'
            THEN 'Olá {{1}}, registramos seu atendimento {{2}} para acompanhamento da sua solicitação.'
        WHEN 'util_lembrete_agendamento_01'
            THEN 'Olá {{1}}, seu agendamento {{2}} está confirmado para {{3}}.'
        WHEN 'mkt_comunicado_servicos_01'
            THEN 'Olá {{1}}, temos uma atualização sobre {{2}} relacionada aos serviços da Maxx Solutions.'
        WHEN 'mkt_novidades_empresa_01'
            THEN 'Olá {{1}}, temos informações sobre {{2}} que podem ajudar no uso dos serviços da Maxx Solutions.'
        ELSE body
    END,
    CASE name
        WHEN 'util_confirmacao_atendimento_01'
            THEN 'Score 94. Utility mantido; removida frase automática e reforçado contexto de atendimento/protocolo.'
        WHEN 'util_lembrete_agendamento_01'
            THEN 'Score 96. Utility mantido; removida frase automática e texto tornado direto sobre agendamento confirmado.'
        WHEN 'mkt_comunicado_servicos_01'
            THEN 'Score 88. Marketing mantido; removida chamada genérica para canal oficial e incluído contexto de serviços.'
        WHEN 'mkt_novidades_empresa_01'
            THEN 'Score 84. Marketing mantido; removidas expressões vagas como novidades/saiba mais e incluído valor claro.'
        ELSE 'Score 70. Sem regra automática específica.'
    END,
    CASE name
        WHEN 'util_confirmacao_atendimento_01' THEN 94
        WHEN 'util_lembrete_agendamento_01' THEN 96
        WHEN 'mkt_comunicado_servicos_01' THEN 88
        WHEN 'mkt_novidades_empresa_01' THEN 84
        ELSE 70
    END,
    NOW()
FROM whatsapp_templates
WHERE LOWER(status) IN ('pending', 'rejected')
  AND name IN (
      'util_confirmacao_atendimento_01',
      'util_lembrete_agendamento_01',
      'mkt_comunicado_servicos_01',
      'mkt_novidades_empresa_01'
  );

UPDATE whatsapp_templates
SET
    category = 'UTILITY',
    body = 'Olá {{1}}, registramos seu atendimento {{2}} para acompanhamento da sua solicitação.',
    components = JSON_ARRAY(
        JSON_OBJECT(
            'type', 'BODY',
            'text', 'Olá {{1}}, registramos seu atendimento {{2}} para acompanhamento da sua solicitação.',
            'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Maria', 'ATD-12345')))
        )
    ),
    meta_payload = JSON_SET(
        COALESCE(meta_payload, JSON_OBJECT()),
        '$.quality_score', 94,
        '$.validation_summary', 'Utility transacional com contexto de atendimento.',
        '$.meta_payload_template', JSON_OBJECT(
            'name', name,
            'language', language,
            'category', 'UTILITY',
            'components', JSON_ARRAY(
                JSON_OBJECT(
                    'type', 'BODY',
                    'text', 'Olá {{1}}, registramos seu atendimento {{2}} para acompanhamento da sua solicitação.',
                    'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Maria', 'ATD-12345')))
                )
            )
        )
    ),
    status = 'READY_TO_SUBMIT',
    template_last_error = NULL,
    updated_at = NOW()
WHERE LOWER(status) IN ('pending', 'rejected')
  AND name = 'util_confirmacao_atendimento_01';

UPDATE whatsapp_templates
SET
    category = 'UTILITY',
    body = 'Olá {{1}}, seu agendamento {{2}} está confirmado para {{3}}.',
    components = JSON_ARRAY(
        JSON_OBJECT(
            'type', 'BODY',
            'text', 'Olá {{1}}, seu agendamento {{2}} está confirmado para {{3}}.',
            'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Maria', 'AG-45678', '10/05/2026 às 14:00')))
        )
    ),
    meta_payload = JSON_SET(
        COALESCE(meta_payload, JSON_OBJECT()),
        '$.quality_score', 96,
        '$.validation_summary', 'Utility transacional com contexto claro de agendamento.',
        '$.meta_payload_template', JSON_OBJECT(
            'name', name,
            'language', language,
            'category', 'UTILITY',
            'components', JSON_ARRAY(
                JSON_OBJECT(
                    'type', 'BODY',
                    'text', 'Olá {{1}}, seu agendamento {{2}} está confirmado para {{3}}.',
                    'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Maria', 'AG-45678', '10/05/2026 às 14:00')))
                )
            )
        )
    ),
    status = 'READY_TO_SUBMIT',
    template_last_error = NULL,
    updated_at = NOW()
WHERE LOWER(status) IN ('pending', 'rejected')
  AND name = 'util_lembrete_agendamento_01';

UPDATE whatsapp_templates
SET
    category = 'MARKETING',
    body = 'Olá {{1}}, temos uma atualização sobre {{2}} relacionada aos serviços da Maxx Solutions.',
    components = JSON_ARRAY(
        JSON_OBJECT(
            'type', 'BODY',
            'text', 'Olá {{1}}, temos uma atualização sobre {{2}} relacionada aos serviços da Maxx Solutions.',
            'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Maria', 'atendimento digital')))
        ),
        JSON_OBJECT(
            'type', 'FOOTER',
            'text', 'Responda PARAR para sair da lista.'
        )
    ),
    meta_payload = JSON_SET(
        COALESCE(meta_payload, JSON_OBJECT()),
        '$.quality_score', 88,
        '$.validation_summary', 'Marketing com contexto de serviços e opt-out.',
        '$.meta_payload_template', JSON_OBJECT(
            'name', name,
            'language', language,
            'category', 'MARKETING',
            'components', JSON_ARRAY(
                JSON_OBJECT(
                    'type', 'BODY',
                    'text', 'Olá {{1}}, temos uma atualização sobre {{2}} relacionada aos serviços da Maxx Solutions.',
                    'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Maria', 'atendimento digital')))
                ),
                JSON_OBJECT(
                    'type', 'FOOTER',
                    'text', 'Responda PARAR para sair da lista.'
                )
            )
        )
    ),
    status = 'READY_TO_SUBMIT',
    template_last_error = NULL,
    updated_at = NOW()
WHERE LOWER(status) IN ('pending', 'rejected')
  AND name = 'mkt_comunicado_servicos_01';

UPDATE whatsapp_templates
SET
    category = 'MARKETING',
    body = 'Olá {{1}}, temos informações sobre {{2}} que podem ajudar no uso dos serviços da Maxx Solutions.',
    components = JSON_ARRAY(
        JSON_OBJECT(
            'type', 'BODY',
            'text', 'Olá {{1}}, temos informações sobre {{2}} que podem ajudar no uso dos serviços da Maxx Solutions.',
            'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Maria', 'novos recursos')))
        ),
        JSON_OBJECT(
            'type', 'FOOTER',
            'text', 'Responda PARAR para sair da lista.'
        )
    ),
    meta_payload = JSON_SET(
        COALESCE(meta_payload, JSON_OBJECT()),
        '$.quality_score', 84,
        '$.validation_summary', 'Marketing com valor claro, sem chamada vaga, e opt-out.',
        '$.meta_payload_template', JSON_OBJECT(
            'name', name,
            'language', language,
            'category', 'MARKETING',
            'components', JSON_ARRAY(
                JSON_OBJECT(
                    'type', 'BODY',
                    'text', 'Olá {{1}}, temos informações sobre {{2}} que podem ajudar no uso dos serviços da Maxx Solutions.',
                    'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Maria', 'novos recursos')))
                ),
                JSON_OBJECT(
                    'type', 'FOOTER',
                    'text', 'Responda PARAR para sair da lista.'
                )
            )
        )
    ),
    status = 'READY_TO_SUBMIT',
    template_last_error = NULL,
    updated_at = NOW()
WHERE LOWER(status) IN ('pending', 'rejected')
  AND name = 'mkt_novidades_empresa_01';

COMMIT;
