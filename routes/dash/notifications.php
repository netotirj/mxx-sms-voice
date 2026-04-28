<?php

global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;

$obRouter->get('/notifications',[
    'name' => '/notifications',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\UsersNotifications::getNotifications($request));
    }
]);

$obRouter->post('/notifications',[
    'name' => '/notifications',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\UsersNotifications::markRead($request));
    }
]);

$obRouter->post('/notifications/mark-all-read',[
    'name' => '/notifications/mark-all-read',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\UsersNotifications::markAllAsRead($request));
    }
]);


$obRouter->post('/notifications/delete-all',[
    'name' => '/notifications/delete-all',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\UsersNotifications::deleteAllNotifications($request));
    }
]);
