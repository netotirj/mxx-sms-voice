<?php

namespace App\Controller\Pages;

class DisproClient
{
    private string $endpoint;
    private array $headers;
    private ?string $lastError = null;
    private int $lastHttpCode = 0;

    public function __construct()
    {
        $this->endpoint = getenv('DISPROURL') ?: 'https://apihttp.disparopro.com.br:8433/mt';
        $keyPro = getenv('DISPROKEY');
        $this->headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $keyPro
        ];
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastHttpCode(): int
    {
        return $this->lastHttpCode;
    }

    public function send(array $messages): ?array
    {
        $this->lastError = null;
        $this->lastHttpCode = 0;

        if (empty(getenv('DISPROKEY'))) {
            $this->lastError = 'Chave DISPROKEY não configurada.';
            return null;
        }

        $payload = json_encode($messages, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            $this->lastError = 'Erro ao montar JSON: ' . json_last_error_msg();
            return null;
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $this->headers,

            // 🔒 Força TLS
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        ]);
        self::configureCurlCertificates($curl);

        $response = curl_exec($curl);
        $this->lastHttpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $errorMsg = curl_error($curl);
            $errorNo = curl_errno($curl);
            curl_close($curl);

            $this->lastError = "Erro cURL #$errorNo: $errorMsg";
            error_log($this->lastError);
            return null;
        }
        curl_close($curl);

        $responseArray = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->lastError = "Erro ao decodificar JSON: " . json_last_error_msg();
            error_log($this->lastError . " | Resposta: " . $response);
            return null;
        }

        $apiStatus = (int)($responseArray['status'] ?? $this->lastHttpCode);
        if ($this->lastHttpCode >= 400 || in_array($apiStatus, [400, 401, 403, 500], true)) {
            $detail = $responseArray['detail'] ?? $responseArray['title'] ?? 'Erro na API DisparoPro.';
            $this->lastError = is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE);
            return null;
        }

        if (isset($responseArray['detail']) && is_array($responseArray['detail']) && array_is_list($responseArray['detail']) === false) {
            $responseArray['detail'] = [$responseArray['detail']];
        }

        return $responseArray;
    }

    public static function getBalanceDISPRO(): ?float
    {
        $url = getenv('DISPROURLBALANCE') ?: 'https://apihttp.disparopro.com.br:8433/balance';
        $key = getenv('DISPROKEY');

        if (empty($key)) {
            return null; // chave não definida
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $key
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => "GET"
        ]);
        self::configureCurlCertificates($curl);

        $response = curl_exec($curl);
        curl_close($curl);

        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return isset($data['detail']['saldo'])
            ? round((float) str_replace(',', '.', $data['detail']['saldo']), 2)
            : null;
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
