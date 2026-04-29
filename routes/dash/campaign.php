<?php
global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;

$obRouter->get('/campaign',[
    'name' => '/campaign',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
         return new Response(200,Pages\Campaign::getCampaign($request));
    }

]);

$obRouter->get('/campaign/realtime',[
    'name' => '/campaign/realtime',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
         return Pages\Campaign::getCampaignRealtime($request);
    }

]);

$obRouter->get('/campaign/new',[
    'name' => '/campaign/new',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
         return new Response(200,Pages\Campaign::getNewCampaign($request));
    }

]);
$obRouter->post('/campaign/new',[
    'name' => '/campaign/new',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
         return Pages\Campaign::setNewCampaign($request);
    }

]);

$obRouter->post('/campaign/upload',[
    'name' => '/campaign/upload',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
         return Pages\Campaign::setUploadCampaign($request);
    }

]);

$obRouter->get('/campaign/{id}/edit',[
    'name' => '/campaign/{id}/edit',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request,$id){
         $response = Pages\Campaign::getEditCampaign($request,$id);
         return $response instanceof Response ? $response : new Response(200, $response);
    }

]);

$obRouter->post('/campaign/{id}/edit', [
    'name' => '/campaign/{id}/edit',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request, $id){
        return Pages\Campaign::setEditCampaign($request, $id);
    }
]);

$obRouter->post('/campaign/{id}/delete', [
    'name' => '/campaign/{id}/delete',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request, $id){
        return Pages\Campaign::setDeleteCampaign($request, $id);
    }
]);

$obRouter->post('/campaign/{id}/send', [
    'name' => '/campaign/{id}/send',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request, $id){
        return Pages\SendSms::sendCampaignSms($request, $id);
    }
]);

$obRouter->get('/campaign/single-shot', [
    'name' => '/campaign/single-shot',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\SendSms::getCampaignSmsSingle($request));
    }
]);

$obRouter->post('/campaign/single-shot-send', [
    'name' => '/campaign/single-shot-send',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return Pages\SendSms::sendCampaignSmsSingle($request);
    }
]);
