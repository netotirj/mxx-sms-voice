<?php

global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;

$obRouter->get('/webhooks/meta/whatsapp', [
    'name' => '/webhooks/meta/whatsapp',
    'middlewares' => [],
    function ($request) {
        return Pages\WhatsApp::verifyWebhook($request);
    }
]);

$obRouter->post('/webhooks/meta/whatsapp', [
    'name' => '/webhooks/meta/whatsapp',
    'middlewares' => [],
    function ($request) {
        return Pages\WhatsApp::receiveWebhook($request);
    }
]);

$obRouter->get('/campaign/whatsapp', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\WhatsApp::getComponentsWhatsApp($request));
    }
]);

$obRouter->get('/campaign/whatsapp/accounts', [
    'name' => '/campaign/whatsapp/accounts',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::listAccounts();
    }
]);

$obRouter->post('/campaign/whatsapp/accounts/sync-meta', [
    'name' => '/campaign/whatsapp/accounts/sync-meta',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::syncAccountsMeta();
    }
]);

$obRouter->get('/campaign/whatsapp/numbers', [
    'name' => '/campaign/whatsapp/numbers',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::listNumbers();
    }
]);

$obRouter->get('/campaign/whatsapp/numbers/health', [
    'name' => '/campaign/whatsapp/numbers/health',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::listNumberHealth();
    }
]);

$obRouter->post('/campaign/whatsapp/numbers/health/sync', [
    'name' => '/campaign/whatsapp/numbers/health/sync',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::syncNumberHealth();
    }
]);

$obRouter->post('/campaign/whatsapp/numbers/client', [
    'name' => '/campaign/whatsapp/numbers/client',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::registerClientNumber();
    }
]);

$obRouter->get('/campaign/whatsapp/number-requests', [
    'name' => '/campaign/whatsapp/number-requests',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::listNumberRequests();
    }
]);

$obRouter->post('/campaign/whatsapp/number-requests/{id}/send-meta', [
    'name' => '/campaign/whatsapp/number-requests/send-meta',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::sendNumberRequestToMeta($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/number-requests/{id}/approve', [
    'name' => '/campaign/whatsapp/number-requests/approve',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::approveNumberRequest($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/number-requests/{id}/reject', [
    'name' => '/campaign/whatsapp/number-requests/reject',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::rejectNumberRequest($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/number-requests/{id}/resend-code', [
    'name' => '/campaign/whatsapp/number-requests/resend-code',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::resendNumberRequestCode($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/number-requests/{id}/confirm-code', [
    'name' => '/campaign/whatsapp/number-requests/confirm-code',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::confirmNumberRequestCode($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/numbers/platform', [
    'name' => '/campaign/whatsapp/numbers/platform',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::createPlatformNumber();
    }
]);

$obRouter->post('/campaign/whatsapp/numbers/platform/{id}/assign', [
    'name' => '/campaign/whatsapp/numbers/platform/{id}/assign',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::assignPlatformNumber($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/numbers/{id}/send-code', [
    'name' => '/campaign/whatsapp/numbers/{id}/send-code',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::sendNumberVerificationCode($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/numbers/{id}/confirm-code', [
    'name' => '/campaign/whatsapp/numbers/{id}/confirm-code',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::confirmNumberVerificationCode($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/numbers/{id}/reveal-pin', [
    'name' => '/campaign/whatsapp/numbers/{id}/reveal-pin',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::revealNumberPin($request, $id);
    }
]);

$obRouter->post('/whatsapp/verification', [
    'name' => '/whatsapp/verification',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        $input = is_array($input) ? $input : $_POST;
        $id = $input['request_id'] ?? null;
        if ($id !== null && $id !== '') {
            return Pages\WhatsApp::resendNumberRequestCode($request, $id);
        }

        return Pages\WhatsApp::sendNumberVerificationCode($request, $input['number_id'] ?? $input['id'] ?? 0);
    }
]);

$obRouter->post('/whatsapp/confirm-number', [
    'name' => '/whatsapp/confirm-number',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        $input = is_array($input) ? $input : $_POST;
        $id = $input['request_id'] ?? null;
        if ($id !== null && $id !== '') {
            return Pages\WhatsApp::confirmNumberRequestCode($request, $id);
        }

        return Pages\WhatsApp::confirmNumberVerificationCode($request, $input['number_id'] ?? $input['id'] ?? 0);
    }
]);

$obRouter->post('/campaign/whatsapp/numbers/{id}/remove', [
    'name' => '/campaign/whatsapp/numbers/{id}/remove',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::removeNumber($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/accounts', [
    'name' => '/campaign/whatsapp/accounts/create',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::createAccount();
    }
]);

$obRouter->post('/campaign/whatsapp/accounts/embedded-signup/complete', [
    'name' => '/campaign/whatsapp/accounts/embedded-signup/complete',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::completeEmbeddedSignupAccount();
    }
]);

$obRouter->post('/whatsapp/connection', [
    'name' => '/whatsapp/connection',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::createAccount();
    }
]);

$obRouter->post('/campaign/whatsapp/accounts/{id}/test', [
    'name' => '/campaign/whatsapp/accounts/{id}/test',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::testAccount($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/accounts/{id}/settings', [
    'name' => '/campaign/whatsapp/accounts/{id}/settings',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::updateAccountSettings($request, $id);
    }
]);

$obRouter->get('/campaign/whatsapp/accounts/{id}/voice-status', [
    'name' => '/campaign/whatsapp/accounts/{id}/voice-status',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::getAccountVoiceStatus($request, $id);
    }
]);

$obRouter->get('/campaign/whatsapp/accounts/{id}/profile', [
    'name' => '/campaign/whatsapp/accounts/{id}/profile',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::getBusinessProfile($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/accounts/{id}/profile', [
    'name' => '/campaign/whatsapp/accounts/{id}/profile/update',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::updateBusinessProfile($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/accounts/{id}/profile/photo', [
    'name' => '/campaign/whatsapp/accounts/{id}/profile/photo',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::updateBusinessProfilePicture($request, $id);
    }
]);

$obRouter->get('/campaign/whatsapp/campaigns', [
    'name' => '/campaign/whatsapp/campaigns',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::listCampaigns();
    }
]);

$obRouter->get('/campaign/whatsapp/templates', [
    'name' => '/campaign/whatsapp/templates',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::listTemplates();
    }
]);

