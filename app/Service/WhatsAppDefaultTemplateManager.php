<?php

namespace App\Service;

use App\Model\Entity\WhatsAppAccount;
use App\Model\Entity\WhatsAppTemplate;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppDefaultTemplateManager
{
    public const LANGUAGE = 'pt_BR';
    public const CATEGORY_UTILITY = 'UTILITY';
    public const CATEGORY_AUTHENTICATION = 'AUTHENTICATION';
    public const CATEGORY_MARKETING = 'MARKETING';

    private const FORBIDDEN_UTILITY_WORDS = [
        'promoção',
        'promocao',
        'oferta',
        'desconto',
        'aproveite',
        'ganhe',
    ];

    private const LEGACY_UNSAFE_NAMES = [
        'boas_vindas_padrao',
        'campanha_promocional',
        'reativacao_cliente',
        'campanha_informativa',
        'teste_saudacao_01',
    ];

    public static function ensureForUser(array $user): array
    {
        $summary = [
            'created' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach (WhatsAppAccount::listForUser($user) as $listedAccount) {
            $account = WhatsAppAccount::getForUser((int)$listedAccount['id'], $user);
            if (!$account) {
                $summary['skipped']++;
                continue;
            }

            $result = self::ensureForAccount($account);
            foreach (['created', 'failed', 'skipped'] as $key) {
                $summary[$key] += (int)($result[$key] ?? 0);
            }
            $summary['errors'] = array_merge($summary['errors'], $result['errors'] ?? []);
        }

        return $summary;
    }

    public static function ensureForAccount(array $account): array
    {
        $summary = [
            'created' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach (self::definitions() as $definition) {
            $validationErrors = self::validateDefinition($definition);
            if ($validationErrors !== []) {
                $summary['failed']++;
                $summary['errors'][] = [
                    'template' => $definition['name'],
                    'errors' => $validationErrors,
                ];
                continue;
            }

            $name = (string)$definition['name'];
            $existing = WhatsAppTemplate::getByNameForTenant($name, self::LANGUAGE, (string)$account['tenancy_id']);
            $metaPayload = self::metaPayload($definition, $name);
            $data = self::templateRowData($account, $definition, $name, $metaPayload);

            if ($existing && self::isSystemTemplateRow($existing)) {
                self::updateExisting((int)$existing['id'], $data);
                $summary['skipped']++;
                continue;
            }

            if ($existing) {
                $summary['skipped']++;
                continue;
            }

            WhatsAppTemplate::create($data);
            $summary['created']++;
        }

        return $summary;
    }

    public static function definitions(): array
    {
        return [
            [
                'name' => 'util_confirmacao_atendimento_01',
                'category' => self::CATEGORY_UTILITY,
                'body' => 'Olá {{1}}, seu atendimento {{2}} foi registrado em nosso sistema. Esta é uma confirmação automática.',
                'variables' => [
                    1 => ['key' => 'nome_cliente', 'description' => 'Nome do cliente', 'example' => 'Maria'],
                    2 => ['key' => 'protocolo_atendimento', 'description' => 'Identificador do atendimento', 'example' => 'ATD-12345'],
                ],
                'approval_note' => 'Confirmacao transacional de atendimento solicitado pelo cliente, sem conteudo promocional.',
            ],
            [
                'name' => 'util_lembrete_agendamento_01',
                'category' => self::CATEGORY_UTILITY,
                'body' => 'Olá {{1}}, lembramos que seu agendamento {{2}} está marcado para {{3}}. Esta é uma notificação automática.',
                'variables' => [
                    1 => ['key' => 'nome_cliente', 'description' => 'Nome do cliente', 'example' => 'Maria'],
                    2 => ['key' => 'codigo_agendamento', 'description' => 'Identificador do agendamento', 'example' => 'AG-45678'],
                    3 => ['key' => 'data_hora', 'description' => 'Data e hora do agendamento', 'example' => '10/05/2026 às 14:00'],
                ],
                'approval_note' => 'Lembrete operacional de agendamento existente solicitado pelo cliente.',
            ],
            [
                'name' => 'mkt_comunicado_servicos_01',
                'category' => self::CATEGORY_MARKETING,
                'body' => 'Olá {{1}}, temos uma atualização sobre {{2}} que pode ser útil para você. Consulte o canal oficial da empresa.',
                'footer' => 'Responda PARAR para sair da lista.',
                'variables' => [
                    1 => ['key' => 'nome_cliente', 'description' => 'Nome do cliente', 'example' => 'Maria'],
                    2 => ['key' => 'tema_comunicado', 'description' => 'Tema do comunicado', 'example' => 'atendimento digital'],
                ],
                'approval_note' => 'Comunicado de marketing geral com opt-out claro.',
            ],
            [
                'name' => 'mkt_novidades_empresa_01',
                'category' => self::CATEGORY_MARKETING,
                'body' => 'Olá {{1}}, preparamos novidades sobre {{2}}. Acesse nossos canais oficiais para saber mais.',
                'footer' => 'Responda PARAR para sair da lista.',
                'variables' => [
                    1 => ['key' => 'nome_cliente', 'description' => 'Nome do cliente', 'example' => 'Maria'],
                    2 => ['key' => 'assunto_novidade', 'description' => 'Assunto da novidade', 'example' => 'novos recursos'],
                ],
                'approval_note' => 'Mensagem de relacionamento e novidades classificada corretamente como marketing.',
            ],
        ];
    }

    public static function metaPayload(array $definition, ?string $name = null): array
    {
        if (strtoupper((string)$definition['category']) === self::CATEGORY_AUTHENTICATION) {
            return [
                'name' => $name ?: $definition['name'],
                'language' => self::LANGUAGE,
                'category' => self::CATEGORY_AUTHENTICATION,
                'components' => [
                    [
                        'type' => 'BODY',
                        'add_security_recommendation' => true,
                    ],
                    [
                        'type' => 'FOOTER',
                        'code_expiration_minutes' => (int)($definition['code_expiration_minutes'] ?? 10),
                    ],
                    [
                        'type' => 'BUTTONS',
                        'buttons' => $definition['buttons'] ?? [
                            [
                                'type' => 'OTP',
                                'otp_type' => 'COPY_CODE',
                                'text' => 'Copiar código',
                            ],
                        ],
                    ],
                ],
            ];
        }

        $bodyComponent = [
            'type' => 'BODY',
            'text' => $definition['body'],
        ];

        $examples = self::bodyExamples($definition);
        if ($examples !== []) {
            $bodyComponent['example'] = ['body_text' => [$examples]];
        }

        $components = [$bodyComponent];
        if (!empty($definition['footer'])) {
            $components[] = [
                'type' => 'FOOTER',
                'text' => (string)$definition['footer'],
            ];
        }

        if (!empty($definition['buttons']) && is_array($definition['buttons'])) {
            $components[] = [
                'type' => 'BUTTONS',
                'buttons' => $definition['buttons'],
            ];
        }

        return [
            'name' => $name ?: $definition['name'],
            'language' => self::LANGUAGE,
            'category' => $definition['category'],
            'components' => $components,
        ];
    }

    private static function templateRowData(
        array $account,
        array $definition,
        string $name,
        array $metaPayload
    ): array {
        return [
            'tenancy_id' => (string)$account['tenancy_id'],
            'user_id' => (int)$account['user_id'],
            'account_id' => (int)$account['id'],
            'waba_id' => $account['waba_id'] ?? null,
            'meta_template_id' => null,
            'name' => $name,
            'language' => self::LANGUAGE,
            'category' => $definition['category'],
            'body' => $definition['body'],
            'components' => $metaPayload['components'],
            'variable_map' => self::variableMap($definition),
            'is_system_template' => true,
            'template_type' => 'system',
            'status' => 'draft',
            'template_submitted_at' => null,
            'template_last_sync_at' => null,
            'template_last_error' => null,
            'meta_payload' => [
                'system_default_template' => true,
                'approval_note' => $definition['approval_note'] ?? null,
                'semantic_variable_map' => self::variableMap($definition),
                'meta_payload_template' => $metaPayload,
                'submitted' => false,
                'reason' => 'Modelo interno do sistema. Enviar para a Meta somente ao criar template do cliente.',
            ],
        ];
    }

    private static function updateExisting(int $id, array $data): void
    {
        (new Database('whatsapp_templates'))->update('id = :id', [
            'user_id' => $data['user_id'],
            'account_id' => $data['account_id'],
            'waba_id' => $data['waba_id'],
            'meta_template_id' => $data['meta_template_id'],
            'category' => $data['category'],
            'body' => $data['body'],
            'components' => json_encode($data['components'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'variable_map' => json_encode($data['variable_map'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_system_template' => 1,
            'template_type' => 'system',
            'status' => $data['status'],
            'template_submitted_at' => $data['template_submitted_at'],
            'template_approved_at' => null,
            'template_rejected_at' => null,
            'template_last_sync_at' => $data['template_last_sync_at'],
            'template_last_error' => $data['template_last_error'],
            'meta_payload' => json_encode($data['meta_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $id]);
    }

    private static function validateDefinition(array $definition): array
    {
        $errors = [];
        $name = (string)($definition['name'] ?? '');
        $category = strtoupper((string)($definition['category'] ?? ''));
        $body = (string)($definition['body'] ?? '');
        $variables = $definition['variables'] ?? [];

        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            $errors[] = 'Nome tecnico invalido.';
        }
        if (!in_array($category, [self::CATEGORY_UTILITY, self::CATEGORY_AUTHENTICATION, self::CATEGORY_MARKETING], true)) {
            $errors[] = 'Categoria deve ser UTILITY, AUTHENTICATION ou MARKETING.';
        }
        if (trim($body) === '') {
            $errors[] = 'BODY vazio.';
        }
        if (preg_match('/\{\{\s*[a-zA-Z_][a-zA-Z0-9_]*\s*\}\}/', $body)) {
            $errors[] = 'BODY deve usar variaveis numericas da Meta, como {{1}}.';
        }

        $bodyVariables = self::templateVariables($body);
        if (count($bodyVariables) > 3) {
            $errors[] = 'BODY excede 3 variaveis.';
        }
        if ($bodyVariables !== range(1, count($bodyVariables))) {
            $errors[] = 'Variaveis do BODY devem ser sequenciais a partir de {{1}}.';
        }
        foreach ($bodyVariables as $position) {
            if (empty($variables[$position]['key']) || !array_key_exists('example', $variables[$position])) {
                $errors[] = 'Variavel {{' . $position . '}} sem chave semantica ou exemplo.';
            }
        }

        if ($category === self::CATEGORY_UTILITY) {
            $lowerBody = mb_strtolower($body, 'UTF-8');
            foreach (self::FORBIDDEN_UTILITY_WORDS as $word) {
                if (str_contains($lowerBody, $word)) {
                    $errors[] = 'Utility contem termo proibido: ' . $word . '.';
                }
            }
        }

        return $errors;
    }

    private static function variableMap(array $definition): array
    {
        $map = [];
        foreach (($definition['variables'] ?? []) as $position => $variable) {
            $map[(string)$position] = [
                'key' => (string)$variable['key'],
                'description' => (string)($variable['description'] ?? ''),
                'example' => (string)($variable['example'] ?? ''),
            ];
        }

        return $map;
    }

    private static function bodyExamples(array $definition): array
    {
        $examples = [];
        foreach (self::templateVariables((string)$definition['body']) as $position) {
            $examples[] = (string)($definition['variables'][$position]['example'] ?? 'exemplo_' . $position);
        }

        return $examples;
    }

    private static function templateVariables(string $body): array
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $matches);
        $numbers = array_values(array_unique(array_map('intval', $matches[1] ?? [])));
        sort($numbers);
        return $numbers;
    }

    private static function isSystemTemplateRow(array $row): bool
    {
        return (int)($row['is_system_template'] ?? 0) === 1
            || strtolower((string)($row['template_type'] ?? '')) === 'system';
    }
}
