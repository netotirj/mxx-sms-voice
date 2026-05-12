# Relatório Técnico: WhatsApp Coexistence (CoEx)

Data: 2026-05-12

## Resumo executivo

Objetivo atendido parcialmente no backend:

- O sistema foi preparado para receber eventos de Coexistência no webhook oficial `/painel/webhooks/meta/whatsapp`.
- Mensagens do cliente, mensagens enviadas pela API e mensagens enviadas manualmente pelo WhatsApp Business App agora podem ser distinguidas por origem.
- Foi adicionada uma trava para bloquear o fluxo tradicional de registro/migração da Cloud API por padrão.

Objetivo ainda pendente fora do código:

- Confirmar no painel da Meta se esta WABA/número específico está elegível para CoEx.
- Executar o onboarding correto de Coexistência no Embedded Signup ou no fluxo equivalente do parceiro/Meta.

## 1. O que foi possível confirmar

### 1.1 CoEx existe e usa onboarding diferente do fluxo tradicional

Documentação observada:

- Meta onboarding reference:
  - https://developers.facebook.com/docs/whatsapp/cloud-api/guides/set-up-cloud-api-with-whatsapp-business-app
- 360dialog Coexistence onboarding:
  - https://docs.360dialog.com/docs/hub/embedded-signup/coexistence-onboarding
- 360dialog Coexistence overview:
  - https://docs.360dialog.com/docs/resources/phone-numbers/coexistence
- 360dialog Coexistence webhooks:
  - https://docs.360dialog.com/docs/messaging/webhook/webhook-events-and-notifications

Conclusão:

- O fluxo correto não é o “Adicionar telefone” tradicional da Cloud API.
- O fluxo correto passa por conectar um número já ativo no WhatsApp Business App.
- Durante esse onboarding aparece a etapa equivalente a:
  - `Connect a WhatsApp Business App`
  - `Connect your existing WhatsApp Business App`
  - `Continue using WhatsApp Business App with the platform`
- O fluxo usa QR Code e exige o scan no celular onde o WhatsApp Business App já está ativo.

### 1.2 O webhook da CoEx usa eventos extras

Eventos identificados na documentação:

- `history`
- `smb_app_state_sync`
- `smb_message_echoes`

Interpretação prática:

- `messages`: mensagem recebida do cliente.
- `statuses`: status de mensagens enviadas pela API.
- `smb_message_echoes`: mensagens enviadas manualmente pelo app do celular, espelhadas para o webhook.
- `history`: histórico compartilhado no onboarding.
- `smb_app_state_sync`: sincronização de contatos/estado do app.

### 1.3 Histórico de até 6 meses

Importante:

- Não encontrei uma página oficial da Meta diretamente acessível aqui afirmando em texto corrido “até 6 meses”.
- Essa capacidade aparece repetidamente na documentação de BSPs e CRMs que implementam CoEx.

Conclusão técnica:

- Tratar “até 6 meses” como altamente provável, mas validar no onboarding real da conta antes de produção.
- O backend foi preparado para registrar o evento `history`, mas não importa automaticamente esse histórico para a base neste momento.

## 2. O que não foi possível confirmar daqui

Não foi possível confirmar a elegibilidade real da conta/WABA atual porque isso depende de acesso autenticado ao ambiente Meta e do fluxo de onboarding da conta específica.

Status atual:

- `Não confirmado no ambiente Meta`

Como confirmar com segurança:

1. Entrar no Embedded Signup ou fluxo equivalente do parceiro oficial.
2. Informar o mesmo número já ativo no WhatsApp Business App.
3. Verificar se aparece a opção de conectar app existente, e não apenas registrar telefone na API.
4. Confirmar se o fluxo gera QR Code para pareamento.
5. Confirmar se aparece a opção de compartilhar histórico/contatos.
6. Se o fluxo cair direto em `Add phone number` ou `register`, interromper imediatamente.

## 3. Onde o fluxo correto deve aparecer

O local exato varia conforme a entrada:

- Embedded Signup da Meta
- onboarding do BSP/parceiro oficial
- fluxo iniciado a partir do número no hub do parceiro

Pontos observados na documentação:

- Primeiro selecionar que o número não está conectado à API.
- Em seguida selecionar que o número já está conectado ao WhatsApp Business App.
- Depois escolher a opção de conectar o app existente.

Sinal de fluxo correto:

- Reentrada do número
- instrução para abrir o WhatsApp Business App
- mensagem dentro do app pedindo scan do QR Code

Sinal de fluxo perigoso:

- solicitação de `request_code`, `verify_code`, `register`, `pin` ou `add phone number` como caminho principal para este número

## 4. Mudanças implementadas no sistema

### 4.1 Webhook preparado para CoEx

Arquivo principal:

- `app/Controller/Pages/WhatsApp.php`

Mudanças:

- Aceita eventos `smb_message_echoes` no mesmo webhook.
- Aceita payload standalone de `history`.
- Aceita payload standalone de `smb_app_state_sync`.
- Cria logs dedicados de CoEx em `C:/wamp64/logs/meta_whatsapp_coex.log`.

### 4.2 Distinção de origem das mensagens

Arquivo principal:

- `app/Model/Entity/WhatsAppConversation.php`

Mensagens agora podem carregar metadados adicionais:

- `source`
- `origin`
- `phone_number_id`
- `waba_id`
- `is_from_api`
- `is_from_app`

Conversas agora podem carregar:

