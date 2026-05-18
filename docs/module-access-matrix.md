# Module Access Matrix

Fonte oficial atual para bloqueio comercial por plano:

- arquivo: `app/Service/ModuleAccessMap.php`
- fluxo de bloqueio:
  1. autenticacao
  2. permissao de rota
  3. modulo/feature liberado no plano

## Modulos mapeados

- `administrative`
- `users`
- `permissions`
- `rates`
- `reports`
- `sms`
- `voice`
- `callcenter`
- `whatsapp`
- `dashboard`

## Regras

- `super_admin` possui bypass explicito no middleware.
- usuarios de tenant precisam passar em ACL e plano.
- menu/sidebar deve refletir ACL e plano.
- endpoints web/AJAX/JSON devem validar ACL e plano no backend.

## Observacoes do banco

- O banco atual nao possui tabela separada de `modules`, `plan_modules` ou `module_permissions`.
- O vinculo comercial real esta concentrado em `mxx_plans.modules_json`, com runtime resolvido por `PlanRuntimeService`.
- O catalogo de rotas/ACL esta concentrado em `sys_routes`, `sys_role_permissions`, `sys_role_templates` e `sys_role_template_permissions`.

## Divergencia historica encontrada

- o menu estava validando apenas ACL e ignorando plano em `ViewComponents::hasPlanFeature()`
- o backend validava plano no middleware, mas o mapa de rotas por feature estava disperso
- rotas novas administrativas, como `global-costs` e `admin/platform-consumption`, precisavam entrar no mapa oficial