$obRouter->get('/campaign/whatsapp/templates/library', [
    'name' => '/campaign/whatsapp/templates/library',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::listTemplateModels();
    }
]);

$obRouter->post('/campaign/whatsapp/pricing/simulate', [
    'name' => '/campaign/whatsapp/pricing/simulate',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::simulatePricing();
    }
]);

$obRouter->post('/campaign/whatsapp/templates', [
    'name' => '/campaign/whatsapp/templates/create',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::createTemplate();
    }
]);

$obRouter->post('/campaign/whatsapp/templates/sync', [
    'name' => '/campaign/whatsapp/templates/sync',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::syncTemplates();
    }
]);

$obRouter->post('/campaign/whatsapp/templates/{id}/sync', [
    'name' => '/campaign/whatsapp/templates/{id}/sync',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::syncTemplate($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/templates/{id}/delete', [
    'name' => '/campaign/whatsapp/templates/{id}/delete',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::deleteTemplate($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/campaigns/recipients/preview', [
    'name' => '/campaign/whatsapp/campaigns/recipients/preview',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::previewCampaignRecipientsUpload();
    }
]);

$obRouter->post('/campaign/whatsapp/campaigns', [
    'name' => '/campaign/whatsapp/campaigns/create',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::createCampaign();
    }
]);

$obRouter->post('/campaign/whatsapp/campaigns/{id}/send', [
    'name' => '/campaign/whatsapp/campaigns/{id}/send',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::sendCampaign($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/campaigns/{id}/cancel-category', [
    'name' => '/campaign/whatsapp/campaigns/{id}/cancel-category',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::cancelCampaignCategory($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/support/send', [
    'name' => '/campaign/whatsapp/support/send',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::sendSupportMessage();
    }
]);

$obRouter->get('/campaign/whatsapp/support/queues', [
    'name' => '/campaign/whatsapp/support/queues',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::listSupportQueues();
    }
]);

$obRouter->post('/campaign/whatsapp/support/queues', [
    'name' => '/campaign/whatsapp/support/queues/create',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::createSupportQueue();
    }
]);

