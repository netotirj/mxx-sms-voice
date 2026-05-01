<?php

global $obRouter;

use App\Controller\Pages;

$obRouter->get('/site-tests', [
    'name' => '/site-tests',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies',
    ],
    function ($request) {
        return Pages\SiteTests::index($request);
    }
]);
