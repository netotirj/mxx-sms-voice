<?php

global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;


$obRouter->get('/campaign/voice', [
    'name' => '/campaign/voice', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::getComponentsVoice($request));
    }
]);

$obRouter->get('/campaign/voice/view', [
    'name' => '/campaign/voice/view', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::getComponentsVoiceSearch($request));
    }
]);


$obRouter->get('/campaign/voice/list', [
    'name' => '/campaign/voice/list', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::getComponentsVoiceList($request));
    }
]);

$obRouter->get('/campaign/voice/trunks', [
    'name' => '/campaign/voice/trunks', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::getComponentsVoiceTrunks($request));
    }
]);

$obRouter->post('/campaign/voice/sip-trunks/new', [
    'name' => '/campaign/voice/sip-trunks/new', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::setNewVoiceTrunks($request));
    }
]);

$obRouter->get('/campaign/voice/sip-trunks/view', [
    'name' => '/campaign/voice/sip-trunks/new', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::getVoiceTrunksView($request));
    }
]);

$obRouter->post('/campaign/voice/sip-trunks/{id}/edit', [
    'name' => '/campaign/voice/sip-trunks/{id}/edit', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return new Response(200, Pages\Voice::setEditSipTrunks($request, $id));
    }
]);

$obRouter->post('/campaign/voice/sip-trunks/delete', [
    'name' => '/campaign/voice/sip-trunks/delete',
    'middlewares' => ['require-session-login'],
    function ($request) {
        return Pages\Voice::setDeleteSipTrunks($request);
    }
]);

$obRouter->patch('/campaign/voice/sip-trunks/{id}/status', [
    'name' => '/campaign/voice/sip-trunks/{id}/status', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return new Response(200, Pages\Voice::setStatusSipTrunks($request, $id));
    }
]);


$obRouter->post('/campaign/voice/{id}/{action}', [
    'name' => '/campaign/voice/sip-trunks/{id}/status', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request, $id, $action) {
        return new Response(200, Pages\Voice::setActionVoiceCampaign($id, $action));
    }
]);

$obRouter->post('/campaign/voice/list-delete', [
    'name' => '/campaign/voice/list', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::setVoiceListDelete($request));
    }
]);

$obRouter->get('/campaign/voice/search', [
    'name' => '/campaign/voice/list', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::getComponentsVoiceListSearch($request));
    }
]);

$obRouter->post('/campaign/voice/upload', [
    'name' => '/campaign/voice/upload', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::setUploadVoiceList($request));
    }
]);

$obRouter->post('/campaign/voice/send-voice', [
    'name' => '/campaign/voice', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::sendVoiceAsterisk($request));
    }
]);


$obRouter->get('/campaign/voice/audios', [
    'name' => '/campaign/voice/audio', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::getComponentsAudioList($request));
    }
]);


$obRouter->get('/campaign/voice/prices', [
    'name' => '/campaign/voice/prices', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::getPriceVoiceList($request));
    }
]);

$obRouter->get('/campaign/voice/audios-search', [
    'name' => '/campaign/voice/audios-search', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::getAudiosFilesAsterisk($request));
    }
]);


$obRouter->get('/campaign/voice/audios-search', [
    'name' => '/campaign/voice/audios-search', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::getAudiosFilesAsterisk($request));
    }
]);

$obRouter->post('/campaign/voice/audio-delete', [
    'name' => '/campaign/voice/audio-delete', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::setAudiosDelete($request));
    }
]);


$obRouter->post('/campaign/voice/audio-upload', [
    'name' => '/campaign/voice/audio-upload', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::setUploadAudiosAsterisk($request));
    }
]);


$obRouter->get('campaign/voice/calls-view', [
    'name' => 'campaign/voice/calls-view', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::getComponentsActiveCalls($request));
    }
]);

$obRouter->get('campaign/voice/live-calls', [
    'name' => 'campaign/voice/live-calls', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::getActiveCalls($request));
    }
]);

$obRouter->post('/campaign/voice/listening', [
    'name' => '/campaign/voice/listening', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return new Response(200, Pages\Voice::getVoiceListening($request, $id));
    }
]);


$obRouter->post('/campaign/voice/stop-listening', [
    'name' => '/campaign/voice/listening', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return new Response(200, Pages\Voice::setVoiceListeningHangup($request, $id));
    }
]);

$obRouter->post('/campaign/voice/hangup', [
    'name' => '/campaign/voice/listening', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::setVoiceHangup($request));
    }
]);

$obRouter->get('/campaign/voice/sip', [
    'name' => '/campaign/voice/sip-devices', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::getComponentsListExtensions($request));
    }
]);


$obRouter->get('/campaign/voice/sip-devices', [
    'name' => '/campaign/voice/sip-devices', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::getListSipDevices($request));
    }
]);


$obRouter->post('/campaign/voice/sip-devices/create', [
    'name' => '/campaign/voice/sip-devices/create', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::setNewSipDevices($request));
    }
]);

$obRouter->get('/campaign/voice/sip-devices/{id}/edit', [
    'name' => '/campaign/voice/sip-devices/{id}/edit', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return new Response(200, Pages\Voice::getEditSipDevices($request, $id));
    }
]);

$obRouter->post('/campaign/voice/sip-devices/{id}/update', [
    'name' => '/campaign/voice/sip-devices/{id}/update', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return new Response(200, Pages\Voice::setEditSipDevices($request, $id));
    }
]);

$obRouter->post('/campaign/voice/sip-devices/delete', [
    'name' => '/campaign/voice/sip-devices/delete', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return new Response(200, Pages\Voice::setDeleteSipDevices($request, $id));
    }
]);

$obRouter->patch('/campaign/voice/sip-devices/{id}/status', [
    'name' => '/campaign/voice/sip-devices/{id}/status', // nome da rota
    'middlewares' => [
        'require-session-login',
        //'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return new Response(200, Pages\Voice::setStatusSipDevices($request, $id));
    }
]);


