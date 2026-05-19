# Auditoria de Planos, Modulos, Limites e Bloqueios Reais

Data: 2026-05-18

## Resumo executivo

O sistema hoje possui duas camadas reais de governanca comercial por plano:

- bloqueio por modulo/feature via `PlanRuntimeService` + `PermissionMiddleware`
- alguns limites numericos pontuais via `PlanAccessPolicy`

Porem, a maior parte dos campos numericos exibidos na tela de planos ainda nao possui bloqueio operacional real no backend. Em varios casos, o campo existe no banco, aparece no frontend e entra no runtime, mas nao ha chamada efetiva da validacao no fluxo de negocio.

## Estrutura atual no banco

Estrutura principal identificada:

- `mxx_plans`: catalogo de planos
- `mxx_user_plans`: vinculo/subscricao do tenant ao plano
- `tenancies.active_plan_id`: plano comercial ativo do tenant
- `tenancy_balance`: snapshot aplicado do plano e tarifas operacionais
- `tenancy_balance.snapshot_json`: congelamento de `pricing`, `limits`, `features` e `catalog`

Campos configuraveis do plano confirmados em `mxx_plans`:

- `simultaneous_access`
- `users_create`
- `users_limit`
- `sms_limit`
- `voice_limit`
- `camp_qtd` (`campaigns_limit` no codigo)
- `trunks`
- `whatsapp_accounts`
- `templates_limit`
- `webrtc_enabled`
- `modules_json`
- tarifas e precos:
  - `value_sms`
  - `value_voice`
  - `voice_open_rate`
  - `voice_smart_rate`
  - `value_whatsapp`
  - `value_whatsapp_marketing`
  - `value_whatsapp_utility`
  - `value_whatsapp_authentication`
  - `whatsapp_voice_enabled`
  - `whatsapp_voice_price_per_minute`
  - `whatsapp_voice_markup_percent`
  - `whatsapp_voice_billing_pulse_seconds`

Observacao importante:

- nao encontrei uso real de tabelas normalizadas do tipo `plan_modules`, `plan_features`, `plan_limits`, `tenant_modules` ou `tenant_features` no fluxo principal atual
- hoje o desenho efetivo esta concentrado em `mxx_plans.modules_json` + snapshot aplicado em `tenancy_balance`

## Runtime real do plano

O runtime ativo e montado por [PlanRuntimeService.php](/c:/wamp64/www/sms/app/Service/PlanRuntimeService.php).

Features/módulos efetivos:

- `administrative`
- `users`
- `create_users`
- `rates`
- `permissions`
- `reports`
- `campaigns`
- `sms`
- `voice`
- `callcenter`
- `webrtc`
- `whatsapp`
- `templates`
- `trunks`

Limites expostos no runtime:

- `simultaneous_access`
- `users`
- `sms`
- `voice`
- `campaigns`
- `trunks`
- `whatsapp_accounts`
- `templates`

## Bloqueios comprovadamente funcionando

### 1. Bloqueio por modulo/feature

Existe bloqueio real no backend via [PermissionMiddleware.php](/c:/wamp64/www/sms/app/Http/Middleware/PermissionMiddleware.php) e [ModuleAccessMap.php](/c:/wamp64/www/sms/app/Service/ModuleAccessMap.php).

Fluxo:

1. valida ACL de rota
2. resolve feature(s) exigida(s) pela rota
3. chama `PlanRuntimeService::assertCanUseFeature()`
4. bloqueia HTML, JSON e SSE com mensagem comercial

Status: `FUNCIONA`

Modulos com bloqueio comercial mapeado por rota:

- `administrative`
- `reports`
- `sms`
- `voice`
- `callcenter`
- `whatsapp`
- `templates`
- `trunks`
- `create_users`
- `webrtc`

### 2. Acessos simultaneos

Existe bloqueio real em [PlanAccessPolicy.php](/c:/wamp64/www/sms/app/Service/PlanAccessPolicy.php) e o login chama isso em [Login.php](/c:/wamp64/www/sms/app/Controller/Pages/Login.php).

Regra atual comprovada:

