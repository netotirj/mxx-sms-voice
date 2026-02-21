<?php

global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;


$obRouter->get('/help/movies', [
    'name' => '/help/movies', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\HelpMovies::getComponentsMovies($request));
    }
]);


