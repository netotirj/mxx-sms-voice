<?php

namespace App\Controller\Pages;
use Firebase\JWT\JWT;


class AsteriskExtensionsSip
{
    // ======== CONFIG ========
    private const string BASE_URL = 'http://192.168.1.8/index.php';
    private const string JWT_ISSUER = 'mxx-asterisk';
    private const int JWT_TTL = 3600; // segundos
    private const int CONNECT_TIMEOUT = 3;
    private const int TIMEOUT = 10;

    // Cache simples de token por request
    private ?string $cachedJwt = null;
    private int $cachedJwtExp = 0;

    /**
     * Exemplo de chamada para listar extensões SIP
     */
    public function listExtensions(array $query = []): array
    {
        // 🔹 se o usuário for super_admin, não envia user_id nem tenant_id
        if (isset($query['function']) && $query['function'] === 'super_admin') {
            unset($query['user_id'], $query['tenant_id']);
        }

        $query['action'] = $query['action'] ?? 'extensions';
        return $this->request('GET', $query);
    }

    public function createExtension(array $query, array $payload): array
    {
        $query['action'] = 'create_extension';
        return $this->request('POST', $query, $payload);
    }

    public function getExtensionById(array $query, string $id): array
    {
        // 🔹 Ação correta definida na sua API PHP
        $query['action'] = 'getById';
        $query['id'] = $id;

        // 🔹 Executa requisição (gera JWT e envia cabeçalho)
        $response = $this->request('GET', $query);

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
        $query['action'] = 'update_extension';
        return $this->request('PUT', $query, $payload);
    }

    public function updateBalance(array $query, array $payload): array
    {
        $query['action'] = 'update_balance';
        return $this->request('PUT', $query, $payload);
    }

    public function updateTariff(array $query, array $payload): array
    {
        //echo "<pre>";
        //print_r($payload);
        //echo "</pre>";exit();

        $query['action'] = 'update_tariff';
        return $this->request('PUT', $query, $payload);
    }

    public function deleteExtension(array $query, array $payload): array
    {
        // 🔹 Define a ação esperada pela API
        $query['action'] = 'delete_extension';

        // 🔹 Executa a requisição DELETE com corpo JSON
        return $this->request('DELETE', $query, $payload);
    }

    public function updateStatus(array $query, array $payload): array
    {
        // 🔹 Define a ação esperada pela API PHP
        $query['action'] = 'update_status';

        // 🔹 Executa a requisição PATCH (atualiza apenas account_status)
        return $this->request('PATCH', $query, $payload);
    }

    // ================== AUDIOS (NOVO) ==================
    public function createAudio(array $query, array $payload): array
    {
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
        return $this->request('POST', $query, $payload);
    }


    public function listAudios(array $query = []): array
    {
        // 🔹 Se o usuário for super_admin, não envia user_id nem tenant_id
        if (isset($query['function']) && strtolower($query['function']) === 'super_admin') {
            unset($query['user_id'], $query['tenant_id']);
        }

        // 🔹 Define ação padrão
        $query['action'] = $query['action'] ?? 'list_audios';

        // 🔹 Faz a requisição
        $response = $this->request('GET', $query);

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
     * Deleta um áudio do Asterisk
     */

    public function deleteAudio(array $query, array $payload): array
    {
        // 🔹 Define a ação esperada pela API
        $query['action'] = 'delete_audio';

        // 🔹 Executa a requisição DELETE com corpo JSON
        return $this->request('DELETE', $query, $payload);
    }


    public function listTrunks(array $query = []): array
    {

        // 🔹 se o usuário for super_admin, não envia user_id nem tenant_id
        if (isset($query['function']) && $query['function'] === 'super_admin') {
            unset($query['user_id'], $query['tenant_id']);
        }

        $query['action'] = 'get_trunks';
        return $this->request('GET', $query);
    }


    public function createSipTrunk(array $query, array $payload): array
    {
        $query['action'] = 'create_trunk';
        return $this->request('POST', $query, $payload);
    }

    public function getTrunkById(array $query, int $id): array
    {

        // 🔹 Ação correta definida na sua API PHP
        $query['action'] = 'getTrunkById';
        $query['id'] = $id;

        // 🔹 Executa requisição (gera JWT e envia cabeçalho)
        $response = $this->request('GET', $query);

        // 🔹 Trata o retorno
        $status = $response['status'] ?? 0;
        $ok = $status >= 200 && $status < 300;

        if (!$ok || empty($response['data'])) {
            return [
                'ok' => false,
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
        $query['action'] = 'update_trunks';
        return $this->request('PUT', $query, $payload);
    }

    public function updateStatusSipTrunks(array $query, array $payload): array
    {
        // 🔹 Define a ação esperada pela API PHP
        $query['action'] = 'status_trunks';

        // 🔹 Executa a requisição PATCH (atualiza apenas account_status)
        return $this->request('PATCH', $query, $payload);
    }

    public function deleteSipTrunks(array $query, array $payload): array
    {
        // 🔹 Define a ação esperada pela API
        $query['action'] = 'delete_trunks';

        // 🔹 Executa a requisição DELETE com corpo JSON
        return $this->request('DELETE', $query, $payload);
    }


    // ================== CORE REQUEST ==================

    private function request(string $method, array $query = [], ?array $body = null): array
    {
        // 🔗 Montagem da URL com query string
        $url = self::BASE_URL;

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

        // Payload mínimo e genérico
        $payload = array_merge([
            'iss' => self::JWT_ISSUER,
            'iat' => $now,
            'exp' => $exp,
        ]);

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

}
