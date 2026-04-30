<?php

namespace App\Service;

use App\Config\WhatsAppConfig;
use GuzzleHttp\Client;

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
            $template['components'] = $components;
        }

        return $this->postMessage($accessToken, $phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ]);
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
                'fields' => 'id,display_phone_number,verified_name,quality_rating,messaging_limit_tier,code_verification_status,platform_type',
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

    private function request(string $method, string $uri, string $accessToken, array $options = []): array
    {
        $options['headers']['Authorization'] = 'Bearer ' . $accessToken;
        $options['headers']['Accept'] = 'application/json';
        $options['headers']['Content-Type'] = 'application/json';

        $response = $this->client->request($method, ltrim($uri, '/'), $options);

        return $this->responseToArray($response);
    }

    private function responseToArray($response): array
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (!is_array($data)) {
            $data = ['raw' => $body];
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => $data,
            'error' => $this->normalizeError($data['error'] ?? null),
        ];
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
