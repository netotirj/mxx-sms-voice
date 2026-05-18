# Auditoria de Acesso por Rota x Plano/Módulo

Gerado automaticamente a partir de `routes/`, `app/Service/ModuleAccessMap.php` e `app/Controller/Pages/ViewComponents.php`.

## Resumo

- Total de rotas catalogadas no código: 302
- Rotas com módulo resolvido no mapa oficial: 263
- Rotas sem módulo resolvido no mapa oficial: 39
- Mensagem padrão para ACL de rota: `Você não possui permissão para acessar esta rota.`
- Mensagem padrão para bloqueio comercial: `O módulo {Módulo} não faz parte do seu plano contratado.`

## Causa Raiz

- O bloqueio comercial estava disperso entre `ModuleAccessMap`, `PlanRuntimeService`, `PermissionMiddleware` e o menu hardcoded em `ViewComponents`.
- O menu lateral vinha escondendo itens por plano em vez de deixá-los visíveis e delegar o bloqueio comercial ao backend.
- A rota `/callcenter/reports` é fisicamente filha de `/callcenter`, mas comercialmente pertence ao módulo `Relatórios`; sem uma regra exata no mapa oficial, ela tende a cair no módulo errado.
- As mensagens de bloqueio não carregavam contexto consistente de módulo, o que abria espaço para erro de identificação.

## Addendum do Incidente 2026-05-18

- Comparação principal usada na regressão: `git diff 34585a7..c3d2130` nos arquivos de ACL, menu e plano.
- O menu e o header estavam carregando permissões apenas por `role_id`, via `PermissionsRules::getRolePermissionsNames($roleId)`, sem filtrar `tenancy_id`. Isso podia fazer o admin enxergar permissões agregadas de outros tenants com o mesmo papel.
- O layout publicava `permissionsVersionKey` fixo em `1:1`, o que impedia sincronização correta do estado de permissões no front quando papéis eram alterados.
- O runtime de plano liberava `reports` por fallback textual em `reports_label`, mesmo quando `modules_json.reports = false`. Na prática, bastava o campo legado de descrição existir para o módulo de relatórios ficar aberto.
- A mensagem comercial foi padronizada para `Este módulo não faz parte do seu plano contratado.` para evitar citar o módulo errado enquanto o bloqueio usa `module_key` e `module_label` apenas como metadado técnico.

## Matriz Oficial

