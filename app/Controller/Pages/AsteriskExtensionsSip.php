<?php

namespace App\Controller\Pages;

use App\Config\TelephonyConfig;
use Firebase\JWT\JWT;


class AsteriskExtensionsSip
{
    // ======== CONFIG ========
    private const string JWT_ISSUER = 'mxx-asterisk';
    private const int JWT_TTL = 3600; // segundos
    private const int CONNECT_TIMEOUT = 3;
    private const int TIMEOUT = 10;

    // Cache simples de token por request
    private ?string $cachedJwt = null;
    private int $cachedJwtExp = 0;

    private static function baseUrl(): string
    {
        return TelephonyConfig::asteriskIndexUrl();
    }

    /**
     * Exemplo de chamada para listar extensões SIP
     */
    public function listExtensions(array $query = []): array
    {
        $query = $this->normalizeQuery($query);

        // 🔹 se o usuário for super_admin, não envia user_id nem tenant_id
        if (($query['function'] ?? null) === 'super_admin') {
            unset($query['user_id'], $query['tenant_id'], $query['tenancy_id'], $query['owner_id']);
        }

        $query['action'] = $query['action'] ?? 'extensions';
        return $this->normalizeApiResponse($this->request('GET', $query), 'extensions', true);
    }

    public function createExtension(array $query, array $payload): array
    {
        $query = $this->normalizeQuery($query);
        $payload = $this->normalizePayload($payload, 'extensions');
        $query['action'] = 'create_extension';
        return $this->normalizeApiResponse($this->request('POST', $query, $payload), 'extensions', false);
    }

    public function getExtensionById(array $query, string $id): array
    {
        $query = $this->normalizeQuery($query);

        // 🔹 Ação correta definida na sua API PHP
        $query['action'] = 'getById';
        $query['id'] = $id;

        // 🔹 Executa requisição (gera JWT e envia cabeçalho)
        $response = $this->normalizeApiResponse($this->request('GET', $query), 'extensions', false);

        // 🔹 Trata o retorno
        $status = $response['status'] ?? 0;
        $ok = $status >= 200 && $status < 300;

        if (!$ok || empty($response['data'])) {
            return [
                'ok' => false,
                'error' => $response['error'] ?? "Ramal {$id} não encontrado.",
                'data' => [],
            ];
        }

        return [
            'ok' => true,
            'data' => $response['data'],
        ];
    }

    public function updateExtension(array $query, array $payload): array
    {
        $query = $this->normalizeQuery($query);
        $payload = $this->normalizePayload($payload, 'extensions');
        $query['action'] = 'update_extension';
        return $this->normalizeApiResponse($this->request('PUT', $query, $payload), 'extensions', false);
    }

    public function updateBalance(array $query, array $payload): array
    {
        $query = $this->normalizeQuery($query);
        $payload = $this->normalizePayload($payload, 'generic');
        $attempts = [
            ['method' => 'POST', 'action' => 'adjust_balance'],
            ['method' => 'POST', 'action' => 'adjust_extension_balance'],
            ['method' => 'PATCH', 'action' => 'adjust_balance'],
            ['method' => 'PUT', 'action' => 'update_balance'],
        ];

        foreach ($attempts as $attempt) {
            $attemptQuery = $query;
            $attemptQuery['action'] = $attempt['action'];
            $response = $this->request($attempt['method'], $attemptQuery, $payload);

            if (!empty($response['ok'])) {
                return $response;
            }

            $status = (int)($response['status'] ?? 0);
            $error = strtolower((string)($response['error'] ?? ''));
            $shouldRetry = $status === 405
                || str_contains($error, 'acao post invalida')
                || str_contains($error, 'ação post inválida')
                || str_contains($error, 'acao put invalida')
                || str_contains($error, 'ação put inválida')
                || str_contains($error, 'acao patch invalida')
                || str_contains($error, 'ação patch inválida')
                || str_contains($error, 'acao post invalida')
                || str_contains($error, 'ação post inválida')
                || str_contains($error, 'acao post')
                || str_contains($error, 'ação post')
                || str_contains($error, 'method not allowed');

            if (!$shouldRetry) {
                return $response;
            }
        }

        return $response ?? [
            'ok' => false,
            'status' => 0,
            'error' => 'Falha ao sincronizar saldo com a API Asterisk.',
            'data' => null,
        ];
    }

