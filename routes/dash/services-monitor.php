<?php

global $obRouter;

use App\Controller\Pages;
use App\Http\Response;

$obRouter->get('/admin/services-monitor', [
    'name' => '/admin/services-monitor',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return new Response(200, Pages\ServicesMonitor::getComponentsServicesMonitor());
    }
]);

$obRouter->get('/admin/services-monitor/status', [
    'name' => '/admin/services-monitor/status',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return Pages\ServicesMonitor::status($request);
    }
]);

$obRouter->get('/admin/services-monitor/logs', [
    'name' => '/admin/services-monitor/logs',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return Pages\ServicesMonitor::logs($request);
    }
]);

$obRouter->post('/admin/services-monitor/restart', [
    'name' => '/admin/services-monitor/restart',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return Pages\ServicesMonitor::restart($request);
    }
]);
