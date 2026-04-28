<?php

declare(strict_types=1);

/**
 * Gera um contrato prático do que este projeto espera da API Asterisk.
 *
 * Uso:
 *   php tools/export_asterisk_contract.php
 *   php tools/export_asterisk_contract.php --format=json
 *   php tools/export_asterisk_contract.php --out=asterisk_contract.md
 */

$options = getopt('', ['format::', 'out::']);
$format = strtolower((string)($options['format'] ?? 'md'));

if (!in_array($format, ['md', 'json'], true)) {
    fwrite(STDERR, "Formato inválido. Use --format=md ou --format=json\n");
    exit(1);
}

$root = getcwd() ?: __DIR__;
$defaultOut = $root . DIRECTORY_SEPARATOR . 'asterisk_contract_expected.' . ($format === 'json' ? 'json' : 'md');
$out = (string)($options['out'] ?? $defaultOut);

$report = buildContract();
$content = $format === 'json'
    ? json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : renderMarkdown($report);

if ($content === false) {
    fwrite(STDERR, "Falha ao gerar conteúdo do contrato.\n");
    exit(1);
}

file_put_contents($out, $content);
echo "Contrato gerado:\n- {$out}\n";

function buildContract(): array
{
    return [
        'generated_at' => date('c'),
        'source_project' => 'mxx/sms',
        'base_client' => 'app/Controller/Pages/AsteriskExtensionsSip.php',
        'notes' => [
            'Este arquivo descreve o contrato esperado pelo frontend/controllers deste projeto.',
            'A API pode aceitar aliases, mas para compatibilidade total deve devolver os campos em snake_case listados abaixo.',
            'Os filtros locais de permissão dependem fortemente de tenant_id, tenancy_id, user_id, owner_id, creator_id, username/extension e is_system.',
        ],
        'shared_aliases' => [
            'tenant_id' => ['tenancy_id', 'tenantId'],
            'tenancy_id' => ['tenant_id', 'tenantId'],
            'user_id' => ['owner_id', 'userId'],
            'owner_id' => ['user_id', 'ownerId'],
            'function' => ['role'],
            'role' => ['function'],
            'extension' => ['username', 'ramal'],
            'username' => ['extension', 'ramal'],
            'file_name' => ['file', 'filename'],
            'duration_seconds' => ['duration', 'seconds'],
            'recording_url' => ['audio_url', 'url'],
            'audio_url' => ['recording_url', 'url'],
        ],
        'actions' => [
            [
                'name' => 'extensions',
                'used_by' => [
                    'Voice::getListSipDevices',
                    'Voice1604::getListSipDevices',
                    'Callcenter SSE listExtensions',
                ],
                'method' => 'GET',
                'query' => [
                    'required' => [],
                    'optional' => ['tenant_id', 'tenancy_id', 'user_id', 'owner_id', 'function', 'role', 'action'],
                    'special_rules' => [
                        'super_admin: não deve depender de tenant_id/user_id',
                        'admin: lista por tenant',
                        'reseller/agente: o projeto filtra localmente por user_id e creator_id',
                    ],
                ],
                'response' => [
                    'envelope' => ['ok', 'status', 'data'],
                    'data_shape' => 'lista em data.data ou diretamente em data',
                    'required_fields' => ['extension', 'username', 'name', 'tenant_id', 'tenancy_id', 'user_id'],
                    'important_fields' => [
                        'creator_id', 'account_status', 'status', 'webrtc', 'transport', 'allow',
                        'caller_number', 'callerid', 'registered', 'online', 'sip_status',
                        'sip_detail', 'sip_status_text', 'balance_admin', 'balance_reseller',
                        'call_minute_cost', 'service_fee', 'role',
                    ],
                ],
            ],
            [
                'name' => 'getById',
                'used_by' => [
                    'Voice::getEditSipDevices',
                    'Voice1604::getEditSipDevices',
                ],
                'method' => 'GET',
                'query' => [
                    'required' => ['id'],
                    'optional' => ['tenant_id', 'tenancy_id', 'user_id', 'owner_id', 'action'],
                ],
                'response' => [
                    'envelope' => ['ok', 'status', 'data'],
                    'data_shape' => 'objeto em data.data ou diretamente em data',
                    'required_fields' => ['extension', 'username', 'name', 'tenant_id', 'user_id'],
                    'important_fields' => ['caller_number', 'account_status', 'webrtc', 'transport', 'allow', 'creator_id'],
                ],
            ],
            [
                'name' => 'create_extension',
                'used_by' => [
                    'Voice::setNewSipDevices',
                    'Voice1604::setNewSipDevices',
                ],
                'method' => 'POST',
                'query' => [
                    'required' => ['tenant_id', 'user_id'],
                    'optional' => ['tenancy_id', 'owner_id', 'action'],
                ],
                'payload' => [
                    'required' => ['extension', 'username', 'password', 'name', 'tenant_id', 'user_id'],
                    'important_fields' => [
                        'caller_number', 'callerid', 'account_status', 'webrtc', 'transport',
                        'allow', 'media_encryption', 'balance_admin', 'balance_reseller',
                        'call_minute_cost', 'service_fee', 'role', 'creator_id',
                    ],
                ],
                'response' => [
                    'envelope' => ['ok', 'status', 'data'],
                    'accepted_success_markers' => ['ok=true', 'status 2xx', 'success=true dentro de data'],
                ],
            ],
            [
                'name' => 'update_extension',
                'used_by' => [
                    'Voice::setEditSipDevices',
                    'Voice1604::setEditSipDevices',
                ],
                'method' => 'PUT',
                'query' => [
                    'required' => ['extension', 'tenant_id'],
                    'optional' => ['tenancy_id', 'user_id', 'owner_id', 'action'],
                ],
                'payload' => [
                    'required' => ['extension', 'password', 'name', 'tenant_id', 'user_id'],
                    'important_fields' => ['caller_number', 'account_status'],
                ],
                'response' => [
                    'envelope' => ['ok', 'status', 'data'],
                ],
            ],
            [
                'name' => 'delete_extension',
                'used_by' => [
                    'Voice::setDeleteSipDevices',
                    'Voice1604::setDeleteSipDevices',
                ],
                'method' => 'DELETE',
                'query' => [
                    'required' => ['tenant_id', 'user_id'],
                    'optional' => ['tenancy_id', 'owner_id', 'action'],
                ],
                'payload' => [
                    'required' => ['extension', 'tenant_id', 'user_id'],
                ],
                'response' => [
                    'envelope' => ['ok', 'status', 'data'],
                ],
            ],
            [
                'name' => 'update_status',
                'used_by' => [
                    'Voice::setStatusSipDevices',
                    'Voice1604::setStatusSipDevices',
                ],
                'method' => 'PATCH',
                'query' => [
                    'required' => ['tenant_id', 'user_id'],
                    'optional' => ['tenancy_id', 'owner_id', 'action'],
                ],
                'payload' => [
                    'required' => ['extension', 'account_status', 'tenant_id', 'user_id'],
                ],
                'response' => [
                    'envelope' => ['ok', 'status', 'data'],
                ],
            ],
            [
                'name' => 'list_audios',
                'used_by' => [
                    'Voice::getAudiosFilesAsterisk',
                    'Voice1604::getAudiosFilesAsterisk',
                    'PanelAgents::playAudioAri',
                ],
                'method' => 'GET',
                'query' => [
                    'required' => [],
                    'optional' => [
                        'tenant_id', 'tenancy_id', 'user_id', 'owner_id', 'role',
                        'function', 'category', 'path', 'action',
                    ],
                    'special_rules' => [
                        'Quando category=upload, a lista é a biblioteca de áudios do tenant/usuário',
                        'Quando path é enviado, o projeto procura um item exato para extrair duration_seconds e name',
                    ],
                ],
                'response' => [
                    'envelope' => ['ok', 'status', 'message', 'total', 'data'],
                    'data_shape' => 'lista',
                    'required_fields' => ['path'],
                    'important_fields' => [
                        'id', 'name', 'display_name', 'file_name', 'file', 'tenant_id',
                        'tenancy_id', 'user_id', 'duration_seconds', 'duration', 'size_bytes',
                        'size', 'category', 'extension',
                    ],
                ],
            ],
            [
                'name' => 'create_audio',
                'used_by' => [
                    'Voice::createAudioDbSync',
                    'Voice1604::createAudioDbSync',
                ],
                'method' => 'POST',
                'query' => [
                    'required' => [],
                    'optional' => ['tenant_id', 'tenancy_id', 'user_id', 'owner_id', 'action'],
                ],
                'payload' => [
                    'required' => ['tenant_id', 'user_id'],
                    'important_fields' => [
                        'name', 'file_name', 'file', 'path', 'duration_seconds',
                        'category', 'extension', 'size_bytes',
                    ],
                ],
                'response' => [
                    'envelope' => ['ok', 'status', 'data'],
                ],
            ],
            [
                'name' => 'delete_audio',
                'used_by' => [
                    'Voice::setAudiosDelete',
                    'Voice1604::setAudiosDelete',
                ],
                'method' => 'DELETE',
                'query' => [
                    'required' => ['tenant_id', 'user_id'],
                    'optional' => ['tenancy_id', 'owner_id', 'action'],
                ],
                'payload' => [
                    'required' => [],
                    'important_fields' => ['id', 'path', 'file_name', 'file', 'tenant_id', 'user_id'],
                ],
                'response' => [
                    'envelope' => ['ok', 'status', 'data'],
                ],
            ],
            [
                'name' => 'list_recordings',
                'used_by' => [
                    'AsteriskExtensionsSip::listRecordings',
                    'Callcenter::getRecordingsFilesAsterisk',
                ],
                'method' => 'GET',
                'query' => [
                    'required' => [],
                    'optional' => [
                        'tenant_id', 'tenancy_id', 'user_id', 'owner_id', 'function',
                        'role', 'call_id', 'category', 'action',
                    ],
                ],
                'response' => [
                    'envelope' => ['ok', 'status', 'message', 'total', 'data'],
                    'data_shape' => 'lista',
                    'required_fields' => ['call_id'],
                    'important_fields' => [
                        'path', 'name', 'display_name', 'file', 'file_name',
                        'tenant_id', 'tenancy_id', 'user_id', 'owner_id',
                        'audio_token', 'recording_url', 'audio_url',
                    ],
                ],
            ],
            [
                'name' => 'get_trunks',
                'used_by' => [
                    'Voice::getVoiceTrunksView',
                    'Voice1604::getVoiceTrunksView',
                    'painel.html seleção de tronco',
                ],
                'method' => 'GET',
                'query' => [
                    'required' => [],
                    'optional' => ['tenant_id', 'tenancy_id', 'user_id', 'owner_id', 'role', 'function', 'action'],
                    'special_rules' => [
                        'super_admin: lista total',
                        'admin/reseller: o projeto filtra localmente por tenant_id/user_id e libera is_system=1',
                    ],
                ],
                'response' => [
                    'envelope' => ['ok', 'status', 'data'],
                    'data_shape' => 'lista em data.data ou diretamente em data',
                    'required_fields' => ['id', 'trunk_id', 'name', 'status', 'direction'],
                    'important_fields' => [
                        'host', 'port', 'transport', 'auth_type', 'username',
                        'techprefix', 'dial_prefix', 'cli_type', 'is_system',
                        'tenant_id', 'tenancy_id', 'user_id', 'online', 'is_online',
                        'asterisk_up', 'sip_status', 'sip_detail', 'sip_status_text',
                        'service_fee',
                    ],
                ],
            ],
            [
                'name' => 'getTrunkById',
                'used_by' => [
                    'Voice::sendVoiceAsterisk',
                    'Voice::getEditVoiceTrunks',
                    'Voice::setStatusSipTrunks',
                    'Voice::setDeleteSipTrunks',
                    'Voice1604 equivalentes',
                ],
                'method' => 'GET',
                'query' => [
                    'required' => ['id'],
                    'optional' => ['tenant_id', 'tenancy_id', 'user_id', 'owner_id', 'action'],
                ],
                'response' => [
                    'envelope' => ['ok', 'status', 'data'],
                    'data_shape' => 'objeto',
                    'required_fields' => ['id', 'trunk_id', 'name', 'status', 'direction'],
                    'important_fields' => [
                        'techprefix', 'is_system', 'user_id', 'tenant_id',
                        'auth_type', 'sip_status', 'sip_detail', 'asterisk_up',
                    ],
                ],
            ],
            [
                'name' => 'create_trunk',
                'used_by' => [
                    'Voice::setNewVoiceTrunks',
                    'Voice1604::setNewVoiceTrunks',
                ],
                'method' => 'POST',
                'query' => [
                    'required' => ['tenant_id', 'user_id'],
                    'optional' => ['tenancy_id', 'owner_id', 'action'],
                ],
                'payload' => [
                    'required' => ['name', 'host', 'auth_type', 'tenant_id', 'user_id'],
                    'important_fields' => [
                        'username', 'password', 'port', 'transport', 'direction',
                        'cli_type', 'techprefix', 'dial_prefix', 'service_fee', 'status',
                    ],
                ],
                'response' => [
                    'envelope' => ['ok', 'status', 'data'],
                ],
            ],
            [
                'name' => 'update_trunks',
                'used_by' => [
                    'Voice::setEditVoiceTrunks',
                    'Voice1604::setEditVoiceTrunks',
                ],
                'method' => 'PUT',
                'query' => [
                    'required' => ['tenant_id', 'user_id'],
                    'optional' => ['tenancy_id', 'owner_id', 'action'],
                ],
                'payload' => [
                    'required' => ['id', 'trunk_id', 'name', 'host', 'auth_type', 'tenant_id', 'user_id'],
                    'important_fields' => [
                        'username', 'password', 'port', 'transport', 'direction',
                        'cli_type', 'techprefix', 'dial_prefix', 'service_fee', 'status',
                    ],
                ],
                'response' => [
                    'envelope' => ['ok', 'status', 'data'],
                ],
            ],
            [
                'name' => 'status_trunks',
                'used_by' => [
                    'Voice::setStatusSipTrunks',
                    'Voice1604::setStatusSipTrunks',
                ],
                'method' => 'PATCH',
                'query' => [
                    'required' => ['tenant_id', 'user_id'],
                    'optional' => ['tenancy_id', 'owner_id', 'action'],
                ],
                'payload' => [
                    'required' => ['trunk_id', 'status', 'tenant_id', 'user_id'],
                ],
                'response' => [
                    'envelope' => ['ok', 'status', 'data'],
                ],
            ],
            [
                'name' => 'delete_trunks',
                'used_by' => [
                    'Voice::setDeleteSipTrunks',
                    'Voice1604::setDeleteSipTrunks',
                ],
                'method' => 'DELETE',
                'query' => [
                    'required' => ['tenant_id', 'user_id'],
                    'optional' => ['tenancy_id', 'owner_id', 'action'],
                ],
                'payload' => [
                    'required' => ['trunk_id', 'tenant_id', 'user_id'],
                ],
                'response' => [
                    'envelope' => ['ok', 'status', 'data'],
                    'important_fields' => ['success', 'message'],
                ],
            ],
        ],
    ];
}

