<?php

namespace App\Service;

use WilliamCosta\DatabaseManager\Database;

class WhatsAppTemplateBlueprintLibrary
{
    public static function ensureSchema(): void
    {
        (new Database())->execute(
            "CREATE TABLE IF NOT EXISTS whatsapp_template_blueprints (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                blueprint_key VARCHAR(120) NOT NULL,
                name VARCHAR(120) NOT NULL,
                category ENUM('MARKETING', 'UTILITY', 'AUTHENTICATION') NOT NULL,
                language VARCHAR(10) NOT NULL DEFAULT 'pt_BR',
                body TEXT NOT NULL,
                variables_json JSON NULL,
                components JSON NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_whatsapp_template_blueprint_key (blueprint_key),
                KEY idx_whatsapp_template_blueprints_lookup (active, category, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function seedDefaults(): void
    {
        self::ensureSchema();
        foreach (self::defaults() as $index => $definition) {
            (new Database())->execute(
                "INSERT INTO whatsapp_template_blueprints
                    (blueprint_key, name, category, language, body, variables_json, components, active, sort_order, created_at, updated_at)
                 VALUES
                    (:blueprint_key, :name, :category, :language, :body, :variables_json, :components, 1, :sort_order, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    category = VALUES(category),
                    language = VALUES(language),
                    body = VALUES(body),
                    variables_json = VALUES(variables_json),
                    components = VALUES(components),
                    active = 1,
                    sort_order = VALUES(sort_order),
                    updated_at = NOW()",
                [
                    ':blueprint_key' => $definition['key'],
                    ':name' => $definition['name'],
                    ':category' => $definition['category'],
                    ':language' => $definition['language'],
                    ':body' => $definition['body'],
                    ':variables_json' => json_encode($definition['variables'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':components' => json_encode(self::componentsFor($definition), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':sort_order' => ($index + 1) * 10,
                ]
            );
        }
    }

    public static function list(?string $category = null): array
    {
        self::seedDefaults();
        $where = 'active = 1';
        $params = [];
        $category = strtoupper(trim((string)$category));
        if (in_array($category, ['MARKETING', 'UTILITY', 'AUTHENTICATION'], true)) {
            $where .= ' AND category = :category';
            $params[':category'] = $category;
        }

        $rows = (new Database('whatsapp_template_blueprints'))
            ->select($where, $params, 'category ASC, sort_order ASC, name ASC')
            ->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map([self::class, 'normalizeRow'], $rows);
    }

    private static function normalizeRow(array $row): array
    {
        $variables = self::jsonArray($row['variables_json'] ?? null);
        $components = self::jsonArray($row['components'] ?? null);

        return [
            'id' => 'blueprint_' . (int)$row['id'],
            'blueprint_id' => (int)$row['id'],
            'key' => (string)$row['blueprint_key'],
            'source' => 'blueprint',
            'source_label' => 'Modelo pronto',
            'name' => (string)$row['name'],
            'suggested_name' => (string)$row['name'],
            'language' => (string)($row['language'] ?: 'pt_BR'),
            'category' => strtoupper((string)$row['category']),
            'header' => null,
            'body' => (string)$row['body'],
            'footer' => null,
            'buttons' => [],
            'variables' => $variables,
            'variable_map' => self::variableMap($variables),
            'components' => $components ?: self::componentsFor([
                'body' => (string)$row['body'],
                'variables' => $variables,
            ]),
        ];
    }

    private static function componentsFor(array $definition): array
    {
        $body = (string)($definition['body'] ?? '');
        $variables = is_array($definition['variables'] ?? null) ? $definition['variables'] : [];
        $examples = [];
        foreach ($variables as $variable) {
            $examples[] = self::exampleFor($variable);
        }

        $component = [
            'type' => 'BODY',
            'text' => $body,
        ];

        if ($examples !== []) {
            $component['example'] = [
                'body_text' => [$examples],
            ];
        }

        return [$component];
    }

    private static function exampleFor(array $variable): string
    {
        $key = (string)($variable['key'] ?? '');
        return match ($key) {
            'nome_cliente' => 'Ana',
            'link', 'link_checkout', 'link_confirmacao', 'link_pesquisa', 'link_compra', 'link_agendamento', 'link_acompanhamento', 'link_rastreio', 'link_boleto', 'link_suporte', 'link_documento', 'link_detalhes' => 'https://exemplo.com.br',
            'validade', 'prazo', 'data_evento', 'data', 'data_entrega', 'vencimento' => '31/12/2026',
            'hora' => '14:30',
            'valor', 'valor_plano' => 'R$ 99,90',
            'cupom' => 'PROMO10',
            'numero_pedido' => '12345',
            'protocolo_atendimento', 'protocolo', 'ticket_protocol', 'ticket.protocol' => 'ATD-12345',
            'codigo_rastreio' => 'BR123456789',
            'numero_chamado' => 'SUP-12345',
            'status' => 'confirmado',
            default => 'exemplo',
        };
    }

    private static function variableMap(array $variables): array
    {
        $map = [];
        foreach ($variables as $variable) {
            $position = (string)(int)($variable['position'] ?? 0);
            if ($position === '0') {
                continue;
            }

            $map[$position] = [
                'key' => (string)($variable['key'] ?? 'variavel_' . $position),
                'label' => (string)($variable['label'] ?? 'Variável ' . $position),
                'description' => (string)($variable['description'] ?? $variable['label'] ?? 'Variável ' . $position),
                'type' => (string)($variable['type'] ?? 'text'),
                'source' => (string)($variable['source'] ?? 'contact_or_sheet'),
            ];
        }

        return $map;
    }

    private static function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function v(
        int $position,
        string $key,
        string $label,
        string $type = 'text',
        string $source = 'contact_or_sheet',
        ?string $description = null
    ): array
    {
        return [
            'position' => $position,
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'source' => $source,
            'description' => $description ?: $label,
        ];
    }

    public static function defaults(): array
    {
        return [
            [
                'key' => 'marketing_maxx_solutions_multicanal',
                'name' => 'marketing_maxx_solutions_multicanal',
                'category' => 'MARKETING',
                'language' => 'pt_BR',
                'body' => "Olá {{1}},\n\nQuer reduzir custos e profissionalizar seu atendimento? 🚀\n\nConheça nossa solução completa:\n📞 Telefonia VoIP\n📩 Disparo de SMS\n💬 Atendimento via WhatsApp\n\nTudo integrado em uma única plataforma!\n\n💰 Planos a partir de {{2}}\n\nFale com a gente e ative agora:\n👉 {{3}}\n\nSe não quiser receber mensagens, responda SAIR.",
                'variables' => [
                    self::v(1, 'nome_cliente', 'Nome do cliente'),
                    self::v(2, 'valor_plano', 'Valor do plano', 'currency'),
                    self::v(3, 'link_ativacao', 'Link de ativação'),
                ],
            ],
            ['key' => 'marketing_oferta_especial', 'name' => 'marketing_oferta_especial', 'category' => 'MARKETING', 'language' => 'pt_BR', 'body' => 'Olá {{1}}! Temos uma oferta especial para você: {{2}}. Aproveite até {{3}} pelo link: {{4}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'oferta', 'Oferta'), self::v(3, 'validade', 'Validade'), self::v(4, 'link', 'Link')]],
            ['key' => 'marketing_cupom_desconto', 'name' => 'marketing_cupom_desconto', 'category' => 'MARKETING', 'language' => 'pt_BR', 'body' => 'Oi {{1}}, seu cupom {{2}} está disponível! Use até {{3}} e aproveite: {{4}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'cupom', 'Cupom'), self::v(3, 'validade', 'Validade'), self::v(4, 'link', 'Link')]],
            ['key' => 'marketing_carrinho_abandonado', 'name' => 'marketing_carrinho_abandonado', 'category' => 'MARKETING', 'language' => 'pt_BR', 'body' => 'Oi {{1}}, você deixou {{2}} no carrinho. Finalize sua compra aqui: {{3}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'produto', 'Produto'), self::v(3, 'link_checkout', 'Link do checkout')]],
            ['key' => 'marketing_lancamento_produto', 'name' => 'marketing_lancamento_produto', 'category' => 'MARKETING', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, temos uma novidade: {{2}}. Confira os detalhes aqui: {{3}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'produto', 'Produto/serviço'), self::v(3, 'link', 'Link')]],
            ['key' => 'marketing_reativacao_cliente', 'name' => 'marketing_reativacao_cliente', 'category' => 'MARKETING', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, sentimos sua falta! Preparamos uma condição especial para você: {{2}}. Veja aqui: {{3}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'condicao', 'Condição especial'), self::v(3, 'link', 'Link')]],
            ['key' => 'marketing_black_friday', 'name' => 'marketing_black_friday', 'category' => 'MARKETING', 'language' => 'pt_BR', 'body' => '{{1}}, a campanha {{2}} começou! Confira as ofertas disponíveis até {{3}}: {{4}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'nome_campanha', 'Nome da campanha'), self::v(3, 'validade', 'Validade'), self::v(4, 'link', 'Link')]],
            ['key' => 'marketing_convite_evento', 'name' => 'marketing_convite_evento', 'category' => 'MARKETING', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, você está convidado para {{2}} no dia {{3}}. Confirme sua presença: {{4}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'evento', 'Evento'), self::v(3, 'data_evento', 'Data do evento'), self::v(4, 'link_confirmacao', 'Link de confirmação')]],
            ['key' => 'marketing_venda_cruzada', 'name' => 'marketing_venda_cruzada', 'category' => 'MARKETING', 'language' => 'pt_BR', 'body' => 'Oi {{1}}, com base no seu interesse em {{2}}, recomendamos {{3}}. Veja aqui: {{4}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'produto_interesse', 'Produto de interesse'), self::v(3, 'recomendacao', 'Recomendação'), self::v(4, 'link', 'Link')]],
            ['key' => 'marketing_pesquisa_satisfacao', 'name' => 'marketing_pesquisa_satisfacao', 'category' => 'MARKETING', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, queremos saber sua opinião sobre {{2}}. Responda a pesquisa aqui: {{3}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'assunto', 'Assunto'), self::v(3, 'link_pesquisa', 'Link da pesquisa')]],
            ['key' => 'marketing_campanha_sazonal', 'name' => 'marketing_campanha_sazonal', 'category' => 'MARKETING', 'language' => 'pt_BR', 'body' => '{{1}}, a campanha de {{2}} já está disponível. Confira as condições: {{3}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'tema_campanha', 'Tema da campanha'), self::v(3, 'link', 'Link')]],
            ['key' => 'marketing_ultimas_unidades', 'name' => 'marketing_ultimas_unidades', 'category' => 'MARKETING', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, restam poucas unidades de {{2}}. Garanta o seu antes que acabe: {{3}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'produto', 'Produto'), self::v(3, 'link_compra', 'Link de compra')]],
            ['key' => 'marketing_beneficio_exclusivo', 'name' => 'marketing_beneficio_exclusivo', 'category' => 'MARKETING', 'language' => 'pt_BR', 'body' => '{{1}}, você tem acesso a um benefício exclusivo: {{2}}. Saiba mais: {{3}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'beneficio', 'Benefício'), self::v(3, 'link', 'Link')]],
            ['key' => 'marketing_lembrete_promocao', 'name' => 'marketing_lembrete_promocao', 'category' => 'MARKETING', 'language' => 'pt_BR', 'body' => 'Oi {{1}}, lembrando que a promoção {{2}} termina em {{3}}. Acesse: {{4}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'promocao', 'Promoção'), self::v(3, 'prazo', 'Prazo'), self::v(4, 'link', 'Link')]],
            ['key' => 'marketing_agendamento_comercial', 'name' => 'marketing_agendamento_comercial', 'category' => 'MARKETING', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, podemos te apresentar {{2}} em uma conversa rápida. Escolha um horário aqui: {{3}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'solucao', 'Solução/oferta'), self::v(3, 'link_agendamento', 'Link de agendamento')]],
            ['key' => 'marketing_indicacao', 'name' => 'marketing_indicacao', 'category' => 'MARKETING', 'language' => 'pt_BR', 'body' => '{{1}}, indique um amigo e receba {{2}}. Veja como participar: {{3}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'recompensa', 'Recompensa'), self::v(3, 'link', 'Link')]],
            ['key' => 'utility_confirmacao_pedido', 'name' => 'utility_confirmacao_pedido', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, seu pedido {{2}} foi confirmado com sucesso. Acompanhe aqui: {{3}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'numero_pedido', 'Número do pedido'), self::v(3, 'link_acompanhamento', 'Link de acompanhamento')]],
            ['key' => 'utility_protocolo_atendimento', 'name' => 'util_protocolo_atendimento_01', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, seu protocolo de atendimento é: {{2}}.', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente', 'text', 'system', 'Preenchido automaticamente pelo sistema no envio do protocolo.'), self::v(2, 'protocolo_atendimento', 'Protocolo do atendimento', 'text', 'system', 'Gerado automaticamente pelo sistema no momento do atendimento.')]],
            ['key' => 'utility_status_pedido', 'name' => 'utility_status_pedido', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, o status do pedido {{2}} foi atualizado para: {{3}}.', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'numero_pedido', 'Número do pedido'), self::v(3, 'status', 'Status')]],
            ['key' => 'utility_envio_rastreamento', 'name' => 'utility_envio_rastreamento', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, seu pedido {{2}} foi enviado. Código de rastreio: {{3}}. Acompanhe: {{4}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'numero_pedido', 'Número do pedido'), self::v(3, 'codigo_rastreio', 'Código de rastreio'), self::v(4, 'link_rastreio', 'Link de rastreio')]],
            ['key' => 'utility_pagamento_confirmado', 'name' => 'utility_pagamento_confirmado', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, recebemos o pagamento de {{2}} referente a {{3}}. Obrigado!', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'valor', 'Valor'), self::v(3, 'referencia', 'Referência')]],
            ['key' => 'utility_boleto_disponivel', 'name' => 'utility_boleto_disponivel', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, seu boleto referente a {{2}} está disponível com vencimento em {{3}}. Acesse: {{4}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'referencia', 'Referência'), self::v(3, 'vencimento', 'Vencimento'), self::v(4, 'link_boleto', 'Link do boleto')]],
            ['key' => 'utility_lembrete_vencimento', 'name' => 'utility_lembrete_vencimento', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, lembramos que {{2}} vence em {{3}}. Acesse os detalhes aqui: {{4}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'descricao', 'Descrição'), self::v(3, 'vencimento', 'Vencimento'), self::v(4, 'link', 'Link')]],
            ['key' => 'utility_agendamento_confirmado', 'name' => 'utility_agendamento_confirmado', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, seu agendamento para {{2}} foi confirmado para {{3}} às {{4}}.', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'servico', 'Serviço'), self::v(3, 'data', 'Data'), self::v(4, 'hora', 'Hora')]],
            ['key' => 'utility_lembrete_agendamento', 'name' => 'utility_lembrete_agendamento', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, lembramos do seu agendamento de {{2}} em {{3}} às {{4}}.', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'servico', 'Serviço'), self::v(3, 'data', 'Data'), self::v(4, 'hora', 'Hora')]],
            ['key' => 'utility_cancelamento_agendamento', 'name' => 'utility_cancelamento_agendamento', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, seu agendamento de {{2}} em {{3}} foi cancelado. Detalhes: {{4}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'servico', 'Serviço'), self::v(3, 'data', 'Data'), self::v(4, 'link', 'Link de detalhes')]],
            ['key' => 'utility_atualizacao_cadastro', 'name' => 'utility_atualizacao_cadastro', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, houve uma atualização no seu cadastro: {{2}}. Caso não reconheça, acesse: {{3}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'descricao_atualizacao', 'Atualização'), self::v(3, 'link_suporte', 'Link de suporte')]],
            ['key' => 'utility_documento_disponivel', 'name' => 'utility_documento_disponivel', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, o documento {{2}} está disponível. Acesse aqui: {{3}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'nome_documento', 'Documento'), self::v(3, 'link_documento', 'Link do documento')]],
            ['key' => 'utility_chamado_aberto', 'name' => 'utility_chamado_aberto', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, seu chamado {{2}} foi aberto com sucesso. Status atual: {{3}}.', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'numero_chamado', 'Número do chamado'), self::v(3, 'status', 'Status')]],
            ['key' => 'utility_chamado_atualizado', 'name' => 'utility_chamado_atualizado', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, o chamado {{2}} recebeu uma atualização: {{3}}.', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'numero_chamado', 'Número do chamado'), self::v(3, 'atualizacao', 'Atualização')]],
            ['key' => 'utility_acesso_sistema', 'name' => 'utility_acesso_sistema', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, seu acesso ao sistema {{2}} foi atualizado. Detalhes: {{3}}', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'sistema', 'Sistema'), self::v(3, 'link_detalhes', 'Link de detalhes')]],
            ['key' => 'utility_entrega_realizada', 'name' => 'utility_entrega_realizada', 'category' => 'UTILITY', 'language' => 'pt_BR', 'body' => 'Olá {{1}}, o pedido {{2}} foi entregue em {{3}}. Obrigado por comprar conosco.', 'variables' => [self::v(1, 'nome_cliente', 'Nome do cliente'), self::v(2, 'numero_pedido', 'Número do pedido'), self::v(3, 'data_entrega', 'Data de entrega')]],
        ];
    }
}
