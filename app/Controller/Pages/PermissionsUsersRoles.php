<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Model\Entity\UserAuthentication;
use App\Model\Entity\UserSearch;
use App\Session\User as SessionUser;
use App\Model\Entity\PermissionsRules;
use App\Utils\View;

class PermissionsUsersRoles extends ViewComponents
{
    /**
     * Exibe a página principal de permissões
     */
    public static function getPermissions(): Response|string
    {
        $obUser = SessionUser::getLogged();
        $userFunction = $obUser['function'] ?? 'user';

        // Só quem for super_admin ou admin vê o botão de criar rotas globais
        $btnHidden = ($userFunction === 'super_admin' || $userFunction === 'admin') ? '' : 'hidden';

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
        $roles = PermissionsRules::getRoles($tenancyId);

        // Pega o total de rotas do primeiro item do array para o contador do topo
        $totalRoutes = !empty($roles) ? ($roles[0]['total_routes_system'] ?? 0) : 0;

        // Retorna o JSON limpo
        return new Response(200, json_encode([
            'status'      => 'ok',
            'data'        => $roles,
            'totalRoutes' => (int)$totalRoutes
        ]), 'application/json');
    }

    /**
     * Salva uma nova Rota Global (O seu modal de Textarea)
     */
    public static function saveGlobalRoute($request): Response
    {
        try {
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

            if (!$name) {
                return new Response(422, json_encode(['status' => 'error', 'message' => 'Nome obrigatório']), 'application/json');
            }

            // Garante que o status seja 'y' ou 'n' (evita gravar o número 1)
            $statusValue = ($status === 'y' || $status === 'active') ? 'y' : 'n';

            // ENVIA PARA A MODEL OS 4 PARÂMETROS
            $roleId = PermissionsRules::registerRole(
                $name,                  // Identificador (Financeiro)
                $label,                 // Nome bonito (Permissões do Financeiro)
                $statusValue,           // 'y'
                $obUser['tenancy_id'],   // UUID da sessão
                $id
            );

            return new Response(200, json_encode(['status' => 'ok', 'message' => 'Papel criado!', 'id' => $roleId]), 'application/json');

        } catch (\Exception $e) {
            return new Response(500, json_encode(['status' => 'error', 'message' => $e->getMessage()]), 'application/json');
        }
    }

    /**
     * Busca as permissões de um papel específico (Para abrir a engrenagem)
     */
    public static function getRolePermissions($request): Response
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $roleId = $data['role_id'] ?? null;
        $obUser = SessionUser::getLogged();

        if (!$roleId) return new Response(422, json_encode(['status' => 'error']), 'application/json');

        // Busca o cruzamento entre sys_routes e sys_role_permissions
        $permissions = PermissionsRules::getCombinedPermissions((int)$roleId, $obUser['tenancy_id']);

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

        $roleId  = $postVars['role_id']  ?? null;
        $routeId = $postVars['route_id'] ?? null; // Agora usamos o ID da rota da sys_routes
        $active  = (int)($postVars['active'] ?? 0);

        // Chama a Model para inserir ou deletar na sys_role_permissions
        PermissionsRules::toggleRolePermission($obUser['tenancy_id'], (int)$roleId, (int)$routeId, $active);

        return json_encode(['status' => 'ok', 'active' => $active]);
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

        // Pega os usuários usando seu método existente (agora com role_id no SELECT)
        $users = UserSearch::getUsers($tenancyId);

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

        $userId = (int)($postVars['user_id'] ?? 0);
        $roleId = (int)($postVars['role_id'] ?? 0);
        $active = (int)($postVars['active'] ?? 0);

        // Evita o "tiro no pé": Não deixa o admin mudar o próprio papel
        if ($userId === (int)$obUser['id']) {
            return new Response(400, json_encode(['status' => 'error', 'message' => 'Não é possível alterar seu próprio perfil.']), 'application/json');
        }

        $targetRole = ($active === 1) ? $roleId : 0;

        // 1. Atualiza o papel na tabela principal
        $success = UserSearch::updateRoleUser($userId, $tenancyId, $targetRole);

        if ($success) {
            // 2. Sincroniza a tabela intermediária (Limpando o que era antigo)
            PermissionsRules::assignRoleToUser($userId, $tenancyId, $targetRole);

            // 3. CHAVE DE OURO: Força o deslogue invalidando o token no banco
            // Sem SQL aqui, apenas chamando a Model
            UserAuthentication::invalidateUserSession($userId, $tenancyId);

            return new Response(200, json_encode(['status' => 'ok']), 'application/json');
        }

        return new Response(500, json_encode(['status' => 'error', 'message' => 'Falha na atualização']), 'application/json');
    }
}