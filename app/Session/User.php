<?php

namespace App\Session;

use App\Model\Entity\UserAuthentication;
use App\Service\PermissionResolver;
use Random\RandomException;



/**
 * Classe responsável pelo controle de sessão de usuário.
 */
class User
{
    private const RUNTIME_CACHE_KEYS = [
        'permission_cache',
        'auth_context_cache',
        'plan_runtime_cache',
    ];

    /**
     * Inicia a sessão PHP de forma segura se ainda não iniciada.
     */
    public static function ensureSessionStarted(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            $cookieParams = self::cookieParams();
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $cookieParams['path'],
                'domain' => $cookieParams['domain'],
                'secure' => $cookieParams['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
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

        if (!headers_sent()) {
            session_regenerate_id(true);
        }

        $_SESSION = [];

        $_SESSION['user'] = [
            'id'            => $obUser->id,
            'name'          => $obUser->name ?? '',
            'email'         => $obUser->email,
            'account_code'  => $obUser->account_code ?? null, // ✅ sem espaço
            'function'      => $obUser->user_function ?? '',
            'user_function' => $obUser->user_function ?? '',
            'role_id'       => $obUser->role_id ?? 0, // 🚀 AQUI: Salvando o ID do papel
            'tenancy_id'    => $obUser->tenancy_id ?? null, // <-- adicionado
            'timezone'      => $obUser->timezone ?? 'America/Sao_Paulo',
            'timeSession'   => time()
        ];

        PermissionResolver::warmUserAccess($_SESSION['user']);

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
        $cookieParams = self::cookieParams();
        setcookie('remember_me', $token, [
            'expires'  => time() + (30 * 24 * 60 * 60),
            'path'     => $cookieParams['path'],
            'domain'   => $cookieParams['domain'],
            'secure'   => $cookieParams['secure'],
            'httponly' => true,
            'samesite' => 'Lax'
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
        if (empty($_COOKIE['remember_me'])) {
            return false;
        }

        $token = $_COOKIE['remember_me'];
        $obUser = UserAuthentication::getUserByRememberToken($token);

        if ($obUser instanceof UserAuthentication) {
            $_SESSION['user'] = [
                'id'           => $obUser->id,
                'name'         => $obUser->name ?? '',
                'email'        => $obUser->email,
                'account_code' => $obUser->account_code ?? null,
                'function'     => $obUser->user_function ?? '',
                'user_function'=> $obUser->user_function ?? '',
                'role_id'      => $obUser->role_id ?? 0,
                'tenancy_id'   => $obUser->tenancy_id ?? null,
                'timezone'     => $obUser->timezone ?? 'America/Sao_Paulo',
                'timeSession'  => time()
            ];

            PermissionResolver::warmUserAccess($_SESSION['user']);

            return true;
        }

        self::expireRememberMeCookie();
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
        self::expireRememberMeCookie();

        // Limpa sessão
        $_SESSION = [];
        self::clearRuntimeCaches();

        if (!headers_sent()) {
            self::expirePhpSessionCookie();
        }

        session_destroy();
    }

    public static function clearRuntimeCaches(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        self::ensureSessionStarted();

        foreach (self::RUNTIME_CACHE_KEYS as $key) {
            unset($_SESSION[$key]);
        }
    }

    private static function expireRememberMeCookie(): void
    {
        $cookieParams = self::cookieParams();
        setcookie('remember_me', '', [
            'expires' => time() - 3600,
            'path' => $cookieParams['path'],
            'domain' => $cookieParams['domain'],
            'secure' => $cookieParams['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function expirePhpSessionCookie(): void
    {
        $cookieParams = self::cookieParams();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $cookieParams['path'],
            'domain' => $cookieParams['domain'],
            'secure' => $cookieParams['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function cookieParams(): array
    {
        $fallbackUrl = (string)(getenv('URL') ?: '');
        $fallbackHost = (string)(parse_url($fallbackUrl, PHP_URL_HOST) ?: '');
        $fallbackPath = (string)(parse_url($fallbackUrl, PHP_URL_PATH) ?: '/');
        $hostHeader = (string)($_SERVER['HTTP_HOST'] ?? '');
        $host = $hostHeader !== ''
            ? preg_replace('/:\d+$/', '', $hostHeader)
            : $fallbackHost;

        $https = (string)($_SERVER['HTTPS'] ?? '');
        $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $secure = $https === 'on'
            || $https === '1'
            || $forwardedProto === 'https'
            || str_starts_with(strtolower($fallbackUrl), 'https://');

        $path = $fallbackPath !== '' ? rtrim($fallbackPath, '/') : '';
        if ($path === '') {
            $path = '/';
        }

        return [
            'domain' => $host,
            'path' => $path,
            'secure' => $secure,
        ];
    }
}