| Método | Rota | Controller::action | Módulo correto | Permissão | Exige plano? | Menu | Comportamento esperado |
| --- | --- | --- | --- | --- | --- | --- | --- |
| GET | `/` | `Login::getLogin` | Sem módulo mapeado | `/` | não | não | fora do menu |
| GET | `/` | `closure` | Sem módulo mapeado | `/` | não | não | fora do menu |
| GET | `/admin/platform-consumption` | `PlatformConsumptionDashboard::getPage` | Administrativo | `/admin/platform-consumption` | sim | não | fora do menu |
| GET | `/admin/platform-consumption/data` | `PlatformConsumptionDashboard::data` | Administrativo | `/admin/platform-consumption/data` | sim | não | fora do menu |
| GET | `/admin/services-monitor` | `ServicesMonitor::getComponentsServicesMonitor` | Administrativo | `/admin/services-monitor` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/admin/services-monitor/logs` | `ServicesMonitor::logs` | Administrativo | `/admin/services-monitor/logs` | sim | não | fora do menu |
| POST | `/admin/services-monitor/restart` | `ServicesMonitor::restart` | Administrativo | `/admin/services-monitor/restart` | sim | não | fora do menu |
| GET | `/admin/services-monitor/status` | `ServicesMonitor::status` | Administrativo | `/admin/services-monitor/status` | sim | não | fora do menu |
| OPTIONS | `/api/public/demo/check` | `PublicDemo::options` | Sem módulo mapeado | `/api/public/demo/check` | não | não | fora do menu |
| POST | `/api/public/demo/check` | `PublicDemo::check` | Sem módulo mapeado | `/api/public/demo/check` | não | não | fora do menu |
| GET | `/api/public/demo/{channel}` | `closure` | Sem módulo mapeado | `/api/public/demo/{channel}` | não | não | fora do menu |
| OPTIONS | `/api/public/demo/{channel}` | `PublicDemo::options` | Sem módulo mapeado | `/api/public/demo/{channel}` | não | não | fora do menu |
| POST | `/api/public/demo/{channel}` | `PublicDemo::send` | Sem módulo mapeado | `/api/public/demo/{channel}` | não | não | fora do menu |
| GET | `/api/site/stats` | `PublicDemo::stats` | Sem módulo mapeado | `/api/site/stats` | não | não | fora do menu |
| OPTIONS | `/api/site/stats` | `PublicDemo::options` | Sem módulo mapeado | `/api/site/stats` | não | não | fora do menu |
| GET | `/auth/social/{provider}` | `SocialAuth::redirectToProvider` | Sem módulo mapeado | `/auth/social/{provider}` | não | não | fora do menu |
| GET | `/auth/social/{provider}/callback` | `SocialAuth::handleCallback` | Sem módulo mapeado | `/auth/social/{provider}/callback` | não | não | fora do menu |
| POST | `/auth/social/{provider}/callback` | `SocialAuth::handleCallback` | Sem módulo mapeado | `/auth/social/{provider}/callback` | não | não | fora do menu |
| GET | `/callback` | `closure` | Sem módulo mapeado | `/callback` | não | não | fora do menu |
| POST | `/callback` | `WebStatusSms::getCallbackPro` | Sem módulo mapeado | `/callback` | não | não | fora do menu |
| GET | `/callcenter/active-breaks` | `PanelAgents::getActiveBreaksForPanel` | Call Center | `/callcenter/active-breaks` | sim | não | fora do menu |
| POST | `/callcenter/agent-login` | `PanelAgents::setLoginAgent` | Call Center | `/callcenter/agent-login` | sim | não | fora do menu |
| GET | `/callcenter/agent-panel` | `PanelAgents::getComponentsPanelAgents` | Call Center | `/callcenter/agent-panel` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/callcenter/agent-set-status` | `PanelAgents::setAgentStatus` | Call Center | `/callcenter/agent-set-status` | sim | não | fora do menu |
| GET | `/callcenter/agents` | `Callcenter::getComponentsAgents` | Call Center | `/callcenter/agents` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/callcenter/agents-list` | `Callcenter::getMonitoringAgentsData` | Call Center | `/callcenter/agents-list` | sim | não | fora do menu |
| GET | `/callcenter/agents/get/{id}` | `Voice::getEditSipDevices` | Call Center | `/callcenter/agents/get/{id}` | sim | não | fora do menu |
| POST | `/callcenter/agents/save` | `Voice::setNewSipDevices` | Call Center | `/callcenter/agents/save` | sim | não | fora do menu |
| GET | `/callcenter/audio-snoop-player` | `Callcenter::getRecordingsFilesAsterisk` | Call Center | `/callcenter/audio-snoop-player` | sim | não | fora do menu |
| GET | `/callcenter/breaks` | `Callcenter::getComponentsBreaks` | Call Center | `/callcenter/breaks` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/callcenter/breaks-delete` | `PanelAgents::DeleteBreaks` | Call Center | `/callcenter/breaks-delete` | sim | não | fora do menu |
| GET | `/callcenter/breaks-list` | `PanelAgents::getComponentsListBreaks` | Call Center | `/callcenter/breaks-list` | sim | não | fora do menu |
| POST | `/callcenter/breaks-save` | `PanelAgents::SetNewsBreaks` | Call Center | `/callcenter/breaks-save` | sim | não | fora do menu |
| POST | `/callcenter/breaks-toggle-status` | `PanelAgents::toggleStatusBreak` | Call Center | `/callcenter/breaks-toggle-status` | sim | não | fora do menu |
| POST | `/callcenter/get-client` | `PanelAgents::getContactDataForPanel` | Call Center | `/callcenter/get-client` | sim | não | fora do menu |
| POST | `/callcenter/lookup-client` | `PanelAgents::getLookupClient` | Call Center | `/callcenter/lookup-client` | sim | não | fora do menu |
| GET | `/callcenter/monitoring` | `Callcenter::getComponentsMonitoring` | Call Center | `/callcenter/monitoring` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/callcenter/monitoring-calls` | `Callcenter::getMonitoringAgentsData` | Call Center | `/callcenter/monitoring-calls` | sim | não | fora do menu |
| POST | `/callcenter/play-audio-ari` | `PanelAgents::playAudioARI` | Call Center | `/callcenter/play-audio-ari` | sim | não | fora do menu |
| GET | `/callcenter/queues` | `Callcenter::getComponentsQueues` | Call Center | `/callcenter/queues` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/callcenter/queues/delete/{id}` | `Callcenter::deleteQueue` | Call Center | `/callcenter/queues/delete/{id}` | sim | não | fora do menu |
| GET | `/callcenter/queues/get/{id}` | `Callcenter::getQueuesListById` | Call Center | `/callcenter/queues/get/{id}` | sim | não | fora do menu |
| GET | `/callcenter/queues/list` | `Callcenter::getQueuesList` | Call Center | `/callcenter/queues/list` | sim | não | fora do menu |
| POST | `/callcenter/queues/save` | `Callcenter::setNewQueues` | Call Center | `/callcenter/queues/save` | sim | não | fora do menu |
| POST | `/callcenter/queues/update-agents-quick` | `Callcenter::updateAgentsQuick` | Call Center | `/callcenter/queues/update-agents-quick` | sim | não | fora do menu |
| POST | `/callcenter/queues/update-feature` | `Callcenter::updateFeature` | Call Center | `/callcenter/queues/update-feature` | sim | não | fora do menu |
| GET | `/callcenter/reports` | `Callcenter::getComponentsCallCenterReports` | Relatorios | `/callcenter/reports` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/callcenter/send-protocol-whatsapp` | `WhatsApp::sendSupportProtocolTemplate` | Call Center | `/callcenter/send-protocol-whatsapp` | sim | não | fora do menu |
| GET | `/campaign` | `Campaign::getCampaign` | SMS | `/campaign` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/campaign/new` | `Campaign::getNewCampaign` | SMS | `/campaign/new` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/new` | `Campaign::setNewCampaign` | SMS | `/campaign/new` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/campaign/realtime` | `Campaign::getCampaignRealtime` | SMS | `/campaign/realtime` | sim | não | fora do menu |
| GET | `/campaign/single-shot` | `SendSms::getCampaignSmsSingle` | SMS | `/campaign/single-shot` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/single-shot-send` | `SendSms::sendCampaignSmsSingle` | SMS | `/campaign/single-shot-send` | sim | não | fora do menu |
| POST | `/campaign/upload` | `Campaign::setUploadCampaign` | SMS | `/campaign/upload` | sim | não | fora do menu |
| GET | `/campaign/voice` | `Voice::getComponentsVoice` | Voz | `/campaign/voice` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/voice/answer` | `Voice::setVoiceAnswer` | Voz | `/campaign/voice/answer` | sim | não | fora do menu |
| POST | `/campaign/voice/audio-delete` | `Voice::setAudiosDelete` | Voz | `/campaign/voice/audio-delete` | sim | não | fora do menu |
| POST | `/campaign/voice/audio-upload` | `Voice::setUploadAudiosAsterisk` | Voz | `/campaign/voice/audio-upload` | sim | não | fora do menu |
| GET | `/campaign/voice/audios` | `Voice::getComponentsAudioList` | Voz | `/campaign/voice/audios` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/campaign/voice/audios-search` | `Voice::getAudiosFilesAsterisk` | Voz | `/campaign/voice/audios-search` | sim | não | fora do menu |
| GET | `/campaign/voice/audios-search` | `Voice::getAudiosFilesAsterisk` | Voz | `/campaign/voice/audios-search` | sim | não | fora do menu |
| GET | `/campaign/voice/calls-view` | `Voice::getComponentsActiveCalls` | Voz | `/campaign/voice/calls-view` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/voice/hangup` | `Voice::setVoiceHangup` | Voz | `/campaign/voice/hangup` | sim | não | fora do menu |
| GET | `/campaign/voice/list` | `Voice::getComponentsVoiceList` | Voz | `/campaign/voice/list` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/voice/list-delete` | `Voice::setVoiceListDelete` | Voz | `/campaign/voice/list-delete` | sim | não | fora do menu |
| POST | `/campaign/voice/listening` | `Voice::getVoiceListening` | Voz | `/campaign/voice/listening` | sim | não | fora do menu |
| GET | `/campaign/voice/live-calls` | `Voice::getActiveCalls` | Voz | `/campaign/voice/live-calls` | sim | não | fora do menu |
| POST | `/campaign/voice/make-call` | `Voice::makeCallManual` | Voz | `/campaign/voice/make-call` | sim | não | fora do menu |
| GET | `/campaign/voice/prices` | `Voice::getPriceVoiceList` | Voz | `/campaign/voice/prices` | sim | não | fora do menu |
| GET | `/campaign/voice/search` | `Voice::getComponentsVoiceListSearch` | Voz | `/campaign/voice/search` | sim | não | fora do menu |
| POST | `/campaign/voice/send-voice` | `Voice::sendVoiceAsterisk` | Voz | `/campaign/voice/send-voice` | sim | não | fora do menu |
| GET | `/campaign/voice/sip` | `Voice::getComponentsListExtensions` | Voz | `/campaign/voice/sip` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/campaign/voice/sip-devices` | `Voice::getListSipDevices` | Voz | `/campaign/voice/sip-devices` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/voice/sip-devices/create` | `Voice::setNewSipDevices` | Voz | `/campaign/voice/sip-devices/create` | sim | não | fora do menu |
| POST | `/campaign/voice/sip-devices/delete` | `Voice::setDeleteSipDevices` | Voz | `/campaign/voice/sip-devices/delete` | sim | não | fora do menu |
| GET | `/campaign/voice/sip-devices/{id}/edit` | `Voice::getEditSipDevices` | Voz | `/campaign/voice/sip-devices/{id}/edit` | sim | não | fora do menu |
| PATCH | `/campaign/voice/sip-devices/{id}/status` | `Voice::setStatusSipDevices` | Voz | `/campaign/voice/sip-devices/{id}/status` | sim | não | fora do menu |
| POST | `/campaign/voice/sip-devices/{id}/update` | `Voice::setEditSipDevices` | Voz | `/campaign/voice/sip-devices/{id}/update` | sim | não | fora do menu |
| POST | `/campaign/voice/sip-trunks/delete` | `Voice::setDeleteSipTrunks` | Voz | `/campaign/voice/sip-trunks/delete` | sim | não | fora do menu |
| POST | `/campaign/voice/sip-trunks/new` | `Voice::setNewVoiceTrunks` | Voz | `/campaign/voice/sip-trunks/new` | sim | não | fora do menu |
| GET | `/campaign/voice/sip-trunks/view` | `Voice::getVoiceTrunksView` | Voz | `/campaign/voice/sip-trunks/view` | sim | não | fora do menu |
| POST | `/campaign/voice/sip-trunks/{id}/edit` | `Voice::setEditSipTrunks` | Voz | `/campaign/voice/sip-trunks/{id}/edit` | sim | não | fora do menu |
| PATCH | `/campaign/voice/sip-trunks/{id}/status` | `Voice::setStatusSipTrunks` | Voz | `/campaign/voice/sip-trunks/{id}/status` | sim | não | fora do menu |
| POST | `/campaign/voice/stop-listening` | `Voice::setVoiceListeningHangup` | Voz | `/campaign/voice/stop-listening` | sim | não | fora do menu |
| GET | `/campaign/voice/trunks` | `Voice::getComponentsVoiceTrunks` | Voz | `/campaign/voice/trunks` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/voice/upload` | `Voice::setUploadVoiceList` | Voz | `/campaign/voice/upload` | sim | não | fora do menu |
| GET | `/campaign/voice/view` | `Voice::getComponentsVoiceSearch` | Voz | `/campaign/voice/view` | sim | não | fora do menu |
| POST | `/campaign/voice/{id}/{action}` | `Voice::setActionVoiceCampaign` | Voz | `/campaign/voice/{id}/{action}` | sim | não | fora do menu |
| GET | `/campaign/whatsapp` | `WhatsApp::getComponentsWhatsApp` | WhatsApp | `/campaign/whatsapp` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/campaign/whatsapp/accounts` | `WhatsApp::listAccounts` | WhatsApp | `/campaign/whatsapp/accounts` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/accounts/create` | `WhatsApp::createAccount` | WhatsApp | `/campaign/whatsapp/accounts/create` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/whatsapp/accounts/embedded-signup/complete` | `WhatsApp::completeEmbeddedSignupAccount` | WhatsApp | `/campaign/whatsapp/accounts/embedded-signup/complete` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/accounts/sync-meta` | `WhatsApp::syncAccountsMeta` | WhatsApp | `/campaign/whatsapp/accounts/sync-meta` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/accounts/{id}/profile` | `WhatsApp::getBusinessProfile` | WhatsApp | `/campaign/whatsapp/accounts/{id}/profile` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/accounts/{id}/profile/photo` | `WhatsApp::updateBusinessProfilePicture` | WhatsApp | `/campaign/whatsapp/accounts/{id}/profile/photo` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/accounts/{id}/profile/update` | `WhatsApp::updateBusinessProfile` | WhatsApp | `/campaign/whatsapp/accounts/{id}/profile/update` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/accounts/{id}/settings` | `WhatsApp::updateAccountSettings` | WhatsApp | `/campaign/whatsapp/accounts/{id}/settings` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/whatsapp/accounts/{id}/test` | `WhatsApp::testAccount` | WhatsApp | `/campaign/whatsapp/accounts/{id}/test` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/accounts/{id}/voice-status` | `WhatsApp::getAccountVoiceStatus` | WhatsApp | `/campaign/whatsapp/accounts/{id}/voice-status` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/calls/connect` | `WhatsApp::initiateCall` | WhatsApp | `/campaign/whatsapp/calls/connect` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/calls/permissions` | `WhatsApp::getCallPermissions` | WhatsApp | `/campaign/whatsapp/calls/permissions` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/calls/permissions/request` | `WhatsApp::requestCallPermission` | WhatsApp | `/campaign/whatsapp/calls/permissions/request` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/calls/sessions` | `WhatsApp::listCallSessions` | WhatsApp | `/campaign/whatsapp/calls/sessions` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/calls/sessions/{id}` | `WhatsApp::getCallSession` | WhatsApp | `/campaign/whatsapp/calls/sessions/{id}` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/calls/{id}/accept` | `WhatsApp::acceptCall` | WhatsApp | `/campaign/whatsapp/calls/{id}/accept` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/calls/{id}/pre-accept` | `WhatsApp::preAcceptCall` | WhatsApp | `/campaign/whatsapp/calls/{id}/pre-accept` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/calls/{id}/reject` | `WhatsApp::rejectCall` | WhatsApp | `/campaign/whatsapp/calls/{id}/reject` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/calls/{id}/terminate` | `WhatsApp::terminateCall` | WhatsApp | `/campaign/whatsapp/calls/{id}/terminate` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/campaigns` | `WhatsApp::listCampaigns` | WhatsApp | `/campaign/whatsapp/campaigns` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/whatsapp/campaigns/create` | `WhatsApp::createCampaign` | WhatsApp | `/campaign/whatsapp/campaigns/create` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/campaigns/recipients/preview` | `WhatsApp::previewCampaignRecipientsUpload` | WhatsApp | `/campaign/whatsapp/campaigns/recipients/preview` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/campaigns/{id}/cancel-category` | `WhatsApp::cancelCampaignCategory` | WhatsApp | `/campaign/whatsapp/campaigns/{id}/cancel-category` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/campaigns/{id}/send` | `WhatsApp::sendCampaign` | WhatsApp | `/campaign/whatsapp/campaigns/{id}/send` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/conversations` | `WhatsApp::listConversations` | WhatsApp | `/campaign/whatsapp/conversations` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/whatsapp/conversations/{id}/claim` | `WhatsApp::claimConversationQueue` | WhatsApp | `/campaign/whatsapp/conversations/{id}/claim` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/conversations/{id}/delete` | `WhatsApp::deleteConversation` | WhatsApp | `/campaign/whatsapp/conversations/{id}/delete` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/conversations/{id}/messages` | `WhatsApp::listMessages` | WhatsApp | `/campaign/whatsapp/conversations/{id}/messages` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/conversations/{id}/queue` | `WhatsApp::assignConversationQueue` | WhatsApp | `/campaign/whatsapp/conversations/{id}/queue` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/conversations/{id}/queue-history` | `WhatsApp::conversationQueueHistory` | WhatsApp | `/campaign/whatsapp/conversations/{id}/queue-history` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/conversations/{id}/read` | `WhatsApp::markConversationRead` | WhatsApp | `/campaign/whatsapp/conversations/{id}/read` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/conversations/{id}/unread` | `WhatsApp::markConversationUnread` | WhatsApp | `/campaign/whatsapp/conversations/{id}/unread` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/messages/send` | `WhatsApp::sendDirectMessage` | WhatsApp | `/campaign/whatsapp/messages/send` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/number-requests` | `WhatsApp::listNumberRequests` | WhatsApp | `/campaign/whatsapp/number-requests` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/whatsapp/number-requests/approve` | `WhatsApp::approveNumberRequest` | WhatsApp | `/campaign/whatsapp/number-requests/approve` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/number-requests/confirm-code` | `WhatsApp::confirmNumberRequestCode` | WhatsApp | `/campaign/whatsapp/number-requests/confirm-code` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/number-requests/reject` | `WhatsApp::rejectNumberRequest` | WhatsApp | `/campaign/whatsapp/number-requests/reject` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/number-requests/resend-code` | `WhatsApp::resendNumberRequestCode` | WhatsApp | `/campaign/whatsapp/number-requests/resend-code` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/number-requests/send-meta` | `WhatsApp::sendNumberRequestToMeta` | WhatsApp | `/campaign/whatsapp/number-requests/send-meta` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/numbers` | `WhatsApp::listNumbers` | WhatsApp | `/campaign/whatsapp/numbers` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/whatsapp/numbers/client` | `WhatsApp::registerClientNumber` | WhatsApp | `/campaign/whatsapp/numbers/client` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/numbers/health` | `WhatsApp::listNumberHealth` | WhatsApp | `/campaign/whatsapp/numbers/health` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/whatsapp/numbers/health/sync` | `WhatsApp::syncNumberHealth` | WhatsApp | `/campaign/whatsapp/numbers/health/sync` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/numbers/platform` | `WhatsApp::createPlatformNumber` | WhatsApp | `/campaign/whatsapp/numbers/platform` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/numbers/platform/{id}/assign` | `WhatsApp::assignPlatformNumber` | WhatsApp | `/campaign/whatsapp/numbers/platform/{id}/assign` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/numbers/{id}/confirm-code` | `WhatsApp::confirmNumberVerificationCode` | WhatsApp | `/campaign/whatsapp/numbers/{id}/confirm-code` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/numbers/{id}/remove` | `WhatsApp::removeNumber` | WhatsApp | `/campaign/whatsapp/numbers/{id}/remove` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/numbers/{id}/reveal-pin` | `WhatsApp::revealNumberPin` | WhatsApp | `/campaign/whatsapp/numbers/{id}/reveal-pin` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/numbers/{id}/send-code` | `WhatsApp::sendNumberVerificationCode` | WhatsApp | `/campaign/whatsapp/numbers/{id}/send-code` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/pricing/simulate` | `WhatsApp::simulatePricing` | WhatsApp | `/campaign/whatsapp/pricing/simulate` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/support/agents/status` | `WhatsApp::updateSupportAgentStatus` | WhatsApp | `/campaign/whatsapp/support/agents/status` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/support/assignable-users` | `WhatsApp::listSupportAssignableUsers` | WhatsApp | `/campaign/whatsapp/support/assignable-users` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/support/dashboard` | `WhatsApp::supportDashboard` | WhatsApp | `/campaign/whatsapp/support/dashboard` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/campaign/whatsapp/support/events` | `WhatsApp::supportEvents` | WhatsApp | `/campaign/whatsapp/support/events` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/support/queues` | `WhatsApp::listSupportQueues` | WhatsApp | `/campaign/whatsapp/support/queues` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/support/queues/create` | `WhatsApp::createSupportQueue` | WhatsApp | `/campaign/whatsapp/support/queues/create` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/campaign/whatsapp/support/queues/{id}` | `WhatsApp::getSupportQueue` | WhatsApp | `/campaign/whatsapp/support/queues/{id}` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/support/queues/{id}/agents` | `WhatsApp::upsertSupportQueueAgent` | WhatsApp | `/campaign/whatsapp/support/queues/{id}/agents` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/whatsapp/support/queues/{id}/agents/remove` | `WhatsApp::removeSupportQueueAgent` | WhatsApp | `/campaign/whatsapp/support/queues/{id}/agents/remove` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/whatsapp/support/queues/{id}/delete` | `WhatsApp::deleteSupportQueue` | WhatsApp | `/campaign/whatsapp/support/queues/{id}/delete` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/whatsapp/support/queues/{id}/update` | `WhatsApp::updateSupportQueue` | WhatsApp | `/campaign/whatsapp/support/queues/{id}/update` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/whatsapp/support/send` | `WhatsApp::sendSupportMessage` | WhatsApp | `/campaign/whatsapp/support/send` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/support/sessions/{id}/finish` | `WhatsApp::finishSupportSession` | WhatsApp | `/campaign/whatsapp/support/sessions/{id}/finish` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/support/sessions/{id}/transfer` | `WhatsApp::transferSupportSession` | WhatsApp | `/campaign/whatsapp/support/sessions/{id}/transfer` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/templates` | `WhatsApp::listTemplates` | WhatsApp | `/campaign/whatsapp/templates` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/whatsapp/templates/create` | `WhatsApp::createTemplate` | WhatsApp | `/campaign/whatsapp/templates/create` | sim | não | fora do menu |
| GET | `/campaign/whatsapp/templates/library` | `WhatsApp::listTemplateModels` | WhatsApp | `/campaign/whatsapp/templates/library` | sim | não | fora do menu |
| POST | `/campaign/whatsapp/templates/sync` | `WhatsApp::syncTemplates` | WhatsApp | `/campaign/whatsapp/templates/sync` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/whatsapp/templates/{id}/delete` | `WhatsApp::deleteTemplate` | WhatsApp | `/campaign/whatsapp/templates/{id}/delete` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/campaign/whatsapp/templates/{id}/sync` | `WhatsApp::syncTemplate` | WhatsApp | `/campaign/whatsapp/templates/{id}/sync` | sim | não | fora do menu |
| POST | `/campaign/{id}/delete` | `Campaign::setDeleteCampaign` | SMS | `/campaign/{id}/delete` | sim | não | fora do menu |
| GET | `/campaign/{id}/edit` | `Campaign::getEditCampaign` | SMS | `/campaign/{id}/edit` | sim | não | fora do menu |
| POST | `/campaign/{id}/edit` | `Campaign::setEditCampaign` | SMS | `/campaign/{id}/edit` | sim | não | fora do menu |
| POST | `/campaign/{id}/send` | `SendSms::sendCampaignSms` | SMS | `/campaign/{id}/send` | sim | não | fora do menu |
| GET | `/dashboard` | `Dashboard::getDashboard` | Dashboard | `/dashboard` | não | sim | visível com ACL |
| GET | `/dashboard/cards` | `Dashboard::getDataViewDash` | Dashboard | `/dashboard/cards` | não | não | fora do menu |
| GET | `/dashboard/charts` | `Dashboard::getDataChartsDashboard` | Dashboard | `/dashboard/charts` | não | não | fora do menu |
| GET | `/dashboard/logout` | `Login::setLogout` | Dashboard | `/dashboard/logout` | não | não | fora do menu |
| POST | `/dashboard/swap-plan` | `Dashboard::setUpdatePlan` | Dashboard | `/dashboard/swap-plan` | não | não | fora do menu |
| PUT | `/dashboard/swap-plan-put` | `Dashboard::setUpdatePlan` | Dashboard | `/dashboard/swap-plan-put` | não | não | fora do menu |
| GET | `/exclusao-de-dados` | `Legal::getDataDeletion` | Sem módulo mapeado | `/exclusao-de-dados` | não | não | fora do menu |
| GET | `/global-costs` | `GlobalCosts::getPage` | Administrativo | `/global-costs` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/global-costs/data` | `GlobalCosts::data` | Administrativo | `/global-costs/data` | sim | não | fora do menu |
| GET | `/global-costs/history` | `GlobalCosts::history` | Administrativo | `/global-costs/history` | sim | não | fora do menu |
| POST | `/global-costs/save` | `GlobalCosts::save` | Administrativo | `/global-costs/save` | sim | não | fora do menu |
| GET | `/global-costs/search` | `GlobalCosts::search` | Administrativo | `/global-costs/search` | sim | não | fora do menu |
| POST | `/global-costs/{id}/status` | `GlobalCosts::updateStatus` | Administrativo | `/global-costs/{id}/status` | sim | não | fora do menu |
| GET | `/help/movies` | `HelpMovies::getComponentsMovies` | Sem módulo mapeado | `/help/movies` | não | não | fora do menu |
| GET | `/integrations/whatsapp/embedded-signup/callback` | `WhatsApp::embeddedSignupCallback` | Sem módulo mapeado | `/integrations/whatsapp/embedded-signup/callback` | não | não | fora do menu |
| GET | `/login` | `Login::getLogin` | Sem módulo mapeado | `/login` | não | não | fora do menu |
| POST | `/login` | `Login::setLogin` | Sem módulo mapeado | `/login` | não | não | fora do menu |
| GET | `/logo.png` | `closure` | Sem módulo mapeado | `/logo.png` | não | não | fora do menu |
| GET | `/me/permissions` | `PermissionsUsersRoles::getCurrentPermissions` | Sem módulo mapeado | `/me/permissions` | não | sim | visível com ACL |
| GET | `/notifications` | `UsersNotifications::getNotifications` | Administrativo | `/notifications` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/notifications` | `UsersNotifications::markRead` | Administrativo | `/notifications` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/notifications/delete-all` | `UsersNotifications::deleteAllNotifications` | Administrativo | `/notifications/delete-all` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/notifications/mark-all-read` | `UsersNotifications::markAllAsRead` | Administrativo | `/notifications/mark-all-read` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/pass-confirmed` | `PassReset::getConfirmPassView` | Sem módulo mapeado | `/pass-confirmed` | não | não | fora do menu |
| POST | `/pass-confirmed-new` | `PassReset::getConfirmPassNew` | Sem módulo mapeado | `/pass-confirmed-new` | não | não | fora do menu |
| GET | `/permissions` | `PermissionsUsersRoles::getPermissions` | Permissoes | `/permissions` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/permissions/global-routes/save` | `PermissionsUsersRoles::saveGlobalRoute` | Permissoes | `/permissions/global-routes/save` | sim | não | fora do menu |
| POST | `/permissions/roles/assign-user` | `PermissionsUsersRoles::setAssignUserRole` | Permissoes | `/permissions/roles/assign-user` | sim | não | fora do menu |
| POST | `/permissions/roles/bulk-toggle` | `PermissionsUsersRoles::bulkTogglePermissions` | Permissoes | `/permissions/roles/bulk-toggle` | sim | não | fora do menu |
| POST | `/permissions/roles/clear` | `PermissionsUsersRoles::clearPermissions` | Permissoes | `/permissions/roles/clear` | sim | não | fora do menu |
| POST | `/permissions/roles/list-all-routes` | `PermissionsUsersRoles::getRolePermissions` | Permissoes | `/permissions/roles/list-all-routes` | sim | não | fora do menu |
| POST | `/permissions/roles/list-users` | `PermissionsUsersRoles::getListUsersForRole` | Permissoes | `/permissions/roles/list-users` | sim | não | fora do menu |
| POST | `/permissions/roles/save` | `PermissionsUsersRoles::saveRole` | Permissoes | `/permissions/roles/save` | sim | não | fora do menu |
| POST | `/permissions/roles/sync-template` | `PermissionsUsersRoles::syncRoleFromTemplate` | Permissoes | `/permissions/roles/sync-template` | sim | não | fora do menu |
| POST | `/permissions/roles/toggle` | `PermissionsUsersRoles::togglePermission` | Permissoes | `/permissions/roles/toggle` | sim | não | fora do menu |
| GET | `/permissions/search` | `PermissionsUsersRoles::getAllPermissionsUsers` | Permissoes | `/permissions/search` | sim | não | fora do menu |
| GET | `/permissions/templates` | `PermissionsUsersRoles::getRoleTemplates` | Permissoes | `/permissions/templates` | sim | não | fora do menu |
| GET | `/plans` | `Plans::getPlans` | Administrativo | `/plans` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/plans/save` | `Plans::save` | Administrativo | `/plans/save` | sim | não | fora do menu |
| GET | `/plans/search` | `Plans::search` | Administrativo | `/plans/search` | sim | não | fora do menu |
| GET | `/plans/{id}` | `Plans::show` | Administrativo | `/plans/{id}` | sim | não | fora do menu |
| POST | `/plans/{id}/delete` | `Plans::delete` | Administrativo | `/plans/{id}/delete` | sim | não | fora do menu |
| POST | `/plans/{id}/status` | `Plans::updateStatus` | Administrativo | `/plans/{id}/status` | sim | não | fora do menu |
| GET | `/politica-de-cookies` | `Legal::getCookiePolicy` | Sem módulo mapeado | `/politica-de-cookies` | não | não | fora do menu |
| GET | `/politica-de-privacidade` | `Legal::getPrivacyPolicy` | Sem módulo mapeado | `/politica-de-privacidade` | não | não | fora do menu |
| GET | `/rates` | `RatesResellers::getRates` | Tarifas | `/rates` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/rates/new` | `RatesResellers::setNewRatesUsers` | Tarifas | `/rates/new` | sim | não | fora do menu |
| GET | `/rates/search` | `RatesResellers::getAllRatesUsers` | Tarifas | `/rates/search` | sim | não | fora do menu |
| POST | `/rates/up-status` | `RatesResellers::setStatusRatesUsers` | Tarifas | `/rates/up-status` | sim | não | fora do menu |
| POST | `/rates/update` | `RatesResellers::setUpdateRatesUsers` | Tarifas | `/rates/update` | sim | não | fora do menu |
| GET | `/rates/users-select` | `RatesResellers::getUsersForRateSelect` | Tarifas | `/rates/users-select` | sim | não | fora do menu |
| POST | `/rates/{id}/delete` | `RatesResellers::setDeleteRatesUsers` | Tarifas | `/rates/{id}/delete` | sim | não | fora do menu |
| GET | `/refills` | `Refills::getRefills` | Administrativo | `/refills` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/refills` | `Refills::getComponentsPlains` | Administrativo | `/refills` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/refills/webhooks-asaas` | `WebStatusPix::getCallbackAsaas` | Administrativo | `/refills/webhooks-asaas` | sim | não | fora do menu |
| GET | `/refills/{id}/checkout-pix` | `Refills::getQrCodePix` | Administrativo | `/refills/{id}/checkout-pix` | sim | não | fora do menu |
| GET | `/refills/{id}/status-pix` | `Refills::getStatusPix` | Administrativo | `/refills/{id}/status-pix` | sim | não | fora do menu |
| GET | `/register` | `RegisterUsers::getRegister` | Sem módulo mapeado | `/register` | não | não | fora do menu |
| POST | `/register` | `RegisterUsers::setRegister` | Sem módulo mapeado | `/register` | não | não | fora do menu |
| GET | `/reports` | `Reports::getReportsStatus` | Relatorios | `/reports` | sim | não | fora do menu |
| GET | `/reports/cdr` | `Reports::getCdrComponents` | Relatorios | `/reports/cdr` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/reports/generate/{id}/invoice` | `Reports::downloadRechargePDF` | Relatorios | `/reports/generate/{id}/invoice` | sim | não | fora do menu |
| GET | `/reports/list-cdr` | `Reports::getCdrCallsAnalysis` | Relatorios | `/reports/list-cdr` | sim | não | fora do menu |
| GET | `/reports/notifications` | `Reports::getNotificationsStatus` | Relatorios | `/reports/notifications` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/reports/notifications-realtime` | `Reports::getNotificationsStatusRealtime` | Relatorios | `/reports/notifications-realtime` | sim | não | fora do menu |
| POST | `/reports/notifications/create` | `Reports::createNotification` | Relatorios | `/reports/notifications/create` | sim | não | fora do menu |
| GET | `/reports/notifications/users` | `Reports::getNotificationTargetUsers` | Relatorios | `/reports/notifications/users` | sim | não | fora do menu |
| GET | `/reports/recharge-realtime` | `Reports::getRechargeResellers` | Relatorios | `/reports/recharge-realtime` | sim | não | fora do menu |
| GET | `/reports/recharge-transactions` | `Reports::getTransactionsResellers` | Relatorios | `/reports/recharge-transactions` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/reports/sms` | `Reports::getReportsStatusRealtime` | Relatorios | `/reports/sms` | sim | não | fora do menu |
| GET | `/reports/sms-stream` | `Reports::getStatusSmsStream` | Relatorios | `/reports/sms-stream` | sim | não | fora do menu |
| GET | `/reports/sms-view` | `Reports::getStatusSmsView` | Relatorios | `/reports/sms-view` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/reports/sms-view-realtime` | `Reports::getStatusSmsViewRealtime` | Relatorios | `/reports/sms-view-realtime` | sim | não | fora do menu |
| GET | `/reports/transactions` | `Reports::getTransactionsPix` | Relatorios | `/reports/transactions` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/reports/transactions-realtime` | `Reports::getTransactionsPixRealtime` | Relatorios | `/reports/transactions-realtime` | sim | não | fora do menu |
| POST | `/reports/transactions/{id}/refund` | `Reports::refundTransactionPix` | Relatorios | `/reports/transactions/{id}/refund` | sim | não | fora do menu |
| POST | `/reports/web-pro` | `WebStatusSms::getCallbackPro` | Relatorios | `/reports/web-pro` | sim | não | fora do menu |
| GET | `/reports/whatsapp` | `Reports::getWhatsAppReportView` | Relatorios | `/reports/whatsapp` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/reports/whatsapp-realtime` | `Reports::getWhatsAppReportRealtime` | Relatorios | `/reports/whatsapp-realtime` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/reset-code` | `PassReset::resendResetCode` | Sem módulo mapeado | `/reset-code` | não | não | fora do menu |
| GET | `/reset-pass` | `PassReset::getResetPass` | Sem módulo mapeado | `/reset-pass` | não | não | fora do menu |
| POST | `/reset-pass` | `PassReset::setResetPass` | Sem módulo mapeado | `/reset-pass` | não | não | fora do menu |
| GET | `/reset-pass-code` | `PassReset::setResetPassCode` | Sem módulo mapeado | `/reset-pass-code` | não | não | fora do menu |
| GET | `/site` | `closure` | Sem módulo mapeado | `/site` | não | não | fora do menu |
| GET | `/site-tests` | `SiteTests::index` | Administrativo | `/site-tests` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/site/logo.png` | `closure` | Sem módulo mapeado | `/site/logo.png` | não | não | fora do menu |
| GET | `/support` | `SupportTickets::getComponentsSupportTickets` | Administrativo | `/support` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/support/diagnostics` | `SupportTickets::diagnostics` | Administrativo | `/support/diagnostics` | sim | não | fora do menu |
| GET | `/support/tickets` | `SupportTickets::listTickets` | Administrativo | `/support/tickets` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/support/tickets/create` | `SupportTickets::createTicket` | Administrativo | `/support/tickets/create` | sim | não | fora do menu |
| GET | `/support/tickets/{id}/messages` | `SupportTickets::listMessages` | Administrativo | `/support/tickets/{id}/messages` | sim | não | fora do menu |
| POST | `/support/tickets/{id}/messages/create` | `SupportTickets::addMessage` | Administrativo | `/support/tickets/{id}/messages/create` | sim | não | fora do menu |
| POST | `/support/tickets/{id}/request-close` | `SupportTickets::requestClosure` | Administrativo | `/support/tickets/{id}/request-close` | sim | não | fora do menu |
| POST | `/support/tickets/{id}/status` | `SupportTickets::updateStatus` | Administrativo | `/support/tickets/{id}/status` | sim | não | fora do menu |
| GET | `/system-updates` | `SystemUpdates::getComponentsSystemUpdates` | Administrativo | `/system-updates` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/system-updates/create` | `SystemUpdates::create` | Administrativo | `/system-updates/create` | sim | não | fora do menu |
| GET | `/system-updates/header` | `SystemUpdates::headerList` | Administrativo | `/system-updates/header` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| GET | `/system-updates/list` | `SystemUpdates::list` | Administrativo | `/system-updates/list` | sim | não | fora do menu |
| GET | `/system-updates/marketing` | `Marketing::getAdminMarketing` | Administrativo | `/system-updates/marketing` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/system-updates/marketing/create` | `Marketing::create` | Administrativo | `/system-updates/marketing/create` | sim | não | fora do menu |
| GET | `/system-updates/marketing/dashboard` | `Marketing::dashboard` | Administrativo | `/system-updates/marketing/dashboard` | sim | não | fora do menu |
| GET | `/system-updates/marketing/settings` | `Marketing::settings` | Administrativo | `/system-updates/marketing/settings` | sim | não | fora do menu |
| POST | `/system-updates/marketing/settings/save` | `Marketing::saveSettings` | Administrativo | `/system-updates/marketing/settings/save` | sim | não | fora do menu |
| POST | `/system-updates/marketing/settings/test` | `Marketing::testSettings` | Administrativo | `/system-updates/marketing/settings/test` | sim | não | fora do menu |
| GET | `/system-updates/marketing/{id}` | `Marketing::show` | Administrativo | `/system-updates/marketing/{id}` | sim | não | fora do menu |
| POST | `/system-updates/marketing/{id}/activate` | `Marketing::activate` | Administrativo | `/system-updates/marketing/{id}/activate` | sim | não | fora do menu |
| POST | `/system-updates/marketing/{id}/archive` | `Marketing::archive` | Administrativo | `/system-updates/marketing/{id}/archive` | sim | não | fora do menu |
| POST | `/system-updates/marketing/{id}/assets/upload` | `Marketing::uploadAsset` | Administrativo | `/system-updates/marketing/{id}/assets/upload` | sim | não | fora do menu |
| POST | `/system-updates/marketing/{id}/delete` | `Marketing::delete` | Administrativo | `/system-updates/marketing/{id}/delete` | sim | não | fora do menu |
| POST | `/system-updates/marketing/{id}/meta/sync-preview` | `Marketing::syncPreview` | Administrativo | `/system-updates/marketing/{id}/meta/sync-preview` | sim | não | fora do menu |
| POST | `/system-updates/marketing/{id}/pause` | `Marketing::pause` | Administrativo | `/system-updates/marketing/{id}/pause` | sim | não | fora do menu |
| POST | `/system-updates/marketing/{id}/status` | `Marketing::status` | Administrativo | `/system-updates/marketing/{id}/status` | sim | não | fora do menu |
| POST | `/system-updates/marketing/{id}/update` | `Marketing::update` | Administrativo | `/system-updates/marketing/{id}/update` | sim | não | fora do menu |
| POST | `/system-updates/update` | `SystemUpdates::update` | Administrativo | `/system-updates/update` | sim | não | fora do menu |
| GET | `/system-updates/users` | `SystemUpdates::users` | Administrativo | `/system-updates/users` | sim | não | fora do menu |
| GET | `/termos-de-servico` | `Legal::getTerms` | Sem módulo mapeado | `/termos-de-servico` | não | não | fora do menu |
| GET | `/termos-de-uso` | `Legal::getTerms` | Sem módulo mapeado | `/termos-de-uso` | não | não | fora do menu |
| GET | `/users` | `Users::getUsers` | Usuarios | `/users` | sim | sim | visível com ACL; bloqueia no clique sem módulo |
| POST | `/users/edit` | `Users::setUsersEdit` | Usuarios | `/users/edit` | sim | não | fora do menu |
| GET | `/users/new` | `Users::getNewUsers` | Usuarios | `/users/new` | sim | não | fora do menu |
| POST | `/users/new` | `Users::getSetNewUsers` | Usuarios | `/users/new` | sim | não | fora do menu |
| GET | `/users/profile` | `Users::getUsersProfile` | Usuarios | `/users/profile` | não | não | fora do menu |
| POST | `/users/profile/reset-pass` | `Users::setUsersResetPass` | Usuarios | `/users/profile/reset-pass` | não | não | fora do menu |
| POST | `/users/profile/update-address` | `Users::setUserAddress` | Usuarios | `/users/profile/update-address` | não | não | fora do menu |
| POST | `/users/profile/upload-images` | `Users::setUsersImagesProfile` | Usuarios | `/users/profile/upload-images` | não | não | fora do menu |
| POST | `/users/refills` | `Refills::setRefillsResellers` | Usuarios | `/users/refills` | sim | não | fora do menu |
| GET | `/users/search` | `Users::getAllUsers` | Usuarios | `/users/search` | sim | não | fora do menu |
| POST | `/users/session` | `HeartbeatController::updateSession` | Sem módulo mapeado | `/users/session` | não | não | fora do menu |
| POST | `/users/up-status` | `Users::setNewStatusUsers` | Usuarios | `/users/up-status` | sim | não | fora do menu |
| POST | `/users/{id}/delete` | `Users::setDeleteUsers` | Usuarios | `/users/{id}/delete` | sim | não | fora do menu |
| GET | `/users/{id}/edit` | `Users::getUsersEdit` | Usuarios | `/users/{id}/edit` | sim | não | fora do menu |
| POST | `/validate-reset-code` | `PassReset::setConfirmPassCode` | Sem módulo mapeado | `/validate-reset-code` | não | não | fora do menu |
| GET | `/webhooks/meta/whatsapp` | `WhatsApp::verifyWebhook` | Sem módulo mapeado | `/webhooks/meta/whatsapp` | não | não | fora do menu |
| POST | `/webhooks/meta/whatsapp` | `WhatsApp::receiveWebhook` | Sem módulo mapeado | `/webhooks/meta/whatsapp` | não | não | fora do menu |
| POST | `/whatsapp/confirm-number` | `WhatsApp::confirmNumberRequestCode` | WhatsApp | `/whatsapp/confirm-number` | sim | não | fora do menu |
| POST | `/whatsapp/connection` | `WhatsApp::createAccount` | WhatsApp | `/whatsapp/connection` | sim | não | fora do menu |
| POST | `/whatsapp/send-message` | `WhatsApp::sendDirectMessage` | WhatsApp | `/whatsapp/send-message` | sim | não | fora do menu |
| POST | `/whatsapp/verification` | `WhatsApp::resendNumberRequestCode` | WhatsApp | `/whatsapp/verification` | sim | não | fora do menu |

