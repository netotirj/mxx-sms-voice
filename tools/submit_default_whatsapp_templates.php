<?php

require __DIR__ . '/../bootstrap/cli.php';

use App\Model\Entity\WhatsAppTemplate;
use App\Model\Entity\WhatsAppAccount;
use App\Service\MetaWhatsAppCloudApi;
use App\Service\WhatsAppDefaultTemplateManager;
use WilliamCosta\DatabaseManager\Database;

$accountId = (int)($argv[1] ?? 14);
$localOnly = in_array('--local-only', $argv, true);
$account = WhatsAppAccount::getById($accountId);
if (!$account) {
    fwrite(STDERR, "Conta {$accountId} nao encontrada.\n");
    exit(1);
}

if ($localOnly) {
    $summary = WhatsAppDefaultTemplateManager::ensureForAccount($account);
    echo 'local-only: created=' . (int)$summary['created']
        . ' skipped=' . (int)$summary['skipped']
        . ' failed=' . (int)$summary['failed'] . "\n";
    foreach (($summary['errors'] ?? []) as $error) {
        echo 'erro local: ' . json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    exit((int)$summary['failed'] > 0 ? 1 : 0);
}

$api = new MetaWhatsAppCloudApi();
$actor = [
    'id' => (int)$account['user_id'],
    'tenancy_id' => (string)$account['tenancy_id'],
    'user_function' => 'super_admin',
];

foreach (WhatsAppDefaultTemplateManager::definitions() as $definition) {
    $name = (string)$definition['name'];
    $language = WhatsAppDefaultTemplateManager::LANGUAGE;
    $existing = WhatsAppTemplate::getByNameForTenant($name, $language, (string)$account['tenancy_id']);
    if ($existing && (string)($existing['status'] ?? '') === 'approved') {
        echo "{$name}: ja aprovado localmente\n";
        continue;
    }

    $payload = WhatsAppDefaultTemplateManager::metaPayload($definition, $name);
    $result = $api->createMessageTemplate((string)$account['access_token'], (string)$account['waba_id'], $payload);
    if (empty($result['ok'])) {
        echo "{$name}: ERRO - " . ($result['error'] ?? 'falha desconhecida') . "\n";
        continue;
    }

    $status = WhatsAppTemplate::normalizeMetaStatus((string)($result['data']['status'] ?? 'pending'));
    if ($existing) {
        WhatsAppTemplate::updateMetaStatusForUser((int)$existing['id'], $actor, $status, $result['data']);
        echo "{$name}: enviado/atualizado na Meta com status {$status}\n";
        continue;
    }

    $id = WhatsAppTemplate::create([
        'tenancy_id' => $account['tenancy_id'],
        'user_id' => (int)$account['user_id'],
        'account_id' => (int)$account['id'],
        'waba_id' => $account['waba_id'] ?? null,
        'meta_template_id' => $result['data']['id'] ?? null,
        'name' => $name,
        'language' => $language,
        'category' => $definition['category'],
        'body' => $definition['body'],
        'components' => $payload['components'] ?? null,
        'variable_map' => variableMap($definition),
        'is_system_template' => false,
        'template_type' => 'tenant',
        'status' => $status,
        'template_submitted_at' => date('Y-m-d H:i:s'),
        'template_last_sync_at' => date('Y-m-d H:i:s'),
        'meta_payload' => $result['data'],
    ]);
    echo "{$name}: criado local #{$id} e enviado na Meta com status {$status}\n";
}

function variableMap(array $definition): array
{
    $map = [];
    foreach (($definition['variables'] ?? []) as $position => $variable) {
        $map[(string)$position] = [
            'key' => (string)$variable['key'],
            'description' => (string)($variable['description'] ?? ''),
            'example' => (string)($variable['example'] ?? ''),
        ];
    }

    return $map;
}
