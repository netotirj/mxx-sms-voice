#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use WebSocket\Client as WsClient;
use Predis\Client as RedisClient;


class StasisListenerAsterisk
{
    private string $ariUser;
    private string $ariPass;
    private string $ariHost;
    private string $stasisApp;
    private HttpClient $http;
    private WsClient $ws;
    private RedisClient $redis;
    private array $channelData = [];
    private int $dtmfCooldownSeconds = 3; // tempo de bloqueio para repetição de mesma tecla
    private array $pendingVars = [];

    public function __construct(string $ariUser, string $ariPass, string $ariHost, string $stasisApp)
    {
        $this->ariUser = $ariUser;
        $this->ariPass = $ariPass;
        $this->ariHost = $ariHost;
        $this->stasisApp = $stasisApp;

        $this->http = new HttpClient(['auth' => [$ariUser, $ariPass]]);
        $this->ws = new WsClient("ws://{$ariUser}:{$ariPass}@{$ariHost}:8088/ari/events?app={$stasisApp}&subscribeAll=true");

        // 🔹 Redis
        $this->redis = new RedisClient([
            'scheme' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => 'mxx123'
        ]);
    }


    public function run()
    {

        while (true) {
            try {
                $raw = $this->ws->receive();
                $event = json_decode($raw, true);
                if (!$event) continue;
                $this->handleEvent($event);


            } catch (Exception $e) {
                //echo "[!] WebSocket error: {$e->getMessage()}\n";
                sleep(3);
            }
        }
    }

    private function handleEvent(array $event)
    {
        $type = $event['type'] ?? '';

        switch ($type) {

            case 'ChannelVarset':
                $this->handleChannelVarset($event);
                break;

            case 'StasisStart':
                $this->onStart($event);
                break;

            case 'ChannelDestroyed':
            case 'ChannelHangupRequest':
            case 'StasisEnd':
                $this->handleChannelDestroyed($event);
                break;

            case 'ChannelStateChange':
                $this->handleChannelStateChange($event);
                break;

            case 'ChannelDtmfReceived':
                $this->onDtmf($event);
                break;

            case 'PlaybackFinished':
                $this->onPlaybackFinished($event);
                break;

            case 'ChannelEnteredBridge':
                $id = $event['channel']['id'] ?? null;
                if ($id && isset($this->channelData[$id])) {
                    $this->channelData[$id]['in_bridge'] = true;
                    $this->updateRedis();
                    echo "[🔗] Canal {$id} entrou na bridge.\n";
                }
                break;

            case 'ChannelLeftBridge':
                $id = $event['channel']['id'] ?? null;
                if ($id && isset($this->channelData[$id])) {
                    $this->channelData[$id]['in_bridge'] = false;
                    $this->updateRedis();
                    echo "[❌] Canal {$id} saiu da bridge.\n";
                }
                break;

            case 'Dial':
                $this->handleDialEvent($event);
                break;

            default:
                // opcional: log silencioso
                // echo "[EVENT] Ignorado: {$type}\n";
                break;
        }
    }




    private function notifyWorkerCheckRequest(string $channelId, ?string $ramalSolicitado = null): void
    {
        try {
            $payload = [
                'type' => 'check_transfer', // novo tipo, mais claro
                'channel' => $channelId,
                'requested_ramal' => $ramalSolicitado,
                'ts' => time()
            ];

            // Fila onde o worker lê solicitações
            $this->redis->rpush('voice:transfer_request', json_encode($payload, JSON_UNESCAPED_UNICODE));

            error_log("[WORKER-NOTIFY] Verificação de transferência enviada: " . json_encode($payload));
        } catch (\Throwable $e) {
            error_log("[WORKER-NOTIFY ⚠️] Falha ao notificar: " . $e->getMessage());
        }
    }

    private function handleChannelVarset(array $event): void
    {
        $channelId = $event['channel']['id'] ?? null;
        $var       = $event['variable'] ?? null;
        $value     = $event['value'] ?? null;
        if (!$channelId || !$var) return;

        // Atualiza variáveis em canal ativo ou pendente
        if (isset($this->channelData[$channelId])) {
            $this->channelData[$channelId]['vars'][$var] = $value;
        } else {
            // Apenas guarda, sem recriar o canal ainda
            $this->pendingVars[$channelId][$var] = $value;
            echo "[VARSET ⏳] Guardando {$var}={$value} até canal {$channelId} existir\n";
        }

        // Loga variáveis importantes
        if (in_array($var, ['OWNER_ID', 'TENANT_ID', 'TYPE', 'ROLE'], true)) {
            error_log("[VARS] Canal={$channelId} → {$var}={$value}");
        }

        // Se OWNER/TENANT chegaram e há FAIL-CDR pendente, tenta salvar agora
        if (
            in_array($var, ['OWNER_ID', 'TENANT_ID'], true) &&
            isset($this->channelData[$channelId])
        ) {
            $this->trySavePendingFailCdr($channelId);
        }

        // Atualiza painel se canal ainda existe
        if (isset($this->channelData[$channelId])) {
            $this->updateRedis();
        }
    }


    private function handleChannelDestroyed(array $event): void
    {
        $channelId = $event['channel']['id'] ?? null;
        if (!$channelId) return;

        // causa / cause_txt
        $causeCode = $event['cause'] ?? null;
        $causeText = $event['cause_txt'] ?? null;

        if (isset($this->channelData[$channelId])) {
            $this->channelData[$channelId]['ended']     = $this->channelData[$channelId]['ended']     ?? time();
            $this->channelData[$channelId]['cause']     = $this->channelData[$channelId]['cause']     ?? $causeCode;
            $this->channelData[$channelId]['cause_txt'] = $this->channelData[$channelId]['cause_txt'] ?? $causeText;
        }

        // resolve peer
        $peer = $this->channelData[$channelId]['peer'] ?? null;

        if (!$peer) {
            foreach ($this->channelData as $cid => $info) {
                if (($info['peer'] ?? null) === $channelId) {
                    $peer = $cid;
                    break;
                }
            }
        }

        // ==============================================================
        // 🔓 LIBERAÇÃO CORRETA DE AGENTE (RAMAL)
        // ==============================================================

        $channelType = $this->channelData[$channelId]['type'] ?? null;

        if ($channelType === 'AGENT' && empty($this->channelData[$channelId]['agent_released'])) {

            $agentId = $this->channelData[$channelId]['agent_id'] ?? null;

            if ($agentId) {
                $this->releaseAgentByChannel($channelId, 'agent_channel_destroyed');
                $this->channelData[$channelId]['agent_released'] = true;

                error_log("[AGENT] 🔓 Ramal {$agentId} liberado (canal {$channelId})");
            }
        }



        // identifica canal principal
        $isMain =
            isset($this->channelData[$channelId]['vars']['OWNER_ID']) ||
            isset($this->channelData[$channelId]['vars']['TYPE']);

        // detecta tronco
        $name = $this->channelData[$channelId]['name'] ?? '';
        $isTrunk = preg_match('/^PJSIP\/mxx\d+-/i', $name);

        // evita duplicata
        $dupKey  = "cdr-saved:{$channelId}";
        $failKey = "cdr-falha-pendente:{$channelId}";

        // ==============================================================
        // 1) FAIL-CDR pendente
        // ==============================================================
        try {
            $failJson = $this->redis->get($failKey);

            if ($failJson) {

                $failEvent  = json_decode($failJson, true) ?: [];
                $dialStatus = $failEvent['dialstatus'] ?? 'FAILED';

                if ($dialStatus === 'PROGRESS') {
                    // ignorar parcial
                    error_log("[CDR] ⏭️ Ignorando CDR PROGRESS (canal {$channelId})");
                    $this->redis->del($failKey);

                } else {
                    // armazenar pendência na memória e tentar salvar
                    $this->channelData[$channelId]['pending_fail_cdr'] = $failEvent;

                    $saved = $this->trySavePendingFailCdr($channelId);

                    if (!$saved) {
                        // precisa esperar VARSET (OWNER/TENANT)
                        error_log("[CDR] ⏳ Aguardando VARSET para salvar FAIL-CDR canal {$channelId}");
                        return;
                    }
                }
            }

        } catch (\Throwable $e) {
            error_log("[CDR] ⚠ Falha no FAIL-CDR: ".$e->getMessage());
        }



        // ==============================================================
        // 2) Chamadas atendidas → tarifação
        // ==============================================================
        if ($isMain && !empty($this->channelData[$channelId]['answered'])) {
            try {
                $this->calculateTariff($channelId);
                $this->channelData[$channelId]['tariff_done'] = true;
                $this->redis->setex($dupKey, 300, 1);
            } catch (\Throwable $e) {
                error_log("[CDR] ❌ Erro ao tarifar canal {$channelId}: ".$e->getMessage());
            }
        }

        // ==============================================================
        // 3) Ramal encerrou antes → tarifar tronco
        // ==============================================================
        if (!$isTrunk && $peer && isset($this->channelData[$peer])) {

            foreach (['started', 'answered', 'ended', 'vars'] as $k) {
                if (empty($this->channelData[$peer][$k]) && !empty($this->channelData[$channelId][$k])) {
                    $this->channelData[$peer][$k] = $this->channelData[$channelId][$k];
                }
            }

            $this->channelData[$peer]['ended'] = $this->channelData[$peer]['ended'] ?? time();

            try {
                $this->http->delete("http://{$this->ariHost}:8088/ari/channels/{$peer}", ['http_errors' => false]);
                $this->calculateTariff($peer);
                $this->channelData[$peer]['tariff_done'] = true;
                $this->redis->setex("cdr-saved:{$peer}", 300, 1);

                error_log("[CDR] 💰 Tarifação forçada do tronco {$peer}");
            } catch (\Throwable $e) {
                error_log("[CDR] ⚠ Erro no tronco forçado: ".$e->getMessage());
            }
        }

        // ==============================================================
        // 🔎 FALLBACK — cliente encerrou, ramal ainda existe
        // ==============================================================

        if ($isMain && $peer && isset($this->channelData[$peer])) {

            if (!empty($this->channelData[$peer]['agent_released'])) {
                // já liberado
            } else {

                $peerType = $this->channelData[$peer]['type'] ?? null;

                if ($peerType === 'AGENT') {

                    $agentId = $this->channelData[$peer]['agent_id'] ?? null;

                    if ($agentId) {
                        $this->releaseAgentByChannel($peer, 'main_channel_destroyed');
                        $this->channelData[$peer]['agent_released'] = true;

                        error_log("[AGENT] 🔓 Ramal {$agentId} liberado após cliente desligar");
                    }
                }
            }
        }



        // ==============================================================
        // 5) LIMPEZA FINAL — **REMOVER SEMPRE SE O CDR FOI SALVO**
        // ==============================================================

        $cdrSaved = $this->redis->exists($dupKey);

        // remover canal SE:
        //   - FAIL-CDR salvo
        //   - OU canal não está aguardando info crítica
        $needsWait =
            empty($this->channelData[$channelId]['dialstatus']) ||
            ($this->channelData[$channelId]['dialstatus'] === 'PROGRESS') ||
            !empty($this->channelData[$channelId]['pending_fail_cdr']);

        if ($cdrSaved || !$needsWait) {
            unset($this->channelData[$channelId]);
            error_log("[CDR] 🧹 Canal {$channelId} removido com segurança.");
        } else {
            error_log("[CDR] ⏳ NÃO removido — aguardando eventos finais (canal {$channelId})");
        }

        $this->updateRedis();
    }




