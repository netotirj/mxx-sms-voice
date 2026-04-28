<?php
global $obRouter;

use App\Controller\Pages;
use App\Http\Response;

$obRouter->get('/auth/social/{provider}', [
    'middlewares' => [
        'require-session-logout'
    ],
    function ($provider, $request) {
        return new Response(200, Pages\SocialAuth::redirectToProvider($request, $provider));
    }
]);

$obRouter->get('/auth/social/{provider}/callback', [
    'middlewares' => [
        'require-session-logout'
    ],
    function ($provider, $request) {
        return new Response(200, Pages\SocialAuth::handleCallback($request, $provider));
    }
]);

$obRouter->post('/auth/social/{provider}/callback', [
    'middlewares' => [
        'require-session-logout'
    ],
    function ($provider, $request) {
        return new Response(200, Pages\SocialAuth::handleCallback($request, $provider));
    }
]);
