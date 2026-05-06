<?php

namespace App\Service;

use App\Config\TelephonyConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

class VoiceOriginate
{
    private Client $client;
    private string $ariBase;
    private string $stasisApp = 'app-asterisk';

    public function __construct()
    {
        $this->ariBase = TelephonyConfig::ariBaseUrl();
        $this->stasisApp = TelephonyConfig::stasisApp();

        $this->client = new Client([
            'auth'        => TelephonyConfig::ariAuth(),
            'timeout'     => 10.0,     // total
            'connect_timeout' => 3.0,  // conecta rápido
            'http_errors' => false,    // NÃO lançar por 4xx/5xx
        ]);
    }

    /**
     * ✅ Retorno rico para monitorar HTTP/timeout/conexão.
     */
    public function originate(array $data): array
    {
        $t0 = microtime(true);


        // ==========================================
        // 🔹 Extrai dados do payload
        // ==========================================
        $number         = $data['phone'];
        $mainAudio      = $data['audio']['main'] ?? null;
        $dtmf           = $data['audio']['dtmf'] ?? [];
        $userId         = $data['user_id'];
        $record_calls   = $data['record_calls'] ?? 0;
        $role           = $data['role'];
        $tenantId       = $data['tenant_id'];
        $callerId       = $data['caller_id'];
        $smsCost        = $data['sms_cost'] ?? 0;
        $callMinuteCost = $data['call_minute_cost'] ?? 0;
        $torpedoCost    = $data['torpedo_cost'] ?? 0;
        $variableType   = $data['variable_type'] ?? 'voice';
        $trunkUsed      = $data['trunk'];
        $trunkId        = $data['trunk_id'] ?? $trunkUsed;
        $trunkName      = $data['trunk_name'] ?? $trunkUsed;
        $trunkBillingType = $data['trunk_billing_type'] ?? '';
        $planId         = $data['plan_id'] ?? '';
        $tariffUsed     = $data['tariff_used'] ?? $callMinuteCost;
        $techPrefix     = trim($data['tech_prefix'] ?? '');
        $jobId          = $data['job_id'] ?? null;
        $callId         = $data['call_id'] ?? null;
        $campaignId     = $data['campaign_id'] ?? null;
        $queueId        = $data['queue_id'] ?? null;
        $taxaOfService  = $data['taxa_of_service'] ?? 0;
        $voiceListId    = $data['voice_list_id'] ?? null;

        $action = strtolower(trim((string)($data['action'] ?? 'dtmf')));

        $reservedAgent = $data['reserved_agent'] ?? null;
        $reservedAgent = $reservedAgent ? preg_replace('/\D/', '', (string)$reservedAgent) : null;
        if ($reservedAgent && !preg_match('/^\d{8}$/', $reservedAgent)) {
            $reservedAgent = null;
        }

        if ($variableType === 'voice') {
            $variableType = 'NORMAL';
        }

        if ($mainAudio && !str_starts_with($mainAudio, 'sound:voice/')) {
            $mainAudio = "sound:voice/{$mainAudio}";
        }

        $variables = [
            'OWNER_ID'         => (string)$userId,
            'ROLE'             => (string)$role,
            'TENANT_ID'        => (string)$tenantId,
            '__RECORD_CALLS'   => (string)$record_calls,
            'EXTENSION'        => (string)$number,
            'TRUNK'            => (string)$trunkName,
            'TRUNK_ID'         => (string)$trunkId,
            'TRUNK_BILLING_TYPE' => (string)$trunkBillingType,
            'PLAN_ID'          => (string)$planId,
            'TARIFF_USED'      => (string)$tariffUsed,
            'TECHPREFIX'       => (string)$techPrefix,
            'CALL_MINUTE_COST' => (string)$callMinuteCost,
            'TORPEDO_COST'     => (string)$torpedoCost,
            'SMS_COST'         => (string)$smsCost,
            'TAXA_OF_SERVICE'  => (string)$taxaOfService,
            'VARIABLE_TYPE'    => (string)$variableType,
            'JOB_ID'           => (string)$jobId,
            'CALL_ID'          => (string)$callId,
            'CAMPAIGN_ID'      => (string)$campaignId,
            'QUEUE_ID'         => (string)$queueId,
            'CAMPAIGN_TYPE'    => (string)$variableType,
            'CALLERID(num)'    => (string)$callerId,
            'CALLERID(name)'   => (string)$callerId,
            'ACTION'           => (string)$action,
            'TORPEDO_TYPE'     => (string)$action,
            'VOICE_LIST_ID'    => (string)$voiceListId
        ];

        if ($reservedAgent) {
            $variables['RESERVED_AGENT'] = (string)$reservedAgent;
        }

        $appArgs = json_encode([
            'action' => $action,
            'audio'  => $mainAudio,
            'dtmf'   => $dtmf
        ], JSON_UNESCAPED_SLASHES);

        $payload = [
            'endpoint'  => "PJSIP/{$techPrefix}{$number}@{$trunkUsed}",
            //'endpoint'  => "PJSIP/{$number}@{$trunkUsed}",
            'extension' => $number, // número limpo
            'timeout'   => 60,
            'callerId'  => $callerId,
            'app'       => $this->stasisApp,
            'appArgs'   => $appArgs,
            'variables' => $variables
        ];

        $uri = "{$this->ariBase}channels";

        try {
            $response = $this->client->post($uri, [
                'json'    => $payload,
                'headers' => ['Content-Type' => 'application/json'],
            ]);

            $lat = (int)round((microtime(true) - $t0) * 1000);
            $status = $response->getStatusCode();

            return [
                'ok'         => in_array($status, [200, 201, 202], true),
                'http_status'=> $status,
                'latency_ms' => $lat,
                'uri'        => $uri,
                'endpoint'   => '/channels',
                'host'       => parse_url($this->ariBase, PHP_URL_HOST) ?: null,
                'body'       => $this->safeBody($response),
            ];

        } catch (ConnectException $e) {
            // ✅ timeout / refused / dns etc (nível de transporte)
            $lat = (int)round((microtime(true) - $t0) * 1000);

            return [
                'ok'              => false,
                'http_status'     => null,
                'latency_ms'      => $lat,
                'uri'             => $uri,
                'endpoint'        => '/channels',
                'host'            => parse_url($this->ariBase, PHP_URL_HOST) ?: null,
                'exception_class' => get_class($e),
                'error'           => $e->getMessage(),
                // para classificar: timeout/conexao/dns
                'connect_error'   => true,
            ];

        } catch (RequestException $e) {
            // pode ter response (4xx/5xx) OU ser erro de request sem response
            $lat = (int)round((microtime(true) - $t0) * 1000);
            $resp = $e->getResponse();

            return [
                'ok'              => false,
                'http_status'     => $resp ? $resp->getStatusCode() : null,
                'latency_ms'      => $lat,
                'uri'             => $uri,
                'endpoint'        => '/channels',
                'host'            => parse_url($this->ariBase, PHP_URL_HOST) ?: null,
                'exception_class' => get_class($e),
                'error'           => $e->getMessage(),
                'body'            => $resp ? $this->safeBody($resp) : null,
            ];

        } catch (\Throwable $e) {
            $lat = (int)round((microtime(true) - $t0) * 1000);

            return [
                'ok'              => false,
                'http_status'     => null,
                'latency_ms'      => $lat,
                'uri'             => $uri,
                'endpoint'        => '/channels',
                'host'            => parse_url($this->ariBase, PHP_URL_HOST) ?: null,
                'exception_class' => get_class($e),
                'error'           => $e->getMessage(),
            ];
        }
    }

    /**
     * ✅ Compat: se alguma parte ainda espera bool.
     */
    public function originateBool(array $data): bool
    {
        return !empty($this->originate($data)['ok']);
    }

    private function safeBody(ResponseInterface $resp): string
    {
        // cuidado com stream: converte com limite
        $body = (string)$resp->getBody();
        if (strlen($body) > 1500) $body = substr($body, 0, 1500) . '...';
        return $body;
    }
}
