<?php
global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;


$obRouter->get('/',[
    'middlewares' => [
        'require-session-logout'
    ],
    function($request){
        return new Response(200,Pages\Login::getLogin($request));
    }

]);

$obRouter->get('/login',[
    'middlewares' => [
        'require-session-logout'
    ],
    function($request){
        return new Response(200,Pages\Login::getLogin($request));
    }

]);

$obRouter->post('/login',[
    'middlewares' => [
        'require-session-logout'
    ],
    function($request){
        return new Response(200,Pages\Login::setLogin($request));
    }

]);

$obRouter->get('/dashboard/logout',[
    'middlewares' => [
    ],
    function($request){
        return new Response(200,Pages\Login::setLogout($request));
    }

]);