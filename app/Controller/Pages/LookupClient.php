<?php

namespace App\Controller\Pages;

class LookupClient
{
    private string $cpfEndpoint;
    private string $nameEndpoint;
    private string $phoneEndpoint;

    private string $cpfToken;
    private string $phoneToken;

    public function __construct()
    {
        $this->cpfEndpoint   = getenv('CONSULTA_API_CPF') ?: '';
        $this->nameEndpoint  = getenv('CONSULTA_API_NOME') ?: '';
        $this->phoneEndpoint = getenv('CONSULTA_API_TEL') ?: '';

        $this->cpfToken   = getenv('CONSULTA_TOKEN_CPF') ?: '';
        $this->phoneToken = getenv('CONSULTA_TOKEN_TEL') ?: '';
    }

    /**
     * 🔎 Lookup by CPF
     */
    public function lookupCpf(string $cpf): ?array
    {
        $cpf = preg_replace('/\D/', '', $cpf);

        $url = $this->cpfEndpoint . '?cpf=' . urlencode($cpf) . '&token=' . urlencode($this->cpfToken);

        return $this->request($url);
    }

    /**
     * 🔎 Lookup by name
     */
    public function lookupName(string $name): ?array
    {
        $url = $this->nameEndpoint . '?nome=' . urlencode($name);

        return $this->request($url);
    }

    /**
     * 📞 Lookup by phone
     */
    public function lookupPhone(string $phone): ?array
    {

        //echo "<pre>";
        //print_r($phone);
        //echo "</pre>";exit();

        $phone = preg_replace('/\D/', '', $phone);

        $url = $this->phoneEndpoint . '?telefone=' . urlencode($phone) . '&token=' . urlencode($this->phoneToken);

        return $this->request($url);
    }

    /**
     * 🔧 Internal request handler
     */
    private function request(string $url): ?array
    {
        if (empty($url)) return null;

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CUSTOMREQUEST => "GET",

            // API is HTTP, so disable SSL checks
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,

            CURLOPT_FAILONERROR => true
        ]);

        $response = curl_exec($curl);

        if ($response === false) {
            $errorMsg = curl_error($curl);
            $errorNo  = curl_errno($curl);
            curl_close($curl);

            error_log("Lookup API Error #$errorNo: $errorMsg | URL: $url");
            return null;
        }

        curl_close($curl);

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON Decode Error: " . json_last_error_msg());
            return null;
        }

        return $data;
    }
}