<?php

global $obRouter;

use App\Controller\Pages;
use App\Http\Response;

$obRouter->get('/plans', [
    'name' => '/plans',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return new Response(200, Pages\Plans::getPlans($request));
    }
]);

$obRouter->get('/plans/search', [
    'name' => '/plans/search',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return Pages\Plans::search($request);
    }
]);

$obRouter->get('/plans/{id}', [
    'name' => '/plans/{id}',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request, $id) {
        return Pages\Plans::show($request, $id);
    }
]);

$obRouter->post('/plans/save', [
    'name' => '/plans/save',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return Pages\Plans::save($request);
    }
]);

$obRouter->post('/plans/{id}/status', [
    'name' => '/plans/{id}/status',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request, $id) {
        return Pages\Plans::updateStatus($request, $id);
    }
]);

$obRouter->post('/plans/{id}/delete', [
    'name' => '/plans/{id}/delete',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request, $id) {
        return Pages\Plans::delete($request, $id);
    }
]);