    private function handleChannelStateChange(array $event)
    {
        $channelId = $event['channel']['id'] ?? null;
        if (!$channelId) return;
        // PATCH — registra o nome do canal
        $this->channelData[$channelId]['name'] = $event['channel']['name'] ?? ($this->channelData[$channelId]['name'] ?? null);


        $state = strtolower($event['channel']['state'] ?? '');
        $caller = $event['channel']['caller']['number'] ?? 'Desconhecido';
        $oldState = $this->channelData[$channelId]['state'] ?? '(novo)';

        // 🔹 Se o canal ainda não existir, inicializa corretamente
        if (!isset($this->channelData[$channelId])) {
            $this->channelData[$channelId] = [
                'id' => $channelId,
                'number' => $caller,
                'status' => '📞 Tocando',
                'state' => $state,
                'started' => time(),
                'duration' => '00:00'
            ];
            echo "[+] Novo canal criado em handleChannelStateChange: {$channelId} ({$caller})\n";
        }

        // 🔹 Atualiza estado e status humanos
        $this->channelData[$channelId]['state'] = $state;

        switch ($state) {
            case 'ringing':
                $this->channelData[$channelId]['status'] = '📞 Tocando';
                break;

            case 'up':
                if (!isset($this->channelData[$channelId]['answered'])) {
                    $this->channelData[$channelId]['answered'] = time();
                }
                $this->channelData[$channelId]['status'] = '✅ Atendida';
                $this->channelData[$channelId]['duration'] = gmdate(
                    "i:s",
                    time() - ($this->channelData[$channelId]['answered'] ?? $this->channelData[$channelId]['started'])
                );
                echo "[✔] Canal {$channelId} agora está ATENDIDO\n";


                break;

            case 'busy':
                $this->channelData[$channelId]['status'] = '⛔ Ocupada';
                break;

            case 'down':
                $this->channelData[$channelId]['status'] = '🔚 Finalizada';
                $this->channelData[$channelId]['ended'] = time();
                $this->channelData[$channelId]['duration'] = isset($this->channelData[$channelId]['answered'])
                    ? gmdate("i:s", $this->channelData[$channelId]['ended'] - $this->channelData[$channelId]['answered'])
                    : '00:00';
                break;
        }

        // 🔹 Associa variáveis pendentes
        if (isset($this->pendingVars[$channelId])) {
            $this->channelData[$channelId]['vars'] = $this->pendingVars[$channelId];
            unset($this->pendingVars[$channelId]);
        }

        // 🔹 Atualiza Redis
        $this->updateRedis();

        echo "[~] Estado alterado: {$oldState} → {$state} (canal {$channelId})\n";

        // 🔹 Mantém lógica de bridge
        if ($state === 'up' && isset($this->channelData[$channelId]['peer'])) {
            $peer = $this->channelData[$channelId]['peer'];

            if (!isset($this->channelData[$channelId]['bridge'])) {
                $bridgeResp = $this->http->post("http://{$this->ariHost}:8088/ari/bridges", ['json' => ['type' => 'mixing']]);
                $bridge = json_decode((string)$bridgeResp->getBody(), true);
                $bridgeId = $bridge['id'] ?? null;

                if ($bridgeId) {
                    $this->http->post("http://{$this->ariHost}:8088/ari/bridges/{$bridgeId}/addChannel", [
                        'json' => ['channel' => [$channelId, $peer]]
                    ]);
                    $this->channelData[$channelId]['bridge'] = $bridgeId;
                    $this->channelData[$peer]['bridge'] = $bridgeId;
                    echo "[🔗] Bridge criada entre {$channelId} e {$peer}\n";
                }
            }
        }

        // 🔹 Mantém lógica de bridge
        if ($state === 'up' && isset($this->channelData[$channelId]['peer'])) {
            $peer = $this->channelData[$channelId]['peer'];

            if (!isset($this->channelData[$channelId]['bridge'])) {
                try {
                    // ✅ Cria bridge mixing
                    $bridgeResp = $this->http->post("http://{$this->ariHost}:8088/ari/bridges", [
                        'json' => ['type' => 'mixing']
                    ]);
                    $bridge = json_decode((string)$bridgeResp->getBody(), true);
                    $bridgeId = $bridge['id'] ?? null;

                    if ($bridgeId) {
                        // ✅ Adiciona ambos os canais (cliente e ramal)
                        $this->http->post("http://{$this->ariHost}:8088/ari/bridges/{$bridgeId}/addChannel", [
                            'json' => ['channel' => [$channelId, $peer]]
                        ]);
                        $this->channelData[$channelId]['bridge'] = $bridgeId;
                        $this->channelData[$peer]['bridge'] = $bridgeId;
                        echo "[🔗] Bridge criada entre {$channelId} e {$peer}\n";
                    }

                    // ✅ Após criar bridge, encerra o canal de origem (tronco) se não for o do ramal
                    $chName = $this->channelData[$channelId]['name'] ?? '';
                    if (!preg_match('/PJSIP\/\d+/', $chName)) {
                        try {
                            $this->http->delete("http://{$this->ariHost}:8088/ari/channels/{$channelId}", ['http_errors' => false]);
                            echo "[CLEANUP] 🔚 Canal de origem {$channelId} encerrado após bridge com {$peer}\n";
                        } catch (\Throwable $e) {
                            echo "[CLEANUP ⚠️] Falha ao encerrar canal {$channelId}: {$e->getMessage()}\n";
                        }
                    }
                } catch (\Throwable $e) {
                    echo "[BRIDGE ⚠️] Erro ao criar bridge entre {$channelId} e {$peer}: {$e->getMessage()}\n";
                }
            }
        }

    }

    private function onStart(array $event)
    {
        $channelId = $event['channel']['id'] ?? null;
        if (!$channelId) return;

        // tenta identificar job_id vindo do originate
        $jobId = $event['variables']['JOB_ID']
            ?? $this->channelData[$channelId]['vars']['JOB_ID']
            ?? null;

        if ($jobId) {

            $ctxRaw = $this->redis->get("voice:job_context:{$jobId}");

            if ($ctxRaw) {
                $ctx = json_decode($ctxRaw, true);

                if (!empty($ctx['ramal'])) {
                    $this->redis->setex(
                        "voice:channel_context:{$channelId}",
                        300,
                        json_encode([
                            'ramal'  => $ctx['ramal'],
                            'job_id' => $jobId,
                            'ts'     => time()
                        ])
                    );
                }
            }
        }

        // -----------------------------
        // Nome real do canal sempre salvo
        // -----------------------------
        $this->channelData[$channelId]['name'] = $event['channel']['name'] ?? null;

        // -----------------------------
        // Extrai appArgs
        // -----------------------------
        $appArgsRaw = trim($event['channel']['dialplan']['app_data'] ?? '');
        if (str_contains($appArgsRaw, ',')) {
            $parts = explode(',', $appArgsRaw, 2);
            $appArgsRaw = $parts[1] ?? '';
        }
        $appArgsRaw = trim($appArgsRaw, "\"'");
        $appArgs = json_decode($appArgsRaw, true);
        if (!is_array($appArgs)) $appArgs = [];

        $mainAudios = $appArgs['audio'] ?? [];
        if (!is_array($mainAudios)) $mainAudios = [$mainAudios];
        $dtmf = $appArgs['dtmf'] ?? [];
        $voiceOnly = $appArgs['action'];
        echo "[DEBUG] TIPO TORPEDO EH {$voiceOnly} \n";


        // -----------------------------
        // CallerID normalizado
        // -----------------------------
        $callerNumber = $event['channel']['caller']['number'] ?? 'anonymous';
        $callerName   = $event['channel']['caller']['name'] ?? $callerNumber;

        if (strlen($callerNumber) > 11) {
            $callerNumber = substr($callerNumber, -11);
        }

        // -----------------------------
        // Inicializa o canal
        // -----------------------------
        if (!isset($this->channelData[$channelId])) {
            $this->channelData[$channelId] = [
                'id'             => $channelId,
                'extension'      => $callerNumber,
                'caller_name'    => $callerName,
                'mainAudios'     => $mainAudios,
                'dtmf'           => $dtmf,
                'voice_only'     => $voiceOnly, // ✅ AQUI ESTAVA FALTANDO
                'dtmf_received'  => false,
                'handled_dtmf'   => [],
                'peer'           => null,
                'started'        => time(),
                'status'         => 'Chamando...',
                'state'          => 'ringing',
                'duration'       => '00:00',
                'vars'           => [],
            ];
            echo "[📞] Novo canal iniciado: {$callerNumber} ({$channelId})\n";
        } else {
            // Apenas atualiza info
            $this->channelData[$channelId]['mainAudios'] = $mainAudios;
            $this->channelData[$channelId]['dtmf']       = $dtmf;
            $this->channelData[$channelId]['caller_name'] = $callerName;
            $this->channelData[$channelId]['voice_only']  = $voiceOnly; // ✅ segurança
        }

        // -----------------------------
        // Merge variáveis do Dialplan/Originate
        // -----------------------------
        $ariVars = $event['variables'] ?? [];
        $this->channelData[$channelId]['vars'] = array_merge(
            $this->channelData[$channelId]['vars'] ?? [],
            $ariVars
        );

        // -----------------------------
        // SALVAR IMEDIATAMENTE NO REDIS (evita race!)
        // -----------------------------
        try {
            $redisKey = 'discador:stasis:channels';
            $this->redis->hset($redisKey, $channelId, json_encode($this->channelData[$channelId]));
            echo "[REDIS] Canal {$channelId} salvo em {$redisKey}\n";
        } catch (\Throwable $e) {
            echo "[REDIS ⚠️] Falha ao salvar canal: {$e->getMessage()}\n";
        }

        // -----------------------------
        // Toca áudio inicial se existir
        // -----------------------------
        if (!empty($mainAudios)) {
            $this->playMainAudio($channelId);
        }

        // -----------------------------
        // Atender canal imediatamente
        // -----------------------------
        try {
            $this->http->post("http://{$this->ariHost}:8088/ari/channels/{$channelId}/answer");
            usleep(200000);
            $this->channelData[$channelId]['state']    = 'up';
            $this->channelData[$channelId]['answered'] = time();
        } catch (\Exception $e) {
            echo "[⚠️] Falha ao atender canal {$channelId}: {$e->getMessage()}\n";
        }

        // -----------------------------
        // Atualiza no Redis após atender
        // -----------------------------
        try {
            $this->redis->hset('discador:stasis:channels', $channelId, json_encode($this->channelData[$channelId]));
        } catch (\Throwable $e) {}
    }


    private function playMainAudio(string $channelId, int $index = 0): void
    {
        $mainAudios = $this->channelData[$channelId]['mainAudios'] ?? [];
        if (!isset($mainAudios[$index])) {
            echo "[⚠️] Nenhum áudio definido para índice {$index} no canal {$channelId}\n";
            return;
        }

        $media = $mainAudios[$index];
        $playbackId = "play_" . substr(md5($channelId . microtime()), 0, 6);
        $url = "http://{$this->ariHost}:8088/ari/channels/{$channelId}/play/{$playbackId}";

        // Guarda o playback atual
        $this->channelData[$channelId]['current_playback'] = [
            'id' => $playbackId,
            'index' => $index,
            'media' => $media
        ];

        // 🔹 Cria subprocesso para o playback
        $pid = pcntl_fork();

        if ($pid === -1) {
            echo "[❌] Falha ao criar processo filho para {$channelId}\n";
            return;
        }

        if ($pid === 0) {
            // === Processo filho ===
            try {
                echo "[🎧 child] Iniciando playback {$playbackId} ({$media}) em {$channelId}\n";

                // 🔸 Cria um novo cliente HTTP isolado (sem herdar conexões do pai)
                $client = new HttpClient([
                    'auth' => [$this->ariUser, $this->ariPass],
                    'headers' => ['Content-Type' => 'application/json'],
                    'timeout' => 3,
                    'http_errors' => false
                ]);

                $response = $client->post($url, ['json' => ['media' => $media]]);
                $status = $response->getStatusCode();

                if (in_array($status, [200, 201])) {
                    echo "[✅ child] Playback {$playbackId} executado com sucesso (HTTP {$status})\n";
                } else {
                    echo "[⚠️ child] Playback {$playbackId} falhou (HTTP {$status})\n";
                }

                // 🔹 Espera um pouco para garantir que o ARI processe o comando antes do processo filho encerrar
                usleep(300000); // 300ms

            } catch (\Throwable $e) {
                echo "[❌ child] Erro ao tocar {$playbackId}: {$e->getMessage()}\n";
            }

            // 🚪 Sai do processo filho para evitar duplicação do loop principal
            exit(0);
        }

        // === Processo pai ===
        echo "[🎵 parent] Playback disparado (PID={$pid}) {$media} em {$channelId}\n";

        // 🔹 Guarda PID para monitoramento ou limpeza futura
        $this->channelData[$channelId]['child_pid'] = $pid;
    }


