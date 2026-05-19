# Auditoria tecnica e arquitetura de campanhas

## Escopo analisado

- `SMS`: `app/Controller/Pages/SendSms.php`, `app/Model/Entity/CampaignSearch.php`, `app/Model/Entity/CallbackSms.php`, `app/Model/Entity/CampaignBatch.php`
- `WhatsApp`: `app/Controller/Pages/WhatsApp.php`, `app/Model/Entity/WhatsAppCampaign.php`, `app/Model/Entity/WhatsAppOutbox.php`, `app/Service/WhatsAppOutboxWorker.php`
- `Voz`: `app/Controller/Pages/Voice.php`, `app/Model/Entity/CampaignVoice.php`, `app/Model/Entity/CampaignVoiceSchedule.php`, `app/Service/VoiceWorker.php`

## Diagnostico encontrado

- `SMS` nao possuia fila persistente nem agendamento backend real. O disparo era sincrono dentro da requisicao HTTP, com risco de timeout, perda parcial de progresso e travamento em campanhas massivas.
- `WhatsApp` ja possuia uma fila madura em `whatsapp_outbox`, com retries e worker dedicados, mas o `scheduled_at` da campanha nao era promovido por um scheduler central.
- `Voz` ja salvava campanhas agendadas em `campaign_voice_schedules`, mas nao havia um despachante unificado consumindo esses agendamentos de forma segura e auditavel.
- Nao existia uma camada transversal de status, lock, run history e logs para todos os canais.

## Riscos mapeados antes da mudanca

- Duplicidade por reenvio manual e ausencia de `dedupe` central.
- Timeout HTTP em disparos grandes de SMS.
- Lacuna entre campanha agendada e disparo efetivo em voz.
- Falta de trilha unificada de auditoria para fila, retry e erros de dispatch.
- Risco de corrida entre workers sem lease/lock comum por campanha.
- Ausencia de rotina central de limpeza e rotacao operacional.

## Estrutura criada

### Banco

- `campaign_dispatch_schedules`
  - agenda mestre por canal, com `status`, `available_at`, `attempt_count`, `locked_at`, `locked_by`, `dedupe_key`, `payload_json`
- `campaign_dispatch_runs`
  - historico por execucao de worker
- `campaign_dispatch_logs`
  - trilha detalhada de eventos e erros
- `campaign_sms_queue`
  - fila persistente por destinatario para SMS

### Servicos

- `app/Service/CampaignSchedulerService.php`
  - scheduler central, retries e roteamento por canal
- `app/Service/CampaignDispatchRepository.php`
  - leases, locks, agendamentos, runs, fila SMS e limpeza
- `app/Service/CampaignDispatchLogger.php`
  - logging unificado
- `app/Service/SmsCampaignPreparationService.php`
  - preflight financeiro, validacao e expansao de contatos
- `app/Service/SmsCampaignQueueService.php`
  - expansao de campanha SMS para fila persistente
- `app/Service/SmsCampaignQueueWorker.php`
  - processamento assicrono da fila SMS
- `app/Service/WhatsAppCampaignDispatchService.php`
  - reaproveita a `whatsapp_outbox` existente para campanhas despachadas pelo scheduler
- `app/Service/VoiceCampaignDispatchService.php`
  - injeta campanhas agendadas na fila Redis de voz e sincroniza status legado

### Integracoes de canal

- `SMS`
  - `SendSms::sendCampaignSms()` agora aceita agendamento e cria schedule central antes de sair da requisicao
- `WhatsApp`
  - `WhatsApp::sendCampaign()` agora identifica `scheduled_at` futuro, cria agendamento central e marca a campanha como `scheduled`
  - quando nao ha agendamento futuro, usa `WhatsAppCampaignDispatchService`
- `Voz`
  - `Voice.php` continua criando `campaign_voice_schedules`, mas agora tambem registra o mesmo disparo no scheduler central

## Fluxo completo

1. O usuario cria ou envia a campanha.
2. Se houver agendamento futuro, a campanha entra em `campaign_dispatch_schedules`.
3. `run_campaign_scheduler.php` busca campanhas prontas, aplica lock e despacha por canal.
4. `SMS` expande a campanha em `campaign_sms_queue`.
5. `run_campaign_sms_worker.php` consome a fila SMS com retry exponencial.
6. `WhatsApp` reaproveita `whatsapp_outbox` e `run_whatsapp_worker.php`.
7. `Voz` reaproveita `voice:queue` e `run_voice_worker.php`.
8. Todos os eventos relevantes sao registrados em `campaign_dispatch_logs` e `campaign_dispatch_runs`.

## Status suportados

- `scheduled`
- `dispatching`
- `queued`
- `retry_waiting`
- `completed`
- `failed`
- `cancelled`

## Locks e concorrencia

- Leases por `locked_at` e `locked_by` no scheduler e na fila SMS.
- `dedupe_key` unico por agendamento e por item da fila SMS.
- Reuso da protecao existente do `whatsapp_outbox`.
- Separacao entre scheduler central e workers por canal para reduzir disputa e facilitar escala horizontal.

## Cron jobs recomendados

Assumindo o projeto em `/var/www/painel` e PHP CLI em `/usr/bin/php`.

```cron
* * * * * /usr/bin/php /var/www/painel/run_campaign_scheduler.php 25 >> /var/log/sms/campaign-scheduler.log 2>&1
* * * * * /usr/bin/php /var/www/painel/run_campaign_retry_worker.php 20 >> /var/log/sms/campaign-retry.log 2>&1
* * * * * /usr/bin/php /var/www/painel/run_campaign_sms_worker.php 200 >> /var/log/sms/campaign-sms-worker.log 2>&1
* * * * * /usr/bin/php /var/www/painel/run_whatsapp_worker.php 50 >> /var/log/sms/whatsapp-worker.log 2>&1
* * * * * /usr/bin/php /var/www/painel/run_voice_worker.php >> /var/log/sms/voice-worker.log 2>&1
15 3 * * * /usr/bin/php /var/www/painel/run_campaign_cleanup.php 30 30 15 >> /var/log/sms/campaign-cleanup.log 2>&1
```

### Frequencia ideal

- Scheduler central: a cada minuto
- Retry: a cada minuto
- Worker SMS: a cada minuto, ou em paralelo com supervisao para alto volume
- Worker WhatsApp: a cada minuto, ou daemon dedicado como ja existe no projeto
- Worker Voz: processo dedicado continuo ou cron de watchdog
- Cleanup: 1x por dia, fora do pico

## Recomendacoes de performance

- Manter lotes pequenos por worker, por exemplo `25` schedules e `200` SMS por execucao.
- Escalar horizontalmente apenas os workers por canal, nunca o scheduler sem monitorar `locked_at`.
- Indexar consultas de dashboard e auditoria pelos novos indices de `status`, `available_at` e `tenancy_id`.
- Evitar carregar campanhas inteiras em memoria quando o canal ja oferece fila nativa paginada.
- Para campanhas muito grandes de SMS, considerar batch insert em `campaign_sms_queue` como evolucao seguinte.

## Pontos criticos ainda merecem atencao

- `WhatsAppCampaignDispatchService` ainda pode receber evolucao para herdar toda a riqueza de personalizacao e auditoria hoje embutida no controller.
- Cancelamento e pausa operacional podem ser expandidos com comandos CLI dedicados por schedule.
- Recorrencia futura ainda nao foi ativada, mas a estrutura ja suporta evolucao com `next_run_at` e politicas RRULE.
- Vale criar telas operacionais para visualizar `campaign_dispatch_schedules`, `campaign_dispatch_runs` e `campaign_dispatch_logs`.
