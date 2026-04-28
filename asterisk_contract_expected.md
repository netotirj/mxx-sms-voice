# Contrato Esperado da API Asterisk

- Projeto fonte: `mxx/sms`
- Gerado em: `2026-04-18T01:48:05+00:00`
- Cliente base: `app/Controller/Pages/AsteriskExtensionsSip.php`

## Observacoes
- Este arquivo descreve o contrato esperado pelo frontend/controllers deste projeto.
- A API pode aceitar aliases, mas para compatibilidade total deve devolver os campos em snake_case listados abaixo.
- Os filtros locais de permissão dependem fortemente de tenant_id, tenancy_id, user_id, owner_id, creator_id, username/extension e is_system.

## Aliases Compartilhados
- `tenant_id` aceita/espelha: `tenancy_id`, `tenantId`
- `tenancy_id` aceita/espelha: `tenant_id`, `tenantId`
- `user_id` aceita/espelha: `owner_id`, `userId`
- `owner_id` aceita/espelha: `user_id`, `ownerId`
- `function` aceita/espelha: `role`
- `role` aceita/espelha: `function`
- `extension` aceita/espelha: `username`, `ramal`
- `username` aceita/espelha: `extension`, `ramal`
- `file_name` aceita/espelha: `file`, `filename`
- `duration_seconds` aceita/espelha: `duration`, `seconds`
- `recording_url` aceita/espelha: `audio_url`, `url`
- `audio_url` aceita/espelha: `recording_url`, `url`

## Action `extensions`
- Metodo HTTP: `GET`
- Usado por: `Voice::getListSipDevices`, `Voice1604::getListSipDevices`, `Callcenter SSE listExtensions`
- Query obrigatoria: `nenhum`
- Query opcional: `tenant_id`, `tenancy_id`, `user_id`, `owner_id`, `function`, `role`, `action`
- Regra: super_admin: não deve depender de tenant_id/user_id
- Regra: admin: lista por tenant
- Regra: reseller/agente: o projeto filtra localmente por user_id e creator_id
- Envelope de resposta: `ok`, `status`, `data`
- Formato de `data`: `lista em data.data ou diretamente em data`
- Campos obrigatorios na resposta: `extension`, `username`, `name`, `tenant_id`, `tenancy_id`, `user_id`
- Campos importantes na resposta: `creator_id`, `account_status`, `status`, `webrtc`, `transport`, `allow`, `caller_number`, `callerid`, `registered`, `online`, `sip_status`, `sip_detail`, `sip_status_text`, `balance_admin`, `balance_reseller`, `call_minute_cost`, `service_fee`, `role`

## Action `getById`
- Metodo HTTP: `GET`
- Usado por: `Voice::getEditSipDevices`, `Voice1604::getEditSipDevices`
- Query obrigatoria: `id`
- Query opcional: `tenant_id`, `tenancy_id`, `user_id`, `owner_id`, `action`
- Envelope de resposta: `ok`, `status`, `data`
- Formato de `data`: `objeto em data.data ou diretamente em data`
- Campos obrigatorios na resposta: `extension`, `username`, `name`, `tenant_id`, `user_id`
- Campos importantes na resposta: `caller_number`, `account_status`, `webrtc`, `transport`, `allow`, `creator_id`

## Action `create_extension`
- Metodo HTTP: `POST`
- Usado por: `Voice::setNewSipDevices`, `Voice1604::setNewSipDevices`
- Query obrigatoria: `tenant_id`, `user_id`
- Query opcional: `tenancy_id`, `owner_id`, `action`
- Payload obrigatorio: `extension`, `username`, `password`, `name`, `tenant_id`, `user_id`
- Payload importante: `caller_number`, `callerid`, `account_status`, `webrtc`, `transport`, `allow`, `media_encryption`, `balance_admin`, `balance_reseller`, `call_minute_cost`, `service_fee`, `role`, `creator_id`
- Envelope de resposta: `ok`, `status`, `data`
- Campos obrigatorios na resposta: `nenhum`
- Campos importantes na resposta: `nenhum`
- Marcador de sucesso aceito: `ok=true`
- Marcador de sucesso aceito: `status 2xx`
- Marcador de sucesso aceito: `success=true dentro de data`