    private function playWorkerAudio(string $channelId, string $audioName): void
    {
        // ============================================================
        // 🎵 Tabela interna do Worker — não afeta mainAudios
        // ============================================================
        $workerAudios = [
            "agents-busy" => "sound:voice/standard-sounds/agents-busy",
            //"erro_transferencia" => "sound:en/sorry-youre-having-problems",
            //"ramal_invalido"     => "sound:en/pbx-invalid",
            //"ok"                 => "sound:en/beep"
        ];


        // Se não existir → usar fallback padrão
        if (!isset($workerAudios[$audioName])) {
            echo "[⚠️ WorkerAudio] '{$audioName}' não existe, usando padrão\n";
            $media = "sound:pt_BR/vm-invalid";
        } else {
            $media = $workerAudios[$audioName];
        }

        // ============================================================
        // 🎧 Dispara o playback
        // ============================================================
        $playbackId = "wplay_" . substr(md5($channelId . microtime()), 0, 6);
        $url = "http://{$this->ariHost}:8088/ari/channels/{$channelId}/play/{$playbackId}";

        try {
            $response = $this->http->post($url, ['json' => ['media' => $media]]);
            $status = $response->getStatusCode();

            echo "[WORKER-AUDIO] 🔊 {$audioName} → {$media} (HTTP {$status})\n";

        } catch (\Throwable $e) {
            echo "[❌ WorkerAudio] Erro ao tocar {$audioName}: {$e->getMessage()}\n";
        }
    }


    /**
     * Manipula eventos DTMF recebidos durante uma chamada ativa.
     * @throws GuzzleException
     */
    private function onDtmf(array $event)
    {
        $digit     = $event['digit'] ?? null;
        $channelId = $event['channel']['id'] ?? null;

        if (!$digit || !$channelId) {
            return;
        }

        $now = time();

        // ============================================================
        // 🔹 Registro inicial de DTMF
        // ============================================================
        $this->channelData[$channelId]['dtmf_received'] = true;
        $this->channelData[$channelId]['last_dtmf']      = $digit;

        if (!isset($this->channelData[$channelId]['handled_dtmf'])) {
            $this->channelData[$channelId]['handled_dtmf'] = [];
        }

        // ============================================================
        // 🔹 Cooldown de DTMF
        // ============================================================
        $lastUse = $this->channelData[$channelId]['handled_dtmf'][$digit] ?? 0;

        if ($lastUse && ($now - $lastUse) < $this->dtmfCooldownSeconds) {
            echo "[DTMF] Ignorado {$digit} (cooldown {$this->dtmfCooldownSeconds}s)\n";
            return;
        }

        echo "[DTMF] Recebido {$digit} no canal {$channelId}\n";

        // ============================================================
        // 🔹 Interrompe playback ativo sem afetar MOH
        // ============================================================
        if (!empty($this->channelData[$channelId]['current_playback']['id'])) {

            $currentPlayback = $this->channelData[$channelId]['current_playback']['id'];

            echo "[DTMF] ⏹ Parando playback {$currentPlayback}\n";

            $this->http->delete(
                "http://{$this->ariHost}:8088/ari/playbacks/{$currentPlayback}",
                ['http_errors' => false]
            );

            unset($this->channelData[$channelId]['current_playback']);
            usleep(150000);
        }

        // ============================================================
        // 🔹 '#' encerra a chamada imediatamente
        // ============================================================
        if ($digit === '#') {
            echo "[DTMF] Encerrando chamada via #\n";
            $this->markDtmfCooldown($channelId, $digit);
            $this->ChannelFinishDestroyed($channelId, "Encerrada via DTMF (#)", true);
            return;
        }

        // ============================================================
        // 🔹 Busca ações configuradas
        // ============================================================
        $ch = $this->channelData[$channelId] ?? null;

        if (!$ch || empty($ch['dtmf'])) {
            return;
        }

        foreach ($ch['dtmf'] as $item) {

            if ((string)$item['digit'] !== (string)$digit) {
                continue;
            }

            $audio  = $item['audio'] ?? ($item['uploaded_audio'] ?? null);
            $action = $item['action'] ?? null;

            // ============================================================
            // 🔹 Feedback sonoro — antes de MOH
            // ============================================================
            if ($audio) {

                echo "[DTMF] 🔊 Tocando áudio: {$audio}\n";

                $pid = "dtmf_" . substr(md5($channelId . microtime()), 0, 6);

                try {
                    $this->http->post(
                        "http://{$this->ariHost}:8088/ari/channels/{$channelId}/play/{$pid}",
                        ['json' => ['media' => $audio]]
                    );
                } catch (\Throwable $e) {
                    echo "[DTMF] ⚠ Erro ao tocar áudio: {$e->getMessage()}\n";
                }

                $this->channelData[$channelId]['current_playback'] = [
                    'id'    => $pid,
                    'media' => $audio,
                    'ts'    => time()
                ];

                // aguarda playback finalizar antes de seguir
                $this->waitPlaybackFinish($channelId);
            }

            $this->updateRedis();

            // ============================================================
            // 🔹 AÇÃO: HANGUP
            // ============================================================
            if ($action === 'hangup') {
                echo "[DTMF] Hangup via DTMF\n";
                $this->markDtmfCooldown($channelId, $digit);
                $this->ChannelFinishDestroyed($channelId, "Encerrada via DTMF (hangup)", true);
                return;
            }

            // ============================================================
            // 🔹 AÇÃO: TRANSFERÊNCIA
            // ============================================================
            if ($action === 'transfer') {

                // -----------------------------------------
                // 🔎 Determina ramal solicitado
                // -----------------------------------------
                $ramalSolicitado =
                    ($item['ramal'] ?? null)
                        ?: ($ch['vars']['EXTENSION'] ?? null);

                if (!$ramalSolicitado) {
                    echo "[TRANSFER] ❌ Nenhum ramal definido\n";
                    $this->playWorkerAudio($channelId, "agents-busy");
                    $this->markDtmfCooldown($channelId, $digit);
                    return;
                }

                echo "[TRANSFER] 🔍 Validando ramal {$ramalSolicitado}...\n";

                // -----------------------------------------
                // 🔸 1) Consulta inicial ao Worker
                // -----------------------------------------
                $this->notifyWorkerCheckRequest($channelId, $ramalSolicitado);
                $resp = $this->waitWorkerResponse($channelId, 3);

                if (!$resp) {
                    echo "[TRANSFER] ⚠ Worker não respondeu\n";
                    $this->playWorkerAudio($channelId, "agents-busy");
                    $this->markDtmfCooldown($channelId, $digit);
                    return;
                }

                // -----------------------------------------
                // 🔸 2) Retentativas com MOH
                // -----------------------------------------
                $tent = 0;
                $maxTent = 3;

                while (empty($resp['ok']) && $tent < $maxTent) {

                    $tent++;
                    echo "[TRANSFER] ❌ Indisponível ({$tent}/{$maxTent})\n";

                    // toca aviso ANTES do MOH
                    $this->playWorkerAudio($channelId, "agents-busy");
                    $this->waitPlaybackFinish($channelId);

                    // inicia música só depois do áudio
                    //$this->playMoh($channelId);

                    sleep(10);

                    echo "[TRANSFER] 🔁 Reconsulta Worker...\n";

                    $this->notifyWorkerCheckRequest($channelId, $ramalSolicitado);
                    $resp = $this->waitWorkerResponse($channelId, 3);

                    if (!$resp) {
                        echo "[TRANSFER] ⚠ Falha na tentativa {$tent}\n";
                        //$this->stopMoh($channelId);
                        $this->playWorkerAudio($channelId, "agents-busy");
                        $this->markDtmfCooldown($channelId, $digit);
                        return;
                    }
                }

                // -----------------------------------------
                // ❌ Falha final
                // -----------------------------------------
                if (empty($resp['ok'])) {
                    echo "[TRANSFER] ❌ Nenhum ramal livre\n";
                    //$this->stopMoh($channelId);
                    $this->playWorkerAudio($channelId, "agents-busy");
                    $this->markDtmfCooldown($channelId, $digit);
                    $this->ChannelFinishDestroyed($channelId, "Falha na transferência", true);
                    return;
                }

                // -----------------------------------------
                // ✅ Sucesso
                // -----------------------------------------
                $ramal = $resp['ramal'] ?? $ramalSolicitado;

                echo "[TRANSFER] ✅ Aprovado → {$ramal}\n";

                // inicia MOH até completar transferência
                //$this->playMoh($channelId);

                try {
                    $this->transferToRamal($channelId, $ramal);
                } catch (\Throwable $e) {
                    echo "[TRANSFER] ❌ ERRO: {$e->getMessage()}\n";
                    //$this->stopMoh($channelId);
                    $this->playWorkerAudio($channelId, "agents-busy");
                    $this->markDtmfCooldown($channelId, $digit);
                    return;
                }

                //$this->stopMoh($channelId);
                $this->markDtmfCooldown($channelId, $digit);
                return;
            }
        }
    }

    private function releaseAgentByChannel( string $channelId, string $by = 'channel_destroyed'): void {
        try {
            $agents = $this->redis->hgetall('discador:agentes');

            foreach ($agents as $ramal => $json) {

                if (!preg_match('/^\d{8}$/', $ramal)) {
                    continue;
                }

                $info = json_decode($json, true) ?: [];

                // 🔒 já está livre → idempotente
                if (($info['status'] ?? '') === 'LIVRE') {
                    continue;
                }

                // 🔑 canal associado ao agente
                $agentChannel =
                    $info['channel']
                    ?? $info['reserved_channel']
                    ?? null;

                if ($agentChannel !== $channelId) {
                    continue;
                }

                // 🔓 LIBERA AGENTE
                unset(
                    $info['channel'],
                    $info['reserved_by'],
                    $info['reserved_channel']
                );

                $info['status']         = 'LIVRE';
                $info['updated']        = time();
                $info['last_update_by'] = $by;

                $this->redis->hset(
                    'discador:agentes',
                    $ramal,
                    json_encode($info, JSON_UNESCAPED_UNICODE)
                );

                error_log(
                    "🟢 [AGENT] Ramal {$ramal} liberado ({$by}) canal={$channelId}"
                );

                return;
            }
        } catch (\Throwable $e) {
            error_log(
                "[AGENT] Erro ao liberar agente canal={$channelId}: ".$e->getMessage()
            );
        }
    }



    private function waitPlaybackFinish(string $channelId, int $timeout = 6)
    {
        $start = time();

        while (time() - $start < $timeout) {

            if (empty($this->channelData[$channelId]['current_playback'])) {
                return true;
            }

            usleep(200000);
        }

        unset($this->channelData[$channelId]['current_playback']);
        return false;
    }

    /**
     * marca cooldown corretamente
     */
    private function markDtmfCooldown(string $channelId, string $digit): void
    {
        $this->channelData[$channelId]['handled_dtmf'][$digit] = time();
        $this->updateRedis();
    }




    private function waitWorkerResponse(string $channelId, int $timeoutSec = 3): ?array
    {
        $key = "voice:worker_response:{$channelId}";
        $limit = microtime(true) + $timeoutSec;

        while (microtime(true) < $limit) {

            try {
                $raw = $this->redis->lpop($key);

                if (!$raw) {
                    usleep(150_000); // 150ms
                    continue;
                }

                $decoded = json_decode($raw, true);

                if (!is_array($decoded)) {
                    echo "[WORKER] ⚠️ Resposta inválida: {$raw}\n";
                    continue;
                }

                // 🚨 Segurança: confirma canal
                if (($decoded['channel'] ?? null) !== $channelId) {
                    echo "[WORKER] ⚠️ Resposta ignorada — canal incorreto\n";
                    continue;
                }

                // 🚨 Segurança extra: resposta muito antiga (opcional)
                if (isset($decoded['ts']) && $decoded['ts'] < time() - 10) {
                    echo "[WORKER] ⚠️ Resposta antiga ignorada\n";
                    continue;
                }

                echo "[WORKER] 🔄 Resposta recebida {$channelId}: "
                    . json_encode($decoded, JSON_UNESCAPED_UNICODE) . "\n";

                return $decoded;

            } catch (\Throwable $e) {
                echo "[WORKER] ⚠️ Erro ao ler resposta: {$e->getMessage()}\n";
            }
        }

        echo "[WORKER] ⏳ Timeout esperando worker para {$channelId}\n";
        return null;
    }

