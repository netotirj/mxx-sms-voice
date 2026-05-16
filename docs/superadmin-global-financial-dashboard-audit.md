# Auditoria do Dashboard Financeiro Global do Superadmin

Data: 2026-05-16

## Estrutura já existente reaproveitada

- Dashboard operacional existente:
  - `app/Controller/Pages/Dashboard.php`
  - `app/Service/DashboardService.php`
  - `resources/view/dashboard/index.html`
  - SSE em `/dashboard/cards` e `/dashboard/charts`
- Ledger financeiro:
  - `app/Service/FinancialTransactionService.php`
  - tabela `financial_transaction_ledger`
- Cadeia financeira/hierarquia:
  - `app/Service/FinancialHierarchyBillingService.php`
  - `app/Service/FinancialHierarchyResolver.php`
  - tabela `financial_hierarchy_logs`
- Relatórios por produto já existentes:
  - SMS: `app/Controller/Pages/Reports.php`, `app/Model/Entity/CallbackSms.php`
  - Voz: `app/Controller/Pages/Reports.php`, `app/Model/Entity/CdrVoice.php`
  - WhatsApp: `app/Controller/Pages/Reports.php`, `app/Service/WhatsAppBilling.php`, `app/Service/WhatsAppVoiceBilling.php`
- Custos globais oficiais:
  - `app/Service/PlatformGlobalCostService.php`
  - `app/Controller/Pages/GlobalCosts.php`
  - `resources/view/global-costs/index.html`

## O que estava pronto antes desta etapa

- Billing real com débito confirmado no ledger.
- Hierarquia financeira entre admin, reseller e cliente.
- Relatórios individuais de SMS, Voz, WhatsApp Mensagem e WhatsApp Voz.
- Dashboard operacional com cards, gráficos e SSE por perfil.
- Exposição de `cost_brl` em relatórios internos do WhatsApp.

## O que estava parcial

- O dashboard atual consolida consumo e operação, mas não padroniza `receita`, `custo` e `lucro` globais em uma área exclusiva do `super_admin`.
- Custos globais já existiam como conceito oficial novo, porém não havia tela consolidada de consumo financeiro cruzando tudo.
- Voz e SMS tinham custos e contagens em lugares diferentes: parte em CDR/callback, parte no ledger, parte em controller.

## O que faltava

- Rota exclusiva do superadmin para visão financeira global.
- Filtros únicos por período, tenant, produto e status.
- Resumo consolidado de:
  - receita total;
  - custo total;
  - lucro total;
  - consumo total por SMS, Voz, WhatsApp e WhatsApp Voz;
  - ranking por tenant;
  - falhas recentes cruzadas.
- Padrão explícito para usar:
  - ledger como base de receita confirmada;
  - CDR/callback/message_cdr/call_cdr como base operacional e de custo unitário.

## Riscos encontrados na auditoria

- `Dashboard.php` já somava operação e custo em consultas diferentes, o que aumenta risco de divergência entre card e relatório.
- Voz exige agregação por `call_id` ou `channel_id` para não duplicar pernas.
- SMS depende de status final do callback e do fechamento do lote para conciliação.
- WhatsApp mistura:
  - confirmação financeira via `billed`;
  - custo interno via `cost_brl`/`base_cost`;
  - status operacional via webhooks.

## Decisão arquitetural aplicada

- Não foi criada nova engine de billing.
- Não foi criada nova tabela para o dashboard global.
- O dashboard novo é somente camada de leitura e consolidação.
- Receita confirmada usa prioritariamente o `financial_transaction_ledger`.
- Custo usa:
  - metadata reconciliada do ledger para SMS e Voz;
  - `whatsapp_message_cdr.cost_brl` para WhatsApp Mensagem;
  - `whatsapp_call_cdr.base_cost` para WhatsApp Voz.
- Contagens operacionais continuam vindo das tabelas de origem:
  - `callback`
  - `cdr`
  - `whatsapp_message_cdr`
  - `whatsapp_call_cdr`

## Implementação desta etapa

- Novo service:
  - `app/Service/PlatformConsumptionDashboardService.php`
- Novo controller:
  - `app/Controller/Pages/PlatformConsumptionDashboard.php`
- Nova view:
  - `resources/view/platform-consumption/index.html`
- Novas rotas:
  - `routes/dash/platform-consumption.php`
  - `/admin/platform-consumption`
  - `/admin/platform-consumption/data`
- Integração no menu:
  - item `Consumo Global` em `app/Controller/Pages/ViewComponents.php`

## Padrão financeiro adotado no dashboard

- Receita:
  - ledger confirmado;
  - apenas pernas de varejo/faturamento final;
  - sem dupla contagem de upstream interno.
- Custo:
  - custo upstream/plataforma;
  - sem confundir com preço final cobrado.
- Lucro:
  - `receita confirmada - custo upstream reconciliado`.
- Consumo:
  - volume operacional por produto.

## Limitações conhecidas

- SMS e Voz legados ainda têm parte do custo histórico guardado em campos operacionais, então o dashboard usa reconciliação híbrida controlada.
- ACD/ASR são consolidados de forma segura por chamada lógica, mas transferências muito fora do padrão ainda dependem da qualidade do `call_id`.
- O novo dashboard não usa SSE para evitar carga global contínua em toda a plataforma.

## Próximas melhorias sugeridas

- Materializar visões agregadas por dia para reduzir custo de leitura em bases muito grandes.
- Criar reconciliação automática entre ledger e produto por tenant/dia.
- Expor margem por produto e por tenant em CSV/exportação.
- Incluir alertas de desvio:
  - receita sem custo;
  - custo sem ledger;
  - billed sem operação financeira;
  - operação financeira sem CDR/callback correspondente.
