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

        // 🔹 Monta payload para cada número
        $payload = [];
        foreach ((array)$phone as $numero) {
            $payload[] = [
                "numero"      => $numero,
                "servico"     => "short",
                "mensagem"    => $message,
                "parceiro_id" => "5034e65a0c", // ajustar se precisar ser dinâmico
                "codificacao" => "0"
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
            CURLOPT_CUSTOMREQUEST  => "POST",
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,   // ativa verificação SSL
            CURLOPT_SSL_VERIFYHOST => 2,      // garante que host corresponde ao certificado
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,

            // 🚨 Faz cURL retornar false em códigos HTTP >= 400
            CURLOPT_FAILONERROR => true
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return "Erro ao enviar SMS: " . $err;
        }

        return true;
    }


}
