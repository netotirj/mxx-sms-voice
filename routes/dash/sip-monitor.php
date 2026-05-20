<?php

global $obRouter;

use App\Controller\Pages;
use App\Http\Response;

$obRouter->get('/admin/sip-monitor', [
    'name' => '/admin/sip-monitor',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function () {
        return new Response(200, Pages\SipMonitor::index());
    }
]);

$obRouter->get('/admin/sip-monitor/status', [
    'name' => '/admin/sip-monitor/status',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return Pages\SipMonitor::status($request);
    }
]);

$obRouter->get('/admin/sip-monitor/test-connection', [
    'name' => '/admin/sip-monitor/test-connection',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function () {
        return Pages\SipMonitor::testConnection();
    }
]);

$obRouter->post('/admin/sip-monitor/start', [
    'name' => '/admin/sip-monitor/start',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return Pages\SipMonitor::start($request);
    }
]);

$obRouter->post('/admin/sip-monitor/stop', [
    'name' => '/admin/sip-monitor/stop',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return Pages\SipMonitor::stop($request);
    }
]);

$obRouter->get('/admin/sip-monitor/stream', [
    'name' => '/admin/sip-monitor/stream',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return Pages\SipMonitor::stream($request);
    }
]);

$obRouter->post('/admin/sip-monitor/emergency-stop', [
    'name' => '/admin/sip-monitor/emergency-stop',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function () {
        return Pages\SipMonitor::emergencyStop();
    }
]);
