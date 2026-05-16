<?php

global $obRouter;

use App\Controller\Pages;
use App\Http\Response;

$obRouter->get('/global-costs', [
    'name' => '/global-costs',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return new Response(200, Pages\GlobalCosts::getPage());
    }
]);

$obRouter->get('/global-costs/search', [
    'name' => '/global-costs/search',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return Pages\GlobalCosts::search($request);
    }
]);

$obRouter->get('/global-costs/history', [
    'name' => '/global-costs/history',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return Pages\GlobalCosts::history($request);
    }
]);

$obRouter->post('/global-costs/save', [
    'name' => '/global-costs/save',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return Pages\GlobalCosts::save($request);
    }
]);

$obRouter->post('/global-costs/{id}/status', [
    'name' => '/global-costs/{id}/status',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request, $id) {
        return Pages\GlobalCosts::updateStatus($request, $id);
    }
]);
