<?php

global $obRouter;

use App\Controller\Pages;

$obRouter->get('/system-updates', [
    'name' => '/system-updates',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new \App\Http\Response(200, Pages\SystemUpdates::getComponentsSystemUpdates());
    }
]);

$obRouter->get('/system-updates/list', [
    'name' => '/system-updates/list',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\SystemUpdates::list($request);
    }
]);

$obRouter->get('/system-updates/header', [
    'name' => '/system-updates/header',
    'middlewares' => [
        'require-session-login'
    ],
    function ($request) {
        return Pages\SystemUpdates::headerList($request);
    }
]);

$obRouter->get('/system-updates/users', [
    'name' => '/system-updates/users',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\SystemUpdates::users($request);
    }
]);

$obRouter->post('/system-updates', [
    'name' => '/system-updates/create',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return Pages\SystemUpdates::create();
    }
]);

$obRouter->post('/system-updates/{id}', [
    'name' => '/system-updates/update',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return Pages\SystemUpdates::update($request, $id);
    }
]);
