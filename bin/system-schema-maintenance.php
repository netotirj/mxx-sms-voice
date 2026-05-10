<?php

require __DIR__ . '/../bootstrap/app.php';

use App\Service\SystemSchemaMaintenance;

$targets = $argv;
array_shift($targets);

if ($targets === []) {
    $targets = ['permissions', 'whatsapp-support', 'application'];
}

$results = [];

foreach ($targets as $target) {
    switch ($target) {
        case 'permissions':
            $results['permissions'] = SystemSchemaMaintenance::syncPermissionsSchema();
            break;

        case 'whatsapp-support':
            $results['whatsapp-support'] = SystemSchemaMaintenance::syncWhatsAppSupportSchema();
            break;

        case 'application':
            $results['application'] = SystemSchemaMaintenance::syncApplicationSchema();
            break;

        default:
            fwrite(STDERR, "Target desconhecido: {$target}\n");
            exit(1);
    }
}

echo json_encode([
    'status' => 'ok',
    'targets' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
