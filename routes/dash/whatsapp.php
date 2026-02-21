<?php

global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;


$obRouter->get('/campaign/whatsapp', [
    'name' => '/campaign/whatsapp', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\WhatsApp::getComponentsWhatsApp($request));
    }
]);