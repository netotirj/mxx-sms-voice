<?php

namespace App\Controller\Pages;

use App\Model\Entity\UserAuthentication;
use App\Service\SocialAuthService;
use App\Session\User as SessionUser;

class SocialAuth
{
    private const int STATE_TTL = 900;

    public static function redirectToProvider($request, string $provider): void
    {
        $provider = SocialAuthService::normalizeProvider($provider);
        $intent = self::normalizeIntent((string)($request->getQueryParams()['intent'] ?? 'login'));

        try {
            if (!SocialAuthService::isSupported($provider) || !SocialAuthService::isEnabled($provider)) {
                throw new \RuntimeException('Rede social não suportada.');
            }

            SocialAuthService::assertProviderIsConfigured($provider);

            SessionUser::ensureSessionStarted();

            $state = bin2hex(random_bytes(24));
            $nonce = bin2hex(random_bytes(24));

            $_SESSION['social_auth'] = [
                'provider' => $provider,
                'intent' => $intent,
                'state' => $state,
                'nonce' => $nonce,
                'created_at' => time(),
            ];

            header('Location: ' . SocialAuthService::getAuthorizationUrl($provider, $state, $nonce), true, 302);
            exit;
        } catch (\Throwable $e) {
            self::redirectBack($request, $intent, 'error', $e->getMessage());
        }
    }

    public static function handleCallback($request, string $provider): void
    {
        $provider = SocialAuthService::normalizeProvider($provider);
        $payload = array_merge($request->getQueryParams(), $request->getPostVars());

        SessionUser::ensureSessionStarted();
        $context = self::pullContext();
        $intent = self::normalizeIntent((string)($context['intent'] ?? $payload['intent'] ?? 'login'));

        try {
            if (!SocialAuthService::isSupported($provider) || !SocialAuthService::isEnabled($provider)) {
                throw new \RuntimeException('Rede social não suportada.');
            }

            if (($payload['error'] ?? '') !== '') {
                $error = trim((string)($payload['error'] ?? ''));
                throw new \RuntimeException('A autenticação com ' . SocialAuthService::getProviderLabel($provider) . ' foi cancelada ou recusada: ' . $error);
            }

            self::assertStateContext($provider, $context, (string)($payload['state'] ?? ''));

            $code = trim((string)($payload['code'] ?? ''));
            if ($code === '') {
                throw new \RuntimeException('O provedor social não retornou código de autorização.');
            }

            $profile = SocialAuthService::exchangeCodeForProfile(
                $provider,
                $code,
                (string)($context['nonce'] ?? ''),
                $payload
            );

            self::finishAuthentication($request, $intent, $profile);
        } catch (\Throwable $e) {
            self::redirectBack($request, $intent, 'error', $e->getMessage());
        }
    }

    private static function finishAuthentication($request, string $intent, array $profile): void
    {
        $email = strtolower(trim((string)($profile['email'] ?? '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('O provedor social não retornou um e-mail utilizável.');
        }

        $user = UserAuthentication::getUserByEmail($email);

        if (!$user instanceof UserAuthentication) {
            $password = bin2hex(random_bytes(16));
            $registration = RegisterUsers::createAccount([
                'name' => trim((string)($profile['first_name'] ?? 'Cliente')),
                'lastname' => trim((string)($profile['last_name'] ?? '')),
                'email' => $email,
                'phone' => '',
                'password' => $password,
            ]);

            if (($registration['status'] ?? 'ERROR') !== 'OK') {
                throw new \RuntimeException((string)($registration['message'] ?? 'Não foi possível criar a conta social.'));
            }

            $user = $registration['user'];
        }

        $login = Login::completeAuthenticatedLogin($user);
        if (($login['status'] ?? 'ERROR') !== 'OK') {
            throw new \RuntimeException((string)($login['message'] ?? 'Não foi possível iniciar a sessão.'));
        }

        $request->getRouter()->redirect('/dashboard');
    }

    private static function assertStateContext(string $provider, array $context, string $receivedState): void
    {
        if ($receivedState === '') {
            throw new \RuntimeException('O retorno do provedor social veio sem state.');
        }

        if ($context === []) {
            throw new \RuntimeException('A sessão de autenticação social expirou. Tente novamente.');
        }

        $expectedState = (string)($context['state'] ?? '');
        $expectedProvider = (string)($context['provider'] ?? '');
        $createdAt = (int)($context['created_at'] ?? 0);

        if ($expectedState === '' || !hash_equals($expectedState, $receivedState)) {
            throw new \RuntimeException('A validação de segurança do login social falhou.');
        }

        if ($expectedProvider !== $provider) {
            throw new \RuntimeException('O retorno recebido não pertence ao provedor esperado.');
        }

        if ($createdAt <= 0 || (time() - $createdAt) > self::STATE_TTL) {
            throw new \RuntimeException('A tentativa de autenticação social expirou. Inicie novamente.');
        }
    }

    private static function pullContext(): array
    {
        $context = $_SESSION['social_auth'] ?? [];
        unset($_SESSION['social_auth']);

        return is_array($context) ? $context : [];
    }

    private static function redirectBack($request, string $intent, string $status, string $message): void
    {
        $route = self::normalizeIntent($intent) === 'register' ? '/register' : '/login';
        $query = http_build_query([
            'auth_status' => $status,
            'auth_message' => $message,
        ]);

        $request->getRouter()->redirect($route . '?' . $query);
    }

    private static function normalizeIntent(string $intent): string
    {
        return strtolower($intent) === 'register' ? 'register' : 'login';
    }
}
