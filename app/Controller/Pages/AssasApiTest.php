<?php

namespace App\Controller\Pages;

/**
 * CLASSE EXCLUSIVA PARA TESTES (SANDBOX)
 * Contém bypass de SSL para evitar erros em ambiente local
 */
class AssasApiTest
{
    private string $baseUrl;
    private string $clientSecret;

    public function __construct(string $baseUrl, string $clientSecret)
    {
        // Garante que não termine com barra para não duplicar no endpoint
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->clientSecret = $clientSecret;
    }

    public function createCob(array $request): array
    {
        // Rota correta para QR Code estático no Asaas
        return $this->send('POST', '/pix/qrCodes/static', $request);
    }

    private function send(string $method, string $resource, array $request = []): array
    {
        $endpoint = $this->baseUrl . $resource;

        $headers = [
            'Cache-Control: no-cache',
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: MxxSolutions-SMS-Test/1.0',
            'access_token: ' . $this->clientSecret
        ];

        $curl = curl_init();

        $options = [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,

            // --- O DRIBLE DO SSL ---
            CURLOPT_SSL_VERIFYPEER => false, // Desativa a verificação de peer
            CURLOPT_SSL_VERIFYHOST => false, // Desativa a verificação do host
            // -----------------------

            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30
        ];

        if (in_array($method, ['POST', 'PUT']) && !empty($request)) {
            $options[CURLOPT_POSTFIELDS] = json_encode($request);
        }

        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if ($error) {
            return [
                'error' => 'Curl Error: ' . $error,
                'status' => $httpCode
            ];
        }

        $responseData = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'error' => 'Erro ao decodificar JSON',
                'raw' => $response,
                'status' => $httpCode
            ];
        }

        return $responseData;
    }
}