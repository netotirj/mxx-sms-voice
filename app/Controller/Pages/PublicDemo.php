<?php

namespace App\Controller\Pages;

use App\Config\TelephonyConfig;
use App\Config\WhatsAppConfig;
use App\Http\Response;
use App\Model\Entity\CallbackSms;
use App\Model\Entity\CdrVoice;
use App\Model\Entity\SiteServiceTest;
use App\Model\Entity\WhatsAppAccount;
use App\Service\MetaWhatsAppCloudApi;
use GuzzleHttp\Client;
use WilliamCosta\DatabaseManager\Database;

class PublicDemo
{
    private const CHANNELS = ['sms', 'whatsapp', 'call'];
    private const CTA_URL = '/register';
    private const CTA_LABEL = 'Fazer cadastro no painel';

    public static function send($request, string $channel): Response
    {
        self::cors();

        $channel = strtolower(trim($channel));
        if (!in_array($channel, self::CHANNELS, true)) {
            return self::json(404, ['success' => false, 'message' => 'Canal de teste não encontrado.']);
        }
        $serviceType = self::serviceType($channel);

        $input = self::input();
        $ip = self::clientIp();
        $phone = self::normalizePhone((string)($input['phone'] ?? ''));
        if ($channel === 'call') {
            $phone = self::siteTestVoicePhone();
        }
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $consent = filter_var($input['consent'] ?? false, FILTER_VALIDATE_BOOL);
        $turnstileToken = trim((string)($input['turnstile_token'] ?? ''));
        $honeypot = trim((string)($input['company_site'] ?? ''));
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if ($honeypot !== '') {
            return self::json(200, ['success' => true, 'message' => 'Solicitação registrada.']);
        }

        if (!$consent) {
            return self::json(422, ['success' => false, 'message' => 'Confirme a autorização para receber o teste.']);
        }

        if (!self::validBrazilPhone($phone)) {
            return self::json(422, ['success' => false, 'message' => 'Informe um telefone brasileiro válido com DDD.']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return self::json(422, ['success' => false, 'message' => 'Informe um e-mail válido.']);
        }

        $turnstile = self::verifyTurnstile($turnstileToken, $ip);
        if (!$turnstile['success']) {
            return self::json(403, ['success' => false, 'message' => $turnstile['message']]);
        }

        $availability = self::availabilityStatus($email, $phone, $ip);
        if (!$availability['available']) {
            return self::json(($availability['reason'] ?? '') === 'ip' ? 429 : 409, [
                'success' => false,
                'message' => $availability['message'],
                'blocked' => true,
                'reason' => $availability['reason'] ?? null,
                'cta' => self::ctaPayload(),
            ]);
        }

        try {
            $requestId = SiteServiceTest::createPending([
                'email' => $email,
                'service_type' => $serviceType,
                'destination' => $phone,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'request_payload' => [
                    'channel' => $channel,
                    'service_type' => $serviceType,
                    'phone' => $phone,
                    'requested_phone' => self::normalizePhone((string)($input['phone'] ?? '')),
                    'email' => $email,
                    'turnstile' => $turnstile,
                ],
            ]);
        } catch (\Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
                return self::json(409, [
                    'success' => false,
                    'message' => self::duplicateMessage($serviceType),
                ]);
            }

            return self::json(500, ['success' => false, 'message' => 'Não foi possível registrar o teste agora.']);
        }

        try {
            $result = match ($channel) {
                'sms' => self::sendSms($phone, $requestId),
                'whatsapp' => self::sendWhatsApp($phone, $requestId),
                'call' => self::startCall($phone, $requestId),
            };

            $ok = (bool)($result['success'] ?? false);
            SiteServiceTest::updateResult($requestId, [
                'status' => $ok ? 'sent' : 'failed',
                'provider' => $result['provider_name'] ?? self::providerName($serviceType),
                'provider_message_id' => $result['provider_message_id'] ?? null,
                'provider_response' => $result['provider'] ?? $result,
                'error_message' => $ok ? null : (string)($result['message'] ?? 'Falha no envio.'),
            ]);

            return self::json($ok ? 200 : 424, [
                'success' => $ok,
                'message' => $result['message'] ?? ($ok ? 'Teste enviado.' : 'Falha ao enviar teste.'),
                'request_id' => $requestId,
            ]);
        } catch (\Throwable $e) {
            SiteServiceTest::updateResult($requestId, [
                'status' => 'failed',
                'provider' => self::providerName($serviceType),
                'provider_response' => ['exception' => get_class($e)],
                'error_message' => $e->getMessage(),
            ]);

            return self::json(500, [
                'success' => false,
                'message' => 'Não foi possível processar o teste agora.',
                'request_id' => $requestId,
            ]);
        }
    }