## Action `update_extension`
- Metodo HTTP: `PUT`
- Usado por: `Voice::setEditSipDevices`, `Voice1604::setEditSipDevices`
- Query obrigatoria: `extension`, `tenant_id`
- Query opcional: `tenancy_id`, `user_id`, `owner_id`, `action`
- Payload obrigatorio: `extension`, `password`, `name`, `tenant_id`, `user_id`
- Payload importante: `caller_number`, `account_status`
- Envelope de resposta: `ok`, `status`, `data`
- Campos obrigatorios na resposta: `nenhum`
- Campos importantes na resposta: `nenhum`

## Action `delete_extension`
- Metodo HTTP: `DELETE`
- Usado por: `Voice::setDeleteSipDevices`, `Voice1604::setDeleteSipDevices`
- Query obrigatoria: `tenant_id`, `user_id`
- Query opcional: `tenancy_id`, `owner_id`, `action`
- Payload obrigatorio: `extension`, `tenant_id`, `user_id`
- Payload importante: `nenhum`
- Envelope de resposta: `ok`, `status`, `data`
- Campos obrigatorios na resposta: `nenhum`
- Campos importantes na resposta: `nenhum`

## Action `update_status`
- Metodo HTTP: `PATCH`
- Usado por: `Voice::setStatusSipDevices`, `Voice1604::setStatusSipDevices`
- Query obrigatoria: `tenant_id`, `user_id`
- Query opcional: `tenancy_id`, `owner_id`, `action`
- Payload obrigatorio: `extension`, `account_status`, `tenant_id`, `user_id`
- Payload importante: `nenhum`
- Envelope de resposta: `ok`, `status`, `data`
- Campos obrigatorios na resposta: `nenhum`
- Campos importantes na resposta: `nenhum`

## Action `list_audios`
- Metodo HTTP: `GET`
- Usado por: `Voice::getAudiosFilesAsterisk`, `Voice1604::getAudiosFilesAsterisk`, `PanelAgents::playAudioAri`
- Query obrigatoria: `nenhum`
- Query opcional: `tenant_id`, `tenancy_id`, `user_id`, `owner_id`, `role`, `function`, `category`, `path`, `action`
- Regra: Quando category=upload, a lista é a biblioteca de áudios do tenant/usuário
- Regra: Quando path é enviado, o projeto procura um item exato para extrair duration_seconds e name
- Envelope de resposta: `ok`, `status`, `message`, `total`, `data`
- Formato de `data`: `lista`
- Campos obrigatorios na resposta: `path`
- Campos importantes na resposta: `id`, `name`, `display_name`, `file_name`, `file`, `tenant_id`, `tenancy_id`, `user_id`, `duration_seconds`, `duration`, `size_bytes`, `size`, `category`, `extension`

## Action `create_audio`
- Metodo HTTP: `POST`
- Usado por: `Voice::createAudioDbSync`, `Voice1604::createAudioDbSync`
- Query obrigatoria: `nenhum`
- Query opcional: `tenant_id`, `tenancy_id`, `user_id`, `owner_id`, `action`
- Payload obrigatorio: `tenant_id`, `user_id`
- Payload importante: `name`, `file_name`, `file`, `path`, `duration_seconds`, `category`, `extension`, `size_bytes`
- Envelope de resposta: `ok`, `status`, `data`
- Campos obrigatorios na resposta: `nenhum`
- Campos importantes na resposta: `nenhum`

## Action `delete_audio`
- Metodo HTTP: `DELETE`
- Usado por: `Voice::setAudiosDelete`, `Voice1604::setAudiosDelete`
- Query obrigatoria: `tenant_id`, `user_id`
- Query opcional: `tenancy_id`, `owner_id`, `action`
- Payload obrigatorio: `nenhum`
- Payload importante: `id`, `path`, `file_name`, `file`, `tenant_id`, `user_id`
- Envelope de resposta: `ok`, `status`, `data`
- Campos obrigatorios na resposta: `nenhum`
- Campos importantes na resposta: `nenhum`

## Action `list_recordings`
- Metodo HTTP: `GET`
- Usado por: `AsteriskExtensionsSip::listRecordings`, `Callcenter::getRecordingsFilesAsterisk`
- Query obrigatoria: `nenhum`
- Query opcional: `tenant_id`, `tenancy_id`, `user_id`, `owner_id`, `function`, `role`, `call_id`, `category`, `action`
- Envelope de resposta: `ok`, `status`, `message`, `total`, `data`
- Formato de `data`: `lista`
- Campos obrigatorios na resposta: `call_id`
- Campos importantes na resposta: `path`, `name`, `display_name`, `file`, `file_name`, `tenant_id`, `tenancy_id`, `user_id`, `owner_id`, `audio_token`, `recording_url`, `audio_url`

