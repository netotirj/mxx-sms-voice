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

        foreach ([$host] as $value) {
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
        self::configureCurlCertificates($ch);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false || $error !== '') {
            error_log('Turnstile verification failed by cURL: ' . $error);
            return false;
        }

        $response = json_decode($result, true);

        if (!is_array($response)) {
            error_log('Turnstile verification returned invalid JSON: ' . $result);
            return false;
        }

        if (($response['success'] ?? false) !== true) {
            error_log('Turnstile verification rejected: ' . json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return false;
        }

        return true;
    }

    private static function configureCurlCertificates($ch): void
    {
        if (!is_resource($ch) && !($ch instanceof \CurlHandle)) {
            return;
        }

        $configuredCa = trim((string)(ini_get('curl.cainfo') ?: ini_get('openssl.cafile')));
        if ($configuredCa !== '') {
            return;
        }

        foreach (self::getCaBundleCandidates() as $candidate) {
            if (is_file($candidate)) {
                curl_setopt($ch, CURLOPT_CAINFO, $candidate);
                return;
            }
        }
    }

    private static function getCaBundleCandidates(): array
    {
        $phpDir = defined('PHP_BINARY') && PHP_BINARY !== '' ? dirname(PHP_BINARY) : '';
        $parentPhpDir = $phpDir !== '' ? dirname($phpDir) : '';

        return array_filter(array_unique([
            (string)getenv('PHP_CURL_CAINFO'),
            (string)getenv('CURL_CA_BUNDLE'),
            (string)getenv('SSL_CERT_FILE'),
            $phpDir !== '' ? $phpDir . DIRECTORY_SEPARATOR . 'cacert.pem' : '',
            $parentPhpDir !== '' ? $parentPhpDir . DIRECTORY_SEPARATOR . 'cacert.pem' : '',
            'C:' . DIRECTORY_SEPARATOR . 'wamp64' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'cacert.pem',
        ]));
    }
}