    public static function options($request, string $channel = ''): Response
    {
        self::cors();
        return new Response(204, '', 'application/json');
    }

    public static function stats($request): Response
    {
        self::cors();

        if (self::statsRateLimited(self::clientIp())) {
            $response = new Response(429, [
                'success' => false,
                'message' => 'Muitas consultas ao painel. Tente novamente em instantes.',
            ], 'application/json');
            $response->addHeader('Retry-After', '60');
            return $response;
        }

        $response = new Response(200, SiteServiceTest::publicStats(8), 'application/json');
        $response->addHeader('Cache-Control', 'public, max-age=5, stale-while-revalidate=10');
        $response->addHeader('X-Robots-Tag', 'noindex');

        return $response;
    }

    public static function check($request): Response
    {
        self::cors();

        $input = self::input();
        $channel = strtolower(trim((string)($input['channel'] ?? '')));
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $phone = self::normalizePhone((string)($input['phone'] ?? ''));
        $ip = self::clientIp();

        if ($channel === 'call') {
            $phone = self::siteTestVoicePhone();
        }

        $availability = self::availabilityStatus($email, $phone, $ip);

        return self::json(200, [
            'success' => true,
            'available' => $availability['available'],
            'blocked' => !$availability['available'],
            'message' => $availability['message'] ?? '',
            'reason' => $availability['reason'] ?? null,
            'cta' => !$availability['available'] ? self::ctaPayload() : null,
        ]);
    }

