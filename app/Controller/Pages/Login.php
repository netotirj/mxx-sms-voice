<?php

namespace App\Controller\Pages;

use App\Utils\View;
use App\Model\Entity\UserAuthentication;
use App\Session\User as SessionLogin;
use App\Model\Entity\PermissionsRules;
use App\Service\PlanAccessPolicy;
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
    #[NoReturn]
    public static function setLogin($request): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $postVars  = $request->getPostVars();
        $login     = trim($postVars['email'] ?? '');
        $password  = trim($postVars['password'] ?? '');
        $captcha   = trim($postVars['cf-turnstile-response'] ?? '');
        $remember  = isset($postVars['remember']) && ($postVars['remember'] === 'on' || $postVars['remember'] === true || $postVars['remember'] === '1');

        if (Recaptcha::isTurnstileEnabled() && !Recaptcha::verifyTurnstile($captcha)) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'Verificação de segurança inválida.']);
        }

        if ($login === '') {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'O campo E-mail/Código não pode ser vazio!']);
        }

        if ($password === '') {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'O campo Senha não pode ser vazio!']);
        }

        // 1. Identifica se é código de conta ou e-mail
        $isAccountCode = preg_match('/^\d{10}$/', $login) === 1;

        if ($isAccountCode) {
            $obUser = UserAuthentication::getUserByAccountCode($login);
            if (!$obUser instanceof UserAuthentication) {
                self::jsonResponse(['status' => 'ERROR', 'message' => 'Código inválido ou sem permissão.']);
            }
        } else {
            $email = filter_var($login, FILTER_VALIDATE_EMAIL);
            if (!$email) {
                self::jsonResponse(['status' => 'ERROR', 'message' => 'Informe um e-mail válido ou um código de 10 dígitos.']);
            }
            $obUser = UserAuthentication::getUserByEmail($email);
        }

        // 2. Verifica senha e existência do usuário
        if (!$obUser instanceof UserAuthentication || !password_verify($password, $obUser->password ?? '')) {
            self::jsonResponse(['status' => 'ERROR', 'message' => 'E-mail/código ou senha incorretos.']);
        }

        $result = self::completeAuthenticatedLogin($obUser, $remember);
        self::jsonResponse($result);
    }

    public static function completeAuthenticatedLogin(UserAuthentication $obUser, bool $remember = false): array
    {
        // Lógica de reconexão / Usuário ativo
        $canReconnect = false;
        if (($obUser->status ?? '') === 'y') {
            $lastActivity = strtotime($obUser->last_activity ?? '');
            $now = time();
            if ($lastActivity !== false && ($now - $lastActivity) > self::SESSION_EXPIRATION) {
                $canReconnect = true;
            }
        }

        if (($obUser->status ?? 'n') === 'n' || $canReconnect) {
            $access = PlanAccessPolicy::assertCanLogin([
                'id' => (int)($obUser->id ?? 0),
                'tenancy_id' => (string)($obUser->tenancy_id ?? ''),
                'user_function' => (string)($obUser->user_function ?? ''),
            ]);

            if (empty($access['allowed'])) {
                return [
                    'status' => 'ERROR',
                    'message' => $access['message'] ?? 'Limite de acessos simultâneos do plano atingido.'
                ];
            }

            SessionLogin::login($obUser, $remember);
            UserAuthentication::setStatusAndActivity($obUser->email, 'y', date('Y-m-d H:i:s'));

            return [
                'status'  => 'OK',
                'message' => 'Olá ' . ($obUser->name ?? '') . ' !'
            ];
        }

        return [
            'status' => 'ERROR',
            'message' => 'Usuário ativo em outro dispositivo!'
        ];
    }

    /**
     * Logout
     */
    public static function setLogout($request): void
    {
        $obUserSession = SessionLogin::getLogged();

        if ($obUserSession) {

            UserAuthentication::invalidateUserSession(
                (int)$obUserSession['id'],
                (string)$obUserSession['tenancy_id']
            );
        }

        SessionLogin::logout();

        $request->getRouter()->redirect('/login');
    }
}
