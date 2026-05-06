<?php

namespace App\Service;

use App\Config\TelephonyConfig;
use App\Model\Entity\CampaignVoice;
use App\RedisConn;
use Exception;
use GuzzleHttp\Client;
use Random\RandomException;
use Throwable;

class VoiceWorker
{
    private $redis;
    private Client $http;
    private string $ariHost;
    private array $ariAuth;
    private string $stasisApp;

    private const int MIN_CPS = 1;
    private const int MAX_CPS = 20;

    /**
     * @throws Exception
     */
    public function __construct(array $opts = [])
    {
        $this->redis     = RedisConn::get();
        $this->ariHost   = $opts['ari_host']   ?? TelephonyConfig::ariHost();
        $this->ariAuth   = $opts['ari_auth']   ?? TelephonyConfig::ariAuth();
        $this->stasisApp = $opts['stasis_app'] ?? TelephonyConfig::stasisApp();

        $this->http = new Client([
            'base_uri'    => 'http://' . $this->ariHost . ':' . TelephonyConfig::ariPort() . '/',
            'timeout'     => 5.0,
            'http_errors' => false,
            'auth'        => $this->ariAuth,
        ]);
    }

    // =========================================================
    // MAIN LOOP
    // =========================================================
    /**
     * @throws RandomException
     * @throws Exception
     */