- superadmin faz bypass
- se `simultaneous_access <= 0`, o codigo trata como sem limite efetivo
- a contagem nao usa tabela dedicada de sessoes ativas
- a contagem usa a tabela `users`, considerando:
  - `status = 'y'`
  - `last_activity >= agora - 3600s`

Status: `FUNCIONA`, com a ressalva de que o controle e por janela de atividade, nao por sessao transacional dedicada

### 3. Criacao de usuarios

Existe bloqueio real em:

- [PlanAccessPolicy.php](/c:/wamp64/www/sms/app/Service/PlanAccessPolicy.php)
- [Users.php](/c:/wamp64/www/sms/app/Controller/Pages/Users.php)

Regra atual comprovada:

- exige `administrative`
- exige `create_users`
- bloqueia tela de novo usuario
- bloqueia POST de criacao

Status: `FUNCIONA`

Importante:

- isso bloqueia o ato de criar usuario
- nao existe validacao numerica comprovada contra `users_limit`

### 4. Limite de trunks

Existe bloqueio real em:

- [PlanAccessPolicy.php](/c:/wamp64/www/sms/app/Service/PlanAccessPolicy.php)
- [Voice.php](/c:/wamp64/www/sms/app/Controller/Pages/Voice.php)

Regra atual comprovada:

- conta trunks atuais
- chama `assertCanCreateTrunk()`
- bloqueia criacao ao atingir o limite

Status: `FUNCIONA`

### 5. Limite de contas/numeros de WhatsApp

Existe bloqueio real em:

- [PlanAccessPolicy.php](/c:/wamp64/www/sms/app/Service/PlanAccessPolicy.php)
- [WhatsApp.php](/c:/wamp64/www/sms/app/Controller/Pages/WhatsApp.php)
- [WhatsAppNumberManager.php](/c:/wamp64/www/sms/app/Service/WhatsAppNumberManager.php)

Regra atual comprovada:

- conta numeros/contas ativos do tenant
- chama `assertCanCreateWhatsAppAccount()`
- bloqueia criacao ao atingir o limite

Status: `FUNCIONA`

### 6. Templates proprios de WhatsApp

Existe bloqueio real por feature em:

- [WhatsApp.php](/c:/wamp64/www/sms/app/Controller/Pages/WhatsApp.php)
- [PlanRuntimeService.php](/c:/wamp64/www/sms/app/Service/PlanRuntimeService.php)

Regra atual comprovada:

- pode usar a area principal de WhatsApp com modulo `whatsapp`
- para criar/sincronizar templates proprios, exige feature `templates`

Status: `FUNCIONA`

### 7. WebRTC

Existe bloqueio real por feature em:

- [Voice.php](/c:/wamp64/www/sms/app/Controller/Pages/Voice.php)
- [PlanRuntimeService.php](/c:/wamp64/www/sms/app/Service/PlanRuntimeService.php)

Regra atual comprovada:

- ao solicitar criacao de ramal WebRTC, chama `assertCanUseFeature('webrtc')`

Status: `FUNCIONA`

## Itens cadastrados no plano sem bloqueio operacional comprovado

Os itens abaixo existem no banco, entram no runtime e aparecem na tela, mas nao encontrei chamada efetiva de bloqueio de negocio no backend:

- `users_limit`
- `sms_limit`
- `voice_limit`
- `campaigns_limit`
- `templates_limit` como limite numerico

Observacoes:

- `templates` funciona como modulo/feature, mas `templates_limit` nao tem enforcement numerico comprovado
- `campaigns_limit` participa da formacao da feature `campaigns`, mas nao ha bloqueio por quantidade encontrado
- `sms_limit` nao bloqueia envio por quantidade; o SMS hoje parece ser controlado por saldo/tarifa
- `voice_limit` nao bloqueia chamadas ou canais ativos; nao encontrei uso como limite de chamadas simultaneas
- `users_limit` nao bloqueia quantidade total de usuarios cadastrados

## Menu e frontend

Tela de planos:

- exibe e salva corretamente os campos em [Plans.php](/c:/wamp64/www/sms/app/Controller/Pages/Plans.php) e [plans/index.html](/c:/wamp64/www/sms/resources/view/plans/index.html)