    private static function statsRateLimited(string $ip): bool
    {
        if (!function_exists('apcu_inc') || !filter_var((string)ini_get('apc.enabled'), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $minute = gmdate('YmdHi');
        $key = 'site_stats:' . hash('sha256', $ip . ':' . $minute);
        $success = false;
        $count = apcu_inc($key, 1, $success, 70);
        if (!$success) {
            apcu_add($key, 1, 70);
            $count = 1;
        }

        return $count > 120;
    }

    private static function availabilityStatus(string $email, string $phone, string $ip): array
    {
        if ($email !== '' && SiteServiceTest::findByEmail($email)) {
            return [
                'available' => false,
                'reason' => 'email',
                'message' => 'Este e-mail já utilizou o teste do site.',
            ];
        }

        if ($phone !== '' && SiteServiceTest::findByDestination($phone)) {
            return [
                'available' => false,
                'reason' => 'phone',
                'message' => 'Este número já utilizou o teste do site.',
            ];
        }

        if ($ip !== '' && SiteServiceTest::findByIp($ip)) {
            return [
                'available' => false,
                'reason' => 'ip',
                'message' => 'Este acesso já utilizou o teste do site.',
            ];
        }

        return [
            'available' => true,
            'message' => '',
        ];
    }

    private static function verifyTurnstile(string $token, string $ip): array
    {
        $required = filter_var(TelephonyConfig::env('PUBLIC_DEMO_TURNSTILE_REQUIRED', 'true'), FILTER_VALIDATE_BOOL);
        $secret = trim((string)TelephonyConfig::env(
            'PUBLIC_DEMO_TURNSTILE_SECRET',
            TelephonyConfig::env('TURNSTILE_SECRET_KEY', '')
        ));

        if (!$required) {
            return ['success' => true, 'skipped' => true];
        }

        if ($secret === '') {
            return [
                'success' => false,
                'message' => 'Validação de segurança não configurada.',
                'error' => 'turnstile_secret_missing',
            ];
        }

        if ($token === '') {
            return [
                'success' => false,
                'message' => 'Confirme a validação de segurança.',
                'error' => 'turnstile_token_missing',
            ];
        }

        $verifyUrl = TelephonyConfig::env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
        $curl = curl_init($verifyUrl);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($response === false) {
            return [
                'success' => false,
                'message' => 'Falha ao validar segurança. Tente novamente.',
                'error' => $error ?: 'turnstile_curl_error',
            ];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            return [
                'success' => false,
                'message' => 'Resposta inválida da validação de segurança.',
                'error' => 'turnstile_invalid_json',
                'http_code' => $httpCode,
            ];
        }

        if (empty($data['success'])) {
            return [
                'success' => false,
                'message' => 'Validação de segurança não confirmada.',
                'error' => implode(',', (array)($data['error-codes'] ?? ['turnstile_failed'])),
                'http_code' => $httpCode,
                'raw' => $data,
            ];
        }

        return [
            'success' => true,
            'http_code' => $httpCode,
            'challenge_ts' => $data['challenge_ts'] ?? null,
            'hostname' => $data['hostname'] ?? null,
        ];
    }

    private static function sendSms(string $phone, int $testId): array
    {
        $message = (string)TelephonyConfig::env(
            'PUBLIC_DEMO_SMS_MESSAGE',
            'Teste Maxx Solutions: seu SMS de demonstração foi enviado com sucesso.'
        );
        $partnerId = substr('site-test-sms-' . $testId . '-' . bin2hex(random_bytes(4)), 0, 100);

        $payload = [[
            'numero' => $phone,
            'servico' => 'short',
            'mensagem' => $message,
            'parceiro_id' => $partnerId,
            'codificacao' => '0',
            'nome_campanha' => 'site_test:test_sms',
        ]];

        $client = new DisproClient();
        $response = $client->send($payload);
        if ($response === null) {
            self::registerSiteSmsCallback($phone, $partnerId, 'FAILED', null, [
                'error' => $client->getLastError(),
                'http_code' => $client->getLastHttpCode(),
            ]);

            return [
                'success' => false,
                'message' => $client->getLastError() ?: 'Falha ao enviar SMS de teste.',
                'provider_name' => 'disparopro',
                'provider_message_id' => $partnerId,
                'provider' => ['http_code' => $client->getLastHttpCode()],
            ];
        }

        $detail = self::firstProviderDetail($response);
        $status = strtoupper((string)($detail['status'] ?? 'ACCEPTED'));
        $providerMessageId = (string)($detail['id'] ?? $detail['message_id'] ?? $detail['reference_id'] ?? $partnerId);
        self::registerSiteSmsCallback($phone, $partnerId, $status, $detail, $response);

        return [
            'success' => true,
            'message' => 'SMS de teste enviado.',
            'provider_name' => 'disparopro',
            'provider_message_id' => $providerMessageId,
            'provider' => $response,
        ];
    }

    private static function sendWhatsApp(string $phone, int $testId): array
    {
        $template = trim((string)TelephonyConfig::env('PUBLIC_DEMO_WHATSAPP_TEMPLATE', 'site_test_notification'));
        $language = trim((string)TelephonyConfig::env('PUBLIC_DEMO_WHATSAPP_LANGUAGE', 'pt_BR')) ?: 'pt_BR';
        $templateName = trim((string)TelephonyConfig::env('PUBLIC_DEMO_WHATSAPP_NAME_PARAM', 'Maxx Solutions'));

        $account = WhatsAppAccount::getSupportAccount();
        $accessToken = (string)($account['access_token'] ?? WhatsAppConfig::platformAccessToken());
        $phoneNumberId = (string)($account['phone_number_id'] ?? WhatsAppConfig::phoneNumberId());
        $wabaId = (string)($account['waba_id'] ?? WhatsAppConfig::platformWabaId());

        if ($accessToken === '' || $phoneNumberId === '') {
            return ['success' => false, 'message' => 'Conta WhatsApp de demonstração não configurada.'];
        }

        $api = new MetaWhatsAppCloudApi();
        $templateCheck = self::checkWhatsAppTemplate($api, $accessToken, $wabaId, $template, $language);
        if (!$templateCheck['ok']) {
            return [
                'success' => false,
                'message' => $templateCheck['message'],
                'provider_name' => 'meta_whatsapp',
                'provider' => [
                    'template' => [
                        'name' => $template,
                        'language' => $language,
                        'expected_status' => 'APPROVED',
                        'suggested_text' => 'Olá {{1}}, este é um teste da plataforma Maxx Solutions.',
                    ],
                    'validation' => $templateCheck,
                ],
            ];
        }

        $components = [[
            'type' => 'body',
            'parameters' => [[
                'type' => 'text',
                'text' => $templateName,
            ]],
        ]];

        $result = $api->sendTemplate($accessToken, $phoneNumberId, $phone, $template, $language, $components);
        $wamid = $result['data']['messages'][0]['id'] ?? null;
        self::registerSiteWhatsAppCdr($phone, !empty($result['ok']) ? 'sent' : 'failed', $wamid, $result['error'] ?? null, $template);

        return [
            'success' => !empty($result['ok']),
            'message' => !empty($result['ok'])
                ? 'WhatsApp de teste enviado.'
                : (($result['error'] ?? null) ?: 'Falha ao enviar WhatsApp de teste.'),
            'provider_name' => 'meta_whatsapp',
            'provider_message_id' => $wamid,
            'provider' => [
                'template' => [
                    'name' => $template,
                    'language' => $language,
                    'components' => $components,
                    'validation' => $templateCheck,
                ],
                'meta' => $result,
            ],
        ];
    }

    private static function startCall(string $phone, int $testId): array
    {
        $trunk = trim((string)TelephonyConfig::env('PUBLIC_DEMO_VOICE_TRUNK', ''));
        $callerId = trim((string)TelephonyConfig::env('PUBLIC_DEMO_CALLER_ID', 'Maxx Solutions'));
        $stasisApp = trim((string)TelephonyConfig::env('PUBLIC_DEMO_VOICE_STASIS_APP', TelephonyConfig::stasisApp()));
        $techPrefix = trim((string)TelephonyConfig::env('PUBLIC_DEMO_VOICE_TECH_PREFIX', ''));
        $trunkBillingType = trim((string)TelephonyConfig::env('PUBLIC_DEMO_VOICE_TRUNK_BILLING_TYPE', 'cli_aberta'));
        $effectivePhone = self::siteTestVoicePhone() ?: $phone;

        if ($trunk === '') {
            self::registerSiteVoiceCdr($effectivePhone, 'FAILED', null, 'Tronco de chamada de demonstração não configurado.', $trunk, $trunkBillingType);
            return [
                'success' => false,
                'message' => 'Tronco de chamada de demonstração não configurado.',
                'provider_name' => 'asterisk_ari',
            ];
        }

        $client = new Client([
            'auth' => TelephonyConfig::ariAuth(),
            'timeout' => 10,
            'connect_timeout' => 4,
            'http_errors' => false,
        ]);

        $endpoint = 'PJSIP/' . $techPrefix . $effectivePhone . '@' . $trunk;
        $payload = [
            'endpoint' => $endpoint,
            'app' => $stasisApp,
            'appArgs' => json_encode([
                'action' => 'public_demo',
                'test_id' => $testId,
                'destination' => $effectivePhone,
            ], JSON_UNESCAPED_SLASHES),
            'callerId' => $callerId,
            'timeout' => (int)TelephonyConfig::env('PUBLIC_DEMO_VOICE_TIMEOUT', 20),
            'variables' => [
                'CALL_TYPE' => 'PUBLIC_DEMO',
                'PUBLIC_DEMO' => '1',
                'SITE_SERVICE_TEST_ID' => (string)$testId,
                'EXTENSION' => $effectivePhone,
                'TRUNK' => $trunk,
                'TRUNK_ID' => $trunk,
                'TRUNK_BILLING_TYPE' => $trunkBillingType,
                'TECHPREFIX' => $techPrefix,
                'CALLERID(name)' => $callerId,
                'CALLERID(num)' => $callerId,
                'HANGUP_AFTER_ANSWER' => '1',
            ],
        ];

        $startedAt = microtime(true);
        $response = $client->post(TelephonyConfig::ariBaseUrl() . 'channels', ['json' => $payload]);

        $status = $response->getStatusCode();
        $body = json_decode((string)$response->getBody(), true);
        $channelId = (string)($body['id'] ?? ('site-test-voice-' . $testId));
        $ok = $status >= 200 && $status < 300;
        $error = $ok ? null : self::voiceErrorMessage($status, $body);
        self::registerSiteVoiceCdr(
            $effectivePhone,
            $ok ? 'SENT' : 'FAILED',
            $channelId,
            $error,
            $trunk,
            $trunkBillingType
        );

        return [
            'success' => $ok,
            'message' => $ok
                ? 'Chamada de teste iniciada.'
                : $error,
            'provider_name' => 'asterisk_ari',
            'provider_message_id' => $channelId,
            'provider' => [
                'http_status' => $status,
                'latency_ms' => (int)round((microtime(true) - $startedAt) * 1000),
                'ari_endpoint' => TelephonyConfig::ariBaseUrl() . 'channels',
                'payload' => $payload,
                'body' => $body ?: (string)$response->getBody(),
            ],
        ];
    }

    private static function checkWhatsAppTemplate(
        MetaWhatsAppCloudApi $api,
        string $accessToken,
        string $wabaId,
        string $template,
        string $language
    ): array {
        if (WhatsAppConfig::fakeSend()) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'WHATSAPP_FAKE_SEND'];
        }

        if ($wabaId === '') {
            return ['ok' => true, 'skipped' => true, 'reason' => 'WABA não configurada para validação prévia'];
        }

        $response = $api->listMessageTemplates($accessToken, $wabaId);
        if (empty($response['ok'])) {
            return [
                'ok' => false,
                'message' => $response['error'] ?? 'Não foi possível validar templates na Meta.',
                'meta' => $response,
            ];
        }

        foreach (($response['data']['data'] ?? []) as $row) {
            if (($row['name'] ?? '') !== $template || ($row['language'] ?? '') !== $language) {
                continue;
            }

            $status = strtoupper((string)($row['status'] ?? ''));
            return [
                'ok' => $status === 'APPROVED',
                'message' => $status === 'APPROVED'
                    ? 'Template aprovado.'
                    : "Template {$template}/{$language} encontrado com status {$status}; aprove-o na Meta antes do teste.",
                'template' => [
                    'name' => $template,
                    'language' => $language,
                    'status' => $status,
                    'category' => $row['category'] ?? null,
                ],
            ];
        }

        return [
            'ok' => false,
            'message' => "Template {$template}/{$language} não encontrado na Meta. Crie e aprove o template site_test_notification em pt_BR.",
        ];
    }

