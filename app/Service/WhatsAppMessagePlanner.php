<?php

namespace App\Service;

class WhatsAppMessagePlanner
{
    private const AUTH_PATTERNS = [
        '/\b(c[oó]digo|otp|token|verifica[cç][aã]o|2fa|autentica[cç][aã]o)\b/i',
    ];

    private const MARKETING_PATTERNS = [
        '/\b(promo[cç][aã]o|oferta|desconto|aproveite|imperd[ií]vel|cupom|black friday|contrate|assine|upgrade|venda|lan[cç]amento)\b/i',
    ];

    private const UTILITY_PATTERNS = [
        '/\b(boleto|fatura|venc|pagamento|pedido|protocolo|status|confirmad[oa]|agendad[oa]|entrega|cobran[cç]a|aviso|solicita[cç][aã]o|ticket)\b/i',
    ];

    public static function planText(string $body, bool $serviceWindowOpen): array
    {
        $parts = self::splitByIntent($body);
        $messages = [];

        foreach ($parts as $index => $part) {
            $category = self::classifyText($part);
            $messageType = $serviceWindowOpen ? 'text' : 'template';

            $messages[] = [
                'sequence' => $index + 1,
                'message_type' => $messageType,
                'body' => $part,
                'template_name' => null,
                'template_language' => 'pt_BR',
                'template_components' => [],
                'template_category' => $messageType === 'template' ? $category : null,
                'intent' => strtolower($category),
                'requires_template' => !$serviceWindowOpen,
            ];
        }

        return $messages;
    }

    public static function planTemplate(string $templateName, string $language, ?string $category, array $components = [], ?string $body = null): array
    {
        $category = WhatsAppCostPolicy::normalizeCategory($category);

        return [[
            'sequence' => 1,
            'message_type' => 'template',
            'body' => trim((string)$body) !== '' ? trim((string)$body) : "[Template] {$templateName} ({$language})",
            'template_name' => $templateName,
            'template_language' => $language,
            'template_components' => $components,
            'template_category' => $category,
            'intent' => strtolower($category),
            'requires_template' => true,
        ]];
    }

    public static function summarize(array $plannedMessages): array
    {
        $summary = [
            'MARKETING' => 0,
            'UTILITY' => 0,
            'AUTHENTICATION' => 0,
            'TEXT' => 0,
        ];

        foreach ($plannedMessages as $message) {
            $category = $message['template_category'] ?? null;
            if ($category) {
                $summary[$category] = ($summary[$category] ?? 0) + 1;
            } else {
                $summary['TEXT']++;
            }
        }

        return $summary;
    }

    private static function splitByIntent(string $body): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+|\r?\n+/', trim($body)) ?: [];
        $parts = [];
        $current = '';
        $currentCategory = null;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            $category = self::classifyText($sentence);
            if ($current !== '' && $currentCategory !== $category) {
                $parts[] = $current;
                $current = '';
            }

            $current = trim($current . ' ' . $sentence);
            $currentCategory = $category;
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts ?: [trim($body)];
    }

    private static function classifyText(string $body): string
    {
        foreach (self::AUTH_PATTERNS as $pattern) {
            if (preg_match($pattern, $body)) {
                return WhatsAppCostPolicy::CATEGORY_AUTHENTICATION;
            }
        }

        foreach (self::MARKETING_PATTERNS as $pattern) {
            if (preg_match($pattern, $body)) {
                return WhatsAppCostPolicy::CATEGORY_MARKETING;
            }
        }

        foreach (self::UTILITY_PATTERNS as $pattern) {
            if (preg_match($pattern, $body)) {
                return WhatsAppCostPolicy::CATEGORY_UTILITY;
            }
        }

        return WhatsAppCostPolicy::CATEGORY_UTILITY;
    }
}
