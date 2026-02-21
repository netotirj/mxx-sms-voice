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