Menu/sidebar:

- o menu principal em [ViewComponents.php](/c:/wamp64/www/sms/app/Controller/Pages/ViewComponents.php) continua sendo montado principalmente por permissao de rota
- o bloqueio comercial de plano esta forte no backend/middleware
- portanto, em varias areas, o menu pode continuar visivel e o clique ser bloqueado pelo plano

Status do frontend:

- `salva no plano`: `SIM`
- `prova de bloqueio so pelo frontend`: `NAO`
- `prova de bloqueio efetivo`: depende do backend correspondente

## Matriz consolidada

| Recurso | Existe no plano? | Campo/tabela | Validacao backend | Validacao frontend | Status | Correcao necessaria |
| --- | --- | --- | --- | --- | --- | --- |
| Modulo Administrativo | Sim | `mxx_plans.modules_json` | `PermissionMiddleware` + `PlanRuntimeService::assertCanUseFeature()` | Menu nao e a fonte principal do bloqueio | Funciona | Opcional alinhar menu com plano |
| Modulo Usuarios | Sim | `modules_json` + legado `users_create` | `/users` depende de `administrative`; criacao depende de `administrative` + `create_users` | Tela responde ao backend | Funciona parcialmente | Se quiser modulo independente de Administrativo, separar no mapa |
| Criar usuarios | Sim | `users_create` / `modules_json.create_users` | `PlanAccessPolicy::canCreateUsers()` em `Users.php` | Sim | Funciona | Nenhuma imediata |
| Limite de usuarios | Sim | `mxx_plans.users_limit` | Nao encontrei uso real | Exibido na tela | Nao bloqueado | Implementar contagem e bloqueio de criacao/ativacao |
| Modulo Permissoes | Sim | `modules_json.permissions` | Hoje as rotas `/permissions` estao sob feature `administrative` | Sim | Funciona via Administrativo | Rever se deseja modulo proprio |
| Modulo Tarifas | Sim | `modules_json.rates` | Bloqueio por feature `rates` | Sim | Funciona | Nenhuma imediata |
| Modulo Relatorios | Sim | `modules_json.reports` | `PermissionMiddleware` | Sim | Funciona | Nenhuma imediata |
| Modulo SMS | Sim | `modules_json.sms` | `PermissionMiddleware` | Sim | Funciona | Nenhuma imediata |
| Limite de SMS | Sim | `mxx_plans.sms_limit` | Nao encontrei uso real | Exibido na tela | Nao bloqueado | Implementar contador diario/mensal ou por saldo, conforme regra comercial |
| Modulo Voz | Sim | `modules_json.voice` | `PermissionMiddleware` | Sim | Funciona | Nenhuma imediata |
| Limite de Voz | Sim | `mxx_plans.voice_limit` | Nao encontrei uso real | Exibido na tela | Nao bloqueado | Definir se sera minutos, canais ou chamadas simultaneas |
| WebRTC | Sim | `webrtc_enabled` / `modules_json.webrtc` | `assertCanUseFeature('webrtc')` em `Voice.php` | Sim | Funciona | Nenhuma imediata |
| Trunks | Sim | `mxx_plans.trunks` | `assertCanCreateTrunk()` | Sim | Funciona | Nenhuma imediata |
| Call Center | Sim | `modules_json.callcenter` | `PermissionMiddleware` | Sim | Funciona | Nenhuma imediata |
| Chamadas simultaneas | Nao comprovado como item proprio | Nao ha campo dedicado claro; `voice_limit` nao esta em uso | Nao encontrei bloqueio real | Nao | Nao bloqueado | Criar campo/uso explicito para canais ou chamadas simultaneas |
| Modulo WhatsApp | Sim | `modules_json.whatsapp` | `PermissionMiddleware` | Sim | Funciona | Nenhuma imediata |
| Limite de contas WhatsApp | Sim | `mxx_plans.whatsapp_accounts` | `assertCanCreateWhatsAppAccount()` | Sim | Funciona | Nenhuma imediata |
| Templates como modulo | Sim | `modules_json.templates` | `assertCanUseFeature('templates')` | Sim | Funciona | Nenhuma imediata |
| Limite de templates | Sim | `mxx_plans.templates_limit` | Nao encontrei uso real | Exibido na tela | Nao bloqueado | Implementar contagem e bloqueio de criacao |
| Campanhas como modulo | Sim | `modules_json.campaigns` / `camp_qtd` | A feature existe no runtime, mas nao achei enforcement por quantidade | Parcial | Parcial | Separar modulo de limite numerico |
| Limite de campanhas | Sim | `camp_qtd` | Nao encontrei uso real | Exibido na tela | Nao bloqueado | Implementar contagem por tenant |
| Acessos simultaneos | Sim | `mxx_plans.simultaneous_access` | `assertCanLogin()` em `Login.php` | Nao depende de frontend | Funciona | Opcional migrar para controle por sessao dedicada |
| Tarifas SMS/Voz/WhatsApp | Sim | `mxx_plans` + `tenancy_balance` snapshot | Aplicadas para cobranca e exibicao | Sim | Funciona como tarifacao | Distinguir no UI entre tarifa e limite |

