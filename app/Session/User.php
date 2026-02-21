<?php

namespace App\Session;

use App\Model\Entity\UserAuthentication;
use Random\RandomException;



/**
 * Classe responsável pelo controle de sessão de usuário.
 */
class User
{

    /**
     * Inicia a sessão PHP de forma segura se ainda não iniciada.
     */
    public static function ensureSessionStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * Cria a sessão de login do usuário.
     *
     * @param UserAuthentication $obUser
     * @param bool $remember Habilita o Remember Me
     * @throws RandomException
     */

    public static function login(UserAuthentication $obUser, bool $remember = false): void
    {
        self::ensureSessionStarted();

        $_SESSION['user'] = [
            'id'            => $obUser->id,
            'name'          => $obUser->name ?? '',
            'email'         => $obUser->email,
            'account_code'  => $obUser->account_code ?? null, // ✅ sem espaço
            'function'      => $obUser->user_function ?? '',
            'tenancy_id'    => $obUser->tenancy_id ?? null, // <-- adicionado
            'timeSession'   => time()
        ];

        if ($remember) {
            self::setRememberMeToken($obUser, $obUser->tenancy_id);
        }
    }


    /**
     * Cria o cookie Remember Me seguro e salva no banco.
     *
     * @param UserAuthentication $obUser
     * @param string $tenancyId
     * @throws RandomException
     */

    private static function setRememberMeToken(UserAuthentication $obUser, string $tenancyId): void
    {
        // 1️⃣ Gera token aleatório seguro
        $token = bin2hex(random_bytes(32));

        // 2️⃣ Cria hash do token para salvar no banco (nunca salvar token puro)
        $hashedToken = password_hash($token, PASSWORD_DEFAULT);

        // 3️⃣ Atualiza token no banco
        // Assumindo que UserAuthentication::setRememberToken já faz UPDATE seguro
        // com tenancy_id para multitenancy
        UserAuthentication::setRememberToken($obUser->id, $hashedToken, $tenancyId);

        // 4️⃣ Cria cookie seguro, válido por 30 dias
        setcookie('remember_me', $token, [
            'expires'  => time() + (30 * 24 * 60 * 60), // 30 dias
            'path'     => '/',
            'domain'   => $_SERVER['HTTP_HOST'],       // se quiser subdomínios, ajustar
            'secure'   => true,                         // forçar HTTPS
            'httponly' => true,
            'samesite' => 'Strict'                      // ou 'Lax' se precisar em subdomínios
        ]);
    }

    /**
     * Retorna os dados do usuário logado ou null.
     *
     * @return array|null
     */
    public static function getLogged(): ?array
    {
        self::ensureSessionStarted();

        return $_SESSION['user'] ?? null;
    }

    /**
     * Verifica se o usuário está logado ou se há um Remember Me válido.
     *
     * @return bool
     */
    public static function isLogged(): bool
    {
        self::ensureSessionStarted();

        if (!isset($_SESSION['user'])) {
            return self::checkRememberMeToken();
        }

        return true;
    }

    /**
     * Verifica o cookie Remember Me e faz login automático se válido.
     *
     * @return bool
     */
    private static function checkRememberMeToken(): bool
    {
        if (!isset($_COOKIE['remember_me'])) {
            return false;
        }

        $token = $_COOKIE['remember_me'];
        $tenancyId = $_SESSION['user']['tenancy_id'] ?? ''; // ou do contexto do login
        $obUser = UserAuthentication::getUserByRememberToken($token, $tenancyId);

        if ($obUser instanceof UserAuthentication) {
            $_SESSION['user'] = [
                'id'    => $obUser->id,
                'name'  => $obUser->name ?? '',
                'email' => $obUser->email,
                'account_code' => $obUser->account_code ?? null, // ✅ sem espaço
                'tenancy_id' => $obUser->tenancy_id ?? null, // <-- adicionado
                'timeSession' => time()
            ];
            return true;
        }

        // Token inválido: limpa cookie
        setcookie('remember_me', '', time() - 3600, '/');
        return false;
    }

    /**
     * Finaliza a sessão e limpa o Remember Me.
     */
    public static function logout(): void
    {
        self::ensureSessionStarted();

        // Limpa token no banco
        if (isset($_SESSION['user']['id'])) {
            UserAuthentication::clearRememberToken($_SESSION['user']['id']);
        }

        // Limpa cookie
        setcookie('remember_me', '', time() - 3600, '/');

        // Limpa sessão
        unset($_SESSION['user']);
        session_destroy();
    }
}

