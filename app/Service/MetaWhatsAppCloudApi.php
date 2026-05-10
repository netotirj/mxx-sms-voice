<?php

namespace App\Service;

use App\Config\WhatsAppConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
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

    public function sendCallPermissionRequest(
        string $accessToken,
        string $phoneNumberId,
        string $to,
        ?string $body = null
    ): array {
        if (WhatsAppConfig::fakeSend()) {
            return $this->fakeMessageResponse($to);
        }

        $interactive = [
            'type' => 'call_permission_request',
            'action' => [
                'name' => 'call_permission_request',
            ],
        ];

        $body = trim((string)$body);
        if ($body !== '') {
            $interactive['body'] = [
                'text' => $body,
            ];
        }

        return $this->postMessage($accessToken, $phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => $interactive,
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

    public function markMessageAsRead(
        string $accessToken,
        string $phoneNumberId,
        string $messageId
    ): array {
        if ($messageId === '') {
            return [
                'ok' => false,
                'status' => 0,
                'data' => [],
                'error' => 'Mensagem inválida para confirmação de leitura.',
            ];
        }

        if (WhatsAppConfig::fakeSend()) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => [
                    'success' => true,
                    'local_test' => true,
                    'message_id' => $messageId,
                ],
                'error' => null,
            ];
        }

        return $this->postMessage($accessToken, $phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
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

    public function uploadMedia(
        string $accessToken,
        string $phoneNumberId,
        string $filePath,
        string $mimeType,
        ?string $fileName = null
    ): array {
        if ($filePath === '' || !is_file($filePath)) {
            return [
                'ok' => false,
                'status' => 0,
                'data' => [],
                'error' => 'Arquivo de mídia não encontrado para envio à Meta.',
            ];
        }

        if (WhatsAppConfig::fakeSend()) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => [
                    'id' => 'local_media_' . substr(hash_file('sha256', $filePath), 0, 20),
                    'local_test' => true,
                ],
                'error' => null,
            ];
        }

        error_log(json_encode([
            'event' => 'meta_whatsapp_media_upload_request',
            'phone_number_id' => $phoneNumberId,
            'file_name' => basename($fileName ?: $filePath),
            'mime_type' => $mimeType,
            'file_size' => filesize($filePath) ?: 0,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            $response = $this->client->request('POST', ltrim($phoneNumberId . '/media', '/'), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                ],
                'verify' => WhatsAppConfig::sslVerify(),
                'multipart' => [
                    [
                        'name' => 'messaging_product',
                        'contents' => 'whatsapp',
                    ],
                    [
                        'name' => 'file',
                        'contents' => Utils::tryFopen($filePath, 'rb'),
                        'filename' => basename($fileName ?: $filePath),
                        'headers' => [
                            'Content-Type' => $mimeType,
                        ],
                    ],
                ],
            ]);

            $result = $this->responseToArray($response);
        } catch (Throwable $e) {
            $result = $this->exceptionToArray($e);
        }

        $result['endpoint'] = ltrim($phoneNumberId . '/media', '/');
        $result['method'] = 'POST';

        error_log(json_encode([
            'event' => 'meta_whatsapp_media_upload_response',
            'phone_number_id' => $phoneNumberId,
            'status' => $result['status'] ?? 0,
            'ok' => $result['ok'] ?? false,
            'error' => $result['error'] ?? null,
            'media_id' => $result['data']['id'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $result;
    }

    public function sendAudioMediaId(string $accessToken, string $phoneNumberId, string $to, string $mediaId): array
    {
        return $this->postMessage($accessToken, $phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'audio',
            'audio' => [
                'id' => $mediaId,
            ],
        ]);
    }

    public function sendImageLink(
        string $accessToken,
        string $phoneNumberId,
        string $to,
        string $imageUrl,
        ?string $caption = null
    ): array {
        if (WhatsAppConfig::fakeSend()) {
            return $this->fakeMessageResponse($to);
        }

        $image = [
            'link' => $imageUrl,
        ];

        $caption = trim((string)$caption);
        if ($caption !== '') {
            $image['caption'] = $caption;
        }

        return $this->postMessage($accessToken, $phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'image',
            'image' => $image,
        ]);
    }

    public function sendImageMediaId(
        string $accessToken,
        string $phoneNumberId,
        string $to,
        string $mediaId,
        ?string $caption = null
    ): array {
        $image = [
            'id' => $mediaId,
        ];

        $caption = trim((string)$caption);
        if ($caption !== '') {
            $image['caption'] = $caption;
        }

        return $this->postMessage($accessToken, $phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'image',
            'image' => $image,
        ]);
    }

    public function sendVideoLink(
        string $accessToken,
        string $phoneNumberId,
        string $to,
        string $videoUrl,
        ?string $caption = null
    ): array {
        if (WhatsAppConfig::fakeSend()) {
            return $this->fakeMessageResponse($to);
        }

        $video = [
            'link' => $videoUrl,
        ];

        $caption = trim((string)$caption);
        if ($caption !== '') {
            $video['caption'] = $caption;
        }

        return $this->postMessage($accessToken, $phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'video',
            'video' => $video,
        ]);
    }

    public function sendVideoMediaId(
        string $accessToken,
        string $phoneNumberId,
        string $to,
        string $mediaId,
        ?string $caption = null
    ): array {
        $video = [
            'id' => $mediaId,
        ];

        $caption = trim((string)$caption);
        if ($caption !== '') {
            $video['caption'] = $caption;
        }

        return $this->postMessage($accessToken, $phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'video',
            'video' => $video,
        ]);
    }

    public function sendDocumentLink(
        string $accessToken,
        string $phoneNumberId,
        string $to,
        string $documentUrl,
        ?string $filename = null,
        ?string $caption = null
    ): array {
        if (WhatsAppConfig::fakeSend()) {
            return $this->fakeMessageResponse($to);
        }

        $document = [
            'link' => $documentUrl,
        ];

        $filename = trim((string)$filename);
        if ($filename !== '') {
            $document['filename'] = $filename;
        }

        $caption = trim((string)$caption);
        if ($caption !== '') {
            $document['caption'] = $caption;
        }

        return $this->postMessage($accessToken, $phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'document',
            'document' => $document,
        ]);
    }

    public function sendDocumentMediaId(
        string $accessToken,
        string $phoneNumberId,
        string $to,
        string $mediaId,
        ?string $filename = null,
        ?string $caption = null
    ): array {
        $document = [
            'id' => $mediaId,
        ];

        $filename = trim((string)$filename);
        if ($filename !== '') {
            $document['filename'] = $filename;
        }

        $caption = trim((string)$caption);
        if ($caption !== '') {
            $document['caption'] = $caption;
        }

        return $this->postMessage($accessToken, $phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'document',
            'document' => $document,
        ]);
    }

    public function getMedia(string $accessToken, string $mediaId): array
    {
        return $this->request('GET', $mediaId, $accessToken);
    }

    public function downloadMediaToFile(string $accessToken, string $mediaUrl, string $targetPath): array
    {
        try {
            $response = $this->client->request('GET', $mediaUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => '*/*',
                ],
                'verify' => WhatsAppConfig::sslVerify(),
            ]);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 0,
                'data' => [],
                'error' => $e->getMessage(),
            ];
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'status' => $status,
                'data' => [],
                'error' => 'Falha ao baixar mídia da Meta.',
            ];
        }

        $bytes = @file_put_contents($targetPath, (string)$response->getBody());
        if ($bytes === false) {
            return [
                'ok' => false,
                'status' => $status,
                'data' => [],
                'error' => 'Falha ao salvar a mídia recebida da Meta no disco local.',
            ];
        }

        return [
            'ok' => true,
            'status' => $status,
            'data' => [
                'path' => $targetPath,
                'size' => filesize($targetPath) ?: $bytes,
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

    public function getCallingSettings(string $accessToken, string $phoneNumberId): array
    {
        $this->logCallingRequest('settings.fetch', $phoneNumberId, []);
        $result = $this->request('GET', $phoneNumberId . '/settings', $accessToken);
        $this->logCallingResponse('settings.fetch', $phoneNumberId, $result);

        return $result;
    }

    public function updateCallingSettings(string $accessToken, string $phoneNumberId, array $calling): array
    {
        $payload = [
            'calling' => $calling,
        ];

        $this->logCallingRequest('settings.update', $phoneNumberId, $payload);
        $result = $this->request('POST', $phoneNumberId . '/settings', $accessToken, [
            'json' => $payload,
        ]);
        $this->logCallingResponse('settings.update', $phoneNumberId, $result);

        return $result;
    }

    public function getCallPermissions(string $accessToken, string $phoneNumberId, string $userWaId): array
    {
        $query = [
            'user_wa_id' => preg_replace('/\D+/', '', $userWaId) ?: $userWaId,
        ];

        $this->logCallingRequest('permissions.fetch', $phoneNumberId, ['query' => $query]);
        $result = $this->request('GET', $phoneNumberId . '/call_permissions', $accessToken, [
            'query' => $query,
        ]);
        $this->logCallingResponse('permissions.fetch', $phoneNumberId, $result);

        return $result;
    }

    public function manageCall(string $accessToken, string $phoneNumberId, array $payload): array
    {
        if (WhatsAppConfig::fakeSend()) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => [
                    'messaging_product' => 'whatsapp',
                    'calls' => [
                        [
                            'id' => (string)($payload['call_id'] ?? ('local_call_' . substr(sha1(json_encode($payload)), 0, 16))),
                        ],
                    ],
                    'local_test' => true,
                ],
                'error' => null,
            ];
        }

        $this->logCallingRequest('calls.manage', $phoneNumberId, $this->maskCallPayloadForLog($payload));
        $result = $this->request('POST', $phoneNumberId . '/calls', $accessToken, [
            'json' => $payload,
        ]);
        $this->logCallingResponse('calls.manage', $phoneNumberId, $result);

        return $result;
    }

    private function postMessage(string $accessToken, string $phoneNumberId, array $payload): array
    {
        $verifyMode = WhatsAppConfig::sslVerify();
        error_log(json_encode([
            'event' => 'meta_whatsapp_message_request',
            'phone_number_id' => $phoneNumberId,
            'to' => $this->maskPhoneForLog((string)($payload['to'] ?? '')),
            'type' => $payload['type'] ?? null,
            'template_name' => $payload['template']['name'] ?? null,
            'ssl_verify' => is_bool($verifyMode) ? $verifyMode : '[ca_bundle]',
            'parameters' => $this->extractLoggedTemplateParameters($payload),
            'payload' => $this->maskPayloadForLog($payload),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $result = $this->request('POST', $phoneNumberId . '/messages', $accessToken, [
            'json' => $payload,
        ]);

        error_log(json_encode([
            'event' => 'meta_whatsapp_message_response',
            'phone_number_id' => $phoneNumberId,
            'to' => $this->maskPhoneForLog((string)($payload['to'] ?? '')),
            'type' => $payload['type'] ?? null,
            'template_name' => $payload['template']['name'] ?? null,
            'status' => $result['status'] ?? 0,
            'ok' => $result['ok'] ?? false,
            'message_id' => $result['data']['messages'][0]['id'] ?? null,
            'error' => $result['error'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $result;
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

            if (isset($component['example'])) {
                throw new InvalidArgumentException('Componentes de envio não podem conter example.body_text.');
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

            if (isset($component['text']) && $this->hasTemplateVariables((string)$component['text'])) {
                throw new InvalidArgumentException('Componentes de aprovação do template não podem ser usados no envio.');
            }
        }

        return $normalized;
    }

    private function hasTemplateVariables(string $text): bool
    {
        return (bool)preg_match('/\{\{\s*\d+\s*\}\}/', $text);
    }

    private function extractLoggedTemplateParameters(array $payload): array
    {
        $parameters = [];
        foreach (($payload['template']['components'] ?? []) as $component) {
            if (!is_array($component)) {
                continue;
            }
            foreach (($component['parameters'] ?? []) as $parameter) {
                if (!is_array($parameter)) {
                    continue;
                }
                $parameters[] = [
                    'component' => $component['type'] ?? null,
                    'type' => $parameter['type'] ?? null,
                    'text' => $parameter['text'] ?? null,
                    'payload' => $parameter['payload'] ?? null,
                ];
            }
        }

        return $parameters;
    }

    private function maskPayloadForLog(array $payload): array
    {
        if (isset($payload['to'])) {
            $payload['to'] = $this->maskPhoneForLog((string)$payload['to']);
        }

        return $payload;
    }

    private function maskCallPayloadForLog(array $payload): array
    {
        if (isset($payload['to'])) {
            $payload['to'] = $this->maskPhoneForLog((string)$payload['to']);
        }

        if (isset($payload['session']['sdp'])) {
            $payload['session'] = [
                'sdp_type' => $payload['session']['sdp_type'] ?? null,
                'sdp_summary' => [
                    'length' => strlen((string)$payload['session']['sdp']),
                    'has_audio' => str_contains(strtolower((string)$payload['session']['sdp']), 'm=audio'),
                    'has_video' => str_contains(strtolower((string)$payload['session']['sdp']), 'm=video'),
                    'candidate_count' => preg_match_all('/^a=candidate:/mi', (string)$payload['session']['sdp']) ?: 0,
                ],
            ];
        }

        return $payload;
    }

    private function maskPhoneForLog(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone) ?: '';
        if (strlen($phone) <= 6) {
            return '***';
        }

        return substr($phone, 0, 4) . '***' . substr($phone, -2);
    }

    private function logCallingRequest(string $event, string $phoneNumberId, array $payload): void
    {
        error_log(json_encode([
            'event' => 'meta_whatsapp_calling_request',
            'action' => $event,
            'phone_number_id' => $phoneNumberId,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function logCallingResponse(string $event, string $phoneNumberId, array $result): void
    {
        error_log(json_encode([
            'event' => 'meta_whatsapp_calling_response',
            'action' => $event,
            'phone_number_id' => $phoneNumberId,
            'status' => $result['status'] ?? 0,
            'ok' => $result['ok'] ?? false,
            'error' => $result['error'] ?? null,
            'error_code' => $result['error_code'] ?? null,
            'error_subcode' => $result['error_subcode'] ?? null,
            'response' => $result['data'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function request(string $method, string $uri, string $accessToken, array $options = []): array
    {
        $options['headers']['Authorization'] = 'Bearer ' . $accessToken;
        $options['headers']['Accept'] = 'application/json';
        $options['verify'] = $options['verify'] ?? WhatsAppConfig::sslVerify();
        if (
            !isset($options['multipart'])
            && !isset($options['form_params'])
            && !isset($options['body'])
            && !isset($options['headers']['Content-Type'])
        ) {
            $options['headers']['Content-Type'] = 'application/json';
        }

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

        if ($subcode === '2388364') {
            return 'A Meta bloqueou temporariamente novas tentativas de confirmação desse número porque houve tentativas demais de validar código. O envio de SMS/ligação e a validação do código têm limites separados. Aguarde um tempo antes de tentar confirmar novamente. (Meta subcode: 2388364)';
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
