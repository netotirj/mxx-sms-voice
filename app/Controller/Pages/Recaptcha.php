<?php

namespace App\Controller\Pages;

class Recaptcha
{
    public static function isTurnstileEnabled(): bool
    {
        return self::isTurnstileConfigured() && !self::isLocalEnvironment();
    }

    public static function isTurnstileConfigured(): bool
    {
        return trim((string)getenv('TURNSTILE_SITE_KEY')) !== '';
    }

    private static function isLocalEnvironment(): bool
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $envUrlHost = strtolower((string)(parse_url((string)getenv('URL'), PHP_URL_HOST) ?: ''));

        foreach ([$host, $envUrlHost] as $value) {
            $value = preg_replace('/:\d+$/', '', $value) ?? $value;

            if (in_array($value, ['localhost', '127.0.0.1', '::1'], true)) {
                return true;
            }
        }

        return false;
    }

    public static function getTurnstileSiteKey(): string
    {
        return trim((string)getenv('TURNSTILE_SITE_KEY'));
    }

    public static function verifyTurnstile(string $captchaResponse, ?string $remoteIp = null): bool
    {
        $verifyUrl = getenv('TURNSTILE_VERIFY_URL') ?: 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
        $secretKey = trim((string)getenv('TURNSTILE_SECRET_KEY'));

        if ($captchaResponse === '' || $secretKey === '') {
            return false;
        }

        if (!$remoteIp && isset($_SERVER['REMOTE_ADDR'])) {
            $remoteIp = $_SERVER['REMOTE_ADDR'];
        }

        $data = [
            'secret' => $secretKey,
            'response' => $captchaResponse,
        ];

        if ($remoteIp) {
            $data['remoteip'] = $remoteIp;
        }

        $ch = curl_init($verifyUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false || $error !== '') {
            return false;
        }

        $response = json_decode($result, true);

        return is_array($response) && ($response['success'] ?? false) === true;
    }

    /**
     * Valida o token do reCAPTCHA no Google
     *
     * @param string $captchaResponse  Token vindo do POST (g-recaptcha-response)
     * @param string|null $remoteIp    IP do usuário (opcional)
     * @return bool
     */
    public static function verify(string $captchaResponse, ?string $remoteIp = null): bool
    {
        $VERIFY_URL = getenv('VERIFY_URL_RECAPTCHA') ?: "https://www.google.com/recaptcha/api/siteverify";
        $SECRET_KEY = getenv('SECRET_KEY_RECAPTCHA');

        if (empty($captchaResponse) || empty($SECRET_KEY)) {
            return false;
        }

        $data = [
            'secret'   => $SECRET_KEY,
            'response' => $captchaResponse,
        ];

         // Detecta IP real mesmo com Cloudflare
        
	if (!$remoteIp && isset($_SERVER['REMOTE_ADDR'])) {
            $remoteIp = $_SERVER['REMOTE_ADDR'];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $VERIFY_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        

        $result = curl_exec($ch);

        $err = curl_error($ch);

        curl_close($ch);

        if ($err) {
            return false;
        }

        $response = json_decode($result, true);

        return isset($response['success']) && $response['success'] === true;
    }
}
