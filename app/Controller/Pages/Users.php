<?php

namespace App\Controller\Pages;


use App\Http\Response;
use App\Session\User as SessionUser;
use App\Utils\View;
use \App\Model\Entity\UserSearch;
use \App\Model\Entity\PermissionsRules;
use Exception;

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

        $usersProfile = UserSearch::getUsers($obUser['tenancy_id'], $obUser['id']);

        $name  = $usersProfile[0]['name'] ?? '';
        $lastname = $usersProfile[0]['last_name'] ?? '';
        $user = $usersProfile[0]['email'];
        $email  = $usersProfile[0]['email'] ?? '';
        $image  = $usersProfile[0]['image'] ?? '';
        $function  = $usersProfile[0]['user_function_translated'] ?? '';
        $address_city = $usersProfile[0]['address_city'] ?? '';
        $address_state = $usersProfile[0]['address_state'] ?? '';
        $address_zipcode = $usersProfile[0]['address_zipcode'] ?? '';


        $parts = array_filter([$address_street = $usersProfile[0]['address_street'] ?? '',
            $address_number = $usersProfile[0]['address_number'] ?? '',
            $address_complement = $usersProfile[0]['address_complement'] ?? '',
            $address_neighborhood = $usersProfile[0]['address_neighborhood'] ?? '',
        ]);

        $fullAddress = implode(', ', $parts);
        // Cria o caminho completo da imagem
        $userAvatar = $image ? URL . '/resources/assets/img/' . $image : URL . '/resources/assets/img/default-avatar.png';

        $content = View::render('/users/profile', [
            'id' => $obUser['id'],
            'name' => $name,
            'lastname' => $lastname,
            'email' => $email,
            'userAvatar' => $userAvatar,
            'user' => $user,
            'function' => $function,
            'address' =>$fullAddress,
            'city' => $address_city,
            'state' => $address_state,
            'zipcode' => $address_zipcode,

        ]);
        return parent::getComponentsUsers('Maxx Solutions - SMS | Users', $content);
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

        $usersNew = UserSearch::getUsers($obUser['tenancy_id']);

        $tenancyName  = $usersNew[0]['tenancy_name'] ?? '';
        $tenancyPhone = $usersNew[0]['tenancy_phone'] ?? '';



        $content = View::render('/users/new', [
            'tenancy_name'  => $tenancyName,
            'tenancy_phone' => $tenancyPhone
        ]);
        return parent::getComponentsUsers('Maxx Solutions - SMS | Campaign', $content);
    }

    /**
     * @throws Exception
     */
    /*public static function getAllUsers($request): Response
    {
        // Busca usuário logado
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        // Busca todos os usuários do tenancy
        $users = UserSearch::getUsers($obUser['tenancy_id']);

        $formattedUsers = [];
        $now = new \DateTime();

        foreach ($users as $user) {
            $status = 'Offline';
            if (!empty($user['last_activity'])) {
                $lastActivity = new \DateTime($user['last_activity']);
                $interval = $now->getTimestamp() - $lastActivity->getTimestamp();
                if ($interval <= 300) {
                    $status = 'Online';
                }
            }

            $userAvatar = $user['image'] ? URL . '/resources/assets/img/' . $user['image'] : URL . '/resources/assets/img/default-avatar.png';

            $balance = ($user['user_function'] === 'reseller')
                ? $user['reseller_balance']
                : null;


            $formattedUsers[] = [
                'id'            => $user['id'],
                'avatar'        => $userAvatar,
                'nome'          => $user['name'],
                'email'         => $user['email'],
                'funcao'        => $user['user_function_translated'] ?? $user['user_function'],
                'cargo'         => $user['job_title_translated'] ?? $user['job_title'],
                'status_account' => $user['status_account'],
                'status'        => $status,
                'balance'       =>  $user['reseller_balance'],
                'created'       => (new \DateTime($user['createdAt']))->format('d/m/Y H:i'),
            ];
        }

        return new Response(200, [
            'status'  => 200,
            'message' => 'Usuários encontrados com sucesso.',
            'data'    => $formattedUsers
        ], 'application/json');
    }*/

    /*public static function getAllUsers($request): Response
    {
        // 🔹 Busca usuário logado
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status'  => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        // 🔹 Se for SUPER ADMIN → pode listar todos os usuários de todas as tenancies
        if ($obUser['function'] === 'super_admin') {
            $users = UserSearch::getAllUsersNoFilter(); // método sem filtro de tenancy
        } else {
            // 🔹 Caso contrário, lista apenas os usuários do mesmo tenancy
            $users = UserSearch::getUsers($obUser['tenancy_id']);
        }

        $formattedUsers = [];
        $now = new \DateTime();

        foreach ($users as $user) {
            $status = 'Offline';
            if (!empty($user['last_activity'])) {
                $lastActivity = new \DateTime($user['last_activity']);
                $interval = $now->getTimestamp() - $lastActivity->getTimestamp();
                if ($interval <= 300) {
                    $status = 'Online';
                }
            }

            $userAvatar = $user['image']
                ? URL . '/resources/assets/img/' . $user['image']
                : URL . '/resources/assets/img/default-avatar.png';

            $formattedUsers[] = [
                'id'             => $user['id'],
                'avatar'         => $userAvatar,
                'nome'           => $user['name'],
                'email'          => $user['email'],
                'funcao'         => $user['user_function_translated'] ?? $user['user_function'],
                'cargo'          => $user['job_title_translated'] ?? $user['job_title'],
                'status_account' => $user['status_account'],
                'status'         => $status,
                'balance'        => $user['reseller_balance'],
                'created'        => (new \DateTime($user['createdAt']))->format('d/m/Y H:i'),
            ];
        }

        return new Response(200, [
            'status'  => 200,
            'message' => 'Usuários encontrados com sucesso.',
            'data'    => $formattedUsers
        ], 'application/json');
    }*/

    public static function getAllUsers($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status'  => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }


        $role = $obUser['function'] ?? '';
        $canEditBalance = in_array($role, ['super_admin', 'admin'], true);

        // SUPER ADMIN vê tudo, senão só tenancy
        if ($role === 'super_admin') {
            $users = UserSearch::getAllUsersNoFilter();
        } elseif ($role === 'admin') {
            $users = UserSearch::getUsers($obUser['tenancy_id']);
        } elseif ($role === 'reseller') {
            // ✅ reseller: só ele (usa o 2º parâmetro do getUsers)
            $users = UserSearch::getUsers($obUser['tenancy_id'], (int)$obUser['id']);
        } else {
            // outros perfis: escolha a regra (ex: só tenancy, ou só ele)
            $users = UserSearch::getUsers($obUser['tenancy_id']);
        }

        $formattedUsers = [];
        $now = new \DateTime();

        foreach ($users as $user) {
            $status = 'Offline';
            if (!empty($user['last_activity'])) {
                $lastActivity = new \DateTime($user['last_activity']);
                $interval = $now->getTimestamp() - $lastActivity->getTimestamp();
                if ($interval <= 300) $status = 'Online';
            }

            $userAvatar = $user['image']
                ? URL . '/resources/assets/img/' . $user['image']
                : URL . '/resources/assets/img/default-avatar.png';

            $formattedUsers[] = [
                'id'             => $user['id'],
                'avatar'         => $userAvatar,
                'nome'           => $user['name'],
                'email'          => $user['email'],

                // 🔐 mantenha o valor real para regras se precisar no front
                'user_function'  => $user['user_function'],

                // 🎨 texto traduzido para UI
                'funcao'         => $user['user_function_translated'] ?? $user['user_function'],

                'cargo'          => $user['job_title_translated'] ?? $user['job_title'],
                'status_account' => $user['status_account'],
                'status'         => $status,

                // ✅ só envia balance para super_admin/admin
                'balance'        => $canEditBalance ? ($user['reseller_balance'] ?? null) : null,

                'created'        => (new \DateTime($user['createdAt']))->format('d/m/Y H:i'),
            ];
        }

        //echo "<pre>";
        //print_r($formattedUsers);
        //echo "</pre>";exit();

        return new Response(200, [
            'status'  => 200,
            'message' => 'Usuários encontrados com sucesso.',
            'data'    => $formattedUsers,

            // ✅ para o front saber a role e bloquear clique/modal
            'meta'    => [
                'role' => $role
            ]
        ], 'application/json');
    }


    public static function getSetNewUsers($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, json_encode([
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ]), 'application/json');
        }

        $postVars = $request->getPostVars();

        // Sanitização
        $name            = htmlspecialchars(trim($postVars['first_name'] ?? ''));
        $last_name       = htmlspecialchars(trim($postVars['last_name'] ?? ''));
        $email           = filter_var(trim($postVars['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $password        = trim($postVars['password'] ?? '');
        $confirmPass     = trim($postVars['confirm_password'] ?? '');
        $role            = trim($postVars['role'] ?? '');
        $status_account  = trim($postVars['status'] ?? 'active');

        $users = UserSearch::getUsers($obUser['tenancy_id']);

        // Validações básicas
        $requiredFields = [
            'first_name'       => $name,
            'last_name'        => $last_name,
            'email'            => $email,
            'password'         => $password,
            'confirm_password' => $confirmPass
        ];

        foreach ($requiredFields as $field => $value) {
            if (trim($value) === '') {
                self::jsonResponse([
                    'status'  => 'ERROR',
                    'message' => 'O campo "' . ucfirst(str_replace('_', ' ', $field)) . '" é obrigatório.'
                ]);
            }
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'E-mail inválido.']);
        }

        foreach ($users as $user) {
            if (strtolower($user['email']) === strtolower($email)) {
                self::jsonResponse(['status' => 'ERROR', 'message' => 'E-mail já cadastrado.']);
            }
        }

        if (strlen($password) < 6) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'Senha deve ter no mínimo 6 caracteres.']);
        }

        if ($password !== $confirmPass) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'Senhas não conferem.']);
        }

        $allowedRoles = ['reseller', 'operator', 'manager','financial'];
        if (!in_array($role, $allowedRoles)) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'Função inválida.']);
        }

        // Criar usuário
        $obUserNew = new UserSearch();
        $obUserNew->name                   = $name;
        $obUserNew->last_name              = $last_name;
        $obUserNew->tenancy_id             = $obUser['tenancy_id'];
        $obUserNew->email                  = $email;
        $obUserNew->user_function          = $role;
        $obUserNew->password               = password_hash($password, PASSWORD_DEFAULT);
        $obUserNew->status                 = 'n';
        $obUserNew->status_account         = $status_account;
        $obUserNew->createdAt              = date('Y-m-d H:i:s');
        $obUserNew->updatedAt              = date('Y-m-d H:i:s');

        $newUserId = $obUserNew->insertUsers();

        // Criar/pegar role
        $roleId = PermissionsRules::createRole($obUser['tenancy_id'], $role, ucfirst($role));

        // Atribuir role ao usuário
        PermissionsRules::assignRoleToUser($newUserId, $obUser['tenancy_id'], $roleId);

        // Aplicar permissões fixas do template via entidade
        PermissionsRules::assignDefaultRolePermissionsToTenancy($role, $obUser['tenancy_id'], $roleId);

        return new Response(200, [
            'status'  => 200,
            'message' => 'O usuário ' . $name . ' criado com sucesso!',
        ], 'application/json');
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
        // Pega usuário logado
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        // Verifica se enviou arquivo
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            return new Response(400, [
                'status' => 400,
                'message' => 'Nenhum arquivo enviado ou erro no upload.'
            ], 'application/json');
        }

        $file = $_FILES['image'];
        $tenancyId = $obUser['tenancy_id'];
        $userId = $obUser['id'];

        $uploadDir = __DIR__ . "/../../../resources/assets/img/upload/{$tenancyId}/{$userId}/";
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Gera nome único para evitar sobrescrita
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = uniqid('imageUser_', true) . "." . $extension;
        $targetPath = $uploadDir . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return new Response(500, [
                'status' => 500,
                'message' => 'Erro ao mover o arquivo para o servidor.'
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
                'image' => $obUserImage->image // retorna caminho da imagem
            ], 'application/json');
        } else {
            return new Response(500, [
                'status' => 500,
                'message' => 'Erro ao atualizar a imagem no banco.'
            ], 'application/json');
        }
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
        $userData = UserSearch::getUserById($obUser['tenancy_id'], $id);

        if (!$userData) {
            return new Response(404, [
                'status' => 404,
                'message' => 'Usuário não encontrado.'
            ], 'application/json');
        }

        // Monta conteúdo da view já com dados do usuário
        $content = View::render('/users/edit', [
            'id'    => $userData['id'],
            'name'  => $userData['name'],
            'last_name'  => $userData['last_name'],
            'tenancy_name' => $userData['tenancy_name'],
            'tenancy_phone' => $userData['tenancy_phone'],
            'email' => $userData['email'],
            'role_admin'     => $userData['user_function'] === 'admin' ? 'selected' : '',
            'role_reseller'  => $userData['user_function'] === 'reseller' ? 'selected' : '',
            'role_manager'   => $userData['user_function'] === 'manager' ? 'selected' : '',
            'role_financial' => $userData['user_function'] === 'financial' ? 'selected' : '',
            'role_operator'  => $userData['user_function'] === 'operator' ? 'selected' : '',
            'status_active'   => $userData['status_account'] === 'active' ? 'selected' : '',
            'status_inactive' => $userData['status_account'] === 'inactive' ? 'selected' : '',
        ]);

        return parent::getComponentsUsers('Maxx Solutions - SMS | Editar Usuário', $content);
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

        if (empty($data['role']) || !in_array($data['role'], ['admin','reseller','manager','financial','operator'])) {
            return new Response(400, ['status' => 400, 'message' => 'Função de usuário inválida.'], 'application/json');
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
            $success = UserSearch::updateUsers((int)$data['id'], $fields, $obUser['tenancy_id']);

            if (!$success) {
                return new Response(404, [
                    'status' => 404,
                    'message' => 'Usuário não encontrado.'
                ], 'application/json');
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
            $success = UserSearch::deleteUsers($Id, $obUser['tenancy_id']);

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
            $success = UserSearch::updateStatusUser($dataStatus['id'], $obUser['tenancy_id'], $dataStatus['status'],);

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

}