<?php

namespace App\Service;

use Predis\Client as RedisClient;

class AsteriskErrorMonitor
{
    public function __construct(
        private RedisClient $redis,
        private string $ns = 'voice'
    ) {}

    /**
     * Recebe o resultado rico do originate (HTTP).
     */
    public function recordHttpResult(array $result, array $data): void
    {
        $ok = (bool)($result['ok'] ?? false);
        if ($ok) return;

        $callId = (string)($data['call_id'] ?? 'unknown');
        $jobId  = $data['job_id'] ?? null;

        $payload = [
            'type'       => 'ari_http_error',
            'class'      => $this->classifyHttp($result),
            'call_id'    => $callId,
            'job_id'     => $jobId,
            'phone'      => $data['phone'] ?? null,
            'strategy'   => $data['strategy'] ?? null,
            'ari_host'   => $result['host'] ?? null,
            'endpoint'   => $result['endpoint'] ?? null,
            'http' => [
                'status'     => $result['http_status'] ?? null,
                'error'      => $result['error'] ?? null,
                'latency_ms' => $result['latency_ms'] ?? null,
                'connect_error' => $result['connect_error'] ?? null,
                'exception'  => $result['exception_class'] ?? null,
            ],
            // cuidado com tamanho: corta body grande
            'body'       => $this->truncate((string)($result['body'] ?? ''), 1200),
            'ts'         => time(),
        ];

        $this->pushError($payload);
    }

    private function pushError(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // Últimos erros globais
        $k = "{$this->ns}:errors:http";
        $this->redis->lpush($k, $json);
        $this->redis->ltrim($k, 0, 500);
        $this->redis->expire($k, 86400);

        $callId = (string)$payload['call_id'];
        $this->redis->setex("{$this->ns}:last_http_error:call:{$callId}", 3600, $json);

        $class = (string)$payload['class'];
        $this->redis->hincrby("{$this->ns}:http_errors_by_class", $class, 1);
        $this->redis->expire("{$this->ns}:http_errors_by_class", 86400);

        if (!empty($payload['job_id'])) {
            $jobId = (string)$payload['job_id'];
            $this->redis->hincrby("campaign:{$jobId}", 'http_errors_total', 1);
            $this->redis->hincrby("campaign:{$jobId}:http_errors_by_class", $class, 1);
            $this->redis->expire("campaign:{$jobId}:http_errors_by_class", 86400);
        }
    }

    public function classifyHttp(array $r): string
    {
        $status = (int)($r['http_status'] ?? 0);
        $err    = strtoupper((string)($r['error'] ?? ''));

        // transporte
        if (!empty($r['connect_error'])) {
            if (str_contains($err, 'TIMED OUT') || str_contains($err, 'TIMEOUT')) return 'timeout';
            if (str_contains($err, 'COULD NOT RESOLVE') || str_contains($err, 'RESOLVE HOST')) return 'dns';
            if (str_contains($err, 'CONNECTION REFUSED')) return 'conn_refused';
            if (str_contains($err, 'FAILED TO CONNECT') || str_contains($err, 'CONNECT')) return 'connect_fail';
            if (str_contains($err, 'SSL')) return 'ssl';
            return 'connect_fail';
        }

        // HTTP
        if ($status === 401 || $status === 403) return 'auth';
        if ($status === 404) return 'not_found';
        if ($status === 409) return 'conflict';
        if ($status === 429) return 'rate_limited';
        if ($status >= 500 && $status <= 599) return 'ari_5xx';
        if ($status >= 400 && $status <= 499) return 'ari_4xx';

        return 'unknown_http';
    }

    private function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) return $s;
        return substr($s, 0, $max) . '...';
    }
}