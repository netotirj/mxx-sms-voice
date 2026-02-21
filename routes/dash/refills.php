<?php

global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;

$obRouter->get('/refills',[
    'name' => '/refills', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\Refills::getRefills($request));
    }
]);


$obRouter->get('/refills/{type}/plans',[
    'name' => '/refills', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request,$type){
        return new Response(200,Pages\Refills::getComponentsPlains($request, $type));
    }
]);

$obRouter->get('/refills/{id}/checkout-pix',[
    'name' => '/refills/{id}/checkout-pix', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request,$id){
        return new Response(200,Pages\Refills::getQrCodePix($request,$id));
    }
]);

$obRouter->get('/refills/{id}/status-pix',[
    'name' => '/refills/{id}/status-pix', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request,$id){
        return new Response(200,Pages\Refills::getStatusPix($request,$id));
    }
]);

$obRouter->post('/refills/webhooks-asaas',[
    function($request){
        return new Response(200,Pages\WebStatusPix::getCallbackAsaas($request));
    }
]);