function renderMarkdown(array $report): string
{
    $lines = [];
    $lines[] = '# Contrato Esperado da API Asterisk';
    $lines[] = '';
    $lines[] = '- Projeto fonte: `' . $report['source_project'] . '`';
    $lines[] = '- Gerado em: `' . $report['generated_at'] . '`';
    $lines[] = '- Cliente base: `' . $report['base_client'] . '`';
    $lines[] = '';
    $lines[] = '## Observacoes';
    foreach ($report['notes'] as $note) {
        $lines[] = '- ' . $note;
    }
    $lines[] = '';
    $lines[] = '## Aliases Compartilhados';
    foreach ($report['shared_aliases'] as $canonical => $aliases) {
        $lines[] = '- `' . $canonical . '` aceita/espelha: ' . inlineList($aliases);
    }
    $lines[] = '';

    foreach ($report['actions'] as $action) {
        $lines[] = '## Action `' . $action['name'] . '`';
        $lines[] = '- Metodo HTTP: `' . $action['method'] . '`';
        $lines[] = '- Usado por: ' . inlineList($action['used_by']);
        $lines[] = '- Query obrigatoria: ' . inlineList($action['query']['required'] ?? []);
        $lines[] = '- Query opcional: ' . inlineList($action['query']['optional'] ?? []);

        foreach (($action['query']['special_rules'] ?? []) as $rule) {
            $lines[] = '- Regra: ' . $rule;
        }

        if (isset($action['payload'])) {
            $lines[] = '- Payload obrigatorio: ' . inlineList($action['payload']['required'] ?? []);
            $lines[] = '- Payload importante: ' . inlineList($action['payload']['important_fields'] ?? []);
        }

        $lines[] = '- Envelope de resposta: ' . inlineList($action['response']['envelope'] ?? []);

        if (isset($action['response']['data_shape'])) {
            $lines[] = '- Formato de `data`: `' . $action['response']['data_shape'] . '`';
        }

        $lines[] = '- Campos obrigatorios na resposta: ' . inlineList($action['response']['required_fields'] ?? []);
        $lines[] = '- Campos importantes na resposta: ' . inlineList($action['response']['important_fields'] ?? []);

        foreach (($action['response']['accepted_success_markers'] ?? []) as $marker) {
            $lines[] = '- Marcador de sucesso aceito: `' . $marker . '`';
        }

        $lines[] = '';
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function inlineList(array $items): string
{
    $items = array_values(array_filter($items, static fn ($item): bool => (string)$item !== ''));

    if ($items === []) {
        return '`nenhum`';
    }

    return implode(', ', array_map(static fn ($item): string => '`' . (string)$item . '`', $items));
}
