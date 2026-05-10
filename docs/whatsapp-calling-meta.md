# WhatsApp Calling API + WebRTC

## Resumo

Esta camada integra o controle de chamadas da Meta WhatsApp Calling API ao stack atual sem substituir o fluxo existente de atendimento por voz.

O que foi implementado nesta etapa:

- cliente Graph para Calling API
- fluxo de permissao de chamada outbound
- tratamento de webhook `calls` e `statuses` de calling
- trilha de logs detalhados para lifecycle e SDP
- compatibilidade com SSE do atendimento
- endpoints autenticados para `connect`, `pre_accept`, `accept`, `reject`, `terminate`
- armazenamento de snapshots de sessão em `runtime/whatsapp-calling/sessions`

O que **nao** foi reescrito:

- frontend WebRTC atual
- fluxo JsSIP/Asterisk atual
- atendimento de mensagens atual

## Arquitetura

### Componentes locais

- `app/Service/MetaWhatsAppCloudApi.php`
  - novos metodos para `calling settings`, `call_permissions` e `POST /calls`
- `app/Service/WhatsAppCallingBridge.php`
  - normaliza eventos da Meta
  - resume SDP/ICE/codecs
  - persiste snapshots de sessao
  - emite eventos internos em `whatsapp_support_events`
- `app/Controller/Pages/WhatsApp.php`
  - webhook agora trata `value.calls`
  - separa `statuses` de mensagem dos `statuses` de chamada
  - expõe endpoints autenticados de controle

### Fluxo inbound

1. Meta envia webhook `calls` com `event=connect` e SDP offer.
2. `receiveWebhook()` encaminha para `WhatsAppCallingBridge`.
3. O snapshot da sessao e gravado em `runtime/whatsapp-calling/sessions/<call_id>.json`.
4. Um evento SSE interno e emitido para o painel.
5. O operador/backend externo usa:
   - `POST /campaign/whatsapp/calls/{id}/pre-accept`
   - `POST /campaign/whatsapp/calls/{id}/accept`
6. A resposta da Meta e logada e o estado local e atualizado.

### Fluxo outbound

1. O backend RTC gera uma SDP offer WebRTC.
2. O sistema chama `POST /campaign/whatsapp/calls/connect`.
3. O controller encaminha para `POST /{phone-number-id}/calls` com `action=connect`.
4. Status de `RINGING`, `ACCEPTED`, `REJECTED` chegam em `value.statuses`.
5. `TERMINATE` chega em `value.calls`.

## Endpoints locais

### Consulta e inspeção

- `GET /campaign/whatsapp/calls/permissions?account_id={id}&user_wa_id={numero}`
- `POST /campaign/whatsapp/calls/permissions/request`
- `GET /campaign/whatsapp/calls/sessions`
- `GET /campaign/whatsapp/calls/sessions/{call_id}`

### Controle de chamada

- `POST /campaign/whatsapp/calls/connect`
- `POST /campaign/whatsapp/calls/{call_id}/pre-accept`
- `POST /campaign/whatsapp/calls/{call_id}/accept`
- `POST /campaign/whatsapp/calls/{call_id}/reject`
- `POST /campaign/whatsapp/calls/{call_id}/terminate`

## Payloads esperados

### Outbound connect

```json
{
  "account_id": 17,
  "to": "5511999999999",
  "biz_opaque_callback_data": "conversation:30",
  "session": {
    "sdp_type": "offer",
    "sdp": "v=0\r\n..."
  }
}
```

### Inbound pre-accept ou accept

```json
{
  "account_id": 17,
  "session": {
    "sdp_type": "answer",
    "sdp": "v=0\r\n..."
  }
}
```

### Reject ou terminate

```json
{
  "account_id": 17
}
```

## Logs

### Arquivos

- webhook geral: `C:/wamp64/logs/meta_whatsapp_webhook_dump.log`
- calling: `C:/wamp64/logs/meta_whatsapp_calling.log`
- snapshots: `runtime/whatsapp-calling/sessions/*.json`

### Eventos principais

- `call.permission.updated`
- `call.status_webhook`
- `call.lifecycle_webhook`
- `call.control_action`
- `call.support_event_failed`

## Permissao de chamada

### Fluxo

1. O painel consulta `GET /campaign/whatsapp/calls/permissions`.
2. Se o contato nao puder receber ligacao, o painel libera `Solicitar permissao`.
3. O backend envia uma mensagem interativa `call_permission_request` pela Cloud API.
4. O status local fica como `pending`.
5. Quando a Meta responder por webhook, o sistema persiste:
   - `granted`
   - `temporary`
   - `pending`
   - `denied`
   - `expired`
   - `no_permission`
6. O backend emite `call.permission.updated` no SSE.
7. So depois de `granted` ou `temporary` o endpoint `connect` deixa a chamada sair.

### Persistencia local

Tabela: `whatsapp_call_permissions`

Campos principais:

- `contact_id`
- `phone_number`
- `permission_status`
- `permission_requested_at`
- `permission_approved_at`
- `permission_expires_at`
- `last_error_code`
- `last_error_message`
- `meta_payload`

Script de criacao:

- `database/sql/20260508_whatsapp_call_permissions.sql`

### Dados resumidos de SDP

Cada snapshot registra:

- `sdp_type`
- `has_audio`
- `has_video`
- `media`
- `codecs`
- `direction`
- `ice_candidates`
- `ice_lite`
- `trickle`

## Dependências da Meta

Fontes usadas nesta auditoria:

- documentacao oficial da Meta referenciada pelo projeto
- espelho da documentacao em ChatArchitect, pois o acesso direto ao `developers.facebook.com` estava indisponivel no momento da auditoria

Pontos confirmados:

- `GET /{phone-number-id}/call_permissions?user_wa_id=...`
- `POST /{phone-number-id}/calls`
- `calling settings` no recurso do numero
- inbound usa webhook `calls`
- outbound recebe `RINGING`, `ACCEPTED`, `REJECTED` em `statuses`
- `TERMINATE` volta em `calls`
- SDP segue WebRTC e inclui ICE dentro do SDP
- codec obrigatorio na pratica e `OPUS`; amostras da Meta tambem mostram `telephone-event`, `PCMU`, `PCMA`, `G722` em alguns cenarios

## Limitações atuais

### Bridge de mídia

Este workspace **nao** contem o bridge de mídia WebRTC puro entre Meta e o backend RTC proprio citado no contexto.

Hoje, o repositorio expõe principalmente:

- JsSIP no browser
- Asterisk/WebSocket para voz existente

Por isso, esta etapa entrega:

- call control
- lifecycle
- logs
- compatibilidade de eventos

Mas a negociação de mídia ponta a ponta com a Meta ainda depende de um adaptador externo que:

- receba a SDP offer/answer
- conecte ao seu backend RTC Node/WebRTC
- gere as respostas SDP compatíveis
- mantenha a sessão RTP/DTLS/ICE durante a chamada

## Rollback

- desligar via `WHATSAPP_CALLING_BRIDGE_ENABLED=false`
- remover uso dos endpoints `/campaign/whatsapp/calls/*`
- os webhooks de mensagem continuam funcionando sem dependência da calling layer

## Próximos testes

1. Habilitar `WHATSAPP_CALLING_BRIDGE_ENABLED`.
2. Confirmar recebimento de `calls` no webhook da Meta.
3. Validar `GET /campaign/whatsapp/calls/sessions` após uma tentativa inbound.
4. Validar `connect` com uma SDP offer real do backend RTC.
5. Validar `pre_accept` e `accept` com answer gerada pelo bridge externo.
6. Confirmar eventos SSE `call.meta.*` no painel.
