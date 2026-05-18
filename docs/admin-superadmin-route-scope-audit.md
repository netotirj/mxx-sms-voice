# Auditoria de Rotas Globais do Administrador x Superadministrador

## Causa raiz

- A tela de permissões carregava a matriz de rotas a partir de `sys_routes` inteiro em `PermissionsRules::getCombinedPermissions()`, e o papel `admin` estava explicitamente isento do filtro-base por escopo.
- A listagem de papéis para admin usava `COUNT(*) FROM sys_routes` em `PermissionsRules::getRoles()`, então o contador mostrava o universo inteiro de rotas do banco, não apenas as rotas administráveis por tenant/admin.
- O backend principal de ACL validava a rota apenas contra `sys_role_permissions`, sem aplicar a governança de `sys_routes.access_scope` e `sys_routes.assignable_by`. Com isso, um vínculo legado incorreto ainda podia vazar rota de plataforma para admin.

## Query antiga problemática

Consulta antiga da matriz de rotas:

```sql
SELECT sr.id, sr.module_name, sr.route_path, IF(srp.id IS NULL, 0, 1)
FROM sys_routes sr
LEFT JOIN sys_role_permissions srp
  ON srp.route_id = sr.id
 AND srp.role_id = :role_id
 AND srp.tenancy_id = :tenancy_id
ORDER BY sr.module_name, sr.route_path
```

Sem filtro por escopo, a query sempre partia de todas as rotas catalogadas.

## Regra nova aplicada

- `super_admin` continua vendo a matriz completa.
- `admin` e demais papéis de tenant ficam limitados ao baseline administrável:
  - `access_scope = 'tenant'`
  - `assignable_by = 'admin'`
- O backend agora aplica a mesma governança antes de aceitar a ACL da rota.
- O cache do contexto do usuário já passa a armazenar apenas rotas governadas para o papel dele.

## Rotas marcadas como superadmin_only

- `/plans`
- `/global-costs`
- `/admin/platform-consumption`
- `/admin/services-monitor`
- `/site-tests`
- `/reports/notifications`
- `/permissions/global-routes`
- prefixo `ticket.`

## Rotas administráveis pelo admin

- Todas as rotas com `access_scope = 'tenant'` e `assignable_by = 'admin'`.
- Exemplos esperados no escopo tenant: dashboard, usuários, notificações locais (`/notifications`), suporte do tenant, configurações permitidas, módulos operacionais do tenant.

## Arquivos alterados

- `app/Model/Entity/PermissionsRules.php`
- `app/Controller/Pages/PermissionsUsersRoles.php`
- `app/Service/PermissionResolver.php`
- `app/Service/SystemSchemaMaintenance.php`
- `database/sql/20260518_admin_superadmin_route_scope_sync.sql`

## Tabelas impactadas

- `sys_routes`
- `sys_role_permissions`
- `sys_role_template_permissions`
- `sys_roles`
- `user_roles`

## Observações de validação

- A correção separa banco, query, tela, cache e backend.
- Não foi possível medir a quantidade real antes/depois por perfil diretamente no MySQL deste ambiente porque a conexão local recusou acesso.
- A validação de sintaxe dos arquivos PHP alterados passou com sucesso.
