<?php

global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;



$obRouter->get('/permissions', [
    'name' => '/permissions', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\PermissionsUsersRoles::getPermissions($request));
    }
]);


$obRouter->get('/permissions/search', [
    'name' => '/permissions/search', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\PermissionsUsersRoles::getAllPermissionsUsers($request));
    }
]);


$obRouter->get('/permissions/{id}/search', [
    'name' => '/permissions/{id}/search', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request,$id){
        return new Response(200, Pages\PermissionsUsersRoles::getAllPermissionsUsersId($request,$id));
    }
]);

$obRouter->post('/permissions/{id}/update', [
    'name' => '/permissions/{id}/update', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request, $id){
        return new Response(200, Pages\PermissionsUsersRoles::setPermissionsUsersId($request, $id));
    }
]);






