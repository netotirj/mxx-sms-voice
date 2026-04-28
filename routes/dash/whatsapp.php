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
        return Pages\WhatsApp::receiveWebhook();
    }
]);

$obRouter->get('/campaign/whatsapp', [
    'name' => '/campaign/whatsapp', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\WhatsApp::getComponentsWhatsApp($request));
    }
]);

$obRouter->get('/campaign/whatsapp/accounts', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::listAccounts();
    }
]);

$obRouter->post('/campaign/whatsapp/accounts', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::createAccount();
    }
]);

$obRouter->post('/campaign/whatsapp/accounts/{id}/test', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::testAccount($request, $id);
    }
]);

$obRouter->get('/campaign/whatsapp/campaigns', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::listCampaigns();
    }
]);

$obRouter->get('/campaign/whatsapp/templates', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::listTemplates();
    }
]);

$obRouter->post('/campaign/whatsapp/templates', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::createTemplate();
    }
]);

$obRouter->post('/campaign/whatsapp/templates/{id}/delete', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::deleteTemplate($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/campaigns', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::createCampaign();
    }
]);

$obRouter->post('/campaign/whatsapp/campaigns/{id}/send', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::sendCampaign($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/support/send', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::sendSupportMessage();
    }
]);

$obRouter->get('/campaign/whatsapp/conversations', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::listConversations($request);
    }
]);

$obRouter->get('/campaign/whatsapp/conversations/{id}/messages', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::listMessages($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/conversations/{id}/read', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::markConversationRead($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/conversations/{id}/unread', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::markConversationUnread($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/conversations/{id}/delete', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\WhatsApp::deleteConversation($request, $id);
    }
]);

$obRouter->post('/campaign/whatsapp/messages/send', [
    'name' => '/campaign/whatsapp',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\WhatsApp::sendDirectMessage();
    }
]);
