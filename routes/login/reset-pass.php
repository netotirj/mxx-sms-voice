<?php
global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;


$obRouter->get('/reset-pass',[
    'middlewares' => [
        'require-session-logout'
    ],
    function($request){;
        return new Response(200,Pages\PassReset::getResetPass($request));
    }

]);

$obRouter->post('/reset-pass',[
    'middlewares' => [
        'require-session-logout'
    ],
    function($request){;
        return new Response(200,Pages\PassReset::setResetPass($request));
    }

]);

$obRouter->get('/reset-pass-code',[
    'middlewares' => [
        'require-session-logout'
    ],
    function($request){
        return new Response(200,Pages\PassReset::setResetPassCode($request));
    }

]);

$obRouter->post('/validate-reset-code',[
    'middlewares' => [
        'require-session-logout'
    ],
    function($request){
        return new Response(200,Pages\PassReset::setConfirmPassCode($request));
    }

]);

$obRouter->get('/pass-confirmed',[
    'middlewares' => [
        'require-session-logout'
    ],
    function($request){
        return new Response(200,Pages\PassReset::getConfirmPassView($request));
    }

]);

$obRouter->post('/pass-confirmed-new',[
    'middlewares' => [
        'require-session-logout'
    ],
    function($request){
        return new Response(200,Pages\PassReset::getConfirmPassNew($request));
    }

]);


$obRouter->post('/reset-code',[
    'middlewares' => [
        'require-session-logout'
    ],
    function($request){
        return new Response(200,Pages\PassReset::resendResetCode($request));
    }

]);

