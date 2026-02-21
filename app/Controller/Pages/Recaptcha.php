<?php

namespace App\Controller\Pages;

class Recaptcha
{
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