    private static function voiceErrorMessage(int $status, mixed $body): string
    {
        if (is_array($body)) {
            $message = trim((string)($body['message'] ?? $body['error'] ?? ''));
            if ($message !== '') {
                return 'Falha ao iniciar chamada de teste: ' . $message;
            }
        }

        return "Falha ao iniciar chamada de teste. ARI retornou HTTP {$status}.";
    }

    private static function duplicateMessage(string $serviceType): string
    {
        return 'Este teste já foi registrado anteriormente.';
    }

    private static function serviceType(string $channel): string
    {
        return $channel === 'call' ? 'voice' : $channel;
    }

    private static function providerName(string $serviceType): string
    {
        return match ($serviceType) {
            'sms' => 'disparopro',
            'whatsapp' => 'meta_whatsapp',
            'voice' => 'asterisk_ari',
            default => 'unknown',
        };
    }

    private static function siteTestUserId(): int
    {
        return (int)TelephonyConfig::env('SITE_TEST_USER_ID', 0);
    }

    private static function siteTestVoicePhone(): string
    {
        return self::normalizePhone((string)TelephonyConfig::env('PUBLIC_DEMO_VOICE_TEST_PHONE', '21968943160'));
    }

    private static function siteTestTenancyId(): string
    {
        return (string)TelephonyConfig::env('SITE_TEST_TENANCY_ID', 'site_test');
    }

