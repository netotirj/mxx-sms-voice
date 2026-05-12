<?php

namespace App\Controller\Pages;


use App\Http\Response;
use App\Model\Entity\AddressSearch;
use App\Model\Entity\RegisterTenancies;
use App\Session\User as SessionUser;
use App\Utils\TenancyHelper;
use App\Utils\View;
use \App\Model\Entity\UserSearch;
use \App\Model\Entity\PermissionsRules;
use App\Service\PlanAccessPolicy;
use DateTime;
use Exception;
use Random\RandomException;
use WilliamCosta\DatabaseManager\Database;

class Users extends ViewComponents
{
    public static function getUsers($request): array|bool|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/users/index', []);
        return parent::getComponentsUsers('Maxx Solutions - SMS | Users', $content);
    }

    public static function getUsersProfile($request): array|bool|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $u = UserSearch::getUserById($obUser['tenancy_id'], (int)$obUser['id']) ?? [];

        // Dados básicos
        $name     = $u['name'] ?? '';
        $lastname = $u['last_name'] ?? '';
        $email    = $u['email'] ?? '';
        $user     = $u['email'] ?? ''; // Ou login, se tiver
        $function = $u['user_function_translated'] ?? '';

        // Dados de endereço isolados (Para limpar as chaves {{number}}, {{neighborhood}}, etc)
        $street       = $u['address_street'] ?? '';
        $number       = $u['address_number'] ?? '';
        $neighborhood = $u['address_neighborhood'] ?? '';
        $complement   = $u['address_complement'] ?? '';
        $city         = $u['address_city'] ?? '';
        $state        = $u['address_state'] ?? '';
        $zipcode      = $u['address_zipcode'] ?? '';

        // Monta o endereço completo apenas se você ainda usar a variável {{address}}
        $parts = array_filter([$street, $number, $complement, $neighborhood]);
        $fullAddress = implode(', ', $parts);

        $userAvatar = ($u['image'] ?? '') ? URL . '/resources/assets/img/' . $u['image'] : URL . '/resources/assets/img/default-avatar.png';

        return parent::getComponentsUsers('Maxx Solutions | Users', View::render('/users/profile', [
            'id'         => $obUser['id'],
            'name'       => $name,
            'lastname'   => $lastname,
            'email'      => $email,
            'userAvatar' => $userAvatar,
            'user'       => $user,
            'function'   => $function,
            'address'    => $fullAddress, // Rua, Número, etc concatenado
            'street'     => $street,      // {{street}}
            'number'     => $number,      // {{number}} - ISSO LIMPA AS CHAVES NO FRONT
            'neighborhood' => $neighborhood,
            'city'       => $city,
            'state'      => $state,
            'zipcode'    => $zipcode
        ]));
    }

    public static function getNewUsers($request): string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        if (!self::canManageUsersByPlan($obUser)) {
            return new Response(403, [
                'status' => 403,
                'message' => 'Seu plano atual não permite criar usuários adicionais.'
            ], 'application/json');
        }

        $usersNew = UserSearch::getUsers($obUser['tenancy_id']);

        $tenancyName  = $usersNew[0]['tenancy_name'] ?? '';
        $tenancyPhone = $usersNew[0]['tenancy_phone'] ?? '';



        $content = View::render('/users/new', [
            'tenancy_name'  => $tenancyName,
            'tenancy_phone' => $tenancyPhone
        ]);
        return parent::getComponentsUsers('Maxx Solutions | Campaign', $content);
    }


    /**
     * @throws Exception
     */
    public static function getAllUsers($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status'  => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }


        $role = strtolower((string)($obUser['user_function'] ?? $obUser['function'] ?? ''));
        $canEditBalance = in_array($role, ['super_admin', 'admin'], true);

        // --- LÓGICA DE FILTRAGEM REFINADA ---
        if (TenancyHelper::isSuperAdmin($obUser)) {
            // Vê absolutamente todos os usuários do sistema (Global)
            $users = UserSearch::getAllUsersGlobal();

        } elseif ($role === 'admin') {
            // Vê todos os usuários da empresa (Tenancy) dele
            $users = UserSearch::getUsers($obUser['tenancy_id']);

        } elseif ($role === 'reseller') {
            // ✅ Revendedor: Passamos o ID dele para a Model filtrar (Ele + Criados por ele)
            $users = UserSearch::getUsers($obUser['tenancy_id'], (int)$obUser['id']);

        } else {
            // ✅ Outros (agente, operador, etc):
            // Agora passamos o ID dele para que ele veja APENAS o próprio perfil
            $users = UserSearch::getUsers($obUser['tenancy_id'], (int)$obUser['id']);
        }

        $formattedUsers = [];
        $now = new DateTime();

        foreach ($users as $user) {
            $status = 'Offline';
            if (!empty($user['last_activity'])) {
                $lastActivity = new DateTime($user['last_activity']);
                $interval = $now->getTimestamp() - $lastActivity->getTimestamp();
                if ($interval <= 300) $status = 'Online';
            }

            $userAvatar = $user['image']
                ? URL . '/resources/assets/img/' . $user['image']
                : URL . '/resources/assets/img/default-avatar.png';

            $listedRole = strtolower((string)($user['user_function'] ?? ''));
            $balance = in_array($listedRole, ['admin', 'super_admin'], true)
                ? ($user['admin_balance'] ?? '0.0000')
                : ($user['reseller_balance'] ?? '0.0000');

            $formattedUsers[] = [
                'id'             => $user['id'],
                'user_id'        => $user['user_id'],
                'avatar'         => $userAvatar,
                'nome'           => $user['name'],
                'last_name'      => $user['last_name'],
                'email'          => $user['email'],

                // 🔐 mantenha o valor real para regras se precisar no front
                'user_function'  => $user['user_function'],

                // 🎨 texto traduzido para UI
                'funcao'         => $user['user_function_translated'] ?? $user['user_function'],

                'cargo'          => $user['job_title_translated'] ?? $user['job_title'],
                'status_account' => $user['status_account'],
                'status'         => $status,

                'balance'        => $balance,

                'created'        => (new DateTime($user['createdAt']))->format('d/m/Y H:i'),
            ];
        }



        return new Response(200, [
            'status'  => 200,
            'message' => 'Usuários encontrados com sucesso.',
            'data'    => $formattedUsers,

            // ✅ para o front saber a role e bloquear clique/modal
            'meta'    => [
                'role' => $role,
                'id'   => $obUser['id']
            ]
        ], 'application/json');
    }


    /**
     * @throws RandomException
     */
    public static function getSetNewUsers($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, json_encode(['status' => 401, 'message' => 'Não autenticado']), 'application/json');
        }

        $postVars = $request->getPostVars();
        $tenancyId = $obUser['tenancy_id'];

        if (!self::canManageUsersByPlan($obUser)) {
            return new Response(403, json_encode([
                'status' => 'ERROR',
                'message' => 'Seu plano atual não permite criar usuários adicionais.'
            ]), 'application/json');
        }

        // 1. Captura os dados (usando 'user_function' ou 'role' conforme seu HTML atual)
        $firstName   = htmlspecialchars(trim($postVars['first_name'] ?? ''));
        $lastName    = htmlspecialchars(trim($postVars['last_name'] ?? ''));
        $email       = filter_var(trim($postVars['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $password    = trim($postVars['password'] ?? '');
        $roleName    = strtolower(trim($postVars['user_function'] ?? $postVars['role'] ?? ''));
        $statusAcc   = trim($postVars['status'] ?? 'active');

        if (empty($firstName) || empty($email) || empty($password) || empty($roleName)) {
            return new Response(400, json_encode(['status' => 'ERROR', 'message' => 'Preencha todos os campos obrigatórios.']), 'application/json');
        }

        if (!self::canAssignRole($obUser, $roleName)) {
            return new Response(403, json_encode([
                'status' => 'ERROR',
                'message' => 'Você não tem permissão para criar usuários com esta função.'
            ]), 'application/json');
        }

        if (strtolower($roleName) === 'admin' && !TenancyHelper::isSuperAdmin($obUser)) {
            return new Response(403, json_encode([
                'status' => 'ERROR',
                'message' => 'O papel administrador padrao do sistema so pode ser gerenciado pelo super administrador.'
            ]), 'application/json');
        }

        // --- 🚀 LÓGICA INTELIGENTE DE DESCRIÇÕES DE PAPÉIS ---
        $roleDescriptions = [
            'admin'           => 'Administrador Geral com acesso total ao sistema',
            'rh'              => 'Gestão de Recursos Humanos e colaboradores',
            'financial'       => 'Acesso a faturamento, notas e relatórios financeiros',
            'reception'       => 'Acesso para recepção e secretariado',
            'manager'         => 'Gestão total da unidade de negócio e operações',
            'supervisor'      => 'Supervisão de equipes e monitoramento de chamadas',
            'agent'           => 'Operação básica de ramais e filas de atendimento',
            'monitor'         => 'Monitoria de qualidade, escuta e feedbacks',
            'support_l1'      => 'Suporte Nível 1 - Atendimento inicial',
            'support_l2'      => 'Suporte Nível 2 - Configurações técnicas e TI',
            'ticket_support'   => 'Atendimento interno autorizado para tickets',
            'reseller'        => 'Acesso para gestão de revendas e clientes finais'
        ];

        // Define a descrição baseada no mapa acima ou gera uma automática
        $description = $roleDescriptions[$roleName] ?? "Perfil para " . ucfirst($roleName);

        // 2. Tenta buscar o papel pelo nome E tenancy_id
        $roleData = PermissionsRules::getRoleByNameAndTenancy($roleName, $tenancyId);

        if (!$roleData) {
            // Agora com os 6 parâmetros corretos e a descrição incluída
            $roleId = PermissionsRules::registerRole(
                $roleName,          // $name
                $description,       // $label (Aqui entra a tua descrição inteligente)
                'y',           // $status
                $tenancyId,         // $tenancyId
                (int)$obUser['id'], // $userId (Quem está a criar)
                null                // $id (Novo registro)
            );
        } else {
            $roleId = $roleData->id;
        }

        // --- GERAÇÃO DO ACCOUNT_CODE ÚNICO ---
        // Chamamos a sua função para gerar o código de 10 dígitos
        $accountCode = RegisterUsers::generateUniqueAccountCode();

        // --- CRIAÇÃO DO USUÁRIO ---

        // 3. Configura o usuário já com o role_id correto
        $obUserNew = new UserSearch();
        $obUserNew->name           = $firstName;
        $obUserNew->last_name      = $lastName;
        $obUserNew->tenancy_id     = $tenancyId;
        $obUserNew->account_code   = $accountCode;
        $obUserNew->user_id        = (int)$obUser['id'];
        $obUserNew->email          = $email;
        $obUserNew->user_function  = $roleName;
        $obUserNew->role_id        = (int)$roleId; // Vínculo direto
        $obUserNew->password       = password_hash($password, PASSWORD_DEFAULT);
        $obUserNew->status_account = $statusAcc;
        $obUserNew->status         = 'n';
        $obUserNew->createdAt      = date('Y-m-d H:i:s');

        $newUserId = $obUserNew->insertUsers();

        if ($newUserId) {
            // 4. Vincula na tabela intermediária de permissões
            PermissionsRules::assignRoleToUser((int)$newUserId, $tenancyId, (int)$roleId);
            self::grantTicketSupportDefaults($tenancyId, (int)$roleId, $roleName);

            return new Response(200, json_encode([
                'status'  => 200,
                'message' => 'Usuário ' . $firstName . ' criado e vinculado ao papel ' . $roleName . '!'
            ]), 'application/json');
        }

        return new Response(500, json_encode(['status' => 'ERROR', 'message' => 'Erro ao criar usuário.']), 'application/json');
    }

    private static function canManageUsersByPlan(array $user): bool
    {
        if (TenancyHelper::isSuperAdmin($user)) {
            return true;
        }

        return PlanAccessPolicy::canCreateUsers((string)($user['tenancy_id'] ?? ''));
    }

    /**
     * @throws Exception
     */
    public static function setUsersResetPass($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $postVars = $request->getPostVars();
        $currentPass = $postVars['current_password'] ?? '';
        $newPass     = $postVars['new_password'] ?? '';
        $confirmPass = $postVars['confirm_password'] ?? '';


        if (empty($currentPass) || empty($newPass) || empty($confirmPass)) {
            return new Response(400, [
                'status' => 400,
                'message' => 'Todos os campos de senha são obrigatórios.'
            ], 'application/json');
        }


        if ($newPass !== $confirmPass) {
            return new Response(400, [
                'status' => 400,
                'message' => 'A nova senha e a confirmação não coincidem.'
            ], 'application/json');
        }


        $userData = UserSearch::getUserById($obUser['tenancy_id'], $obUser['id']);
        if (!$userData) {
            return new Response(404, [
                'status' => 404,
                'message' => 'Usuário não encontrado.'
            ], 'application/json');
        }


        if (!password_verify($currentPass, $userData['password'])) {
            return new Response(400, [
                'status' => 400,
                'message' => 'Senha atual incorreta.'
            ], 'application/json');
        }

        $obUserPass = new UserSearch();
        $obUserPass->id = $obUser['id'];
        $obUserPass->tenancy_id = $obUser['tenancy_id'];
        $obUserPass->password = password_hash($newPass, PASSWORD_DEFAULT);


        if ($obUserPass->updatePassword()) {
            // Sucesso
            return new Response(200, [
                'success' => true,
                'message' => 'Senha atualizada com sucesso!'
            ], 'application/json');
        } else {
            // Erro ao atualizar
            return new Response(500, [
                'success' => false,
                'message' => 'Erro ao atualizar a senha.'
            ], 'application/json');
        }
    }

    public static function setUsersImagesProfile($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            return new Response(400, [
                'status' => 400,
                'message' => 'Nenhum arquivo enviado ou erro no upload.'
            ], 'application/json');
        }

        $file = $_FILES['image'];
        $tenancyId = $obUser['tenancy_id'];
        $userId = $obUser['id'];
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            return new Response(422, [
                'status' => 422,
                'message' => 'O avatar enviado está vazio.'
            ], 'application/json');
        }

        if ($size > 2 * 1024 * 1024) {
            return new Response(422, [
                'status' => 422,
                'message' => 'O avatar deve ter no máximo 2 MB.'
            ], 'application/json');
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return new Response(422, [
                'status' => 422,
                'message' => 'Arquivo de avatar inválido.'
            ], 'application/json');
        }

        $mimeType = self::detectAvatarMimeType($tmpName);
        $extensionMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($extensionMap[$mimeType])) {
            return new Response(422, [
                'status' => 422,
                'message' => 'Envie um avatar JPG, PNG ou WEBP.'
            ], 'application/json');
        }

        $uploadDir = __DIR__ . "/../../../resources/assets/img/upload/{$tenancyId}/{$userId}/";
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            return new Response(500, [
                'status' => 500,
                'message' => 'Não foi possível preparar o diretório de upload no servidor.'
            ], 'application/json');
        }

        if (!is_writable($uploadDir)) {
            @chmod($uploadDir, 0775);
        }

        if (!is_writable($uploadDir)) {
            return new Response(500, [
                'status' => 500,
                'message' => 'O diretório de upload está sem permissão de escrita no servidor.'
            ], 'application/json');
        }

        $fileName = uniqid('imageUser_', true) . "." . $extensionMap[$mimeType];
        $targetPath = $uploadDir . $fileName;

        if (!move_uploaded_file($tmpName, $targetPath)) {
            return new Response(500, [
                'status' => 500,
                'message' => 'Erro ao mover o arquivo para o servidor. Verifique permissões da pasta resources/assets/img/upload.'
            ], 'application/json');
        }

        // Limita a 3 arquivos por usuário e remove os mais antigos
        $files = glob($uploadDir . '*'); // todos os arquivos
        if (count($files) > 3) {
            // Ordena pelo tempo de modificação (mais antigos primeiro)
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });

            $filesToDelete = array_slice($files, 0, count($files) - 3);
            foreach ($filesToDelete as $filePath) {
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }
        }

        // Atualiza referência no banco
        $obUserImage = new UserSearch();
        $obUserImage->id = $userId;
        $obUserImage->tenancy_id = $tenancyId;
        $obUserImage->image = "upload/{$tenancyId}/{$userId}/{$fileName}";

        if ($obUserImage->updateUserImage()) {
            return new Response(200, [
                'status' => 200,
                'message' => 'Imagem salva com sucesso!',
                'image' => $obUserImage->image,
                'avatar_url' => URL . '/resources/assets/img/' . $obUserImage->image
            ], 'application/json');
        } else {
            return new Response(500, [
                'status' => 500,
                'message' => 'Erro ao atualizar a imagem no banco.'
            ], 'application/json');
        }
    }

    private static function detectAvatarMimeType(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    return strtolower($detected);
                }
            }
        }

        return '';
    }

    /**
     * Método responsável por atualizar o endereço do perfil via Model AddressSearch
     */
    public static function setUserAddress($request): Response
    {
        // 1. Pega usuário logado
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, ['status' => 401, 'message' => 'Não autorizado'], 'application/json');
        }

        $role = $obUser['user_function'] ?? $obUser['function'] ?? '';
        if (!in_array($role, ['admin', 'super_admin'])) {
            return new Response(403, [
                'status' => 403,
                'message' => 'Acesso negado: Somente administradores podem alterar o endereço da empresa.'
            ], 'application/json');
        }

        // 2. Pega os dados do POST (JSON decodificado vindo do fetch)
        $postVars = json_decode(file_get_contents('php://input'), true);


        // 3. Instancia a SUA Model existente
        $obAddress = new AddressSearch();
        $obAddress->tenancy_id  = $obUser['tenancy_id']; // Vincula pelo tenancy do logado
        $obAddress->zipcode     = $postVars['cep'] ?? '';
        $obAddress->street      = $postVars['logradouro'] ?? '';
        $obAddress->number      = $postVars['numero'] ?? '';
        $obAddress->complement  = $postVars['complemento'] ?? null;
        $obAddress->neighborhood = $postVars['bairro'] ?? '';
        $obAddress->city        = $postVars['cidade'] ?? '';
        $obAddress->state       = $postVars['estado'] ?? '';
        $obAddress->country     = 'Brasil';

        // 4. Usa o método save() que você já tem na classe
        if ($obAddress->save()) {
            return new Response(200, [
                'status' => 200,
                'message' => 'Endereço atualizado com sucesso!'
            ], 'application/json');
        }

        return new Response(500, [
            'status' => 500,
            'message' => 'Erro ao processar a atualização do endereço.'
        ], 'application/json');
    }


    public static function getUsersEdit($request, int $id): Response|string

    {

        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        // Buscar usuário no banco pelo ID passado
        $userData = TenancyHelper::isSuperAdmin($obUser)
            ? UserSearch::getUserByIdGlobal($id)
            : UserSearch::getUserById($obUser['tenancy_id'], $id);

        if (!$userData) {
            return new Response(404, [
                'status' => 404,
                'message' => 'Usuário não encontrado.'
            ], 'application/json');
        }

        if (!self::canManageTargetUser($obUser, $userData, true)) {
            return new Response(403, [
                'status' => 403,
                'message' => 'Você não tem permissão para editar este usuário.'
            ], 'application/json');
        }

        // Monta conteúdo da view já com dados do usuário
        $content = View::render('/users/edit', [
            'id'            => $userData['id'],
            'name'          => $userData['name'],
            'last_name'     => $userData['last_name'],
            'tenancy_name'  => $userData['tenancy_name'],
            'tenancy_phone' => $userData['tenancy_phone'],
            'email'         => $userData['email'],

            // --- Administrativo ---
            'role_admin'      => $userData['user_function'] === 'admin' ? 'selected' : '',
            'role_rh'         => $userData['user_function'] === 'rh' ? 'selected' : '',
            'role_financial'  => $userData['user_function'] === 'financial' ? 'selected' : '',
            'role_reception'  => $userData['user_function'] === 'reception' ? 'selected' : '',

            // --- Operação de Telemarketing ---
            'role_manager'    => $userData['user_function'] === 'manager' ? 'selected' : '',
            'role_supervisor' => $userData['user_function'] === 'supervisor' ? 'selected' : '',
            // Aceita 'agent' ou o legado 'operator'/'o'
            'role_agent'      => in_array($userData['user_function'], ['agent', 'operator', 'o']) ? 'selected' : '',
            'role_monitor'    => $userData['user_function'] === 'monitor' ? 'selected' : '',

            // --- Suporte Técnico ---
            'role_support_l1' => $userData['user_function'] === 'support_l1' ? 'selected' : '',
            'role_support_l2' => $userData['user_function'] === 'support_l2' ? 'selected' : '',
            'role_ticket_support' => $userData['user_function'] === 'ticket_support' ? 'selected' : '',

            // --- Parceiros ---
            'role_reseller'   => $userData['user_function'] === 'reseller' ? 'selected' : '',

            // --- Status ---
            'status_active'   => $userData['status_account'] === 'active' ? 'selected' : '',
            'status_inactive' => $userData['status_account'] === 'inactive' ? 'selected' : '',
        ]);

        return parent::getComponentsUsers('Maxx Solutions | Editar Usuário', $content);
    }


    public static function setUsersEdit($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $data = $request->getPostVars();

        // ─── Validações obrigatórias ───
        if (empty($data['id']) || !is_numeric($data['id'])) {
            return new Response(400, ['status' => 400, 'message' => 'ID de usuário inválido.'], 'application/json');
        }

        if (empty($data['first_name']) || empty($data['last_name'])) {
            return new Response(400, ['status' => 400, 'message' => 'Nome e sobrenome são obrigatórios.'], 'application/json');
        }

        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return new Response(400, ['status' => 400, 'message' => 'E-mail inválido.'], 'application/json');
        }

        $allowedRoles = [
            'admin', 'rh', 'financial', 'reception',       // Administrativo
            'manager', 'supervisor', 'agent', 'monitor',   // Operação
            'operator',                                    // Legado
            'support_l1', 'support_l2',                    // Suporte técnico genérico
            'ticket_support', 'support_ticket_manager',    // Atendimento específico de tickets
            'reseller'                                     // Parceiros
        ];

        $data['role'] = strtolower(trim((string)($data['role'] ?? '')));

        if (empty($data['role']) || !in_array($data['role'], $allowedRoles, true)) {
            return new Response(400, json_encode([
                'success' => false,
                'message' => 'Função inválida: ' . ($data['role'] ?? 'não informada')
            ]), 'application/json');
        }

        if (strtolower((string)$data['role']) === 'admin' && !TenancyHelper::isSuperAdmin($obUser)) {
            return new Response(403, [
                'status' => 403,
                'message' => 'O papel administrador padrao do sistema so pode ser gerenciado pelo super administrador.'
            ], 'application/json');
        }

        if (empty($data['status']) || !in_array($data['status'], ['active','inactive'])) {
            return new Response(400, ['status' => 400, 'message' => 'Status inválido.'], 'application/json');
        }

        // ─── Validação de senha ───
        $updatePassword = false;
        if (!empty($data['password']) || !empty($data['confirm_password'])) {
            if ($data['password'] !== $data['confirm_password']) {
                return new Response(400, ['status' => 400, 'message' => 'As senhas não conferem.'], 'application/json');
            }
            if (strlen($data['password']) < 6) {
                return new Response(400, ['status' => 400, 'message' => 'A senha deve ter no mínimo 6 caracteres.'], 'application/json');
            }
            $updatePassword = true;
        }

        // ─── Monta campos para update ───
        $fields = [
            'name'                => trim($data['first_name']),
            'last_name'           => trim($data['last_name']),
            'email'               => strtolower(trim($data['email'])),
            'user_function'       => $data['role'],
            'status_account'      => $data['status'],
            'updatedAt'           => date('Y-m-d H:i:s'),
        ];

        if ($updatePassword) {
            $fields['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        try {
            $targetUser = TenancyHelper::isSuperAdmin($obUser)
                ? UserSearch::getUserByIdGlobal((int)$data['id'])
                : UserSearch::getUserById($obUser['tenancy_id'], (int)$data['id']);

            if (!$targetUser) {
                return new Response(404, [
                    'status' => 404,
                    'message' => 'Usuário não encontrado.'
                ], 'application/json');
            }

            if (!self::canManageTargetUser($obUser, $targetUser, true)) {
                return new Response(403, [
                    'status' => 403,
                    'message' => 'Você não tem permissão para editar este usuário.'
                ], 'application/json');
            }

            $isSelfEditKeepingSameRole =
                (int)($targetUser['id'] ?? 0) === (int)($obUser['id'] ?? 0)
                && strtolower((string)($targetUser['user_function'] ?? '')) === $data['role'];

            if (!self::canAssignRole($obUser, $data['role']) && !$isSelfEditKeepingSameRole) {
                return new Response(403, [
                    'status' => 403,
                    'message' => 'Você não tem permissão para atribuir esta função.'
                ], 'application/json');
            }

            $targetTenancyId = (string)$targetUser['tenancy_id'];
            $success = UserSearch::updateUsers(
                (int)$data['id'],
                $fields,
                TenancyHelper::isSuperAdmin($obUser) ? null : $obUser['tenancy_id']
            );

            if (!$success) {
                return new Response(404, [
                    'status' => 404,
                    'message' => 'Usuário não encontrado.'
                ], 'application/json');
            }

            $roleOwner = $obUser;
            $roleOwner['tenancy_id'] = $targetTenancyId;
            $roleId = self::ensureRoleForUserFunction($data['role'], $roleOwner);
            if ($roleId > 0) {
                PermissionsRules::assignRoleToUser((int)$data['id'], $targetTenancyId, $roleId);
                self::grantTicketSupportDefaults($targetTenancyId, $roleId, $data['role']);
            }

            return new Response(200, [
                'status' => 200,
                'message' => 'Usuário atualizado com sucesso.'
            ], 'application/json');

        } catch (\Exception $e) {
            return new Response(500, [
                'status' => 500,
                'message' => 'Erro ao atualizar usuário: '.$e->getMessage()
            ], 'application/json');
        }
    }

    private static function ensureRoleForUserFunction(string $roleName, array $ownerUser): int
    {
        $roleData = PermissionsRules::getRoleByNameAndTenancy($roleName, $ownerUser['tenancy_id']);
        if ($roleData) {
            return (int)$roleData->id;
        }

        $descriptions = [
            'ticket_support' => 'Atendimento interno autorizado para tickets',
            'support_ticket_manager' => 'Gestor interno autorizado para tickets',
        ];

        return (int)PermissionsRules::registerRole(
            $roleName,
            $descriptions[$roleName] ?? ('Perfil para ' . ucfirst($roleName)),
            'y',
            $ownerUser['tenancy_id'],
            (int)$ownerUser['id'],
            null
        );
    }

    private static function grantTicketSupportDefaults(string $tenancyId, int $roleId, string $roleName): void
    {
        if (!in_array($roleName, ['ticket_support', 'support_ticket_manager'], true)) {
            return;
        }

        $paths = [
            '/support',
            '/support/diagnostics',
            '/support/tickets',
            '/support/tickets/create',
            '/support/tickets/{id}/messages',
            '/support/tickets/{id}/messages/create',
            'ticket.view_all',
            'ticket.view_own',
            'ticket.create',
            'ticket.reply',
            '/support/tickets/{id}/status',
            'ticket.change_status',
            'ticket.update',
        ];

        $db = new Database();
        foreach ($paths as $path) {
            $routeId = (int)$db->execute(
                'SELECT id FROM sys_routes WHERE route_path = :path LIMIT 1',
                [':path' => $path]
            )->fetchColumn();

            if ($routeId <= 0) {
                continue;
            }

            $db->execute(
                'INSERT IGNORE INTO sys_role_permissions (tenancy_id, role_id, route_id) VALUES (:tenancy_id, :role_id, :route_id)',
                [
                    ':tenancy_id' => $tenancyId,
                    ':role_id' => $roleId,
                    ':route_id' => $routeId,
                ]
            );
        }
    }

    public static function setDeleteUsers($request, int $Id): Response|array

    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        try {
            $isSuperAdmin = TenancyHelper::isSuperAdmin($obUser);
            $targetUser = $isSuperAdmin
                ? UserSearch::getUserByIdGlobal($Id)
                : UserSearch::getUserById((string)$obUser['tenancy_id'], $Id);

            if (!$targetUser) {
                return new Response(404, [
                    'status' => 404,
                    'message' => 'Usuário não encontrado.'
                ], 'application/json');
            }

            if ((int)($targetUser['id'] ?? 0) === (int)($obUser['id'] ?? 0) && !$isSuperAdmin) {
                return new Response(403, [
                    'status' => 403,
                    'message' => 'Você não pode excluir a própria conta por esta tela.'
                ], 'application/json');
            }

            if (!self::canManageTargetUser($obUser, $targetUser, false)) {
                return new Response(403, [
                    'status' => 403,
                    'message' => 'Você não tem permissão para excluir este usuário.'
                ], 'application/json');
            }

            $targetTenancyId = (string)($targetUser['tenancy_id'] ?? '');
            $tenantOwnerId = $targetTenancyId !== ''
                ? RegisterTenancies::getTenancyOwnerUserId($targetTenancyId)
                : null;

            if ($isSuperAdmin
                && $targetTenancyId !== ''
                && strtolower((string)($targetUser['user_function'] ?? '')) === 'admin'
                && $tenantOwnerId === (int)$targetUser['id']) {
                $summary = RegisterTenancies::deleteTenancyTree($targetTenancyId);

                return new Response(200, [
                    'status' => 200,
                    'message' => 'Tenant e toda a árvore de dados foram excluídos com sucesso.',
                    'data' => $summary,
                ], 'application/json');
            }

            $success = UserSearch::deleteUsers(
                $Id,
                $isSuperAdmin ? null : $obUser['tenancy_id']
            );

            if (!$success) {
                return new Response(404, [
                    'status' => 404,
                    'message' => 'Usuário não encontrado.'
                ], 'application/json');
            }

            return new Response(200, [
                'status' => 200,
                'message' => 'Usuário deletado com sucesso.'
            ], 'application/json');

        } catch (\Exception $e) {
            return new Response(500, [
                'status' => 500,
                'message' => 'Erro ao deletar usuario: ' . $e->getMessage()
            ], 'application/json');
        }
    }

    public static function setNewStatusUsers($request):Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }
               $dataStatus = $request->getPostVars();

        try {
            $targetUser = TenancyHelper::isSuperAdmin($obUser)
                ? UserSearch::getUserByIdGlobal((int)($dataStatus['id'] ?? 0))
                : UserSearch::getUserById((string)$obUser['tenancy_id'], (int)($dataStatus['id'] ?? 0));

            if (!$targetUser) {
                return new Response(404, [
                    'status' => 404,
                    'message' => 'Usuário não encontrado.'
                ], 'application/json');
            }

            if (!self::canManageTargetUser($obUser, $targetUser, false)) {
                return new Response(403, [
                    'status' => 403,
                    'message' => 'Você não tem permissão para alterar o status deste usuário.'
                ], 'application/json');
            }

            $success = UserSearch::updateStatusUser(
                (int)$dataStatus['id'],
                TenancyHelper::isSuperAdmin($obUser) ? null : $obUser['tenancy_id'],
                (string)$dataStatus['status']
            );

            if (!$success) {
                return new Response(404, [
                    'status' => 404,
                    'message' => 'Usuário não encontrado.'
                ], 'application/json');
            }

            return new Response(200, [
                'status' => 200,
                'message' => 'Status atualizado com sucesso.'
            ], 'application/json');

        } catch (\Exception $e) {
            return new Response(500, [
                'status' => 500,
                'message' => 'Erro ao atualizar o status: ' . $e->getMessage()
            ], 'application/json');
        }


    }

    private static function normalizedRole(array $user): string
    {
        return strtolower(trim((string)($user['user_function'] ?? $user['function'] ?? '')));
    }

    private static function canAssignRole(array $actor, string $roleName): bool
    {
        $roleName = strtolower(trim($roleName));
        $actorRole = self::normalizedRole($actor);

        if ($roleName === '') {
            return false;
        }

        if (TenancyHelper::isSuperAdmin($actor)) {
            return true;
        }

        if ($actorRole === 'admin') {
            return $roleName !== 'admin';
        }

        if ($actorRole === 'reseller') {
            return in_array($roleName, ['agent', 'operator'], true);
        }

        return false;
    }

    private static function canManageTargetUser(array $actor, array $targetUser, bool $allowSelf = false): bool
    {
        if (TenancyHelper::isSuperAdmin($actor)) {
            return true;
        }

        $actorId = (int)($actor['id'] ?? 0);
        $targetId = (int)($targetUser['id'] ?? 0);
        $actorTenancy = (string)($actor['tenancy_id'] ?? '');
        $targetTenancy = (string)($targetUser['tenancy_id'] ?? '');
        $actorRole = self::normalizedRole($actor);

        if ($actorTenancy === '' || $targetTenancy === '' || $actorTenancy !== $targetTenancy) {
            return false;
        }

        if ($actorId === $targetId) {
            return $allowSelf;
        }

        if ($actorRole === 'admin') {
            return true;
        }

        if ($actorRole === 'reseller') {
            return (int)($targetUser['user_id'] ?? 0) === $actorId;
        }

        return false;
    }

}
