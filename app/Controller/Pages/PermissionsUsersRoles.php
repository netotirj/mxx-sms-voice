<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Model\Entity\UserAuthentication;
use App\Model\Entity\UserSearch;
use App\Session\User as SessionUser;
use App\Model\Entity\PermissionsRules;
use App\Service\AuthContext;
use App\Service\PermissionResolver;
use App\Utils\View;

class PermissionsUsersRoles extends ViewComponents
{
    private const PROTECTED_ROLE_NAMES = ['super_admin', 'admin'];
    private const SUPERADMIN_ROUTE_PREFIXES = [
        '/permissions/global-routes',
        '/plans',
        '/support',
        'ticket.',
        '/system-updates',
        '/site-tests',
        '/reports/notifications',
    ];

    /**
     * Exibe a página principal de permissões
     */
    public static function getPermissions(): Response|string
    {
        $obUser = SessionUser::getLogged();
        $isSuperAdmin = self::isCurrentUserSuperAdmin($obUser);

        // Apenas super admin pode cadastrar rotas globais.
        $btnHidden = $isSuperAdmin ? '' : 'hidden';

        $content = View::render('/permissions/index', [
            'btnGlobalHidden' => $btnHidden
        ]);

        return parent::getComponentsUsers('Maxx Solutions | Permissões', $content);
    }

    /**
     * Busca os Papéis (Roles) disponíveis para o Tenancy
     */
    public static function getAllPermissionsUsers($request): Response
    {
        // Pega o tenancy_id da sessão
        $obUser = SessionUser::getLogged();
        $tenancyId = $obUser['tenancy_id'] ?? '';

        // Chama a Model acima
        $isSuperAdmin = self::isCurrentUserSuperAdmin($obUser);
        $roles = $isSuperAdmin
            ? PermissionsRules::getRolesGlobal()
            : PermissionsRules::getRoles($tenancyId);

        if ($isSuperAdmin) {
            $roles = array_merge(PermissionsRules::getSystemRoleTemplatesForListing(), $roles);
        }

        if (!$isSuperAdmin) {
            $roles = array_values(array_filter($roles, static function (array $role): bool {
                $name = strtolower(trim((string)($role['name'] ?? '')));
                return !in_array($name, self::PROTECTED_ROLE_NAMES, true);
            }));
        }

        // Pega o total de rotas do primeiro item do array para o contador do topo
        $totalRoutes = !empty($roles) ? ($roles[0]['total_routes_system'] ?? 0) : 0;

        // Retorna o JSON limpo
        return new Response(200, json_encode([
            'status'      => 'ok',
            'data'        => $roles,
            'totalRoutes' => (int)$totalRoutes
        ]), 'application/json');
    }

