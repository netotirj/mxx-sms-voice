<?php

global $obRouter;

use App\Controller\Pages;
use App\Http\Response;

$obRouter->get('/system-updates/marketing', [
    'name' => '/system-updates/marketing',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Marketing::getAdminMarketing($request));
    }
]);

$obRouter->get('/system-updates/marketing/dashboard', [
    'name' => '/system-updates/marketing/dashboard',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\Marketing::dashboard();
    }
]);

$obRouter->get('/system-updates/marketing/settings', [
    'name' => '/system-updates/marketing/settings',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\Marketing::settings();
    }
]);

$obRouter->post('/system-updates/marketing/settings', [
    'name' => '/system-updates/marketing/settings/save',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\Marketing::saveSettings();
    }
]);

$obRouter->post('/system-updates/marketing/settings/test', [
    'name' => '/system-updates/marketing/settings/test',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\Marketing::testSettings();
    }
]);

$obRouter->post('/system-updates/marketing/create', [
    'name' => '/system-updates/marketing/create',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\Marketing::create($request);
    }
]);

$obRouter->get('/system-updates/marketing/{id}', [
    'name' => '/system-updates/marketing/{id}',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\Marketing::show($request, $id);
    }
]);

$obRouter->post('/system-updates/marketing/{id}/update', [
    'name' => '/system-updates/marketing/{id}/update',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\Marketing::update($request, $id);
    }
]);

$obRouter->post('/system-updates/marketing/{id}/assets/upload', [
    'name' => '/system-updates/marketing/{id}/assets/upload',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\Marketing::uploadAsset($request, $id);
    }
]);

$obRouter->post('/system-updates/marketing/{id}/status', [
    'name' => '/system-updates/marketing/{id}/status',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\Marketing::status($request, $id);
    }
]);

$obRouter->post('/system-updates/marketing/{id}/pause', [
    'name' => '/system-updates/marketing/{id}/pause',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\Marketing::pause($request, $id);
    }
]);

$obRouter->post('/system-updates/marketing/{id}/activate', [
    'name' => '/system-updates/marketing/{id}/activate',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\Marketing::activate($request, $id);
    }
]);

$obRouter->post('/system-updates/marketing/{id}/archive', [
    'name' => '/system-updates/marketing/{id}/archive',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\Marketing::archive($request, $id);
    }
]);

$obRouter->post('/system-updates/marketing/{id}/delete', [
    'name' => '/system-updates/marketing/{id}/delete',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\Marketing::delete($request, $id);
    }
]);

$obRouter->post('/system-updates/marketing/{id}/meta/sync-preview', [
    'name' => '/system-updates/marketing/{id}/meta/sync-preview',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\Marketing::syncPreview($request, $id);
    }
]);
