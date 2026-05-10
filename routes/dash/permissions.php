<?php

global $obRouter;

use \App\Http\Response;
use \App\Controller\Pages;



$obRouter->get('/permissions', [
    'name' => '/permissions', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\PermissionsUsersRoles::getPermissions($request));
    }
]);

$obRouter->get('/me/permissions', [
    'name' => '/me/permissions',
    'middlewares' => [
        'require-session-login'
    ],
    function($request){
        return new Response(200, Pages\PermissionsUsersRoles::getCurrentPermissions($request));
    }
]);

$obRouter->post('/permissions/roles/save', [
    'name' => '/permissions/roles/save', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\PermissionsUsersRoles::saveRole($request));
    }
]);

$obRouter->post('/permissions/global-routes/save', [
    'name' => '/permissions/global-routes/save', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\PermissionsUsersRoles::saveGlobalRoute($request));
    }
]);


$obRouter->get('/permissions/search', [
    'name' => '/permissions/search', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\PermissionsUsersRoles::getAllPermissionsUsers($request));
    }
]);

$obRouter->get('/permissions/templates', [
    'name' => '/permissions/templates',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\PermissionsUsersRoles::getRoleTemplates($request));
    }
]);

$obRouter->post('/permissions/roles/list-all-routes', [
    'name' => '/permissions/roles/list-all-routes', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\PermissionsUsersRoles::getRolePermissions($request));
    }
]);

$obRouter->post('/permissions/roles/list-users', [
    'name' => '/permissions/roles/list-users', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\PermissionsUsersRoles::getListUsersForRole($request));
    }
]);

$obRouter->post('/permissions/roles/assign-user', [
    'name' => '/permissions/roles/assign-user', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\PermissionsUsersRoles::setAssignUserRole($request));
    }
]);





$obRouter->post('/permissions/roles/toggle', [
    'name' => '/permissions/roles/toggle', // nome da rota
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\PermissionsUsersRoles::togglePermission($request));
    }
]);

$obRouter->post('/permissions/roles/bulk-toggle', [
    'name' => '/permissions/roles/bulk-toggle',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\PermissionsUsersRoles::bulkTogglePermissions($request));
    }
]);

$obRouter->post('/permissions/roles/clear', [
    'name' => '/permissions/roles/clear',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\PermissionsUsersRoles::clearPermissions($request));
    }
]);

$obRouter->post('/permissions/roles/sync-template', [
    'name' => '/permissions/roles/sync-template',
    'middlewares' => [
        'require-session-login',
        'require-permissions-tenancies'
    ],
    function($request){
        return new Response(200, Pages\PermissionsUsersRoles::syncRoleFromTemplate($request));
    }
]);
