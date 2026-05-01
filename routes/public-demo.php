<?php

use App\Controller\Pages;
use App\Http\Response;

global $obRouter;

$obRouter->options('/api/public/demo/{channel}', [
    'name' => '/api/public/demo/{channel}',
    'middlewares' => [],
    fn($request, $channel) => Pages\PublicDemo::options($request, $channel),
]);

$obRouter->post('/api/public/demo/{channel}', [
    'name' => '/api/public/demo/{channel}',
    'middlewares' => [],
    fn($request, $channel) => Pages\PublicDemo::send($request, $channel),
]);

$obRouter->get('/api/public/demo/{channel}', [
    'name' => '/api/public/demo/{channel}',
    'middlewares' => [],
    fn() => new Response(405, [
        'success' => false,
        'message' => 'Use POST para solicitar um teste.',
    ], 'application/json'),
]);

$obRouter->get('/api/site/stats', [
    'name' => '/api/site/stats',
    'middlewares' => [],
    fn($request) => Pages\PublicDemo::stats($request),
]);

$obRouter->options('/api/site/stats', [
    'name' => '/api/site/stats',
    'middlewares' => [],
    fn($request) => Pages\PublicDemo::options($request, 'stats'),
]);