    public static function getCurrentPermissions($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, json_encode([
                'status' => 'error',
                'message' => 'Usuário não autenticado.'
            ]), 'application/json');
        }

        $context = AuthContext::current();
        $version = PermissionResolver::versionForUser($obUser);

        return new Response(200, json_encode([
            'status' => 'ok',
            'data' => [
                'role_id' => (int)($context['role_id'] ?? 0),
                'permissions' => array_values(array_unique(array_map(
                    static fn ($value): string => trim((string)$value),
                    (array)($context['permissions'] ?? [])
                ))),
                'is_super_admin' => (bool)($context['is_super_admin'] ?? false),
                'version' => $version,
            ],
        ]), 'application/json');
    }

    /**
     * Salva uma nova Rota Global (O seu modal de Textarea)
     */
    public static function saveGlobalRoute($request): Response
    {
        try {
            $obUser = SessionUser::getLogged();
            if (!self::isCurrentUserSuperAdmin($obUser)) {
                return new Response(403, json_encode([
                    'status' => 'error',
                    'message' => 'Apenas o super administrador pode cadastrar rotas globais.'
                ]), 'application/json');
            }

            $data = $request->getPostVars();
            $name  = $data['name']  ?? null; // ID interno: modulo_usuarios
            $label = $data['label'] ?? null; // Nome bonito: Gestão de Usuários
            $routesRaw = $data['routes'] ?? null;

            if (!$name || !$label || !$routesRaw) {
                return new Response(422, json_encode(['status' => 'error', 'message' => 'Campos obrigatórios faltando.']), 'application/json');
            }

            // Trata o textarea (converte lista em array)
            $routesArray = array_filter(array_map('trim', explode(',', str_replace(["\n", "\r"], ',', $routesRaw))));

            // Chama a nova Model para inserir na sys_routes
            PermissionsRules::registerGlobalRoute($name, $label, $routesArray);

            return new Response(200, json_encode([
                'status' => 'ok',
                'message' => 'Módulo e rotas cadastrados com sucesso!'
            ]), 'application/json');

        } catch (\Exception $e) {
            return new Response(500, json_encode(['status' => 'error', 'message' => $e->getMessage()]), 'application/json');
        }
    }

    public static function saveRole($request): Response
    {
        try {
            // Pega o usuário da sessão (Segurança e Tenancy)
            $obUser = SessionUser::getLogged();
            if (!$obUser) return new Response(401, json_encode(['status' => 401]), 'application/json');

            $data = $request->getPostVars();

            // Mapeamento: O formulário envia 'role_name' e 'description'
            $id     = $data['id'] ?? null; // Pega o ID do input hidden
            $name   = $data['role_name']   ?? null;
            $label  = $data['description'] ?? null; // Texto: "Permissões do Financeiro"
            $status = $data['status']     ?? 'y';
            $templateName = strtolower(trim((string)($data['template_name'] ?? '')));

            if (!$name) {
                return new Response(422, json_encode(['status' => 'error', 'message' => 'Nome obrigatório']), 'application/json');
            }

            if ($templateName !== '') {
                $template = PermissionsRules::getRoleTemplateByName($templateName);
                if (!$template) {
                    return new Response(422, json_encode(['status' => 'error', 'message' => 'Modelo do sistema não encontrado.']), 'application/json');
                }
            }

            if (!self::isCurrentUserSuperAdmin($obUser)) {
                $normalizedName = strtolower(trim((string)$name));
                if (in_array($normalizedName, self::PROTECTED_ROLE_NAMES, true)) {
                    return new Response(403, json_encode(['status' => 'error', 'message' => 'Este papel é reservado ao super administrador.']), 'application/json');
                }

                if (!empty($id) && self::isProtectedRoleId((int)$id, (string)$obUser['tenancy_id'])) {
                    return new Response(403, json_encode(['status' => 'error', 'message' => 'Você não pode editar este papel.']), 'application/json');
                }
            }

            // Garante que o status seja 'y' ou 'n' (evita gravar o número 1)
            $statusValue = ($status === 'y' || $status === 'active') ? 'y' : 'n';

            // ENVIA PARA A MODEL OS 4 PARÂMETROS
            $roleId = PermissionsRules::registerRole(
                $name,                  // Identificador (Financeiro)
                $label,                 // Nome bonito (Permissões do Financeiro)
                $statusValue,           // 'y'
                $obUser['tenancy_id'],   // UUID da sessão
                null,
                $id
            );

            if ($roleId > 0 && $templateName !== '') {
                PermissionsRules::syncRoleFromTemplateSnapshot(
                    (int)$roleId,
                    (string)$obUser['tenancy_id'],
                    $templateName
                );
            }

            return new Response(200, json_encode(['status' => 'ok', 'message' => 'Papel criado!', 'id' => $roleId]), 'application/json');

        } catch (\Exception $e) {
            return new Response(500, json_encode(['status' => 'error', 'message' => $e->getMessage()]), 'application/json');
        }
    }

    public static function getRoleTemplates($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, json_encode([
                'status' => 'error',
                'message' => 'Usuário não autenticado.'
            ]), 'application/json');
        }

        $templates = PermissionsRules::getSystemRoleTemplatesForListing();

        return new Response(200, json_encode([
            'status' => 'ok',
            'data' => $templates,
        ]), 'application/json');
    }

    public static function syncRoleFromTemplate($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, json_encode([
                'status' => 'error',
                'message' => 'Usuário não autenticado.'
            ]), 'application/json');
        }

        $postVars = $request->getPostVars();
        $roleId = (int)($postVars['role_id'] ?? 0);
        if ($roleId <= 0) {
            return new Response(422, json_encode([
                'status' => 'error',
                'message' => 'Papel inválido.'
            ]), 'application/json');
        }

        $isSuperAdmin = self::isCurrentUserSuperAdmin($obUser);
        $role = $isSuperAdmin
            ? PermissionsRules::getRoleByIdAny($roleId)
            : PermissionsRules::getRoleById($roleId, (string)$obUser['tenancy_id']);

        if (!$role) {
            return new Response(404, json_encode([
                'status' => 'error',
                'message' => 'Papel não encontrado.'
            ]), 'application/json');
        }

        $roleTenancyId = (string)($role['tenancy_id'] ?? '');
        if (!$isSuperAdmin && self::isProtectedRoleId($roleId, $roleTenancyId)) {
            return new Response(403, json_encode([
                'status' => 'error',
                'message' => 'Você não pode sincronizar este papel.'
            ]), 'application/json');
        }

        $templateId = (int)($role['template_id'] ?? 0);
        if ($templateId <= 0) {
            return new Response(422, json_encode([
                'status' => 'error',
                'message' => 'Este papel não está vinculado a um modelo do sistema.'
            ]), 'application/json');
        }

        PermissionsRules::syncRoleFromTemplateSnapshot($roleId, $roleTenancyId);

        return new Response(200, json_encode([
            'status' => 'ok',
            'message' => 'Permissões sincronizadas com o modelo do sistema.'
        ]), 'application/json');
    }

    /**
     * Busca as permissões de um papel específico (Para abrir a engrenagem)
     */
    public static function getRolePermissions($request): Response
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $roleId = (int)($data['role_id'] ?? 0);
        $obUser = SessionUser::getLogged();
        $isSuperAdmin = self::isCurrentUserSuperAdmin($obUser);

        if (!$roleId) return new Response(422, json_encode(['status' => 'error']), 'application/json');

        if ($roleId < 0) {
            if (!$isSuperAdmin) {
                return new Response(403, json_encode([
                    'status' => 'error',
                    'message' => 'Apenas o super administrador pode acessar o papel padrao do sistema.'
                ]), 'application/json');
            }

            $templateId = abs($roleId);
            $template = PermissionsRules::getRoleTemplateById($templateId);
            if (!$template) {
                return new Response(404, json_encode([
                    'status' => 'error',
                    'message' => 'Template de papel não encontrado.'
                ]), 'application/json');
            }

            return new Response(200, json_encode([
                'status' => 'ok',
                'data' => PermissionsRules::getCombinedTemplatePermissions($templateId)
            ]), 'application/json');
        }

        $role = $isSuperAdmin
            ? PermissionsRules::getRoleByIdAny($roleId)
            : PermissionsRules::getRoleById($roleId, (string)$obUser['tenancy_id']);

        if (!$role) {
            return new Response(404, json_encode([
                'status' => 'error',
                'message' => 'Papel não encontrado.'
            ]), 'application/json');
        }

        if (!$isSuperAdmin && self::isProtectedRoleId((int)$roleId, (string)$obUser['tenancy_id'])) {
            return new Response(403, json_encode([
                'status' => 'error',
                'message' => 'Você não pode visualizar permissões deste papel.'
            ]), 'application/json');
        }

        // Busca o cruzamento entre sys_routes e sys_role_permissions
        $permissions = PermissionsRules::getCombinedPermissions((int)$roleId, (string)$role['tenancy_id']);
        if (!$isSuperAdmin) {
            $permissions = array_values(array_filter($permissions, static function (array $permission) use ($obUser): bool {
                return self::canCurrentUserManageRoute($obUser, $permission);
            }));
        }

        return new Response(200, json_encode([
            'status' => 'ok',
            'data'   => $permissions
        ]), 'application/json');
    }

    /**
     * Liga/Desliga uma permissão (O Switch do Modal)
     */
    public static function togglePermission($request): string
    {
        $obUser = SessionUser::getLogged();
        $postVars = $request->getPostVars();
        $isSuperAdmin = self::isCurrentUserSuperAdmin($obUser);

        $roleId  = (int)($postVars['role_id']  ?? 0);
        $routeId = (int)($postVars['route_id'] ?? 0); // Agora usamos o ID da rota da sys_routes
        $active  = (int)($postVars['active'] ?? 0);

        if ($roleId < 0) {
            if (!$isSuperAdmin) {
                return json_encode(['status' => 'error', 'message' => 'Apenas o super administrador pode alterar o papel padrao do sistema.']);
            }

            $templateId = abs($roleId);
            $template = PermissionsRules::getRoleTemplateById($templateId);
            if (!$template) {
                return json_encode(['status' => 'error', 'message' => 'Template de papel não encontrado.']);
            }

            PermissionsRules::syncRoleTemplatePermission($templateId, $routeId, $active);
            return json_encode(['status' => 'ok', 'active' => $active, 'synced' => true, 'template' => true]);
        }

        $targetRole = $isSuperAdmin
            ? PermissionsRules::getRoleByIdAny($roleId)
            : PermissionsRules::getRoleById($roleId, (string)$obUser['tenancy_id']);

        if (!$targetRole) {
            return json_encode(['status' => 'error', 'message' => 'Papel não encontrado.']);
        }

        if (!$isSuperAdmin) {
            if (self::isProtectedRoleId($roleId, (string)$obUser['tenancy_id'])) {
                return json_encode(['status' => 'error', 'message' => 'Você não pode alterar este papel.']);
            }

            $route = PermissionsRules::getRouteById($routeId);
            if (!$route || !self::canCurrentUserManageRoute($obUser, $route)) {
                return json_encode(['status' => 'error', 'message' => 'Esta rota é reservada ao super administrador.']);
            }
        }

        // Chama a Model para inserir ou deletar na sys_role_permissions
        PermissionsRules::toggleRolePermission((string)$targetRole['tenancy_id'], $roleId, $routeId, $active);

        return json_encode(['status' => 'ok', 'active' => $active]);
    }

    public static function clearPermissions($request): string
    {
        $obUser = SessionUser::getLogged();
        $postVars = $request->getPostVars();
        $isSuperAdmin = self::isCurrentUserSuperAdmin($obUser);

        $roleId = (int)($postVars['role_id'] ?? 0);

        if ($roleId < 0) {
            if (!$isSuperAdmin) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Apenas o super administrador pode limpar o papel padrao do sistema.'
                ]);
            }

            $templateId = abs($roleId);
            $template = PermissionsRules::getRoleTemplateById($templateId);
            if (!$template) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Template de papel não encontrado.'
                ]);
            }

            PermissionsRules::clearTemplatePermissions($templateId);
            return json_encode(['status' => 'ok', 'cleared' => true, 'template' => true]);
        }

        $targetRole = $isSuperAdmin
            ? PermissionsRules::getRoleByIdAny($roleId)
            : PermissionsRules::getRoleById($roleId, (string)$obUser['tenancy_id']);

        if (!$targetRole) {
            return json_encode(['status' => 'error', 'message' => 'Papel não encontrado.']);
        }

        if (!$isSuperAdmin && self::isProtectedRoleId($roleId, (string)$obUser['tenancy_id'])) {
            return json_encode(['status' => 'error', 'message' => 'Você não pode alterar este papel.']);
        }

        PermissionsRules::clearRolePermissions((string)$targetRole['tenancy_id'], $roleId);

        return json_encode(['status' => 'ok', 'cleared' => true]);
    }

    public static function bulkTogglePermissions($request): string
    {
        $obUser = SessionUser::getLogged();
        $postVars = $request->getPostVars();
        $isSuperAdmin = self::isCurrentUserSuperAdmin($obUser);

        $roleId = (int)($postVars['role_id'] ?? 0);
        $active = (int)($postVars['active'] ?? 0);
        $routeIds = $postVars['route_ids'] ?? [];

        if (!is_array($routeIds)) {
            $routeIds = [$routeIds];
        }

        $routeIds = array_values(array_unique(array_filter(array_map('intval', $routeIds), static fn (int $id): bool => $id > 0)));
        if ($routeIds === []) {
            return json_encode(['status' => 'error', 'message' => 'Nenhuma rota válida foi informada.']);
        }

        if ($roleId < 0) {
            if (!$isSuperAdmin) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Apenas o super administrador pode alterar o papel padrao do sistema.'
                ]);
            }

            $templateId = abs($roleId);
            $template = PermissionsRules::getRoleTemplateById($templateId);
            if (!$template) {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Template de papel não encontrado.'
                ]);
            }

            PermissionsRules::bulkToggleTemplatePermissions($templateId, $routeIds, $active);
            return json_encode(['status' => 'ok', 'active' => $active, 'bulk' => true, 'template' => true]);
        }

        $targetRole = $isSuperAdmin
            ? PermissionsRules::getRoleByIdAny($roleId)
            : PermissionsRules::getRoleById($roleId, (string)$obUser['tenancy_id']);

        if (!$targetRole) {
            return json_encode(['status' => 'error', 'message' => 'Papel não encontrado.']);
        }

        if (!$isSuperAdmin) {
            if (self::isProtectedRoleId($roleId, (string)$obUser['tenancy_id'])) {
                return json_encode(['status' => 'error', 'message' => 'Você não pode alterar este papel.']);
            }

            foreach ($routeIds as $routeId) {
                $route = PermissionsRules::getRouteById($routeId);
                if (!$route || !self::canCurrentUserManageRoute($obUser, $route)) {
                    return json_encode([
                        'status' => 'error',
                        'message' => 'Uma ou mais rotas selecionadas são reservadas ao super administrador.'
                    ]);
                }
            }
        }

        PermissionsRules::bulkToggleRolePermissions((string)$targetRole['tenancy_id'], $roleId, $routeIds, $active);

        return json_encode(['status' => 'ok', 'active' => $active, 'bulk' => true]);
    }

    /**
     * Busca todos os usuários da tenancy e marca quem pertence ao papel selecionado
     */
    public static function getListUsersForRole($request): Response
    {
        $obUser = SessionUser::getLogged();
        $postVars = $request->getPostVars();
        //echo "<pre>";
        //print_r($postVars);
        //echo "</pre>";exit();

        // O ID do papel que eu quero ATRIBUIR (vindo do clique no botão verde)
        $targetRoleId = (int)($postVars['role_id'] ?? 0);
        $tenancyId = $obUser['tenancy_id'];
        $isSuperAdmin = self::isCurrentUserSuperAdmin($obUser);

        if ($targetRoleId < 0) {
            return new Response(422, json_encode([
                'status' => 'error',
                'message' => 'O papel padrao do sistema nao possui membros diretos.'
            ]), 'application/json');
        }

        $targetRole = $isSuperAdmin
            ? PermissionsRules::getRoleByIdAny($targetRoleId)
            : PermissionsRules::getRoleById($targetRoleId, (string)$tenancyId);

        if (!$targetRole) {
            return new Response(404, json_encode([
                'status' => 'error',
                'message' => 'Papel não encontrado.'
            ]), 'application/json');
        }

        $roleTenancyId = (string)($targetRole['tenancy_id'] ?? '');

        if (!$isSuperAdmin && self::isProtectedRoleId($targetRoleId, (string)$tenancyId)) {
            return new Response(403, json_encode([
                'status' => 'error',
                'message' => 'Você não pode gerenciar usuários deste papel.'
            ]), 'application/json');
        }

        // Lista os usuários da tenancy dona do papel selecionado.
        $users = UserSearch::getUsers($roleTenancyId);

        //echo "<pre>";
        //print_r($users);
        //echo "</pre>";exit();


        $data = [];
        foreach ($users as $user) {
            $data[] = [
                'id'    => $user['id'],
                'name'  => $user['name'] . ' ' . $user['last_name'],
                'email' => $user['email'],
                // Se o role_id que o usuário tem no banco for igual ao que abrimos no modal
                'belongs_to_role' => ((int)$user['role_id'] === $targetRoleId)
            ];
        }

        return new Response(200, json_encode([
            'status' => 'ok',
            'users'  => $data
        ]), 'application/json');
    }

    /**
     * Atribui ou remove o papel de um usuário
     */
    public static function setAssignUserRole($request): Response
    {
        $obUser    = SessionUser::getLogged();
        $postVars  = $request->getPostVars();
        $tenancyId = $obUser['tenancy_id'];
        $isSuperAdmin = self::isCurrentUserSuperAdmin($obUser);

        $userId = (int)($postVars['user_id'] ?? 0);
        $roleId = (int)($postVars['role_id'] ?? 0);
        $active = (int)($postVars['active'] ?? 0);

        if ($roleId < 0) {
            return new Response(422, json_encode([
                'status' => 'error',
                'message' => 'Nao e possivel vincular usuarios diretamente ao papel padrao do sistema.'
            ]), 'application/json');
        }

        // Evita o "tiro no pé": Não deixa o admin mudar o próprio papel
        if ($userId === (int)$obUser['id']) {
            return new Response(400, json_encode(['status' => 'error', 'message' => 'Não é possível alterar seu próprio perfil.']), 'application/json');
        }

        if (!$isSuperAdmin && self::isProtectedRoleId($roleId, (string)$tenancyId)) {
            return new Response(403, json_encode(['status' => 'error', 'message' => 'Este papel é reservado ao super administrador.']), 'application/json');
        }

        $targetUser = $isSuperAdmin
            ? UserSearch::getUserByIdGlobal($userId)
            : UserSearch::getUserById((string)$tenancyId, $userId);

        if (!$targetUser) {
            return new Response(404, json_encode(['status' => 'error', 'message' => 'Usuário não encontrado.']), 'application/json');
        }

        $targetTenancyId = (string)($targetUser['tenancy_id'] ?? '');
        if ($targetTenancyId === '') {
            return new Response(422, json_encode(['status' => 'error', 'message' => 'Tenancy do usuário não identificada.']), 'application/json');
        }

        if ($active === 1) {
            $targetRole = $isSuperAdmin
                ? PermissionsRules::getRoleByIdAny($roleId)
                : PermissionsRules::getRoleById($roleId, $targetTenancyId);

            if (!$targetRole) {
                return new Response(404, json_encode(['status' => 'error', 'message' => 'Papel não encontrado.']), 'application/json');
            }

            if ((string)($targetRole['tenancy_id'] ?? '') !== $targetTenancyId) {
                return new Response(422, json_encode([
                    'status' => 'error',
                    'message' => 'Esse papel pertence a outro cliente e não pode ser vinculado a este usuário.'
                ]), 'application/json');
            }
        }

        $targetRole = ($active === 1) ? $roleId : 0;

        // 1. Atualiza o papel na tabela principal
        $success = UserSearch::updateRoleUser($userId, $targetTenancyId, $targetRole);

        if ($success) {
            // 2. Sincroniza a tabela intermediária (Limpando o que era antigo)
            PermissionsRules::assignRoleToUser($userId, $targetTenancyId, $targetRole);

            // 3. CHAVE DE OURO: Força o deslogue invalidando o token no banco
            // Sem SQL aqui, apenas chamando a Model
            UserAuthentication::invalidateUserSession($userId, $targetTenancyId);

            return new Response(200, json_encode(['status' => 'ok']), 'application/json');
        }

        return new Response(500, json_encode(['status' => 'error', 'message' => 'Falha na atualização']), 'application/json');
    }

    private static function isCurrentUserSuperAdmin(?array $user): bool
    {
        $function = strtolower(trim((string)($user['user_function'] ?? $user['function'] ?? '')));
        return $function === 'super_admin';
    }

    private static function isProtectedRoleId(int $roleId, string $tenancyId): bool
    {
        if ($roleId <= 0 || $tenancyId === '') {
            return false;
        }

        $role = PermissionsRules::getRoleById($roleId, $tenancyId);
        $name = strtolower(trim((string)($role['name'] ?? '')));

        return in_array($name, self::PROTECTED_ROLE_NAMES, true);
    }

    private static function isProtectedRoutePath(string $routePath): bool
    {
        $normalizedRoutePath = str_starts_with($routePath, 'ticket.')
            ? trim($routePath)
            : '/' . trim($routePath, '/');

        foreach (self::SUPERADMIN_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($prefix, 'ticket.')) {
                $prefix = trim($prefix);
                if ($normalizedRoutePath === $prefix || str_starts_with($normalizedRoutePath, $prefix)) {
                    return true;
                }
                continue;
            }

            $prefix = '/' . trim($prefix, '/');
            if ($normalizedRoutePath === $prefix || str_starts_with($normalizedRoutePath, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function canCurrentUserManageRoute(?array $user, array $route): bool
    {
        if (self::isCurrentUserSuperAdmin($user)) {
            return true;
        }

        $assignableBy = strtolower(trim((string)($route['assignable_by'] ?? 'admin')));
        $accessScope = strtolower(trim((string)($route['access_scope'] ?? 'tenant')));
        $routePath = (string)($route['name'] ?? $route['route_path'] ?? '');

        if ($assignableBy !== 'admin') {
            return false;
        }

        if ($accessScope !== 'tenant') {
            return false;
        }

        return !self::isProtectedRoutePath($routePath);
    }
}
