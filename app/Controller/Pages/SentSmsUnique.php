<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\RegisterTenancies;
use App\Model\Entity\UserPlans;
use App\Session\User as SessionUser;

class SentSmsUnique
{
    /**
     * Envia SMS via API DisparoPro.
     *
     * @param string|array $phone Número de telefone com DDI (ex: 5511999887744)
     * @param string $message Texto da mensagem
     *
     * @return bool|string Retorna true em caso de sucesso ou a mensagem de erro
     */
    public static function send(string|array $phone, string $message): bool|string
    {
        $endpoint = getenv('DISPROURL') ?: 'https://apihttp.disparopro.com.br:8433/mt';
        $apiKey   = getenv('DISPROKEY');

        if (empty($apiKey)) {
            return 'Chave DISPROKEY não configurada.';
        }

        $message = trim($message);
        if ($message === '' || mb_strlen($message, 'UTF-8') > 1377) {
            return 'Mensagem vazia ou acima do limite permitido.';
        }

        $payload = [];
        foreach ((array)$phone as $numero) {
            $numero = self::normalizePhone((string)$numero);
            if (!self::isValidPhone($numero)) {
                return "Número inválido: {$numero}";
            }

            $payload[] = [
                "numero"      => $numero,
                "servico"     => "short",
                "mensagem"    => $message,
                "parceiro_id" => substr('reset-' . bin2hex(random_bytes(8)), 0, 100),
                "codificacao" => "0",
                "nome_campanha" => "Reset de senha"
            ];
        }

        $headers = [
            "Authorization: Bearer {$apiKey}",
            "Content-Type: application/json"
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING        => "",
            CURLOPT_MAXREDIRS       => 10,
            CURLOPT_TIMEOUT         => 30,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => "POST",
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,   // ativa verificação SSL
            CURLOPT_SSL_VERIFYHOST => 2,      // garante que host corresponde ao certificado
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,

        ]);
        self::configureCurlCertificates($curl);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($err) {
            return "Erro ao enviar SMS: " . $err;
        }

        $data = json_decode((string)$response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return 'Resposta inválida da API DisparoPro.';
        }

        if ($httpCode >= 400 || (int)($data['status'] ?? 200) >= 400) {
            $detail = $data['detail'] ?? $data['title'] ?? 'Erro na API DisparoPro.';
            return is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE);
        }

        $details = $data['detail'] ?? [];
        if (is_array($details) && array_is_list($details) === false) {
            $details = [$details];
        }

        foreach ($details as $detail) {
            if (strtoupper((string)($detail['status'] ?? '')) === 'ACCEPTED') {
                return true;
            }
        }

        return 'SMS não foi aceito pela API DisparoPro.';
    }

    private static function normalizePhone(string $phone): string
    {
        $phone = str_replace(',', '.', $phone);
        if (stripos($phone, 'e') !== false) {
            $phone = number_format((float)$phone, 0, '', '');
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 11) {
            $digits = '55' . $digits;
        }

        if (strlen($digits) === 13 && !str_starts_with($digits, '55')) {
            $digits = '55' . substr($digits, -11);
        }

        return $digits;
    }

    private static function isValidPhone(string $phone): bool
    {
        return str_starts_with($phone, '55') && strlen($phone) >= 12 && strlen($phone) <= 13;
    }

    private static function configureCurlCertificates($curl): void
    {
        if (!is_resource($curl) && !($curl instanceof \CurlHandle)) {
            return;
        }

        $configuredCa = trim((string)(ini_get('curl.cainfo') ?: ini_get('openssl.cafile')));
        if ($configuredCa !== '') {
            return;
        }

        foreach (self::getCaBundleCandidates() as $candidate) {
            if (is_file($candidate)) {
                curl_setopt($curl, CURLOPT_CAINFO, $candidate);
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