- `phone_number_id`
- `waba_id`
- `last_source`
- `last_origin`

Mapeamento implementado:

- mensagem recebida do cliente:
  - `direction = inbound`
  - `source = whatsapp`
  - `origin = customer`
- mensagem enviada pela API:
  - `direction = outbound`
  - `source = cloud_api`
  - `origin = api`
  - `is_from_api = 1`
- mensagem enviada manualmente no app:
  - `direction = outbound`
  - `source = whatsapp_business_app`
  - `origin = human_agent`
  - `is_from_app = 1`

### 4.3 Proteção contra duplicidade

Já existia e foi mantido:

- deduplicação por `wamid`

Resultado:

- se a Meta reenviar o mesmo evento, a mensagem não é reinserida indevidamente.

### 4.4 Proteção contra automação indevida

Implementação:

- mensagens do tipo `smb_message_echoes` são gravadas como `outbound`
- elas não passam pelo fluxo de atendimento automático de inbound do cliente

Resultado:

- resposta manual do atendente pelo celular aparece no painel
- mas não dispara reprocessamento como se fosse uma nova mensagem do cliente

### 4.5 Trava contra migração tradicional

Arquivos:

- `app/Config/WhatsAppConfig.php`
- `app/Service/WhatsAppNumberManager.php`
- `.env.example`

Nova flag:

- `WHATSAPP_ALLOW_TRADITIONAL_REGISTRATION=false`

Comportamento:

- por padrão, operações de fluxo tradicional da Cloud API ficam bloqueadas:
  - solicitar código
  - confirmar código
  - registrar número
  - desregistrar número

Uso recomendado:

- manter `false` para este número de suporte
- só liberar explicitamente se a empresa decidir fazer onboarding tradicional para outro número

## 5. Situação do webhook atual

Rota atual:

- `/painel/webhooks/meta/whatsapp`

Conclusão:

- antes: preparado para `messages`, `statuses`, `calls` e eventos de qualidade
- agora: preparado também para CoEx

Limitação atual:

- o evento `history` é logado, mas não é importado automaticamente para a base de mensagens
- isso foi intencional para evitar carga incorreta ou duplicação de histórico no primeiro rollout

## 6. Situação do banco de dados

Migration criada:

- `database/sql/20260512_whatsapp_coexistence_support.sql`

Além disso:

- o código também tenta autocriar as colunas novas em runtime se elas ainda não existirem

## 7. Testes recomendados antes de produção

### Testes de onboarding Meta

1. Confirmar que o fluxo é CoEx e não `Add phone number`.
2. Confirmar geração de QR Code.
3. Confirmar mensagem dentro do WhatsApp Business App pedindo scan.
4. Confirmar opção de compartilhar histórico/contatos.

### Testes funcionais do sistema

1. Enviar template pela API com o mesmo número e verificar:
   - envio bem-sucedido
   - `wamid` salvo
   - status `sent/delivered/read`
2. Responder manualmente pelo celular e verificar:
   - chegada via `smb_message_echoes`
   - mensagem aparece no painel como `outbound`
   - não dispara automação de inbound
3. Cliente responder no WhatsApp e verificar:
   - chegada via `messages`
   - mensagem aparece como `inbound`
   - conversa atualiza fila/unread normalmente
4. Validar duplicidade:
   - reenviar payload do mesmo `wamid`
   - confirmar que a base não cria nova linha
5. Validar logs:
   - `meta_whatsapp_webhook_dump.log`
   - `meta_whatsapp_coex.log`
6. Validar webhook `history`:
   - confirmar recebimento e registro em log
   - decidir depois se haverá importação real para o painel

## 8. Limitações e pontos de atenção

Com base na documentação disponível e no comportamento esperado:

- templates continuam sendo enviados pela API, não pelo app
- regra da janela de 24h continua valendo para mensagens de serviço enviadas pela API
- mensagens enviadas pelo app continuam livres no app, mas não devem ser cobradas/contabilizadas como API
- grupos, alguns tipos especiais de mensagem e recursos do app podem não sincronizar para a API
- histórico antigo além do período suportado pode não migrar
- dispositivos vinculados/companion devices podem sofrer restrições após onboarding
- não desinstalar o WhatsApp Business App após ativação

## 9. Passo a passo seguro para produção

1. Aplicar a migration de CoEx.
2. Validar que o webhook publicado é `/painel/webhooks/meta/whatsapp`.
3. Confirmar que `WHATSAPP_ALLOW_TRADITIONAL_REGISTRATION=false`.
4. Fazer backup lógico do banco antes da ativação.
5. Executar o onboarding somente no fluxo de Coexistência.
6. Parar imediatamente se o fluxo pedir registro tradicional do número.
7. Após a ativação, enviar:
   - 1 template pela API
   - 1 resposta manual no celular
   - 1 resposta do cliente
8. Conferir:
   - painel
   - deduplicação por `wamid`
   - logs CoEx
   - status das mensagens
9. Só depois liberar automações mais sensíveis.

## 10. Conclusão final

Conclusão objetiva:

- O sistema agora está preparado para operar com CoEx no backend e tratar corretamente mensagens vindas do cliente, da API e do WhatsApp Business App.
- A elegibilidade real da conta Meta para CoEx ainda precisa ser confirmada no onboarding autenticado da própria conta.
- O fluxo seguro para este número é o de conectar o WhatsApp Business App existente com QR Code.
- O fluxo tradicional de registro da Cloud API foi bloqueado por padrão para reduzir risco operacional.