## Diagnostico por tema

### Modulos

O bloqueio por modulo hoje esta implementado e centralizado. Essa parte esta madura o suficiente para backend.

### Limites numericos

Ha uma assimetria forte:

- `simultaneous_access`, `trunks` e `whatsapp_accounts` tem enforcement real
- os demais limites numericos existem, mas nao foram ligados a fluxos de negocio

### SMS

O SMS hoje parece operar mais por `saldo + tarifa` do que por `limite de plano`.

Conclusao:

- modulo SMS: bloqueado por plano
- quantidade de SMS por plano: nao comprovada

### Voz

A voz hoje tem:

- modulo de acesso
- trunks
- webrtc
- tarifas

Mas nao encontrei prova de:

- limite de chamadas simultaneas por plano
- uso real de `voice_limit`
- limite mensal/diario de voz

### WhatsApp

WhatsApp esta melhor definido:

- modulo comercial
- limite de contas/numeros
- templates como feature separada
- tarifas por categoria

Mas ainda nao encontrei prova de:

- limite de mensagens por plano
- limite de campanhas por plano
- limite de templates por quantidade

## Causa raiz principal

O catalogo de planos evoluiu mais rapido do que o enforcement.

Hoje o sistema tem:

- uma camada de cadastro rica
- um runtime de plano organizado
- algumas features e limites realmente ligados ao negocio

Mas ainda ha varios campos de limite que sao apenas:

1. persistidos no banco
2. exibidos na tela
3. carregados no runtime
4. sem chamada real nos fluxos operacionais

## Recomendacao final

Prioridade alta:

1. separar formalmente no UI e na documentacao o que e:
   - modulo
   - feature
   - limite numerico
   - tarifa
2. implementar enforcement real para:
   - `users_limit`
   - `sms_limit`
   - `voice_limit`
   - `campaigns_limit`
   - `templates_limit`
3. decidir explicitamente o significado comercial de `voice_limit`:
   - minutos
   - chamadas simultaneas
   - canais ativos
4. decidir explicitamente o significado comercial de `sms_limit`:
   - total mensal
   - total diario
   - apenas informativo, se saldo for a unica trava

Prioridade media:

1. criar uma tabela/servico de uso consolidado por tenant para limites
2. mover limites numericos para validadores dedicados por dominio:
   - usuarios
   - sms
   - voz
   - campanhas
   - templates
3. alinhar o menu para refletir melhor a estrategia comercial, se desejar sinalizacao visual de modulo bloqueado

## Conclusao

O sistema ja prova bloqueio real para:

- modulos por plano
- acessos simultaneos
- criacao de usuarios por feature
- trunks
- contas WhatsApp
- templates como feature
- WebRTC

O sistema nao prova bloqueio real, hoje, para:

- limite total de usuarios
- limite de SMS
- limite de voz
- limite numerico de campanhas
- limite numerico de templates
- chamadas simultaneas como capacidade de voz

Se nao ha validacao no backend, nesta auditoria o item foi considerado `NAO BLOQUEADO`.
