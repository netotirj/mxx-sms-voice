<?php

namespace App\Controller\Pages;


use App\Config\WhatsAppConfig;
use App\Http\Response;
use App\Model\Entity\WhatsAppAccount;
use App\Model\Entity\WhatsAppCampaign;
use App\Model\Entity\WhatsAppConversation;
use App\Model\Entity\WhatsAppTemplate;
use App\Service\MetaWhatsAppCloudApi;
use App\Session\User as SessionUser;
use App\Utils\View;

class WhatsApp extends ViewComponents
{
    public static function getComponentsWhatsApp(): Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/whatsapp/index', []);
        return parent::getComponentsUsers('Maxx Solutions - SMS | VOZ', $content);
    }

    public static function listAccounts(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppAccount::listForUser($obUser),
        ]);
    }

    public static function createAccount(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $input = self::jsonInput();
        $required = ['label', 'phone_number_id', 'display_phone_number', 'access_token'];
        foreach ($required as $field) {
            if (trim((string)($input[$field] ?? '')) === '') {
                return self::json(422, [
                    'success' => false,
                    'message' => "Campo obrigatório: {$field}.",
                ]);
            }
        }

        try {
            $id = WhatsAppAccount::create([
                'tenancy_id' => $obUser['tenancy_id'],
                'user_id' => (int)$obUser['id'],
                'label' => trim((string)$input['label']),
                'waba_id' => self::nullableString($input['waba_id'] ?? null),
                'business_id' => self::nullableString($input['business_id'] ?? null),
                'phone_number_id' => trim((string)$input['phone_number_id']),
                'display_phone_number' => self::normalizePhone((string)$input['display_phone_number']),
                'access_token' => trim((string)$input['access_token']),
                'app_secret' => self::nullableString($input['app_secret'] ?? null),
                'verify_token' => self::nullableString($input['verify_token'] ?? null),
                'status' => 'active',
            ]);

            return self::json(201, [
                'success' => true,
                'message' => 'Número WhatsApp cadastrado.',
                'id' => $id,
            ]);
        } catch (\Throwable $e) {
            return self::json(500, [
                'success' => false,
                'message' => 'Falha ao cadastrar número WhatsApp.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function testAccount($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $account = WhatsAppAccount::getForUser((int)$id, $obUser);
        if (!$account) {
            return self::json(404, [
                'success' => false,
                'message' => 'Número WhatsApp não encontrado.',
            ]);
        }

        try {
            $result = (new MetaWhatsAppCloudApi())->getPhoneNumber(
                (string)$account['access_token'],
                (string)$account['phone_number_id']
            );

            return self::json($result['ok'] ? 200 : 502, [
                'success' => $result['ok'],
                'message' => $result['ok'] ? 'Conexão com Meta validada.' : 'Meta não validou o número.',
                'meta' => $result,
            ]);
        } catch (\Throwable $e) {
            return self::json(502, [
                'success' => false,
                'message' => 'Falha ao consultar Meta Cloud API.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function listCampaigns(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppCampaign::listForUser($obUser),
        ]);
    }

    public static function listTemplates(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppTemplate::listForUser($obUser),
        ]);
    }

    public static function createTemplate(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $input = self::jsonInput();
        $name = trim((string)($input['name'] ?? ''));
        $language = trim((string)($input['language'] ?? 'pt_BR')) ?: 'pt_BR';
        $category = strtoupper(trim((string)($input['category'] ?? 'MARKETING')));
        $body = self::nullableString($input['body'] ?? null);
        $components = $input['components'] ?? null;
        $status = strtolower(trim((string)($input['status'] ?? 'approved'))) ?: 'approved';

        if ($name === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe o nome do template aprovado na Meta.',
            ]);
        }

        if (!in_array($category, ['MARKETING', 'UTILITY', 'AUTHENTICATION'], true)) {
            return self::json(422, [
                'success' => false,
                'message' => 'Categoria de template inválida.',
            ]);
        }

        if (!in_array($status, ['draft', 'pending', 'approved', 'rejected', 'paused'], true)) {
            return self::json(422, [
                'success' => false,
                'message' => 'Status de template inválido.',
            ]);
        }

        if (is_string($components) && trim($components) !== '') {
            $decoded = json_decode($components, true);
            if (!is_array($decoded)) {
                return self::json(422, [
                    'success' => false,
                    'message' => 'Componentes precisa ser um JSON válido.',
                ]);
            }
            $components = $decoded;
        }

        try {
            $id = WhatsAppTemplate::create([
                'tenancy_id' => $obUser['tenancy_id'],
                'user_id' => (int)$obUser['id'],
                'name' => $name,
                'language' => $language,
                'category' => $category,
                'body' => $body,
                'components' => is_array($components) ? $components : null,
                'status' => $status,
            ]);

            return self::json(201, [
                'success' => true,
                'message' => 'Template salvo.',
                'id' => $id,
            ]);
        } catch (\Throwable $e) {
            return self::json(500, [
                'success' => false,
                'message' => 'Falha ao salvar template.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function deleteTemplate($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $ok = WhatsAppTemplate::deleteForUser((int)$id, $obUser);

        return self::json($ok ? 200 : 404, [
            'success' => $ok,
            'message' => $ok ? 'Template excluído.' : 'Template não encontrado.',
        ]);
    }

    public static function listConversations($request): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $query = $request->getQueryParams();
        $accountId = isset($query['account_id']) ? (int)$query['account_id'] : null;

        if ($accountId !== null && $accountId > 0 && !WhatsAppAccount::getForUser($accountId, $obUser)) {
            return self::json(404, [
                'success' => false,
                'message' => 'Conta WhatsApp não encontrada.',
            ]);
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppConversation::listForUser($obUser, $accountId),
        ]);
    }

    public static function listMessages($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $conversation = WhatsAppConversation::getForUser((int)$id, $obUser);
        if (!$conversation) {
            return self::json(404, [
                'success' => false,
                'message' => 'Conversa não encontrada.',
            ]);
        }

        return self::json(200, [
            'success' => true,
            'conversation' => $conversation,
            'data' => WhatsAppConversation::listMessagesForUser((int)$id, $obUser),
        ]);
    }

    public static function markConversationRead($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $ok = WhatsAppConversation::markReadForUser((int)$id, $obUser);

        return self::json($ok ? 200 : 404, [
            'success' => $ok,
            'message' => $ok ? 'Conversa marcada como lida.' : 'Conversa não encontrada.',
        ]);
    }

    public static function markConversationUnread($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $ok = WhatsAppConversation::markUnreadForUser((int)$id, $obUser);

        return self::json($ok ? 200 : 404, [
            'success' => $ok,
            'message' => $ok ? 'Conversa marcada como não lida.' : 'Conversa não encontrada.',
        ]);
    }

    public static function deleteConversation($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $ok = WhatsAppConversation::deleteForUser((int)$id, $obUser);

        return self::json($ok ? 200 : 404, [
            'success' => $ok,
            'message' => $ok ? 'Conversa excluída.' : 'Conversa não encontrada.',
        ]);
    }

    public static function sendDirectMessage(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        if (!self::canUseSupportAccount($obUser)) {
            return self::json(403, [
                'success' => false,
                'message' => 'Você não tem permissão para enviar pela conta central de suporte.',
            ]);
        }

        $input = self::jsonInput();
        $accountId = (int)($input['account_id'] ?? 0);
        $to = self::normalizePhone((string)($input['to'] ?? ''));
        $messageType = strtolower((string)($input['message_type'] ?? 'text'));
        $message = trim((string)($input['message'] ?? ''));
        $templateName = trim((string)($input['template_name'] ?? ''));
        $templateLanguage = trim((string)($input['template_language'] ?? 'pt_BR')) ?: 'pt_BR';
        $templateComponents = $input['template_components'] ?? [];
        $contactName = self::nullableString($input['name'] ?? null);

        if ($accountId <= 0) {
            return self::json(422, [
                'success' => false,
                'message' => 'Selecione uma conta WhatsApp.',
            ]);
        }

        if (strlen($to) < 8 || strlen($to) > 15) {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe o número com DDI e DDD.',
            ]);
        }

        if (!in_array($messageType, ['text', 'template'], true)) {
            return self::json(422, [
                'success' => false,
                'message' => 'Tipo de mensagem inválido.',
            ]);
        }

        if ($messageType === 'text' && $message === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Digite a mensagem.',
            ]);
        }

        if ($messageType === 'template' && $templateName === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe o template aprovado da Meta.',
            ]);
        }

        if ($messageType === 'template' && is_string($templateComponents) && trim($templateComponents) !== '') {
            $decodedComponents = json_decode($templateComponents, true);
            if (!is_array($decodedComponents)) {
                return self::json(422, [
                    'success' => false,
                    'message' => 'Componentes do template precisa ser um JSON válido.',
                ]);
            }
            $templateComponents = $decodedComponents;
        }

        if (!is_array($templateComponents)) {
            $templateComponents = [];
        }

        $account = WhatsAppAccount::getForUser($accountId, $obUser);
        if (!$account) {
            return self::json(404, [
                'success' => false,
                'message' => 'Conta WhatsApp não encontrada.',
            ]);
        }

        $messageBody = $messageType === 'template'
            ? "[Template] {$templateName} ({$templateLanguage})"
            : $message;

        $conversationId = WhatsAppConversation::findOrCreate([
            'tenancy_id' => $obUser['tenancy_id'],
            'user_id' => (int)$obUser['id'],
            'account_id' => $accountId,
            'contact_phone' => $to,
            'contact_name' => $contactName,
            'last_message' => $messageBody,
            'last_direction' => 'outbound',
            'unread_count' => 0,
        ]);

        try {
            $api = new MetaWhatsAppCloudApi();
            if ($messageType === 'template') {
                $result = $api->sendTemplate(
                    (string)$account['access_token'],
                    (string)$account['phone_number_id'],
                    $to,
                    $templateName,
                    $templateLanguage,
                    $templateComponents
                );
            } else {
                $result = $api->sendText(
                    (string)$account['access_token'],
                    (string)$account['phone_number_id'],
                    $to,
                    $message
                );
            }

            $wamid = $result['data']['messages'][0]['id'] ?? null;
            WhatsAppConversation::addMessage([
                'conversation_id' => $conversationId,
                'account_id' => $accountId,
                'wamid' => $wamid,
                'direction' => 'outbound',
                'message_type' => $messageType,
                'body' => $messageBody,
                'status' => $result['ok'] ? 'sent' : 'failed',
                'error_message' => $result['ok'] ? null : ($result['error'] ?? 'Falha no envio.'),
                'payload' => $result['data'] ?? [],
            ]);

            return self::json($result['ok'] ? 200 : 502, [
                'success' => $result['ok'],
                'message' => $result['ok']
                    ? ($messageType === 'template' ? 'Template enviado.' : 'Mensagem enviada.')
                    : 'A Meta recusou o envio. Use template aprovado para iniciar conversa fora da janela de 24h.',
                'conversation_id' => $conversationId,
                'wamid' => $wamid,
                'meta' => $result,
            ]);
        } catch (\Throwable $e) {
            WhatsAppConversation::addMessage([
                'conversation_id' => $conversationId,
                'account_id' => $accountId,
                'direction' => 'outbound',
                'message_type' => $messageType,
                'body' => $messageBody,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return self::json(502, [
                'success' => false,
                'message' => 'Falha ao enviar mensagem pela Meta.',
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function verifyWebhook($request): Response
    {
        $query = $request->getQueryParams();
        $mode = (string)($query['hub_mode'] ?? $query['hub.mode'] ?? '');
        $token = (string)($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = (string)($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');

        if ($mode === 'subscribe' && hash_equals(WhatsAppConfig::webhookVerifyToken(), $token)) {
            return new Response(200, $challenge);
        }

        return new Response(403, 'Invalid verify token');
    }

    public static function receiveWebhook(): Response
    {
        $payload = self::jsonInput();
        $processed = 0;

        try {
            foreach (($payload['entry'] ?? []) as $entry) {
                foreach (($entry['changes'] ?? []) as $change) {
                    $value = $change['value'] ?? [];
                    $phoneNumberId = (string)($value['metadata']['phone_number_id'] ?? '');
                    if ($phoneNumberId === '') {
                        continue;
                    }

                    $account = WhatsAppAccount::getByPhoneNumberId($phoneNumberId);
                    if (!$account) {
                        continue;
                    }

                    foreach (($value['messages'] ?? []) as $message) {
                        if (self::storeInboundWebhookMessage($account, $message, $value)) {
                            $processed++;
                        }
                    }

                    foreach (($value['statuses'] ?? []) as $status) {
                        if (self::storeWebhookMessageStatus($status)) {
                            $processed++;
                        }
                    }
                }
            }

            return self::json(200, [
                'success' => true,
                'processed' => $processed,
            ]);
        } catch (\Throwable $e) {
            return self::json(200, [
                'success' => false,
                'processed' => $processed,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function storeInboundWebhookMessage(array $account, array $message, array $value): bool
    {
        $from = self::normalizePhone((string)($message['from'] ?? ''));
        if ($from === '') {
            return false;
        }

        $contactName = null;
        foreach (($value['contacts'] ?? []) as $contact) {
            if (($contact['wa_id'] ?? '') === $from) {
                $contactName = self::nullableString($contact['profile']['name'] ?? null);
                break;
            }
        }

        $body = self::extractWebhookMessageBody($message);
        $messageType = (string)($message['type'] ?? 'unknown');

        $conversationId = WhatsAppConversation::findOrCreate([
            'tenancy_id' => $account['tenancy_id'],
            'user_id' => (int)$account['user_id'],
            'account_id' => (int)$account['id'],
            'contact_phone' => $from,
            'contact_name' => $contactName,
            'last_message' => $body,
            'last_direction' => 'inbound',
            'unread_count' => 1,
        ]);

        try {
            WhatsAppConversation::addMessage([
                'conversation_id' => $conversationId,
                'account_id' => (int)$account['id'],
                'wamid' => $message['id'] ?? null,
                'direction' => 'inbound',
                'message_type' => $messageType,
                'body' => $body,
                'status' => 'received',
                'payload' => $message,
            ]);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private static function storeWebhookMessageStatus(array $status): bool
    {
        $wamid = (string)($status['id'] ?? '');
        $statusName = (string)($status['status'] ?? '');
        $errorMessage = null;

        if (!empty($status['errors'][0]['message'])) {
            $errorMessage = (string)$status['errors'][0]['message'];
        } elseif (!empty($status['errors'][0]['title'])) {
            $errorMessage = (string)$status['errors'][0]['title'];
        }

        return WhatsAppConversation::updateMessageStatusByWamid($wamid, $statusName, $errorMessage, $status);
    }

    private static function extractWebhookMessageBody(array $message): string
    {
        $type = (string)($message['type'] ?? '');

        return match ($type) {
            'text' => (string)($message['text']['body'] ?? ''),
            'button' => (string)($message['button']['text'] ?? $message['button']['payload'] ?? '[Botão]'),
            'interactive' => (string)(
                $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? '[Interativo]'
            ),
            'image' => (string)($message['image']['caption'] ?? '[Imagem]'),
            'audio' => '[Áudio]',
            'video' => (string)($message['video']['caption'] ?? '[Vídeo]'),
            'document' => (string)($message['document']['filename'] ?? '[Documento]'),
            'sticker' => '[Sticker]',
            'location' => '[Localização]',
            default => $type !== '' ? "[{$type}]" : '[Mensagem]',
        };
    }

    public static function createCampaign(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $input = self::jsonInput();
        $accountId = (int)($input['account_id'] ?? 0);
        $account = $accountId > 0 ? WhatsAppAccount::getForUser($accountId, $obUser) : null;

        if (!$account) {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe um número WhatsApp válido.',
            ]);
        }

        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe o nome da campanha.',
            ]);
        }

        $messageType = strtolower((string)($input['message_type'] ?? 'template'));
        if (!in_array($messageType, ['text', 'template'], true)) {
            return self::json(422, [
                'success' => false,
                'message' => 'Tipo de mensagem inválido.',
            ]);
        }

        if ($messageType === 'text' && trim((string)($input['message_body'] ?? '')) === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe o texto da mensagem.',
            ]);
        }

        if ($messageType === 'template' && trim((string)($input['template_name'] ?? '')) === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe o nome do template aprovado na Meta.',
            ]);
        }

        $templateComponents = $input['template_components'] ?? [];
        if ($messageType === 'template' && is_string($templateComponents) && trim($templateComponents) !== '') {
            $decodedComponents = json_decode($templateComponents, true);
            if (!is_array($decodedComponents)) {
                return self::json(422, [
                    'success' => false,
                    'message' => 'Componentes do template precisa ser um JSON válido.',
                ]);
            }
            $templateComponents = $decodedComponents;
        }
        if (!is_array($templateComponents)) {
            $templateComponents = [];
        }

        $recipients = self::normalizeRecipients($input['recipients'] ?? []);
        if ($recipients === []) {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe ao menos um destinatário válido.',
            ]);
        }

        try {
            $campaignId = WhatsAppCampaign::create([
                'tenancy_id' => $obUser['tenancy_id'],
                'user_id' => (int)$obUser['id'],
                'account_id' => $accountId,
                'name' => $name,
                'message_type' => $messageType,
                'message_body' => self::nullableString($input['message_body'] ?? null),
                'template_name' => self::nullableString($input['template_name'] ?? null),
                'template_language' => self::nullableString($input['template_language'] ?? 'pt_BR'),
                'template_components' => $templateComponents,
                'total_recipients' => count($recipients),
                'status' => 'draft',
            ]);

            foreach ($recipients as $recipient) {
                WhatsAppCampaign::addRecipient($campaignId, $recipient);
            }

            return self::json(201, [
                'success' => true,
                'message' => 'Campanha WhatsApp criada.',
                'id' => $campaignId,
                'total_recipients' => count($recipients),
            ]);
        } catch (\Throwable $e) {
            return self::json(500, [
                'success' => false,
                'message' => 'Falha ao criar campanha WhatsApp.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function sendCampaign($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $campaign = WhatsAppCampaign::getForUser((int)$id, $obUser);
        if (!$campaign) {
            return self::json(404, [
                'success' => false,
                'message' => 'Campanha WhatsApp não encontrada.',
            ]);
        }

        $account = WhatsAppAccount::getForUser((int)$campaign['account_id'], $obUser);
        if (!$account) {
            return self::json(404, [
                'success' => false,
                'message' => 'Número WhatsApp da campanha não encontrado.',
            ]);
        }

        $recipients = WhatsAppCampaign::getPendingRecipients((int)$campaign['id']);
        if ($recipients === []) {
            return self::json(200, [
                'success' => true,
                'message' => 'Campanha sem destinatários pendentes.',
            ]);
        }

        $api = new MetaWhatsAppCloudApi();
        WhatsAppCampaign::markStatus((int)$campaign['id'], 'sending');

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($recipients as $recipient) {
            try {
                if ($campaign['message_type'] === 'text') {
                    $result = $api->sendText(
                        (string)$account['access_token'],
                        (string)$account['phone_number_id'],
                        (string)$recipient['phone'],
                        (string)$campaign['message_body']
                    );
                } else {
                    $components = json_decode((string)($campaign['template_components'] ?? '[]'), true);
                    $result = $api->sendTemplate(
                        (string)$account['access_token'],
                        (string)$account['phone_number_id'],
                        (string)$recipient['phone'],
                        (string)$campaign['template_name'],
                        (string)($campaign['template_language'] ?: 'pt_BR'),
                        is_array($components) ? $components : []
                    );
                }

                $wamid = $result['data']['messages'][0]['id'] ?? null;
                if ($result['ok']) {
                    $sent++;
                    WhatsAppCampaign::updateRecipientResult((int)$recipient['id'], 'sent', $wamid);
                    continue;
                }

                $failed++;
                $error = $result['error'] ?? 'Falha no envio pela Meta.';
                $errors[] = ['phone' => $recipient['phone'], 'error' => $error];
                WhatsAppCampaign::updateRecipientResult((int)$recipient['id'], 'failed', null, $error);
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['phone' => $recipient['phone'], 'error' => $e->getMessage()];
                WhatsAppCampaign::updateRecipientResult((int)$recipient['id'], 'failed', null, $e->getMessage());
            }
        }

        WhatsAppCampaign::updateCounters((int)$campaign['id']);

        return self::json(200, [
            'success' => $failed === 0,
            'message' => $failed === 0 ? 'Campanha enviada.' : 'Campanha processada com falhas.',
            'sent' => $sent,
            'failed' => $failed,
            'errors' => array_slice($errors, 0, 20),
        ]);
    }

    public static function sendSupportMessage(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $input = self::jsonInput();
        $to = self::normalizePhone((string)($input['to'] ?? ''));
        $message = trim((string)($input['message'] ?? ''));

        if (strlen($to) < 8 || strlen($to) > 15) {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe seu WhatsApp com DDI e DDD.',
            ]);
        }

        if ($message === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Digite a mensagem para enviar.',
            ]);
        }

        $account = WhatsAppAccount::getSupportAccount();
        if (!$account) {
            return self::json(404, [
                'success' => false,
                'message' => 'Conta WhatsApp central do suporte não configurada.',
            ]);
        }

        try {
            $result = (new MetaWhatsAppCloudApi())->sendText(
                (string)$account['access_token'],
                (string)$account['phone_number_id'],
                $to,
                $message
            );

            return self::json($result['ok'] ? 200 : 502, [
                'success' => $result['ok'],
                'message' => $result['ok']
                    ? 'Mensagem enviada pelo WhatsApp.'
                    : 'A Meta recusou o envio. Para iniciar conversa fora da janela de 24h, use template aprovado.',
                'wamid' => $result['data']['messages'][0]['id'] ?? null,
                'meta' => $result,
            ]);
        } catch (\Throwable $e) {
            return self::json(502, [
                'success' => false,
                'message' => 'Falha ao enviar mensagem pela Meta.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function requireUser(): array|Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return self::json(401, [
                'success' => false,
                'message' => 'Usuário não autenticado.',
            ]);
        }

        return $obUser;
    }

    private static function canUseSupportAccount(array $user): bool
    {
        $role = strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));
        return in_array($role, ['super_admin', 'admin', 'support_l2'], true);
    }

    private static function json(int $status, array $payload): Response
    {
        return new Response($status, $payload, 'application/json');
    }

    private static function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);

        if (is_array($data)) {
            return $data;
        }

        return $_POST ?: [];
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private static function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }

    private static function normalizeRecipients(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\r\n,;]+/', $raw) ?: [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $recipients = [];
        foreach ($raw as $item) {
            $name = null;
            $phone = '';

            if (is_array($item)) {
                $phone = self::normalizePhone((string)($item['phone'] ?? $item['number'] ?? ''));
                $name = self::nullableString($item['name'] ?? null);
            } else {
                $phone = self::normalizePhone((string)$item);
            }

            if (strlen($phone) < 8 || strlen($phone) > 15 || isset($recipients[$phone])) {
                continue;
            }

            $recipients[$phone] = [
                'phone' => $phone,
                'name' => $name,
            ];
        }

        return array_values($recipients);
    }
}