    public function updateTariff(array $query, array $payload): array
    {
        $query = $this->normalizeQuery($query);
        $payload = $this->normalizePayload($payload, 'generic');
        $tenantId = (string)($payload['tenant_id'] ?? $payload['tenancy_id'] ?? $query['tenant_id'] ?? $query['tenancy_id'] ?? '');
        if ($tenantId === '') {
            return [
                'ok' => false,
                'status' => 400,
                'error' => 'Informe tenant_id para sincronizar as configuracoes de tarifa.',
                'data' => null,
            ];
        }

        $syncTargets = [];

        if (!empty($payload['admin']) && is_array($payload['admin'])) {
            $syncTargets[] = $payload['admin'];
        }

        if (!empty($payload['resellers']) && is_array($payload['resellers'])) {
            foreach ($payload['resellers'] as $reseller) {
                if (is_array($reseller)) {
                    $syncTargets[] = $reseller;
                }
            }
        }

        if ($syncTargets === []) {
            $syncTargets[] = $payload;
        }

        $results = [];
        $errors = [];

        foreach ($syncTargets as $target) {
            $userId = (string)($target['user_id'] ?? $target['owner_id'] ?? $query['user_id'] ?? $query['owner_id'] ?? '');
            if ($userId === '') {
                $errors[] = 'Um dos lotes de sincronizacao nao informou user_id.';
                continue;
            }

            $configPayload = [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
            ];

            foreach (['balance_admin', 'balance_reseller', 'call_minute_cost', 'service_fee'] as $field) {
                if (array_key_exists($field, $target)) {
                    $configPayload[$field] = $target[$field];
                }
            }

            $configQuery = $query;
            $configQuery['action'] = 'set_extension_config';
            $configQuery['user_id'] = $userId;
            $configQuery['tenant_id'] = $tenantId;

            $response = $this->request('POST', $configQuery, $configPayload);
            $results[] = [
                'user_id' => $userId,
                'ok' => !empty($response['ok']),
                'status' => (int)($response['status'] ?? 0),
                'error' => $response['error'] ?? null,
            ];

            if (empty($response['ok'])) {
                $errors[] = "user_id {$userId}: " . ($response['error'] ?? 'Falha ao sincronizar configuracao.');
            }
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'status' => 400,
                'error' => 'Falha ao sincronizar configuracoes na API Asterisk/Stasis: ' . implode(' | ', $errors),
                'data' => ['results' => $results],
            ];
        }

