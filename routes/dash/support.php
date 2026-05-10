<?php

global $obRouter;

use \App\Controller\Pages;

$obRouter->get('/support', [
    'name' => '/support',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new \App\Http\Response(200, Pages\SupportTickets::getComponentsSupportTickets());
    }
]);

$obRouter->get('/support/tickets', [
    'name' => '/support/tickets',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\SupportTickets::listTickets($request);
    }
]);

$obRouter->get('/support/diagnostics', [
    'name' => '/support/diagnostics',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\SupportTickets::diagnostics();
    }
]);

$obRouter->post('/support/tickets', [
    'name' => '/support/tickets/create',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\SupportTickets::createTicket();
    }
]);

$obRouter->get('/support/tickets/{id}/messages', [
    'name' => '/support/tickets/{id}/messages',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\SupportTickets::listMessages($request, $id);
    }
]);

$obRouter->post('/support/tickets/{id}/messages', [
    'name' => '/support/tickets/{id}/messages/create',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\SupportTickets::addMessage($request, $id);
    }
]);

$obRouter->post('/support/tickets/{id}/status', [
    'name' => '/support/tickets/{id}/status',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\SupportTickets::updateStatus($request, $id);
    }
]);

$obRouter->post('/support/tickets/{id}/request-close', [
    'name' => '/support/tickets/{id}/request-close',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\SupportTickets::requestClosure($request, $id);
    }
]);