    public function run(): void
    {
        $orig = new VoiceOriginate();
        $bucket = new TokenBucket($this->redis);
        $monitor = new AsteriskErrorMonitor($this->redis);

        echo "🚀 Worker Voice iniciado (PID " . getmypid() . ")\n";

        while (true) {

            $agentManager = new AgentManager([
                'ari_host' => $this->ariHost,
                'ari_auth' => $this->ariAuth,
            ]);

            // =====================================================
            // 🛑 STASIS GATE — aguarda listener voltar
            // =====================================================
            if (!$this->isStasisOnline(10)) {
                echo "🛑 STASIS OFFLINE → aguardando heartbeat...\n";
                sleep(1); // não consome fila, não gera carga
                continue;
            }

            // =====================================================
            // 🔄 SYNC agentes / chamadas
            // =====================================================
            try {
                $this->syncAgentsFromActiveCalls();
            } catch (Throwable $e) {
                echo "[SYNC] ERRO: {$e->getMessage()}\n";
            }

            // =====================================================
            // 🔄 POP fila (PRIORIDADE TRANSFER)
            // =====================================================
            $item = $this->redis->blpop('voice:transfer_request', 1);

            if (!$item) {
                $item = $this->redis->blpop('voice:queue', 1);
            }

            if (!$item) {
                continue;
            }

            [$listKey, $raw] = $item;

            // =====================================================
            // 🔁 TRANSFER REQUEST
            // =====================================================
            if ($listKey === 'voice:transfer_request') {

                $payload = json_decode($raw, true) ?: [
                    'channel' => (string)$raw,
                ];

                try {
                    $this->handleTransferRequest($payload);
                } catch (Throwable $e) {
                    echo "[TRANSFER] ERRO: {$e->getMessage()}\n";
                }

                continue;
            }

            // =====================================================
            // 📞 ORIGINATE
            // =====================================================
            $data = json_decode($raw, true);

            if (!is_array($data) || empty($data['phone'])) {
                continue;
            }

            $jobId = $data['job_id'] ?? null;

            // =====================================================
            // ⏸ PAUSE GATE (por JOB_ID)
            // =====================================================
            if (!empty($jobId) && $this->redis->get("campaign:pause:job:{$jobId}")) {

                $this->redis->rpush("voice:paused:job:{$jobId}", $raw);
                $this->redis->hset("campaign:{$jobId}", 'status', 'paused');

                echo "⏸ Campanha {$jobId} PAUSADA → segurando payload\n";
                continue;
            }


            // =====================================================
            // 🆔 CALL_ID (chave por chamada)
            // =====================================================
            $callId = $data['call_id'] ?? null;
            $callId = $callId ? trim((string)$callId) : null;

            // fallback seguro
            if (!$callId) {
                $callId = $jobId ?: ('call-' . getmypid() . '-' . bin2hex(random_bytes(3)));
            }

            $data['call_id'] = $callId;

            // =====================================================
            // 🧠 Detecta se EXISTE ação de TRANSFER
            // =====================================================
            $needsAgent = false;
            $finalAction = strtolower(trim((string)($data['action'] ?? '')));
            $dtmf = $data['audio']['dtmf'] ?? [];

            if ($finalAction === 'transfer_only') {
                $needsAgent = true;
            } elseif ($finalAction === 'dtmf' && is_array($dtmf)) {
                foreach ($dtmf as $itemDtmf) {
                    if (($itemDtmf['action'] ?? null) === 'transfer') {
                        $needsAgent = true;
                        break;
                    }
                }
            }

            // transfer_only NÃO pré-reserva
            $skipPreReserve = ($finalAction === 'transfer_only');

            // =====================================================
            // 🧠 STRATEGY
            // =====================================================
            $strategy = strtolower(trim((string)($data['strategy'] ?? 'rrmemory')));

            if (!in_array($strategy, ['rrmemory', 'leastrecent', 'linear', 'ringall'], true)) {
                $strategy = 'rrmemory';
            }

            $data['strategy'] = $strategy;

            $pricingError = $this->validatePricingPayload($data);
            if ($pricingError !== null) {
                $this->pushDlq($jobId, $callId, $raw, 'invalid_pricing_payload', [
                    'error' => $pricingError,
                ]);

                if ($jobId) {
                    $this->markCallFailed($data, $jobId);
                    $this->notifyJob($jobId, 'invalid_pricing_payload', $pricingError, [
                        'call_id' => $callId,
                        'phone' => $data['phone'] ?? null,
                    ]);
                }

                echo "❌ Payload sem tarifação segura → {$pricingError}\n";
                continue;
            }

            if (!empty($jobId)) {
                $lockKey = "campaign:{$jobId}:started";

                if ($this->redis->setnx($lockKey, time())) {
                    $this->redis->expire($lockKey, 3600);
                    CampaignVoice::updateStatusByJob($jobId, 'p');
                    echo "📊 Campanha {$jobId} → PROCESSANDO\n";
                }
            }

            //echo "🏁 Strategy {$strategy} FRONTEND\n";

            $this->logOnce(
                "strategy_frontend:" . ($jobId ?: 'global'),
                10,
                "🏁 Strategy {$strategy} FRONTEND"
            );

            // =====================================================
            // ⏱ CPS
            // =====================================================
            $rate = max(
                self::MIN_CPS,
                min(self::MAX_CPS, (float)($data['rate'] ?? 1))
            );

            $bucketKey = $jobId
                ? "campaign:{$jobId}:bucket"
                : "campaign:global:bucket";

            if (!$bucket->waitAndConsume($bucketKey, $rate, $rate, 2.0, 1)) {

                echo "⏱ CPS bloqueou → reenfileirando\n";

                $this->notifyJob($jobId, 'cps_blocked', 'CPS bloqueou → reenfileirando', [
                    'bucket' => $bucketKey,
                    'rate' => $rate,
                ]);

                $this->redis->rpush('voice:queue', $raw);
                usleep(200_000);
                continue;
            }


            // =====================================================
            // 🧠 ENDPOINTS
            // =====================================================
            $endpoints = array_values(
                array_unique(
                    array_filter((array)($data['endpoints'] ?? []))
                )
            );

            // =====================================================
            // 🧠 AGENT MANAGER
            // =====================================================
            $ramal = null;

            if ($needsAgent && $endpoints) {

                // 1. Consulta o status real no Redis/ARI
                $agentsStatus = $agentManager->addFromPayload($endpoints, false);

                $onlineEndpoints = [];
                $counts = ['OFFLINE' => 0, 'PAUSA' => 0, 'OCUPADO' => 0];

                // 2. Filtra apenas quem está ONLINE e LIVRE
                foreach ((array)$agentsStatus as $r => $st) {
                    $isOnline = ($st['online'] ?? false) === true;
                    $status   = strtoupper($st['status'] ?? 'OFFLINE');

                    if ($isOnline && $status === 'LIVRE') {
                        $onlineEndpoints[] = (string)$r;
                    } else {
                        // Classifica o motivo do bloqueio para o log
                        if (!$isOnline) {
                            $counts['OFFLINE']++;
                        } elseif ($status === 'PAUSA') {
                            $counts['PAUSA']++;
                        } else {
                            $counts['OCUPADO']++;
                        }
                    }
                }

                $onlineEndpoints = array_values(array_unique($onlineEndpoints));

                // 3. Bloqueio de Fila e Reenfileiramento
                if (empty($onlineEndpoints)) {
                    // 1. Vamos descobrir o motivo predominante para mostrar no log
                    $motivos = [];
                    $snapshotAgentes = [];
                    foreach ((array)$agentsStatus as $ramalStatus => $st) {
                        $ramalStatus = (string)$ramalStatus;
                        $statusReal = strtoupper((string)($st['status'] ?? ''));
                        $nomePausa  = $st['status_name'] ?? null;
                        $onlineFlag  = ($st['online'] ?? false) ? 'online' : 'offline';

                        if ($ramalStatus !== '') {
                            $snapshotAgentes[] = trim($ramalStatus . ':' . $onlineFlag . '/' . ($statusReal ?: 'UNKNOWN'));
                        }

                        if ($statusReal === 'PAUSA' && $nomePausa) {
                            $motivos[] = $nomePausa;
                        } elseif ($statusReal === 'OFFLINE') {
                            $motivos[] = "OFFLINE";
                        }
                    }

                    // 2. Cria uma mensagem detalhada
                    if (!empty($motivos)) {
                        // Conta os motivos (Ex: 2 ALMOÇO, 1 BANHEIRO)
                        $counts = array_count_values($motivos);
                        $detalhe = [];
                        foreach ($counts as $txt => $qtd) {
                            $detalhe[] = "{$qtd} em {$txt}";
                        }
                        $msgFinal = "⚠ Bloqueio: " . implode(", ", $detalhe);
                    } else {
                        $msgFinal = "⚠ Aguardando agentes ficarem LIVRES (Fila de espera)";
                    }

                    if (!empty($endpoints)) {
                        $msgFinal .= " | endpoints=" . implode(',', $endpoints);
                    }

                    if (!empty($snapshotAgentes)) {
                        $msgFinal .= " | status=" . implode(' | ', $snapshotAgentes);
                    }

                    // 3. Grava o log que aparece na tela de campanhas
                    $this->logOnce("block:".$jobId, 5, $msgFinal);

                    // 🔥 A LINHA QUE FALTA É ESTA:
                    // Ela pega a mensagem que você já montou ($msgFinal) e manda pro monitor da tela.
                    $this->notifyJob($jobId, 'no_agents_available', $msgFinal);

                    usleep(800000);
                    $this->redis->rpush('voice:queue', $raw);
                    continue;
                }

                $endpoints = $onlineEndpoints;

                // =================================================
                // 🧠 PRE-RESERVA
                // =================================================
                $workerId = getmypid() . '-' . bin2hex(random_bytes(3));

                $criteria = [
                    'preferred' => $endpoints,
                    'strict_preferred' => 1,
                    'reserve_ttl' => 60,
                    'skills' => (array)($data['skills'] ?? []),
                    'vip' => (bool)($data['vip'] ?? false),
                    'strategy' => $strategy,
                ];

                if (!$skipPreReserve && $strategy !== 'ringall') {

                    $ramal = $agentManager->reserveAgentAdvanced($criteria, $workerId);

                    if (!$ramal) {

                        $this->logOnce(
                            "no_agents_available:" . ($jobId ?: 'global'),
                            10,
                            "⚠ Nenhum agente disponível → reenfileirando"
                        );

                        $this->notifyJob($jobId, 'no_agents_available', 'Nenhum agente disponível → reenfileirando', [
                            'endpoints' => $endpoints,
                            'strategy' => $strategy,
                            'skills' => (array)($data['skills'] ?? []),
                            'vip' => (bool)($data['vip'] ?? false),
                        ]);

                        usleep(500_000);
                        $this->redis->rpush('voice:queue', $raw);
                        continue;
                    }

                    echo "✔ Agente reservado: {$ramal}\n";

                    $data['reserved_agent'] = $ramal;
                }

                // =================================================
                // 🧾 CONTEXTO POR CALL_ID (O Stasis consulta isso no UP)
                // =================================================
                $this->redis->setex(
                    "voice:call_context:{$callId}",
                    300,
                    json_encode([
                        'endpoints'    => $endpoints,
                        'ramal'        => $ramal,
                        'strategy'     => $strategy,
                        'job_id'       => $jobId,
                        'call_id'      => $callId,
                        'tenant_id'    => $data['tenant_id'] ?? '0', // 🚀 Essencial para o caminho da pasta
                        'user_id'      => $data['user_id'] ?? '0',   // 🚀 Essencial para o caminho da pasta
                        'record_calls' => (int)($data['record_calls'] ?? 0), // 🚀 O gatilho!
                        'ts'           => time(),
                    ], JSON_UNESCAPED_UNICODE)
                );

            } else {
                echo "🎧 Chamada sem transferência → sem reserva\n";
            }

            // =====================================================
            // 📞 ORIGINATE REAL
            // =====================================================
            try {

                //$ok = $orig->originate($data);

                $result = $orig->originate($data);

                if (!empty($result['connect_error'])) {
                    if ($this->redis->setnx('voice:stasis:last_error:lock', time())) {
                        $this->redis->expire('voice:stasis:last_error:lock', 5);

                        $this->redis->setex('voice:stasis:last_error', 3600, json_encode([
                            'msg' => $result['error'] ?? 'Falha de conexão ARI',
                            'ts' => time(),
                        ], JSON_UNESCAPED_UNICODE));
                    }
                }

                if (!empty($result['ok'])) {
                    $this->redis->del('voice:stasis:last_error');
                }

                // ✅ grava erro HTTP/timeout/etc (só grava quando ok=false)
                $monitor->recordHttpResult($result, $data);

                $ok = (bool)($result['ok'] ?? false);

                if (!$ok) {

                    $cls = $monitor->classifyHttp($result);

                    // erros permanentes → NÃO retry
                    if (in_array($cls, ['auth', 'not_found'], true)) {

                        // salva payload completo pra auditoria / reprocesso
                        $this->pushDlq($jobId, $callId, $raw, $cls, $result);

                        if ($jobId) {
                            $this->markCallFailed($data, $jobId);
                        }
                        echo "❌ ORIGINATE {$cls} → movido p/ DLQ\n";
                        continue;
                    }

                    // erros transientes → retry com backoff
                    $attemptKey = $jobId
                        ? "campaign:{$jobId}:retry:{$callId}"
                        : "call:retry:{$callId}";

                    $attempt = (int)$this->redis->incr($attemptKey);
                    $this->redis->expire($attemptKey, 3600);

                    if ($attempt <= 5) {
                        $baseMs = min(5000, 200 * (2 ** ($attempt - 1)));
                        $jitter = random_int(0, 150);
                        $delayMs = $baseMs + $jitter;

                        echo "🔁 ORIGINATE falhou ({$cls}) retry {$attempt}/5 em {$delayMs}ms\n";

                        usleep($delayMs * 1000);
                        $this->redis->rpush('voice:queue', $raw);
                        continue;
                    }

                    $this->pushDlq($jobId, $callId, $raw, "max_retries:{$cls}", $result);

                    if ($jobId) {
                        $this->markCallFailed($data, $jobId);
                    }

                    echo "❌ ORIGINATE falhou ({$cls}) → max retries → DLQ + cancel\n";
                    continue;
                }

                if ($ok && $jobId) {

                    $processed = $this->redis->hIncrBy(
                        "campaign:{$jobId}",
                        'processed',
                        1
                    );

                    $total = (int)$this->redis->hGet(
                        "campaign:{$jobId}",
                        'total'
                    );

                    echo "📊 Campanha {$jobId}: {$processed}/{$total}\n";

                    if (!empty($result['ok'])) {
                        echo "✅ ORIGINATE OK http=" . ($result['http_status'] ?? 'null') . "\n";
                    }

                    if ($processed >= $total) {

                        if ($this->redis->setnx("campaign:{$jobId}:finished", time())) {
                            $this->redis->expire("campaign:{$jobId}:finished", 3600);
                            CampaignVoice::updateStatusByJob($jobId, 'p');
                            echo "🏁 Campanha {$jobId} PROCESSANDO\n";
                        }
                    }
                }

            } catch (Throwable $e) {

                // ✅ registra como erro genérico
                $monitor->recordHttpResult([
                    'ok' => false,
                    'http_status' => null,
                    'latency_ms' => null,
                    'host' => $this->ariHost ?? null,
                    'endpoint' => '/channels',
                    'error' => '[ORIGINATE_EXCEPTION] ' . $e->getMessage(),
                    'connect_error' => false,
                    'exception_class' => get_class($e),
                ], $data);

                // ✅ também grava como "last_error" do stasis/ARI (porque é erro no originate)
                $this->redis->setex('voice:stasis:last_error', 3600, json_encode([
                    'msg' => $e->getMessage(),
                    'ts' => time(),
                ], JSON_UNESCAPED_UNICODE));

                if ($jobId) {
                    $this->markCallFailed($data, $jobId);
                }

                echo "[ORIGINATE] ERRO: {$e->getMessage()}\n";
            }

            echo "--------------------------------------------------\n";
        }
    }

