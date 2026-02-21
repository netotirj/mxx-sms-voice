<?php

global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;


$obRouter->get('/rates', [
    'name' => '/rates', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\RatesResellers::getRates($request));
    }
]);


$obRouter->get('/rates/search', [
    'name' => '/rates/search', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\RatesResellers::getAllRatesUsers($request));
    }
]);

$obRouter->post('/rates/new', [
    'name' => '/rates/new', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\RatesResellers::setNewRatesUsers($request));
    }
]);

$obRouter->post('/rates/update', [
    'name' => '/rates/update', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\RatesResellers::setUpdateRatesUsers($request));
    }
]);


$obRouter->post('/rates/up-status', [
    'name' => '/rates/up-status', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\RatesResellers::setStatusRatesUsers($request));
    }
]);


$obRouter->post('/rates/{id}/delete', [
    'name' => '/rates/{id}/delete', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return new Response(200, Pages\RatesResellers::setDeleteRatesUsers($request, $id));
    }
]);