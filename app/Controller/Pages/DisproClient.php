<?php

namespace App\Controller\Pages;

class DisproClient
{
    private string $endpoint;
    private array $headers;

    public function __construct()
    {
        $this->endpoint = getenv('DISPROURL') ?: 'https://apihttp.disparopro.com.br:8433/mt';
        $keyPro = getenv('DISPROKEY');
        $this->headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $keyPro
        ];
    }


    public function send(array $messages): ?array
    {
        $payload = json_encode($messages);

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $this->headers,

            // 🔒 Força TLS
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,

            // 🚨 Faz cURL retornar false em códigos HTTP >= 400
            CURLOPT_FAILONERROR => true
        ]);

        $response = curl_exec($curl);

        if ($response === false) {
            $errorMsg = curl_error($curl);
            $errorNo = curl_errno($curl);
            curl_close($curl);

            // Loga ou trata erro
            error_log("Erro cURL #$errorNo: $errorMsg");
            return null;
        }
        curl_close($curl);

        $responseArray = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Erro ao decodificar JSON: " . json_last_error_msg());
            return null;
        }

        if (isset($responseArray['status']) && in_array($responseArray['status'], [400, 401, 403, 500])) {
            return null;
        }
        return $responseArray;
    }

    public static function getBalanceDISPRO(): ?float
    {
        $url = getenv('DISPROURLBALANCE') ?: 'https://apihttp.disparopro.com.br/balance';
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


}
