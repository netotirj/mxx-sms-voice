<?php

require __DIR__ . '/../bootstrap/cli.php';

use WilliamCosta\DatabaseManager\Database;

$db = new Database();

$routes = [
    'WhatsApp - Números' => [
        '/campaign/whatsapp/number-requests/{id}/send-meta',
        '/campaign/whatsapp/number-requests/{id}/approve',
        '/campaign/whatsapp/number-requests/{id}/reject',
        '/campaign/whatsapp/number-requests/{id}/resend-code',
        '/campaign/whatsapp/number-requests/{id}/confirm-code',
    ],
    'WhatsApp - Precificação' => [
        '/campaign/whatsapp/pricing/simulate',
    ],
    'WhatsApp - Templates' => [
        '/campaign/whatsapp/templates/library',
    ],
    'Planos e Recargas: Gestão Geral' => [
        '/refills/{type}/plans',
    ],
    'Sistema: Testes' => [
        '/site-tests',
    ],
    'Gestão de Usuários' => [
        '/users/{id}/edit',
    ],
];

foreach ($routes as $moduleName => $paths) {
    foreach ($paths as $path) {
        $db->execute(
            "INSERT INTO sys_routes (module_name, route_path)
             VALUES (:module_name, :route_path)
             ON DUPLICATE KEY UPDATE module_name = VALUES(module_name)",
            [
                ':module_name' => $moduleName,
                ':route_path' => $path,
            ]
        );
    }
}

$permissionCopies = [
    '/campaign/whatsapp/number-requests/send-meta' => '/campaign/whatsapp/number-requests/{id}/send-meta',
    '/campaign/whatsapp/number-requests/approve' => '/campaign/whatsapp/number-requests/{id}/approve',
    '/campaign/whatsapp/number-requests/reject' => '/campaign/whatsapp/number-requests/{id}/reject',
    '/campaign/whatsapp/number-requests/resend-code' => '/campaign/whatsapp/number-requests/{id}/resend-code',
    '/campaign/whatsapp/number-requests/confirm-code' => '/campaign/whatsapp/number-requests/{id}/confirm-code',
    '/campaign/whatsapp/templates' => '/campaign/whatsapp/templates/library',
    '/campaign/whatsapp' => '/campaign/whatsapp/pricing/simulate',
    '/refills' => '/refills/{type}/plans',
    '/users' => '/users/{id}/edit',
    '/permissions' => '/site-tests',
];

foreach ($permissionCopies as $sourcePath => $targetPath) {
    $db->execute(
        "INSERT IGNORE INTO sys_role_permissions (tenancy_id, role_id, route_id)
         SELECT rp.tenancy_id, rp.role_id, target.id
         FROM sys_role_permissions rp
         INNER JOIN sys_routes source ON source.id = rp.route_id AND source.route_path = :source_path
         INNER JOIN sys_routes target ON target.route_path = :target_path",
        [
            ':source_path' => $sourcePath,
            ':target_path' => $targetPath,
        ]
    );
}

echo "missing_permission_routes_ok\n";
