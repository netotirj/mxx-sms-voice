<?php

namespace App\Service;

use Firebase\JWT\JWT;

class SocialAuthService
{
    public static function isEnabled(string $provider): bool
    {
        $provider = self::normalizeProvider($provider);

        $flag = match ($provider) {
            'google' => getenv('SOCIAL_AUTH_GOOGLE_ENABLED'),
            'facebook' => getenv('SOCIAL_AUTH_FACEBOOK_ENABLED'),
            'apple' => getenv('SOCIAL_AUTH_APPLE_ENABLED'),
            default => null,
        };

        if ($flag === false || $flag === null || trim((string)$flag) === '') {
            return $provider !== 'apple';
        }

        return in_array(strtolower(trim((string)$flag)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function getProviderLabel(string $provider): string
    {
        return match (self::normalizeProvider($provider)) {
            'google' => 'Google',
            'apple' => 'Apple',
            'facebook' => 'Facebook',
            default => 'Rede social',
        };
    }

    public static function normalizeProvider(string $provider): string
    {
        return strtolower(trim($provider));
    }

    public static function isSupported(string $provider): bool
    {
        return in_array(self::normalizeProvider($provider), ['google', 'apple', 'facebook'], true);
    }

    public static function getStartUrl(string $provider): string
    {
        return self::getBaseUrl() . '/auth/social/' . self::normalizeProvider($provider);
    }

    public static function getCallbackUrl(string $provider): string
    {
        return self::getBaseUrl() . '/auth/social/' . self::normalizeProvider($provider) . '/callback';
    }

    public static function getAuthorizationUrl(string $provider, string $state, string $nonce = ''): string
    {
        $provider = self::normalizeProvider($provider);

        return match ($provider) {
            'google' => self::buildGoogleAuthorizationUrl($state),
            'apple' => self::buildAppleAuthorizationUrl($state, $nonce),
            'facebook' => self::buildFacebookAuthorizationUrl($state),
            default => throw new \RuntimeException('Provedor social não suportado.'),
        };
    }

    public static function exchangeCodeForProfile(string $provider, string $code, string $nonce = '', array $callbackData = []): array
    {
        $provider = self::normalizeProvider($provider);

        return match ($provider) {
            'google' => self::exchangeGoogleCode($code),
            'apple' => self::exchangeAppleCode($code, $nonce, $callbackData),
            'facebook' => self::exchangeFacebookCode($code),
            default => throw new \RuntimeException('Provedor social não suportado.'),
        };
    }

    public static function assertProviderIsConfigured(string $provider): void
    {
        $provider = self::normalizeProvider($provider);

        if (!self::isEnabled($provider)) {
            throw new \RuntimeException(self::getProviderLabel($provider) . ' está desativado no momento.');
        }

        if ($provider === 'google') {
            $clientId = trim((string)getenv('GOOGLE_OAUTH_CLIENT_ID'));
            $clientSecret = trim((string)getenv('GOOGLE_OAUTH_CLIENT_SECRET'));

            if ($clientId === '' || $clientSecret === '') {
                throw new \RuntimeException('Configure GOOGLE_OAUTH_CLIENT_ID e GOOGLE_OAUTH_CLIENT_SECRET no .env.');
            }

            return;
        }

        if ($provider === 'apple') {
            $clientId = trim((string)getenv('APPLE_SIGNIN_CLIENT_ID'));
            $teamId = trim((string)getenv('APPLE_SIGNIN_TEAM_ID'));
            $keyId = trim((string)getenv('APPLE_SIGNIN_KEY_ID'));
            $privateKey = self::resolveApplePrivateKey();
            $host = (string)parse_url(self::getBaseUrl(), PHP_URL_HOST);

            if ($clientId === '' || $teamId === '' || $keyId === '' || $privateKey === '') {
                throw new \RuntimeException('Configure APPLE_SIGNIN_CLIENT_ID, APPLE_SIGNIN_TEAM_ID, APPLE_SIGNIN_KEY_ID e a chave privada no .env.');
            }

            if (in_array(strtolower($host), ['localhost', '127.0.0.1'], true)) {
                throw new \RuntimeException('A Apple exige redirect HTTPS com domínio real. Use o login Apple em produção.');
            }

            return;
        }

        if ($provider === 'facebook') {
            $appId = trim((string)getenv('FACEBOOK_OAUTH_APP_ID'));
            $appSecret = trim((string)getenv('FACEBOOK_OAUTH_APP_SECRET'));

            if ($appId === '' || $appSecret === '') {
                throw new \RuntimeException('Configure FACEBOOK_OAUTH_APP_ID e FACEBOOK_OAUTH_APP_SECRET no .env.');
            }

            return;
        }

        throw new \RuntimeException('Provedor social não suportado.');
    }

    private static function buildGoogleAuthorizationUrl(string $state): string
    {
        $params = [
            'client_id' => trim((string)getenv('GOOGLE_OAUTH_CLIENT_ID')),
            'redirect_uri' => self::getCallbackUrl('google'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'select_account',
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    private static function buildAppleAuthorizationUrl(string $state, string $nonce): string
    {
        $params = [
            'client_id' => trim((string)getenv('APPLE_SIGNIN_CLIENT_ID')),
            'redirect_uri' => self::getCallbackUrl('apple'),
            'response_type' => 'code',
            'response_mode' => 'form_post',
            'scope' => 'name email',
            'state' => $state,
            'nonce' => $nonce,
        ];

        return 'https://appleid.apple.com/auth/authorize?' . http_build_query($params);
    }

    private static function buildFacebookAuthorizationUrl(string $state): string
    {
        $params = [
            'client_id' => trim((string)getenv('FACEBOOK_OAUTH_APP_ID')),
            'redirect_uri' => self::getCallbackUrl('facebook'),
            'state' => $state,
            'scope' => 'email,public_profile',
            'response_type' => 'code',
        ];

        return 'https://www.facebook.com/dialog/oauth?' . http_build_query($params);
    }

    private static function exchangeGoogleCode(string $code): array
    {
        $tokenResponse = self::postForm('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => trim((string)getenv('GOOGLE_OAUTH_CLIENT_ID')),
            'client_secret' => trim((string)getenv('GOOGLE_OAUTH_CLIENT_SECRET')),
            'redirect_uri' => self::getCallbackUrl('google'),
            'grant_type' => 'authorization_code',
        ]);

        if (empty($tokenResponse['access_token'])) {
            throw new \RuntimeException('O Google não retornou access_token.');
        }

        $profile = self::getJson('https://openidconnect.googleapis.com/v1/userinfo', [
            'Authorization: Bearer ' . $tokenResponse['access_token'],
        ]);

        $email = strtolower(trim((string)($profile['email'] ?? '')));
        if ($email === '') {
            throw new \RuntimeException('O Google não retornou um e-mail válido.');
        }

        $emailVerified = $profile['email_verified'] ?? true;
        if (!in_array($emailVerified, [true, 'true', 1, '1'], true)) {
            throw new \RuntimeException('O e-mail da conta Google ainda não foi verificado.');
        }

        [$firstName, $lastName] = self::splitName((string)($profile['name'] ?? ''));

        return [
            'provider' => 'google',
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => trim((string)($profile['name'] ?? '')),
        ];
    }

    private static function exchangeAppleCode(string $code, string $nonce, array $callbackData): array
    {
        $tokenResponse = self::postForm('https://appleid.apple.com/auth/token', [
            'client_id' => trim((string)getenv('APPLE_SIGNIN_CLIENT_ID')),
            'client_secret' => self::buildAppleClientSecret(),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => self::getCallbackUrl('apple'),
        ]);

        $idToken = (string)($tokenResponse['id_token'] ?? '');
        if ($idToken === '') {
            throw new \RuntimeException('A Apple não retornou id_token.');
        }

        $claims = self::decodeJwtPayload($idToken);
        self::assertAppleClaims($claims, $nonce);

        $email = strtolower(trim((string)($claims['email'] ?? '')));
        if ($email === '') {
            throw new \RuntimeException('A Apple não retornou um e-mail válido.');
        }

        $userPayload = [];
        if (!empty($callbackData['user']) && is_string($callbackData['user'])) {
            $decodedUser = json_decode($callbackData['user'], true);
            if (is_array($decodedUser)) {
                $userPayload = $decodedUser;
            }
        }

        $firstName = trim((string)($userPayload['name']['firstName'] ?? ''));
        $lastName = trim((string)($userPayload['name']['lastName'] ?? ''));

        if ($firstName === '' && $lastName === '') {
            [$firstName, $lastName] = self::splitName((string)($claims['email'] ?? ''));
        }

        return [
            'provider' => 'apple',
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => trim($firstName . ' ' . $lastName),
        ];
    }

    private static function exchangeFacebookCode(string $code): array
    {
        $tokenResponse = self::getJson('https://graph.facebook.com/oauth/access_token?' . http_build_query([
            'client_id' => trim((string)getenv('FACEBOOK_OAUTH_APP_ID')),
            'client_secret' => trim((string)getenv('FACEBOOK_OAUTH_APP_SECRET')),
            'redirect_uri' => self::getCallbackUrl('facebook'),
            'code' => $code,
        ]));

        $accessToken = trim((string)($tokenResponse['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new \RuntimeException('O Facebook não retornou access_token.');
        }

        $profile = self::getJson('https://graph.facebook.com/me?' . http_build_query([
            'fields' => 'id,name,first_name,last_name,email',
            'access_token' => $accessToken,
        ]));

        $email = strtolower(trim((string)($profile['email'] ?? '')));
        if ($email === '') {
            throw new \RuntimeException('O Facebook nao retornou um e-mail valido. Verifique se o app solicitou a permissao de e-mail.');
        }

        $firstName = trim((string)($profile['first_name'] ?? ''));
        $lastName = trim((string)($profile['last_name'] ?? ''));

        if ($firstName === '' && $lastName === '') {
            [$firstName, $lastName] = self::splitName((string)($profile['name'] ?? $email));
        }

        return [
            'provider' => 'facebook',
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => trim((string)($profile['name'] ?? trim($firstName . ' ' . $lastName))),
        ];
    }

    private static function assertAppleClaims(array $claims, string $nonce): void
    {
        $issuer = (string)($claims['iss'] ?? '');
        $audience = $claims['aud'] ?? '';
        $expiresAt = (int)($claims['exp'] ?? 0);
        $tokenNonce = (string)($claims['nonce'] ?? '');

        if ($issuer !== 'https://appleid.apple.com') {
            throw new \RuntimeException('O token retornado pela Apple tem emissor inválido.');
        }

        if ($audience !== trim((string)getenv('APPLE_SIGNIN_CLIENT_ID'))) {
            throw new \RuntimeException('O token retornado pela Apple não pertence a este aplicativo.');
        }

        if ($expiresAt > 0 && $expiresAt < time()) {
            throw new \RuntimeException('O token retornado pela Apple já expirou.');
        }

        if ($nonce !== '' && $tokenNonce !== '' && !hash_equals($nonce, $tokenNonce)) {
            throw new \RuntimeException('A resposta da Apple não passou na validação de nonce.');
        }
    }

    private static function buildAppleClientSecret(): string
    {
        $teamId = trim((string)getenv('APPLE_SIGNIN_TEAM_ID'));
        $clientId = trim((string)getenv('APPLE_SIGNIN_CLIENT_ID'));
        $keyId = trim((string)getenv('APPLE_SIGNIN_KEY_ID'));
        $privateKey = self::resolveApplePrivateKey();
        $now = time();

        return JWT::encode([
            'iss' => $teamId,
            'iat' => $now,
            'exp' => $now + 3600,
            'aud' => 'https://appleid.apple.com',
            'sub' => $clientId,
        ], $privateKey, 'ES256', $keyId);
    }

    private static function resolveApplePrivateKey(): string
    {
        $inlineKey = trim((string)getenv('APPLE_SIGNIN_PRIVATE_KEY'));
        if ($inlineKey !== '') {
            return str_replace('\n', PHP_EOL, $inlineKey);
        }

        $path = trim((string)getenv('APPLE_SIGNIN_PRIVATE_KEY_PATH'));
        if ($path === '' || !is_file($path)) {
            return '';
        }

        $contents = @file_get_contents($path);

        return is_string($contents) ? $contents : '';
    }

    private static function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            throw new \RuntimeException('Token JWT inválido.');
        }

        $payload = json_decode(self::base64UrlDecode($parts[1]), true);

        if (!is_array($payload)) {
            throw new \RuntimeException('Não foi possível ler o token retornado pelo provedor.');
        }

        return $payload;
    }

    private static function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new \RuntimeException('Falha ao decodificar token JWT.');
        }

        return $decoded;
    }

    private static function splitName(string $fullName): array
    {
        $fullName = trim($fullName);

        if ($fullName === '') {
            return ['Cliente', ''];
        }

        if (str_contains($fullName, '@')) {
            $fullName = preg_replace('/@.*/', '', $fullName) ?? $fullName;
            $fullName = str_replace(['.', '_', '-'], ' ', $fullName);
            $fullName = ucwords(trim($fullName));
        }

        $parts = preg_split('/\s+/', $fullName) ?: [];
        $firstName = trim((string)array_shift($parts));
        $lastName = trim(implode(' ', $parts));

        return [$firstName !== '' ? $firstName : 'Cliente', $lastName];
    }

    private static function postForm(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new \RuntimeException('Não foi possível conectar ao provedor social.');
        }

        $payload = json_decode($response, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('A resposta do provedor social não pôde ser lida.');
        }

        if ($statusCode >= 400) {
            $error = (string)($payload['error_description'] ?? $payload['error'] ?? 'erro_desconhecido');
            throw new \RuntimeException('O provedor social recusou a autenticação: ' . $error);
        }

        return $payload;
    }

    private static function getJson(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new \RuntimeException('Não foi possível consultar o perfil social.');
        }

        $payload = json_decode($response, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('A resposta de perfil do provedor não pôde ser lida.');
        }

        if ($statusCode >= 400) {
            throw new \RuntimeException('O provedor social não liberou o perfil do usuário.');
        }

        return $payload;
    }

    private static function getBaseUrl(): string
    {
        if (defined('URL')) {
            return rtrim((string)URL, '/');
        }

        return rtrim((string)getenv('URL'), '/');
    }
}
