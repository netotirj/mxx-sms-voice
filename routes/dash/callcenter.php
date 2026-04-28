<?php

global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;


$obRouter->get('/callcenter/agents', [
    'name' => '/callcenter/agents', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Callcenter::getComponentsAgents($request));
    }
]);

$obRouter->get('/callcenter/queues', [
    'name' => '/callcenter/queues', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Callcenter::getComponentsQueues($request));
    }
]);

$obRouter->get('/callcenter/audio-snoop-player', [
    'name' => '/callcenter/audio-snoop-player', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Callcenter::getRecordingsFilesAsterisk($request));
    }
]);

$obRouter->get('/callcenter/breaks', [
    'name' => '/callcenter/breaks', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Callcenter::getComponentsBreaks($request));
    }
]);

$obRouter->get('/callcenter/monitoring', [
    'name' => '/callcenter/monitoring', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Callcenter::getComponentsMonitoring($request));
    }
]);

$obRouter->get('/callcenter/monitoring-calls', [
    'name' => '/callcenter/monitoring-calls', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Callcenter::getMonitoringAgentsData($request));
    }
]);

$obRouter->get('/callcenter/reports', [
    'name' => '/callcenter/reports', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Callcenter::getComponentsCallCenterReports($request));
    }
]);

$obRouter->get('/callcenter/agents-list', [
    'name' => '/callcenter/agents-list', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Callcenter::getMonitoringAgentsData($request));
    }
]);

$obRouter->post('/callcenter/agents/save', [
    'name' => '/callcenter/agents/save', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Voice::setNewSipDevices($request));
    }
]);

$obRouter->get('/callcenter/agents/get/{id}', [
    'name' => '/callcenter/agents/get/{id}', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request, $id) {
        return new Response(200, Pages\Voice::getEditSipDevices($request, $id));
    }
]);



$obRouter->post('/callcenter/queues/save', [
    'name' => '/callcenter/queues/save', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Callcenter::setNewQueues($request));
    }
]);

$obRouter->get('/callcenter/queues/list', [
    'name' => '/callcenter/queues/list', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Callcenter::getQueuesList($request));
    }
]);

$obRouter->post('/callcenter/queues/update-agents-quick', [
    'name' => '/callcenter/queues/update-agents-quick', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Callcenter::updateAgentsQuick($request));
    }
]);

$obRouter->get('/callcenter/queues/get/{id}', [
    'name' => '/callcenter/queues/get/{id}', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request,$id) {
        return new Response(200, Pages\Callcenter::getQueuesListById($request, $id));
    }
]);

$obRouter->post('/callcenter/queues/update-feature', [
    'name' => '/callcenter/queues/update-feature', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\Callcenter::updateFeature($request));
    }
]);


$obRouter->post('/callcenter/queues/delete/{id}', [
    'name' => '/callcenter/queues/delete/{id}', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request,$id) {
        return new Response(200, Pages\Callcenter::deleteQueue($request, $id));
    }
]);


$obRouter->get('/callcenter/agent-panel', [
    'name' => '/callcenter/agent-panel', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\PanelAgents::getComponentsPanelAgents($request));
    }
]);

$obRouter->post('/callcenter/agent-login', [
    'name' => '/callcenter/agent-login', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\PanelAgents::setLoginAgent($request));
    }
]);

$obRouter->get('/callcenter/breaks-list', [
    'name' => '/callcenter/breaks-list', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\PanelAgents::getComponentsListBreaks($request));
    }
]);

$obRouter->post('/callcenter/breaks-save', [
    'name' => '/callcenter/breaks-save', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\PanelAgents::SetNewsBreaks($request));
    }
]);

$obRouter->post('/callcenter/breaks-toggle-status', [
    'name' => '/callcenter/breaks-toggle-status', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\PanelAgents::toggleStatusBreak($request));
    }
]);

$obRouter->post('/callcenter/breaks-delete', [
    'name' => '/callcenter/breaks-delete', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\PanelAgents::DeleteBreaks($request));
    }
]);

$obRouter->get('/callcenter/active-breaks', [
    'name' => '/callcenter/active-breaks', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\PanelAgents::getActiveBreaksForPanel($request));
    }
]);

$obRouter->post('/callcenter/agent-set-status', [
    'name' => '/callcenter/agent-set-status', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\PanelAgents::setAgentStatus($request));
    }
]);


$obRouter->post('/callcenter/get-client', [
    'name' => '/callcenter/get-client', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\PanelAgents::getContactDataForPanel($request));
    }
]);

$obRouter->post('/callcenter/lookup-client', [
    'name' => '/callcenter/lookup-client', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\PanelAgents::getLookupClient ($request));
    }
]);

$obRouter->post('/callcenter/play-audio-ari', [
    'name' => '/callcenter/play-audio-ari', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function ($request) {
        return new Response(200, Pages\PanelAgents::playAudioARI($request));
    }
]);









