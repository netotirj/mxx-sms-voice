<?php

namespace App\Controller\Pages;


use App\Config\WhatsAppConfig;
use App\Http\Response;
use App\Model\Entity\WhatsAppAccount;
use App\Model\Entity\WhatsAppCampaign;
use App\Model\Entity\WhatsAppConversation;
use App\Model\Entity\WhatsAppOutbox;
use App\Model\Entity\WhatsAppTemplate;
use App\Service\MetaWhatsAppCloudApi;
use App\Service\WhatsAppCostPolicy;
use App\Service\WhatsAppMessagePlanner;
use App\Service\WhatsAppNumberManager;
use App\Service\WhatsAppNumberSafety;
use App\Service\WhatsAppOutboxWorker;
use App\Service\WhatsAppSupportDesk;
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

    public static function listNumbers(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppNumberManager::listForUser($obUser),
            'available_platform_numbers' => self::canManageWhatsAppNumbers($obUser)
                ? WhatsAppNumberManager::listAvailablePlatformNumbers()
                : [],
        ]);
    }

    public static function listNumberHealth(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppNumberSafety::listForUser($obUser),
        ]);
    }

    public static function syncNumberHealth(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'message' => 'Qualidade dos números atualizada.',
            'data' => WhatsAppNumberSafety::syncAllForUser($obUser),
        ]);
    }

    public static function registerClientNumber(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        try {
            $number = WhatsAppNumberManager::registerClientNumber($obUser, self::jsonInput());

            return self::json(201, [
                'success' => true,
                'message' => empty($number['meta_id'])
                    ? 'Solicitação recebida. O administrador fará a conexão do número.'
                    : 'Número recebido. Agora envie o código de confirmação.',
                'data' => $number,
            ]);
        } catch (\InvalidArgumentException $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return self::json(409, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function listNumberRequests(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppNumberManager::listNumberRequests($obUser),
            'can_manage_numbers' => self::canManageWhatsAppNumbers($obUser),
        ]);
    }

    public static function approveNumberRequest($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        if (!self::canManageWhatsAppNumbers($obUser)) {
            return self::json(403, [
                'success' => false,
                'message' => 'Ação permitida apenas para administrador.',
            ]);
        }

        try {
            return self::json(200, [
                'success' => true,
                'message' => 'Solicitação aprovada. Agora envie o código de confirmação.',
                'data' => WhatsAppNumberManager::approveNumberRequest($obUser, (int)$id),
            ]);
        } catch (\Throwable $e) {
            return self::json(409, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function rejectNumberRequest($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        if (!self::canManageWhatsAppNumbers($obUser)) {
            return self::json(403, [
                'success' => false,
                'message' => 'Ação permitida apenas para administrador.',
            ]);
        }

        try {
            $input = self::jsonInput();
            return self::json(200, [
                'success' => true,
                'message' => 'Solicitação recusada.',
                'data' => WhatsAppNumberManager::rejectNumberRequest($obUser, (int)$id, (string)($input['reason'] ?? '')),
            ]);
        } catch (\Throwable $e) {
            return self::json(409, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function sendNumberVerificationCode($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $input = self::jsonInput();

        try {
            if (!self::canManageWhatsAppNumbers($obUser)) {
                return self::json(403, [
                    'success' => false,
                    'message' => 'Ação permitida apenas para administrador.',
                ]);
            }

            $number = WhatsAppNumberManager::sendVerificationCode(
                $obUser,
                (int)$id,
                (string)($input['method'] ?? 'SMS')
            );

            return self::json(200, [
                'success' => true,
                'message' => 'Código enviado.',
                'data' => $number,
            ]);
        } catch (\Throwable $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function confirmNumberVerificationCode($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $input = self::jsonInput();

        try {
            if (!self::canManageWhatsAppNumbers($obUser)) {
                return self::json(403, [
                    'success' => false,
                    'message' => 'Ação permitida apenas para administrador.',
                ]);
            }

            $number = WhatsAppNumberManager::confirmVerificationCode(
                $obUser,
                (int)$id,
                (string)($input['code'] ?? '')
            );

            return self::json(200, [
                'success' => true,
                'message' => 'Número conectado.',
                'data' => $number,
            ]);
        } catch (\InvalidArgumentException $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return self::json(409, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function createPlatformNumber(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        if (!self::canManageWhatsAppNumbers($obUser)) {
            return self::json(403, [
                'success' => false,
                'message' => 'Você não tem permissão para cadastrar números da plataforma.',
            ]);
        }

        try {
            $number = WhatsAppNumberManager::createPlatformNumber($obUser, self::jsonInput());

            return self::json(201, [
                'success' => true,
                'message' => 'Número da plataforma cadastrado.',
                'data' => $number,
            ]);
        } catch (\InvalidArgumentException $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return self::json(409, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function assignPlatformNumber($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        try {
            if (!self::canManageWhatsAppNumbers($obUser)) {
                return self::json(403, [
                    'success' => false,
                    'message' => 'Ação permitida apenas para administrador.',
                ]);
            }

            $number = WhatsAppNumberManager::assignPlatformNumber($obUser, (int)$id, self::jsonInput());

            return self::json(200, [
                'success' => true,
                'message' => 'Número conectado.',
                'data' => $number,
            ]);
        } catch (\Throwable $e) {
            return self::json(409, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function removeNumber($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        try {
            if (!self::canManageWhatsAppNumbers($obUser)) {
                return self::json(403, [
                    'success' => false,
                    'message' => 'Ação permitida apenas para administrador.',
                ]);
            }

            $number = WhatsAppNumberManager::removeNumberFromMeta($obUser, (int)$id);

            return self::json(200, [
                'success' => true,
                'message' => 'Número removido.',
                'data' => $number,
            ]);
        } catch (\Throwable $e) {
            return self::json(409, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function createAccount(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        if (!self::canManageWhatsAppNumbers($obUser)) {
            return self::json(403, [
                'success' => false,
                'message' => 'Ação permitida apenas para administrador.',
            ]);
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
                'message' => 'Falha ao consultar dados do número.',
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
        $category = WhatsAppCostPolicy::normalizeCategory((string)($input['category'] ?? 'UTILITY'));
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

        $templateCategory = null;
        if ($messageType === 'template') {
            $template = WhatsAppTemplate::getByNameForUser($templateName, $templateLanguage, $obUser);
            if ($template && (string)$template['status'] !== 'approved') {
                return self::json(422, [
                    'success' => false,
                    'message' => 'Template cadastrado, mas ainda não está aprovado.',
                ]);
            }

            $templateCategory = WhatsAppCostPolicy::normalizeCategory($template['category'] ?? 'MARKETING');
        }

        $lastInboundAt = WhatsAppConversation::getLastInboundAt($accountId, $to);
        $serviceWindowOpen = WhatsAppCostPolicy::isServiceWindowOpen($lastInboundAt);

        if ($messageType === 'text' && !$serviceWindowOpen) {
            return self::json(422, [
                'success' => false,
                'message' => 'Mensagem de texto livre bloqueada: fora da janela gratuita de 24h. Use um template aprovado.',
                'last_inbound_at' => $lastInboundAt,
            ]);
        }

        if (
            $messageType === 'template'
            && $templateCategory === WhatsAppCostPolicy::CATEGORY_MARKETING
            && WhatsAppConversation::hasMarketingOptOut($accountId, $to)
        ) {
            return self::json(409, [
                'success' => false,
                'message' => 'Envio de marketing bloqueado: destinatário solicitou descadastro.',
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

        $plannedMessages = $messageType === 'template'
            ? WhatsAppMessagePlanner::planTemplate($templateName, $templateLanguage, $templateCategory, $templateComponents, $template['body'] ?? null)
            : WhatsAppMessagePlanner::planText($message, $serviceWindowOpen);

        $outboxIds = [];
        $estimatedCost = 0.0;
        foreach ($plannedMessages as $planned) {
            if (
                ($planned['template_category'] ?? null) === WhatsAppCostPolicy::CATEGORY_MARKETING
                && WhatsAppConversation::hasMarketingOptOut($accountId, $to)
            ) {
                continue;
            }

            $outboxIds[] = self::enqueuePlannedWhatsAppMessage([
                'tenancy_id' => $obUser['tenancy_id'],
                'user_id' => (int)$obUser['id'],
                'account_id' => $accountId,
                'conversation_id' => $conversationId,
                'contact_phone' => $to,
                'contact_name' => $contactName,
            ], $planned, $serviceWindowOpen);

            $estimatedCost += WhatsAppCostPolicy::estimateBrazilCostUsd(
                (string)$planned['message_type'],
                $planned['template_category'] ?? null,
                $serviceWindowOpen
            );
        }

        return self::json(202, [
            'success' => true,
            'message' => 'Mensagem desmembrada e enfileirada.',
            'conversation_id' => $conversationId,
            'outbox_ids' => $outboxIds,
            'parts' => $plannedMessages,
            'counters' => WhatsAppMessagePlanner::summarize($plannedMessages),
            'estimated_cost_usd' => round($estimatedCost, 4),
        ]);
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

                    if (self::storeWebhookNumberQuality($value)) {
                        $processed++;
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
        if ($messageType === 'text' && WhatsAppCostPolicy::looksLikeOptOut($body)) {
            WhatsAppConversation::registerMarketingOptOut((int)$account['id'], $from, $message['id'] ?? null);
        }

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

        WhatsAppSupportDesk::handleInboundConversation($conversationId, $account, $body);

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

    private static function storeWebhookNumberQuality(array $value): bool
    {
        $hasQuality = isset($value['quality_rating'])
            || isset($value['current_quality_update_event'])
            || isset($value['messaging_limit_tier']);

        if (!$hasQuality) {
            return false;
        }

        try {
            WhatsAppNumberSafety::handleMetaWebhook($value);
            return true;
        } catch (\Throwable) {
            return false;
        }
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

        $templateCategory = null;
        if ($messageType === 'template') {
            $template = WhatsAppTemplate::getByNameForUser(
                trim((string)$input['template_name']),
                trim((string)($input['template_language'] ?? 'pt_BR')) ?: 'pt_BR',
                $obUser
            );
            $templateCategory = WhatsAppCostPolicy::normalizeCategory($template['category'] ?? 'MARKETING');
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
                'template_category' => $templateCategory,
                'template_components' => $templateComponents,
                'scheduled_at' => self::nullableString($input['scheduled_at'] ?? null),
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

        WhatsAppCampaign::markStatus((int)$campaign['id'], 'queued');

        $queued = 0;
        $failed = 0;
        $errors = [];
        $estimatedCostUsd = 0.0;
        $templateCategory = null;
        $template = null;

        if ($campaign['message_type'] === 'template') {
            $template = WhatsAppTemplate::getByNameForUser(
                (string)$campaign['template_name'],
                (string)($campaign['template_language'] ?: 'pt_BR'),
                $obUser
            );
            $templateCategory = WhatsAppCostPolicy::normalizeCategory($template['category'] ?? 'MARKETING');

            if ($template && (string)$template['status'] !== 'approved') {
                WhatsAppCampaign::markStatus((int)$campaign['id'], 'failed');

                return self::json(422, [
                    'success' => false,
                    'message' => 'Template cadastrado, mas ainda não está aprovado.',
                ]);
            }
        }

        foreach ($recipients as $recipient) {
            try {
                $recipientPhone = (string)$recipient['phone'];
                $lastInboundAt = WhatsAppConversation::getLastInboundAt((int)$account['id'], $recipientPhone);
                $serviceWindowOpen = WhatsAppCostPolicy::isServiceWindowOpen($lastInboundAt);

                if ($campaign['message_type'] === 'text' && !$serviceWindowOpen) {
                    $failed++;
                    $error = 'Texto livre bloqueado: destinatário fora da janela gratuita de 24h.';
                    $errors[] = ['phone' => $recipientPhone, 'error' => $error];
                    WhatsAppCampaign::updateRecipientResult((int)$recipient['id'], 'failed', null, $error);
                    continue;
                }

                if (
                    $campaign['message_type'] === 'template'
                    && $templateCategory === WhatsAppCostPolicy::CATEGORY_MARKETING
                    && WhatsAppConversation::hasMarketingOptOut((int)$account['id'], $recipientPhone)
                ) {
                    $failed++;
                    $error = 'Marketing bloqueado: destinatário solicitou descadastro.';
                    $errors[] = ['phone' => $recipientPhone, 'error' => $error];
                    WhatsAppCampaign::updateRecipientResult((int)$recipient['id'], 'failed', null, $error);
                    continue;
                }

                $components = json_decode((string)($campaign['template_components'] ?? '[]'), true);
                $plannedMessages = $campaign['message_type'] === 'template'
                    ? WhatsAppMessagePlanner::planTemplate(
                        (string)$campaign['template_name'],
                        (string)($campaign['template_language'] ?: 'pt_BR'),
                        $templateCategory,
                        is_array($components) ? $components : [],
                        $template['body'] ?? null
                    )
                    : WhatsAppMessagePlanner::planText((string)$campaign['message_body'], $serviceWindowOpen);

                foreach ($plannedMessages as $planned) {
                    if (
                        ($planned['template_category'] ?? null) === WhatsAppCostPolicy::CATEGORY_MARKETING
                        && WhatsAppConversation::hasMarketingOptOut((int)$account['id'], $recipientPhone)
                    ) {
                        continue;
                    }

                    self::enqueuePlannedWhatsAppMessage(
                        [
                            'tenancy_id' => $obUser['tenancy_id'],
                            'user_id' => (int)$obUser['id'],
                            'account_id' => (int)$account['id'],
                            'campaign_id' => (int)$campaign['id'],
                            'campaign_recipient_id' => (int)$recipient['id'],
                            'contact_phone' => $recipientPhone,
                            'contact_name' => $recipient['name'] ?? null,
                        ],
                        $planned,
                        $serviceWindowOpen
                    );

                    $queued++;
                    $estimatedCostUsd += WhatsAppCostPolicy::estimateBrazilCostUsd(
                        (string)$planned['message_type'],
                        $planned['template_category'] ?? null,
                        $serviceWindowOpen
                    );
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['phone' => $recipient['phone'], 'error' => $e->getMessage()];
                WhatsAppCampaign::updateRecipientResult((int)$recipient['id'], 'failed', null, $e->getMessage());
            }
        }

        WhatsAppCampaign::updateCounters((int)$campaign['id']);

        return self::json(200, [
            'success' => $failed === 0,
            'message' => $failed === 0 ? 'Campanha desmembrada e enfileirada.' : 'Campanha enfileirada parcialmente.',
            'queued' => $queued,
            'failed' => $failed,
            'estimated_cost_usd' => round($estimatedCostUsd, 4),
            'projected_daily_cost_usd' => round($estimatedCostUsd, 4),
            'projected_monthly_cost_usd' => round($estimatedCostUsd * 30, 4),
            'template_category' => $templateCategory,
            'errors' => array_slice($errors, 0, 20),
        ]);
    }

    public static function cancelCampaignCategory($request, int|string $id): Response
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

        $input = self::jsonInput();
        $category = WhatsAppCostPolicy::normalizeCategory((string)($input['category'] ?? 'MARKETING'));
        $cancelled = WhatsAppOutbox::cancelByCampaignAndCategory((int)$campaign['id'], $category);

        return self::json(200, [
            'success' => true,
            'message' => 'Mensagens canceladas por categoria.',
            'category' => $category,
            'cancelled' => $cancelled,
        ]);
    }

    public static function listSupportQueues(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppSupportDesk::listQueuesForUser($obUser),
        ]);
    }

    public static function createSupportQueue(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        try {
            $id = WhatsAppSupportDesk::createQueue($obUser, self::jsonInput());

            return self::json(201, [
                'success' => true,
                'message' => 'Fila de atendimento criada.',
                'id' => $id,
            ]);
        } catch (\InvalidArgumentException $e) {
            return self::json(422, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            return self::json(500, [
                'success' => false,
                'message' => 'Falha ao criar fila de atendimento.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function upsertSupportQueueAgent($request, int|string $queueId): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $ok = WhatsAppSupportDesk::upsertQueueAgent($obUser, (int)$queueId, self::jsonInput());

        return self::json($ok ? 200 : 404, [
            'success' => $ok,
            'message' => $ok ? 'Atendente vinculado à fila.' : 'Fila de atendimento não encontrada.',
        ]);
    }

    public static function updateSupportAgentStatus(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $ok = WhatsAppSupportDesk::updateAgentStatus($obUser, self::jsonInput());

        return self::json($ok ? 200 : 404, [
            'success' => $ok,
            'message' => $ok ? 'Status do atendente atualizado.' : 'Fila de atendimento não encontrada.',
        ]);
    }

    public static function supportDashboard(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppSupportDesk::dashboard($obUser),
        ]);
    }

    public static function finishSupportSession($request, int|string $sessionId): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $ok = WhatsAppSupportDesk::finishSession($obUser, (int)$sessionId);

        return self::json($ok ? 200 : 404, [
            'success' => $ok,
            'message' => $ok ? 'Atendimento finalizado.' : 'Sessão de atendimento não encontrada.',
        ]);
    }

    public static function transferSupportSession($request, int|string $sessionId): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $ok = WhatsAppSupportDesk::transferSession($obUser, (int)$sessionId, self::jsonInput());

        return self::json($ok ? 200 : 404, [
            'success' => $ok,
            'message' => $ok ? 'Atendimento transferido.' : 'Sessão, fila ou atendente indisponível.',
        ]);
    }

    public static function supportEvents($request): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $query = $request->getQueryParams();
        $headers = $request->getHeaders();
        $afterId = (int)($query['after_id'] ?? $query['lastEventId'] ?? $headers['Last-Event-ID'] ?? $headers['Last-Event-Id'] ?? 0);
        $events = WhatsAppSupportDesk::events($obUser, $afterId);
        $content = "retry: 2000\n";

        foreach ($events as $event) {
            $content .= 'id: ' . (int)$event['id'] . "\n";
            $content .= 'event: ' . (string)$event['event_type'] . "\n";
            $content .= 'data: ' . ($event['payload'] ?: '{}') . "\n\n";
        }

        if ($events === []) {
            $content .= "event: heartbeat\n";
            $content .= 'data: {"ok":true}' . "\n\n";
        }

        $response = new Response(200, $content, 'text/event-stream');
        $response->addHeader('Cache-Control', 'no-cache');
        $response->addHeader('X-Accel-Buffering', 'no');

        return $response;
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

        $lastInboundAt = WhatsAppConversation::getLastInboundAt((int)$account['id'], $to);
        if (!WhatsAppCostPolicy::isServiceWindowOpen($lastInboundAt)) {
            return self::json(422, [
                'success' => false,
                'message' => 'Mensagem de suporte bloqueada: fora da janela gratuita de 24h. Use um template aprovado para reabrir a conversa.',
                'last_inbound_at' => $lastInboundAt,
            ]);
        }

        $serviceWindowOpen = true;
        $conversationId = WhatsAppConversation::findOrCreate([
            'tenancy_id' => $account['tenancy_id'],
            'user_id' => (int)$account['user_id'],
            'account_id' => (int)$account['id'],
            'contact_phone' => $to,
            'last_message' => $message,
            'last_direction' => 'outbound',
            'unread_count' => 0,
        ]);

        $plannedMessages = WhatsAppMessagePlanner::planText($message, $serviceWindowOpen);
        $outboxIds = [];
        foreach ($plannedMessages as $planned) {
            $outboxIds[] = self::enqueuePlannedWhatsAppMessage([
                'tenancy_id' => $account['tenancy_id'],
                'user_id' => (int)$account['user_id'],
                'account_id' => (int)$account['id'],
                'conversation_id' => $conversationId,
                'contact_phone' => $to,
            ], $planned, $serviceWindowOpen);
        }

        return self::json(202, [
            'success' => true,
            'message' => 'Mensagem de suporte desmembrada e enfileirada.',
            'conversation_id' => $conversationId,
            'outbox_ids' => $outboxIds,
            'parts' => $plannedMessages,
            'counters' => WhatsAppMessagePlanner::summarize($plannedMessages),
        ]);
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

    private static function canManageWhatsAppNumbers(array $user): bool
    {
        $role = strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));
        return $role === 'super_admin';
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

    private static function enqueuePlannedWhatsAppMessage(array $base, array $planned, bool $serviceWindowOpen): int
    {
        $category = $planned['template_category'] ?? null;
        $estimatedCost = WhatsAppCostPolicy::estimateBrazilCostUsd(
            (string)$planned['message_type'],
            $category,
            $serviceWindowOpen
        );

        return WhatsAppOutbox::enqueue(array_merge($base, [
            'sequence' => $planned['sequence'],
            'message_type' => $planned['message_type'],
            'body' => $planned['body'],
            'template_name' => $planned['template_name'],
            'template_language' => $planned['template_language'] ?? 'pt_BR',
            'template_category' => $category,
            'template_components' => $planned['template_components'] ?? [],
            'service_window_open' => $serviceWindowOpen ? 1 : 0,
            'billable_estimate' => $estimatedCost > 0 ? 1 : 0,
            'estimated_cost_usd' => $estimatedCost,
        ]));
    }
}