    public function isStasisOnline(int $ttlSeconds = 10): bool
    {
        $hbRaw = $this->redis->get('voice:stasis:heartbeat');
        if (!$hbRaw) return false;

        $hb = json_decode((string)$hbRaw, true);
        if (!is_array($hb)) return false;

        $ts = (int)($hb['ts'] ?? 0);
        return $ts > 0 && (time() - $ts) <= $ttlSeconds;
    }

    private function logOnce(string $key, int $ttl, string $msg): void
    {
        $k = "voice:logonce:{$key}";
        if ($this->redis->setnx($k, time())) {
            $this->redis->expire($k, $ttl);
            echo $msg . "\n";
        }
    }

    private function validatePricingPayload(array $data): ?string
    {
        $type = strtolower(trim((string)($data['variable_type'] ?? 'voice')));

        if (empty($data['trunk_id']) || empty($data['trunk'])) {
            return 'Payload de voz sem trunk_id/trunk.';
        }

        if (empty($data['trunk_billing_type'])) {
            return 'Payload de voz sem tipo de tarifação do tronco.';
        }

        if (empty($data['plan_id'])) {
            return 'Payload de voz sem plan_id.';
        }

        $tariffUsed = round((float)($data['tariff_used'] ?? 0), 4);
        if ($tariffUsed <= 0) {
            return 'Payload de voz sem tarifa usada.';
        }

        if (in_array($type, ['voice', 'normal', 'outbound'], true) && (float)($data['call_minute_cost'] ?? 0) <= 0) {
            return 'Payload de voz sem call_minute_cost.';
        }

        if ($type === 'torpedo' && (float)($data['torpedo_cost'] ?? 0) <= 0) {
            return 'Payload de torpedo sem torpedo_cost.';
        }

        if ($type === 'sms' && (float)($data['sms_cost'] ?? 0) <= 0) {
            return 'Payload de SMS sem sms_cost.';
        }

        if ($type === 'service_fee' && (float)($data['taxa_of_service'] ?? 0) <= 0) {
            return 'Payload de taxa de serviço sem valor configurado.';
        }

        return null;
    }

