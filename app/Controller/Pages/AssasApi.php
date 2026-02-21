<?php

namespace App\Controller\Pages;

class AssasApi
{
    private string $baseUrl;
    private string $clientSecret;

    public function __construct(string $baseUrl, string $clientSecret)
    {
        $this->baseUrl = $baseUrl;
        $this->clientSecret = $clientSecret;
    }


    public function createCob(array $request): array
    {
        return $this->send('POST', '/v3/pix/qrCodes/static', $request);
    }

    public function consultStatusCob(): array
    {
        return $this->send('GET', '/webhooks');
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
        ]);

        if (in_array($method, ['POST', 'PUT']) && !empty($request)) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($request));
        }

        $response = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            return ['error' => $error];
        }

        $responseData = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Erro ao decodificar JSON: ' . json_last_error_msg(), 'raw' => $response];
        }
        return $responseData;
    }


}
