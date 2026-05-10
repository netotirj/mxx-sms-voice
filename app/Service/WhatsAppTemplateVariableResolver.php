<?php

namespace App\Service;

use InvalidArgumentException;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppTemplateVariableResolver
{
    public static function buildSendComponents(array $template, array $context, array $inputVariables = []): array
    {
        $positions = self::templatePositions($template);
        if ($positions === []) {
            return [];
        }

        $variableMap = self::jsonColumnToArray($template['variable_map'] ?? null) ?: [];
        $parameters = [];
        $resolved = [];

        foreach ($positions as $position) {
            $meta = is_array($variableMap[(string)$position] ?? null) ? $variableMap[(string)$position] : [];
            $key = (string)($meta['key'] ?? 'variavel_' . $position);
            $value = self::resolveValue($position, $key, $context, $inputVariables);

            if (trim($value) === '') {
                throw new InvalidArgumentException(self::missingVariableMessage($key));
            }

            $parameters[] = [
                'type' => 'text',
                'text' => $value,
            ];
            $resolved[(string)$position] = [
                'key' => $key,
                'text' => $value,
            ];
        }

        $components = self::buildTemplateSendComponents($template, $resolved);
        if ($components === []) {
            $components = [[
                'type' => 'body',
                'parameters' => $parameters,
            ]];
        }

        return [
            'components' => $components,
            'resolved' => $resolved,
            'preview_body' => self::renderPreview($template, $resolved),
        ];
    }

    public static function normalizeInputVariables(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return [];
            }

            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    private static function resolveValue(int $position, string $key, array $context, array $inputVariables): string
    {
        $normalizedKey = strtolower(trim($key));

        if (in_array($normalizedKey, ['nome_cliente', 'cliente_nome', 'contact_name', 'customer_name'], true)) {
            $name = trim((string)($context['contact_name'] ?? ''));
            return $name !== '' ? $name : trim((string)($context['contact_name_fallback'] ?? 'cliente'));
        }

        if (in_array($normalizedKey, ['nome_empresa', 'empresa', 'tenant_name', 'company_name'], true)) {
            $name = trim((string)($context['tenant_name'] ?? ''));
            return $name !== '' ? $name : 'empresa';
        }

        if (in_array($normalizedKey, ['agencia', 'agência', 'agency', 'nome_agencia'], true)) {
            return trim((string)($context['contact_agency'] ?? $context['agency'] ?? ''));
        }

        if (in_array($normalizedKey, ['valor_plano', 'plano_valor', 'plan_value', 'plan_amount'], true)) {
            $plan = self::planFromInput($inputVariables);
            if ($plan) {
                return 'R$ ' . number_format((float)($plan['amount_plan'] ?? 0), 2, ',', '.');
            }
        }

        $candidates = self::candidateKeys($position, $normalizedKey);
        foreach ($candidates as $candidate) {
            $value = self::valueByPath($inputVariables, $candidate);
            if ($value !== null && trim((string)$value) !== '') {
                return self::formatValue($normalizedKey, $value);
            }
        }

        return '';
    }

    private static function candidateKeys(int $position, string $key): array
    {
        $aliases = [
            'codigo_agendamento' => ['appointment.code', 'appointment_code', 'agendamento.codigo', 'agendamento_codigo', 'code'],
            'data_hora' => ['appointment.datetime', 'appointment_datetime', 'agendamento.data_hora', 'agendamento_data_hora', 'datetime'],
            'protocolo_atendimento' => ['ticket.protocol', 'ticket_protocol', 'protocolo'],
            'tema_comunicado' => ['tema', 'assunto'],
            'assunto_novidade' => ['assunto', 'novidade'],
            'numero_pedido' => ['order.number', 'order_number', 'pedido.numero', 'pedido_numero'],
            'status_pedido' => ['order.status', 'order_status', 'pedido.status', 'pedido_status'],
            'cobranca_id' => ['billing.id', 'billing_id', 'cobranca.id'],
            'data_vencimento' => ['billing.due_date', 'billing_due_date', 'vencimento'],
            'referencia_id' => ['reference.id', 'reference_id', 'referencia'],
            'codigo_verificacao' => ['code', 'otp', 'verification_code'],
            'codigo_acesso' => ['code', 'otp', 'access_code'],
            'agencia' => ['agency', 'agencia', 'agência', 'branch', 'unidade'],
            'nome_agencia' => ['agency', 'agencia', 'agência', 'branch', 'unidade'],
            'valor_plano' => ['valor', 'valor_plano', 'plano.valor', 'plan.value', 'plan.amount', 'amount_plan'],
            'link_ativacao' => ['link', 'link_ativacao', 'ativacao.link', 'activation_link', 'checkout_link'],
        ];

        return array_values(array_unique(array_merge(
            [$key, (string)$position, 'var_' . $position, 'variavel_' . $position],
            $aliases[$key] ?? []
        )));
    }

    private static function valueByPath(array $values, string $path): mixed
    {
        if (array_key_exists($path, $values)) {
            return $values[$path];
        }

        $current = $values;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    private static function planFromInput(array $inputVariables): ?array
    {
        $planId = self::valueByPath($inputVariables, 'plan_id')
            ?? self::valueByPath($inputVariables, 'plano_id')
            ?? self::valueByPath($inputVariables, 'plan.id')
            ?? self::valueByPath($inputVariables, 'plano.id');

        $planId = (int)$planId;
        if ($planId <= 0) {
            return null;
        }

        try {
            $row = (new Database('mxx_plans'))
                ->select('id = :id AND status = :status', [':id' => $planId, ':status' => 'active'], '', '1')
                ->fetch(\PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            error_log('[whatsapp_template_plan_variable] ' . $e->getMessage());
            return null;
        }
    }

    private static function formatValue(string $key, mixed $value): string
    {
        $value = trim((string)$value);
        if ($value === '' || !in_array($key, ['data_hora', 'data_vencimento'], true)) {
            return $value;
        }

        $timestamp = strtotime($value);
        if (!$timestamp) {
            return $value;
        }

        return $key === 'data_hora'
            ? date('d/m/Y \à\s H:i', $timestamp)
            : date('d/m/Y', $timestamp);
    }

    private static function templatePositions(array $template): array
    {
        $body = (string)($template['body'] ?? '');
        $components = self::jsonColumnToArray($template['components'] ?? null) ?: [];

        foreach ($components as $component) {
            if (is_array($component) && strtoupper((string)($component['type'] ?? '')) === 'BODY') {
                $body .= "\n" . (string)($component['text'] ?? '');
            }
        }

        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $matches);
        $positions = array_values(array_unique(array_map('intval', $matches[1] ?? [])));
        sort($positions);

        return $positions;
    }

    private static function renderPreview(array $template, array $resolved): string
    {
        $body = self::templateBody($template);
        if ($body === '') {
            return '[Template] ' . (string)($template['name'] ?? '') . ' (' . (string)($template['language'] ?? 'pt_BR') . ')';
        }

        foreach ($resolved as $position => $data) {
            $body = preg_replace(
                '/\{\{\s*' . preg_quote((string)$position, '/') . '\s*\}\}/',
                (string)($data['text'] ?? ''),
                $body
            ) ?? $body;
        }

        return trim($body);
    }

    private static function templateBody(array $template): string
    {
        $components = self::jsonColumnToArray($template['components'] ?? null) ?: [];

        foreach ($components as $component) {
            if (is_array($component) && strtoupper((string)($component['type'] ?? '')) === 'BODY') {
                $text = trim((string)($component['text'] ?? ''));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return trim((string)($template['body'] ?? ''));
    }

    private static function buildTemplateSendComponents(array $template, array $resolved): array
    {
        $sourceComponents = self::jsonColumnToArray($template['components'] ?? null) ?: [];
        $sendComponents = [];

        foreach ($sourceComponents as $component) {
            if (!is_array($component)) {
                continue;
            }

            $type = strtoupper((string)($component['type'] ?? ''));
            if ($type === 'BODY') {
                $positions = self::extractPositionsFromText((string)($component['text'] ?? ''));
                if ($positions !== []) {
                    $sendComponents[] = [
                        'type' => 'body',
                        'parameters' => self::resolvedParametersForPositions($positions, $resolved),
                    ];
                }
                continue;
            }

            if ($type !== 'BUTTONS') {
                continue;
            }

            foreach (array_values($component['buttons'] ?? []) as $index => $button) {
                if (!is_array($button)) {
                    continue;
                }

                $buttonType = strtolower((string)($button['type'] ?? ''));
                $positions = self::extractPositionsFromText((string)($button['url'] ?? ''));
                if ($positions === []) {
                    $positions = self::extractPositionsFromText((string)($button['text'] ?? ''));
                }
                if ($positions === []) {
                    $positions = self::extractPositionsFromText((string)($button['payload'] ?? ''));
                }
                if ($positions === []) {
                    continue;
                }

                $sendComponents[] = [
                    'type' => 'button',
                    'sub_type' => $buttonType !== '' ? $buttonType : 'url',
                    'index' => (string)$index,
                    'parameters' => self::resolvedParametersForPositions($positions, $resolved),
                ];
            }
        }

        return $sendComponents;
    }

    private static function resolvedParametersForPositions(array $positions, array $resolved): array
    {
        $parameters = [];
        foreach ($positions as $position) {
            $text = (string)($resolved[(string)$position]['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $parameters[] = [
                'type' => 'text',
                'text' => $text,
            ];
        }

        return $parameters;
    }

    private static function extractPositionsFromText(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $matches);
        $positions = array_values(array_unique(array_map('intval', $matches[1] ?? [])));
        sort($positions);

        return $positions;
    }

    private static function jsonColumnToArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function missingVariableMessage(string $key): string
    {
        return match ($key) {
            'nome_cliente' => 'Nome do contato não definido para template',
            'codigo_agendamento' => 'Código do agendamento não definido para template',
            'data_hora' => 'Data/hora do agendamento não definida para template',
            default => 'Variável obrigatória não definida para template: ' . $key,
        };
    }
}
