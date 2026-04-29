<?php
global $obRouter;

use App\Controller\Pages;
use App\Http\Response;

$obRouter->get('/politica-de-privacidade', [
    'middlewares' => [],
    function () {
        return new Response(200, Pages\Legal::getPrivacyPolicy());
    }
]);

$obRouter->get('/termos-de-servico', [
    'middlewares' => [],
    function () {
        return new Response(200, Pages\Legal::getTerms());
    }
]);

$obRouter->get('/exclusao-de-dados', [
    'middlewares' => [],
    function () {
        return new Response(200, Pages\Legal::getDataDeletion());
    }
]);
