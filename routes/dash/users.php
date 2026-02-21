<?php

global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;



$obRouter->get('/users', [
    'name' => '/users', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\Users::getUsers($request));
    }
]);

$obRouter->get('/users/search', [
    'name' => '/users/search', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\Users::getAllUsers($request));
    }
]);

$obRouter->get('/users/new', [
    'name' => '/users/new', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\Users::getNewUsers($request));
    }
]);

$obRouter->post('/users/new', [
    'name' => '/users/new', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\Users::getSetNewUsers($request));
    }
]);

$obRouter->get('/users/{id}/edit', [
    'name' => '/users/{id}/edit', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function($request,$id){
        return new Response(200, Pages\Users::getUsersEdit($request,$id));
    }
]);

$obRouter->post('/users/edit', [
    'name' => '/users/edit', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\Users::setUsersEdit($request));
    }
]);


$obRouter->post('/users/{id}/delete', [
    'name' => '/users/{id}/delete', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function($request,$id){
        return new Response(200, Pages\Users::setDeleteUsers($request,$id));
    }
]);

$obRouter->post('/users/up-status', [
    'name' => '/users/up-status', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\Users::setNewStatusUsers($request));
    }
]);

$obRouter->get('/users/profile', [
    'name' => '/users/profile', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\Users::getUsersProfile($request));
    }
]);

$obRouter->post('/users/profile/reset-pass', [
    'name' => '/users/profile/reset-pass', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\Users::setUsersResetPass($request));
    }
]);

$obRouter->post('/users/profile/upload-images', [
    'name' => '/users/profile/upload-images', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\Users::setUsersImagesProfile($request));
    }
]);

$obRouter->post('/users/refills', [
    'name' => '/users/refills', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\Refills::setRefillsResellers($request));
    }
]);