    private static function siteTestTenancyExists(): bool
    {
        static $exists = null;

        if ($exists !== null) {
            return $exists;
        }

        $tenancyId = self::siteTestTenancyId();
        if ($tenancyId === '') {
            return $exists = false;
        }

        $row = (new Database('tenancies'))
            ->select('id = :id', [':id' => $tenancyId], '', '1', 'id')
            ->fetch(\PDO::FETCH_ASSOC);

        return $exists = is_array($row);
    }

    private static function firstProviderDetail(array $response): array
    {
        $detail = $response['detail'] ?? [];
        if (is_array($detail) && isset($detail[0]) && is_array($detail[0])) {
            return $detail[0];
        }

        return is_array($detail) ? $detail : [];
    }

    private static function registerSiteSmsCallback(string $phone, string $partnerId, string $status, ?array $detail, array $providerResponse): void
    {
        if (!self::siteTestTenancyExists()) {
            error_log('[site_test_sms_callback] skipped: SITE_TEST_TENANCY_ID not found in tenancies');
            return;
        }

        try {
            $callback = new CallbackSms();
            $callback->phone_sms = (string)($detail['numero'] ?? $phone);
            $callback->status_sms = $status;
            $callback->value_sms = '0.0000';
            $callback->camp_name = 'site_test:test_sms';
            $callback->id_partner = (string)($detail['parceiro_id'] ?? $partnerId);
            $callback->date_send = date('Y-m-d H:i:s');
            $callback->user_id = self::siteTestUserId();
            $callback->tenancy_id = self::siteTestTenancyId();
            $callback->campaign_id = null;
            $callback->batch_id = null;
            $callback->operator = (string)($detail['operadora'] ?? $detail['operator'] ?? 'SITE_TEST');
            $callback->insertStatus();
        } catch (\Throwable $e) {
            error_log('[site_test_sms_callback] ' . $e->getMessage() . ' provider=' . json_encode($providerResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    private static function registerSiteWhatsAppCdr(string $phone, string $status, ?string $wamid, ?string $error = null, string $template = 'site_test_notification'): void
    {
        if (!self::siteTestTenancyExists()) {
            error_log('[site_test_whatsapp_cdr] skipped: SITE_TEST_TENANCY_ID not found in tenancies');
            return;
        }

        try {
            (new Database('whatsapp_message_cdr'))->insert([
                'client_id' => self::siteTestUserId(),
                'tenancy_id' => self::siteTestTenancyId(),
                'type' => 'site_test',
                'phone_number' => $phone,
                'message_category' => 'service',
                'template_name' => $template,
                'direction' => 'outbound',
                'price_brl' => 0,
                'billed' => 0,
                'status' => $status === 'sent' ? 'sent' : 'failed',
                'whatsapp_outbox_id' => null,
                'whatsapp_message_id' => null,
                'wamid' => $wamid,
                'error_message' => $error,
                'timestamp' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[site_test_whatsapp_cdr] ' . $e->getMessage());
        }
    }

    private static function registerSiteVoiceCdr(
        string $phone,
        string $dialStatus,
        ?string $channelId,
        ?string $error = null,
        string $trunk = 'site_test',
        string $trunkBillingType = 'cli_aberta'
    ): void
    {
        if (!self::siteTestTenancyExists()) {
            error_log('[site_test_voice_cdr] skipped: SITE_TEST_TENANCY_ID not found in tenancies');
            return;
        }

        try {
            $now = date('Y-m-d H:i:s');
            $cdr = new CdrVoice();
            $cdr->channel_id = $channelId ?: ('site-test-voice-' . bin2hex(random_bytes(4)));
            $cdr->job_id = 'site_test';
            $cdr->call_id = $cdr->channel_id;
            $cdr->campaign_id = null;
            $cdr->campaign_type = 'test_voice';
            $cdr->tenancy_id = self::siteTestTenancyId();
            $cdr->user_id = self::siteTestUserId();
            $cdr->user_name = 'Site Test';
            $cdr->user_account_code = 'site_test';
            $cdr->channel_number = $phone;
            $cdr->endpoints = 'site_test';
            $cdr->number = $phone;
            $cdr->destination = $phone;
            $cdr->direction = 'outbound';
            $cdr->trunk = $trunk;
            $cdr->trunk_id = $trunk === 'site_test' ? null : $trunk;
            $cdr->trunk_billing_type = $trunkBillingType;
            $cdr->techprefix = null;
            $cdr->type = 'outbound';
            $cdr->state = $dialStatus === 'SENT' ? 'ORIGINATED' : 'FAILED';
            $cdr->dialstatus = $dialStatus;
            $cdr->cause = null;
            $cdr->cause_txt = $error ?: 'Teste de voz disparado pelo site.';
            $cdr->sip_code = null;
            $cdr->duration = 0;
            $cdr->billsec = 0;
            $cdr->taxa_of_service = 0;
            $cdr->value = 0;
            $cdr->agent_abandoned = 0;
            $cdr->agent_abandon_reason = null;
            $cdr->call_minute_cost = 0;
            $cdr->hangup_by = null;
            $cdr->sms_cost = 0;
            $cdr->torpedo_cost = 0;
            $cdr->application = 'site_test_public_demo';
            $cdr->cdr_timestamp = $now;
            $cdr->role = 'site_test';
            $cdr->started = $now;
            $cdr->answered = null;
            $cdr->ended = $dialStatus === 'FAILED' ? $now : null;
            $cdr->insertCdr();
        } catch (\Throwable $e) {
            error_log('[site_test_voice_cdr] ' . $e->getMessage());
        }
    }

    private static function input(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }

        return $_POST ?? [];
    }

    private static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 11) {
            $digits = '55' . $digits;
        }

        return $digits;
    }

    private static function validBrazilPhone(string $phone): bool
    {
        return (bool)preg_match('/^55[1-9]{2}9?\d{8}$/', $phone);
    }

    private static function clientIp(): string
    {
        $cloudflareIp = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cloudflareIp !== '') {
            return $cloudflareIp;
        }

        $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private static function cors(): void
    {
        if (headers_sent()) {
            return;
        }

        $configured = trim((string)TelephonyConfig::env('PUBLIC_DEMO_ALLOWED_ORIGIN', ''));
        $fallbackOrigin = self::sameOriginBase();
        $allowedOrigin = $configured !== '' ? $configured : $fallbackOrigin;
        $requestOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));

        if ($requestOrigin !== '' && self::originMatches($requestOrigin, $allowedOrigin, $fallbackOrigin)) {
            header('Access-Control-Allow-Origin: ' . $requestOrigin);
            header('Vary: Origin');
        } elseif ($allowedOrigin !== '') {
            header('Access-Control-Allow-Origin: ' . $allowedOrigin);
        }

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept');
    }

    private static function json(int $status, array $payload): Response
    {
        self::cors();
        return new Response($status, $payload, 'application/json');
    }

    private static function ctaPayload(): array
    {
        return [
            'label' => self::CTA_LABEL,
            'url' => self::CTA_URL,
        ];
    }

    private static function sameOriginBase(): string
    {
        $baseUrl = (string)(defined('VIEW_URL') ? VIEW_URL : (defined('URL') ? URL : ''));
        $scheme = (string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https');
        $host = (string)(parse_url($baseUrl, PHP_URL_HOST) ?: '');

        return $host !== '' ? $scheme . '://' . $host : '';
    }

    private static function originMatches(string $origin, string ...$allowedOrigins): bool
    {
        foreach ($allowedOrigins as $allowedOrigin) {
            $allowedOrigin = trim($allowedOrigin);
            if ($allowedOrigin !== '' && strcasecmp($origin, $allowedOrigin) === 0) {
                return true;
            }
        }

        return false;
    }
}
