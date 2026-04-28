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
                    'code_verification_status' => 'VERIFIED',
                    'platform_type' => 'CLOUD_API',
                ],
                'error' => null,
            ];
        }

        return $this->request('GET', $phoneNumberId, $accessToken, [
            'query' => [
                'fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status,platform_type',
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
            'error' => $data['error']['message'] ?? null,
        ];
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
