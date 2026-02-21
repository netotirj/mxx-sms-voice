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

    private array $checkDebounce = []; // [channelId => float microtime]



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

    public function run(): void
    {
        $lastHb = 0;

        while (true) {

            // ✅ heartbeat (independente de evento)
            $now = time();
            if (($now - $lastHb) >= 3) {
                $lastHb = $now;

                try {
                    $this->redis->setex('voice:stasis:heartbeat', 10, json_encode([
                        'ts'   => $now,
                        'pid'  => getmypid(),
                        'host' => $this->ariHost,
                        'app'  => $this->stasisApp,
                    ], JSON_UNESCAPED_UNICODE));
                } catch (\Throwable $e) {
                    // não derruba o listener
                }
            }

            try {
                // ✅ IMPORTANTÍSSIMO: timeout pra não travar o loop
                $raw = $this->ws->receive(1); // 1s
                if (!$raw) continue;

                $event = json_decode($raw, true);
                if (!$event) continue;

                $this->handleEvent($event);

            } catch (\Throwable $e) {

                // registra erro recente (opcional)
                try {
                    $this->redis->setex('voice:stasis:last_error', 30, json_encode([
                        'ts'  => time(),
                        'msg' => $e->getMessage(),
                    ], JSON_UNESCAPED_UNICODE));
                } catch (\Throwable) {}

                sleep(1);
            }
        }
    }

    /**
     * @throws GuzzleException
     */
    private function handleEvent(array $event): void
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

    private function handleChannelVarset(array $event): void
    {
        $channelId = $event['channel']['id'] ?? null;
        $var       = $event['variable'] ?? null;
        $value     = $event['value'] ?? null;
        if (!$channelId || !$var) return;

        // ✅ TUMBA: ignora varset de canal morto e limpa lixo
        if (!empty($this->deadChannels[$channelId]) && $this->deadChannels[$channelId] > time()) {
            unset($this->pendingVars[$channelId]);
            return;
        }

        // Atualiza variáveis em canal ativo ou pendente
        if (isset($this->channelData[$channelId])) {
            $this->channelData[$channelId]['vars'][$var] = $value;
        } else {
            // Apenas guarda, sem recriar o canal ainda
            $this->pendingVars[$channelId][$var] = $value;
            echo "[VARSET ⏳] Guardando {$var}={$value} até canal {$channelId} existir\n";
        }

        // Loga variáveis importantes
        if (in_array($var, ['OWNER_ID', 'TENANT_ID', 'TYPE', 'ROLE', 'JOB_ID', 'TAXA_OF_SERVICE', 'CALL_ID', 'CAMPAIGN_ID', 'CAMPAIGN_TYPE'], true)) {
            error_log("[VARS] Canal={$channelId} → {$var}={$value}");
        }

        // Se OWNER/TENANT chegaram e há FAIL-CDR pendente, tenta salvar agora
        if (
            in_array($var, ['OWNER_ID', 'TENANT_ID'], true) &&
            isset($this->channelData[$channelId])
        ) {
            $this->trySavePendingFailCdr($channelId);
        }

        // Se existe FAIL-CDR pendente, atualiza TYPE nele
        if (
            in_array($var, [
                'TYPE',
                'VARIABLE_TYPE',
                'CAMPAIGN_ID',
                'CAMPAIGN_TYPE',
                'CALL_ID',
                'TAXA_OF_SERVICE',
                'JOB_ID'], true) &&
            isset($this->channelData[$channelId]['pending_fail_cdr'])
        ) {
            $keyMap = [
                'TYPE'            => 'variable_type',
                'VARIABLE_TYPE'   => 'variable_type',
                'CAMPAIGN_ID'     => 'campaign_id',
                'CAMPAIGN_TYPE'   => 'variable_type',
                'CALL_ID'         => 'call_id',
                'TAXA_OF_SERVICE' => 'taxa_of_service',
                'JOB_ID'          => 'job_id',
            ];

            $field = $keyMap[$var] ?? null;


            if ($field) {
                $this->channelData[$channelId]['pending_fail_cdr'][$field] = $value;

                $failKey = "cdr-falha-pendente:{$channelId}";
                $this->redis->setex(
                    $failKey,
                    180,
                    json_encode(
                        $this->channelData[$channelId]['pending_fail_cdr'],
                        JSON_UNESCAPED_UNICODE
                    )
                );

                error_log("[CDR] 🔄 FAIL-CDR atualizado {$field}={$value} canal={$channelId}");
            }

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

        // =============================================================
        // 🟣 CHAMADA MANUAL — regra especial de encerramento
        // =============================================================

        $vars = $this->channelData[$channelId]['vars'] ?? [];

        $isManual = !empty($vars['__IS_MANUAL']) || !empty($vars['IS_MANUAL']);
        $leg      = $vars['ARI_LEG'] ?? $vars['__ARI_LEG'] ?? null;
        $callId   = $vars['CALL_ID'] ?? null;

        // 🅰 A-leg manual
        if ($isManual && $leg === 'A' && $callId) {

            // verifica se ainda existe B-leg vivo para ESTE call_id
            $hasBLeg = false;

            foreach ($this->channelData as $cid => $data) {
                $v = $data['vars'] ?? [];
                if (
                    ($v['CALL_ID'] ?? null) === $callId &&
                    (($v['ARI_LEG'] ?? null) === 'B' || ($v['__ARI_LEG'] ?? null) === 'B')
                ) {
                    $hasBLeg = true;
                    break;
                }
            }

            if ($hasBLeg) {
                error_log("[MANUAL] ⏭ Ignorando destroy A-leg {$channelId} (B-leg ativo)");
                return;
            }

            // B-leg já morreu → agora pode limpar
            error_log("[MANUAL] 🧹 Limpando A-leg manual {$channelId} (B-leg finalizado)");
            // NÃO retorna — deixa cair no cleanup normal
        }




        // 🔥 RINGALL LOSER → remove imediatamente
        if (!empty($this->channelData[$channelId]['ringall_loser'])) {
            unset($this->channelData[$channelId]);
            $this->updateRedis();
            error_log("[RINGALL] 🧹 Canal {$channelId} removido (loser ringall)");
            return;
        }

        // ✅ se já está morto (loser), só some e não processa CDR
        if (!empty($this->deadChannels[$channelId]) && $this->deadChannels[$channelId] > time()) {
            unset($this->pendingVars[$channelId]);
            unset($this->channelData[$channelId]);
            $this->updateRedis();
            return;
        }


        // causa / cause_txt
        $causeCode = $event['cause'] ?? null;
        $causeText = $event['cause_txt'] ?? null;

        if (isset($this->channelData[$channelId])) {
            $this->channelData[$channelId]['ended']     = $this->channelData[$channelId]['ended']     ?? time();
            $this->channelData[$channelId]['cause']     = $this->channelData[$channelId]['cause']     ?? $causeCode;
            $this->channelData[$channelId]['cause_txt'] = $this->channelData[$channelId]['cause_txt'] ?? $causeText;
        }

        // ✅ se esse canal (main) morreu e tinha transferência em andamento, destrava o próprio canal
        if (!empty($this->channelData[$channelId]['transfer_in_progress'])) {
            $this->unlockDtmfTransfer($channelId, 'main_channel_destroyed');
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
        // ✅ RINGALL CLEANUP (APENAS COMPLEMENTO - NÃO MUDA ESTRUTURA)
        // - Se INBOUND morreu: derruba todos OUTBOUNDS que ainda existem (para de tocar)
        // - Se OUTBOUND ringall morreu: remove do mapa do inbound (não tarifa tronco aqui)
        // ==============================================================

        try {

            // 1) Detecta se ESTE canal é um outbound ringall (marca via vars ou appArgs)
            $vars = $this->channelData[$channelId]['vars'] ?? [];

            $isRingallOutbound =
                (($vars['RINGALL'] ?? '') === '1') ||
                (!empty($vars['RINGALL_INBOUND']) && !empty($vars['RINGALL_BRIDGE']));

            if ($isRingallOutbound) {

                $inbound = trim((string)($vars['RINGALL_INBOUND'] ?? ''));
                $bridge  = trim((string)($vars['RINGALL_BRIDGE']  ?? ''));

                // remove do mapa de outbound no inbound (se existir)
                if ($inbound && isset($this->channelData[$inbound]['ringall']['outbound'])) {

                    // tenta achar por channelId
                    foreach (($this->channelData[$inbound]['ringall']['outbound'] ?? []) as $ramal => $ch) {
                        if ($ch === $channelId) {
                            unset($this->channelData[$inbound]['ringall']['outbound'][$ramal]);
                            break;
                        }
                    }
                }

                // 👉 Importante: outbound ringall NÃO deve acionar "tarifar tronco" via peer.
                // Então "zera" o peer daqui pra frente, mas sem mexer no resto da estrutura.
                // (Isso evita cair no bloco 3) Ramal encerrou antes → tarifar tronco)
                $peer = null;

                // opcional: remove do bridge (não quebra se falhar)
                if ($bridge) {
                    try {
                        $this->http->delete("http://{$this->ariHost}:8088/ari/bridges/{$bridge}/removeChannel", [
                            'query'       => ['channel' => $channelId],
                            'http_errors' => false
                        ]);
                    } catch (\Throwable) {}
                }
            }

            // 2) Se ESTE canal é inbound com ringall ativo -> mata todo mundo
            if (!empty($this->channelData[$channelId]['ringall'])) {

                $ring = $this->channelData[$channelId]['ringall'];
                $bridgeId = $ring['bridge'] ?? null;
                $winnerCh = $ring['winner']['channel'] ?? null;

                $outs = $ring['outbound'] ?? [];
                if (is_array($outs)) {
                    foreach ($outs as $ramal => $outCh) {
                        if (!$outCh) continue;

                        // não derruba winner se ainda está vivo
                        if ($winnerCh && $outCh === $winnerCh) continue;

                        // remove da bridge se tiver (best-effort)
                        if ($bridgeId) {
                            try {
                                $this->http->delete("http://{$this->ariHost}:8088/ari/bridges/{$bridgeId}/removeChannel", [
                                    'query'       => ['channel' => $outCh],
                                    'http_errors' => false
                                ]);
                            } catch (\Throwable) {}
                        }

                        // derruba o canal outbound (best-effort)
                        try {
                            $this->http->delete("http://{$this->ariHost}:8088/ari/channels/{$outCh}", [
                                'http_errors' => false
                            ]);
                        } catch (\Throwable) {}

                        // limpa o que for possível do seu mapa local
                        unset($this->channelData[$outCh]);
                    }
                }

                // limpa estrutura ringall do inbound (não interfere em CDR)
                unset($this->channelData[$channelId]['ringall']);
            }

        } catch (\Throwable $e) {
            error_log("[RINGALL] ⚠ cleanup falhou: ".$e->getMessage());
        }


        // ==============================================================
        // 🔓 LIBERAÇÃO CORRETA DE AGENTE (RAMAL)
        // ==============================================================

        $channelType = $this->channelData[$channelId]['type'] ?? null;

        if ($channelType === 'AGENT' && empty($this->channelData[$channelId]['agent_released'])) {

            $this->markDead($channelId, 30);

            $vars = $this->channelData[$channelId]['vars'] ?? [];

            // ✅ ringall outbound também é AGENT -> não pode destravar transfer/origin por engano
            $isRingallAgent =
                (($vars['RINGALL'] ?? '') === '1') ||
                (!empty($vars['RINGALL_INBOUND']) && !empty($vars['RINGALL_BRIDGE']));

            // ✅ se esse AGENT veio de uma transferência (e NÃO é ringall), destrava o origin
            if (!$isRingallAgent) {
                $origin = $this->channelData[$channelId]['transfer_origin'] ?? null;

                if ($origin) {
                    // ✅ para MOH no cliente/origem (se estiver tocando)
                    $this->stopMoh($origin, 'agent_channel_destroyed');

                    $this->unlockDtmfTransfer($origin, 'agent_channel_destroyed');
                    unset($this->channelData[$origin]['transfer_in_progress']);

                    error_log("[TRANSFER] 🔓 Unlock origin={$origin} (agent destroyed={$channelId})");
                }
            }

            // ✅ libera o ramal SEMPRE (transfer ou ringall)
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
        $vars = $this->channelData[$channelId]['vars'] ?? [];

        // 1️⃣ prioridade máxima: var explícita do dialplan
        $isTrunkByVar =
            !empty($vars['TRUNK']) ||
            !empty($vars['TRUNK_ID']);

        // 2️⃣ compatibilidade antiga (mxx*)
        $isTrunkByName =
            preg_match('/^PJSIP\/mxx\d+-/i', $name);

        // 3️⃣ fallback: tudo que NÃO for ramal numérico (8 dígitos)
        $isRamalNumerico =
            preg_match('/^PJSIP\/\d{8}-/i', $name);

        $isTrunk = $isTrunkByVar || $isTrunkByName || !$isRamalNumerico;


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

        $vars = $this->channelData[$channelId]['vars'] ?? [];

        $isManual = (
            !empty($vars['MANUAL_CALL']) ||
            !empty($vars['IS_MANUAL']) ||
            !empty($vars['__IS_MANUAL'])
        );


        // ==============================================================
        // 2) Chamadas atendidas → tarifação
        // ==============================================================
        if ($isMain && !empty($this->channelData[$channelId]['answered'])) {

            // 🚫 ramal do discador não tarifa
            if (!$isTrunk && !$isManual) {
                error_log("[CDR] ⏭ Ramal do discador ignorado {$channelId}");
            } else {
                try {
                    $this->calculateTariff($channelId);
                    $this->channelData[$channelId]['tariff_done'] = true;
                    $this->redis->setex($dupKey, 300, 1);
                } catch (\Throwable $e) {
                    error_log("[CDR] ❌ Erro ao tarifar canal {$channelId}: ".$e->getMessage());
                }
            }
        }


        // ==============================================================
        // 3) Ramal encerrou antes → tarifar tronco
        // ==============================================================

        $isDiscadorRamal = (!$isTrunk && !$isManual);

        if (!$isManual && !$isDiscadorRamal && $peer && isset($this->channelData[$peer]))
        {

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

            // ✅ se o cliente morreu durante transfer, garante que não fica MOH preso nele
            $this->stopMoh($channelId, 'main_channel_destroyed');

            if (empty($this->channelData[$peer]['agent_released'])) {

                $peerType = $this->channelData[$peer]['type'] ?? null;

                if ($peerType === 'AGENT') {

                    $pvars = $this->channelData[$peer]['vars'] ?? [];
                    $peerIsRingall =
                        (($pvars['RINGALL'] ?? '') === '1') ||
                        (!empty($pvars['RINGALL_INBOUND']) && !empty($pvars['RINGALL_BRIDGE']));

                    // ✅ ringall: só libera ramal, não mexe em lock de transferência
                    $agentId = $this->channelData[$peer]['agent_id'] ?? null;

                    if ($agentId) {
                        $this->releaseAgentByChannel($peer, 'main_channel_destroyed');
                        $this->channelData[$peer]['agent_released'] = true;

                        error_log("[AGENT] 🔓 Ramal {$agentId} liberado após cliente desligar"
                            . ($peerIsRingall ? " (ringall)" : ""));
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
            // ✅ TUMBA antes de remover (evita recriar por statechange/dial/varset atrasado)
            $this->markDead($channelId, 30);

            unset($this->pendingVars[$channelId]);
            unset($this->channelData[$channelId]);

            error_log("[CDR] 🧹 Canal {$channelId} removido com segurança.");
        } else {
            error_log("[CDR] ⏳ NÃO removido — aguardando eventos finais (canal {$channelId})");
        }

        $this->updateRedis();
    }

    private function handleChannelStateChange(array $event): void
    {
        $channelId = $event['channel']['id'] ?? null;
        if (!$channelId) return;

        // ✅ TUMBA: não recria canal morto (ringall loser etc.)
        if (!empty($this->deadChannels[$channelId]) && $this->deadChannels[$channelId] > time()) {
            return;
        }

        // PATCH — registra o nome do canal
        if (isset($this->channelData[$channelId])) {
            $this->channelData[$channelId]['name'] = $event['channel']['name'] ?? ($this->channelData[$channelId]['name'] ?? null);
        }

        $state  = strtolower($event['channel']['state'] ?? '');
        $caller = $event['channel']['caller']['number'] ?? 'Desconhecido';
        $oldState = $this->channelData[$channelId]['state'] ?? '(novo)';

        // 🔹 Se o canal ainda não existir, inicializa corretamente
        if (!isset($this->channelData[$channelId])) {
            $this->channelData[$channelId] = [
                'id'       => $channelId,
                'number'   => $caller,
                'status'   => '📞 Tocando',
                'state'    => $state,
                'started'  => time(),
                'duration' => '00:00',
                'vars'     => [],
            ];
            // nome do canal
            $this->channelData[$channelId]['name'] = $event['channel']['name'] ?? null;

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
            $this->channelData[$channelId]['vars'] = array_merge(
                $this->channelData[$channelId]['vars'] ?? [],
                $this->pendingVars[$channelId]
            );
            unset($this->pendingVars[$channelId]);
        }

        // 🔹 Atualiza Redis
        $this->updateRedis();

        echo "[~] Estado alterado: {$oldState} → {$state} (canal {$channelId})\n";

        // =====================================================
        // ✅ RINGALL WINNER (mantém fluxo / sem criar bridge nova)
        // =====================================================
        if ($state === 'up') {

            $vars = $this->channelData[$channelId]['vars'] ?? [];

            // outbound ringall? (criado via ringallToRamais)
            if (($vars['RINGALL'] ?? '') === '1') {

                $inbound  = $vars['RINGALL_INBOUND'] ?? null;
                $bridgeId = $vars['RINGALL_BRIDGE']  ?? null;
                $ramal    = $vars['RINGALL_RAMAL']   ?? null;

                // ✅ normaliza inbound (evita "1769... " com lixo)
                $inbound = $inbound ? trim((string)$inbound) : null;

                if ($inbound && $bridgeId && $ramal && isset($this->channelData[$inbound])) {

                    // ✅ garante estrutura ringall no inbound (e preserva outbound já preenchido)
                    if (!isset($this->channelData[$inbound]['ringall'])) {
                        $this->channelData[$inbound]['ringall'] = [
                            'bridge'   => $bridgeId,
                            'targets'  => [],              // opcional (se você tiver)
                            'outbound' => [],              // ramal => channelId
                            'winner'   => null,
                            'started'  => $this->channelData[$inbound]['started'] ?? time(),
                            'deadline' => time() + 20,
                        ];
                    }

                    // ✅ registra esse outbound caso ainda não esteja no mapa
                    if (empty($this->channelData[$inbound]['ringall']['outbound'][$ramal])) {
                        $this->channelData[$inbound]['ringall']['outbound'][$ramal] = $channelId;
                    }

                    // ✅ já tem winner? então esse é perdedor (hangup seguro)
                    if (!empty($this->channelData[$inbound]['ringall']['winner'])) {
                        echo "[RINGALL] Perdedor {$channelId} (winner já definido)\n";
                        try {
                            $this->http->delete("http://{$this->ariHost}:8088/ari/channels/{$channelId}", ['http_errors' => false]);
                        } catch (\Throwable) {}
                        return;
                    }

                    // ✅ define winner
                    $this->channelData[$inbound]['ringall']['winner'] = [
                        'ramal'   => $ramal,
                        'channel' => $channelId,
                        'ts'      => time(),
                    ];

                    echo "[RINGALL] ✅ Winner {$ramal} channel={$channelId} inbound={$inbound} bridge={$bridgeId}\n";

                    // ✅ adiciona winner no bridge existente (usa query, não json)
                    try {
                        $this->http->post("http://{$this->ariHost}:8088/ari/bridges/{$bridgeId}/addChannel", [
                            'query'       => ['channel' => $channelId],
                            'http_errors' => false
                        ]);
                    } catch (\Throwable) {}

                    // ✅ derruba perdedores (hangup seguro)
                    $outs = $this->channelData[$inbound]['ringall']['outbound'] ?? [];
                    foreach ($outs as $r => $ch) {
                        if ($ch && $ch !== $channelId) {

                            // 1) derruba no Asterisk
                            try {
                                $this->http->delete("http://{$this->ariHost}:8088/ari/channels/{$ch}", ['http_errors' => false]);
                            } catch (\Throwable) {}

                            // 2) marca como morto pra não recriar
                            $this->markDead($ch, 30);

                            // 3) limpa pendingVars (VARSET que chega depois)
                            unset($this->pendingVars[$ch]);

                            // 4) remove do painel imediatamente
                            unset($this->channelData[$ch]);

                            error_log("[RINGALL] 🧹 Perdedor removido do painel: {$ch} (ramal {$r})");
                        }
                    }

                    $this->updateRedis();



                    // ✅ mantém o “peer flow”
                    $this->channelData[$inbound]['peer']   = $channelId;
                    $this->channelData[$channelId]['peer'] = $inbound;

                    // ✅ impede bridge normal (fluxo 1-1) de criar outra bridge
                    $this->channelData[$inbound]['bridge']   = $bridgeId;
                    $this->channelData[$channelId]['bridge'] = $bridgeId;

                    // ✅ memória rrmemory (o inbound guarda a RR_MEM_KEY)
                    $memKey = $this->channelData[$inbound]['vars']['RR_MEM_KEY'] ?? null;
                    if ($memKey) {
                        $this->redis->setex($memKey, 86400, $ramal);
                    }

                    $this->updateRedis();
                    return; // ringall resolvido
                }

                // debug útil quando não entra
                echo "[RINGALL ⚠️] Outbound UP sem contexto completo: "
                    . "inbound=" . ($inbound ?: 'null')
                    . " bridge=" . ($bridgeId ?: 'null')
                    . " ramal=" . ($ramal ?: 'null')
                    . " inbound_exists=" . (isset($this->channelData[$inbound ?? '']) ? '1' : '0')
                    . "\n";
            }
        }


        // =====================================================
        // 🔹 Bridge normal (transfer de 1 ramal) — sem duplicar
        // =====================================================
        if ($state === 'up' && isset($this->channelData[$channelId]['peer'])) {

            // ✅ Ringall outbound não entra no fluxo normal de bridge
            $vars = $this->channelData[$channelId]['vars'] ?? [];
            if (($vars['RINGALL'] ?? '') === '1') {
                return;
            }

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

                        // ✅ Adiciona ambos os canais via QUERY (ARI correto)
                        $this->http->post("http://{$this->ariHost}:8088/ari/bridges/{$bridgeId}/addChannel", [
                            'query'       => ['channel' => "{$channelId},{$peer}"],
                            'http_errors' => false,
                        ]);

                        $this->channelData[$channelId]['bridge'] = $bridgeId;
                        $this->channelData[$peer]['bridge']      = $bridgeId;

                        echo "[🔗] Bridge criada entre {$channelId} e {$peer}\n";

                        $origin =
                            $this->channelData[$channelId]['transfer_origin']
                            ?? $this->channelData[$peer]['transfer_origin']
                            ?? $this->channelData[$channelId]['transfer_from']
                            ?? $this->channelData[$peer]['transfer_from']
                            ?? null;

                        if ($origin) {
                            // ✅ para MOH só no cliente
                            $this->stopMoh($origin, 'bridge_created_origin');

                            // ✅ libera lock/in-progress do cliente
                            $this->unlockDtmfTransfer($origin, 'bridge_created');
                            unset($this->channelData[$origin]['transfer_in_progress']);
                        }
                    }

                    // ✅ Após criar bridge, encerra o canal de origem (tronco) se não for o do ramal
                    $isRingall = !empty($this->channelData[$channelId]['ringall'])
                        || ((($this->channelData[$channelId]['vars'] ?? [])['RINGALL'] ?? '') === '1')
                        || !empty(($this->channelData[$peer] ?? [])['ringall'])
                        || (((($this->channelData[$peer] ?? [])['vars'] ?? [])['RINGALL'] ?? '') === '1');

                    if (!$isRingall) {
                        $chName = $this->channelData[$channelId]['name'] ?? '';
                        if (!preg_match('/PJSIP\/\d+/', $chName)) {
                            try {
                                $this->http->delete("http://{$this->ariHost}:8088/ari/channels/{$channelId}", ['http_errors' => false]);
                                echo "[CLEANUP] 🔚 Canal de origem {$channelId} encerrado após bridge com {$peer}\n";
                            } catch (\Throwable $e) {
                                echo "[CLEANUP ⚠️] Falha ao encerrar canal {$channelId}: {$e->getMessage()}\n";
                            }
                        }
                    } else {
                        echo "[CLEANUP] 🛑 Ringall ativo: cleanup automático ignorado (channel={$channelId})\n";
                    }

                    $this->updateRedis();

                } catch (\Throwable $e) {
                    echo "[BRIDGE ⚠️] Erro ao criar bridge entre {$channelId} e {$peer}: {$e->getMessage()}\n";

                    // ✅ não deixar lock preso se falhou bridge
                    $origin =
                        $this->channelData[$channelId]['transfer_origin']
                        ?? $this->channelData[$peer]['transfer_origin']
                        ?? $this->channelData[$channelId]['transfer_from']
                        ?? $this->channelData[$peer]['transfer_from']
                        ?? null;

                    if ($origin) {
                        $this->unlockDtmfTransfer($origin, 'bridge_exception');
                        unset($this->channelData[$origin]['transfer_in_progress']);
                    }
                }
            }

        }

    }

    private function markDead(string $channelId, int $ttl = 15): void
    {
        $this->deadChannels[$channelId] = time() + $ttl;
    }









    /*private function onStart(array $event): void
    {
        $channelId = $event['channel']['id'] ?? null;
        if (!$channelId) return;

        // -----------------------------
        // Variáveis vindas do originate / dialplan
        // -----------------------------
        $ariVars = $event['variables'] ?? [];

        $jobId        = $ariVars['JOB_ID']        ?? null;
        $campaignId   = $ariVars['CAMPAIGN_ID']   ?? null;
        $campaignType = $ariVars['CAMPAIGN_TYPE'] ?? null;

        // ✅ CALL_ID pode vir no onStart OU por VARSET (race)
        $callId = $ariVars['CALL_ID'] ?? null;

        // fallback: VARSET chegou antes
        if (!$callId && !empty($this->pendingVars[$channelId]['CALL_ID'])) {
            $callId = $this->pendingVars[$channelId]['CALL_ID'];
        }

        // se já existe channelData vars (raro aqui, mas seguro)
        if (!$callId && !empty($this->channelData[$channelId]['vars']['CALL_ID'])) {
            $callId = $this->channelData[$channelId]['vars']['CALL_ID'];
        }

        $callId = $callId ? trim((string)$callId) : null;

        echo "[DEBUG] CALL_ID={$callId}\n";

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

        // -----------------------------
        // Detecta ringall outbound pelo appArgs
        // -----------------------------
        $mode = null;
        $inboundFromArgs = null;
        $bridgeFromArgs  = null;

        $raw = trim($event['channel']['dialplan']['app_data'] ?? '');
        $raw = trim($raw, "\"'");

        // seu appArgs do originate: "ringall,inbound,bridge"
        if ($raw !== '') {
            $parts = array_map('trim', explode(',', $raw));
            $mode = $parts[0] ?? null;
            if ($mode === 'ringall') {
                $inboundFromArgs = $parts[1] ?? null;
                $bridgeFromArgs  = $parts[2] ?? null;
            }
        }

        if ($mode === 'ringall') {

            // ✅ garante que channelData exista antes de setar vars
            if (!isset($this->channelData[$channelId])) {
                $this->channelData[$channelId] = [
                    'id'       => $channelId,
                    'name'     => $event['channel']['name'] ?? null,
                    'peer'     => $inboundFromArgs,   // inbound = peer lógico
                    'started'  => time(),
                    'state'    => strtolower($event['channel']['state'] ?? 'ringing'),
                    'status'   => '📞 RingAll',
                    'duration' => '00:00',
                    'vars'     => [],
                ];
            } else {
                $this->channelData[$channelId]['name'] = $event['channel']['name'] ?? ($this->channelData[$channelId]['name'] ?? null);
                $this->channelData[$channelId]['peer'] = $inboundFromArgs ?: ($this->channelData[$channelId]['peer'] ?? null);
                if (!isset($this->channelData[$channelId]['vars'])) $this->channelData[$channelId]['vars'] = [];
            }

            // ✅ ramal do OUTBOUND: pega do nome do canal (PJSIP/02911642-xxxxx)
            $ramal = null;
            $nm = $event['channel']['name'] ?? '';
            if (preg_match('/PJSIP\/(\d{8})/i', $nm, $m)) {
                $ramal = $m[1];
            }

            // injeta vars pro stateChange eleger winner
            $this->channelData[$channelId]['vars']['RINGALL']         = '1';
            $this->channelData[$channelId]['vars']['RINGALL_INBOUND'] = $inboundFromArgs;
            $this->channelData[$channelId]['vars']['RINGALL_BRIDGE']  = $bridgeFromArgs;
            $this->channelData[$channelId]['vars']['RINGALL_RAMAL']   = $ramal;

            // ✅ MUITO IMPORTANTE: não tocar áudio, não answer, não rodar transfer_only
            echo "[RINGALL] onStart outbound {$channelId} inbound={$inboundFromArgs} bridge={$bridgeFromArgs} ramal=" . ($ramal ?: "null") . "\n";

            // salva logo (evita race)
            try {
                $this->redis->hset('discador:stasis:channels', $channelId, json_encode($this->channelData[$channelId]));
            } catch (\Throwable $e) {}

            return;
        }

        $mainAudios = $appArgs['audio'] ?? [];
        if (!is_array($mainAudios)) $mainAudios = [$mainAudios];

        $dtmf   = $appArgs['dtmf'] ?? [];
        $action = $appArgs['action'] ?? null;

        echo "[DEBUG] ACTION={$action}\n";

        // -----------------------------
        // CallerID normalizado
        // -----------------------------
        $callerNumber = $event['channel']['caller']['number'] ?? 'anonymous';
        $callerName   = $event['channel']['caller']['name'] ?? $callerNumber;

        if (strlen($callerNumber) > 11) {
            $callerNumber = substr($callerNumber, -11);
        }

        // -----------------------------
        // ✅ Inicializa canal COMPLETO sempre
        // (não faça "vars-only" antes, senão nunca entra aqui)
        // -----------------------------
        if (!isset($this->channelData[$channelId])) {
            $this->channelData[$channelId] = [
                'id'            => $channelId,
                'name'          => $event['channel']['name'] ?? null,
                'extension'     => $callerNumber,
                'caller_name'   => $callerName,
                'mainAudios'    => $mainAudios,
                'dtmf'          => $dtmf,
                'action'        => $action,
                'play_only'     => ($action === 'play_only') ? 'play_only' : null,
                'dtmf_received' => false,
                'handled_dtmf'  => [],
                'peer'          => null,
                'started'       => time(),
                'status'        => 'Chamando...',
                'state'         => 'ringing',
                'duration'      => '00:00',
                'vars'          => [],
            ];
            echo "[📞] Novo canal iniciado: {$callerNumber} ({$channelId})\n";
        } else {
            // Atualiza campos principais
            $this->channelData[$channelId]['name']        = $event['channel']['name'] ?? ($this->channelData[$channelId]['name'] ?? null);
            $this->channelData[$channelId]['mainAudios']  = $mainAudios;
            $this->channelData[$channelId]['dtmf']        = $dtmf;
            $this->channelData[$channelId]['caller_name'] = $callerName;
            $this->channelData[$channelId]['action']      = $action;
            $this->channelData[$channelId]['play_only']   = ($action === 'play_only') ? 'play_only' : null;
        }

        // -----------------------------
        // Injeta explicitamente vars "core" (evita race com VARSET)
        // -----------------------------
        if ($jobId)        $this->channelData[$channelId]['vars']['JOB_ID'] = $jobId;
        if ($campaignId)   $this->channelData[$channelId]['vars']['CAMPAIGN_ID'] = $campaignId;
        if ($campaignType) $this->channelData[$channelId]['vars']['CAMPAIGN_TYPE'] = $campaignType;
        if ($callId)       $this->channelData[$channelId]['vars']['CALL_ID'] = $callId;

        // Merge variáveis do Dialplan/Originate
        $this->channelData[$channelId]['vars'] = array_merge(
            $this->channelData[$channelId]['vars'] ?? [],
            $ariVars
        );

        // Se tinha pendingVars, injeta também (resolve varset antes do canal existir)
        if (!empty($this->pendingVars[$channelId])) {
            $this->channelData[$channelId]['vars'] = array_merge(
                $this->channelData[$channelId]['vars'],
                $this->pendingVars[$channelId]
            );
            unset($this->pendingVars[$channelId]);
        }

        // -----------------------------
        // ✅ Contexto por CALL_ID (mesmo com ramal NULL)
        // -----------------------------
        if ($callId) {
            $ccRaw = $this->redis->get("voice:call_context:{$callId}");
            if ($ccRaw) {
                $cc = json_decode($ccRaw, true) ?: [];

                $this->redis->setex(
                    "voice:channel_context:{$channelId}",
                    300,
                    json_encode([
                        'call_id'        => $callId,
                        'job_id'         => $jobId,
                        'reserved_ramal' => $cc['ramal'] ?? null,
                        'ramal'          => $cc['ramal'] ?? null,
                        'strategy'       => $cc['strategy'] ?? ($this->channelData[$channelId]['vars']['STRATEGY'] ?? 'rrmemory'),
                        'endpoints'      => $cc['endpoints'] ?? [],
                        'ts'             => time(),
                    ], JSON_UNESCAPED_UNICODE)
                );
            }
        }

        // -----------------------------
        // SALVAR IMEDIATAMENTE NO REDIS (evita race)
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
        // Atender canal
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
        // ✅ TRANSFER_ONLY: transfere direto (sem DTMF)
        // -----------------------------
        if ($action === 'transfer_only') {

            echo "[TRANSFER_ONLY] Iniciando transferência automática (canal {$channelId})\n";

            // ✅ MOH enquanto o worker decide/espera agente
            $this->stopMoh($channelId, 'transfer_only_start_guard'); // idempotente
            $this->playMoh($channelId, 'default', 'transfer_only_wait_agent_answer');

            // garante call_id
            if (!$callId) {
                $callId = $this->channelData[$channelId]['vars']['CALL_ID'] ?? null;
                $callId = $callId ? trim((string)$callId) : null;
            }

            $ramalSolicitado = null;

            // 1) call_context por CALL_ID
            if ($callId) {
                $ccRaw = $this->redis->get("voice:call_context:{$callId}");
                if ($ccRaw) {
                    $cc = json_decode($ccRaw, true) ?: [];
                    $ramalSolicitado = $cc['ramal'] ?? null; // pode ser null no transfer_only
                }
            }

            // 2) RESERVED_AGENT
            if (!$ramalSolicitado) {
                $ramalSolicitado = $this->channelData[$channelId]['vars']['RESERVED_AGENT'] ?? null;
            }

            // 3) EXTENSION só se for ramal
            if (!$ramalSolicitado) {
                $ext = $this->channelData[$channelId]['vars']['EXTENSION'] ?? null;
                $ext = preg_replace('/\D/', '', (string)$ext);
                if (preg_match('/^\d{8}$/', $ext)) {
                    $ramalSolicitado = $ext;
                }
            }

            // normaliza e valida
            $ramalSolicitado = $ramalSolicitado ? preg_replace('/\D/', '', (string)$ramalSolicitado) : null;
            if ($ramalSolicitado && !preg_match('/^\d{8}$/', $ramalSolicitado)) {
                $ramalSolicitado = null;
            }

            echo "[TRANSFER_ONLY] 🔍 Validando agente " . ($ramalSolicitado ?: "AUTO")
                . " call_id=" . ($callId ?: "null") . "\n";

            $tent     = 0;
            $maxTent  = 2;

            // janela de espera por tentativa (segundos)
            $timeoutTotal = 2; //8.0;

            while ($tent < $maxTent) {

                if (!$this->channelExists($channelId)) {
                    echo "[TRANSFER_ONLY] ⚠ Canal destruído durante espera\n";
                    $this->stopMoh($channelId);
                    return;
                }

                // ✅ tenta enviar check (pode ser bloqueado pelo debounce)
                $reqId = $this->notifyWorkerCheckRequest($channelId, $ramalSolicitado, $callId);

                if (!$reqId) {
                    // debounce bloqueou: NÃO existe resposta nova -> só espera pouco e tenta de novo
                    usleep(200_000); // 200ms
                    continue;
                }

                // ✅ só espera se enviou (req_id existe)
                $resp = $this->waitWorkerResponse($channelId, 2, $reqId);

                if ($resp && !empty($resp['ok'])) {

                    // ✅ RINGALL (Opção A) — vem sem ramal
                    if (($resp['mode'] ?? '') === 'ringall') {

                        $targets = $resp['targets'] ?? [];
                        $targets = is_array($targets) ? $targets : [];

                        echo "[TRANSFER_ONLY] ✅ Ringall autorizado (" . count($targets) . " alvos)\n";

                        // ringall vai controlar o resto -> garante que não fica MOH “preso”
                        $this->stopMoh($channelId);

                        if (!empty($resp['rr_mem_key'])) {
                            $this->channelData[$channelId]['vars']['RR_MEM_KEY'] = $resp['rr_mem_key'];
                        }
                        if (!empty($resp['call_id'])) {
                            $this->channelData[$channelId]['vars']['CALL_ID'] = $resp['call_id'];
                        }

                        $this->ringallToRamais($channelId, $targets);
                        return;
                    }

                    // ✅ modo normal (1 ramal)
                    $ramal = $resp['ramal'] ?? $ramalSolicitado;

                    echo "[TRANSFER_ONLY] ✅ Aprovado → {$ramal}\n";

                    try {
                        // ✅ MOH no canal do cliente enquanto o ramal está sendo chamado
                        usleep(200000);
                        $this->playMoh($channelId, 'default');

                        $this->transferToRamal($channelId, $ramal);

                    } catch (\Throwable $e) {
                        echo "[TRANSFER_ONLY] ❌ ERRO: {$e->getMessage()}\n";

                        // ✅ se falhou, para o MOH pra não deixar o cliente preso em música
                        $this->stopMoh($channelId);

                        $this->stopMoh($channelId, 'before_agents_busy');
                        $this->playWorkerAudio($channelId, "agents-busy");
                        $this->ChannelFinishDestroyed($channelId, "Erro ao transferir", true);
                        return;
                    }

                    return; // ✅ não seguir fluxo normal
                }

                // aqui: ou não veio resp, ou veio ok=false
                $tent++;
                $reason = $resp['reason'] ?? 'no_response';

                echo "[TRANSFER_ONLY] ❌ Indisponível ({$tent}/{$maxTent}) reason={$reason}\n";

                // antes de tocar áudio, garante que MOH não está ativo
                $this->stopMoh($channelId);

                // toca áudio em tentativas ímpares
                if ($tent % 2 === 1) {
                    $this->stopMoh($channelId, 'before_agents_busy');
                    $this->playWorkerAudio($channelId, "agents-busy");
                    $this->waitPlaybackFinish($channelId);
                }

                // ✅ espera “humana” antes de tentar de novo, mas sem flood
                $inicio = microtime(true);
                while (microtime(true) - $inicio < $timeoutTotal) {

                    if (!$this->channelExists($channelId)) {
                        echo "[TRANSFER_ONLY] ⚠ Canal destruído durante espera\n";
                        $this->stopMoh($channelId);
                        return;
                    }

                    // tenta enviar (respeita debounce)
                    $reqId = $this->notifyWorkerCheckRequest($channelId, $ramalSolicitado, $callId);
                    if ($reqId) {
                        $resp = $this->waitWorkerResponse($channelId, 1, $reqId);
                        if ($resp && !empty($resp['ok'])) {

                            // ringall
                            if (($resp['mode'] ?? '') === 'ringall') {
                                $targets = $resp['targets'] ?? [];
                                $targets = is_array($targets) ? $targets : [];
                                echo "[TRANSFER_ONLY] ✅ Ringall autorizado (" . count($targets) . " alvos)\n";
                                $this->stopMoh($channelId);
                                $this->ringallToRamais($channelId, $targets);
                                return;
                            }

                            $ramal = $resp['ramal'] ?? $ramalSolicitado;

                            echo "[TRANSFER_ONLY] ✅ Aprovado → {$ramal}\n";

                            try {
                                usleep(200000);
                                $this->playMoh($channelId, 'default');

                                $this->transferToRamal($channelId, $ramal);

                            } catch (\Throwable $e) {
                                echo "[TRANSFER_ONLY] ❌ ERRO: {$e->getMessage()}\n";
                                $this->stopMoh($channelId);
                                $this->playWorkerAudio($channelId, "agents-busy");
                                $this->ChannelFinishDestroyed($channelId, "Erro ao transferir", true);
                                return;
                            }

                            return;
                        }
                    }

                    usleep(250_000); // 250ms entre re-tentativas dentro da janela
                }

                // cai pro próximo tent
            }

            echo "[TRANSFER_ONLY] ❌ Nenhum agente livre\n";
            $this->stopMoh($channelId);
            $this->playWorkerAudio($channelId, "agents-busy");
            $this->ChannelFinishDestroyed($channelId, "Falha na transferência", true);
            return;
        }

        // -----------------------------
        // Atualiza no Redis após atender
        // -----------------------------
        try {
            $this->redis->hset('discador:stasis:channels', $channelId, json_encode($this->channelData[$channelId]));
        } catch (\Throwable $e) {}
    }*/

    private function getLeg(string $channelId, array $ariVars = []): ?string
    {
        $vars = $ariVars;

        if (!empty($this->channelData[$channelId]['vars'])) {
            $vars = array_merge($vars, $this->channelData[$channelId]['vars']);
        }
        if (!empty($this->pendingVars[$channelId])) {
            $vars = array_merge($vars, $this->pendingVars[$channelId]);
        }

        $leg = $vars['ARI_LEG'] ?? $vars['__ARI_LEG'] ?? null;
        return $leg ? strtoupper(trim($leg)) : null; // 'A' ou 'B'
    }



    private function onStart(array $event): void
    {
        $channelId = $event['channel']['id'] ?? null;
        if (!$channelId) return;

        // =====================================================
        // 🔁 CONTINUE ONCE (evita double continue no Stasis)
        // =====================================================
        $continueOnce = function(array $event, bool $forceNoQuery = false) use ($channelId) {
            try {
                if (!empty($this->channelData[$channelId]['vars']['STASIS_CONTINUED'])) return;

                if (!isset($this->channelData[$channelId])) {
                    $this->channelData[$channelId] = ['id'=>$channelId,'vars'=>[]];
                }

                $this->channelData[$channelId]['vars']['STASIS_CONTINUED'] = '1';

                // marca no canal (best effort)
                $this->http->post(
                    "http://{$this->ariHost}:8088/ari/channels/{$channelId}/variable",
                    [
                        'query'       => ['variable' => 'STASIS_CONTINUED', 'value' => '1'],
                        'http_errors' => false,
                    ]
                );

                if ($forceNoQuery) {
                    $res = $this->http->post(
                        "http://{$this->ariHost}:8088/ari/channels/{$channelId}/continue",
                        ['http_errors' => false]
                    );

                    $code = method_exists($res, 'getStatusCode') ? $res->getStatusCode() : null;
                    echo "[CONTINUE] channel={$channelId} (noquery) http=" . ($code ?? 'null') . "\n";
                    return;
                }

                $dp  = $event['channel']['dialplan'] ?? [];
                $ctx = $dp['context'] ?? null;
                $ext = $dp['exten']   ?? null;
                $pri = isset($dp['priority']) ? ((int)$dp['priority'] + 1) : null;

                $q = [];
                if ($ctx && $ext && $pri) {
                    $q = ['context' => $ctx, 'extension' => $ext, 'priority' => $pri];
                }

                $res = $this->http->post(
                    "http://{$this->ariHost}:8088/ari/channels/{$channelId}/continue",
                    [
                        'query'       => $q,
                        'http_errors' => false,
                    ]
                );

                $code = method_exists($res, 'getStatusCode') ? $res->getStatusCode() : null;
                echo "[CONTINUE] channel={$channelId} ctx={$ctx} exten={$ext} pri={$pri} http=" . ($code ?? 'null') . "\n";

            } catch (\Throwable $e) {
                echo "[CONTINUE ⚠️] {$e->getMessage()}\n";
            }
        };

        // -----------------------------
        // Variáveis vindas do originate / dialplan
        // -----------------------------
        $ariVars = $event['variables'] ?? [];

        $jobId        = $ariVars['JOB_ID']        ?? null;
        $campaignId   = $ariVars['CAMPAIGN_ID']   ?? null;
        $campaignType = $ariVars['CAMPAIGN_TYPE'] ?? null;

        // ✅ CALL_ID pode vir no onStart OU por VARSET (race)
        $callId = $ariVars['CALL_ID'] ?? null;

        // fallback: VARSET chegou antes
        if (!$callId && !empty($this->pendingVars[$channelId]['CALL_ID'])) {
            $callId = $this->pendingVars[$channelId]['CALL_ID'];
        }

        // se já existe channelData vars (raro aqui, mas seguro)
        if (!$callId && !empty($this->channelData[$channelId]['vars']['CALL_ID'])) {
            $callId = $this->channelData[$channelId]['vars']['CALL_ID'];
        }

        $callId = $callId ? trim((string)$callId) : null;

        echo "[DEBUG] CALL_ID={$callId}\n";

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


        $allVars = $ariVars;
        if (!empty($this->pendingVars[$channelId])) {
            $allVars = array_merge($allVars, $this->pendingVars[$channelId]);
        }

        $leg = $allVars['ARI_LEG'] ?? $allVars['__ARI_LEG'] ?? null;
        $isPreDial = ($leg === 'B');

        $isManual = (
            !empty($allVars['MANUAL_CALL']) ||
            !empty($allVars['IS_MANUAL']) ||
            !empty($allVars['__IS_MANUAL']) ||
            !empty($allVars['__MANUAL_CALL'])
        );

        if ($isManual) {
            $allVars['MANUAL_CALL'] = '1';
        }

        echo "[DEBUG] IS_PREDIAL=" . ($isPreDial ? '1' : '0') . " leg=" . ($leg ?: "null") . "\n";
        echo "[DEBUG] IS_MANUAL=" . ($isManual ? '1' : '0') . "\n";


        // -----------------------------
        // Detecta ringall outbound pelo appArgs
        // -----------------------------
        $mode = null;
        $inboundFromArgs = null;
        $bridgeFromArgs  = null;

        $raw = trim($event['channel']['dialplan']['app_data'] ?? '');
        $raw = trim($raw, "\"'");

        // seu appArgs do originate: "ringall,inbound,bridge"
        if ($raw !== '') {
            $parts = array_map('trim', explode(',', $raw));
            $mode = $parts[0] ?? null;
            if ($mode === 'ringall') {
                $inboundFromArgs = $parts[1] ?? null;
                $bridgeFromArgs  = $parts[2] ?? null;
            }
        }

        if ($mode === 'ringall') {

            // ✅ garante que channelData exista antes de setar vars
            if (!isset($this->channelData[$channelId])) {
                $this->channelData[$channelId] = [
                    'id'       => $channelId,
                    'name'     => $event['channel']['name'] ?? null,
                    'peer'     => $inboundFromArgs,   // inbound = peer lógico
                    'started'  => time(),
                    'state'    => strtolower($event['channel']['state'] ?? 'ringing'),
                    'status'   => '📞 RingAll',
                    'duration' => '00:00',
                    'vars'     => [],
                ];
            } else {
                $this->channelData[$channelId]['name'] = $event['channel']['name'] ?? ($this->channelData[$channelId]['name'] ?? null);
                $this->channelData[$channelId]['peer'] = $inboundFromArgs ?: ($this->channelData[$channelId]['peer'] ?? null);
                if (!isset($this->channelData[$channelId]['vars'])) $this->channelData[$channelId]['vars'] = [];
            }

            // ✅ ramal do OUTBOUND: pega do nome do canal (PJSIP/02911642-xxxxx)
            $ramal = null;
            $nm = $event['channel']['name'] ?? '';
            if (preg_match('/PJSIP\/(\d{8})/i', $nm, $m)) {
                $ramal = $m[1];
            }

            // injeta vars pro stateChange eleger winner
            $this->channelData[$channelId]['vars']['RINGALL']         = '1';
            $this->channelData[$channelId]['vars']['RINGALL_INBOUND'] = $inboundFromArgs;
            $this->channelData[$channelId]['vars']['RINGALL_BRIDGE']  = $bridgeFromArgs;
            $this->channelData[$channelId]['vars']['RINGALL_RAMAL']   = $ramal;

            // ✅ MUITO IMPORTANTE: não tocar áudio, não answer, não rodar transfer_only
            echo "[RINGALL] onStart outbound {$channelId} inbound={$inboundFromArgs} bridge={$bridgeFromArgs} ramal=" . ($ramal ?: "null") . "\n";

            // salva logo (evita race)
            try {
                $this->redis->hset('discador:stasis:channels', $channelId, json_encode($this->channelData[$channelId]));
            } catch (\Throwable $e) {}

            return;
        }

        $mainAudios = $appArgs['audio'] ?? [];
        if (!is_array($mainAudios)) $mainAudios = [$mainAudios];

        $dtmf   = $appArgs['dtmf'] ?? [];
        $action = $appArgs['action'] ?? null;

        echo "[DEBUG] ACTION={$action}\n";

        // -----------------------------
        // CallerID normalizado
        // -----------------------------
        $callerNumber = $event['channel']['caller']['number'] ?? 'anonymous';
        $callerName   = $event['channel']['caller']['name'] ?? $callerNumber;

        if (strlen($callerNumber) > 11) {
            $callerNumber = substr($callerNumber, -11);
        }

        // -----------------------------
        // ✅ Inicializa canal COMPLETO sempre
        // (não faça "vars-only" antes, senão nunca entra aqui)
        // -----------------------------
        if (!isset($this->channelData[$channelId])) {
            $this->channelData[$channelId] = [
                'id'            => $channelId,
                'name'          => $event['channel']['name'] ?? null,
                'extension'     => $callerNumber,
                'caller_name'   => $callerName,
                'mainAudios'    => $mainAudios,
                'dtmf'          => $dtmf,
                'action'        => $action,
                'play_only'     => ($action === 'play_only') ? 'play_only' : null,
                'dtmf_received' => false,
                'handled_dtmf'  => [],
                'peer'          => null,
                'started'       => time(),
                'status'        => 'Chamando...',
                'state'         => 'ringing',
                'duration'      => '00:00',
                'vars'          => [],
            ];
            echo "[📞] Novo canal iniciado: {$callerNumber} ({$channelId})\n";
        } else {
            // Atualiza campos principais
            $this->channelData[$channelId]['name']        = $event['channel']['name'] ?? ($this->channelData[$channelId]['name'] ?? null);
            $this->channelData[$channelId]['mainAudios']  = $mainAudios;
            $this->channelData[$channelId]['dtmf']        = $dtmf;
            $this->channelData[$channelId]['caller_name'] = $callerName;
            $this->channelData[$channelId]['action']      = $action;
            $this->channelData[$channelId]['play_only']   = ($action === 'play_only') ? 'play_only' : null;
        }

        // -----------------------------
        // Injeta explicitamente vars "core" (evita race com VARSET)
        // -----------------------------
        if ($jobId)        $this->channelData[$channelId]['vars']['JOB_ID'] = $jobId;
        if ($campaignId)   $this->channelData[$channelId]['vars']['CAMPAIGN_ID'] = $campaignId;
        if ($campaignType) $this->channelData[$channelId]['vars']['CAMPAIGN_TYPE'] = $campaignType;
        if ($callId)       $this->channelData[$channelId]['vars']['CALL_ID'] = $callId;

        // Merge variáveis do Dialplan/Originate
        $this->channelData[$channelId]['vars'] = array_merge(
            $this->channelData[$channelId]['vars'] ?? [],
            $ariVars
        );

        // Se tinha pendingVars, injeta também (resolve varset antes do canal existir)
        if (!empty($this->pendingVars[$channelId])) {
            $this->channelData[$channelId]['vars'] = array_merge(
                $this->channelData[$channelId]['vars'],
                $this->pendingVars[$channelId]
            );
            unset($this->pendingVars[$channelId]);
        }

        // -----------------------------
        // ✅ Contexto por CALL_ID (mesmo com ramal NULL)
        // -----------------------------
        if ($callId) {
            $ccRaw = $this->redis->get("voice:call_context:{$callId}");
            if ($ccRaw) {
                $cc = json_decode($ccRaw, true) ?: [];

                $this->redis->setex(
                    "voice:channel_context:{$channelId}",
                    300,
                    json_encode([
                        'call_id'        => $callId,
                        'job_id'         => $jobId,
                        'reserved_ramal' => $cc['ramal'] ?? null,
                        'ramal'          => $cc['ramal'] ?? null,
                        'strategy'       => $cc['strategy'] ?? ($this->channelData[$channelId]['vars']['STRATEGY'] ?? 'rrmemory'),
                        'endpoints'      => $cc['endpoints'] ?? [],
                        'ts'             => time(),
                    ], JSON_UNESCAPED_UNICODE)
                );
            }
        }

        // -----------------------------
        // SALVAR IMEDIATAMENTE NO REDIS (evita race)
        // -----------------------------
        try {
            $redisKey = 'discador:stasis:channels';
            $this->redis->hset($redisKey, $channelId, json_encode($this->channelData[$channelId]));
            echo "[REDIS] Canal {$channelId} salvo em {$redisKey}\n";
        } catch (\Throwable $e) {
            echo "[REDIS ⚠️] Falha ao salvar canal: {$e->getMessage()}\n";
        }

        // ============================================
        // 🚦 MANUAL / PRE-DIAL DEVEM SAIR ANTES DO FLOW NORMAL
        // ============================================

        if ($isManual) {
            echo "[MANUAL] A-only (não publicar) channel={$channelId}\n";

            // você pode manter channelData mínimo se quiser
            $this->channelData[$channelId] = $this->channelData[$channelId] ?? [
                'id' => $channelId,
                'name' => $event['channel']['name'] ?? null,
                'started' => time(),
                'vars' => [],
            ];

            $this->channelData[$channelId]['vars'] = array_merge(
                $this->channelData[$channelId]['vars'],
                $ariVars,
                $this->pendingVars[$channelId] ?? []
            );
            unset($this->pendingVars[$channelId]);

            // ✅ NÃO faz hset no discador:stasis:channels pro A
            // $this->redis->hset(...)

            // ✅ só libera dialplan
            $continueOnce($event, true);
            return;
        }


        // -----------------------------
        // ✅ PRE-DIAL (canal B)
        // NÃO answer (evita 412)
        // -----------------------------
        if ($isPreDial) {

            if (!isset($this->channelData[$channelId])) {
                $callerNumber = $event['channel']['caller']['number'] ?? 'anonymous';
                $callerName   = $event['channel']['caller']['name'] ?? $callerNumber;

                $this->channelData[$channelId] = [
                    'id'          => $channelId,
                    'name'        => $event['channel']['name'] ?? null,
                    'extension'   => $callerNumber,
                    'caller_name' => $callerName,
                    'peer'        => null,
                    'started'     => time(),
                    'status'      => '📌 Pre-dial',
                    'state'       => strtolower($event['channel']['state'] ?? 'ringing'),
                    'duration'    => '00:00',
                    'vars'        => [],
                ];
            }

            // merge vars
            $this->channelData[$channelId]['vars'] = array_merge(
                $this->channelData[$channelId]['vars'],
                $ariVars
            );

            if (!empty($this->pendingVars[$channelId])) {
                $this->channelData[$channelId]['vars'] = array_merge(
                    $this->channelData[$channelId]['vars'],
                    $this->pendingVars[$channelId]
                );
                unset($this->pendingVars[$channelId]);
            }

            try {
                $this->redis->hset(
                    'discador:stasis:channels',
                    $channelId,
                    json_encode($this->channelData[$channelId])
                );
            } catch (\Throwable $e) {}

            echo "[PREDIAL] channel={$channelId} -> continue(noquery)\n";

            // flag p/ dialplan
            try {
                $this->http->post(
                    "http://{$this->ariHost}:8088/ari/channels/{$channelId}/variable",
                    [
                        'query'       => ['variable' => 'ARI_B_READY', 'value' => '1'],
                        'http_errors' => false,
                    ]
                );
            } catch (\Throwable $e) {}

            // libera o canal B
            $continueOnce($event, true);
            return;
        }

        // -----------------------------
        // Toca áudio inicial se existir
        // -----------------------------
        if (!empty($mainAudios)) {
            $this->playMainAudio($channelId);
        }

        // -----------------------------
        // Atender canal
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
        // ✅ TRANSFER_ONLY: transfere direto (sem DTMF)
        // -----------------------------
        if ($action === 'transfer_only') {

            echo "[TRANSFER_ONLY] Iniciando transferência automática (canal {$channelId})\n";

            // ✅ MOH enquanto o worker decide/espera agente
            $this->stopMoh($channelId, 'transfer_only_start_guard'); // idempotente
            $this->playMoh($channelId, 'default', 'transfer_only_wait_agent_answer');

            // garante call_id
            if (!$callId) {
                $callId = $this->channelData[$channelId]['vars']['CALL_ID'] ?? null;
                $callId = $callId ? trim((string)$callId) : null;
            }

            $ramalSolicitado = null;

            // 1) call_context por CALL_ID
            if ($callId) {
                $ccRaw = $this->redis->get("voice:call_context:{$callId}");
                if ($ccRaw) {
                    $cc = json_decode($ccRaw, true) ?: [];
                    $ramalSolicitado = $cc['ramal'] ?? null; // pode ser null no transfer_only
                }
            }

            // 2) RESERVED_AGENT
            if (!$ramalSolicitado) {
                $ramalSolicitado = $this->channelData[$channelId]['vars']['RESERVED_AGENT'] ?? null;
            }

            // 3) EXTENSION só se for ramal
            if (!$ramalSolicitado) {
                $ext = $this->channelData[$channelId]['vars']['EXTENSION'] ?? null;
                $ext = preg_replace('/\D/', '', (string)$ext);
                if (preg_match('/^\d{8}$/', $ext)) {
                    $ramalSolicitado = $ext;
                }
            }

            // normaliza e valida
            $ramalSolicitado = $ramalSolicitado ? preg_replace('/\D/', '', (string)$ramalSolicitado) : null;
            if ($ramalSolicitado && !preg_match('/^\d{8}$/', $ramalSolicitado)) {
                $ramalSolicitado = null;
            }

            echo "[TRANSFER_ONLY] 🔍 Validando agente " . ($ramalSolicitado ?: "AUTO")
                . " call_id=" . ($callId ?: "null") . "\n";

            $tent     = 0;
            $maxTent  = 2;

            // janela de espera por tentativa (segundos)
            $timeoutTotal = 2; //8.0;

            while ($tent < $maxTent) {

                if (!$this->channelExists($channelId)) {
                    echo "[TRANSFER_ONLY] ⚠ Canal destruído durante espera\n";
                    $this->stopMoh($channelId);
                    return;
                }

                // ✅ tenta enviar check (pode ser bloqueado pelo debounce)
                $reqId = $this->notifyWorkerCheckRequest($channelId, $ramalSolicitado, $callId);

                if (!$reqId) {
                    // debounce bloqueou: NÃO existe resposta nova -> só espera pouco e tenta de novo
                    usleep(200_000); // 200ms
                    continue;
                }

                // ✅ só espera se enviou (req_id existe)
                $resp = $this->waitWorkerResponse($channelId, 2, $reqId);

                if ($resp && !empty($resp['ok'])) {

                    // ✅ RINGALL (Opção A) — vem sem ramal
                    if (($resp['mode'] ?? '') === 'ringall') {

                        $targets = $resp['targets'] ?? [];
                        $targets = is_array($targets) ? $targets : [];

                        echo "[TRANSFER_ONLY] ✅ Ringall autorizado (" . count($targets) . " alvos)\n";

                        // ringall vai controlar o resto -> garante que não fica MOH “preso”
                        $this->stopMoh($channelId);

                        if (!empty($resp['rr_mem_key'])) {
                            $this->channelData[$channelId]['vars']['RR_MEM_KEY'] = $resp['rr_mem_key'];
                        }
                        if (!empty($resp['call_id'])) {
                            $this->channelData[$channelId]['vars']['CALL_ID'] = $resp['call_id'];
                        }

                        $this->ringallToRamais($channelId, $targets);
                        return;
                    }

                    // ✅ modo normal (1 ramal)
                    $ramal = $resp['ramal'] ?? $ramalSolicitado;

                    echo "[TRANSFER_ONLY] ✅ Aprovado → {$ramal}\n";

                    try {
                        // ✅ MOH no canal do cliente enquanto o ramal está sendo chamado
                        usleep(200000);
                        $this->playMoh($channelId, 'default');

                        $this->transferToRamal($channelId, $ramal);

                    } catch (\Throwable $e) {
                        echo "[TRANSFER_ONLY] ❌ ERRO: {$e->getMessage()}\n";

                        // ✅ se falhou, para o MOH pra não deixar o cliente preso em música
                        $this->stopMoh($channelId);

                        $this->stopMoh($channelId, 'before_agents_busy');
                        $this->playWorkerAudio($channelId, "agents-busy");
                        $this->ChannelFinishDestroyed($channelId, "Erro ao transferir", true);
                        return;
                    }

                    return; // ✅ não seguir fluxo normal
                }

                // aqui: ou não veio resp, ou veio ok=false
                $tent++;
                $reason = $resp['reason'] ?? 'no_response';

                echo "[TRANSFER_ONLY] ❌ Indisponível ({$tent}/{$maxTent}) reason={$reason}\n";

                // antes de tocar áudio, garante que MOH não está ativo
                $this->stopMoh($channelId);

                // toca áudio em tentativas ímpares
                if ($tent % 2 === 1) {
                    $this->stopMoh($channelId, 'before_agents_busy');
                    $this->playWorkerAudio($channelId, "agents-busy");
                    $this->waitPlaybackFinish($channelId);
                }

                // ✅ espera “humana” antes de tentar de novo, mas sem flood
                $inicio = microtime(true);
                while (microtime(true) - $inicio < $timeoutTotal) {

                    if (!$this->channelExists($channelId)) {
                        echo "[TRANSFER_ONLY] ⚠ Canal destruído durante espera\n";
                        $this->stopMoh($channelId);
                        return;
                    }

                    // tenta enviar (respeita debounce)
                    $reqId = $this->notifyWorkerCheckRequest($channelId, $ramalSolicitado, $callId);
                    if ($reqId) {
                        $resp = $this->waitWorkerResponse($channelId, 1, $reqId);
                        if ($resp && !empty($resp['ok'])) {

                            // ringall
                            if (($resp['mode'] ?? '') === 'ringall') {
                                $targets = $resp['targets'] ?? [];
                                $targets = is_array($targets) ? $targets : [];
                                echo "[TRANSFER_ONLY] ✅ Ringall autorizado (" . count($targets) . " alvos)\n";
                                $this->stopMoh($channelId);
                                $this->ringallToRamais($channelId, $targets);
                                return;
                            }

                            $ramal = $resp['ramal'] ?? $ramalSolicitado;

                            echo "[TRANSFER_ONLY] ✅ Aprovado → {$ramal}\n";

                            try {
                                usleep(200000);
                                $this->playMoh($channelId, 'default');

                                $this->transferToRamal($channelId, $ramal);

                            } catch (\Throwable $e) {
                                echo "[TRANSFER_ONLY] ❌ ERRO: {$e->getMessage()}\n";
                                $this->stopMoh($channelId);
                                $this->playWorkerAudio($channelId, "agents-busy");
                                $this->ChannelFinishDestroyed($channelId, "Erro ao transferir", true);
                                return;
                            }

                            return;
                        }
                    }

                    usleep(250_000); // 250ms entre re-tentativas dentro da janela
                }

                // cai pro próximo tent
            }

            echo "[TRANSFER_ONLY] ❌ Nenhum agente livre\n";
            $this->stopMoh($channelId);
            $this->playWorkerAudio($channelId, "agents-busy");
            $this->ChannelFinishDestroyed($channelId, "Falha na transferência", true);
            return;
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
            "agents-error" => "sound:voice/standard-sounds/agents-error",
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
    private function onDtmf(array $event): void
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

                $callId = $ch['vars']['CALL_ID'] ?? null;
                $callId = $callId ? trim((string)$callId) : null;

                // ✅ Se não tiver ramal no item, fica AUTO (null)
                $ramalSolicitado = $item['ramal'] ?? null;

                $ramalSolicitado = $ramalSolicitado ? preg_replace('/\D/', '', (string)$ramalSolicitado) : null;
                if ($ramalSolicitado && !preg_match('/^\d{8}$/', $ramalSolicitado)) {
                    $ramalSolicitado = null;
                }

                // ✅ TTL guard
                $this->guardDtmfTransferTtl($channelId);

                // ✅ 0) trava reentrada (memória)
                if (!empty($this->channelData[$channelId]['transfer_in_progress'])) {
                    echo "[TRANSFER] ⛔ Ignorado: transferência já em andamento\n";
                    $this->markDtmfCooldown($channelId, $digit);
                    return;
                }

                // ✅ 1) trava reentrada (Redis) - evita race entre eventos/processos
                $lockKey = "voice:lock:dtmf_transfer:{$channelId}";
                $lockOk  = $this->redis->set($lockKey, '1', 'NX', 'EX', 30);
                if (!$lockOk) {
                    echo "[TRANSFER] ⛔ Ignorado: lock Redis já existe\n";
                    $this->markDtmfCooldown($channelId, $digit);
                    return;
                }

                // marca in-progress
                $this->channelData[$channelId]['transfer_in_progress'] = [
                    'ts'    => time(),
                    'digit' => $digit,
                ];

                echo "[TRANSFER] 🔍 Worker escolherá: " . ($ramalSolicitado ?: "AUTO")
                    . " call_id=" . ($callId ?: "null") . "\n";

                // ✅ Sempre usa req_id pra não consumir resposta errada
                $reqId = $this->notifyWorkerCheckRequest($channelId, $ramalSolicitado, $callId);

                // debounce segurou? dá um respiro e tenta de novo
                if (!$reqId) {
                    usleep(200_000);
                    $reqId = $this->notifyWorkerCheckRequest($channelId, $ramalSolicitado, $callId);
                }

                // ✅ se ainda não tem reqId, não existe resposta nova pra esperar -> libera e sai
                if (!$reqId) {
                    echo "[TRANSFER] ⚠ Debounce bloqueou envio (sem req_id)\n";
                    $this->unlockDtmfTransfer($channelId, 'debounce_blocked');
                    $this->markDtmfCooldown($channelId, $digit);
                    return;
                }



                $resp = $reqId ? $this->waitWorkerResponse($channelId, 3, $reqId) : null;

                if (!$resp) {
                    echo "[TRANSFER] ⚠ Worker não respondeu\n";
                    $this->unlockDtmfTransfer($channelId, 'worker_timeout_initial');
                    $this->stopMoh($channelId, 'before_agents_busy');
                    $this->playWorkerAudio($channelId, "agents-busy");
                    $this->markDtmfCooldown($channelId, $digit);
                    return;
                }

                if (($resp['reason'] ?? null) === 'channel_not_active') {
                    echo "[TRANSFER] 🛑 channel_not_active\n";
                    $this->unlockDtmfTransfer($channelId, 'channel_not_active_initial');
                    return;
                }

                // Retentativas
                $tent = 0;
                $maxTent = 9;

                while (empty($resp['ok']) && $tent < $maxTent) {

                    $tent++;
                    echo "[TRANSFER] ❌ Indisponível ({$tent}/{$maxTent}) reason=" . ($resp['reason'] ?? 'unknown') . "\n";

                    $this->stopMoh($channelId, 'before_agents_busy');
                    $this->playWorkerAudio($channelId, "agents-busy");
                    $this->waitPlaybackFinish($channelId);

                    $timeoutTotal = 10;
                    $intervalo    = 0.3;
                    $inicio       = microtime(true);

                    while (microtime(true) - $inicio < $timeoutTotal) {

                        if (!$this->channelExists($channelId)) {
                            echo "[TRANSFER] ⚠ Canal destruído durante espera\n";
                            $this->unlockDtmfTransfer($channelId, 'channel_destroyed_wait');
                            return;
                        }

                        $reqId = $this->notifyWorkerCheckRequest($channelId, $ramalSolicitado, $callId);
                        if (!$reqId) {
                            usleep((int)($intervalo * 1_000_000));
                            continue;
                        }

                        $resp = $this->waitWorkerResponse($channelId, 1, $reqId);

                        if ($resp && (($resp['reason'] ?? null) === 'channel_not_active')) {
                            echo "[TRANSFER] 🛑 channel_not_active\n";
                            $this->unlockDtmfTransfer($channelId, 'channel_not_active_wait');
                            return;
                        }

                        if ($resp && !empty($resp['ok'])) {
                            break;
                        }

                        usleep((int)($intervalo * 1_000_000));
                    }

                    if (!empty($resp['ok'])) break;

                    // tentativa "cheia"
                    $reqId = $this->notifyWorkerCheckRequest($channelId, $ramalSolicitado, $callId);
                    $resp  = $reqId ? $this->waitWorkerResponse($channelId, 3, $reqId) : null;

                    if (!$resp) {
                        echo "[TRANSFER] ⚠ Falha na tentativa {$tent}\n";
                        $this->unlockDtmfTransfer($channelId, 'worker_timeout_retry_full');
                        $this->stopMoh($channelId, 'before_agents_busy');
                        $this->playWorkerAudio($channelId, "agents-busy");
                        $this->markDtmfCooldown($channelId, $digit);
                        return;
                    }

                    if (($resp['reason'] ?? null) === 'channel_not_active') {
                        echo "[TRANSFER] 🛑 channel_not_active\n";
                        $this->unlockDtmfTransfer($channelId, 'channel_not_active_retry_full');
                        return;
                    }
                }

                if (empty($resp['ok'])) {
                    echo "[TRANSFER] ❌ Nenhum agente livre\n";
                    $this->unlockDtmfTransfer($channelId, 'no_free_agents');
                    $this->stopMoh($channelId, 'before_agents_busy');
                    $this->playWorkerAudio($channelId, "agents-busy");
                    $this->markDtmfCooldown($channelId, $digit);
                    $this->ChannelFinishDestroyed($channelId, "Falha na transferência", true);
                    return;
                }

                // ✅ Ringall (se strategy for ringall)
                if (($resp['mode'] ?? '') === 'ringall') {
                    $targets = $resp['targets'] ?? [];
                    $targets = is_array($targets) ? $targets : [];
                    echo "[TRANSFER] ✅ Ringall autorizado (" . count($targets) . " alvos)\n";

                    // ringall é "transfer iniciado" também, então libera o lock do DTMF
                    // (o ringall flow já controla o resto)
                    $this->unlockDtmfTransfer($channelId, 'ringall_started');
                    $this->stopMoh($channelId, 'ringall_start');
                    $this->ringallToRamais($channelId, $targets);
                    $this->markDtmfCooldown($channelId, $digit);
                    return;
                }

                $ramal = $resp['ramal'] ?? null;

                if (!$ramal) {
                    echo "[TRANSFER] ⚠ Worker respondeu ok mas sem ramal\n";
                    $this->unlockDtmfTransfer($channelId, 'ok_without_ramal');
                    $this->stopMoh($channelId, 'before_agents_busy');
                    $this->playWorkerAudio($channelId, "agents-busy");
                    $this->markDtmfCooldown($channelId, $digit);
                    return;
                }

                echo "[TRANSFER] ✅ Aprovado → {$ramal}\n";

                try {

                    // ✅ garante que não tem playback segurando o áudio
                    // (se você já chamou waitPlaybackFinish acima, pode manter só o usleep)
                    usleep(200000);

                    // ✅ MOH no canal do cliente enquanto o ramal está sendo chamado
                    $this->playMoh($channelId, 'default', 'dtmf_transfer_wait_agent_answer');

                    $this->transferToRamal($channelId, $ramal);

                } catch (\Throwable $e) {

                    echo "[TRANSFER] ❌ ERRO: {$e->getMessage()}\n";

                    // ✅ se falhou, para o MOH pra não deixar o cliente preso em música


                    $this->unlockDtmfTransfer($channelId, 'transfer_exception');
                    $this->stopMoh($channelId, 'transfer_exception');
                    $this->playWorkerAudio($channelId, "agents-busy");
                    $this->markDtmfCooldown($channelId, $digit);
                    return;
                }

                // ✅ aqui NÃO libera — o unlock deve acontecer na bridge_created (sucesso)
                // ou agent_channel_destroyed (falha).
                $this->markDtmfCooldown($channelId, $digit);
                return;
            }
        }
    }

    private function playMoh(string $channelId, string $class = 'default', string $why = ''): void
    {
        try {
            if (!$this->channelExists($channelId)) {
                echo "[MOH] ▶ skip start channel={$channelId} (no_channel) why={$why}\n";
                return;
            }

            // ✅ idempotência (memória)
            $st = $this->channelData[$channelId]['moh'] ?? null;
            $isActive = !empty($st['active']);
            $curClass = $st['class'] ?? null;

            // se já está ativo e mesma classe, não faz nada
            if ($isActive && $curClass === $class) {
                echo "[MOH] ▶ skip start channel={$channelId} class={$class} (already_active) why={$why}\n";
                return;
            }

            // se está ativo mas classe diferente, para antes (evita “duas MOH” / estado sujo)
            if ($isActive && $curClass !== $class) {
                $this->stopMoh($channelId, "class_change:{$curClass}->{$class}");
            }

            $res = $this->http->post("http://{$this->ariHost}:8088/ari/channels/{$channelId}/moh", [
                'query' => ['mohClass' => $class],
                'http_errors' => false,
            ]);

            $http = $res->getStatusCode();

            // ✅ considera 2xx como ok; 409/404 etc apenas loga
            if ($http >= 200 && $http < 300) {
                $this->channelData[$channelId]['moh'] = [
                    'active' => true,
                    'class'  => $class,
                    'ts'     => time(),
                    'why'    => $why,
                ];
            }

            echo "[MOH] ▶ start channel={$channelId} class={$class} http={$http} why={$why}\n";

        } catch (\Throwable $e) {
            echo "[MOH] ⚠ start fail channel={$channelId} class={$class} why={$why} err={$e->getMessage()}\n";
        }
    }

    private function stopMoh(string $channelId, string $why = ''): void
    {
        try {
            if (!$this->channelExists($channelId)) {
                // mesmo sem canal, limpa memória (idempotente)
                unset($this->channelData[$channelId]['moh']);
                echo "[MOH] ⏹ skip stop channel={$channelId} (no_channel) why={$why}\n";
                return;
            }

            // ✅ idempotência (memória)
            $st = $this->channelData[$channelId]['moh'] ?? null;
            if (empty($st['active'])) {
                echo "[MOH] ⏹ skip stop channel={$channelId} (not_active) why={$why}\n";
                return;
            }

            $res = $this->http->delete("http://{$this->ariHost}:8088/ari/channels/{$channelId}/moh", [
                'http_errors' => false,
            ]);

            $http = $res->getStatusCode();

            // limpa estado local sempre (idempotente)
            unset($this->channelData[$channelId]['moh']);

            echo "[MOH] ⏹ stop channel={$channelId} http={$http} why={$why}\n";

        } catch (\Throwable $e) {
            // mesmo se falhar, limpa estado local pra não “prender”
            unset($this->channelData[$channelId]['moh']);
            echo "[MOH] ⚠ stop fail channel={$channelId} why={$why} err={$e->getMessage()}\n";
        }
    }

    private function playMohBridge(string $bridgeId, string $class = 'default', string $why = ''): void
    {
        try {
            // ✅ idempotência (memória)
            $st = $this->bridgeData[$bridgeId]['moh'] ?? null;
            $isActive = !empty($st['active']);
            $curClass = $st['class'] ?? null;

            if ($isActive && $curClass === $class) {
                echo "[MOH] ▶ skip start bridge={$bridgeId} class={$class} (already_active) why={$why}\n";
                return;
            }

            if ($isActive && $curClass !== $class) {
                $this->stopMohBridge($bridgeId, "class_change:{$curClass}->{$class}");
            }

            $res = $this->http->post("http://{$this->ariHost}:8088/ari/bridges/{$bridgeId}/moh", [
                'query' => ['mohClass' => $class],
                'http_errors' => false,
            ]);

            $http = $res->getStatusCode();

            if ($http >= 200 && $http < 300) {
                $this->bridgeData[$bridgeId]['moh'] = [
                    'active' => true,
                    'class'  => $class,
                    'ts'     => time(),
                    'why'    => $why,
                ];
            }

            echo "[MOH] ▶ start bridge={$bridgeId} class={$class} http={$http} why={$why}\n";

        } catch (\Throwable $e) {
            echo "[MOH] ⚠ start fail bridge={$bridgeId} class={$class} why={$why} err={$e->getMessage()}\n";
        }
    }

    private function stopMohBridge(string $bridgeId, string $why = ''): void
    {
        try {
            $st = $this->bridgeData[$bridgeId]['moh'] ?? null;
            if (empty($st['active'])) {
                echo "[MOH] ⏹ skip stop bridge={$bridgeId} (not_active) why={$why}\n";
                return;
            }

            $res = $this->http->delete("http://{$this->ariHost}:8088/ari/bridges/{$bridgeId}/moh", [
                'http_errors' => false,
            ]);

            $http = $res->getStatusCode();

            unset($this->bridgeData[$bridgeId]['moh']);

            echo "[MOH] ⏹ stop bridge={$bridgeId} http={$http} why={$why}\n";

        } catch (\Throwable $e) {
            unset($this->bridgeData[$bridgeId]['moh']);
            echo "[MOH] ⚠ stop fail bridge={$bridgeId} why={$why} err={$e->getMessage()}\n";
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

    private function channelExists(string $channelId): bool
    {
        return isset($this->channelData[$channelId]);
    }

    private int $transferLockTtlSeconds = 25; // ajuste: <= EX do Redis (30) e > tempo normal de bridge

    private function guardDtmfTransferTtl(string $originChannelId): void
    {
        $originChannelId = trim((string)$originChannelId);
        if ($originChannelId === '') return;

        $info = $this->channelData[$originChannelId]['transfer_in_progress'] ?? null;
        if (empty($info) || !is_array($info)) return;

        $ts = (int)($info['ts'] ?? 0);
        if ($ts <= 0) return;

        $age = time() - $ts;
        if ($age <= $this->transferLockTtlSeconds) return;

        // ✅ TTL estourou: libera geral (idempotente)
        echo "[TRANSFER] ⏳ TTL expirado origin={$originChannelId} age={$age}s ttl={$this->transferLockTtlSeconds}s\n";
        $this->unlockDtmfTransfer($originChannelId, 'ttl_expired');
    }


    private function notifyWorkerCheckRequest(string $channelId, ?string $ramalSolicitado = null, ?string $callId = null): ?string
    {
        try {
            $pendingKey = "voice:check_pending:{$channelId}"; // guarda req_id em voo
            $guardKey   = "voice:check_sent:{$channelId}";    // debounce curto

            // 1) se já tem req_id pendente (em voo), reutiliza
            $pending = $this->redis->get($pendingKey);
            if ($pending) {
                return (string)$pending;
            }

            // 2) debounce pra não criar pendente toda hora
            $resp = $this->redis->executeRaw(['SET', $guardKey, '1', 'PX', '800', 'NX']);
            if ($resp !== 'OK') {
                return null;
            }

            // 3) tenta ramal do contexto (se não veio)
            if (!$ramalSolicitado) {
                $ctxRaw = $this->redis->get("voice:channel_context:{$channelId}");
                $ctx    = $ctxRaw ? json_decode($ctxRaw, true) : [];
                $ramalSolicitado = $ctx['reserved_ramal'] ?? $ctx['ramal'] ?? null;
            }

            $ramalSolicitado = $ramalSolicitado ? preg_replace('/\D/', '', (string)$ramalSolicitado) : null;
            if ($ramalSolicitado && !preg_match('/^\d{8}$/', $ramalSolicitado)) {
                $ramalSolicitado = null;
            }

            $reqId = bin2hex(random_bytes(8));

            // 4) marca como pendente por poucos segundos (tempo do wait)
            // (use SETEX pq é redis comum / predis)
            $this->redis->setex($pendingKey, 5, $reqId);

            $payload = [
                'type'            => 'check_transfer',
                'channel'         => $channelId,
                'requested_ramal' => $ramalSolicitado,
                'call_id'         => $callId,
                'req_id'          => $reqId,
                'ts'              => time(),
            ];

            $this->redis->rpush('voice:transfer_request', json_encode($payload, JSON_UNESCAPED_UNICODE));

            error_log("[WORKER-NOTIFY] check_transfer channel={$channelId} requested=" . ($ramalSolicitado ?: "AUTO") .
                " call_id=" . ($callId ?: "null") . " req_id={$reqId}");

            return $reqId;

        } catch (\Throwable $e) {
            error_log("[WORKER-NOTIFY ⚠️] Falha ao notificar: " . $e->getMessage());
            return null;
        }
    }



    private function waitWorkerResponse(string $channelId, int $timeoutSec = 3, ?string $expectedReqId = null): ?array
    {
        $key        = "voice:worker_response:{$channelId}";
        $pendingKey = "voice:check_pending:{$channelId}";
        $limit      = microtime(true) + $timeoutSec;

        while (microtime(true) < $limit) {
            try {
                $raw = $this->redis->lpop($key);

                if (!$raw) {
                    usleep(120_000); // 120ms
                    continue;
                }

                $decoded = json_decode($raw, true);

                if (!is_array($decoded)) {
                    echo "[WORKER] ⚠️ Resposta inválida: {$raw}\n";
                    continue;
                }

                // Segurança: confirma canal
                if (($decoded['channel'] ?? null) !== $channelId) {
                    echo "[WORKER] ⚠️ Resposta ignorada — canal incorreto\n";
                    continue;
                }

                // Se worker avisou que o canal não existe/ativo, aborta imediatamente
                if (($decoded['reason'] ?? null) === 'channel_not_active') {
                    // libera pendência e retorna (o caller deve abortar e NÃO tocar áudio)
                    $this->redis->del($pendingKey);

                    echo "[WORKER] 🛑 channel_not_active para {$channelId}\n";
                    return $decoded;
                }

                // Resposta muito antiga (se tiver ts)
                if (isset($decoded['ts']) && (int)$decoded['ts'] < time() - 10) {
                    echo "[WORKER] ⚠️ Resposta antiga ignorada\n";
                    continue;
                }

                // Se não estou esperando req_id específico -> primeira válida
                if ($expectedReqId === null) {
                    $this->redis->del($pendingKey);

                    echo "[WORKER] 🔄 Resposta recebida {$channelId}: "
                        . json_encode($decoded, JSON_UNESCAPED_UNICODE) . "\n";
                    return $decoded;
                }

                // Estou esperando req_id específico
                $respReqId = $decoded['req_id'] ?? null;

                // Sem req_id => descarta (não dá pra confiar / não requeue)
                if (!$respReqId) {
                    echo "[WORKER] ⚠️ Resposta sem req_id ignorada (esperado={$expectedReqId})\n";
                    continue;
                }

                // req_id diferente => descarta (não requeue, evita churn/flood)
                if ((string)$respReqId !== (string)$expectedReqId) {
                    echo "[WORKER] ⚠️ Resposta descartada — req_id diferente (esperado={$expectedReqId}, veio={$respReqId})\n";
                    continue;
                }

                // Match perfeito
                $this->redis->del($pendingKey);

                echo "[WORKER] 🔄 Resposta recebida {$channelId}: "
                    . json_encode($decoded, JSON_UNESCAPED_UNICODE) . "\n";

                return $decoded;

            } catch (\Throwable $e) {
                echo "[WORKER] ⚠️ Erro ao ler resposta: {$e->getMessage()}\n";
            }
        }

        echo "[WORKER] ⏳ Timeout esperando worker para {$channelId}\n";
        // libera pendência pra permitir nova tentativa
        $this->redis->del("voice:check_pending:{$channelId}");
        return null;
    }

    private function unlockDtmfTransfer(string $originChannelId, string $why = ''): void
    {
        $originChannelId = trim((string)$originChannelId);
        if ($originChannelId === '') return;

        $lockKey = "voice:lock:dtmf_transfer:{$originChannelId}";

        // snapshot pra log (idempotente)
        $hadMem  = !empty($this->channelData[$originChannelId]['transfer_in_progress']);
        $why     = $why ? trim($why) : 'manual';

        try {
            // ✅ memória local (idempotente)
            if (isset($this->channelData[$originChannelId]['transfer_in_progress'])) {
                unset($this->channelData[$originChannelId]['transfer_in_progress']);
            }

            // ✅ redis lock (idempotente)
            $deleted = 0;
            try {
                $deleted = (int) $this->redis->del($lockKey); // 0 se não existia
            } catch (\Throwable $e) {
                // não derruba fluxo
                $deleted = -1; // sinaliza falha no log
            }

            // ✅ log rico
            echo "[TRANSFER] 🔓 Unlock origin={$originChannelId} why={$why}"
                . " mem=" . ($hadMem ? '1' : '0')
                . " redis_del=" . $deleted
                . "\n";

        } catch (\Throwable $e) {
            // idempotente e silencioso
            echo "[TRANSFER] ⚠ Unlock falhou origin={$originChannelId} why={$why} err={$e->getMessage()}\n";
        }
    }



    private function transferToRamal(string $channelId, string $ramal): void
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

            // ✅ origem do transfer (pra liberar lock depois)
            $this->channelData[$newChannelId]['transfer_origin'] = $channelId;

            // ✅ trava memória no canal origem (pra não aceitar outro DTMF transfer)
            $this->channelData[$channelId]['transfer_in_progress'] = [
                'ts'          => time(),
                'to_agent'    => $ramal,
                'new_channel' => $newChannelId,
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
            $this->playWorkerAudio($channelId, "agents-error");
        }
    }

    private function ringallToRamais(string $inboundChannelId, array $targets): void
    {
        echo "[RINGALL] 🔔 Iniciando ringall: {$inboundChannelId} → " . implode(',', $targets) . "\n";

        // 0) limita e normaliza alvos
        $targets = array_values(array_unique(array_filter(array_map(function ($r) {
            $r = preg_replace('/\D/', '', (string)$r);
            return preg_match('/^\d{8}$/', $r) ? $r : null;
        }, $targets))));
        $targets = array_slice($targets, 0, 10);

        if (!$targets) {
            echo "[RINGALL] ⚠ Nenhum alvo válido\n";
            $this->playWorkerAudio($inboundChannelId, "agents-busy");
            return;
        }

        // inbound precisa existir
        if (empty($this->channelData[$inboundChannelId])) {
            echo "[RINGALL] ⚠ Inbound não encontrado em channelData: {$inboundChannelId}\n";
            return;
        }

        // evita duplicar ringall no mesmo inbound
        if (!empty($this->channelData[$inboundChannelId]['ringall']['bridge'])) {
            echo "[RINGALL] ⚠ Já existe ringall em andamento no inbound {$inboundChannelId}\n";
            return;
        }

        try {
            // 1) CallerID real e destino (igual transfer)
            $callerId = $this->channelData[$inboundChannelId]['vars']['CALLERID(num)']
                ?? $this->channelData[$inboundChannelId]['extension']
                ?? '0000';

            $destinationOrigin =
                $this->channelData[$inboundChannelId]['vars']['EXTENSION']
                ?? $this->channelData[$inboundChannelId]['destination']
                ?? $this->channelData[$inboundChannelId]['number']
                ?? $callerId;

            $destinationOrigin = preg_replace('/\D+/', '', (string)$destinationOrigin);

            // 2) cria bridge mixing (mesmo padrão do transfer)
            $bridgeId = 'b_' . substr(md5($inboundChannelId . microtime(true)), 0, 10);

            $resp = $this->http->post("http://{$this->ariHost}:8088/ari/bridges/{$bridgeId}", [
                'query'       => ['type' => 'mixing'],
                'http_errors' => false,
            ]);
            if ($resp->getStatusCode() >= 400) {
                throw new \Exception("Falha ao criar bridge (HTTP {$resp->getStatusCode()})");
            }

            // 3) add inbound no bridge
            $resp = $this->http->post("http://{$this->ariHost}:8088/ari/bridges/{$bridgeId}/addChannel", [
                'query'       => ['channel' => $inboundChannelId],
                'http_errors' => false,
            ]);
            if ($resp->getStatusCode() >= 400) {
                $this->http->delete("http://{$this->ariHost}:8088/ari/bridges/{$bridgeId}", ['http_errors' => false]);
                throw new \Exception("Falha ao add inbound no bridge (HTTP {$resp->getStatusCode()})");
            }

            // 4) marca estado do inbound como “ringall em progresso”
            $this->channelData[$inboundChannelId]['ringall'] = [
                'bridge'   => $bridgeId,
                'targets'  => $targets,
                'outbound' => [],        // ramal => channelId
                'winner'   => null,      // ['ramal'=>..,'channel'=>..,'ts'=>..]
                'started'  => time(),
                'deadline' => time() + 20,
            ];

            // ✅ impede handleChannelStateChange de criar outra bridge
            $this->channelData[$inboundChannelId]['bridge'] = $bridgeId;

            // 5) origina outbound pra todos
            foreach ($targets as $ramal) {

                // não tenta originar se inbound morreu
                if (!$this->channelExists($inboundChannelId)) {

                    // inbound morreu -> derruba tudo que já criou
                    foreach (($this->channelData[$inboundChannelId]['ringall']['outbound'] ?? []) as $r => $ch) {
                        if ($ch) {
                            try {
                                $this->http->delete("http://{$this->ariHost}:8088/ari/channels/{$ch}", ['http_errors' => false]);
                            } catch (\Throwable) {}

                            // marca como morto e remove do painel imediatamente
                            try { $this->markDead($ch, 20); } catch (\Throwable) {}
                            unset($this->pendingVars[$ch]);
                            unset($this->channelData[$ch]);
                        }
                    }

                    try {
                        $this->http->delete("http://{$this->ariHost}:8088/ari/bridges/{$bridgeId}", ['http_errors' => false]);
                    } catch (\Throwable) {}

                    unset($this->channelData[$inboundChannelId]['ringall']);
                    unset($this->channelData[$inboundChannelId]['bridge']);

                    $this->updateRedis();
                    return;
                }

                $response = $this->http->post("http://{$this->ariHost}:8088/ari/channels", [
                    'json' => [
                        'endpoint' => "PJSIP/{$ramal}",
                        'app'      => $this->stasisApp,
                        'appArgs'  => "ringall,{$inboundChannelId},{$bridgeId}",
                        'callerId' => $destinationOrigin,
                        'timeout'  => 20,
                    ],
                    'http_errors' => false
                ]);

                $status = $response->getStatusCode();
                if ($status >= 400) {
                    echo "[RINGALL] ❌ Falha originate ramal={$ramal} HTTP {$status}\n";
                    continue;
                }

                $new = json_decode((string)$response->getBody(), true);
                $newChannelId = $new['id'] ?? null;

                if (!$newChannelId) {
                    echo "[RINGALL] ❌ Ramal {$ramal} sem channelId\n";
                    continue;
                }

                echo "[RINGALL] 📞 Outbound criado: {$newChannelId} (ramal {$ramal})\n";

                // cria channelData igual o transfer
                $this->channelData[$newChannelId] = [
                    'id'            => $newChannelId,
                    'peer'          => $inboundChannelId,
                    'transfer_from' => $inboundChannelId,

                    'type'     => 'AGENT',
                    'agent_id' => $ramal,

                    'extension'   => $ramal,
                    'number'      => $ramal,
                    'destination' => $ramal,

                    'state'    => 'dialing',
                    'started'  => time(),
                    'duration' => '00:00',

                    'vars' => array_merge(
                        ($this->channelData[$inboundChannelId]['vars'] ?? []),
                        [
                            'DEST_NUMBER'     => $destinationOrigin,
                            'AGENT_ID'        => $ramal,
                            'RINGALL'         => '1',
                            'RINGALL_INBOUND' => $inboundChannelId,
                            'RINGALL_BRIDGE'  => $bridgeId,
                            'RINGALL_RAMAL'   => $ramal,
                        ]
                    ),

                    'ringall_outbound' => true,
                ];

                // registra no estado do inbound
                $this->channelData[$inboundChannelId]['ringall']['outbound'][$ramal] = $newChannelId;
            }

            // se nenhum outbound criou, limpa bridge e ringall
            if (empty($this->channelData[$inboundChannelId]['ringall']['outbound'])) {
                echo "[RINGALL] ⚠ Nenhum outbound criado\n";

                $this->http->delete("http://{$this->ariHost}:8088/ari/bridges/{$bridgeId}", ['http_errors' => false]);

                unset($this->channelData[$inboundChannelId]['ringall']);
                unset($this->channelData[$inboundChannelId]['bridge']);

                $this->playWorkerAudio($inboundChannelId, "agents-busy");
                return;
            }

            $this->updateRedis();
            echo "[RINGALL] ✅ Ringall iniciado. Aguardando ANSWER para eleger winner.\n";

        } catch (\Throwable $e) {
            echo "[RINGALL] ❌ Erro: {$e->getMessage()}\n";

            // best effort cleanup do que pode ter ficado
            try {
                if (!empty($this->channelData[$inboundChannelId]['ringall']['outbound'])) {
                    foreach (($this->channelData[$inboundChannelId]['ringall']['outbound'] ?? []) as $ch) {
                        if ($ch) {
                            try { $this->http->delete("http://{$this->ariHost}:8088/ari/channels/{$ch}", ['http_errors' => false]); } catch (\Throwable) {}
                            try { $this->markDead($ch, 20); } catch (\Throwable) {}
                            unset($this->pendingVars[$ch]);
                            unset($this->channelData[$ch]);
                        }
                    }
                }
                if (!empty($this->channelData[$inboundChannelId]['ringall']['bridge'])) {
                    $b = $this->channelData[$inboundChannelId]['ringall']['bridge'];
                    $this->http->delete("http://{$this->ariHost}:8088/ari/bridges/{$b}", ['http_errors' => false]);
                }
            } catch (\Throwable) {}

            unset($this->channelData[$inboundChannelId]['ringall']);
            unset($this->channelData[$inboundChannelId]['bridge']);

            $this->updateRedis();
            $this->playWorkerAudio($inboundChannelId, "agents-error");
        }
    }




    private function ChannelFinishDestroyed( string $channelId, string $motivo = 'Encerrada', bool $forcarTarifa = false): void
    {
        if (empty($this->channelData[$channelId])) {
            return;
        }

        $this->channelData[$channelId]['status']    = "🔚 {$motivo}";
        $this->channelData[$channelId]['state']     = 'down';
        $this->channelData[$channelId]['ended']     = time();
        $this->channelData[$channelId]['cause_txt'] = $motivo;
        $this->channelData[$channelId]['cause']     = 487;

        $this->updateRedis();

        try {

            // -------------------------------------------------
            // 🔹 Cancela SOMENTE o playback do canal (se existir)
            // -------------------------------------------------
            if (!empty($this->channelData[$channelId]['current_playback']['id'])) {

                $playbackId = $this->channelData[$channelId]['current_playback']['id'];

                $this->http->delete(
                    "http://{$this->ariHost}:8088/ari/playbacks/{$playbackId}",
                    ['http_errors' => false]
                );

                unset($this->channelData[$channelId]['current_playback']);
            }

            usleep(100000);

            // -------------------------------------------------
            // 🔹 Encerra o canal
            // -------------------------------------------------
            $this->http->delete(
                "http://{$this->ariHost}:8088/ari/channels/{$channelId}",
                ['http_errors' => false]
            );

            error_log("[HANGUP] Canal {$channelId} encerrado ({$motivo})");

            // -------------------------------------------------
            // 🔹 Garante CDR / tarifação
            // -------------------------------------------------
            if ($forcarTarifa && empty($this->channelData[$channelId]['tariff_done'])) {

                $this->calculateTariff($channelId);
                $this->channelData[$channelId]['tariff_done'] = true;

                error_log("[CDR] 💾 CDR gravado após encerramento canal={$channelId}");
            }

        } catch (\Throwable $e) {
            error_log("[HANGUP] ⚠️ Erro ao encerrar canal {$channelId}: {$e->getMessage()}");
        }

        // -------------------------------------------------
        // 🔹 Marca CDR salvo (proteção anti-duplicidade)
        // -------------------------------------------------
        $this->redis->setex("cdr-saved:{$channelId}", 60, 1);

        unset($this->channelData[$channelId]);
        $this->updateRedis();
    }

    private function onPlaybackFinished(array $event): void
    {
        $channelId = str_replace(
            'channel:',
            '',
            $event['playback']['target_uri'] ?? ''
        );

        if (!$channelId || empty($this->channelData[$channelId])) {
            return;
        }

        $data = &$this->channelData[$channelId]; // ← referência (mais eficiente)
        $action = $data['action'] ?? null;

        echo "[🎵] Playback finalizado no canal {$channelId}\n";
        echo "[ACTION] " . ($data['play_only'] ?? $action ?? 'normal') . "\n";

        // limpeza padrão
        unset($data['current_playback'], $data['handled_dtmf']);

        /*
         * =====================================================
         * PLAY ONLY  → encerra canal após áudio
         * =====================================================
         */
        if (($data['play_only'] ?? null) === 'play_only') {

            unset($data['play_only']); // evita dupla execução

            echo "[PLAY_ONLY] 🎧 Áudio finalizado → encerrando canal {$channelId}\n";

            $this->ChannelFinishDestroyed(
                $channelId,
                'Encerrada após áudio (play_only)',
                true
            );

            return;
        }

        /*
         * =====================================================
         * AÇÕES QUE NÃO DEVEM CONTINUAR FLUXO
         * =====================================================
         */
        if (in_array($action, ['dtmf', 'transfer_only'], true)) {
            return;
        }

        /*
         * =====================================================
         * FLUXO NORMAL
         * =====================================================
         */
    }


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
                $this->channelData[$chanId]['status'] = '📡 Progress...';
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

            // TYPE pode vir do canal OU ter chegado antes
            $payload = [
                'dialstatus'  => strtoupper($status),
                'msg'         => $msg,
                'started'     => $chan['started'] ?? time(),
                'ended'       => time(),
                'number'      => $chan['number'] ?? ($vars['CALLERID(num)'] ?? null),
                'destination' => $chan['destination'] ?? ($chan['dialstring'] ?? null),
                'job_id' => $vars['JOB_ID'] ?? ($chan['job_id'] ?? null) ?? ($this->pendingVars[$chanId]['JOB_ID'] ?? null),
                'campaign_id' => $vars['CAMPAIGN_ID']
                    ?? ($chan['campaign_id'] ?? null)
                        ?? ($this->pendingVars[$chanId]['CAMPAIGN_ID'] ?? null),

                'campaign_type' => $vars['CAMPAIGN_TYPE']
                    ?? ($chan['campaign_type'] ?? null)
                        ?? ($this->pendingVars[$chanId]['CAMPAIGN_TYPE'] ?? null),

                'variable_type' => $vars['VARIABLE_TYPE'] ?? $vars['TYPE'] ?? null,

                'call_minute_cost' => $vars['CALL_MINUTE_COST'] ?? null,
                'sms_cost'         => $vars['SMS_COST'] ?? null,
                'torpedo_cost'     => $vars['TORPEDO_COST'] ?? null,
                'taxa_of_service'  => 0,
                'owner_id'  => $vars['OWNER_ID'] ?? null,
                'tenant_id' => $vars['TENANT_ID'] ?? null,

                'application' => 'app-asterisk',
            ];

            // 🔥 injeta TYPE que chegou antes
            if (!empty($this->pendingVars[$chanId]['VARIABLE_TYPE'])) {
                $payload['variable_type'] = $this->pendingVars[$chanId]['VARIABLE_TYPE'];
            } elseif (!empty($this->pendingVars[$chanId]['TYPE'])) {
                $payload['variable_type'] = $this->pendingVars[$chanId]['TYPE'];
            }
            if (!empty($this->pendingVars[$chanId]['CAMPAIGN_ID'])) {
                $payload['campaign_id'] = $this->pendingVars[$chanId]['CAMPAIGN_ID'];
            }

            if (!empty($this->pendingVars[$chanId]['CAMPAIGN_TYPE'])) {
                $payload['campaign_type'] = $this->pendingVars[$chanId]['CAMPAIGN_TYPE'];
            }


            // agora SIM salva
            $this->redis->setex($failKey, 180, json_encode($payload, JSON_UNESCAPED_UNICODE));
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
            ?? ($vars['VARIABLE_TYPE']
            ?? ($this->pendingVars[$channelId]['VARIABLE_TYPE'] ?? null)
            ?? ($vars['TYPE']
                ?? ($this->pendingVars[$channelId]['TYPE'] ?? 'normal')
            )
        )
        );

        $jobId = $failEvent['job_id']
            ?? ($vars['JOB_ID'] ?? null)
            ?? ($this->pendingVars[$channelId]['JOB_ID'] ?? null);

        $campaignId = $failEvent['campaign_id']
            ?? ($vars['CAMPAIGN_ID'] ?? null)
            ?? ($this->pendingVars[$channelId]['CAMPAIGN_ID'] ?? null);

        $campaignType = $failEvent['campaign_type']
            ?? ($vars['CAMPAIGN_TYPE'] ?? null)
            ?? ($this->pendingVars[$channelId]['CAMPAIGN_TYPE'] ?? null);



        $minuteCost  = (float)($failEvent['call_minute_cost']      ?? ($vars['CALL_MINUTE_COST'] ?? 0));
        $smsCost     = (float)($failEvent['sms_cost']              ?? ($vars['SMS_COST']         ?? 0));
        $torpedoCost = (float)($failEvent['torpedo_cost']          ?? ($vars['TORPEDO_COST']     ?? 0));
        $taxaOfService = 0.0;

        $number = $call['number']
            ?? ($call['extension'] ?? ($vars['CALLERID(num)'] ?? 'Desconhecido'));

        $destination = $call['destination'] ?? ($call['dialstring'] ?? '—');

        $causeCode = $call['cause']     ?? null;
        $causeText = $call['cause_txt'] ?? null;

        $started = $call['started'] ?? time();
        $ended   = $call['ended']   ?? time();

        $cdr = [
            'channel_id'       => $channelId,
            'job_id'           => $jobId,
            'campaign_id'      => $campaignId,
            'campaign_type'    => $campaignType,
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
            'taxa_of_service'   => $taxaOfService,
            'call_minute_cost'  => $minuteCost,
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
        if (!$call) return;

        // memória
        if (!empty($call['tariff_done'])) {
            error_log("[CDR] ⏭ Já tarifado (memória) canal={$channelId}");
            return;
        }

        if (empty($call['ended'])) {
            error_log("[CDR] ❌ Ignorado — chamada não finalizada canal={$channelId}");
            return;
        }

        $vars = $call['vars'] ?? [];

        // ✅ MANUAL: só B-leg tarifa
        $isManual = (
            !empty($vars['MANUAL_CALL']) ||
            !empty($vars['IS_MANUAL']) ||
            !empty($vars['__IS_MANUAL']) ||
            !empty($vars['__MANUAL_CALL'])
        );

        $leg = $vars['ARI_LEG'] ?? $vars['__ARI_LEG'] ?? null;
        if ($isManual && $leg === 'A') {
            error_log("[CDR] ⏭ MANUAL A-leg ignorado no calculateTariff canal={$channelId}");
            return;
        }

        // ✅ dedupe forte: por CALL_ID quando existir (principalmente manual)
        $callId = $vars['CALL_ID'] ?? null;
        $callId = $callId ? trim((string)$callId) : null;

        $dupKeyChan = "tarifacao:ja_registrada:chan:{$channelId}";
        $dupKeyCall = $callId ? "tarifacao:ja_registrada:call:{$callId}" : null;

        if ($this->redis->exists($dupKeyChan) || ($dupKeyCall && $this->redis->exists($dupKeyCall))) {
            error_log("[CDR] ⏭ Já tarifado (Redis) canal={$channelId}" . ($callId ? " call_id={$callId}" : ""));
            return;
        }

        // tempos
        $started  = $call['started']  ?? time();
        $answered = $call['answered'] ?? null;
        $ended    = $call['ended']    ?? time();

        // ✅ só tarifa atendidas
        if (empty($answered)) {
            error_log("[CDR] ⏭ calculateTariff ignorado para canal sem ANSWER (canal={$channelId})");
            return;
        }

        // ----- resto do seu código (type/owner/tenant/custo/cause etc) -----

        $type = strtolower($vars['VARIABLE_TYPE'] ?? $vars['TYPE'] ?? $call['type'] ?? 'normal');

        $ownerId  = $vars['OWNER_ID']  ?? $call['owner_id']  ?? null;
        $tenantId = $vars['TENANT_ID'] ?? $call['tenant_id'] ?? null;
        $role     = $vars['ROLE']      ?? $call['role']      ?? null;

        $jobId = $vars['JOB_ID'] ?? null;

        $campaignId = $vars['CAMPAIGN_ID']
            ?? ($call['campaign_id'] ?? null)
            ?? ($this->pendingVars[$channelId]['CAMPAIGN_ID'] ?? null);

        $campaignType = $vars['CAMPAIGN_TYPE']
            ?? ($call['campaign_type'] ?? null)
            ?? ($this->pendingVars[$channelId]['CAMPAIGN_TYPE'] ?? null);

        if (empty($ownerId) || empty($tenantId)) {
            error_log("[CDR] ⏳ Aguardando OWNER_ID/TENANT_ID antes de tarifar canal={$channelId}");
            $this->channelData[$channelId]['wait_vars_for_tariff'] = true;
            return;
        }

        // custos dinâmicos
        $minuteCost  = isset($vars['CALL_MINUTE_COST']) ? (float)$vars['CALL_MINUTE_COST'] : 0.0;
        $smsCost     = isset($vars['SMS_COST'])         ? (float)$vars['SMS_COST']         : 0.0;
        $torpedoCost = isset($vars['TORPEDO_COST'])     ? (float)$vars['TORPEDO_COST']     : 0.0;
        $taxaOfService = isset($vars['TAXA_OF_SERVICE'])     ? (float)$vars['TAXA_OF_SERVICE']     : 0.0;

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
        $isRamalNumerico = preg_match('/^pjsip\/([0-9]{6,8})-/i', $chName, $match);
        $isRamal = $isRamalNumerico && !$isManual;

        if ($isRamal) {
            $taxaOfService = 0.0;
        }

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
            'job_id'           => $jobId,
            'call_id'          => $callId,
            'campaign_id'      => $campaignId,
            'campaign_type'    => $campaignType,
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
            'taxa_of_service'  => number_format($taxaOfService, 4, '.', ''),
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

        // ✅ grava as 2 chaves (chan + call) — call_id é a mais importante
        $this->redis->setex($dupKeyChan, 180, 1);
        if ($dupKeyCall) $this->redis->setex($dupKeyCall, 180, 1);

        $this->channelData[$channelId]['tariff_done'] = true;

        error_log("[CDR] 💰 Tarifação OK canal={$channelId} tipo={$type} dur={$durationSec}s = R$ {$cdr['value']}");

    }




    /*private function calculateTariff(string $channelId): void
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

        $jobId = $vars['JOB_ID'] ?? null;
        $callId = $vars['CALL_ID'] ?? null;

        $campaignId = $vars['CAMPAIGN_ID']
            ?? ($call['campaign_id'] ?? null)
            ?? ($this->pendingVars[$channelId]['CAMPAIGN_ID'] ?? null);

        $campaignType = $vars['CAMPAIGN_TYPE']
            ?? ($call['campaign_type'] ?? null)
            ?? ($this->pendingVars[$channelId]['CAMPAIGN_TYPE'] ?? null);



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
        $taxaOfService = isset($vars['TAXA_OF_SERVICE'])     ? (float)$vars['TAXA_OF_SERVICE']     : 0.0;



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

        if ($isRamal) {
            $taxaOfService = 0.0;
        }

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
            'job_id'           => $jobId,
            'call_id'          => $callId,
            'campaign_id'      => $campaignId,
            'campaign_type'    => $campaignType,
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
            'taxa_of_service'  => number_format($taxaOfService, 4, '.', ''),
            'call_minute_cost' => $minuteCost,
            'sms_cost'         => $smsCost,
            'started'          => $started,
            'answered'         => $answered,
            'ended'            => $ended,
            'timestamp'        => date('Y-m-d H:i:s'),
        ];

        //$cdr['campaign_id']   = $campaignId;
        //$cdr['campaign_type'] = 'voice';
        //$cdr['job_id']        = $jobId;

        // ===================================================
        // 🔥 Salva no Redis
        // ===================================================
        $this->redis->rpush('asterisk:tarifacoes', json_encode($cdr, JSON_UNESCAPED_UNICODE));
        $this->redis->setex($dupKey, 180, 1);

        $this->channelData[$channelId]['tariff_done'] = true;

        error_log("[CDR] 💰 Tarifação OK canal={$channelId} tipo={$type} dur={$durationSec}s = R$ {$cdr['value']}");
    }*/




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


    /*private function updateRedis(): void
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
                $taxaOfService = (float)($vars['TAXA_OF_SERVICE'] ?? 0);

                // se não atendeu, zera (painel)
                if (empty($answered)) {
                    $taxaOfService = 0.0;
                }

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

                if ($isRamal) {
                    $taxaOfService = 0.0;
                }

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
                    'taxa_of_service'  => number_format($taxaOfService, 2, '.', ''),
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
    }*/

    private function updateRedis(): void
    {
        try {
            $now = time();

            $payload = [
                'statusGeral'   => 'OK',
                'totalChamadas' => 0, // ✅ vai ser contado só com chamadas válidas
                'chamadas'      => [],
                'timestamp'     => date('Y-m-d H:i:s'),
                'server_now'    => $now
            ];

            // cache local pra não ficar batendo ARI repetido
            $nameCache = [];

            foreach ($this->channelData as $id => $c) {

                if (!$id) continue;

                // ✅ 1) Ignora canal tombado (dead)
                if (!empty($this->deadChannels[$id]) && $this->deadChannels[$id] > $now) {
                    continue;
                }

                // ✅ 2) Ignora "stub" (sem started) -> isso não é chamada ativa de painel
                // (stub pode existir só pra salvar FAIL-CDR)
                if (empty($c['started'])) {
                    continue;
                }

                $started  = (int)($c['started'] ?? $now);
                $answered = !empty($c['answered']) ? (int)$c['answered'] : null;
                $ended    = !empty($c['ended']) ? (int)$c['ended'] : null;

                // duração
                $dur = 0;
                if ($answered && empty($ended)) {
                    $dur = $now - $answered;
                } elseif ($answered && $ended) {
                    $dur = $ended - $answered;
                }

                // nome do canal
                $name = $c['name'] ?? null;

                // fallback via ARI (somente se não tiver name e o canal ainda está vivo)
                if (!$name) {
                    if (isset($nameCache[$id])) {
                        $name = $nameCache[$id];
                    } else {
                        try {
                            $res = $this->http->get("http://{$this->ariHost}:8088/ari/channels/{$id}", [
                                'auth'    => [$this->ariUser, $this->ariPass],
                                'headers' => ['Accept' => 'application/json'],
                                'http_errors' => false,
                            ]);

                            if ($res->getStatusCode() < 400) {
                                $chanData = json_decode((string)$res->getBody(), true) ?: [];
                                $name = $chanData['name'] ?? null;
                                $nameCache[$id] = $name;

                                if ($name) {
                                    $this->channelData[$id]['name'] = $name;
                                }
                            }
                        } catch (\Throwable) {
                            // ignora
                        }
                    }
                }

                // vars
                $vars = is_array($c['vars'] ?? null) ? $c['vars'] : [];

                // ✅ MANUAL: não mostra A-leg no painel
                $isManual = !empty($vars['IS_MANUAL']) || !empty($vars['__IS_MANUAL']) || !empty($vars['MANUAL_CALL']) || !empty($vars['__MANUAL_CALL']);
                $leg = strtoupper(trim((string)($vars['ARI_LEG'] ?? $vars['__ARI_LEG'] ?? '')));

                // se for manual e for A-leg -> não entra no painel
                if ($isManual && $leg === 'A') {
                    continue;
                }

                // se NÃO é manual e leg está vazio -> deixa passar (discador costuma vir null)

                // --- Suporte às variáveis vindas do dialplan ---
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

                $context  = strtolower((string)($vars['CONTEXT'] ?? ($c['context'] ?? '')));
                $ownerId  = $vars['OWNER_ID'] ?? null;
                $tenantId = $vars['TENANT_ID'] ?? null;
                $role     = $vars['ROLE'] ?? null;

                $minuteCost  = (float)($vars['CALL_MINUTE_COST'] ?? 0);
                $torpedoCost = (float)($vars['TORPEDO_COST'] ?? 0);

                $type = strtoupper((string)($vars['VARIABLE_TYPE'] ?? ($vars['TYPE'] ?? '')));
                $taxaOfService = (float)($vars['TAXA_OF_SERVICE'] ?? 0);

                // se não atendeu, zera (painel)
                if (empty($answered)) {
                    $taxaOfService = 0.0;
                }

                // detectar canal de spy
                $isSpy = ($context === 'spy-control' || isset($vars['SPY_CHANNEL']));

                // =======================================================
                // 🔥 DETECÇÃO CORRETA DE TIPOS DE CANAIS
                // =======================================================
                $chName = strtolower((string)($name ?? ''));

                $isTrunk = (bool)preg_match('/^pjsip\/mxx/i', $chName);
                $isRamal = (bool)preg_match('/^pjsip\/([0-9]{8})-/i', $chName);

                if ($isRamal) {
                    $taxaOfService = 0.0;
                }

                $isLocal = (!$isTrunk && !$isRamal && preg_match('/^local\//i', $chName));

                if ($isLocal) {
                    $trunk = 'LOCAL';
                    $displayCost = 0.0;

                } elseif ($isTrunk) {
                    $trunk = $vars['TRUNK'] ?? 'TRONCO';
                    $displayCost = ($type === 'TORPEDO') ? $torpedoCost : $minuteCost;

                } elseif ($isRamal) {

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
                $caller = $c['caller_name'] ?? ($c['extension'] ?? ($callerIdName ?? '—'));
                $destination = $c['destination'] ?? null;

                // ajustes se for spy
                if ($isSpy) {
                    $type = 'Escuta';
                    $trunk = $vars['SPY_TRUNK'] ?? '—';
                    $displayCost = 0.0;
                    $caller = 'Monitoramento';

                    $destino = $vars['SPY_DESTINATION'] ?? '—';
                    $destination = "Escutando {$destino}";
                }

                // ✅ incrementa total só com canal válido
                $payload['totalChamadas']++;

                $payload['chamadas'][] = [
                    'id'          => $id,
                    'name'        => $name,
                    'number'      => $c['extension'] ?? ($c['number'] ?? $callerIdNum ?? 'Desconhecido'),
                    'caller'      => $caller,
                    'destination' => $destination ?? $dest ?? $extension,
                    'status'      => $c['status'] ?? ($c['state'] ?? '—'),
                    'state'       => $c['state'] ?? '',
                    'duration'    => gmdate("i:s", max(0, (int)$dur)),
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
                    'taxa_of_service'  => number_format($taxaOfService, 2, '.', ''),
                    'variable_type'    => $type,
                    'vars'             => $vars
                ];
            }

            $this->redis->set('asterisk:active_calls', json_encode($payload, JSON_UNESCAPED_UNICODE));
            echo "[Redis] Atualizado com {$payload['totalChamadas']} chamadas.\n";

        } catch (\Throwable $e) {
            echo "[Redis ❌] Erro ao atualizar: {$e->getMessage()}\n";
        }
    }
}
// ================= Configuração =================
$listener = new StasisListenerAsterisk('maxx', 'mxx123', '127.0.0.1', 'app-asterisk');
$listener->run();



