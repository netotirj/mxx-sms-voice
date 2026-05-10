<?php

namespace App\Controller\Pages;

class AssasApi
{
    private string $baseUrl;
    private string $clientSecret;

    public function __construct(string $baseUrl, string $clientSecret)
    {
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        $this->clientSecret = $clientSecret;
    }


    public function createCob(array $request): array
    {
        return $this->send('POST', '/pix/qrCodes/static', $request);
    }

    public function consultStatusCob(): array
    {
        return $this->send('GET', '/webhooks');
    }

    public function listPaymentsByPixQrCodeId(string $pixQrCodeId): array
    {
        return $this->send('GET', '/payments?pixQrCodeId=' . rawurlencode($pixQrCodeId));
    }

    private function send(string $method, string $resource, array $request = []): array
    {
        $endpoint = $this->baseUrl . $resource;
        $headers = [
            'Cache-Control: no-cache',
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: MxxSolutions-SMS/1.0',
            'access_token: ' . $this->clientSecret
        ];

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            // ❌ não usa caminho fixo de Windows
            CURLOPT_SSL_VERIFYPEER => true,  // garante HTTPS
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
        ]);

        if (in_array($method, ['POST', 'PUT']) && !empty($request)) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($request));
        }

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($error) {
            return ['error' => 'Falha de comunicação com o provedor PIX.', 'status' => $httpCode];
        }

        $responseData = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Resposta inválida do provedor PIX.', 'status' => $httpCode];
        }
        if ($httpCode >= 400) {
            return ['error' => 'Provedor PIX retornou erro HTTP.', 'status' => $httpCode, 'errors' => $responseData['errors'] ?? null];
        }
        return $responseData;
    }

    private static function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return 'https://api.asaas.com/v3';
        }

        return preg_match('#/v3$#i', $baseUrl) ? $baseUrl : $baseUrl . '/v3';
    }

}