    private function markCallFailed(array $data, ?string $jobId): void
    {
        if (!$jobId) {
            return;
        }

        $this->redis->hIncrBy("campaign:{$jobId}", 'failed_calls', 1);
        $this->redis->hIncrBy("campaign:{$jobId}", 'processed', 1);

        if (!empty($data['campaign_id'])) {
            CampaignVoice::incrementCampaignCounters((int)$data['campaign_id'], 'FAILED');
        }
    }

    private function pushDlq(?string $jobId, string $callId, string $raw, string $reason, array $result = []): void
    {
        $http = $result['http_status'] ?? null;

        // fingerprint de "mesmo erro para mesma chamada"
        $fingerprint = sha1(($jobId ?? '-') . '|' . $callId . '|' . $reason . '|' . ($http ?? '-') . '|' . $raw);
        $dedupeKey   = "voice:dlq:dedupe:{$fingerprint}";
        $ttl         = 86400 * 7;

        // ✅ Predis-friendly: SETNX + EXPIRE
        // (se já existe, não grava de novo)
        $first = (int)$this->redis->setnx($dedupeKey, 1);
        if ($first !== 1) {
            return;
        }
        $this->redis->expire($dedupeKey, $ttl);

        $payload = [
            'ts'     => time(),
            'job_id' => $jobId,
            'call_id'=> $callId,
            'reason' => $reason,
            'result' => [
                'http_status'   => $http,
                'error'         => $result['error'] ?? null,
                'connect_error' => $result['connect_error'] ?? null,
                'class'         => $result['class'] ?? null,
            ],
            'raw' => $raw,
        ];

        $k = "voice:dlq";
        $this->redis->lpush($k, json_encode($payload, JSON_UNESCAPED_UNICODE));
        $this->redis->ltrim($k, 0, 5000);
        $this->redis->expire($k, $ttl);

        if ($jobId) {
            $kj = "voice:dlq:job:{$jobId}";
            $this->redis->lpush($kj, json_encode($payload, JSON_UNESCAPED_UNICODE));
            $this->redis->ltrim($kj, 0, 1000);
            $this->redis->expire($kj, $ttl);
        }
    }

    private function notifyJob(?string $jobId, string $code, string $message, array $ctx = []): void
    {
        if (!$jobId) return;

        $now = time();

        // 1) Contador por motivo - SEMPRE ATUALIZA
        $this->redis->hIncrBy("campaign:{$jobId}:requeue_counts", $code, 1);
        $this->redis->expire("campaign:{$jobId}:requeue_counts", 86400);

        // 2) Status atual do runtime - SEMPRE ATUALIZA
        // Mover isso para antes do 'return' garante que a troca de ALMOÇO para BANHEIRO seja instantânea na tela
        $this->redis->setex("campaign:{$jobId}:runtime_status", 20, json_encode([
            'code' => $code,
            'msg'  => $message,
            'ts'   => $now,
            'ctx'  => $ctx,
        ], JSON_UNESCAPED_UNICODE));

        // 3) Throttle/Dedup: SÓ PARA O HISTÓRICO
        $throttleKey = "campaign:{$jobId}:notify_throttle:{$code}";
        if (!$this->redis->setnx($throttleKey, (string)$now)) {
            // Se já notificou nos últimos 3s, não grava no histórico/timeline de novo
            // mas o "runtime_status" lá em cima já foi atualizado com a nova mensagem!
            return;
        }
        $this->redis->expire($throttleKey, 3);

        // 4) Histórico curto de eventos (Timeline)
        $event = json_encode([
            'code' => $code,
            'msg'  => $message,
            'ts'   => $now,
            'ctx'  => $ctx,
        ], JSON_UNESCAPED_UNICODE);

        $k = "campaign:{$jobId}:events";
        $this->redis->lPush($k, $event);
        $this->redis->lTrim($k, 0, 200);
        $this->redis->expire($k, 86400);
    }


    // =========================================================
    // TRANSFER DECISION
    // =========================================================

