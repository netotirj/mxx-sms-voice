<?php
global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;

$obRouter->get('/dashboard', [
    'name' => '/dashboard', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\Dashboard::getDashboard($request));
    }
]);

$obRouter->get('/dashboard/charts',[
    'name' => '/dashboard/charts', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\Dashboard::getDataChartsDashboard($request));
    }

]);

$obRouter->get('/dashboard/cards',[
    'name' => '/dashboard/cards', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\Dashboard::getDataViewDash($request));
    }

]);

$obRouter->post('/dashboard/swap-plan',[
    'name' => '/dashboard/swap-plan', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200,Pages\Dashboard::setUpdatePlan($request));
    }

]);