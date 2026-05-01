<?php

namespace App\Service;

use App\Config\WhatsAppConfig;
use GuzzleHttp\Client;
use Throwable;

class MetaWhatsAppCloudApi
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => WhatsAppConfig::graphBaseUrl() . '/' . WhatsAppConfig::graphVersion() . '/',
            'timeout' => 20,
            'connect_timeout' => 8,
            'http_errors' => false,
            'verify' => WhatsAppConfig::sslVerify(),
        ]);
    }

    public function sendText(string $accessToken, string $phoneNumberId, string $to, string $message): array
    {
        if (WhatsAppConfig::fakeSend()) {
            return $this->fakeMessageResponse($to);
        }

        return $this->postMessage($accessToken, $phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $message,
            ],
        ]);
    }

    public function sendTemplate(
        string $accessToken,
        string $phoneNumberId,
        string $to,
        string $templateName,
        string $languageCode = 'pt_BR',
        array $components = []
    ): array {
        if (WhatsAppConfig::fakeSend()) {
            return $this->fakeMessageResponse($to);
        }

        $template = [
            'name' => $templateName,
            'language' => ['code' => $languageCode],
        ];

        if ($components !== []) {
            $normalizedComponents = $this->normalizeTemplateSendComponents($components);
            if ($normalizedComponents !== []) {
                $template['components'] = $normalizedComponents;
            }
        }

        return $this->postMessage($accessToken, $phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ]);
    }

    public function sendAudioLink(string $accessToken, string $phoneNumberId, string $to, string $audioUrl): array
    {
        if (WhatsAppConfig::fakeSend()) {
            return $this->fakeMessageResponse($to);
        }

        return $this->postMessage($accessToken, $phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'audio',
            'audio' => [
                'link' => $audioUrl,
            ],
        ]);
    }

    public function getMedia(string $accessToken, string $mediaId): array
    {
        return $this->request('GET', $mediaId, $accessToken);
    }

    public function downloadMediaToFile(string $accessToken, string $mediaUrl, string $targetPath): array
    {
        $response = $this->client->request('GET', $mediaUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => '*/*',
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'status' => $status,
                'data' => [],
                'error' => 'Falha ao baixar mídia da Meta.',
            ];
        }

        file_put_contents($targetPath, (string)$response->getBody());

        return [
            'ok' => true,
            'status' => $status,
            'data' => [
                'path' => $targetPath,
                'size' => filesize($targetPath) ?: 0,
            ],
            'error' => null,
        ];
    }

    public function getMe(string $accessToken): array
    {
        return $this->request('GET', 'me', $accessToken);
    }

    public function debugToken(string $inputToken, string $appId, string $appSecret): array
    {
        if ($appId === '' || $appSecret === '') {
            return [
                'ok' => false,
                'status' => 0,
                'data' => [
                    'error' => [
                        'message' => 'Configure WHATSAPP_META_APP_ID e WHATSAPP_META_APP_SECRET para validar tokens da Cloud API.',
                    ],
                ],
                'error' => 'Configure WHATSAPP_META_APP_ID e WHATSAPP_META_APP_SECRET para validar tokens da Cloud API.',
            ];
        }

        $response = $this->client->request('GET', '../debug_token', [
            'query' => [
                'input_token' => $inputToken,
                'access_token' => $appId . '|' . $appSecret,
            ],
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        return $this->responseToArray($response);
    }

    public function getWaba(string $accessToken, string $wabaId): array
    {
        return $this->request('GET', $wabaId, $accessToken);
    }

    public function listPhoneNumbers(string $accessToken, string $wabaId): array
    {
        return $this->request('GET', $wabaId . '/phone_numbers', $accessToken);
    }

    public function createMessageTemplate(string $accessToken, string $wabaId, array $payload): array
    {
        if (WhatsAppConfig::fakeSend()) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => [
                    'id' => 'local_template_' . substr(hash('sha256', json_encode($payload)), 0, 16),
                    'status' => 'PENDING',
                    'category' => $payload['category'] ?? 'UTILITY',
                    'local_test' => true,
                ],
                'error' => null,
            ];
        }

        return $this->request('POST', $wabaId . '/message_templates', $accessToken, [
            'json' => $payload,
        ]);
    }

    public function listMessageTemplates(string $accessToken, string $wabaId): array
    {
        if (WhatsAppConfig::fakeSend()) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => ['data' => []],
                'error' => null,
            ];
        }

        return $this->request('GET', $wabaId . '/message_templates', $accessToken, [
            'query' => [
                'fields' => 'id,name,language,status,category,components,rejected_reason,quality_score',
                'limit' => 250,
            ],
        ]);
    }

    public function deleteMessageTemplate(string $accessToken, string $wabaId, string $name, ?string $templateId = null): array
    {
        if (WhatsAppConfig::fakeSend()) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => ['success' => true, 'local_test' => true],
                'error' => null,
            ];
        }

        $query = ['name' => $name];
        if ($templateId !== null && $templateId !== '') {
            $query['hsm_id'] = $templateId;
        }

        return $this->request('DELETE', $wabaId . '/message_templates', $accessToken, [
            'query' => $query,
        ]);
    }

    public function getBusinessProfile(string $accessToken, string $phoneNumberId): array
    {
        return $this->request('GET', $phoneNumberId . '/whatsapp_business_profile', $accessToken, [
            'query' => [
                'fields' => 'about,address,description,email,profile_picture_url,websites,vertical',
            ],
        ]);
    }

    public function updateBusinessProfile(string $accessToken, string $phoneNumberId, array $payload): array
    {
        $payload['messaging_product'] = 'whatsapp';

        return $this->request('POST', $phoneNumberId . '/whatsapp_business_profile', $accessToken, [
            'json' => $payload,
        ]);
    }

    public function uploadProfilePicture(string $accessToken, string $filePath, string $mimeType): array
    {
        $appId = WhatsAppConfig::metaAppId();
        if ($appId === '') {
            return $this->missingConfig('WHATSAPP_META_APP_ID');
        }

        if (WhatsAppConfig::fakeSend()) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => [
                    'h' => 'local_profile_picture_handle_' . substr(hash_file('sha256', $filePath), 0, 16),
                    'local_test' => true,
                ],
                'error' => null,
            ];
        }

        $session = $this->request('POST', $appId . '/uploads', $accessToken, [
            'query' => [
                'file_length' => filesize($filePath) ?: 0,
                'file_type' => $mimeType,
                'file_name' => basename($filePath),
            ],
        ]);

        if (!$session['ok']) {
            return $session;
        }

        $sessionId = (string)($session['data']['id'] ?? '');
        if ($sessionId === '') {
            return [
                'ok' => false,
                'status' => $session['status'],
                'data' => $session['data'],
                'error' => 'Meta não retornou sessão de upload da foto.',
            ];
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return [
                'ok' => false,
                'status' => 0,
                'data' => [],
                'error' => 'Não foi possível ler a imagem enviada.',
            ];
        }

        $uploadUrl = WhatsAppConfig::graphBaseUrl() . '/' . WhatsAppConfig::graphVersion() . '/' . ltrim($sessionId, '/');

        try {
            $response = $this->client->request('POST', $uploadUrl, [
                'headers' => [
                    'Authorization' => 'OAuth ' . $accessToken,
                    'file_offset' => '0',
                    'Content-Type' => $mimeType,
                ],
                'body' => $handle,
            ]);

            $result = $this->responseToArray($response);
            $result['endpoint'] = $sessionId;
            return $result;
        } catch (Throwable $e) {
            $result = $this->exceptionToArray($e);
            $result['endpoint'] = $sessionId;
            return $result;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function validatePlatformCredentials(): array
    {
        $accessToken = WhatsAppConfig::platformAccessToken();
        $wabaId = WhatsAppConfig::platformWabaId();

        return [
            'me' => $accessToken !== ''
                ? $this->getMe($accessToken)
                : $this->missingConfig('WHATSAPP_PLATFORM_ACCESS_TOKEN'),
            'waba' => $accessToken !== '' && $wabaId !== ''
                ? $this->getWaba($accessToken, $wabaId)
                : $this->missingConfig('WHATSAPP_PLATFORM_WABA_ID ou WHATSAPP_PLATFORM_ACCESS_TOKEN'),
            'phone_numbers' => $accessToken !== '' && $wabaId !== ''
                ? $this->listPhoneNumbers($accessToken, $wabaId)
                : $this->missingConfig('WHATSAPP_PLATFORM_WABA_ID ou WHATSAPP_PLATFORM_ACCESS_TOKEN'),
            'debug_token' => $accessToken !== ''
                ? $this->debugToken($accessToken, WhatsAppConfig::metaAppId(), WhatsAppConfig::metaAppSecret())
                : $this->missingConfig('WHATSAPP_PLATFORM_ACCESS_TOKEN'),
        ];
    }

    public function getPhoneNumber(string $accessToken, string $phoneNumberId): array
    {
        if (WhatsAppConfig::fakeSend()) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => [
                    'id' => $phoneNumberId,
                    'display_phone_number' => 'local-test',
                    'verified_name' => 'Ambiente local',
                    'name_status' => 'APPROVED',
                    'new_name_status' => null,
                    'quality_rating' => 'GREEN',
                    'messaging_limit_tier' => 'TIER_1K',
                    'code_verification_status' => 'VERIFIED',
                    'platform_type' => 'CLOUD_API',
                ],
                'error' => null,
            ];
        }

        return $this->request('GET', $phoneNumberId, $accessToken, [
            'query' => [
                'fields' => 'id,display_phone_number,verified_name,name_status,new_display_name,new_name_status,quality_rating,messaging_limit_tier,code_verification_status,platform_type',
            ],
        ]);
    }

    public function createPhoneNumber(
        string $accessToken,
        string $wabaId,
        string $countryCode,
        string $phoneNumber,
        string $verifiedName
    ): array {
        if (WhatsAppConfig::fakeSend()) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => [
                    'id' => 'local_phone_' . substr(hash('sha256', $countryCode . $phoneNumber), 0, 16),
                    'display_phone_number' => '+' . $countryCode . $phoneNumber,
                    'verified_name' => $verifiedName,
                    'local_test' => true,
                ],
                'error' => null,
            ];
        }

        return $this->request('POST', $wabaId . '/phone_numbers', $accessToken, [
            'json' => [
                'cc' => $countryCode,
                'phone_number' => $phoneNumber,
                'verified_name' => $verifiedName,
            ],
        ]);
    }

    public function updatePhoneNumberDisplayName(string $accessToken, string $phoneNumberId, string $displayName): array
    {
        if (WhatsAppConfig::fakeSend()) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => ['success' => true, 'local_test' => true],
                'error' => null,
            ];
        }

        return $this->request('POST', $phoneNumberId, $accessToken, [
            'query' => [
                'new_display_name' => $displayName,
            ],
        ]);
    }

    public function requestVerificationCode(
        string $accessToken,
        string $phoneNumberId,
        string $method = 'SMS',
        string $language = 'pt_BR'
    ): array {
        if (WhatsAppConfig::fakeSend()) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => ['success' => true, 'local_test' => true],
                'error' => null,
            ];
        }

        return $this->request('POST', $phoneNumberId . '/request_code', $accessToken, [
            'json' => [
                'code_method' => strtoupper($method) === 'VOICE' ? 'VOICE' : 'SMS',
                'language' => $language,
            ],
        ]);
    }

    public function verifyPhoneNumberCode(string $accessToken, string $phoneNumberId, string $code): array
    {
        if (WhatsAppConfig::fakeSend()) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => ['success' => true, 'local_test' => true],
                'error' => null,
            ];
        }

        return $this->request('POST', $phoneNumberId . '/verify_code', $accessToken, [
            'json' => [
                'code' => $code,
            ],
        ]);
    }

    public function registerPhoneNumber(string $accessToken, string $phoneNumberId, string $pin): array
    {
        if (WhatsAppConfig::fakeSend()) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => ['success' => true, 'local_test' => true],
                'error' => null,
            ];
        }

        return $this->request('POST', $phoneNumberId . '/register', $accessToken, [
            'json' => [
                'messaging_product' => 'whatsapp',
                'pin' => $pin,
            ],
        ]);
    }

    public function deregisterPhoneNumber(string $accessToken, string $phoneNumberId): array
    {
        if (WhatsAppConfig::fakeSend()) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => ['success' => true, 'local_test' => true],
                'error' => null,
            ];
        }

        return $this->request('POST', $phoneNumberId . '/deregister', $accessToken, [
            'json' => [
                'messaging_product' => 'whatsapp',
            ],
        ]);
    }

    private function postMessage(string $accessToken, string $phoneNumberId, array $payload): array
    {
        return $this->request('POST', $phoneNumberId . '/messages', $accessToken, [
            'json' => $payload,
        ]);
    }

    private function normalizeTemplateSendComponents(array $components): array
    {
        $normalized = [];

        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }

            $type = strtolower((string)($component['type'] ?? ''));
            if ($type === '') {
                continue;
            }

            if (isset($component['parameters']) && is_array($component['parameters'])) {
                $item = [
                    'type' => $type,
                    'parameters' => $component['parameters'],
                ];
                if (isset($component['sub_type'])) {
                    $item['sub_type'] = $component['sub_type'];
                }
                if (isset($component['index'])) {
                    $item['index'] = (string)$component['index'];
                }
                $normalized[] = $item;
                continue;
            }

            if (in_array($type, ['body', 'header'], true) && isset($component['text']) && $this->hasTemplateVariables((string)$component['text'])) {
                $normalized[] = [
                    'type' => $type,
                    'parameters' => [
                        [
                            'type' => 'text',
                            'text' => (string)$component['text'],
                        ],
                    ],
                ];
            }
        }

        return $normalized;
    }

    private function hasTemplateVariables(string $text): bool
    {
        return (bool)preg_match('/\{\{\s*\d+\s*\}\}/', $text);
    }

    private function request(string $method, string $uri, string $accessToken, array $options = []): array
    {
        $options['headers']['Authorization'] = 'Bearer ' . $accessToken;
        $options['headers']['Accept'] = 'application/json';
        $options['headers']['Content-Type'] = 'application/json';

        try {
            $response = $this->client->request($method, ltrim($uri, '/'), $options);
            $result = $this->responseToArray($response);
        } catch (Throwable $e) {
            $result = $this->exceptionToArray($e);
        }

        $result['endpoint'] = ltrim($uri, '/');
        $result['method'] = strtoupper($method);

        if (
            str_contains($uri, '/request_code')
            || str_contains($uri, '/verify_code')
            || str_contains($uri, '/register')
            || array_key_exists('new_display_name', $options['query'] ?? [])
            || ($method === 'POST' && str_ends_with($uri, '/phone_numbers'))
        ) {
            error_log(json_encode([
                'event' => 'meta_whatsapp_api_response',
                'method' => $method,
                'endpoint' => ltrim($uri, '/'),
                'status' => $result['status'],
                'ok' => $result['ok'],
                'error' => $result['error'],
                'response' => $result['data'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $result;
    }

    private function responseToArray($response): array
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (!is_array($data)) {
            $data = ['raw' => $body];
        }

        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $errorCode = isset($error['code']) ? (string)$error['code'] : null;
        $retryAfter = $this->normalizeRetryAfter($response->getHeaderLine('Retry-After'));

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => $data,
            'error' => $this->normalizeError($data['error'] ?? null),
            'error_code' => $errorCode,
            'error_subcode' => isset($error['error_subcode']) ? (string)$error['error_subcode'] : null,
            'retry_after' => $retryAfter,
            'rate_limited' => $this->isRateLimited($status, $errorCode, (string)($error['message'] ?? '')),
        ];
    }

    private function exceptionToArray(Throwable $e): array
    {
        return [
            'ok' => false,
            'status' => 0,
            'data' => [
                'exception' => get_class($e),
            ],
            'error' => 'Falha de comunicação com a Meta: ' . $e->getMessage(),
            'error_code' => null,
            'error_subcode' => null,
            'retry_after' => null,
            'rate_limited' => false,
        ];
    }

    private function normalizeRetryAfter(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return max(1, (int)$value);
        }

        $timestamp = strtotime($value);
        return $timestamp ? max(1, $timestamp - time()) : null;
    }

    private function isRateLimited(int $status, ?string $code, string $message): bool
    {
        $message = strtolower($message);
        return $status === 429
            || in_array((string)$code, ['4', '17', '32', '613', '80008'], true)
            || str_contains($message, 'too many calls')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'rate-limiting');
    }

    private function missingConfig(string $name): array
    {
        $message = 'Configuração ausente: ' . $name . '.';

        return [
            'ok' => false,
            'status' => 0,
            'data' => [
                'error' => [
                    'message' => $message,
                ],
            ],
            'error' => $message,
        ];
    }

    private function normalizeError(mixed $error): ?string
    {
        if (!is_array($error)) {
            return null;
        }

        $message = (string)($error['message'] ?? '');
        $code = (string)($error['code'] ?? '');
        $subcode = (string)($error['error_subcode'] ?? '');
        $type = (string)($error['type'] ?? '');
        $userTitle = (string)($error['error_user_title'] ?? '');
        $userMessage = (string)($error['error_user_msg'] ?? '');
        $lowerMessage = strtolower($message);

        if (
            $code === '190'
            || str_contains($lowerMessage, 'validating access token')
            || str_contains($lowerMessage, 'session has expired')
        ) {
            return 'Token de acesso da Meta expirado ou inválido. Atualize WHATSAPP_PLATFORM_ACCESS_TOKEN e tente novamente.';
        }

        if (
            str_contains($lowerMessage, 'phone_numbers')
            && (
                str_contains($lowerMessage, 'node type (application)')
                || str_contains($lowerMessage, 'nonexisting field')
            )
        ) {
            return 'WHATSAPP_PLATFORM_WABA_ID parece estar com o ID do aplicativo Meta. Configure o ID da conta WhatsApp Business (WABA), não o ID do app.';
        }

        if (
            str_contains($lowerMessage, 'unsupported post request')
            || str_contains($lowerMessage, 'object with id')
            || str_contains($lowerMessage, 'does not exist')
        ) {
            return 'Não foi possível acessar a WABA configurada. Verifique WHATSAPP_PLATFORM_WABA_ID, permissões do token e vínculo da conta WhatsApp Business ao app Meta.';
        }

        if ($userMessage !== '') {
            $prefix = $userTitle !== '' ? $userTitle . ': ' : '';
            $suffix = $subcode !== '' ? ' (Meta subcode: ' . $subcode . ')' : '';

            return $prefix . $userMessage . $suffix;
        }

        $details = (string)($error['error_data']['details'] ?? '');
        if ($message !== '' && $details !== '') {
            return $message . ' Detalhes: ' . $details;
        }

        if ($message !== '' && $subcode !== '') {
            return $message . ' (Meta subcode: ' . $subcode . ')';
        }

        return $message !== '' ? $message : null;
    }

    private function fakeMessageResponse(string $to): array
    {
        return [
            'ok' => true,
            'status' => 200,
            'data' => [
                'messaging_product' => 'whatsapp',
                'contacts' => [
                    [
                        'input' => $to,
                        'wa_id' => $to,
                    ],
                ],
                'messages' => [
                    [
                        'id' => 'local_' . bin2hex(random_bytes(8)),
                    ],
                ],
                'local_test' => true,
            ],
            'error' => null,
        ];
    }
}