## Action `get_trunks`
- Metodo HTTP: `GET`
- Usado por: `Voice::getVoiceTrunksView`, `Voice1604::getVoiceTrunksView`, `painel.html seleção de tronco`
- Query obrigatoria: `nenhum`
- Query opcional: `tenant_id`, `tenancy_id`, `user_id`, `owner_id`, `role`, `function`, `action`
- Regra: super_admin: lista total
- Regra: admin/reseller: o projeto filtra localmente por tenant_id/user_id e libera is_system=1
- Envelope de resposta: `ok`, `status`, `data`
- Formato de `data`: `lista em data.data ou diretamente em data`
- Campos obrigatorios na resposta: `id`, `trunk_id`, `name`, `status`, `direction`
- Campos importantes na resposta: `host`, `port`, `transport`, `auth_type`, `username`, `techprefix`, `dial_prefix`, `cli_type`, `is_system`, `tenant_id`, `tenancy_id`, `user_id`, `online`, `is_online`, `asterisk_up`, `sip_status`, `sip_detail`, `sip_status_text`, `service_fee`

## Action `getTrunkById`
- Metodo HTTP: `GET`
- Usado por: `Voice::sendVoiceAsterisk`, `Voice::getEditVoiceTrunks`, `Voice::setStatusSipTrunks`, `Voice::setDeleteSipTrunks`, `Voice1604 equivalentes`
- Query obrigatoria: `id`
- Query opcional: `tenant_id`, `tenancy_id`, `user_id`, `owner_id`, `action`
- Envelope de resposta: `ok`, `status`, `data`
- Formato de `data`: `objeto`
- Campos obrigatorios na resposta: `id`, `trunk_id`, `name`, `status`, `direction`
- Campos importantes na resposta: `techprefix`, `is_system`, `user_id`, `tenant_id`, `auth_type`, `sip_status`, `sip_detail`, `asterisk_up`

## Action `create_trunk`
- Metodo HTTP: `POST`
- Usado por: `Voice::setNewVoiceTrunks`, `Voice1604::setNewVoiceTrunks`
- Query obrigatoria: `tenant_id`, `user_id`
- Query opcional: `tenancy_id`, `owner_id`, `action`
- Payload obrigatorio: `name`, `host`, `auth_type`, `tenant_id`, `user_id`
- Payload importante: `username`, `password`, `port`, `transport`, `direction`, `cli_type`, `techprefix`, `dial_prefix`, `service_fee`, `status`
- Envelope de resposta: `ok`, `status`, `data`
- Campos obrigatorios na resposta: `nenhum`
- Campos importantes na resposta: `nenhum`

## Action `update_trunks`
- Metodo HTTP: `PUT`
- Usado por: `Voice::setEditVoiceTrunks`, `Voice1604::setEditVoiceTrunks`
- Query obrigatoria: `tenant_id`, `user_id`
- Query opcional: `tenancy_id`, `owner_id`, `action`
- Payload obrigatorio: `id`, `trunk_id`, `name`, `host`, `auth_type`, `tenant_id`, `user_id`
- Payload importante: `username`, `password`, `port`, `transport`, `direction`, `cli_type`, `techprefix`, `dial_prefix`, `service_fee`, `status`
- Envelope de resposta: `ok`, `status`, `data`
- Campos obrigatorios na resposta: `nenhum`
- Campos importantes na resposta: `nenhum`

## Action `status_trunks`
- Metodo HTTP: `PATCH`
- Usado por: `Voice::setStatusSipTrunks`, `Voice1604::setStatusSipTrunks`
- Query obrigatoria: `tenant_id`, `user_id`
- Query opcional: `tenancy_id`, `owner_id`, `action`
- Payload obrigatorio: `trunk_id`, `status`, `tenant_id`, `user_id`
- Payload importante: `nenhum`
- Envelope de resposta: `ok`, `status`, `data`
- Campos obrigatorios na resposta: `nenhum`
- Campos importantes na resposta: `nenhum`

## Action `delete_trunks`
- Metodo HTTP: `DELETE`
- Usado por: `Voice::setDeleteSipTrunks`, `Voice1604::setDeleteSipTrunks`
- Query obrigatoria: `tenant_id`, `user_id`
- Query opcional: `tenancy_id`, `owner_id`, `action`
- Payload obrigatorio: `trunk_id`, `tenant_id`, `user_id`
- Payload importante: `nenhum`
- Envelope de resposta: `ok`, `status`, `data`
- Campos obrigatorios na resposta: `nenhum`
- Campos importantes na resposta: `success`, `message`

