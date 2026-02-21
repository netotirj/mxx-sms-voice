<?php

global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;



$obRouter->get('/reports', [
    'name' => '/reports', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\Reports::getReportsStatus($request));
    }
]);


$obRouter->get('/reports/sms',[
    'name' => '/reports/sms', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\Reports::getReportsStatusRealtime($request));
    }
]);

$obRouter->get('/reports/sms-stream',[
    'name' => '/reports/sms-stream', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\Reports::getStatusSmsStream($request));
    }
]);

$obRouter->get('/reports/notifications',[
    'name' => '/reports/notifications', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\Reports::getNotificationsStatus($request));
    }
]);

$obRouter->get('/reports/notifications-realtime',[
    'name' => '/reports/notifications-realtime', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\Reports::getNotificationsStatusRealtime($request));
    }
]);

$obRouter->get('/reports/transactions',[
    'name' => '/reports/transactions', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\Reports::getTransactionsPix($request));
    }
]);

$obRouter->get('/reports/transactions-realtime',[
    'name' => '/reports/transactions-realtime', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\Reports::getTransactionsPixRealtime($request));
    }
]);

$obRouter->get('/reports/recharge-transactions',[
    'name' => '/reports/recharge-transactions', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\Reports::getTransactionsResellers($request));
    }
]);

$obRouter->get('/reports/recharge-realtime',[
    'name' => '/reports/recharge-realtime', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\Reports::getRechargeResellers($request));
    }
]);

$obRouter->get('/reports/sms-view',[
    'name' => '/reports/sms-view', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\Reports::getStatusSmsView($request));
    }
]);

$obRouter->get('/reports/sms-view-realtime',[
    'name' => '/reports/sms-view-realtime', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\Reports::getStatusSmsViewRealtime($request));
    }
]);

$obRouter->get('/reports/generate/{id}/invoice',[
    'name' => '/reports/generate/{id}/invoice', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request,$id){
        return new Response(200,Pages\Reports::downloadRechargePDF($request,$id));
    }
]);

$obRouter->get('/reports/cdr',[
    //'name' => '/reports/cdr', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\Reports::getCdrComponents($request));
    }
]);

$obRouter->get('/reports/list-cdr',[
    //'name' => '/reports/list-cdr', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\Reports::getCdrCallsAnalysis($request));
    }
]);


$obRouter->post('/reports/web-pro',[
    function($request){
        return new Response(200, Pages\WebStatusSms::getCallbackPro($request));
    }
]);






