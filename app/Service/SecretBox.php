<?php

namespace App\Service;

use App\Config\TelephonyConfig;

class SecretBox
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain;
        }

        if (str_starts_with($plain, self::PREFIX)) {
            return $plain;
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plain,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Falha ao proteger credencial.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(?string $secret): ?string
    {
        if ($secret === null || $secret === '') {
            return $secret;
        }

        if (!str_starts_with($secret, self::PREFIX)) {
            return $secret;
        }

        $raw = base64_decode(substr($secret, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('Credencial protegida inválida.');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plain = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plain === false) {
            throw new \RuntimeException('Não foi possível abrir credencial protegida.');
        }

        return $plain;
    }

    private static function key(): string
    {
        $material = (string)TelephonyConfig::env('WHATSAPP_TOKEN_ENCRYPTION_KEY', '');
        if ($material === '') {
            $material = (string)TelephonyConfig::env('APP_KEY', '');
        }
        if ($material === '') {
            $material = (string)TelephonyConfig::env('JWT_SECRET', '');
        }

        if ($material === '') {
            throw new \RuntimeException('Configure WHATSAPP_TOKEN_ENCRYPTION_KEY no .env antes de salvar credenciais WhatsApp.');
        }

        return hash('sha256', $material, true);
    }
}