## Módulos Oficiais

| Chave | Label | Features | Permissões-base | Menus |
| --- | --- | --- | --- | --- |
| `users` | Usuarios | `users` | `/users` | `administrativo.gestao.usuarios` |
| `rates` | Tarifas | `rates` | `/rates` | `tarifas` |
| `permissions` | Permissoes | `permissions` | `/permissions` | `administrativo.configuracao.permissoes` |
| `reports` | Relatorios | `reports` | `/reports, /callcenter/reports` | `relatorios` |
| `administrative` | Administrativo | `administrative` | `/refills, /notifications, /plans, /support, /system-updates, /admin/services-monitor, /global-costs, /admin/platform-consumption` | `administrativo, recargas` |
| `dashboard` | Dashboard | `` | `/dashboard` | `dashboard` |
| `voice` | Voz | `voice` | `/campaign/voice` | `voz.operacao, voz.recursos` |
| `callcenter` | Call Center | `callcenter` | `/callcenter, /callcenter/monitoring` | `voz.configuracao.callcenter` |
| `whatsapp` | WhatsApp | `whatsapp` | `/campaign/whatsapp` | `whatsapp` |
| `movies` | Ajuda | `` | `/movies` | `` |
| `sms` | SMS | `sms` | `/campaign` | `mensageria` |

