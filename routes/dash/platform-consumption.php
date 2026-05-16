<?php

global $obRouter;

use App\Controller\Pages;
use App\Http\Response;

$obRouter->get('/admin/platform-consumption', [
    'name' => '/admin/platform-consumption',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return new Response(200, Pages\PlatformConsumptionDashboard::getPage());
    }
]);

$obRouter->get('/admin/platform-consumption/data', [
    'name' => '/admin/platform-consumption/data',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return Pages\PlatformConsumptionDashboard::data($request);
    }
]);