        return [
            'ok' => true,
            'status' => 200,
            'data' => [
                'success' => true,
                'message' => 'Configuracoes sincronizadas com sucesso.',
                'results' => $results,
            ],
        ];
    }

    public function deleteExtension(array $query, array $payload): array
    {
        $query = $this->normalizeQuery($query);
        $payload = $this->normalizePayload($payload, 'extensions');
        // 🔹 Define a ação esperada pela API
        $query['action'] = 'delete_extension';

        // 🔹 Executa a requisição DELETE com corpo JSON
        return $this->request('DELETE', $query, $payload);
    }

    public function updateStatus(array $query, array $payload): array
    {
        $query = $this->normalizeQuery($query);
        $payload = $this->normalizePayload($payload, 'extensions');
        // 🔹 Define a ação esperada pela API PHP
        $query['action'] = 'update_status';

        // 🔹 Executa a requisição PATCH (atualiza apenas account_status)
        return $this->request('PATCH', $query, $payload);
    }

    // ================== AUDIOS (NOVO) ==================
    public function createAudio(array $query, array $payload): array
    {
        $query = $this->normalizeQuery($query);
        $payload = $this->normalizePayload($payload, 'audios');
        // 🔹 Define a ação
        $query['action'] = 'create_audio';

        // 🔹 Garante que os identificadores estejam na URL
        if (!isset($query['user_id']) && !empty($payload['user_id'])) {
            $query['user_id'] = $payload['user_id'];
        }

        if (!isset($query['tenant_id']) && !empty($payload['tenant_id'])) {
            $query['tenant_id'] = $payload['tenant_id'];
        }

        // 🚀 Faz a requisição POST com o corpo JSON
        return $this->normalizeApiResponse($this->request('POST', $query, $payload), 'audios', false);
    }


    public function listAudios(array $query = []): array
    {
        $query = $this->normalizeQuery($query);

        // 🔹 Se o usuário for super_admin, não envia user_id nem tenant_id
        if (isset($query['function']) && strtolower($query['function']) === 'super_admin') {
            unset($query['user_id'], $query['tenant_id'], $query['tenancy_id'], $query['owner_id']);
        }

        // 🔹 Define ação padrão
        $query['action'] = $query['action'] ?? 'list_audios';

        // 🔹 Faz a requisição
        $response = $this->normalizeApiResponse($this->request('GET', $query), 'audios', true);

        //echo "<pre>";
        //print_r($response);
        //echo "</pre>";exit();

        // 🔹 Verifica se deu erro na requisição
        if (!isset($response['ok']) || !$response['ok']) {
            return [
                'ok' => false,
                'error' => $response['error'] ?? 'Erro ao listar áudios',
                'data' => [],
            ];
        }

        // 🔹 Extrai o corpo interno do retorno
        $body = $response['data'] ?? [];

        // 🔹 Extrai a lista real de áudios
        $audios = $body['data'] ?? [];

        return [
            'ok' => true,
            'status' => $body['status'] ?? 200,
            'message' => $body['message'] ?? '',
            'total' => $body['total'] ?? count($audios),
            'data' => $audios,
        ];
    }

    /**
     * Lista as gravações de chamadas (Snoop/Record) do Call Center
     * -----------------------------------------------------------
     */
    public function listRecordings(array $query = []): array
    {
        $query = $this->normalizeQuery($query);

        // 🔹 Se for super_admin, remove filtros restritivos para ver tudo
        if (isset($query['function']) && strtolower($query['function']) === 'super_admin') {
            unset($query['user_id'], $query['tenant_id'], $query['tenancy_id'], $query['owner_id']);
        }

        // 🔹 Define a ação para o backend (ex: buscar no banco CDR)
        $query['action'] = $query['action'] ?? 'list_recordings';

        // 🔹 Faz a requisição ao seu motor/API
        $response = $this->normalizeApiResponse($this->request('GET', $query), 'recordings', true);

        // 🔹 Validação da resposta
        if (!isset($response['ok']) || !$response['ok']) {
            return [
                'ok'    => false,
                'error' => $response['error'] ?? 'Erro ao listar gravações',
                'data'  => [],
            ];
        }

        $body = $response['data'] ?? [];
        $data = $body['data'] ?? [];

        // 🔹 PULO DO GATO: Gerar o Token de Áudio para cada linha
        // O caminho no disco é: tenant_XXX/user_YYY/call:ZZZ.wav
        foreach ($data as &$item) {
            if (!empty($item['call_id'])) {
                $tenantId = $item['tenancy_id'] ?? '0';
                $userId   = $item['owner_id']   ?? '0';
                $callId   = $item['call_id'];

                // Garante que o callId tenha o prefixo "call:" se não vier do banco
                $fileName = (strpos($callId, 'call:') === false) ? "call:{$callId}.wav" : "{$callId}.wav";

                // Monta a string do caminho relativo
                $pathString = "tenant_{$tenantId}/user_{$userId}/{$fileName}";

                // Gera o token Base64 para o recording.php
                $item['audio_token'] = base64_encode($pathString);

                // Link pronto para o player do Dashboard
                // Ajuste o domínio para o seu servidor
                $item['recording_url'] = "/recording.php?token=" . $item['audio_token'];
            }
        }

        return [
            'ok'      => true,
            'status'  => $body['status']  ?? 200,
            'message' => $body['message'] ?? '',
            'total'   => $body['total']   ?? count($data),
            'data'    => $data,
        ];
    }



    /**
     * Deleta um áudio do Asterisk
     */

    public function deleteAudio(array $query, array $payload): array
    {
        $query = $this->normalizeQuery($query);
        $payload = $this->normalizePayload($payload, 'audios');
        // 🔹 Define a ação esperada pela API
        $query['action'] = 'delete_audio';

        // 🔹 Executa a requisição DELETE com corpo JSON
        return $this->request('DELETE', $query, $payload);
    }


    public function listTrunks(array $query = []): array
    {
        $query = $this->normalizeQuery($query);

        // 🔹 se o usuário for super_admin, não envia user_id nem tenant_id
        if (($query['function'] ?? null) === 'super_admin') {
            unset($query['user_id'], $query['tenant_id'], $query['tenancy_id'], $query['owner_id']);
        }

        $query['action'] = 'get_trunks';
        return $this->normalizeApiResponse($this->request('GET', $query), 'trunks', true);
    }


    public function createSipTrunk(array $query, array $payload): array
    {
        $query = $this->normalizeQuery($query);
        $payload = $this->normalizePayload($payload, 'trunks');
        $query['action'] = 'create_trunk';
        return $this->normalizeApiResponse($this->request('POST', $query, $payload), 'trunks', false);
    }

    public function getTrunkById(array $query, int $id): array
    {
        $query = $this->normalizeQuery($query);

        // 🔹 Ação correta definida na sua API PHP
        $query['action'] = 'getTrunkById';
        $query['id'] = $id;
        $query['trunk_id'] = (string)$id;

        // 🔹 Executa requisição (gera JWT e envia cabeçalho)
        $response = $this->normalizeApiResponse($this->request('GET', $query), 'trunks', false);

        $row = $this->extractEntityData($response, false);

        if (!is_array($row) || $row === []) {
            $fallback = $this->findTrunkInList($query, $id);
            if ($fallback !== null) {
                return [
                    'ok' => true,
                    'status' => 200,
                    'data' => [
                        'success' => true,
                        'data' => $fallback,
                    ],
                ];
            }

            return [
                'ok' => false,
                'status' => $response['status'] ?? 404,
                'error' => $response['error'] ?? "SIP Trunk {$id} não encontrado.",
                'data' => [],
            ];
        }

        return [
            'ok' => true,
            'data' => $response['data'],
        ];
    }

    public function updateSipTrunks(array $query, array $payload): array
    {
        $query = $this->normalizeQuery($query);
        $payload = $this->normalizePayload($payload, 'trunks');
        $query['action'] = 'update_trunks';
        return $this->normalizeApiResponse($this->request('PUT', $query, $payload), 'trunks', false);
    }

    public function updateStatusSipTrunks(array $query, array $payload): array
    {
        $query = $this->normalizeQuery($query);
        $payload = $this->normalizePayload($payload, 'trunks');
        // 🔹 Define a ação esperada pela API PHP
        $query['action'] = 'status_trunks';

        // 🔹 Executa a requisição PATCH (atualiza apenas account_status)
        return $this->request('PATCH', $query, $payload);
    }

    public function deleteSipTrunks(array $query, array $payload): array
    {
        $query = $this->normalizeQuery($query);
        $payload = $this->normalizePayload($payload, 'trunks');
        // 🔹 Define a ação esperada pela API
        $query['action'] = 'delete_trunks';

        // 🔹 Executa a requisição DELETE com corpo JSON
        return $this->request('DELETE', $query, $payload);
    }


    // ================== CORE REQUEST ==================

    private function request(string $method, array $query = [], ?array $body = null): array
    {
        // 🔗 Montagem da URL com query string
        $url = self::baseUrl();

        if (!empty($query)) {
            $qs = http_build_query($query);

            $url .= str_contains($url, '?')
                ? '&' . $qs
                : '?' . $qs;
        }

        // 🔐 Gera JWT com base nos dados da requisição
        try {
            $jwt = $this->getJwt($query);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Erro ao gerar JWT: ' . $e->getMessage(),
                'data' => null,
            ];
        }

        $ch = curl_init($url);

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $jwt,
        ];

        $upperMethod = strtoupper($method);

        // 🔄 Configuração do método HTTP
        switch ($upperMethod) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                break;
            case 'PUT':
            case 'PATCH':
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $upperMethod);
                break;

            default:
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;
        }

        // 📦 Envio de corpo JSON quando aplicável
        if (in_array($upperMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE);

            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);

            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        // ⚙️ Opções do cURL
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false, // caso seja HTTPS interno
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        // 🚀 Executa requisição
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $inf = curl_getinfo($ch);

        curl_close($ch);

        // ❌ Falha de conexão
        if ($raw === false) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Erro de conexão cURL: ' . $err,
                'info' => $inf,
            ];
        }

        // 📦 Decodifica JSON com segurança
        $decoded = json_decode($raw, true);

        $status = (int)($inf['http_code'] ?? 0);
        $ok = $status >= 200 && $status < 300;

        $errorMessage = null;

        if (!$ok) {
            $errorMessage =
                $decoded['message']
                ?? $decoded['error']
                ?? "Erro HTTP {$status}";
        }
        return [
            'ok'     => $ok,
            'status' => $status,
            'data'   => json_last_error() === JSON_ERROR_NONE ? $decoded : null,
            'raw'    => $raw,
            'error'  => $errorMessage,
            'info'   => $inf,
        ];
    }

    // ================== JWT ==================

    private function getJwt(array $claims = []): string
    {
        if ($this->cachedJwt && (time() + 5) < $this->cachedJwtExp) {
            return $this->cachedJwt;
        }

        $jwt = $this->makeJwt($claims);
        return $jwt;
    }

    private function makeJwt(array $extraClaims = []): string
    {

        //echo "PHP timezone: " . date_default_timezone_get() . PHP_EOL;
        //echo "Hora PHP: " . date('Y-m-d H:i:s') . PHP_EOL;
        //echo "Time(): " . time() . PHP_EOL;

        $now = time();
        $exp = $now + self::JWT_TTL;

        $secret = $_ENV['JWT_SECRET'] ?? null;

        //echo "<pre>";
        //print_r($secret);
        //echo "</pre>";exit;

        if (!$secret) {
            throw new \RuntimeException('JWT_SECRET não definido no .env');
        }

        $jwtClaims = [];
        foreach ($extraClaims as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $jwtClaims[$key] = $value;
            }
        }

        // Payload mínimo e genérico
        $payload = array_merge([
            'iss' => self::JWT_ISSUER,
            'iat' => $now,
            'exp' => $exp,
        ], $jwtClaims);

        //echo "<pre>";
        //print_r($payload);
        //echo "</pre>";exit();

        $token = JWT::encode($payload, $secret, 'HS256');

        //echo "<pre>";
        //print_r($token);
        //echo "</pre>";exit();

        // Atualiza cache
        $this->cachedJwt = $token;
        $this->cachedJwtExp = $exp;

        return $token;
    }

    private function normalizeQuery(array $query): array
    {
        if (isset($query['tenant_id']) && !isset($query['tenancy_id'])) {
            $query['tenancy_id'] = $query['tenant_id'];
        } elseif (isset($query['tenancy_id']) && !isset($query['tenant_id'])) {
            $query['tenant_id'] = $query['tenancy_id'];
        }

        if (isset($query['user_id']) && !isset($query['owner_id'])) {
            $query['owner_id'] = $query['user_id'];
        } elseif (isset($query['owner_id']) && !isset($query['user_id'])) {
            $query['user_id'] = $query['owner_id'];
        }

        if (isset($query['function']) && !isset($query['role'])) {
            $query['role'] = $query['function'];
        } elseif (isset($query['role']) && !isset($query['function'])) {
            $query['function'] = $query['role'];
        }

        if (isset($query['extension']) && !isset($query['username'])) {
            $query['username'] = $query['extension'];
        } elseif (isset($query['username']) && !isset($query['extension'])) {
            $query['extension'] = $query['username'];
        }

        return $query;
    }

    private function normalizePayload(array $payload, string $entity): array
    {
        $payload = $this->normalizeQuery($payload);

        if ($entity === 'extensions') {
            if (isset($payload['extension']) && !isset($payload['username'])) {
                $payload['username'] = $payload['extension'];
            } elseif (isset($payload['username']) && !isset($payload['extension'])) {
                $payload['extension'] = $payload['username'];
            }

            if (isset($payload['callerid']) && !isset($payload['caller_number'])) {
                $payload['caller_number'] = $payload['callerid'];
            } elseif (isset($payload['caller_number']) && !isset($payload['callerid'])) {
                $payload['callerid'] = $payload['caller_number'];
            }
        }

        if ($entity === 'audios' || $entity === 'recordings') {
            if (isset($payload['file']) && !isset($payload['file_name'])) {
                $payload['file_name'] = $payload['file'];
            } elseif (isset($payload['file_name']) && !isset($payload['file'])) {
                $payload['file'] = $payload['file_name'];
            }
        }

        if ($entity === 'trunks') {
            if (isset($payload['id']) && !isset($payload['trunk_id'])) {
                $payload['trunk_id'] = (string)$payload['id'];
            }
        }

        return $payload;
    }

    private function normalizeApiResponse(array $response, string $entity, bool $expectsList): array
    {
        if (!isset($response['data']) || !is_array($response['data'])) {
            return $response;
        }

        $body = $response['data'];

        if (array_key_exists('data', $body)) {
            $body['data'] = $this->normalizeEntityValue($body['data'], $entity, $expectsList);

            if ($expectsList && is_array($body['data']) && !isset($body['total'])) {
                $body['total'] = count($body['data']);
            }

            $response['data'] = $body;
            return $response;
        }

        $response['data'] = $this->normalizeEntityValue($body, $entity, $expectsList);
        return $response;
    }

    private function normalizeEntityValue(mixed $value, string $entity, bool $expectsList): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($expectsList || $this->isList($value)) {
            return array_values(array_map(
                fn ($row) => is_array($row) ? $this->normalizeEntityRow($row, $entity) : $row,
                $value
            ));
        }

        return $this->normalizeEntityRow($value, $entity);
    }

    private function normalizeEntityRow(array $row, string $entity): array
    {
        $row = $this->copyAlias($row, 'tenant_id', ['tenancy_id', 'tenantId']);
        $row = $this->copyAlias($row, 'tenancy_id', ['tenant_id', 'tenantId']);
        $row = $this->copyAlias($row, 'user_id', ['owner_id', 'userId']);
        $row = $this->copyAlias($row, 'owner_id', ['user_id', 'ownerId']);
        $row = $this->copyAlias($row, 'status', ['account_status', 'sip_status_text']);

        switch ($entity) {
            case 'extensions':
                $row = $this->copyAlias($row, 'extension', ['username', 'ramal', 'id']);
                $row = $this->copyAlias($row, 'username', ['extension', 'ramal']);
                $row = $this->copyAlias($row, 'caller_number', ['callerid', 'caller_number_external']);
                $row = $this->copyAlias($row, 'callerid', ['caller_number']);
                $row = $this->copyAlias($row, 'transport', ['transport_name']);
                $row = $this->copyAlias($row, 'online', ['registered', 'is_online']);
                $row = $this->copyAlias($row, 'registered', ['online']);
                $row = $this->copyAlias($row, 'sip_status_text', ['sip_detail', 'status']);
                break;

            case 'trunks':
                $row = $this->copyAlias($row, 'trunk_id', ['id', 'trunkId']);
                $row = $this->copyAlias($row, 'id', ['trunk_id']);
                $row = $this->copyAlias($row, 'direction', ['route_direction']);
                $row = $this->copyAlias($row, 'is_system', ['system', 'is_default']);
                $row = $this->copyAlias($row, 'techprefix', ['tech_prefix']);
                $row = $this->copyAlias($row, 'dial_prefix', ['dialPrefix']);
                $row = $this->copyAlias($row, 'online', ['is_online', 'registered']);
                $row = $this->copyAlias($row, 'is_online', ['online']);
                $row = $this->copyAlias($row, 'sip_status_text', ['sip_detail', 'status']);
                break;

            case 'audios':
                $row = $this->copyAlias($row, 'file_name', ['file', 'filename']);
                $row = $this->copyAlias($row, 'file', ['file_name']);
                $row = $this->copyAlias($row, 'duration_seconds', ['duration', 'seconds']);
                $row = $this->copyAlias($row, 'size_bytes', ['size']);
                $row = $this->copyAlias($row, 'name', ['display_name', 'file_name']);
                break;

            case 'recordings':
                $row = $this->copyAlias($row, 'file', ['file_name', 'name']);
                $row = $this->copyAlias($row, 'display_name', ['name', 'file']);
                $row = $this->copyAlias($row, 'audio_url', ['recording_url', 'url']);
                $row = $this->copyAlias($row, 'recording_url', ['audio_url', 'url']);
                $row = $this->copyAlias($row, 'call_id', ['id', 'uniqueid']);
                break;
        }

        return $row;
    }

    private function copyAlias(array $row, string $target, array $sources): array
    {
        if (isset($row[$target]) && $row[$target] !== null && $row[$target] !== '') {
            return $row;
        }

        foreach ($sources as $source) {
            if (isset($row[$source]) && $row[$source] !== null && $row[$source] !== '') {
                $row[$target] = $row[$source];
                break;
            }
        }

        return $row;
    }

    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private function extractEntityData(array $response, bool $expectsList): array|null
    {
        $data = $response['data'] ?? null;

        if (!is_array($data)) {
            return null;
        }

        if (array_key_exists('data', $data)) {
            $data = $data['data'];
        }

        if (!is_array($data)) {
            return null;
        }

        if ($expectsList) {
            return $this->isList($data) ? $data : [$data];
        }

        return $this->isList($data) ? ($data[0] ?? null) : $data;
    }

    private function findTrunkInList(array $query, int $id): ?array
    {
        $fallbackQuery = $query;
        unset($fallbackQuery['id'], $fallbackQuery['trunk_id']);

        $listResponse = $this->listTrunks($fallbackQuery);
        $list = $this->extractEntityData($listResponse, true);

        if (!is_array($list)) {
            return null;
        }

        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }

            $candidateId = (string)($row['id'] ?? '');
            $candidateTrunkId = (string)($row['trunk_id'] ?? '');

            if ($candidateId === (string)$id || $candidateTrunkId === (string)$id) {
                return $row;
            }
        }

        return null;
    }

}