## Rotas Sem Módulo no Mapa Oficial

- `GET /` -> `Login::getLogin`
- `GET /` -> `closure`
- `OPTIONS /api/public/demo/check` -> `PublicDemo::options`
- `POST /api/public/demo/check` -> `PublicDemo::check`
- `GET /api/public/demo/{channel}` -> `closure`
- `OPTIONS /api/public/demo/{channel}` -> `PublicDemo::options`
- `POST /api/public/demo/{channel}` -> `PublicDemo::send`
- `GET /api/site/stats` -> `PublicDemo::stats`
- `OPTIONS /api/site/stats` -> `PublicDemo::options`
- `GET /auth/social/{provider}` -> `SocialAuth::redirectToProvider`
- `GET /auth/social/{provider}/callback` -> `SocialAuth::handleCallback`
- `POST /auth/social/{provider}/callback` -> `SocialAuth::handleCallback`
- `GET /callback` -> `closure`
- `POST /callback` -> `WebStatusSms::getCallbackPro`
- `GET /exclusao-de-dados` -> `Legal::getDataDeletion`
- `GET /help/movies` -> `HelpMovies::getComponentsMovies`
- `GET /integrations/whatsapp/embedded-signup/callback` -> `WhatsApp::embeddedSignupCallback`
- `GET /login` -> `Login::getLogin`
- `POST /login` -> `Login::setLogin`
- `GET /logo.png` -> `closure`
- `GET /me/permissions` -> `PermissionsUsersRoles::getCurrentPermissions`
- `GET /pass-confirmed` -> `PassReset::getConfirmPassView`
- `POST /pass-confirmed-new` -> `PassReset::getConfirmPassNew`
- `GET /politica-de-cookies` -> `Legal::getCookiePolicy`
- `GET /politica-de-privacidade` -> `Legal::getPrivacyPolicy`
- `GET /register` -> `RegisterUsers::getRegister`
- `POST /register` -> `RegisterUsers::setRegister`
- `POST /reset-code` -> `PassReset::resendResetCode`
- `GET /reset-pass` -> `PassReset::getResetPass`
- `POST /reset-pass` -> `PassReset::setResetPass`
- `GET /reset-pass-code` -> `PassReset::setResetPassCode`
- `GET /site` -> `closure`
- `GET /site/logo.png` -> `closure`
- `GET /termos-de-servico` -> `Legal::getTerms`
- `GET /termos-de-uso` -> `Legal::getTerms`
- `POST /users/session` -> `HeartbeatController::updateSession`
- `POST /validate-reset-code` -> `PassReset::setConfirmPassCode`
- `GET /webhooks/meta/whatsapp` -> `WhatsApp::verifyWebhook`
- `POST /webhooks/meta/whatsapp` -> `WhatsApp::receiveWebhook`

## Limitações desta execução

- Esta execução auditou o código-fonte local.
- A auditoria de `sys_routes`, `sys_role_permissions`, `sys_role_template_permissions` e `mxx_plans.modules_json` no banco não pôde ser concluída porque a conexão MySQL local recusou conexão neste ambiente.
- Os itens de menu foram inferidos a partir das referências de rota em `ViewComponents.php`.