$obRouter->get('/campaign/whatsapp/support/queues/{id}', [
    'name' => '/campaign/whatsapp/support/queues/{id}',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::getSupportQueue($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/support/queues/{id}', [
    'name' => '/campaign/whatsapp/support/queues/{id}/update',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::updateSupportQueue($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/support/queues/{id}/delete', [
    'name' => '/campaign/whatsapp/support/queues/{id}/delete',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::deleteSupportQueue($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/support/queues/{id}/agents', [
    'name' => '/campaign/whatsapp/support/queues/{id}/agents',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::upsertSupportQueueAgent($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/support/queues/{id}/agents/remove', [
    'name' => '/campaign/whatsapp/support/queues/{id}/agents/remove',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::removeSupportQueueAgent($request, $id);
    }
]);

$obRouter->get('/campaign/whatsapp/support/assignable-users', [
    'name' => '/campaign/whatsapp/support/assignable-users',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::listSupportAssignableUsers();
    }
]);

$obRouter->post('/campaign/whatsapp/support/agents/status', [
    'name' => '/campaign/whatsapp/support/agents/status',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::updateSupportAgentStatus();
    }
]);

$obRouter->get('/campaign/whatsapp/support/dashboard', [
    'name' => '/campaign/whatsapp/support/dashboard',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::supportDashboard();
    }
]);

$obRouter->get('/campaign/whatsapp/support/events', [
    'name' => '/campaign/whatsapp/support/events',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::supportEvents($request);
    }
]);

$obRouter->get('/campaign/whatsapp/calls/permissions', [
    'name' => '/campaign/whatsapp/calls/permissions',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::getCallPermissions();
    }
]);

$obRouter->post('/campaign/whatsapp/calls/permissions/request', [
    'name' => '/campaign/whatsapp/calls/permissions/request',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::requestCallPermission();
    }
]);

$obRouter->get('/campaign/whatsapp/calls/sessions', [
    'name' => '/campaign/whatsapp/calls/sessions',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::listCallSessions($request);
    }
]);

$obRouter->get('/campaign/whatsapp/calls/sessions/{id}', [
    'name' => '/campaign/whatsapp/calls/sessions/{id}',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::getCallSession($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/calls/connect', [
    'name' => '/campaign/whatsapp/calls/connect',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::initiateCall();
    }
]);

$obRouter->post('/campaign/whatsapp/calls/{id}/pre-accept', [
    'name' => '/campaign/whatsapp/calls/{id}/pre-accept',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::preAcceptCall($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/calls/{id}/accept', [
    'name' => '/campaign/whatsapp/calls/{id}/accept',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::acceptCall($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/calls/{id}/reject', [
    'name' => '/campaign/whatsapp/calls/{id}/reject',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::rejectCall($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/calls/{id}/terminate', [
    'name' => '/campaign/whatsapp/calls/{id}/terminate',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::terminateCall($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/support/sessions/{id}/finish', [
    'name' => '/campaign/whatsapp/support/sessions/{id}/finish',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::finishSupportSession($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/support/sessions/{id}/transfer', [
    'name' => '/campaign/whatsapp/support/sessions/{id}/transfer',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::transferSupportSession($request, $id);
    }
]);

$obRouter->get('/campaign/whatsapp/conversations', [
    'name' => '/campaign/whatsapp/conversations',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::listConversations($request);
    }
]);

$obRouter->get('/campaign/whatsapp/conversations/{id}/messages', [
    'name' => '/campaign/whatsapp/conversations/{id}/messages',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::listMessages($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/conversations/{id}/read', [
    'name' => '/campaign/whatsapp/conversations/{id}/read',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::markConversationRead($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/conversations/{id}/unread', [
    'name' => '/campaign/whatsapp/conversations/{id}/unread',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::markConversationUnread($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/conversations/{id}/delete', [
    'name' => '/campaign/whatsapp/conversations/{id}/delete',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::deleteConversation($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/conversations/{id}/queue', [
    'name' => '/campaign/whatsapp/conversations/{id}/queue',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::assignConversationQueue($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/conversations/{id}/claim', [
    'name' => '/campaign/whatsapp/conversations/{id}/claim',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::claimConversationQueue($request, $id);
    }
]);

$obRouter->get('/campaign/whatsapp/conversations/{id}/queue-history', [
    'name' => '/campaign/whatsapp/conversations/{id}/queue-history',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::conversationQueueHistory($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/messages/send', [
    'name' => '/campaign/whatsapp/messages/send',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::sendDirectMessage();
    }
]);

$obRouter->post('/whatsapp/send-message', [
    'name' => '/whatsapp/send-message',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::sendDirectMessage();
    }
]);
