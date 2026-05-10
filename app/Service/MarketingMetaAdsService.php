<?php

namespace App\Service;

class MarketingMetaAdsService
{
    public static function testConnection(array $settings): array
    {
        $token = trim((string)($settings['meta_access_token'] ?? ''));
        $accountId = trim((string)($settings['meta_ad_account_id'] ?? ''));

        if ($token === '' || $accountId === '') {
            return [
                'success' => false,
                'status_code' => 422,
                'message' => 'Configure o token da Meta e o Ad Account ID antes de testar a conexão.',
                'response' => null,
            ];
        }

        $endpoint = 'https://graph.facebook.com/v25.0/act_' . rawurlencode($accountId)
            . '?fields=id,name,account_status&access_token=' . rawurlencode($token);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $curlError !== '') {
            return [
                'success' => false,
                'status_code' => 500,
                'message' => 'Falha ao consultar a Meta: ' . $curlError,
                'response' => null,
            ];
        }

        $decoded = json_decode((string)$body, true);
        $ok = $statusCode >= 200 && $statusCode < 300 && is_array($decoded) && !isset($decoded['error']);

        return [
            'success' => $ok,
            'status_code' => $statusCode,
            'message' => $ok ? 'Conexão validada com sucesso.' : self::extractError($decoded, 'A Meta retornou erro ao validar a conexão.'),
            'response' => $decoded,
        ];
    }

    public static function buildSyncPreview(array $campaign, array $settings): array
    {
        $payload = [
            'campaign' => [
                'name' => (string)($campaign['name'] ?? ''),
                'objective' => (string)($campaign['objective'] ?? ''),
                'status' => (string)($campaign['status'] ?? ''),
                'daily_budget' => (string)($campaign['budget_daily'] ?? '0.00'),
                'lifetime_budget' => (string)($campaign['budget_total'] ?? '0.00'),
                'start_time' => (string)($campaign['start_date'] ?? ''),
                'stop_time' => (string)($campaign['end_date'] ?? ''),
            ],
            'adset' => [
                'city' => (string)($campaign['city'] ?? ''),
                'state' => (string)($campaign['state'] ?? ''),
                'age_min' => (int)($campaign['age_start'] ?? 0),
                'age_max' => (int)($campaign['age_end'] ?? 0),
                'interests' => self::normalizeInterests($campaign['interests_list'] ?? $campaign['interests'] ?? []),
            ],
            'creative' => [
                'primary_text' => (string)($campaign['primary_text'] ?? ''),
                'title' => (string)($campaign['ad_title'] ?? ''),
                'call_to_action' => (string)($campaign['call_to_action'] ?? ''),
                'link' => (string)($campaign['destination_link'] ?? ''),
                'whatsapp_number' => (string)($campaign['whatsapp_number'] ?? ''),
                'video_path' => (string)($campaign['video_path'] ?? ''),
                'thumbnail_path' => (string)($campaign['thumbnail_path'] ?? ''),
            ],
            'meta' => [
                'ad_account_id' => (string)($settings['meta_ad_account_id'] ?? ''),
                'pixel_id' => (string)($settings['meta_pixel_id'] ?? ''),
                'page_id' => (string)($settings['meta_page_id'] ?? ''),
                'business_id' => (string)($settings['meta_business_id'] ?? ''),
            ],
        ];

        $errors = [];
        if (trim((string)($settings['meta_ad_account_id'] ?? '')) === '') {
            $errors[] = 'Meta Ad Account ID não configurado.';
        }
        if (trim((string)($settings['meta_access_token'] ?? '')) === '') {
            $errors[] = 'Meta Access Token não configurado.';
        }
        if (trim((string)($campaign['video_path'] ?? '')) === '') {
            $errors[] = 'Campanha ainda não possui vídeo vinculado.';
        }
        if (trim((string)($campaign['primary_text'] ?? '')) === '') {
            $errors[] = 'Texto principal do anúncio é obrigatório.';
        }
        if (trim((string)($campaign['ad_title'] ?? '')) === '') {
            $errors[] = 'Título do anúncio é obrigatório.';
        }

        return [
            'ready' => $errors === [],
            'errors' => $errors,
            'payload' => $payload,
        ];
    }

    private static function extractError(?array $response, string $fallback): string
    {
        if (!is_array($response)) {
            return $fallback;
        }

        return trim((string)($response['error']['message'] ?? $response['message'] ?? $fallback));
    }

    private static function normalizeInterests(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn ($item) => trim((string)$item), $value), static fn ($item) => $item !== ''));
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,;]+/', $value) ?: [];
        return array_values(array_filter(array_map(static fn ($item) => trim((string)$item), $parts), static fn ($item) => $item !== ''));
    }
}