    private function transferToRamal(string $channelId, string $ramal)
    {
        echo "[TRANSFER] 🔁 Execução da transferência: {$channelId} → {$ramal}\n";

        try {
            // 📌 1. Captura CallerID real do cliente
            $callerId = $this->channelData[$channelId]['vars']['CALLERID(num)']
                ?? $this->channelData[$channelId]['extension']
                ?? '0000';

            // 📌 2. Captura o número destino original (cliente)
            $destinationOrigin =
                $this->channelData[$channelId]['vars']['EXTENSION']
                ?? $this->channelData[$channelId]['destination']
                ?? $this->channelData[$channelId]['number']
                ?? $callerId;

            // Normaliza número (apenas dígitos)
            $destinationOrigin = preg_replace('/\D+/', '', $destinationOrigin);

            // 📌 3. Cria o novo canal chamando o ramal escolhido pelo Worker
            $response = $this->http->post("http://{$this->ariHost}:8088/ari/channels", [
                'json' => [
                    'endpoint' => "PJSIP/{$ramal}",
                    'app'      => $this->stasisApp,
                    'appArgs'  => "transfer,{$channelId}",
                    'callerId' => $destinationOrigin,
                    'timeout'  => 30
                ],
                'http_errors' => false
            ]);

            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new \Exception("Falha ao criar canal (HTTP {$status})");
            }

            $new = json_decode((string)$response->getBody(), true);
            $newChannelId = $new['id'] ?? null;

            if (!$newChannelId) {
                throw new \Exception("Falha: Asterisk não retornou ID do novo canal");
            }

            echo "[TRANSFER] 📞 Novo canal criado: {$newChannelId}\n";

            // 📌 4. Registra metadata básica do novo canal
            /*$this->channelData[$newChannelId] = [
                'id' => $newChannelId,
                'peer' => $channelId,       // quem chamou
                'transfer_from' => $channelId,
                'extension' => $ramal,
                'number' => $ramal,
                'destination' => $ramal,
                'state' => 'dialing',
                'started' => time(),
                'duration' => '00:00',
                'vars' => [
                    'DEST_NUMBER' => $destinationOrigin
                ]
            ];*/

            $this->channelData[$newChannelId] = [
                'id' => $newChannelId,
                'peer' => $channelId,
                'transfer_from' => $channelId,

                // 🔑 IDENTIDADE DO RAMAL
                'type' => 'AGENT',
                'agent_id' => $ramal,

                'extension' => $ramal,
                'number' => $ramal,
                'destination' => $ramal,

                'state' => 'dialing',
                'started' => time(),
                'duration' => '00:00',

                'vars' => [
                    'DEST_NUMBER' => $destinationOrigin,
                    'AGENT_ID'    => $ramal
                ]
            ];


            // 📌 5. Atualiza peer no canal original
            $this->channelData[$channelId]['peer'] = $newChannelId;

            // 📌 6. Copia metadados (tenant, owner, etc)
            if (!empty($this->channelData[$channelId]['vars'])) {
                $this->channelData[$newChannelId]['vars'] = array_merge(
                    $this->channelData[$channelId]['vars'],
                    ['DEST_NUMBER' => $destinationOrigin]
                );
            }

            //$this->playMoh($newChannelId);

            $this->updateRedis();

            echo "[TRANSFER] ✅ Transferência iniciada. Aguardando ANSWER para criar bridge.\n";

            // ⚠️ Daqui para frente o fluxo depende:
            // → onChannelStateChange
            // → onStasisStart do novo canal
            // → onStasisEnd
            // → onBridge events

            // O transferToRamal não gerencia mais fila, agentes, fallback, etc.
            // Essas decisões pertencem ao Worker.

        } catch (\Throwable $e) {

            // Aqui NÃO devemos tentar próxima fila/agente (agora é o Worker)
            echo "[TRANSFER] ❌ Erro ao transferir para {$ramal}: " . $e->getMessage() . "\n";

            // Apenas toca áudio de erro
            $this->playMainAudio($channelId, "erro_transferencia");
        }
    }



    /**
     * Encerra uma chamada de forma segura, atualizando Redis e CDR.
     */
    private function ChannelFinishDestroyed(string $channelId, string $motivo = 'Encerrada', bool $forcarTarifa = false): void
    {
        if (empty($this->channelData[$channelId])) return;

        $this->channelData[$channelId]['status'] = "🔚 {$motivo}";
        $this->channelData[$channelId]['state'] = 'down';
        $this->channelData[$channelId]['ended'] = time();
        $this->channelData[$channelId]['cause_txt'] = $motivo;
        $this->channelData[$channelId]['cause'] = 487; // SIP 487 Request Terminated

        $this->updateRedis();

        try {
            $this->http->delete("http://{$this->ariHost}:8088/ari/playbacks", ['http_errors' => false]);
            usleep(100000);
            $this->http->delete("http://{$this->ariHost}:8088/ari/channels/{$channelId}", ['http_errors' => false]);
            error_log("[HANGUP] Canal {$channelId} encerrado ({$motivo})");

            // 🔹 Garante CDR e tarifação
            if ($forcarTarifa) {
                $this->calculateTariff($channelId);
                $this->channelData[$channelId]['tariff_done'] = true;
                error_log("[CDR] 💾 CDR gravado após encerramento via DTMF canal={$channelId}");
            }

        } catch (\Throwable $e) {
            error_log("[HANGUP] ⚠️ Erro ao encerrar canal {$channelId}: " . $e->getMessage());
        }

        $this->redis->setex("cdr-saved:{$channelId}", 60, 1);
        unset($this->channelData[$channelId]);
        $this->updateRedis();
    }

    private function onPlaybackFinished(array $event)
    {
        $channelId = str_replace('channel:', '', $event['playback']['target_uri'] ?? '');
        unset($this->channelData[$channelId]['current_playback']);
        unset($this->channelData[$channelId]['handled_dtmf']);
        echo "[🎵] Playback finalizado no canal {$channelId}, reset DTMF liberado\n";
    }

    /*private function onPlaybackFinished(array $event)
    {
        $channelId = str_replace(
            'channel:',
            '',
            $event['playback']['target_uri'] ?? ''
        );

        if (!$channelId || empty($this->channelData[$channelId])) {
            return;
        }

        echo "[🎵] Playback finalizado no canal {$channelId}\n";
        echo "[VOICE_ONLY]  {$this->channelData[$channelId]['voice_only']}\n";

        // -------------------------------------------------
        // 🔹 Limpeza padrão (mantém comportamento atual)
        // -------------------------------------------------
        unset($this->channelData[$channelId]['current_playback']);
        unset($this->channelData[$channelId]['handled_dtmf']);

        // -------------------------------------------------
        // 🔹 AÇÃO: VOICE_ONLY
        // -------------------------------------------------
        if (!empty($this->channelData[$channelId]['voice_only'])) {

            echo "[VOICE_ONLY] 🎧 Áudio finalizado → encerrando canal {$channelId}\n";

            // evita dupla execução
            unset($this->channelData[$channelId]['voice_only']);

            $this->ChannelFinishDestroyed(
                $channelId,
                'Encerrada após áudio (voice_only)',
                true
            );

            return;
        }

        // -------------------------------------------------
        // 🔹 Fluxo normal continua (DTMF, próximo áudio, etc)
        // -------------------------------------------------
    }*/



    private function handleDialEvent(array $event): void
    {
        $dialStatus = strtoupper(trim($event['dialstatus'] ?? ''));
        $dialString = $event['dialstring'] ?? '';

        $originId = $event['channel']['id'] ?? null;   // canal origem (ramal ou local/sub-rotina)
        $peerId   = $event['peer']['id'] ?? null;      // canal tronco (PJSIP/mxx...)

        // PATCH — salva nome do canal origem e do peer
        if ($originId) {
            $this->channelData[$originId]['name'] = $event['channel']['name'] ?? ($this->channelData[$originId]['name'] ?? null);
        }
        if ($peerId) {
            $this->channelData[$peerId]['name'] = $event['peer']['name'] ?? ($this->channelData[$peerId]['name'] ?? null);
        }


        // 🔥 SE O DIAL CRIOU UM NOVO CANAL (TRONCO)
        // COPIA VARS DO ORIGEM → TRONCO
        if ($originId && $peerId && $originId !== $peerId) {

            if (isset($this->channelData[$originId]['vars'])) {

                if (!isset($this->channelData[$peerId])) {
                    $this->channelData[$peerId] = [
                        'id' => $peerId,
                        'vars' => []
                    ];
                }

                if (!isset($this->channelData[$peerId]['vars'])) {
                    $this->channelData[$peerId]['vars'] = [];
                }

                foreach ($this->channelData[$originId]['vars'] as $k => $v) {
                    $this->channelData[$peerId]['vars'][$k] = $v;
                }

                error_log("[VARS] 🔁 Copiados VARSET do canal origem {$originId} → tronco {$peerId}");
            }
        }

        // ###############################################
        // ### A PARTIR DAQUI É O SEU CÓDIGO ORIGINAL  ###
        // ###############################################

        $peerNum = $event['peer']['caller']['number']
            ?? $event['peer']['caller']['extension']
            ?? null;

        $chanId = $event['channel']['id'] ?? $peerId;
        if (!$chanId) {
            echo "[Dial] Ignorado: sem ID de canal.\n";
            return;
        }

        // Criar canal se não existir
        if (!isset($this->channelData[$chanId])) {
            $this->channelData[$chanId] = [
                'id' => $chanId,
                'number' => $peerNum ?? 'Desconhecido',
                'status' => '📞 Ligando...',
                'state' => 'dialing',
                'started' => time(),
                'duration' => '00:00',
            ];
        } else {
            if (empty($this->channelData[$chanId]['number']) && $peerNum) {
                $this->channelData[$chanId]['number'] = $peerNum;
            }
            if (empty($this->channelData[$chanId]['started'])) {
                $this->channelData[$chanId]['started'] = time();
            }
        }

        $this->channelData[$chanId]['last_dial_event'] = $event;

        if ($dialString) {
            $dest = explode('@', $dialString)[0];
            $this->channelData[$chanId]['destination'] = $dest;
        }

        switch ($dialStatus) {
            case '':
                $this->channelData[$chanId]['status'] = '🔄 Conectando...';
                $this->channelData[$chanId]['state'] = 'dialing';
                break;

            case 'PROGRESS':
                $this->channelData[$chanId]['status'] = '📡 Em progresso...';
                $this->channelData[$chanId]['state'] = 'progress';
                $this->channelData[$chanId]['progress_time'] = time();
                break;

            case 'ANSWER':
                $this->channelData[$chanId]['status'] = '✅ Atendida';
                $this->channelData[$chanId]['state'] = 'up';
                $this->channelData[$chanId]['answered'] = time();
                break;

            case 'BUSY':
                $this->channelData[$chanId]['status'] = '⛔ Ocupada';
                $this->channelData[$chanId]['state'] = 'busy';
                $this->recordImmediateFailCdr($chanId, 'BUSY', 'Chamada ocupada');
                break;

            case 'CONGESTION':
                $this->channelData[$chanId]['status'] = '⚠️ Congestionamento';
                $this->channelData[$chanId]['state'] = 'congestion';
                $this->recordImmediateFailCdr($chanId, 'CONGESTION', 'Falha de rede / congestionamento');
                break;

            case 'NOANSWER':
                $this->channelData[$chanId]['status'] = '❌ Não Atendida';
                $this->channelData[$chanId]['state'] = 'noanswer';
                $this->recordImmediateFailCdr($chanId, 'NOANSWER');
                break;

            case 'CANCEL':
                $this->channelData[$chanId]['status'] = '🚫 Cancelada';
                $this->channelData[$chanId]['state'] = 'canceled';
                $this->recordImmediateFailCdr($chanId, 'CANCEL');
                break;

            default:
                $this->channelData[$chanId]['status'] = '📞 Chamando...';
                $this->channelData[$chanId]['state'] = 'dialing';
                break;
        }

        if ($dialStatus !== '') {
            $this->channelData[$chanId]['dialstatus'] = $dialStatus;
        }

        // Se o Destroyed veio antes → agora é hora de gravar o CDR pendente
        if (!empty($this->channelData[$chanId]['wait_dialstatus'])) {
            unset($this->channelData[$chanId]['wait_dialstatus']);

            error_log("[CDR] ▶ Recebido dialstatus FINAL após Destroyed — consolidando CDR (canal={$chanId})");

            // Agora sim chama o CDR de falha
            if (!empty($this->channelData[$chanId]['pending_fail_cdr'])) {
                $p = $this->channelData[$chanId]['pending_fail_cdr'];
                $this->recordImmediateFailCdr($chanId, $p['status'], $p['msg']);
            } else {
                // fallback: CDR direto
                $this->recordImmediateFailCdr($chanId, $dialStatus);
            }
        }


        try {
            $this->updateRedis();
        } catch (\Throwable $e) {
            error_log("[Redis] ⚠️ Falha ao atualizar Redis no Dial: " . $e->getMessage());
        }

        echo "[Dial] Atualizado: canal={$chanId}, status='{$dialStatus}', destino=" .
            ($this->channelData[$chanId]['destination'] ?? '—') . "\n";
    }



    private function recordImmediateFailCdr(string $chanId, string $status, string $msg = 'Falhada'): void
    {
        try {
            $chan = $this->channelData[$chanId] ?? [];
            $vars = $chan['vars'] ?? [];

            $failKey = "cdr-falha-pendente:{$chanId}";
            $dupKey  = "cdr-saved:{$chanId}";

            // já salvo? não duplica
            if ($this->redis->exists($dupKey)) {
                error_log("[CDR] ⏭️ FAIL-CDR já salvo anteriormente ({$chanId})");
                return;
            }

            // ==============================
            // PREPARA O EVENTO DE FALHA PENDENTE
            // ==============================

            $payload = [
                'dialstatus'  => strtoupper($status),
                'msg'         => $msg,

                // dados importantes que podem existir no momento da falha
                'started'     => $chan['started'] ?? time(),
                'ended'       => time(),
                'number'      => $chan['number'] ?? ($vars['CALLERID(num)'] ?? null),
                'destination' => $chan['destination'] ?? ($chan['dialstring'] ?? null),

                // TYPE pode vir depois — mas se já existir, salvamos
                'variable_type' => $vars['VARIABLE_TYPE'] ?? $vars['TYPE'] ?? null,

                // custos dinâmicos podem vir depois — mas pegue se já tiver
                'call_minute_cost' => $vars['CALL_MINUTE_COST'] ?? null,
                'sms_cost'         => $vars['SMS_COST'] ?? null,
                'torpedo_cost'     => $vars['TORPEDO_COST'] ?? null,

                // OWNER/TENANT podem chegar depois no VARSET
                'owner_id'  => $vars['OWNER_ID'] ?? null,
                'tenant_id' => $vars['TENANT_ID'] ?? null,

                // para referência
                'application' => 'app-asterisk',
            ];

            // salva evento pendente no Redis
            $this->redis->setex($failKey, 180, json_encode($payload, JSON_UNESCAPED_UNICODE));

            // salva também em memória
            $this->channelData[$chanId]['pending_fail_cdr'] = $payload;

            // logs amigáveis
            if (empty($vars['OWNER_ID']) || empty($vars['TENANT_ID'])) {
                error_log("[CDR] ⏳ FAIL-CDR aguardando VARSET canal={$chanId}");
            } else {
                error_log("[CDR] 🟡 FAIL-CDR criou evento pendente (VARS presentes), aguardando Destroyed");
            }

        } catch (\Throwable $e) {
            error_log("[CDR] ⚠️ Erro ao registrar FAIL-CDR: ".$e->getMessage());
        }
    }


    /**
     * Tenta salvar um CDR de falha (NOANSWER/FAILED/BUSY) que está pendente no Redis/memória.
     * Retorna true se conseguiu salvar, false se ainda está aguardando OWNER/TENANT
     * ou se não há falha pendente.
     */
    private function trySavePendingFailCdr(string $channelId): bool
    {
        $call = $this->channelData[$channelId] ?? null;
        if (!$call) {
            error_log("[CDR] ⚠ trySavePendingFailCdr sem channelData para {$channelId}");
            return false;
        }

        $failKey  = "cdr-falha-pendente:{$channelId}";
        $failJson = $this->redis->get($failKey);

        // pode estar em memória (pending_fail_cdr) ou só no Redis
        $failEvent = $call['pending_fail_cdr'] ?? null;
        if (!$failEvent && $failJson) {
            $failEvent = json_decode($failJson, true) ?: null;
        }
        if (!$failEvent) {
            // não há falha pendente pra esse canal
            return false;
        }

        // normaliza o status (tanto faz 'status' ou 'dialstatus')
        $dialStatus = $failEvent['dialstatus'] ?? ($failEvent['status'] ?? 'FAILED');

        // se ainda for apenas PROGRESS, não salva — só mantém pendente
        if ($dialStatus === 'PROGRESS') {
            error_log("[CDR] ⏳ FAIL-CDR ainda em PROGRESS canal={$channelId}");
            $payloadToKeep = $failJson ?: json_encode($failEvent, JSON_UNESCAPED_UNICODE);
            $this->redis->setex($failKey, 180, $payloadToKeep);
            return false;
        }

        $vars = $call['vars'] ?? [];

        // OWNER/TENANT podem vir do próprio canal ou do pendingVars
        $ownerId  = $vars['OWNER_ID']  ?? ($this->pendingVars[$channelId]['OWNER_ID']  ?? null);
        $tenantId = $vars['TENANT_ID'] ?? ($this->pendingVars[$channelId]['TENANT_ID'] ?? null);

        if (empty($ownerId) || empty($tenantId)) {
            // ainda não dá pra salvar, só renova o TTL do pendente
            error_log("[CDR] ⏳ FAIL-CDR aguardando VARSET OWNER_ID/TENANT_ID canal={$channelId}");
            $payloadToKeep = $failJson ?: json_encode($failEvent, JSON_UNESCAPED_UNICODE);
            $this->redis->setex($failKey, 180, $payloadToKeep);
            // mantém channelData vivo pra quando o VARSET chegar
            return false;
        }

        // Já temos OWNER/TENANT → agora monta o CDR de falha
        $type = strtolower(
            $failEvent['variable_type']
            ?? ($vars['VARIABLE_TYPE'] ?? ($vars['TYPE'] ?? 'normal'))
        );

        $minuteCost  = (float)($failEvent['call_minute_cost'] ?? ($vars['CALL_MINUTE_COST'] ?? 0));
        $smsCost     = (float)($failEvent['sms_cost']         ?? ($vars['SMS_COST']         ?? 0));
        $torpedoCost = (float)($failEvent['torpedo_cost']     ?? ($vars['TORPEDO_COST']     ?? 0));

        $number = $call['number']
            ?? ($call['extension'] ?? ($vars['CALLERID(num)'] ?? 'Desconhecido'));

        $destination = $call['destination'] ?? ($call['dialstring'] ?? '—');

        $causeCode = $call['cause']     ?? null;
        $causeText = $call['cause_txt'] ?? null;

        $started = $call['started'] ?? time();
        $ended   = $call['ended']   ?? time();

        $cdr = [
            'channel_id'       => $channelId,
            'owner_id'         => $ownerId,
            'tenant_id'        => $tenantId,
            'number'           => $number,
            'destination'      => $destination,
            'type'             => $type,
            'state'            => 'failed',
            'dialstatus'       => $dialStatus,
            'cause'            => $causeCode,
            'cause_txt'        => $causeText,
            'duration'         => 0,
            'value'            => '0.0000',
            'call_minute_cost' => $minuteCost,
            'sms_cost'         => $smsCost,
            'torpedo_cost'     => $torpedoCost,
            'started'          => $started,
            'ended'            => $ended,
            'timestamp'        => date('Y-m-d H:i:s'),
            'application'      => $failEvent['application'] ?? 'app-asterisk',
        ];

        $dupKey = "cdr-saved:{$channelId}";
        if ($this->redis->exists($dupKey)) {
            error_log("[CDR] ⏭️ FAIL-CDR já salvo previamente canal={$channelId}");
            return true;
        }

        $this->redis->rpush('asterisk:tarifacoes', json_encode($cdr, JSON_UNESCAPED_UNICODE));
        $this->redis->setex($dupKey, 300, 1);
        $this->redis->del($failKey);
        unset($this->channelData[$channelId]['pending_fail_cdr']);

        error_log("[CDR] ❗ FAIL-CDR salvo com OWNER/TENANT canal={$channelId}");

        return true;
    }




    private function calculateTariff(string $channelId): void
    {
        $call = $this->channelData[$channelId] ?? null;
        if (!$call) {
            return;
        }

        // ===================================================
        // 🚫 Evita duplicação
        // ===================================================
        if (!empty($call['tariff_done'])) {
            error_log("[CDR] ⏭ Já tarifado (memória) canal={$channelId}");
            return;
        }

        $dupKey = "tarifacao:ja_registrada:{$channelId}";
        if ($this->redis->exists($dupKey)) {
            error_log("[CDR] ⏭ Já tarifado (Redis) canal={$channelId}");
            return;
        }

        if (empty($call['ended'])) {
            error_log("[CDR] ❌ Ignorado — chamada não finalizada canal={$channelId}");
            return;
        }

        // ===================================================
        // 🔸 Extrai variáveis
        // ===================================================
        $vars = $call['vars'] ?? [];

        $type = strtolower(
            $vars['VARIABLE_TYPE']
            ?? $vars['TYPE']
            ?? $call['type']
            ?? 'normal'
        );

        $ownerId  = $vars['OWNER_ID']  ?? $call['owner_id']  ?? null;
        $tenantId = $vars['TENANT_ID'] ?? $call['tenant_id'] ?? null;
        $role     = $vars['ROLE']      ?? $call['role']      ?? null;

        // ⚠️ Somente AGORA podemos validar OWNER/TENANT
        if (empty($ownerId) || empty($tenantId)) {
            error_log("[CDR] ⏳ Aguardando OWNER_ID/TENANT_ID antes de tarifar canal={$channelId}");
            $this->channelData[$channelId]['wait_vars_for_tariff'] = true;
            return;
        }

        // custos dinâmicos
        $minuteCost  = isset($vars['CALL_MINUTE_COST']) ? (float)$vars['CALL_MINUTE_COST'] : 0.0;
        $smsCost     = isset($vars['SMS_COST'])         ? (float)$vars['SMS_COST']         : 0.0;
        $torpedoCost = isset($vars['TORPEDO_COST'])     ? (float)$vars['TORPEDO_COST']     : 0.0;

        /*$number = $call['number']
            ?? $call['extension']
            ?? ($vars['CALLERID(num)'] ?? null);

        $destination = $call['destination'] ?? ($call['dialstring'] ?? null);*/

        $number = $call['number']
            ?? $vars['CALLERID_NUM']
            ?? $vars['CALLERID(num)']
            ?? null;

        $destination = $call['destination']
            ?? ($vars['EXTENSION'] ?? null)  // ou $vars['DESTINATION'] se usar assim
            ?? ($call['dialstring'] ?? null);



        $state = $call['state'] ?? 'unknown';

        // ===================================================
        // 🔹 CAUSA FINAL
        // ===================================================
        $cause = $call['cause'] ?? null;
        $causeText = $call['cause_txt'] ?? null;

        $redisCause = $this->redis->hget("asterisk:causes", $channelId);
        if ($redisCause) {
            $parsed = json_decode($redisCause, true);
            if (!empty($parsed)) {
                $cause     = $parsed['cause']     ?? $cause;
                $causeText = $parsed['cause_txt'] ?? $causeText;
            }
        }

        // ===================================================
        // 🔹 Map de causas → dialstatus
        // ===================================================
        $causeMap = [
            16 => ['NORMAL_CLEARING', 'ANSWER', 'Normal Clearing'],
            17 => ['USER_BUSY', 'BUSY', 'Ocupado'],
            18 => ['NO_USER_RESPONDING', 'NOANSWER', 'Sem resposta'],
            19 => ['NO_ANSWER', 'NOANSWER', 'Não Atendida'],
            21 => ['CALL_REJECTED', 'CANCEL', 'Rejeitada'],
            28 => ['INVALID_NUMBER_FORMAT', 'FAILED', 'Número inválido'],
            34 => ['CONGESTION', 'CONGESTION', 'Congestionamento'],
            41 => ['TEMPORARY_FAILURE', 'FAILED', 'Falha temporária'],
            47 => ['RESOURCE_UNAVAILABLE', 'FAILED', 'Recurso indisponível'],
            486 => ['BUSY_HERE', 'BUSY', 'Ocupado'],
            487 => ['REQUEST_TERMINATED', 'CANCEL', 'Cancelada'],
            603 => ['DECLINE', 'CANCEL', 'Recusada'],
            0  => ['UNKNOWN', 'FAILED', 'Desconhecido'],
        ];

        if (!empty($call['answered'])) {
            $dialStatus = 'ANSWER';
            $causeName = 'NORMAL_CLEARING';
            $causeText = 'Normal Clearing';
        } elseif (!empty($causeMap[(int)$cause])) {
            [$causeName, $dialStatus, $causeText] = $causeMap[(int)$cause];
        } else {
            $dialStatus = strtoupper($call['dialstatus'] ?? 'FAILED');
            $causeName = 'UNKNOWN';
            $causeText = $causeText ?? 'Desconhecido';
        }

        // ===================================================
        // 🔸 Tempos
        // ===================================================
        $started  = $call['started']  ?? time();
        $answered = $call['answered'] ?? null;
        $ended    = $call['ended']    ?? time();

        // 👇 Adicione ISSO:
        if (empty($answered)) {
            // não foi atendida → quem grava é o fluxo de falha (cdr-falha-pendente)
            error_log("[CDR] ⏭ calculateTariff ignorado para canal sem ANSWER (canal={$channelId})");
            return;
        }

        // ===================================================
        // 🔥 DETECÇÃO CORRETA DE RAMAL (6–8 dígitos)
        // ===================================================
        $chName = strtolower($call['name'] ?? '');
        $isRamal = preg_match('/^pjsip\/([0-9]{6,8})-/i', $chName, $match);
        $channelNumber = $match[1] ?? null;

        // ===================================================
        // 🔸 Cálculo de custo
        // ===================================================
        $durationSec = 0;
        $cost = 0;

        if ($answered) {
            $durationSec = max(0, $ended - $answered);

            if ($durationSec > 0) {

                if ($type === 'torpedo') {

                    // Torpedo só tarifa se NÃO for ramal
                    if (!$isRamal) {
                        $cost = $this->calculateTorpedoTariff($durationSec, $torpedoCost, $minuteCost);
                    }

                } elseif ($type === 'outbound') {

                    // Outbound sempre tarifa
                    $cost = $this->calculateNormalTariff($durationSec, $minuteCost);

                } elseif (!$isRamal) {

                    // Normal só tarifa se não for ramal
                    $cost = $this->calculateNormalTariff($durationSec, $minuteCost);
                }
            }
        }

        // ===================================================
        // 🔹 CDR FINAL
        // ===================================================
        $cdr = [
            'channel_id'       => $channelId,
            'owner_id'         => $ownerId,
            'tenant_id'        => $tenantId,
            'role'             => $role,
            'number'           => $number,
            'destination'      => $destination,
            'channelNumber'    => $channelNumber,
            'type'             => $type,
            'state'            => $state,
            'dialstatus'       => $dialStatus,
            'cause'            => $cause,
            'cause_txt'        => $causeText,
            'duration'         => $durationSec,
            'value'            => number_format($cost, 4, '.', ''),
            'call_minute_cost' => $minuteCost,
            'sms_cost'         => $smsCost,
            'started'          => $started,
            'answered'         => $answered,
            'ended'            => $ended,
            'timestamp'        => date('Y-m-d H:i:s'),
        ];

        // ===================================================
        // 🔥 Salva no Redis
        // ===================================================
        $this->redis->rpush('asterisk:tarifacoes', json_encode($cdr, JSON_UNESCAPED_UNICODE));
        $this->redis->setex($dupKey, 180, 1);

        $this->channelData[$channelId]['tariff_done'] = true;

        error_log("[CDR] 💰 Tarifação OK canal={$channelId} tipo={$type} dur={$durationSec}s = R$ {$cdr['value']}");
    }




    /**
     * @param float $durationSec Duração da chamada em segundos
     * @param float $torpedoCost
     * @param float $minuteCost Custo adicional por minuto (>60s, default 0.10)
     * Torpedo:
     * - <= 60s: cobra apenas TORPEDO_COST (valor fixo)
     * -  > 60s: acrescenta custo incremental por 6s com base no CALL_MINUTE_COST
     *
     * Ex.: minuteCost = 0.30 → cada passo de 6s custa 0.03
     *     61–66s → +0.03; 67–72s → +0.06; ...; 115–120s → +0.30, e assim por diante.
     * @return float
     */
    private function calculateTorpedoTariff(float $durationSec, float $torpedoCost, float $minuteCost): float
    {
        if ($durationSec <= 60) {
            return round($torpedoCost, 4);
        }

        $incrementSec = 6;
        $extraSec = max(0, $durationSec - 60);

        // Número de passos de 6s (arredonda para cima)
        $steps = (int)ceil($extraSec / $incrementSec);

        // Valor por passo de 6s: 1/10 do minuto
        $perStep = $minuteCost / 10.0;

        $total = $torpedoCost + ($steps * $perStep);
        return round($total, 4);
    }


    /**
     * Normal (dinâmico, híbrido 30/6):
     * - Até 30s: meia tarifa (CALL_MINUTE_COST / 2)
     * - 31–60s: cresce em blocos de 6s até valor cheio do minuto
     * - >60s: tarifação por minuto com cadência de 6s
     *
     * @param float $durationSec Duração da chamada em segundos
     * @param float $minuteCost Custo por minuto (default 0.10)
     */
    private function calculateNormalTariff(float $durationSec, float $minuteCost): float
    {
        $minuteRate = $minuteCost;
        $halfRate = $minuteRate / 2;
        $minSeconds = 30;
        $incrementSec = 6;

        if ($durationSec <= $minSeconds) {
            // até 30s = meia tarifa
            return round($halfRate, 4);
        }

        if ($durationSec <= 60) {
            // 31–60s → cresce linearmente até o valor cheio
            $extra = ceil(($durationSec - $minSeconds) / $incrementSec) * $incrementSec;
            $billedDuration = $minSeconds + $extra;
            $progress = ($billedDuration - $minSeconds) / 30; // 0..1
            return round($halfRate + ($progress * $halfRate), 4);
        }

        // >60s → tarifação contínua por minuto (cadência de 6s)
        $extra = ceil(($durationSec - 60) / $incrementSec) * $incrementSec;
        $billedDuration = 60 + $extra;
        return round(($billedDuration / 60) * $minuteRate, 4);
    }


    private function updateRedis()
    {
        try {
            $now = time();
            $payload = [
                'statusGeral' => 'OK',
                'totalChamadas' => count($this->channelData),
                'chamadas' => [],
                'timestamp' => date('Y-m-d H:i:s'),
                'server_now' => $now
            ];

            foreach ($this->channelData as $id => $c) {

                $started  = $c['started']  ?? $now;
                $answered = $c['answered'] ?? null;
                $ended    = $c['ended']    ?? null;
                $dur = 0;

                // cálculo de duração
                if ($answered && empty($ended)) {
                    $dur = $now - $answered;
                } elseif ($answered && $ended) {
                    $dur = $ended - $answered;
                }

                // nome do canal
                $name = $c['name'] ?? null;

                // fallback via ARI
                if (!$name && $id) {
                    try {
                        $res = $this->http->get("http://{$this->ariHost}:8088/ari/channels/{$id}", [
                            'auth' => [$this->ariUser, $this->ariPass],
                            'headers' => ['Accept' => 'application/json']
                        ]);

                        $chanData = json_decode($res->getBody(), true);
                        $name = $chanData['name'] ?? null;
                        $this->channelData[$id]['name'] = $name;

                    } catch (\Throwable $e) {
                        $name = null;
                    }
                }

                // variáveis do canal
                $vars = $c['vars'] ?? [];


                // --- Suporte às variáveis vindas do dialplan (somente adiciona, não substitui nada) ---
                $callerIdNum  = $vars['CALLERID_NUM']
                    ?? $vars['CALLERID(num)']
                    ?? ($c['callerid_num'] ?? null);

                $callerIdName = $vars['CALLERID_NAME']
                    ?? $vars['CALLERID(name)']
                    ?? ($c['callerid_name'] ?? null);

                $extension    = $vars['EXTENSION']
                    ?? ($c['extension'] ?? null);

                $dest         = $vars['DESTINATION']
                    ?? ($c['destination'] ?? null);



                $context  = strtolower($vars['CONTEXT'] ?? ($c['context'] ?? ''));
                $ownerId  = $vars['OWNER_ID'] ?? null;
                $tenantId = $vars['TENANT_ID'] ?? null;
                $role     = $vars['ROLE'] ?? null;

                $minuteCost  = (float)($vars['CALL_MINUTE_COST'] ?? 0);
                $torpedoCost = (float)($vars['TORPEDO_COST'] ?? 0);
                $type = strtoupper($vars['VARIABLE_TYPE'] ?? ($vars['TYPE'] ?? ''));

                // detectar canal de spy
                $isSpy = ($context === 'spy-control' || isset($vars['SPY_CHANNEL']));

                // =======================================================
                // 🔥 DETECÇÃO CORRETA DE TIPOS DE CANAIS
                // =======================================================
                $chName = strtolower($name ?? '');

                // tronco real (ex: PJSIP/mxx001-...)
                $isTrunk = preg_match('/^pjsip\/mxx/i', $chName);

                // ramal real (ex: PJSIP/123456-0000abc) → exatamente 6 dígitos!
                $isRamal = preg_match('/^pjsip\/([0-9]{8})-/i', $chName);

                // canal local (com segurança)
                $isLocal = (!$isTrunk && !$isRamal && preg_match('/^local\//i', $chName));

                if ($isLocal) {

                    $trunk = 'LOCAL';
                    $displayCost = 0.0;

                } elseif ($isTrunk) {

                    $trunk = $vars['TRUNK'] ?? 'TRONCO';
                    $displayCost = ($type === 'TORPEDO') ? $torpedoCost : $minuteCost;

                } elseif ($isRamal) {

                    // 👉 Se for OUTBOUND, mesmo sendo ramal, exibe TRONCO
                    if ($type === 'OUTBOUND') {
                        $trunk = $vars['TRUNK'] ?? 'TRONCO';
                        $displayCost = $minuteCost;
                    } else {
                        $trunk = 'RAMAL';
                        $displayCost = 0.0;
                    }

                } else {

                    $trunk = $vars['TRUNK'] ?? '—';
                    $displayCost = ($type === 'TORPEDO') ? $torpedoCost : $minuteCost;
                }



                // dados padrão
                $caller = $c['caller_name'] ?? ($c['extension'] ?? '—');
                $destination = $c['destination'] ?? null;

                // ajustes se for spy
                if ($isSpy) {
                    $type = 'Escuta';
                    $trunk = $vars['SPY_TRUNK'] ?? '—';
                    $displayCost = 0.0;
                    $caller = 'Monitoramento';

                    $origem = $vars['SPY_NUMBER'] ?? '—';
                    $destino = $vars['SPY_DESTINATION'] ?? '—';
                    $destination = "Escutando {$destino}";
                }

                // montar payload
                $payload['chamadas'][] = [
                    'id'          => $id,
                    'name'        => $name,
                    'number'      => $c['extension'] ?? ($c['number'] ?? $callerIdNum ?? 'Desconhecido'),
                    'caller'      => $caller,
                    'destination' => $destination ?? $extension,
                    'status'      => $c['status'] ?? ($c['state'] ?? '—'),
                    'state'       => $c['state'] ?? '',
                    'duration'    => gmdate("i:s", max(0, $dur)),
                    'started'     => $started,
                    'answered'    => $answered,
                    'ended'       => $ended,
                    'dtmf'        => $c['last_dtmf'] ?? null,
                    'in_bridge'   => isset($c['bridge']),
                    'peer'        => $c['peer'] ?? null,
                    'owner_id'    => $ownerId,
                    'tenant_id'   => $tenantId,
                    'role'        => $role,
                    'trunk'       => $trunk,
                    'extension'   => $vars['EXTENSION'] ?? null,
                    'sms_cost'    => $vars['SMS_COST'] ?? null,
                    'call_minute_cost' => number_format($displayCost, 2, '.', ''),
                    'variable_type'    => $type,
                    'vars'             => $vars
                ];
            }

            // salvar no Redis
            $this->redis->set('asterisk:active_calls', json_encode($payload, JSON_UNESCAPED_UNICODE));
            echo "[Redis] Atualizado com {$payload['totalChamadas']} chamadas.\n";

        } catch (\Exception $e) {
            echo "[Redis ❌] Erro ao atualizar: {$e->getMessage()}\n";
        }
    }
}
// ================= Configuração =================
$listener = new StasisListenerAsterisk('maxx', 'mxx123', '127.0.0.1', 'app-asterisk');
$listener->run();


