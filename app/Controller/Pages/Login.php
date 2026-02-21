<?php

namespace App\Controller\Pages;

use App\Utils\View;
use App\Model\Entity\UserAuthentication;
use App\Session\User as SessionLogin;
use App\Model\Entity\PermissionsRules;
use JetBrains\PhpStorm\NoReturn;
use Random\RandomException;

class Login extends ViewComponents
{
    private const int SESSION_EXPIRATION = 3600; // 1 hora

    /**
     * Retorna os componentes de login para renderização.
     */
    public static function getLogin($request): array|bool|string
    {
        $content = View::render('', []);
        return parent::getComponentsLogin('Maxx Solutions - SMS | Login', $content);
    }



    /**
     * Realiza o login do usuário via POST JSON.
     * @throws RandomException
     */
    /*#[NoReturn] public static function setLogin($request): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $postVars = $request->getPostVars();
        $email = trim($postVars['email'] ?? '');
        $password = trim($postVars['password'] ?? '');
        $captcha = trim($postVars['g-recaptcha-response'] ?? '');
        $remember = isset($postVars['remember']) && ($postVars['remember'] === 'on' || $postVars['remember'] === true || $postVars['remember'] === '1');

        if ($email === '') {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'O campo E-mail não pode ser vazio!']);
        }

        $email = filter_var($email, FILTER_VALIDATE_EMAIL);
        if (!$email) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'E-mail inválido.']);
        }

        if ($password === '') {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'O campo Senha não pode ser vazio!']);
        }

        if (!Recaptcha::verify($captcha)) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'Falha na verificação do reCAPTCHA!']);
        }

        $obUser = UserAuthentication::getUserByEmail($email);

        if (!$obUser instanceof UserAuthentication || !password_verify($password, $obUser->password ?? '')) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'E-mail ou senha incorretos.']);
        }

        $canReconnect = false;
        if ($obUser->status === 'y') {
            $lastActivity = strtotime($obUser->last_activity ?? '');
            $now = time();

            if ($lastActivity !== false && ($now - $lastActivity) > self::SESSION_EXPIRATION) {
                $canReconnect = true;
            }
        }

        if ($obUser->status === 'n' || $canReconnect) {
            // Login com Remember Me se marcado
            SessionLogin::login($obUser, $remember);

            // Atualiza status e atividade
            UserAuthentication::setStatusAndActivity($obUser->email, 'y', date('Y-m-d H:i:s'));

            // 🔹 Detecta a função do usuário
            $userFunction = $obUser->user_function ?? '';
            $roleId = PermissionsRules::getRoleIdByUserId($obUser->id);

            // 🔹 Ajusta permissões conforme função
            if ($userFunction === 'super_admin') {
                // Super admin: sincroniza apenas regras globais se necessário
                PermissionsRules::syncSuperAdminPermissions($obUser->id);
            } elseif ($userFunction === 'admin') {
                // Admin/Tenancy: sincroniza apenas para o tenancy do usuário
                if ($roleId) {
                    PermissionsRules::syncNewRoutesForAllTenancies($roleId, $obUser->tenancy_id);
                }
            } else {
                // Para manager/operator/outros
                PermissionsRules::syncUserFunctionPermissions($obUser->id, $obUser->tenancy_id);
            }

            self::jsonResponse([
                'status' => 'OK',
                'message' => 'Olá ' . $obUser->name . ' !'
            ]);
        }

        // Se usuário estiver ativo em outro dispositivo sem timeout
        self::jsonResponse([
            'status' => 'ERROR',
            'message' => 'Usuário ativo em outro dispositivo!'
        ]);
    }*/

    #[NoReturn]
    public static function setLogin($request): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $postVars  = $request->getPostVars();
        $login     = trim($postVars['email'] ?? ''); // agora é "email ou código"
        $password  = trim($postVars['password'] ?? '');
        $captcha   = trim($postVars['g-recaptcha-response'] ?? '');
        $remember  = isset($postVars['remember']) && ($postVars['remember'] === 'on' || $postVars['remember'] === true || $postVars['remember'] === '1');

        if ($login === '') {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'O campo E-mail/Código não pode ser vazio!']);
        }

        if ($password === '') {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'O campo Senha não pode ser vazio!']);
        }

        /*if (!Recaptcha::verify($captcha)) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'Falha na verificação do reCAPTCHA!']);
        }*/

        // ✅ Detecta se é account_code (10 dígitos) ou email
        $isAccountCode = preg_match('/^\d{10}$/', $login) === 1;

        if ($isAccountCode) {
            // busca admin pelo account_code do tenancy
            $obUser = UserAuthentication::getUserByAccountCode($login);
        } else {
            // valida e-mail normalmente
            $email = filter_var($login, FILTER_VALIDATE_EMAIL);
            if (!$email) {
                self::jsonResponse(['status' => 'ERROR', 'message' => 'Informe um e-mail válido ou um código de 10 dígitos.']);
            }

            $obUser = UserAuthentication::getUserByEmail($email);
        }

        if (!$obUser instanceof UserAuthentication || !password_verify($password, $obUser->password ?? '')) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'E-mail/código ou senha incorretos.']);
        }

        $canReconnect = false;
        if (($obUser->status ?? '') === 'y') {
            $lastActivity = strtotime($obUser->last_activity ?? '');
            $now = time();

            if ($lastActivity !== false && ($now - $lastActivity) > self::SESSION_EXPIRATION) {
                $canReconnect = true;
            }
        }

        if (($obUser->status ?? '') === 'n' || $canReconnect) {

            SessionLogin::login($obUser, $remember);

            // ✅ atualiza status e atividade usando o email real do usuário
            UserAuthentication::setStatusAndActivity($obUser->email, 'y', date('Y-m-d H:i:s'));

            $userFunction = $obUser->user_function ?? '';
            $roleId       = PermissionsRules::getRoleIdByUserId($obUser->id);

            if ($userFunction === 'super_admin') {
                PermissionsRules::syncSuperAdminPermissions($obUser->id);
            } elseif ($userFunction === 'admin') {
                if ($roleId) {
                    PermissionsRules::syncNewRoutesForAllTenancies($roleId, $obUser->tenancy_id);
                }
            } else {
                PermissionsRules::syncUserFunctionPermissions($obUser->id, $obUser->tenancy_id);
            }

            self::jsonResponse([
                'status'  => 'OK',
                'message' => 'Olá ' . ($obUser->name ?? '') . ' !'
            ]);
        }

        self::jsonResponse([
            'status' => 'ERROR',
            'message' => 'Usuário ativo em outro dispositivo!'
        ]);
    }

        /**
     * Logout
     */
    public static function setLogout($request): void
    {
        $obStatusUser = new UserAuthentication();
        $obStatusUser->email = $_SESSION['user']['email'];
        $obStatusUser->status = 'n';
        $obStatusUser->updateStatusUser();

        SessionLogin::logout();

        $request->getRouter()->redirect('/login');
    }
}