    private function handleTransferRequest(array $payload): void
    {
        $channelId = $payload['channel'] ?? null;
        if (!$channelId) return;

        $reqId  = $payload['req_id']  ?? null;
        $callId = $payload['call_id'] ?? null;
        $callId = $callId ? trim((string)$callId) : null;

        // =========================================================
        // ✅ ANTI-TIMEOUT: lock + cache da última resposta
        // =========================================================
        $lockKey     = "voice:transfer_decision:{$channelId}";
        $lastKey     = "voice:transfer_last:{$channelId}";
        $lockSeconds = 2; // 5s é alto e causa timeout no seu loop

        if (!$this->redis->set($lockKey, getmypid(), 'NX', 'EX', $lockSeconds)) {

            // responde com a última decisão (se existir) para não dar timeout
            $lastRaw = $this->redis->get($lastKey);
            if ($lastRaw) {
                $last = json_decode((string)$lastRaw, true) ?: null;
                if (is_array($last)) {
                    $last['req_id'] = $reqId;
                    $last['ts']     = time();

                    $this->redis->rpush(
                        "voice:worker_response:{$channelId}",
                        json_encode($last, JSON_UNESCAPED_UNICODE)
                    );
                    return;
                }
            }

            // fallback: responde "processando"
            $this->respondTransfer($channelId, false, null, 'busy_processing', $reqId);
            return;
        }

        // =========================================================
        // 🔍 canal existe?
        // =========================================================
        try {
            $check = $this->http->get("ari/channels/{$channelId}");
            if ($check->getStatusCode() !== 200) throw new \RuntimeException('Channel not active');
        } catch (\Throwable) {
            $cachePayload = [
                'ok'      => false,
                'ramal'   => null,
                'channel' => $channelId,
                'reason'  => 'channel_not_active',
                'ts'      => time(),
                'req_id'  => $reqId,
            ];
            $this->redis->setex($lastKey, 3, json_encode($cachePayload, JSON_UNESCAPED_UNICODE));
            $this->respondTransfer($channelId, false, null, 'channel_not_active', $reqId);
            return;
        }

        // =========================================================
        // 🔑 contexto do canal (pode ter call_id)
        // =========================================================
        $ctxRaw = $this->redis->get("voice:channel_context:{$channelId}");
        $ctx    = $ctxRaw ? json_decode($ctxRaw, true) : [];

        if (!$callId) {
            $callId = $ctx['call_id'] ?? null;
            $callId = $callId ? trim((string)$callId) : null;
        }

        // ✅ call_context por CALL_ID (fonte de verdade)
        $callCtx = [];
        if ($callId) {
            $ccRaw   = $this->redis->get("voice:call_context:{$callId}");
            $callCtx = $ccRaw ? (json_decode($ccRaw, true) ?: []) : [];
        }

        // =========================================================
        // requested: payload > channel_context > call_context
        // =========================================================
        $requested = $payload['requested_ramal'] ?? null;
        if (!$requested) $requested = $ctx['reserved_ramal'] ?? $ctx['ramal'] ?? null;
        if (!$requested) $requested = $callCtx['ramal'] ?? null;

        $requested = $requested ? preg_replace('/\D/', '', (string)$requested) : null;
        if ($requested && !preg_match('/^\d{8}$/', $requested)) $requested = null;

        // =========================================================
        // endpoints / strategy: channel_context > call_context
        // =========================================================
        $endpoints = (array)($ctx['endpoints'] ?? []);


        if (!$endpoints && !empty($callCtx['endpoints'])) $endpoints = (array)$callCtx['endpoints'];

        $endpoints = array_values(array_unique(array_filter(array_map(
            fn($r) => preg_replace('/\D/', '', (string)$r),
            $endpoints
        ))));

        $strategyPick = strtolower(trim((string)($ctx['strategy'] ?? ($callCtx['strategy'] ?? 'rrmemory'))));
        if (!in_array($strategyPick, ['rrmemory','leastrecent','linear','ringall'], true)) $strategyPick = 'rrmemory';

        // ✅ memória por endpoints (evita cruzar listas diferentes)
        $memKey = 'discador:agentes:last_ramal:global';
        if (!empty($endpoints)) {
            $tmp = $endpoints;
            sort($tmp, SORT_STRING);
            $memKey = 'discador:agentes:last_ramal:' . substr(sha1(implode(',', $tmp)), 0, 10);
        }



        // =========================================================
        // TTL reserva
        // =========================================================
        $now = time();
        $hasValidReservation = function (array $info) use ($now): bool {
            $resTtl = 60;
            if (empty($info['reserved_by'])) return false;

            $ts = (int)($info['reserved_at'] ?? 0);
            if ($ts <= 0) $ts = (int)($info['updated'] ?? 0);

            return $ts > 0 && ($now - $ts) <= $resTtl;
        };

        // =========================================================
        // agentes candidatos
        // =========================================================
        $agents          = $this->redis->hgetall('discador:agentes');
        $freeAgents      = [];
        $reservedAgents  = [];
        $requestedStatus = null;

        foreach ($agents as $ramal => $json) {

            $ramal = (string)$ramal; // ✅ garante string

            if (!preg_match('/^\d{8}$/', (string)$ramal)) continue;
            if ($endpoints && !in_array($ramal, $endpoints, true)) continue;

            $info = json_decode((string)$json, true) ?: [];
            if (empty($info['online'])) continue;

            $isReallyFree = (
                ($info['status'] ?? '') === 'LIVRE'
                && empty($info['reserved_by'])
                && empty($info['channel'])
                && empty($info['reserved_channel'])
            );

            $isReservedButValid = (
                ($info['status'] ?? '') === 'LIVRE'
                && empty($info['channel'])
                && empty($info['reserved_channel'])
                && $hasValidReservation($info)
            );

            if ($requested && $requested === $ramal && ($isReallyFree || $isReservedButValid)) {
                $requestedStatus = $isReallyFree ? 'LIVRE' : 'RESERVED_OK';
            }

            if ($isReallyFree) $freeAgents[] = $ramal;
            if ($isReservedButValid) $reservedAgents[] = $ramal;
        }

        $pickByStrategy = function (array $candidates) use ($strategyPick, $memKey): ?string {
            if (!$candidates) return null;

            // normaliza SEMPRE como string (e remove lixo)
            $candidates = array_values(array_filter(array_map(function($v){
                $v = preg_replace('/\D/', '', (string)$v);
                return $v !== '' ? $v : null;
            }, $candidates)));

            if (!$candidates) return null;

            // ordena como número (melhor que SORT_STRING pra ramal)
            sort($candidates, SORT_NUMERIC);

            if ($strategyPick === 'linear') {
                return $candidates[0] ?? null;
            }

            if ($strategyPick === 'rrmemory') {
                $last = $this->redis->get($memKey);
                $last = $last ? preg_replace('/\D/', '', (string)$last) : null;

                if ($last !== null) {
                    $i = array_search($last, $candidates, true); // agora bate
                    if ($i !== false) {
                        $rot = array_merge(
                            array_slice($candidates, $i + 1),
                            array_slice($candidates, 0, $i + 1)
                        );
                        return $rot[0] ?? $candidates[0] ?? null;
                    }
                }

                return $candidates[0] ?? null;
            }

            if ($strategyPick === 'leastrecent') {
                $best = null; $bestCalls = null; $bestTie = null;

                foreach ($candidates as $r) {
                    $raw  = $this->redis->hget('discador:agentes', $r);
                    $info = $raw ? json_decode((string)$raw, true) : [];

                    $calls = (int)($info['calls_total'] ?? 0);
                    $tie   = (int)($info['last_assigned'] ?? 0); // empate: quem ficou mais tempo sem pegar

                    if (
                        $bestCalls === null ||
                        $calls < $bestCalls ||
                        ($calls === $bestCalls && $tie < $bestTie)
                    ) {
                        $bestCalls = $calls;
                        $bestTie   = $tie;
                        $best      = $r;
                    }
                }

                return $best ?? ($candidates[0] ?? null);
            }

            return $candidates[0] ?? null;
        };

        // =========================================================
        // ringall: devolve lista e NÃO escolhe ramal único
        // =========================================================
        if ($strategyPick === 'ringall') {

            // se você quer respeitar requested (opcional):
            // - se ramalSolicitado existe e está livre, você pode colocar ele no topo
            // - mas ringall normalmente ignora requested e toca pra todos

            $targets = !empty($freeAgents) ? $freeAgents : $reservedAgents;

            $targets = array_values(array_unique(array_filter(array_map(function($r){
                $r = preg_replace('/\D/', '', (string)$r);
                return preg_match('/^\d{8}$/', $r) ? $r : null;
            }, $targets))));
            $targets = array_slice($targets, 0, 10);

            if (!$targets) {
                $cachePayload = [
                    'ok'      => false,
                    'ramal'   => null,
                    'channel' => $channelId,
                    'reason'  => 'no_free_agents',
                    'ts'      => time(),
                    'req_id'  => $reqId,
                ];
                $this->redis->setex($lastKey, 3, json_encode($cachePayload, JSON_UNESCAPED_UNICODE));
                $this->respondTransfer($channelId, false, null, 'no_free_agents', $reqId);
                return;
            }

            $cachePayload = [
                'ok'         => true,
                'mode'       => 'ringall',
                'targets'    => $targets,
                'channel'    => $channelId,
                'reason'     => 'ringall',
                'ts'         => time(),
                'req_id'     => $reqId,
                'call_id'    => $callId,
                'rr_mem_key' => $memKey,
            ];
            $this->redis->setex($lastKey, 3, json_encode($cachePayload, JSON_UNESCAPED_UNICODE));

            $this->respondTransfer($channelId, true, null, 'ringall', $reqId, [
                'mode'       => 'ringall',
                'targets'    => $targets,
                'call_id'    => $callId,
                'rr_mem_key' => $memKey,
            ]);

            return;
        }


        // =========================================================
        // escolha
        // =========================================================
        $chosen = null;
        $reason = null;

        if ($requested && ($requestedStatus === 'LIVRE' || $requestedStatus === 'RESERVED_OK')) {
            $chosen = $requested;
            $reason = ($requestedStatus === 'LIVRE')
                ? 'requested_ramal_available'
                : 'requested_ramal_reserved_ok';

        } elseif (!empty($freeAgents)) {
            $chosen = $pickByStrategy($freeAgents);

            $reason = $endpoints ? "fallback_inside_endpoints:{$strategyPick}" : "fallback_global_free_ramal:{$strategyPick}";

        } elseif (!empty($reservedAgents)) {
            $chosen = $pickByStrategy($reservedAgents);
            $reason = $endpoints ? "fallback_reserved_inside_endpoints:{$strategyPick}" : "fallback_reserved_global:{$strategyPick}";
        }

        if (!$chosen) {
            $cachePayload = [
                'ok'      => false,
                'ramal'   => null,
                'channel' => $channelId,
                'reason'  => 'no_free_agents',
                'ts'      => time(),
                'req_id'  => $reqId,
            ];
            $this->redis->setex($lastKey, 3, json_encode($cachePayload, JSON_UNESCAPED_UNICODE));
            $this->respondTransfer($channelId, false, null, 'no_free_agents', $reqId);
            return;
        }

        // ❌ NÃO grava memKey aqui — só no Redirect OK (atendimento real)

        // =========================================================
        // toma posse da reserva do escolhido
        // =========================================================
        try {
            $infoRaw = $this->redis->hget('discador:agentes', $chosen);
            $info    = $infoRaw ? json_decode((string)$infoRaw, true) : [];

            $info['reserved_by']      = 'transfer_worker';
            $info['reserved_channel'] = $channelId;
            $info['reserved_at']      = time();
            $info['updated']          = time();
            $info['last_update_by']   = 'transfer_worker_decision';

            $this->redis->hset('discador:agentes', $chosen, json_encode($info, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {}

        // =========================================================
        // channel_context (mantém call_id e guarda rr_mem_key)
        // =========================================================
        $ctx['call_id']        = $callId ?: ($ctx['call_id'] ?? null);
        $ctx['reserved_ramal'] = $chosen;
        $ctx['reserved_by']    = 'transfer_worker';
        $ctx['decided_at']     = time();
        $ctx['rr_mem_key']     = $memKey;

        $this->redis->setex("voice:channel_context:{$channelId}", 60, json_encode($ctx, JSON_UNESCAPED_UNICODE));

        // pending transfer
        $this->redis->setex(
            "voice:pending_transfer:{$channelId}",
            30,
            json_encode([
                'channel'    => $channelId,
                'ramal'      => $chosen,
                'call_id'    => $callId,
                'rr_mem_key' => $memKey,   // ✅ AQUI
                'decided_at' => time(),
                'worker'     => getmypid(),
            ], JSON_UNESCAPED_UNICODE)
        );


        // cache da decisão (pra responder retentativas sem timeout)
        $cachePayload = [
            'ok'      => true,
            'ramal'   => $chosen,
            'channel' => $channelId,
            'reason'  => $reason ?: 'ok',
            'ts'      => time(),
            'req_id'  => $reqId,
        ];
        $this->redis->setex($lastKey, 3, json_encode($cachePayload, JSON_UNESCAPED_UNICODE));

        $this->respondTransfer($channelId, true, $chosen, $reason ?: 'ok', $reqId);
    }

    private function respondTransfer(string $channelId, bool $ok, ?string $ramal, string $reason, ?string $reqId, array $extra = []): void
    {
        $payload = array_merge([
            'ok'      => $ok,
            'ramal'   => $ramal,
            'channel' => $channelId,
            'reason'  => $reason,
            'ts'      => time(),
            'req_id'  => $reqId,
        ], $extra);

        $this->redis->rpush("voice:worker_response:{$channelId}", json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    // =========================================================
    // SYNC + EXECUÇÃO REAL DO REDIRECT
    // =========================================================

    private function syncAgentsFromActiveCalls(): void
    {
        // =========================
        // 1) SNAPSHOT REAL
        // =========================
        $raw     = $this->redis->get('asterisk:active_calls');
        $payload = $raw ? json_decode($raw, true) : [];
        $calls   = $payload['chamadas'] ?? [];

        $activeChannels = [];
        foreach ($calls as $c) {
            if (!empty($c['id'])) {
                $activeChannels[(string)$c['id']] = true;
            }
        }

        // =========================
        // 2) CACHE AGENTES (1x só)
        // =========================
        $agentsRaw = $this->redis->hgetall('discador:agentes');

        $decodeAgent = static function ($json): array {
            $a = json_decode((string)$json, true);
            return is_array($a) ? $a : [];
        };

        $clearReserve = static function (array &$info): void {
            unset($info['reserved_by'], $info['reserved_channel'], $info['reserved_at']);
        };

        $normR = static function ($r): string {
            return preg_replace('/\D/', '', (string)$r);
        };

        // =========================
        // 3) EXECUTA TRANSFERS PENDENTES
        // =========================
        foreach ($this->redis->keys('voice:pending_transfer:*') as $key) {

            $data = json_decode((string)$this->redis->get($key), true);
            if (!is_array($data)) {
                $this->redis->del($key);
                continue;
            }

            $channel = $data['channel'] ?? null;
            $ramal   = $data['ramal']   ?? null;

            if (!$channel || !$ramal) {
                $this->redis->del($key);
                continue;
            }

            $channel = (string)$channel;
            $ramal   = $normR($ramal);

            if (!$this->redis->set("lock:redirect:{$channel}", getmypid(), 'NX', 'EX', 5)) {
                continue;
            }

            if (!isset($activeChannels[$channel])) {
                $this->redis->del($key);
                continue;
            }

            try {
                if (!$this->attemptRedirectToRamal($channel, $ramal)) {
                    continue;
                }

                echo "🔁 Redirect OK {$channel} → {$ramal}\n";

                // 🚨 ADICIONE ISSO
                $this->redis->del($key);

                // =====================================================
                // 3.A) LIMPA QUALQUER RESERVA LIGADA A ESSE CHANNEL
                // =====================================================
                foreach ($agentsRaw as $r => $j) {

                    $r = $normR($r);
                    if (!preg_match('/^\d{8}$/', $r)) continue;

                    $inf = $decodeAgent($j);
                    if (empty($inf['online'])) continue;

                    if (empty($inf['reserved_channel']) || (string)$inf['reserved_channel'] !== $channel) {
                        continue;
                    }

                    if ($r === $ramal) continue;

                    $isOrphan = (($inf['status'] ?? '') === 'LIVRE') && empty($inf['channel']);
                    if (!$isOrphan) continue;

                    $clearReserve($inf);
                    $inf['last_update_by'] = 'orphan_reservation_cleanup_after_redirect';

                    $this->redis->hset('discador:agentes', $r, json_encode($inf, JSON_UNESCAPED_UNICODE));
                    $agentsRaw[$r] = json_encode($inf, JSON_UNESCAPED_UNICODE);

                    echo "🧹 Reserva órfã limpa {$r} (channel {$channel})\n";
                }

                // =====================================================
                // 3.B) MARCA OCUPADO O RAMAL QUE RECEBEU O REDIRECT
                // =====================================================
                $info = [];
                $infoRaw = $this->redis->hget('discador:agentes', $ramal);
                if ($infoRaw) $info = $decodeAgent($infoRaw);

                $now = time();

                $info['status']           = 'OCUPADO';
                $info['channel']          = $channel;
                $info['reserved_by']      = 'redirect';
                $info['reserved_channel'] = $channel;
                $info['reserved_at']      = $now;

                // ✅ leastrecent = quem recebeu menos chamadas
                $info['calls_total'] = (int)($info['calls_total'] ?? 0) + 1;

                $info['last_call_ts']  = $now;
                $info['last_assigned'] = $now;

                $info['updated']        = $now;
                $info['last_update_by'] = 'redirect';

                $this->redis->hset('discador:agentes', $ramal, json_encode($info, JSON_UNESCAPED_UNICODE));
                $agentsRaw[$ramal] = json_encode($info, JSON_UNESCAPED_UNICODE);

                // =====================================================
                // 3.C) RRMEMORY: atualiza "last_ramal" AQUI (atendimento real)
                // =====================================================
                $memKey = $data['rr_mem_key'] ?? null;

                if (!$memKey) {
                    $ctxRaw = $this->redis->get("voice:channel_context:{$channel}");
                    $ctx    = $ctxRaw ? (json_decode($ctxRaw, true) ?: []) : [];
                    $memKey = $ctx['rr_mem_key'] ?? null;
                }

                if (!$memKey) {
                    $memKey = 'discador:agentes:last_ramal:global';
                }

                $this->redis->setex($memKey, 86400, $ramal);

            } catch (\Throwable $e) {
                echo "[REDIRECT] ERRO {$e->getMessage()}\n";
            }
        }

        // =========================
        // 4) LIBERAÇÃO REAL + GC
        // =========================
        foreach ($agentsRaw as $ramal => $json) {

            $ramal = $normR($ramal);
            if (!preg_match('/^\d{8}$/', $ramal)) continue;

            // ✅ SEMPRE relê do Redis pra não sobrescrever com snapshot velho
            $liveRaw = $this->redis->hget('discador:agentes', $ramal);
            $info    = $liveRaw ? $decodeAgent($liveRaw) : $decodeAgent($json);

            if (empty($info['online'])) continue;

            // ✅ garante que contador nunca some
            $info['calls_total'] = (int)($info['calls_total'] ?? 0);

            $ch = $info['channel'] ?? null;

            // 4.A) Se tem channel e ele ainda tá ativo -> não mexe
            if ($ch && isset($activeChannels[(string)$ch])) {
                continue;
            }

            // 4.B) GC: reserva ligada a reserved_channel morto
            if (!empty($info['reserved_channel']) && !isset($activeChannels[(string)$info['reserved_channel']])) {
                if (($info['status'] ?? '') === 'LIVRE' && empty($info['channel'])) {
                    $clearReserve($info);
                    $info['last_update_by'] = 'reserved_channel_dead_cleanup';

                    $this->redis->hset('discador:agentes', $ramal, json_encode($info, JSON_UNESCAPED_UNICODE));
                    $agentsRaw[$ramal] = json_encode($info, JSON_UNESCAPED_UNICODE);

                    echo "🧽 Reserva com reserved_channel morto limpa {$ramal}\n";
                    continue;
                }
            }

            // 4.C) GC: reserva sem reserved_channel (TTL)
            if (($info['status'] ?? '') === 'LIVRE' && !empty($info['reserved_by']) && empty($info['reserved_channel'])) {
                $ttl = 20;

                $ts = (int)($info['reserved_at'] ?? 0);
                if ($ts <= 0) $ts = (int)($info['updated'] ?? 0);

                if ($ts > 0 && (time() - $ts) > $ttl) {
                    $clearReserve($info);
                    $info['last_update_by'] = 'expired_reservation_cleanup';

                    $this->redis->hset('discador:agentes', $ramal, json_encode($info, JSON_UNESCAPED_UNICODE));
                    $agentsRaw[$ramal] = json_encode($info, JSON_UNESCAPED_UNICODE);

                    echo "🧹 Reserva expirada limpa {$ramal}\n";
                    continue;
                }
            }

            // 4.D) Libera OCUPADO sem canal real
            if (($info['status'] ?? '') === 'OCUPADO' && !empty($info['channel'])) {

                $info['status'] = 'LIVRE';
                $info['became_free_at'] = time();

                unset($info['channel']);
                $clearReserve($info);

                $info['updated']        = time();
                $info['last_update_by'] = 'channel_destroyed';

                $this->redis->hset('discador:agentes', $ramal, json_encode($info, JSON_UNESCAPED_UNICODE));
                $agentsRaw[$ramal] = json_encode($info, JSON_UNESCAPED_UNICODE);

                echo "🟢 Agente {$ramal} ficou LIVRE\n";
            }
        }
    }





    private function attemptRedirectToRamal(string $channel, string $ramal): bool
    {
        try {
            // 🔒 Proteção: apenas 1 redirect por channel
            $lock = $this->redis->set(
                "voice:redirected:{$channel}",
                $ramal,
                'NX',
                'EX',
                20
            );

            if (!$lock) {
                return false;
            }

            // 🔍 Confirma que o channel ainda existe
            $check = $this->http->get("ari/channels/{$channel}");
            if ($check->getStatusCode() !== 200) {
                return false;
            }

            $info = json_decode((string)$check->getBody(), true);

            // 🔍 Estados válidos para redirect
            $state = $info['state'] ?? '';
            if (!in_array($state, ['Stasis', 'Ring', 'Up'], true)) {
                error_log("[REDIRECT] Estado inválido {$state} channel={$channel}");
                return false;
            }

            // ==================================================
            // 1️⃣ TENTATIVA PRINCIPAL → DIALPLAN
            // ==================================================
            $resp = $this->http->post(
                "ari/channels/{$channel}/redirect",
                [
                    'query' => [
                        'context'   => 'from-internal', // ajuste conforme seu dialplan
                        'extension' => $ramal,
                        'priority'  => 1
                    ]
                ]
            );

            if ($resp->getStatusCode() >= 200 && $resp->getStatusCode() < 300) {
                return true;
            }

            // ==================================================
            // 2️⃣ FALLBACK → ENDPOINT DIRETO
            // ==================================================
            $endpoint = "PJSIP/{$ramal}";

            $resp = $this->http->post(
                "ari/channels/{$channel}/redirect",
                [
                    'query' => [
                        'endpoint' => $endpoint
                    ]
                ]
            );

            return $resp->getStatusCode() >= 200 && $resp->getStatusCode() < 300;

        } catch (\Throwable $e) {

            error_log("[REDIRECT ❌] {$channel} → {$ramal} | {$e->getMessage()}");
            return false;
        }
    }
}