namespace App\Service;

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
        $this->redis = RedisConn::get();
        $this->ariHost = $opts['ari_host'] ?? '192.168.1.5';
        $this->ariAuth = $opts['ari_auth'] ?? ['maxx', 'mxx123'];
        $this->stasisApp = $opts['stasis_app'] ?? 'app-asterisk';

        $this->http = new Client([
            'base_uri' => "http://{$this->ariHost}:8088/",
            'timeout' => 5.0,
            'http_errors' => false,
            'auth' => $this->ariAuth,
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

        echo "🚀 Worker Voice iniciado (PID " . getmypid() . ")\n";

        while (true) {

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

            $strategy = strtolower(trim((string)($data['strategy'] ?? 'rrmemory')));

            if (!in_array($strategy, ['rrmemory', 'leastrecent', 'linear', 'ringall'], true)) {
                $strategy = 'rrmemory';
            }


            $jobId = $data['job_id'] ?? null;

            // =====================================================
            // 🧠 Detecta se EXISTE ação de TRANSFER
            // =====================================================
            $needsAgent = false;

            $finalAction = strtolower(trim((string)($data['action'] ?? '')));

            $dtmf = $data['audio']['dtmf'] ?? [];

            // 🔹 transferência direta (NORMAL sem dtmf/áudio)
            if ($finalAction === 'transfer_only') {
                $needsAgent = true;
            } // 🔹 DTMF com ação de transferência
            elseif ($finalAction === 'dtmf' && is_array($dtmf)) {
                foreach ($dtmf as $itemDtmf) {
                    if (($itemDtmf['action'] ?? null) === 'transfer') {
                        $needsAgent = true;
                        break;
                    }
                }
            }

            if (!empty($jobId)) {

                $lockKey = "campaign:{$jobId}:started";

                if ($this->redis->setnx($lockKey, time())) {

                    // lock expira por segurança
                    $this->redis->expire($lockKey, 3600);

                    // 🔥 muda status no banco
                    CampaignVoice::updateStatusByJob($jobId, 'p');

                    echo "📊 Campanha {$jobId} → PROCESSANDO\n";
                }
            }


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

            if (
                !$bucket->waitAndConsume(
                    $bucketKey,
                    $rate,
                    $rate,
                    2.0,
                    1
                )
            ) {
                echo "⏱ CPS bloqueou → reenfileirando\n";
                $this->redis->rpush('voice:queue', $raw);
                usleep(200_000);
                continue;
            }

            // =====================================================
            // 🧠 ENDPOINTS (RAMPAIS)
            // =====================================================
            $endpoints = array_values(
                array_unique(
                    array_filter((array)($data['endpoints'] ?? []))
                )
            );

            // =====================================================
            // 🧠 AGENT MANAGER (SÓ SE PRECISAR)
            // =====================================================
            $ramal = null;

            if ($needsAgent && $endpoints) {

                $agentManager = new AgentManager([
                    'ari_host' => $this->ariHost,
                    'ari_auth' => $this->ariAuth,
                ]);

                //$agentManager->addFromPayload($endpoints, true);

                $agentsStatus = $agentManager->addFromPayload($endpoints, false);

                print_r($agentsStatus);

                // =====================================================
                // 🧠 RESERVA ATÔMICA
                // =====================================================
                $workerId = getmypid() . '-' . bin2hex(random_bytes(3));

                $criteria = [
                    'preferred' => $endpoints,
                    'strict_preferred' => 0,
                    'reserve_ttl' => 30,
                    'skills' => (array)($data['skills'] ?? []),
                    'vip' => (bool)($data['vip'] ?? false),
                    'strategy' => $strategy, // ✅ NOVO
                ];


                $ramal = $agentManager->reserveAgentAdvanced(
                    $criteria,
                    $workerId
                );

                var_dump($ramal);

                if (!$ramal) {
                    echo "⚠ Nenhum agente disponível → reenfileirando\n";
                    $this->redis->rpush('voice:queue', $raw);
                    continue;
                }

                echo "✔ Agente reservado: {$ramal}\n";

                $this->redis->setex(
                    "voice:call_context:{$jobId}",
                    300,
                    json_encode([
                        'endpoints' => $endpoints,
                        'ramal' => $ramal,
                    ])
                );

                $data['reserved_agent'] = $ramal;

            } else {
                echo "🎧 Chamada sem transferência → sem reserva de agente\n";
            }


            // =====================================================
            // 📞 ORIGINATE REAL
            // =====================================================
            try {
                //$orig->originate($data);

                //print_r($data);

                $ok = $orig->originate($data);

                if ($ok && $jobId) {

                    // incrementa processados
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

                    // ✅ FINALIZA CAMPANHA
                    if ($processed >= $total) {

                        // lock anti-duplicação
                        if ($this->redis->setnx("campaign:{$jobId}:finished", time())) {

                            $this->redis->expire("campaign:{$jobId}:finished", 3600);

                            CampaignVoice::updateStatusByJob($jobId, 'p');

                            echo "🏁 Campanha {$jobId} FINALIZADA\n";
                        }
                    }
                }


            } catch (Throwable $e) {
                echo "[ORIGINATE] ERRO: {$e->getMessage()}\n";
            }

            echo "--------------------------------------------------\n";
        }
    }


    // =========================================================
    // TRANSFER DECISION
    // =========================================================
    /*private function handleTransferRequest(array $payload): void
    {
        $channelId = $payload['channel'] ?? null;
        $requested = $payload['requested_ramal'] ?? null;

        if (!$channelId) {
            return;
        }

        // 🔒 GARANTE: 1 decisão por channel
        $decisionLock = $this->redis->set(
            "voice:transfer_decision:{$channelId}",
            getmypid(),
            'NX',
            'EX',
            10
        );

        if (!$decisionLock) {
            return;
        }

        // 🔍 VALIDA SE O CHANNEL AINDA EXISTE
        try {
            $check = $this->http->get("ari/channels/{$channelId}");
            if ($check->getStatusCode() !== 200) {
                throw new \RuntimeException('Channel not active');
            }
        } catch (\Throwable) {
            $this->respondTransfer(
                $channelId,
                false,
                null,
                'channel_not_active'
            );
            return;
        }

        // 🔑 CONTEXTO DO CANAL
        $ctxRaw = $this->redis->get("voice:channel_context:{$channelId}");
        $ctx    = $ctxRaw ? json_decode($ctxRaw, true) : [];

        //echo "<pre>";
        //print_r($ctx);
        //echo "</pre>";exit;

        $endpoints = array_map(
            fn ($r) => preg_replace('/\D/', '', $r),
            (array) ($ctx['endpoints'] ?? [])
        );

        // 🔍 BUSCA AGENTES
        $agents          = $this->redis->hgetall('discador:agentes');
        $freeAgents      = [];
        $requestedStatus = null;

        foreach ($agents as $ramal => $json) {

            if (!preg_match('/^\d{8}$/', $ramal)) {
                continue;
            }

            if ($endpoints && !in_array($ramal, $endpoints, true)) {
                continue;
            }

            $info = json_decode($json, true) ?: [];

            if (!($info['online'] ?? false)) {
                continue;
            }

            // 🎯 VALIDA RAMAL SOLICITADO (COM RESERVA!)
            if (
                $requested === $ramal &&
                ($info['status'] ?? '') === 'LIVRE' &&
                empty($info['reserved_by'])
            ) {
                $requestedStatus = 'LIVRE';
            }

            // 🟢 LISTA DE AGENTES LIVRES
            if (
                ($info['status'] ?? '') === 'LIVRE' &&
                empty($info['reserved_by'])
            ) {
                $freeAgents[] = $ramal;
            }
        }

        // 🧠 DECISÃO FINAL
        $chosen = null;
        $reason = null;

        if ($requested && $requestedStatus === 'LIVRE') {
            $chosen = $requested;
            $reason = 'requested_ramal_available';
        } elseif ($freeAgents) {
            $chosen = $freeAgents[0];
            $reason = $endpoints
                ? 'fallback_inside_endpoints'
                : 'fallback_global_free_ramal';
        }

        // ❌ NENHUM AGENTE
        if (!$chosen) {

            $strategy = $ctx['strategy_no_agent'] ?? 'wait';

            if ($strategy === 'wait') {

                $waitKey = "voice:wait_notified:{$channelId}";

                if (!$this->redis->set($waitKey, 1, 'NX', 'EX', 5)) {
                    return;
                }

                $this->respondTransfer(
                    $channelId,
                    false,
                    null,
                    'wait_no_agent'
                );
                return;
            }

            $this->respondTransfer(
                $channelId,
                false,
                null,
                'no_free_agents'
            );
            return;
        }

        // =========================
        // 🧾 REGISTRA RESERVA NO CONTEXTO DO CANAL
        // =========================
        $ctx['reserved_ramal'] = $chosen;
        $ctx['reserved_by']    = 'transfer_worker';
        $ctx['decided_at']     = time();

        $this->redis->setex(
            "voice:channel_context:{$channelId}",
            60,
            json_encode($ctx, JSON_UNESCAPED_UNICODE)
        );

        // =========================
        // 🧾 REGISTRA TRANSFERÊNCIA PENDENTE
        // =========================
        $this->redis->setex(
            "voice:pending_transfer:{$channelId}",
            30,
            json_encode([
                'channel'    => $channelId,
                'ramal'      => $chosen,
                'decided_at' => time(),
                'worker'     => getmypid(),
            ], JSON_UNESCAPED_UNICODE)
        );

        // 📤 RESPONDE AO STASIS
        $this->respondTransfer(
            $channelId,
            true,
            $chosen,
            $reason
        );
    }*/

    private function handleTransferRequest(array $payload): void
    {
        $channelId = $payload['channel'] ?? null;
        $requested = $payload['requested_ramal'] ?? null;

        if (!$channelId) {
            return;
        }

        // Normaliza requested (evita telefone gigante)
        $requested = $requested ? preg_replace('/\D/', '', (string)$requested) : null;
        if ($requested && !preg_match('/^\d{8}$/', $requested)) {
            $requested = null;
        }

        // 🔒 GARANTE: 1 decisão por channel
        $decisionLock = $this->redis->set(
            "voice:transfer_decision:{$channelId}",
            getmypid(),
            'NX',
            'EX',
            10
        );

        if (!$decisionLock) {
            return;
        }

        // 🔍 VALIDA SE O CHANNEL AINDA EXISTE
        try {
            $check = $this->http->get("ari/channels/{$channelId}");
            if ($check->getStatusCode() !== 200) {
                throw new \RuntimeException('Channel not active');
            }
        } catch (\Throwable) {
            $this->respondTransfer(
                $channelId,
                false,
                null,
                'channel_not_active'
            );
            return;
        }

        // 🔑 CONTEXTO DO CANAL
        $ctxRaw = $this->redis->get("voice:channel_context:{$channelId}");
        $ctx = $ctxRaw ? json_decode($ctxRaw, true) : [];

        $endpoints = array_map(
            fn($r) => preg_replace('/\D/', '', (string)$r),
            (array)($ctx['endpoints'] ?? [])
        );

        // TTL padrão da reserva (mesmo do Lua)
        $now = time();
        $resTtl = 20;

        $hasValidReservation = function (array $info) use ($now, $resTtl): bool {
            if (empty($info['reserved_by'])) {
                return false;
            }

            // preferir reserved_at (novo), fallback updated (legado)
            $ts = (int)($info['reserved_at'] ?? 0);
            if ($ts <= 0) {
                $ts = (int)($info['updated'] ?? 0);
            }

            return $ts > 0 && ($now - $ts) <= $resTtl;
        };

        // 🔍 BUSCA AGENTES
        $agents = $this->redis->hgetall('discador:agentes');
        $freeAgents = [];
        $reservedAgents = [];
        $requestedStatus = null;

        foreach ($agents as $ramal => $json) {

            if (!preg_match('/^\d{8}$/', (string)$ramal)) {
                continue;
            }

            if ($endpoints && !in_array($ramal, $endpoints, true)) {
                continue;
            }

            $info = json_decode((string)$json, true) ?: [];

            if (!($info['online'] ?? false)) {
                continue;
            }

            // 🟢 LIVRE "DE VERDADE" (sem reserva, sem canal)
            $isReallyFree = (
                ($info['status'] ?? '') === 'LIVRE'
                && empty($info['reserved_by'])
                && empty($info['channel'])
                && empty($info['reserved_channel'])
            );

            // 🟡 LIVRE MAS RESERVADO (reserva válida por TTL, ainda sem canal)
            $isReservedButValid = (
                ($info['status'] ?? '') === 'LIVRE'
                && empty($info['channel'])
                && empty($info['reserved_channel'])
                && $hasValidReservation($info)
            );

            // 🎯 RAMAL SOLICITADO (aceita livre OU reservado válido)
            if ($requested && $requested === $ramal && ($isReallyFree || $isReservedButValid)) {
                $requestedStatus = $isReallyFree ? 'LIVRE' : 'RESERVED_OK';
            }

            // 🟢 LISTA DE LIVRES
            if ($isReallyFree) {
                $freeAgents[] = $ramal;
            }

            // 🟡 LISTA DE RESERVADOS VÁLIDOS (fallback)
            if ($isReservedButValid) {
                $reservedAgents[] = $ramal;
            }
        }

        // 🧠 DECISÃO FINAL
        $chosen = null;
        $reason = null;

        if ($requested && ($requestedStatus === 'LIVRE' || $requestedStatus === 'RESERVED_OK')) {
            $chosen = $requested;
            $reason = ($requestedStatus === 'LIVRE')
                ? 'requested_ramal_available'
                : 'requested_ramal_reserved_ok';
        } elseif (!empty($freeAgents)) {
            $chosen = $freeAgents[0];
            $reason = $endpoints
                ? 'fallback_inside_endpoints'
                : 'fallback_global_free_ramal';
        } elseif (!empty($reservedAgents)) {
            $chosen = $reservedAgents[0];
            $reason = $endpoints
                ? 'fallback_reserved_inside_endpoints'
                : 'fallback_reserved_global';
        }

        // ❌ NENHUM AGENTE DISPONÍVEL
        if (!$chosen) {

            $strategy = $ctx['strategy_no_agent'] ?? 'wait';

            if ($strategy === 'wait') {

                $waitKey = "voice:wait_notified:{$channelId}";

                if (!$this->redis->set($waitKey, 1, 'NX', 'EX', 5)) {
                    return;
                }

                $this->respondTransfer(
                    $channelId,
                    false,
                    null,
                    'wait_no_agent'
                );
                return;
            }

            $this->respondTransfer(
                $channelId,
                false,
                null,
                'no_free_agents'
            );
            return;
        }

        // =========================================================
        // ✅ “TOMA POSSE” DA RESERVA + RENOVA TTL
        // (evita expirar no meio e vincula ao channel)
        // =========================================================
        try {
            $infoRaw = $this->redis->hget('discador:agentes', $chosen);
            $info = $infoRaw ? json_decode((string)$infoRaw, true) : [];

            $info['reserved_by'] = 'transfer_worker';
            $info['reserved_channel'] = $channelId;
            $info['reserved_at'] = time();
            $info['updated'] = time();
            $info['last_update_by'] = 'transfer_worker_decision';

            $this->redis->hset(
                'discador:agentes',
                $chosen,
                json_encode($info, JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {
            // se falhar o keepalive, segue mesmo assim
        }

        // =========================
        // 🧾 REGISTRA RESERVA NO CONTEXTO DO CANAL
        // =========================
        $ctx['reserved_ramal'] = $chosen;
        $ctx['reserved_by'] = 'transfer_worker';
        $ctx['decided_at'] = time();

        $this->redis->setex(
            "voice:channel_context:{$channelId}",
            60,
            json_encode($ctx, JSON_UNESCAPED_UNICODE)
        );

        // =========================
        // 🧾 REGISTRA TRANSFERÊNCIA PENDENTE
        // =========================
        $this->redis->setex(
            "voice:pending_transfer:{$channelId}",
            30,
            json_encode([
                'channel' => $channelId,
                'ramal' => $chosen,
                'decided_at' => time(),
                'worker' => getmypid(),
            ], JSON_UNESCAPED_UNICODE)
        );

        // 📤 RESPONDE AO STASIS
        $this->respondTransfer(
            $channelId,
            true,
            $chosen,
            $reason
        );
    }


    private function respondTransfer(string $channel, bool $ok, ?string $ramal, string $reason): void
    {
        $this->redis->rpush(
            "voice:worker_response:{$channel}",
            json_encode(compact(
                'ok',
                'ramal',
                'channel',
                'reason'
            ))
        );
    }

    // =========================================================
    // SYNC + EXECUÇÃO REAL DO REDIRECT
    // =========================================================
    private function syncAgentsFromActiveCalls(): void
    {
        // =========================
        // 1️⃣ SNAPSHOT REAL
        // =========================
        $raw = $this->redis->get('asterisk:active_calls');
        $payload = $raw ? json_decode($raw, true) : [];
        $calls = $payload['chamadas'] ?? [];

        $activeChannels = [];

        foreach ($calls as $c) {
            if (!empty($c['id'])) {
                $activeChannels[(string)$c['id']] = true;
            }
        }

        // =========================
        // 2️⃣ EXECUTA TRANSFERS PENDENTES
        // =========================
        foreach ($this->redis->keys('voice:pending_transfer:*') as $key) {

            $data = json_decode($this->redis->get($key), true);

            if (!$data) {
                $this->redis->del($key);
                continue;
            }

            $channel = $data['channel'] ?? null;
            $ramal = $data['ramal'] ?? null;

            if (!$channel || !$ramal) {
                $this->redis->del($key);
                continue;
            }

            // 🔒 LOCK DE REDIRECT
            if (!$this->redis->set(
                "lock:redirect:{$channel}",
                getmypid(),
                'NX',
                'EX',
                5
            )) {
                continue;
            }

            // ❌ Canal morreu antes do redirect
            if (!isset($activeChannels[$channel])) {
                $this->redis->del($key);
                continue;
            }

            try {
                if ($this->attemptRedirectToRamal($channel, $ramal)) {

                    echo "🔁 Redirect OK {$channel} → {$ramal}\n";

                    // 🔄 ATUALIZA AGENTE
                    $infoRaw = $this->redis->hget('discador:agentes', $ramal);
                    $info = $infoRaw ? json_decode($infoRaw, true) : [];

                    $info['status'] = 'OCUPADO';
                    $info['channel'] = $channel;
                    $info['reserved_by'] = 'redirect';
                    $info['reserved_channel'] = $channel;
                    $info['updated'] = time();
                    $info['last_update_by'] = 'redirect';

                    $this->redis->hset(
                        'discador:agentes',
                        $ramal,
                        json_encode($info, JSON_UNESCAPED_UNICODE)
                    );

                    $this->redis->del($key);
                }
            } catch (\Throwable $e) {
                echo "[REDIRECT] ERRO {$e->getMessage()}\n";
            }
        }

        // =========================
        // 3️⃣ LIBERAÇÃO REAL + GC
        // =========================
        $agents = $this->redis->hgetall('discador:agentes');

        foreach ($agents as $ramal => $json) {

            if (!preg_match('/^\d{8}$/', $ramal)) {
                continue;
            }

            $info = json_decode($json, true) ?: [];

            if (!($info['online'] ?? false)) {
                continue;
            }

            $ch = $info['channel'] ?? null;

            // 🔗 Canal ainda ativo → não mexe
            if ($ch && isset($activeChannels[$ch])) {
                continue;
            }


            // 🧹 LIMPA RESERVA EXPIRADA (sem canal real)
            // Regra:
            // - agente LIVRE
            // - tem reserved_by
            // - não está em channel ativo (info['channel'] vazio ou morto)
            // - não tem reserved_channel (ainda não virou redirect)
            // - só limpa se passou do TTL (baseado em reserved_at; fallback updated)
            if (
                (($info['status'] ?? '') === 'LIVRE')
                && !empty($info['reserved_by'])
                && empty($info['reserved_channel'])
            ) {
                // se tiver channel e ele ainda está ativo, não mexe
                $ch = $info['channel'] ?? null;
                if ($ch && isset($activeChannels[$ch])) {
                    // está em chamada real
                } else {

                    // TTL default (igual ao Lua)
                    $ttl = 20;

                    // preferir reserved_at (novo), fallback updated (legado)
                    $ts = (int)($info['reserved_at'] ?? 0);
                    if ($ts <= 0) {
                        $ts = (int)($info['updated'] ?? 0);
                    }

                    // se não tem timestamp, considera elegível pra limpar (mas eu prefiro NÃO limpar)
                    // aqui vamos ser conservadores: sem ts => NÃO limpa
                    if ($ts > 0 && (time() - $ts) > $ttl) {

                        unset($info['reserved_by'], $info['reserved_channel'], $info['reserved_at']);

                        $info['updated'] = time();
                        $info['last_update_by'] = 'expired_reservation_cleanup';

                        $this->redis->hset(
                            'discador:agentes',
                            $ramal,
                            json_encode($info, JSON_UNESCAPED_UNICODE)
                        );

                        echo "🧹 Reserva expirada limpa {$ramal}\n";
                        continue;
                    }
                }
            }


            // 🔓 LIBERA AGENTE OCUPADO SEM CANAL
            if (($info['status'] ?? '') === 'OCUPADO') {

                $info['status'] = 'LIVRE';

                unset(
                    $info['channel'],
                    $info['reserved_by'],
                    $info['reserved_channel']
                );

                $info['updated'] = time();
                $info['last_update_by'] = 'channel_destroyed';

                $this->redis->hset(
                    'discador:agentes',
                    $ramal,
                    json_encode($info, JSON_UNESCAPED_UNICODE)
                );

                echo "🟢 Agente {$ramal} ficou LIVRE\n";
            }
        }
    }


    private function attemptRedirectToRamal(string $channel, string $ramal): bool
    {
        try {

            /**
             * 🔒 Proteção: apenas 1 redirect por channel
             */
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

            /**
             * 🔍 Confirma que o channel ainda existe
             */
            $check = $this->http->get("ari/channels/{$channel}");
            if ($check->getStatusCode() !== 200) {
                return false;
            }

            $info = json_decode((string)$check->getBody(), true);

            /**
             * 🔍 Estados válidos para redirect
             * (Stasis É O CASO REAL)
             */
            $state = $info['state'] ?? '';
            if (!in_array($state, ['Stasis', 'Ring', 'Up'], true)) {
                error_log("[REDIRECT] Estado inválido {$state} channel={$channel}");
                return false;
            }

            /**
             * ==================================================
             * 1️⃣ TENTATIVA PRINCIPAL → DIALPLAN (RECOMENDADO)
             * ==================================================
             */
            $resp = $this->http->post(
                "ari/channels/{$channel}/redirect",
                [
                    'query' => [
                        'context' => 'from-internal', // 🔴 AJUSTE AO SEU CONTEXTO
                        'extension' => $ramal,
                        'priority' => 1
                    ]
                ]
            );

            if ($resp->getStatusCode() >= 200 && $resp->getStatusCode() < 300) {
                return true;
            }

            /**
             * ==================================================
             * 2️⃣ FALLBACK → ENDPOINT DIRETO
             * ==================================================
             */
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

            error_log(
                "[REDIRECT ❌] {$channel} → {$ramal} | {$e->getMessage()}"
            );

            return false;
        }
    }

}


