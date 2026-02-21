<?php

namespace App\Service;

use GuzzleHttp\Client;

class VoiceOriginateOld
{
    private Client $client;
    private string $ariBase;
    private string $stasisApp = 'app-asterisk';

    public function __construct()
    {
        // base sem /channels para montar igual ao antigo
        $this->ariBase = "http://192.168.1.8:8088/ari/";

        $this->client = new Client([
            'auth' => ['maxx', 'mxx123'],
            'timeout' => 10,
            'http_errors' => false,
        ]);
    }

    /**
     * Originate 100% idêntico ao originateCalls()
     */
    public function originate(array $data): bool
    {

        try {

            // ==========================================
            // 🔹 Extrai dados do payload
            // ==========================================
            $number         = $data['phone'];
            $mainAudio      = $data['audio']['main'] ?? null;
            $dtmf           = $data['audio']['dtmf'] ?? []; // AGORA correto
            $userId         = $data['user_id'];
            $role           = $data['role'];
            $tenantId       = $data['tenant_id'];
            $callerId       = $data['caller_id'];
            $smsCost        = $data['sms_cost'] ?? 0;
            $callMinuteCost = $data['call_minute_cost'] ?? 0;
            $torpedoCost    = $data['torpedo_cost'] ?? 0;
            $variableType   = $data['variable_type'] ?? 'voice';
            $trunkUsed      = $data['trunk'];
            $jobId          = $data['job_id'] ?? null;
            $callId         = $data['call_id'] ?? null;
            $campaignId     = $data['campaign_id'] ?? null;
            $taxaOfService  = $data['taxa_of_service'] ?? 0;

            // ✅ action vem do payload (ex: transfer_only)
            $action = strtolower(trim((string)($data['action'] ?? 'dtmf')));

            // ✅ reserved_agent (8 dígitos) se existir
            $reservedAgent = $data['reserved_agent'] ?? null;
            $reservedAgent = $reservedAgent ? preg_replace('/\D/', '', (string)$reservedAgent) : null;
            if ($reservedAgent && !preg_match('/^\d{8}$/', $reservedAgent)) {
                $reservedAgent = null;
            }

            // ==========================================
            // 🔹 Ajuste do tipo
            // ==========================================
            if ($variableType === 'voice') {
                $variableType = 'NORMAL';
            }

            // ==========================================
            // 🔹 Caminho do áudio igual ao antigo
            // ==========================================
            if ($mainAudio && !str_starts_with($mainAudio, 'sound:voice/')) {
                $mainAudio = "sound:voice/{$mainAudio}";
            }

            // ==========================================
            // 🔹 Variáveis enviadas ao Asterisk
            // ==========================================
            $variables = [
                'OWNER_ID'         => (string)$userId,
                'ROLE'             => (string)$role,
                'TENANT_ID'        => (string)$tenantId,
                'EXTENSION'        => (string)$number,
                'TRUNK'            => (string)$trunkUsed,
                'CALL_MINUTE_COST' => (string)$callMinuteCost,
                'TORPEDO_COST'     => (string)$torpedoCost,
                'SMS_COST'         => (string)$smsCost,
                'TAXA_OF_SERVICE'  => (string)$taxaOfService,
                'VARIABLE_TYPE'    => (string)$variableType,
                'JOB_ID'           => (string)$jobId,
                'CALL_ID'          => (string)$callId,
                'CAMPAIGN_ID'      => (string)$campaignId,
                'CAMPAIGN_TYPE'    => (string)$variableType,
                'CALLERID(num)'    => (string)$callerId,
                'CALLERID(name)'   => (string)$callerId,

                // ✅ CRÍTICO: action também como variável do canal
                'ACTION'           => (string)$action,

                // ✅ opcional: compatibilidade caso seu stasis leia outro nome
                'TORPEDO_TYPE'     => (string)$action,
            ];

            // ✅ envia RESERVED_AGENT quando houver reserva
            if ($reservedAgent) {
                $variables['RESERVED_AGENT'] = (string)$reservedAgent;
            }

            // ==========================================
            // 🔹 Stasis Args igual ao originateCalls()
            // ==========================================
            $appArgs = json_encode([
                'action' => $action,
                'audio'  => $mainAudio,
                'dtmf'   => $dtmf
            ], JSON_UNESCAPED_SLASHES);

            // ==========================================
            // 🔹 Payload idêntico ao seu sistema antigo
            // ==========================================
            $payload = [
                'endpoint'  => "PJSIP/{$number}@{$trunkUsed}",
                'extension' => $number,
                'timeout'   => 60,
                'callerId'  => $callerId,
                'app'       => $this->stasisApp,
                'appArgs'   => $appArgs,
                'variables' => $variables
            ];

            // ==========================================
            // 🔥 POST EXATAMENTE COMO O ANTIGO
            // ==========================================
            $response = $this->client->post(
                "{$this->ariBase}channels",
                [
                    'json'    => $payload,
                    'headers' => ['Content-Type' => 'application/json']
                ]
            );

            return in_array($response->getStatusCode(), [200, 201, 202], true);

        } catch (\Throwable $e) {
            error_log("Originate failed: " . $e->getMessage());
            return false;
        }
    }
}
