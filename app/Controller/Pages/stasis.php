#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

use App\Config\TelephonyConfig;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use WebSocket\Client as WsClient;
use Predis\Client as RedisClient;
use WilliamCosta\DotEnv\Environment;

Environment::load(__DIR__ . '/../../../');


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
        $this->ws = new WsClient("ws://{$ariUser}:{$ariPass}@{$ariHost}:" . TelephonyConfig::ariPort() . "/ari/events?app={$stasisApp}&subscribeAll=true");

        // 🔹 Redis
        $this->redis = new RedisClient(TelephonyConfig::redisConfig());
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
        $id   = $event['channel']['id'] ?? null;
        $name = $event['channel']['name'] ?? '';

        // =============================================================
        // 🛡️ TRAVA GLOBAL SNOOP (O SEGREDO DA LIMPEZA)
        // =============================================================
        // Verificamos se é Snoop pelo Nome ou pela Tag na memória
        $isSnoop = (str_contains($name, 'Snoop/') || (isset($this->channelData[$id]['is_snoop'])));

        switch ($type) {

            case 'StasisStart':
                if ($isSnoop) {
                    // Tenta pegar o ID do pai pelo nome do canal: Snoop/123.456-0000
                    $parentId = null;
                    if (preg_match('/Snoop\/([0-9]+\.[0-9]+)/', $name, $matches)) {
                        $parentId = $matches[1];
                    }

                    $this->channelData[$id] = [
                        'id'       => $id,
                        'is_snoop' => true,
                        'parent_id'=> $parentId, // <--- CRUCIAL: Guarda a relação
                        'name'     => $name
                    ];

                    // Se for um áudio injetado no ramal, marca o pai para o ícone aparecer
                    if ($parentId && isset($this->channelData[$parentId])) {
                        $this->channelData[$parentId]['audio_executando'] = true;
                        $this->updateRedis();
                    }
                    return;
                }
                $this->onStart($event);
                break;

            case 'ChannelDestroyed':
            case 'ChannelHangupRequest':
            case 'StasisEnd':
                // Se for Snoop, limpa e NÃO chama o handleChannelDestroyed
                if ($isSnoop) {
                    if (isset($this->channelData[$id])) {
                        unset($this->channelData[$id]);
                        unset($this->pendingVars[$id]);
                        $this->updateRedis(); // 🧹 Limpa o Dashboard IMEDIATAMENTE
                        echo "[SNOOP] 🧹 Canal finalizado e removido do Redis: {$id}\n";
                    }
                    return; // ✋ Impede que o Snoop caia na tarifação/zumbi
                }
                $this->handleChannelDestroyed($event);
                break;

            case 'ChannelVarset':
            case 'ChannelStateChange':
                // Snoop não tarifa e não muda estado de chamada real
                if ($isSnoop) return;

                if ($type === 'ChannelVarset') {
                    $this->handleChannelVarset($event);
                } else {
                    $this->handleChannelStateChange($event);
                }
                break;

            case 'ChannelEnteredBridge':
            case 'ChannelLeftBridge':
                // Snoop não deve aparecer como "Em Ponte" no dashboard
                if ($isSnoop) return;

                if ($id && isset($this->channelData[$id])) {
                    $this->channelData[$id]['in_bridge'] = ($type === 'ChannelEnteredBridge');
                    $this->updateRedis();
                    $action = ($type === 'ChannelEnteredBridge') ? "entrou na" : "saiu da";
                    echo "[🔗] Canal {$id} {$action} bridge.\n";
                }
                break;

            case 'ChannelDtmfReceived':
                if ($isSnoop) return;
                $this->onDtmf($event);
                break;

            case 'PlaybackFinished':
                $this->onPlaybackFinished($event);
                break;

            case 'Dial':
                $this->handleDialEvent($event);
                break;

            default:
                // Eventos não tratados
                break;
        }
    }

    private function handleChannelVarset(array $event): void
    {
        $channelId = $event['channel']['id'] ?? null;
        $var       = $event['variable'] ?? null;
        $value     = $event['value'] ?? null;

        if (!$channelId || !$var) return;

        // 🔥 Ignora varset de canal morto
        if (!empty($this->deadChannels[$channelId]) && $this->deadChannels[$channelId] > time()) {
            unset($this->pendingVars[$channelId]);
            return;
        }

        // Se for o canal de gravação, só remove da memória e tchau
        if (isset($this->channelData[$channelId]['vars']['SNOOP_RECORDING']) ||
            isset($this->channelData[$channelId]['is_snoop'])) {

            unset($this->channelData[$channelId]);
            $this->redis->hdel('discador:stasis:channels', $channelId);
            echo "[SNOOP] Canal de gravação {$channelId} finalizado e removido.\n";
            return;
        }

        // ==========================================================
        // ✅ GARANTE QUE O CANAL EXISTA (não depende mais do onStart)
        // ==========================================================
        if (!isset($this->channelData[$channelId])) {
            $this->channelData[$channelId] = [
                'id'       => $channelId,
                'name'     => $event['channel']['name'] ?? null,
                'started'  => time(), // ESSENCIAL para updateRedis não ignorar
                'state'    => strtolower($event['channel']['state'] ?? 'ringing'),
                'status'   => 'Chamando...',
                'duration' => '00:00',
                'vars'     => [],
            ];
        }

        if (!isset($this->channelData[$channelId]['vars']) || !is_array($this->channelData[$channelId]['vars'])) {
            $this->channelData[$channelId]['vars'] = [];
        }

        // ==========================================================
        // ✅ MERGE pendingVars (se existirem)
        // ==========================================================
        if (!empty($this->pendingVars[$channelId])) {
            $this->channelData[$channelId]['vars'] = array_merge(
                $this->pendingVars[$channelId],
                $this->channelData[$channelId]['vars']
            );
            unset($this->pendingVars[$channelId]);
        }

        // ==========================================================
        // ✅ GRAVA VAR NO CANAL
        // ==========================================================
        $this->channelData[$channelId]['vars'][$var] = $value;

        // ==========================================================
        // ✅ COPIA IDENTIDADE PRO TOPO (para filtro SSE funcionar)
        // ==========================================================
        $v = $this->channelData[$channelId]['vars'];

        $this->channelData[$channelId]['owner_id'] =
            $this->channelData[$channelId]['owner_id']
            ?? $v['OWNER_ID'] ?? $v['__OWNER_ID'] ?? null;

        $this->channelData[$channelId]['tenant_id'] =
            $this->channelData[$channelId]['tenant_id']
            ?? $v['TENANT_ID'] ?? $v['__TENANT_ID'] ?? null;

        $this->channelData[$channelId]['role'] =
            $this->channelData[$channelId]['role']
            ?? $v['ROLE'] ?? $v['__ROLE'] ?? null;

        // ==========================================================
        // 📞 COPIA CALLERID PARA O TOPO (sempre atualiza quando chegar)
        // ==========================================================
        if ($var === 'CALLERID(num)') {
            $this->channelData[$channelId]['caller_number'] = $value;
        } else {
            $this->channelData[$channelId]['caller_number'] =
                $this->channelData[$channelId]['caller_number']
                ?? $v['CALLERID(num)'] ?? null;
        }

        if ($var === 'CALLERID(name)') {
            $this->channelData[$channelId]['caller_name'] = $value;
        } else {
            $this->channelData[$channelId]['caller_name'] =
                $this->channelData[$channelId]['caller_name']
                ?? $v['CALLERID(name)'] ?? null;
        }

        // ==========================================================
        // 🔎 LOG VARS IMPORTANTES (incluindo __)
        // ==========================================================
        if (in_array($var, [
            'OWNER_ID','__OWNER_ID',
            'TENANT_ID','__TENANT_ID',
            'RECORD_CALLS', '__RECORD_CALLS', // 🚀 ADICIONEI ESTAS DUAS AQUI
            'TYPE','VARIABLE_TYPE','__VARIABLE_TYPE',
            'ROLE','__ROLE',
            'JOB_ID','CALL_ID','CAMPAIGN_ID','TECHPREFIX','CAMPAIGN_TYPE',
            'TAXA_OF_SERVICE','CALL_MINUTE_COST',
            'TRUNK','__TRUNK',
            'CALLERID(num)',
            'CALLERID(name)',
        ], true)) {
            error_log("[VARS] Canal={$channelId} → {$var}={$value}");
        }

        // ==========================================================
        // 🔁 FAIL-CDR (mantido intacto)
        // ==========================================================
        if (in_array($var, ['OWNER_ID', 'TENANT_ID', '__OWNER_ID', '__TENANT_ID'], true)) {
            $this->trySavePendingFailCdr($channelId);
        }

        if (
            in_array($var, [
                'TYPE','VARIABLE_TYPE','CAMPAIGN_ID','CAMPAIGN_TYPE',
                'CALL_ID','TAXA_OF_SERVICE','TECHPREFIX','JOB_ID',
                'CALLERID(num)','CALLERID(name)',
            ], true)
            && isset($this->channelData[$channelId]['pending_fail_cdr'])
        ) {

            $keyMap = [
                'TYPE'            => 'variable_type',
                'VARIABLE_TYPE'   => 'variable_type',
                'CAMPAIGN_ID'     => 'campaign_id',
                'CAMPAIGN_TYPE'   => 'variable_type',
                'CALL_ID'         => 'call_id',
                'TECHPREFIX'      => 'techprefix',
                'TAXA_OF_SERVICE' => 'taxa_of_service',
                'JOB_ID'          => 'job_id',
                'CALLERID(num)'   => 'caller_number',
                'CALLERID(name)'  => 'caller_name',
            ];

            $field = $keyMap[$var] ?? null;

            if ($field) {
                $this->channelData[$channelId]['pending_fail_cdr'][$field] = $value;

                $failKey = "cdr-falha-pendente:{$channelId}";
                $this->redis->setex(
                    $failKey,
                    180,
                    json_encode($this->channelData[$channelId]['pending_fail_cdr'], JSON_UNESCAPED_UNICODE)
                );

                error_log("[CDR] 🔄 FAIL-CDR atualizado {$field}={$value} canal={$channelId}");
            }
        }

        // ==========================================================
        // 🔄 Atualiza painel
        // ==========================================================
        $this->updateRedis();
    }


    private function handleChannelDestroyed(array $event): void
    {
        $channelId = $event['channel']['id'] ?? null;
        if (!$channelId) return;

        // 🔥 PULO DO GATO: Se for o Snoop que marcamos no onStart
        if (isset($this->channelData[$channelId]['is_snoop'])) {
            unset($this->channelData[$channelId]);
            unset($this->pendingVars[$channelId]);

            // 🚀 Isso aqui é o que vai tirar o "zumbi" do seu Dashboard Ativo:
            $this->updateRedis();

            echo "[SNOOP] 🧹 Gravação finalizada. Canal {$channelId} removido do Redis.\n";
            return;
        }

        // ✅ Se já era canal "morto" (ringall loser etc) não recria nem processa
        if (!empty($this->deadChannels[$channelId]) && $this->deadChannels[$channelId] > time()) {
            unset($this->pendingVars[$channelId]);
            unset($this->channelData[$channelId]);
            $this->updateRedis();
            return;
        }

        // Se não existe no mapa, nada a fazer (mas limpa pending)
        if (!isset($this->channelData[$channelId])) {
            unset($this->pendingVars[$channelId]);
            return;
        }

        // =============================================================
        // ✅ Merge vars + pending (pra não perder __IS_MANUAL/__ARI_LEG etc)
        // =============================================================
        if (!isset($this->channelData[$channelId]['vars']) || !is_array($this->channelData[$channelId]['vars'])) {
            $this->channelData[$channelId]['vars'] = [];
        }
        if (!empty($this->pendingVars[$channelId]) && is_array($this->pendingVars[$channelId])) {
            $this->channelData[$channelId]['vars'] = array_merge(
                $this->channelData[$channelId]['vars'],
                $this->pendingVars[$channelId]
            );
            unset($this->pendingVars[$channelId]);
        }

        $vars = $this->channelData[$channelId]['vars'] ?? [];

        // =============================================================
        // ✅ resolve peer (direto ou reverso)
        // =============================================================
        $peer = $this->channelData[$channelId]['peer'] ?? null;

        if (!$peer) {
            foreach ($this->channelData as $cid => $info) {
                if (($info['peer'] ?? null) === $channelId) {
                    $peer = $cid;
                    break;
                }
            }
        }

        // =============================================================
        // ✅ MANUAL: segura A-leg enquanto peer (B-leg) existir
        // - NÃO depende de CALL_ID (porque manual agora pode ser só args)
        // =============================================================
        $isManual = (
            !empty($vars['MANUAL_CALL']) ||
            !empty($vars['__MANUAL_CALL']) ||
            !empty($vars['IS_MANUAL']) ||
            !empty($vars['__IS_MANUAL']) ||
            // opcional: se você salvou isso no onStart:
            !empty($this->channelData[$channelId]['stasis_mode']) && $this->channelData[$channelId]['stasis_mode'] === 'manual'
        );

        $leg = $vars['ARI_LEG'] ?? $vars['__ARI_LEG'] ?? null;

        // Se for A-leg manual e ainda existe peer vivo -> não remove do mapa agora
        if ($isManual && $leg === 'A' && $peer && isset($this->channelData[$peer])) {
            error_log("[MANUAL] ⏭ Ignorando destroy A-leg {$channelId} (peer vivo={$peer})");
            return;
        }

        // =============================================================
        // 🔥 RINGALL LOSER → remove imediatamente (sem CDR)
        // =============================================================
        if (!empty($this->channelData[$channelId]['ringall_loser'])) {
            unset($this->channelData[$channelId]);
            $this->updateRedis();
            error_log("[RINGALL] 🧹 Canal {$channelId} removido (loser ringall)");
            return;
        }

        // =============================================================
        // causa / cause_txt
        // =============================================================
        $causeCode = $event['cause'] ?? null;
        $causeText = $event['cause_txt'] ?? null;

        $this->channelData[$channelId]['ended'] = $this->channelData[$channelId]['ended'] ?? time();

        if ($causeCode !== null && !isset($this->channelData[$channelId]['cause'])) {
            $this->channelData[$channelId]['cause'] = $causeCode;
        }

        if ($causeText !== null && !isset($this->channelData[$channelId]['cause_txt'])) {
            $this->channelData[$channelId]['cause_txt'] = $causeText;
        }

        // se esse canal morreu e tinha transferência em andamento, destrava ele mesmo
        if (!empty($this->channelData[$channelId]['transfer_in_progress'])) {
            $this->unlockDtmfTransfer($channelId, 'main_channel_destroyed');
        }

        // =============================================================
        // ✅ RINGALL CLEANUP (complemento)
        // =============================================================
        try {
            $isRingallOutbound =
                (($vars['RINGALL'] ?? '') === '1') ||
                (!empty($vars['RINGALL_INBOUND']) && !empty($vars['RINGALL_BRIDGE']));

            if ($isRingallOutbound) {

                $inbound = trim((string)($vars['RINGALL_INBOUND'] ?? ''));
                $bridge  = trim((string)($vars['RINGALL_BRIDGE']  ?? ''));

                if ($inbound && isset($this->channelData[$inbound]['ringall']['outbound'])) {
                    foreach (($this->channelData[$inbound]['ringall']['outbound'] ?? []) as $ramal => $ch) {
                        if ($ch === $channelId) {
                            unset($this->channelData[$inbound]['ringall']['outbound'][$ramal]);
                            break;
                        }
                    }
                }

                // outbound ringall NÃO deve forçar tronco via peer
                $peer = null;

                if ($bridge) {
                    try {
                        $this->http->delete("http://{$this->ariHost}:8088/ari/bridges/{$bridge}/removeChannel", [
                            'query'       => ['channel' => $channelId],
                            'http_errors' => false
                        ]);
                    } catch (\Throwable) {}
                }
            }

            if (!empty($this->channelData[$channelId]['ringall'])) {

                $ring     = $this->channelData[$channelId]['ringall'];
                $bridgeId = $ring['bridge'] ?? null;
                $winnerCh = $ring['winner']['channel'] ?? null;
                $outs     = $ring['outbound'] ?? [];

                if (is_array($outs)) {
                    foreach ($outs as $ramal => $outCh) {
                        if (!$outCh) continue;
                        if ($winnerCh && $outCh === $winnerCh) continue;

                        if ($bridgeId) {
                            try {
                                $this->http->delete("http://{$this->ariHost}:8088/ari/bridges/{$bridgeId}/removeChannel", [
                                    'query'       => ['channel' => $outCh],
                                    'http_errors' => false
                                ]);
                            } catch (\Throwable) {}
                        }

                        try {
                            $this->http->delete("http://{$this->ariHost}:8088/ari/channels/{$outCh}", [
                                'http_errors' => false
                            ]);
                        } catch (\Throwable) {}

                        unset($this->channelData[$outCh]);
                    }
                }

                unset($this->channelData[$channelId]['ringall']);
            }

        } catch (\Throwable $e) {
            error_log("[RINGALL] ⚠ cleanup falhou: " . $e->getMessage());
        }

        // =============================================================
        // 🔓 LIBERAÇÃO DE AGENTE
        // =============================================================
        $channelType = $this->channelData[$channelId]['type'] ?? null;

        if ($channelType === 'AGENT' && empty($this->channelData[$channelId]['agent_released'])) {

            $this->markDead($channelId, 30);

            $isRingallAgent =
                (($vars['RINGALL'] ?? '') === '1') ||
                (!empty($vars['RINGALL_INBOUND']) && !empty($vars['RINGALL_BRIDGE']));

            if (!$isRingallAgent) {
                $origin = $this->channelData[$channelId]['transfer_origin'] ?? null;
                if ($origin) {
                    $this->stopMoh($origin, 'agent_channel_destroyed');
                    $this->unlockDtmfTransfer($origin, 'agent_channel_destroyed');
                    unset($this->channelData[$origin]['transfer_in_progress']);
                    error_log("[TRANSFER] 🔓 Unlock origin={$origin} (agent destroyed={$channelId})");
                }
            }

            $agentId = $this->channelData[$channelId]['agent_id'] ?? null;
            if ($agentId) {
                $this->releaseAgentByChannel($channelId, 'agent_channel_destroyed');
                $this->channelData[$channelId]['agent_released'] = true;
                error_log("[AGENT] 🔓 Ramal {$agentId} liberado (canal {$channelId})");
            }
        }

        // =============================================================
        // identifica canal principal
        // =============================================================
        $isMain =
            isset($this->channelData[$channelId]['vars']['OWNER_ID']) ||
            isset($this->channelData[$channelId]['vars']['TYPE']) ||
            isset($this->channelData[$channelId]['vars']['__OWNER_ID']) ||
            isset($this->channelData[$channelId]['vars']['__TYPE']);

        // detecta tronco
        $name = $this->channelData[$channelId]['name'] ?? '';

        $isTrunkByVar =
            !empty($vars['TRUNK']) || !empty($vars['TRUNK_ID']) ||
            !empty($vars['__TRUNK']) || !empty($vars['__TRUNK_ID']);

        $isTrunkByName = (bool)preg_match('/^PJSIP\/mxx\d+-/i', $name);
        $isRamalNumerico = (bool)preg_match('/^PJSIP\/\d{8}-/i', $name);

        $isTrunk = $isTrunkByVar || $isTrunkByName || !$isRamalNumerico;

        // evita duplicata
        $dupKey  = "cdr-saved:{$channelId}";
        $failKey = "cdr-falha-pendente:{$channelId}";

        // =============================================================
        // 1) FAIL-CDR pendente
        // =============================================================
        try {
            $failJson = $this->redis->get($failKey);
            if ($failJson) {

                $failEvent  = json_decode($failJson, true) ?: [];
                $dialStatus = $failEvent['dialstatus'] ?? 'FAILED';

                if ($dialStatus === 'PROGRESS') {
                    error_log("[CDR] ⏭️ Ignorando CDR PROGRESS (canal {$channelId})");
                    $this->redis->del($failKey);
                } else {
                    $this->channelData[$channelId]['pending_fail_cdr'] = $failEvent;

                    $saved = $this->trySavePendingFailCdr($channelId);
                    if (!$saved) {
                        error_log("[CDR] ⏳ Aguardando VARSET para salvar FAIL-CDR canal {$channelId}");
                        return;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("[CDR] ⚠ Falha no FAIL-CDR: " . $e->getMessage());
        }

        // =============================================================
        // 2) Chamadas atendidas → tarifação
        // =============================================================
        if ($isMain && !empty($this->channelData[$channelId]['answered'])) {

            // 🚫 ramal do discador não tarifa (MAS manual pode)
            if (!$isTrunk && !$isManual) {
                error_log("[CDR] ⏭ Ramal do discador ignorado {$channelId}");
            } else {
                try {
                    $this->calculateTariff($channelId);
                    $this->channelData[$channelId]['tariff_done'] = true;
                    $this->redis->setex($dupKey, 300, 1);
                } catch (\Throwable $e) {
                    error_log("[CDR] ❌ Erro ao tarifar canal {$channelId}: " . $e->getMessage());
                }
            }
        }

        // =============================================================
        // 3) Ramal encerrou antes → tarifar tronco
        // =============================================================
        $isDiscadorRamal = (!$isTrunk && !$isManual);

        if (!$isDiscadorRamal && $peer && isset($this->channelData[$peer])) {

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
                error_log("[CDR] ⚠ Erro no tronco forçado: " . $e->getMessage());
            }
        }

        // =============================================================
        // 4) FALLBACK — cliente encerrou, ramal ainda existe
        // =============================================================
        if ($isMain && $peer && isset($this->channelData[$peer])) {

            $this->stopMoh($channelId, 'main_channel_destroyed');

            if (empty($this->channelData[$peer]['agent_released'])) {

                $peerType = $this->channelData[$peer]['type'] ?? null;

                if ($peerType === 'AGENT') {

                    $pvars = $this->channelData[$peer]['vars'] ?? [];
                    $peerIsRingall =
                        (($pvars['RINGALL'] ?? '') === '1') ||
                        (!empty($pvars['RINGALL_INBOUND']) && !empty($pvars['RINGALL_BRIDGE']));

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

        // =============================================================
        // 5) LIMPEZA FINAL
        // =============================================================
        $cdrSaved = (bool)$this->redis->exists($dupKey);

        $needsWait =
            empty($this->channelData[$channelId]['dialstatus']) ||
            ($this->channelData[$channelId]['dialstatus'] === 'PROGRESS') ||
            !empty($this->channelData[$channelId]['pending_fail_cdr']);

        if ($cdrSaved || !$needsWait) {

            $this->markDead($channelId, 30);

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

        $now = time();

        // ✅ TUMBA: não recria canal morto (ringall loser etc.)
        if (!empty($this->deadChannels[$channelId]) && $this->deadChannels[$channelId] > $now) {
            return;
        }

        $state  = strtolower($event['channel']['state'] ?? '');
        $chName = $event['channel']['name'] ?? ''; // Nome técnico: PJSIP/1001-xxx
        $caller = $event['channel']['caller']['number'] ?? 'Desconhecido';

        // ==========================================================
        // ✅ Se o canal ainda não existir, cria skeleton completo
        // ==========================================================
        if (!isset($this->channelData[$channelId])) {
            $this->channelData[$channelId] = [
                'id'       => $channelId,
                'number'   => $caller,
                'name'     => $event['channel']['name'] ?? null,
                'status'   => '📞 Tocando',
                'state'    => $state ?: 'ringing',
                'started'  => $now,      // importante pro painel
                'duration' => '00:00',
                'vars'     => [],
            ];

            echo "[+] Novo canal criado em handleChannelStateChange: {$channelId} ({$caller})\n";
        } else {
            // PATCH — registra/atualiza nome do canal
            $this->channelData[$channelId]['name'] = $event['channel']['name'] ?? ($this->channelData[$channelId]['name'] ?? null);
            if (!isset($this->channelData[$channelId]['vars']) || !is_array($this->channelData[$channelId]['vars'])) {
                $this->channelData[$channelId]['vars'] = [];
            }
            if (empty($this->channelData[$channelId]['started'])) {
                $this->channelData[$channelId]['started'] = $now; // evita stub sem started
            }
        }

        $oldState = $this->channelData[$channelId]['state'] ?? '(novo)';

        // ==========================================================
        // ✅ Associa pendingVars ANTES (pra já ter identidade no painel)
        // ==========================================================
        if (isset($this->pendingVars[$channelId]) && is_array($this->pendingVars[$channelId])) {
            $this->channelData[$channelId]['vars'] = array_merge(
                $this->channelData[$channelId]['vars'] ?? [],
                $this->pendingVars[$channelId]
            );
            unset($this->pendingVars[$channelId]);
        }

        // ==========================================================
        // ✅ Copia identidade do vars pro topo (SSE filtra por isso)
        // ==========================================================
        $v = $this->channelData[$channelId]['vars'] ?? [];

        $this->channelData[$channelId]['owner_id'] = $this->channelData[$channelId]['owner_id']
            ?? $v['OWNER_ID'] ?? $v['__OWNER_ID'] ?? null;

        $this->channelData[$channelId]['tenant_id'] = $this->channelData[$channelId]['tenant_id']
            ?? $v['TENANT_ID'] ?? $v['__TENANT_ID'] ?? null;

        $this->channelData[$channelId]['role'] = $this->channelData[$channelId]['role']
            ?? $v['ROLE'] ?? $v['__ROLE'] ?? null;

        // ==========================================================
        // 🔹 Atualiza estado e status humanos
        // ==========================================================
        $this->channelData[$channelId]['state'] = $state;

        switch ($state) {
            case 'ringing':
                $this->channelData[$channelId]['status'] = '📞 Tocando';
                break;

            case 'progress':
                // ✅ seu painel usa "Progress..." em alguns fluxos
                $this->channelData[$channelId]['status'] = '📡 Progress...';
                break;

            case 'up':
                if (!isset($this->channelData[$channelId]['answered'])) {
                    $this->channelData[$channelId]['answered'] = $now;
                }
                $this->channelData[$channelId]['status'] = '✅ Atendida';
                $this->channelData[$channelId]['duration'] = gmdate(
                    "i:s",
                    $now - ($this->channelData[$channelId]['answered'] ?? $this->channelData[$channelId]['started'])
                );
                echo "[✔] Canal {$channelId} agora está ATENDIDO\n";

                // ==========================================================
                // 🎙️ GATILHO DE GRAVAÇÃO VIA SNOOP (EVITA ÁUDIO MUDO)
                // ==========================================================
                if (preg_match('/PJSIP\/\d+/', $chName)) {

                    $v = $this->channelData[$channelId]['vars'] ?? [];
                    $cId = $v['CALL_ID'] ?? $v['__CALL_ID'] ?? null;

                    if ($cId) {
                        $ccRaw = $this->redis->get("voice:call_context:{$cId}");

                        if ($ccRaw) {
                            $cc = json_decode($ccRaw, true);
                            $shouldRecord = (int)($cc['record_calls'] ?? 0);

                            if ($shouldRecord === 1) {
                                $tId = $cc['tenant_id'] ?? '0';
                                $uId = $cc['user_id'] ?? '0';

                                // 📂 Define o nome do arquivo.
                                // Se o seu CALL_ID não vier com "call:", a gente garante aqui para o Linux.
                                $recordName = "tenant_{$tId}/user_{$uId}/{$cId}";

                                try {
                                    $snoopResp = $this->http->post("http://{$this->ariHost}:8088/ari/channels/{$channelId}/snoop", [
                                        'json' => [
                                            'app'     => $this->stasisApp,
                                            'spy'     => 'both',
                                            'appArgs' => 'snoop_recording'
                                        ],
                                        'http_errors' => false
                                    ]);

                                    if ($snoopResp->getStatusCode() == 200) {
                                        $snoopData = json_decode((string)$snoopResp->getBody(), true);
                                        $snoopId   = $snoopData['id'];

                                        $this->http->post("http://{$this->ariHost}:8088/ari/channels/{$snoopId}/record", [
                                            'query' => [
                                                'name'     => $recordName,
                                                'format'   => 'wav',
                                                'ifExists' => 'overwrite',
                                                'beep'     => false
                                            ],
                                            'http_errors' => false
                                        ]);
                                        echo "[REC-OK] 🎙️ Gravação Snoop iniciada: {$recordName}.wav\n";
                                    }
                                } catch (\Throwable $e) {
                                    echo "[REC-EXCEPTION] ⚠️ Erro: " . $e->getMessage() . "\n";
                                }
                            }
                        }
                    }
                }

                break;

            case 'busy':
                $this->channelData[$channelId]['status'] = '⛔ Ocupada';
                break;

            case 'down':
                $this->channelData[$channelId]['status'] = '🔚 Finalizada';
                $this->channelData[$channelId]['ended'] = $now;
                $this->channelData[$channelId]['duration'] = isset($this->channelData[$channelId]['answered'])
                    ? gmdate("i:s", $this->channelData[$channelId]['ended'] - $this->channelData[$channelId]['answered'])
                    : '00:00';
                break;
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

                $inbound = $inbound ? trim((string)$inbound) : null;

                if ($inbound && $bridgeId && $ramal && isset($this->channelData[$inbound])) {

                    if (!isset($this->channelData[$inbound]['ringall'])) {
                        $this->channelData[$inbound]['ringall'] = [
                            'bridge'   => $bridgeId,
                            'targets'  => [],
                            'outbound' => [],
                            'winner'   => null,
                            'started'  => $this->channelData[$inbound]['started'] ?? $now,
                            'deadline' => $now + 20,
                        ];
                    }

                    if (empty($this->channelData[$inbound]['ringall']['outbound'][$ramal])) {
                        $this->channelData[$inbound]['ringall']['outbound'][$ramal] = $channelId;
                    }

                    if (!empty($this->channelData[$inbound]['ringall']['winner'])) {
                        echo "[RINGALL] Perdedor {$channelId} (winner já definido)\n";
                        try {
                            $this->http->delete("http://{$this->ariHost}:8088/ari/channels/{$channelId}", ['http_errors' => false]);
                        } catch (\Throwable) {}
                        return;
                    }

                    $this->channelData[$inbound]['ringall']['winner'] = [
                        'ramal'   => $ramal,
                        'channel' => $channelId,
                        'ts'      => $now,
                    ];

                    echo "[RINGALL] ✅ Winner {$ramal} channel={$channelId} inbound={$inbound} bridge={$bridgeId}\n";

                    try {
                        $this->http->post("http://{$this->ariHost}:8088/ari/bridges/{$bridgeId}/addChannel", [
                            'query'       => ['channel' => $channelId],
                            'http_errors' => false
                        ]);
                    } catch (\Throwable) {}

                    $outs = $this->channelData[$inbound]['ringall']['outbound'] ?? [];
                    foreach ($outs as $r => $ch) {
                        if ($ch && $ch !== $channelId) {
                            try {
                                $this->http->delete("http://{$this->ariHost}:8088/ari/channels/{$ch}", ['http_errors' => false]);
                            } catch (\Throwable) {}

                            $this->markDead($ch, 30);
                            unset($this->pendingVars[$ch]);
                            unset($this->channelData[$ch]);

                            error_log("[RINGALL] 🧹 Perdedor removido do painel: {$ch} (ramal {$r})");
                        }
                    }

                    $this->updateRedis();

                    $this->channelData[$inbound]['peer']   = $channelId;
                    $this->channelData[$channelId]['peer'] = $inbound;

                    $this->channelData[$inbound]['bridge']   = $bridgeId;
                    $this->channelData[$channelId]['bridge'] = $bridgeId;

                    $memKey = $this->channelData[$inbound]['vars']['RR_MEM_KEY'] ?? null;
                    if ($memKey) {
                        $this->redis->setex($memKey, 86400, $ramal);
                    }

                    $this->updateRedis();
                    return;
                }

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

            $vars = $this->channelData[$channelId]['vars'] ?? [];
            if (($vars['RINGALL'] ?? '') === '1') {
                return;
            }

            $peer = $this->channelData[$channelId]['peer'];

            if (!isset($this->channelData[$channelId]['bridge'])) {
                try {
                    $bridgeResp = $this->http->post("http://{$this->ariHost}:8088/ari/bridges", [
                        'json' => ['type' => 'mixing']
                    ]);
                    $bridge = json_decode((string)$bridgeResp->getBody(), true);
                    $bridgeId = $bridge['id'] ?? null;

                    if ($bridgeId) {
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
                            $this->stopMoh($origin, 'bridge_created_origin');
                            $this->unlockDtmfTransfer($origin, 'bridge_created');
                            unset($this->channelData[$origin]['transfer_in_progress']);
                        }
                    }

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



    private function onStart(array $event): void
    {
        $channelId = $event['channel']['id'] ?? null;
        if (!$channelId) return;

        if (($args[0] ?? '') === 'snoop_recording') {
            // 💾 Guardamos apenas o essencial para a limpeza futura
            $this->channelData[$channelId] = [
                'id'       => $channelId,
                'is_snoop' => true,
                'name'     => $event['channel']['name'] ?? 'Snoop'
            ];
            echo "[SNOOP] 🎙️ Canal {$channelId} marcado como gravação. Ignorado no Dashboard.\n";
            return;
        }

        $now = time();

        // =====================================================
        // 🔁 CONTINUE ONCE (evita double continue no Stasis)
        // =====================================================

        $continueOnce = function(array $event) use ($channelId) {

            try {
                if (!empty($this->channelData[$channelId]['vars']['STASIS_CONTINUED'])) {
                    return;
                }

                $this->channelData[$channelId]['vars']['STASIS_CONTINUED'] = '1';

                // marca variável no canal (best effort)
                try {
                    $this->http->post(
                        "http://{$this->ariHost}:8088/ari/channels/{$channelId}/variable",
                        [
                            'query'       => ['variable' => 'STASIS_CONTINUED', 'value' => '1'],
                            'http_errors' => false,
                        ]
                    );
                } catch (\Throwable) {}

                // continua automaticamente
                $res = $this->http->post(
                    "http://{$this->ariHost}:8088/ari/channels/{$channelId}/continue",
                    ['http_errors' => false]
                );

                $code = method_exists($res, 'getStatusCode')
                    ? $res->getStatusCode()
                    : null;

                echo "[CONTINUE] channel={$channelId} http=" . ($code ?? 'null') . "\n";

            } catch (\Throwable $e) {
                echo "[CONTINUE ⚠️] {$e->getMessage()}\n";
            }
        };


        // -----------------------------
        // Variáveis vindas do originate / dialplan
        // -----------------------------
        $ariVars = is_array($event['variables'] ?? null) ? $event['variables'] : [];

        // ✅ merge antecipado de pendingVars (evita race)
        $pending = !empty($this->pendingVars[$channelId]) && is_array($this->pendingVars[$channelId])
            ? $this->pendingVars[$channelId]
            : [];

        $allVars = array_merge($ariVars, $pending);

        // -----------------------------
        // Core vars (com suporte a __)
        // -----------------------------
        $jobId        = $allVars['JOB_ID']        ?? $allVars['__JOB_ID']        ?? null;
        $campaignId   = $allVars['CAMPAIGN_ID']   ?? $allVars['__CAMPAIGN_ID']   ?? null;
        $campaignType = $allVars['CAMPAIGN_TYPE'] ?? $allVars['__CAMPAIGN_TYPE'] ?? null;
        $techPrefix   = $allVars['TECHPREFIX']    ?? $allVars['__TECHPREFIX']    ?? null;

        // 🚀 ADICIONE ESTA LINHA AQUI PARA CAPTURAR O GATILHO DE GRAVAÇÃO
        $recordCalls  = $allVars['RECORD_CALLS']  ?? $allVars['__RECORD_CALLS']  ?? 0;

        // ✅ CALL_ID pode vir no onStart OU por VARSET
        $callId = $allVars['CALL_ID'] ?? $allVars['__CALL_ID'] ?? null;
        if (!$callId && !empty($this->channelData[$channelId]['vars']['CALL_ID'])) {
            $callId = $this->channelData[$channelId]['vars']['CALL_ID'];
        }
        $callId = $callId ? trim((string)$callId) : null;

        echo "[DEBUG] CALL_ID={$callId}\n";

        // -----------------------------
        // appArgs (mantido)
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
        // ✅ STASIS args (manual) - NÃO depende de VARSET (evita race)
        // dialplan: Stasis(app-asterisk,manual)
        // -----------------------------
        $args = $event['args'] ?? [];
        $stasisMode = strtolower((string)($args[0] ?? ''));

        if ($stasisMode === 'manual') {
            // força manual independente de variables/pendingVars
            $allVars['__IS_MANUAL']  = '1';
            $allVars['IS_MANUAL']    = '1';
            $allVars['MANUAL_CALL']  = '1';
            $allVars['__MANUAL_CALL']= '1';
        }

        // ============================================
        // 🚨 FAIL FLOW: canal entrou no Stasis só pra registrar falha e encerrar
        // ============================================
        if ($stasisMode === 'fail') {

            if (!isset($this->channelData[$channelId])) {
                $this->channelData[$channelId] = ['id' => $channelId, 'vars' => []];
            }

            $ariVars = is_array($event['variables'] ?? null) ? $event['variables'] : [];
            $pending = !empty($this->pendingVars[$channelId]) && is_array($this->pendingVars[$channelId])
                ? $this->pendingVars[$channelId]
                : [];

            $allVars = array_merge($ariVars, $pending);

            $this->channelData[$channelId]['vars'] = array_merge(
                $this->channelData[$channelId]['vars'] ?? [],
                $allVars
            );

            unset($this->pendingVars[$channelId]);

            $v    = $this->channelData[$channelId]['vars'] ?? [];
            $code = $v['CERROR_CODE'] ?? $v['__CERROR_CODE'] ?? 'FAIL';
            $msg  = "{$code}";

            // ✅ cria CDR de falha
            $this->recordImmediateFailCdr($channelId, 'CONGESTION', $msg);

            // ✅ libera o dialplan para executar Playback + Hangup
            $continueOnce($event);

            return;
        }

        // -----------------------------
        // flags
        // -----------------------------
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
        // Detecta ringall outbound (mantido)
        // -----------------------------
        $mode = null;
        $inboundFromArgs = null;
        $bridgeFromArgs  = null;

        $raw = trim($event['channel']['dialplan']['app_data'] ?? '');
        $raw = trim($raw, "\"'");

        if ($raw !== '') {
            $parts = array_map('trim', explode(',', $raw));
            $mode = $parts[0] ?? null;
            if ($mode === 'ringall') {
                $inboundFromArgs = $parts[1] ?? null;
                $bridgeFromArgs  = $parts[2] ?? null;
            }
        }

        if ($mode === 'ringall') {

            if (!isset($this->channelData[$channelId])) {
                $this->channelData[$channelId] = [
                    'id'       => $channelId,
                    'name'     => $event['channel']['name'] ?? null,
                    'peer'     => $inboundFromArgs,
                    'started'  => $now,
                    'state'    => strtolower($event['channel']['state'] ?? 'ringing'),
                    'status'   => '📞 RingAll',
                    'duration' => '00:00',
                    'vars'     => [],
                ];
            } else {
                $this->channelData[$channelId]['name'] = $event['channel']['name'] ?? ($this->channelData[$channelId]['name'] ?? null);
                $this->channelData[$channelId]['peer'] = $inboundFromArgs ?: ($this->channelData[$channelId]['peer'] ?? null);
                if (!isset($this->channelData[$channelId]['vars']) || !is_array($this->channelData[$channelId]['vars'])) {
                    $this->channelData[$channelId]['vars'] = [];
                }
                if (empty($this->channelData[$channelId]['started'])) {
                    $this->channelData[$channelId]['started'] = $now;
                }
            }

            $ramal = null;
            $nm = $event['channel']['name'] ?? '';
            if (preg_match('/PJSIP\/(\d{8})/i', $nm, $m)) {
                $ramal = $m[1];
            }

            $this->channelData[$channelId]['vars']['RINGALL']         = '1';
            $this->channelData[$channelId]['vars']['RINGALL_INBOUND'] = $inboundFromArgs;
            $this->channelData[$channelId]['vars']['RINGALL_BRIDGE']  = $bridgeFromArgs;
            $this->channelData[$channelId]['vars']['RINGALL_RAMAL']   = $ramal;

            // ✅ injeta ALLVARS aqui também (pra identity não sumir)
            $this->channelData[$channelId]['vars'] = array_merge(
                $this->channelData[$channelId]['vars'],
                $allVars
            );
            unset($this->pendingVars[$channelId]);

            echo "[RINGALL] onStart outbound {$channelId} inbound={$inboundFromArgs} bridge={$bridgeFromArgs} ramal=" . ($ramal ?: "null") . "\n";

            try {
                $this->redis->hset('discador:stasis:channels', $channelId, json_encode($this->channelData[$channelId]));
            } catch (\Throwable) {}

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
        // ✅ Inicializa/atualiza canal
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
                'started'       => $now,
                'status'        => 'Chamando...',
                'state'         => 'ringing',
                'duration'      => '00:00',
                'vars'          => [],
            ];
            echo "[📞] Novo canal iniciado: {$callerNumber} ({$channelId})\n";
        } else {
            $this->channelData[$channelId]['name']        = $event['channel']['name'] ?? ($this->channelData[$channelId]['name'] ?? null);
            $this->channelData[$channelId]['mainAudios']  = $mainAudios;
            $this->channelData[$channelId]['dtmf']        = $dtmf;
            $this->channelData[$channelId]['caller_name'] = $callerName;
            $this->channelData[$channelId]['action']      = $action;
            $this->channelData[$channelId]['play_only']   = ($action === 'play_only') ? 'play_only' : null;

            if (empty($this->channelData[$channelId]['started'])) {
                $this->channelData[$channelId]['started'] = $now;
            }
            if (!isset($this->channelData[$channelId]['vars']) || !is_array($this->channelData[$channelId]['vars'])) {
                $this->channelData[$channelId]['vars'] = [];
            }
        }

        // -----------------------------
        // Injeta explicitamente vars "core"
        // -----------------------------
        if ($jobId)        $this->channelData[$channelId]['vars']['JOB_ID'] = $jobId;
        if ($campaignId)   $this->channelData[$channelId]['vars']['CAMPAIGN_ID'] = $campaignId;
        if ($campaignType) $this->channelData[$channelId]['vars']['CAMPAIGN_TYPE'] = $campaignType;
        if ($callId)       $this->channelData[$channelId]['vars']['CALL_ID'] = $callId;
        if ($techPrefix)   $this->channelData[$channelId]['vars']['TECHPREFIX'] = $techPrefix;

        // 🚀 ADICIONE ESTA LINHA PARA GUARDAR NO ESTADO DO CANAL
        $this->channelData[$channelId]['vars']['RECORD_CALLS'] = $recordCalls;


        // ✅ merge geral (ari + pending)
        $this->channelData[$channelId]['vars'] = array_merge(
            $this->channelData[$channelId]['vars'] ?? [],
            $allVars
        );
        unset($this->pendingVars[$channelId]);

        // ==========================================================
        // ✅ Copia identidade pro topo (SSE filtra por isso)
        // ==========================================================
        $v = $this->channelData[$channelId]['vars'] ?? [];

        $this->channelData[$channelId]['owner_id'] = $this->channelData[$channelId]['owner_id']
            ?? $v['OWNER_ID'] ?? $v['__OWNER_ID'] ?? null;

        $this->channelData[$channelId]['tenant_id'] = $this->channelData[$channelId]['tenant_id']
            ?? $v['TENANT_ID'] ?? $v['__TENANT_ID'] ?? null;

        $this->channelData[$channelId]['role'] = $this->channelData[$channelId]['role']
            ?? $v['ROLE'] ?? $v['__ROLE'] ?? null;

        // -----------------------------
        // ✅ Contexto por CALL_ID (mantido)
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
                        'ts'             => $now,
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
            $this->channelData[$channelId]['is_manual'] = true;
            $this->channelData[$channelId]['manual_a_only'] = true;
            $this->channelData[$channelId]['do_not_cdr'] = true;

            $this->channelData[$channelId]['vars']['IS_MANUAL'] = '1';
            $this->channelData[$channelId]['vars']['MANUAL_A_ONLY'] = '1';
            $this->channelData[$channelId]['vars']['DO_NOT_CDR'] = '1';

            echo "[MANUAL] A-only (não publicar / não tarifar) channel={$channelId}\n";
            $continueOnce($event);
            return;
        }

        if ($isPreDial) {
            echo "[PREDIAL] channel={$channelId} -> continue(noquery)\n";

            try {
                $this->http->post(
                    "http://{$this->ariHost}:8088/ari/channels/{$channelId}/variable",
                    [
                        'query'       => ['variable' => 'ARI_B_READY', 'value' => '1'],
                        'http_errors' => false,
                    ]
                );
            } catch (\Throwable) {}

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

        // Atualiza no Redis após atender
        try {
            $this->redis->hset('discador:stasis:channels', $channelId, json_encode($this->channelData[$channelId]));
        } catch (\Throwable) {}
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
        // 1. Pegamos o dígito e garantimos que seja tratado como string
        $digit     = isset($event['digit']) ? (string)$event['digit'] : null;
        $channelId = $event['channel']['id'] ?? null;

        // 2. Mudança CRÍTICA: Verificamos se é nulo.
        // Se for "0", a condição ($digit === null) será FALSA e o código CONTINUA.
        if ($digit === null || $channelId === null) {
            return;
        }

        $now = time();

        // ============================================================
        // 🔹 Registro inicial de DTMF
        // ============================================================
        $this->channelData[$channelId]['dtmf_received'] = true;
        $this->channelData[$channelId]['last_dtmf']      = $digit;

        // --- ADICIONE ESTA CHAMADA AQUI ---
        $this->persistDtmfToGlobalRedis($channelId, $digit);

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

    private function persistDtmfToGlobalRedis(string $channelId, string $digit): void
    {
        try {
            $raw = $this->redis->get('asterisk:active_calls');
            $data = $raw ? json_decode($raw, true) : ['chamadas' => []];

            $changed = false;
            if (isset($data['chamadas']) && is_array($data['chamadas'])) {
                foreach ($data['chamadas'] as &$call) {
                    // No seu foreach dentro do PHP:
                    if (explode('.', $call['id'])[0] === explode('.', $channelId)[0]) {

                        // Usamos (string) para garantir que o 0 não seja lido como nulo/vazio
                        $valorAtual = isset($call['dtmf']) ? (string)$call['dtmf'] : '';

                        // Concatena o novo dígito (também como string)
                        $call['dtmf'] = $valorAtual . (string)$digit;

                        $changed = true;
                    }
                }
            }

            if ($changed) {
                $this->redis->set('asterisk:active_calls', json_encode($data));
            }
        } catch (\Throwable $e) {
            echo "[REDIS-DTMF] Erro ao persistir: " . $e->getMessage() . "\n";
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
        $targetUri = $event['playback']['target_uri'] ?? '';
        $channelId = str_replace('channel:', '', $targetUri);

        if (!$channelId) {
            return;
        }

        echo "[🎵] Playback finalizado no canal {$channelId}\n";

        // =============================================================
        // 🔊 LÓGICA PARA LIMPAR ÍCONE DE ÁUDIO (SNOOP)
        // =============================================================
        if (isset($this->channelData[$channelId]['is_snoop'])) {
            $parentId = $this->channelData[$channelId]['parent_id'] ?? null;

            // Se encontrarmos o canal pai (o ramal), removemos a flag de áudio
            if ($parentId && isset($this->channelData[$parentId])) {
                unset($this->channelData[$parentId]['audio_executando']);
                echo "[Redis] 🧹 Áudio finalizado: Removendo ícone do ramal pai {$parentId}\n";
            }

            // Como o Snoop só servia para o áudio, podemos limpá-lo aqui também
            unset($this->channelData[$channelId]);
            $this->updateRedis(); // Atualiza o Dashboard imediatamente
            return;
        }

        // =============================================================
        // FLUXO ORIGINAL (Para chamadas do discador/URA)
        // =============================================================
        if (empty($this->channelData[$channelId])) {
            return;
        }

        $data = &$this->channelData[$channelId];
        $action = $data['action'] ?? null;

        // Limpeza de variáveis de controle de fluxo
        unset($data['current_playback'], $data['handled_dtmf']);

        if (($data['play_only'] ?? null) === 'play_only') {
            unset($data['play_only']);
            echo "[PLAY_ONLY] 🎧 Encerrando canal principal {$channelId}\n";
            $this->ChannelFinishDestroyed($channelId, 'Encerrada após áudio', true);
            return;
        }

        if (in_array($action, ['dtmf', 'transfer_only'], true)) {
            return;
        }
    }


    private function handleDialEvent(array $event): void
    {
        $dialStatus = strtoupper(trim($event['dialstatus'] ?? ''));
        $dialString = $event['dialstring'] ?? '';

        $originId = $event['channel']['id'] ?? null;   // canal origem (ramal ou local/sub-rotina)
        $peerId   = $event['peer']['id'] ?? null;      // canal tronco (PJSIP/mxx...)

        // PATCH — salva nome do canal origem e do peer
        if ($originId) {
            $this->channelData[$originId]['linkedid'] = $event['channel']['linkedid'] ?? null;
        }

        if ($peerId) {
            $this->channelData[$peerId]['linkedid'] = $event['peer']['linkedid'] ?? null;
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
                $this->channelData[$chanId]['status'] = '⛔ Ocupado';
                $this->channelData[$chanId]['state'] = 'busy';
                $sipDetails = $this->getSipCause($peerId ?? $originId ?? $chanId);
                $this->recordImmediateFailCdr($chanId, 'BUSY', $sipDetails);
                break;

            case 'CONGESTION':
                $this->channelData[$chanId]['status'] = '⚠️ Congestion';
                $this->channelData[$chanId]['state'] = 'congestion';
                $sipDetails = $this->getSipCause($peerId ?? $originId ?? $chanId);
                $this->recordImmediateFailCdr($chanId, 'CONGESTION', $sipDetails);
                break;

            case 'NOANSWER':
                $this->channelData[$chanId]['status'] = '❌ Não Atendida';
                $this->channelData[$chanId]['state'] = 'noanswer';
                $sipDetails = $this->getSipCause($peerId ?? $originId ?? $chanId);
                $this->recordImmediateFailCdr($chanId, 'NOANSWER', $sipDetails);
                break;

            case 'CANCEL':
                $this->channelData[$chanId]['status'] = '🚫 Cancelada';
                $this->channelData[$chanId]['state'] = 'canceled';
                $sipDetails = $this->getSipCause($peerId ?? $originId ?? $chanId);
                $this->recordImmediateFailCdr($chanId, 'CANCEL', $sipDetails);
                break;

            default:
                $this->channelData[$chanId]['status'] = '📞 Ringing...';
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

            if (!empty($this->channelData[$chanId]['pending_fail_cdr'])) {
                $p = $this->channelData[$chanId]['pending_fail_cdr'];
                $this->recordImmediateFailCdr($chanId, $p['dialstatus'] ?? $p['status'] ?? $dialStatus, $p['msg'] ?? null);
            } else {
                $sipDetails = $this->getSipCause($peerId ?? $originId ?? $chanId);
                $this->recordImmediateFailCdr($chanId, $dialStatus, $sipDetails);
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

    private function shouldIgnoreCdr(string $channelId): bool
    {
        $call = $this->channelData[$channelId] ?? [];
        $vars = $call['vars'] ?? [];
        $pending = $this->pendingVars[$channelId] ?? [];

        return
            !empty($call['do_not_cdr']) ||
            !empty($call['manual_a_only']) ||
            !empty($vars['DO_NOT_CDR']) ||
            !empty($vars['MANUAL_A_ONLY']) ||
            !empty($vars['__MANUAL_A_ONLY']) ||
            !empty($pending['DO_NOT_CDR']) ||
            !empty($pending['MANUAL_A_ONLY']) ||
            !empty($pending['__MANUAL_A_ONLY']);
    }

    /**
     * Busca a causa real no Asterisk para evitar erros estáticos
     */
    private function getSipCause(string $chanId): array
    {
        $isdnToSip = [
            1   => ['code' => '404', 'txt' => 'Number Not Found'],
            3   => ['code' => '404', 'txt' => 'No Route'],
            16  => ['code' => '200', 'txt' => 'Normal Clearing'],
            17  => ['code' => '486', 'txt' => 'Busy'],
            18  => ['code' => '408', 'txt' => 'No Response (Timeout)'],
            19  => ['code' => '480', 'txt' => 'No Answer'],
            21  => ['code' => '403', 'txt' => 'Rejected (IP/Auth)'],
            27  => ['code' => '502', 'txt' => 'Destination Out of Order'],
            28  => ['code' => '484', 'txt' => 'Invalid Number Format'],
            34  => ['code' => '503', 'txt' => 'Circuit Congestion'],
            38  => ['code' => '503', 'txt' => 'Network Outage'],
            41  => ['code' => '503', 'txt' => 'Temporary Failure'],
            102 => ['code' => '408', 'txt' => 'Protocol Timeout'],
        ];

        $sipMap = [
            '403' => 'Forbidden',
            '404' => 'Not Found',
            '408' => 'Timeout',
            '480' => 'Temporarily Unavailable',
            '484' => 'Address Incomplete',
            '486' => 'Busy',
            '500' => 'Server Error',
            '502' => 'Bad Gateway',
            '503' => 'Service Unavailable',
            '603' => 'Declined',
        ];

        $varsToTry = ['PJSIP_RESPONSE_CODE', 'HANGUPCAUSE'];

        foreach ($varsToTry as $varName) {
            try {
                $response = $this->http->get("channels/{$chanId}/variable", [
                    'query' => ['variable' => $varName],
                    'timeout' => 0.2
                ]);

                $val = json_decode((string)$response->getBody(), true)['value'] ?? '';

                if ($val === '' || $val === '0' || $val === null) {
                    continue;
                }

                if ($varName === 'PJSIP_RESPONSE_CODE') {
                    return [
                        'code' => (string)$val,
                        'txt'  => $sipMap[(string)$val] ?? 'SIP Error'
                    ];
                }

                if ($varName === 'HANGUPCAUSE') {
                    $hangup = (int)$val;
                    if (isset($isdnToSip[$hangup])) {
                        return $isdnToSip[$hangup];
                    }
                }

                return [
                    'code' => (string)$val,
                    'txt'  => $varName
                ];
            } catch (\Throwable $e) {
                continue;
            }
        }

        $ariCause = (int)($this->channelData[$chanId]['cause'] ?? 0);

        if ($ariCause > 0 && isset($isdnToSip[$ariCause])) {
            return $isdnToSip[$ariCause];
        }

        return ['code' => '503', 'txt' => 'Service Unavailable'];
    }

    private function recordImmediateFailCdr($chanOrEvent, string $status = '', $msg = null): void
    {
        try {
            $chanId = is_array($chanOrEvent)
                ? ($chanOrEvent['peer']['id'] ?? $chanOrEvent['channel']['id'] ?? null)
                : $chanOrEvent;

            if (!$chanId) {
                return;
            }

            $chan = $this->channelData[$chanId] ?? [];

            if ($this->shouldIgnoreCdr($chanId)) {
                error_log("[CDR] 🚫 Ignorando FAIL-CDR de canal não tarifável: {$chanId}");
                return;
            }

            $vars = $chan['vars'] ?? [];

            $failKey = "cdr-falha-pendente:{$chanId}";
            $dupKey  = "cdr-saved:{$chanId}";

            if ($this->redis->exists($dupKey)) {
                error_log("[CDR] ⏭️ FAIL-CDR já salvo anteriormente ({$chanId})");
                return;
            }

            $sipCode = '503';
            $statusMsg = 'Desconhecida';
            $isdnCause = null;

            if (is_array($msg)) {
                $sipCode   = (string)($msg['code'] ?? '503');
                $statusMsg = (string)($msg['txt'] ?? 'Erro');
                $isdnCause = isset($msg['isdn_cause']) ? (int)$msg['isdn_cause'] : null;
            } elseif (is_string($msg) && $msg !== '') {
                $statusMsg = $msg;
            } elseif ($status !== '') {
                $statusMsg = 'Falha: ' . strtoupper($status);
            }

            $payload = [
                'dialstatus'  => strtoupper($status),
                'sip_code'    => $sipCode,
                'cause'       => $isdnCause ?? ($chan['cause'] ?? null),
                'cause_txt'   => $statusMsg,
                'msg'         => $statusMsg,
                'started'     => $chan['started'] ?? time(),
                'ended'       => time(),
                'number'      => $chan['number'] ?? ($vars['CALLERID(num)'] ?? null),
                'destination' => $chan['destination'] ?? ($chan['dialstring'] ?? null),

                'job_id' => $vars['JOB_ID']
                    ?? ($chan['job_id'] ?? null)
                        ?? ($this->pendingVars[$chanId]['JOB_ID'] ?? null),

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
                'trunk'            => $vars['TRUNK'] ?? ($this->pendingVars[$chanId]['TRUNK'] ?? null),
                'trunk_id'         => $vars['TRUNK_ID'] ?? ($this->pendingVars[$chanId]['TRUNK_ID'] ?? ($vars['TRUNK'] ?? null)),
                'techprefix'       => $vars['TECHPREFIX'] ?? null,
                'taxa_of_service'  => 0,
                'owner_id'         => $vars['OWNER_ID'] ?? null,
                'tenant_id'        => $vars['TENANT_ID'] ?? null,

                'application' => 'app-asterisk',
            ];

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

            $this->redis->setex($failKey, 180, json_encode($payload, JSON_UNESCAPED_UNICODE));
            $this->channelData[$chanId]['pending_fail_cdr'] = $payload;

            if (empty($vars['OWNER_ID']) || empty($vars['TENANT_ID'])) {
                error_log("[CDR] ⏳ FAIL-CDR aguardando VARSET canal={$chanId}");
            } else {
                error_log("[CDR] 🟡 FAIL-CDR criou evento pendente (VARS presentes), aguardando Destroyed");
            }

        } catch (\Throwable $e) {
            error_log("[CDR] ⚠️ Erro ao registrar FAIL-CDR: " . $e->getMessage());
        }
    }

    private function trySavePendingFailCdr(string $channelId): bool
    {
        $call = $this->channelData[$channelId] ?? null;
        if (!$call) {
            error_log("[CDR] ⚠ trySavePendingFailCdr sem channelData para {$channelId}");
            return false;
        }

        if ($this->shouldIgnoreCdr($channelId)) {
            error_log("[CDR] 🚫 Ignorando consolidação de FAIL-CDR de canal não tarifável: {$channelId}");
            $this->redis->del("cdr-falha-pendente:{$channelId}");
            unset($this->channelData[$channelId]['pending_fail_cdr']);
            return true;
        }

        $failKey  = "cdr-falha-pendente:{$channelId}";
        $failJson = $this->redis->get($failKey);

        $failEvent = $call['pending_fail_cdr'] ?? null;
        if (!$failEvent && $failJson) {
            $failEvent = json_decode($failJson, true) ?: null;
        }
        if (!$failEvent) return false;

        $dialStatus = strtoupper((string)($failEvent['dialstatus'] ?? ($failEvent['status'] ?? 'FAILED')));

        if ($dialStatus === 'PROGRESS') {
            error_log("[CDR] ⏳ FAIL-CDR ainda em PROGRESS canal={$channelId}");
            $payloadToKeep = $failJson ?: json_encode($failEvent, JSON_UNESCAPED_UNICODE);
            $this->redis->setex($failKey, 180, $payloadToKeep);
            return false;
        }

        $vars = $call['vars'] ?? [];
        $pending = (!empty($this->pendingVars[$channelId]) && is_array($this->pendingVars[$channelId]))
            ? $this->pendingVars[$channelId] : [];

        $ownerId  = $vars['OWNER_ID']  ?? ($pending['OWNER_ID']  ?? null);
        $tenantId = $vars['TENANT_ID'] ?? ($pending['TENANT_ID'] ?? null);

        if (empty($ownerId) || empty($tenantId)) {
            $payloadToKeep = $failJson ?: json_encode($failEvent, JSON_UNESCAPED_UNICODE);
            $this->redis->setex($failKey, 180, $payloadToKeep);
            return false;
        }

        $type = strtolower(
            (string)($failEvent['variable_type']
                ?? $vars['VARIABLE_TYPE']
                ?? $pending['VARIABLE_TYPE']
                ?? $vars['TYPE']
                ?? $pending['TYPE']
                ?? 'normal'
            )
        );

        $jobId = $failEvent['job_id']
            ?? $vars['JOB_ID']
            ?? $pending['JOB_ID']
            ?? null;

        $techprefix = $failEvent['techprefix']
            ?? $vars['TECHPREFIX']
            ?? $pending['TECHPREFIX']
            ?? null;

        $trunkName = $failEvent['trunk']
            ?? $vars['TRUNK']
            ?? $pending['TRUNK']
            ?? null;

        $trunkId = $failEvent['trunk_id']
            ?? $vars['TRUNK_ID']
            ?? $pending['TRUNK_ID']
            ?? $trunkName;

        $campaignId = $failEvent['campaign_id']
            ?? $vars['CAMPAIGN_ID']
            ?? $pending['CAMPAIGN_ID']
            ?? null;

        $campaignType = $failEvent['campaign_type']
            ?? $vars['CAMPAIGN_TYPE']
            ?? $pending['CAMPAIGN_TYPE']
            ?? null;

        $minuteCost    = (float)($failEvent['call_minute_cost'] ?? ($vars['CALL_MINUTE_COST'] ?? 0));
        $smsCost       = (float)($failEvent['sms_cost'] ?? ($vars['SMS_COST'] ?? 0));
        $torpedoCost   = (float)($failEvent['torpedo_cost'] ?? ($vars['TORPEDO_COST'] ?? 0));
        $taxaOfService = (float)($failEvent['taxa_of_service'] ?? ($vars['TAXA_OF_SERVICE'] ?? 0) ?? 0);

        $firstNonEmpty = function (...$vals) {
            foreach ($vals as $v) {
                if (is_string($v)) $v = trim($v);
                if ($v !== null && $v !== '') return $v;
            }
            return '—';
        };

        $number = $call['number'] ?? null
            ?? $vars['__ENDPOINT'] ?? null
            ?? $vars['__RAMAL'] ?? null
            ?? $vars['CALLERID(num)'] ?? null
            ?? $pending['CALLERID(num)'] ?? null
            ?? ($call['caller_number'] ?? null)
            ?? $vars['__OUT_CLI'] ?? null
            ?? $vars['OUT_CLI'] ?? null
            ?? '-';

        $exten = $call['last_dial_event']['caller']['dialplan']['exten'] ?? null;

        $destination = $firstNonEmpty(
            $exten,
            $call['destination'] ?? null,
            $call['dialstring'] ?? null,
            $vars['ORIGINAL_DEST'] ?? null
        );

        $endpoint = $vars['__ENDPOINT'] ?? $vars['OUT_CLI'] ?? '-';

        $hangupCause = (int)($firstNonEmpty(
            $failEvent['cause'] ?? null,
            $failEvent['hangupcause'] ?? null,
            $vars['FAIL_CAUSE'] ?? null,
            $vars['__FAIL_CAUSE'] ?? null,
            $vars['__HANGUPCAUSE'] ?? null,
            $pending['FAIL_CAUSE'] ?? null,
            $pending['__FAIL_CAUSE'] ?? null,
            $pending['__HANGUPCAUSE'] ?? null,
            $call['cause'] ?? null
        ) ?: 0);

        $sipCode = (string)($firstNonEmpty(
            $failEvent['sip_code'] ?? null,
            $call['sip_code'] ?? null,
            '503'
        ));

        $causeText = $firstNonEmpty(
            $failEvent['msg'] ?? null,
            $failEvent['cause_txt'] ?? null,
            $call['cause_txt'] ?? null
        );

        $started = (int)($failEvent['started'] ?? ($call['started'] ?? time()));
        $ended   = (int)($failEvent['ended'] ?? ($call['ended'] ?? time()));

        $cdr = [
            'channel_id'        => $channelId,
            'job_id'            => $jobId,
            'campaign_id'       => $campaignId,
            'campaign_type'     => $campaignType,
            'owner_id'          => $ownerId,
            'tenant_id'         => $tenantId,
            'number'            => $number,
            'endpoint'          => $endpoint,
            'destination'       => $destination,
            'type'              => $type,
            'state'             => 'failed',
            'dialstatus'        => $dialStatus,
            'cause'             => $hangupCause,
            'sip_code'          => $sipCode,
            'cause_txt'         => $causeText,
            'trunk'             => $trunkName,
            'trunk_id'          => $trunkId,
            'techprefix'        => $techprefix,
            'duration'          => 0,
            'value'             => '0.0000',
            'taxa_of_service'   => $taxaOfService,
            'call_minute_cost'  => $minuteCost,
            'sms_cost'          => $smsCost,
            'torpedo_cost'      => $torpedoCost,
            'started'           => $started,
            'ended'             => $ended,
            'timestamp'         => date('Y-m-d H:i:s'),
            'application'       => $failEvent['application'] ?? 'app-asterisk',
        ];

        $linkedId = $this->channelData[$channelId]['linkedid']
            ?? $this->pendingVars[$channelId]['linkedid']
            ?? $channelId;

        $dupKey = "cdr-saved:{$linkedId}";

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
        // 🚫 Evita duplicação (memória)
        // ===================================================
        if (!empty($call['tariff_done'])) {
            error_log("[CDR] ⏭ Já tarifado (memória) canal={$channelId}");
            return;
        }

        // ===================================================
        // ✅ MANUAL: detectar manual + ignorar A-leg
        // ===================================================
        $vars = $call['vars'] ?? [];

        $isManual = (
            !empty($vars['MANUAL_CALL']) ||
            !empty($vars['__MANUAL_CALL']) ||
            !empty($vars['IS_MANUAL']) ||
            !empty($vars['__IS_MANUAL'])
        );

        $leg = $vars['ARI_LEG'] ?? $vars['__ARI_LEG'] ?? null;
        if ($isManual && $leg === 'A') {
            error_log("[CDR] ⏭ MANUAL A-leg ignorado no calculateTariff canal={$channelId}");
            return;
        }

        // ===================================================
        // 🚫 Evita duplicação (Redis) — sempre por canal
        // ===================================================
        $dupKey = "tarifacao:ja_registrada:{$channelId}";

        // ✅ CALL_ID existe, mas dedupe por CALL_ID só para MANUAL
        $callId = $vars['CALL_ID'] ?? null;
        $callId = $callId ? trim((string)$callId) : null;

        $dupKeyCall = ($isManual && $callId)
            ? "tarifacao:ja_registrada:call:{$callId}"
            : null;

        if ($this->redis->exists($dupKey) || ($dupKeyCall && $this->redis->exists($dupKeyCall))) {
            error_log("[CDR] ⏭ Já tarifado (Redis) canal={$channelId}" . ($dupKeyCall ? " call_id={$callId}" : ""));
            return;
        }

        if (empty($call['ended'])) {
            error_log("[CDR] ❌ Ignorado — chamada não finalizada canal={$channelId}");
            return;
        }

        // ===================================================
        // 🔸 Extrai variáveis (continua como era)
        // ===================================================
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
        $minuteCost    = isset($vars['CALL_MINUTE_COST']) ? (float)$vars['CALL_MINUTE_COST'] : 0.0;
        $smsCost       = isset($vars['SMS_COST'])         ? (float)$vars['SMS_COST']         : 0.0;
        $torpedoCost   = isset($vars['TORPEDO_COST'])     ? (float)$vars['TORPEDO_COST']     : 0.0;
        $taxaOfService = isset($vars['TAXA_OF_SERVICE'])  ? (float)$vars['TAXA_OF_SERVICE']  : 0.0;


        // ✅ CID (caller) — prioriza OUT_CLI / CALLERID_NUM (dialplan)
        $number =
            $vars['OUT_CLI']
            ?? $vars['CALLERID_NUM']
            ?? $vars['CALLERID(num)']
            ?? ($call['number'] ?? null);

        $endpoint = $vars['__RAMAL']  ?? $vars['__ENDPOINT'] ?? $vars['OUT_CLI'] ?? '-';

        // ✅ DESTINO — prioriza DESTINATION / DIAL_DEST (dialplan)
        $destination =
            $vars['DESTINATION']
            ?? $vars['DIAL_DEST']
            ?? $vars['__DIAL_DEST']
            ?? $vars['EXTENSION']
            ?? ($call['destination'] ?? null)
            ?? ($call['dialstring'] ?? null);

        $state = $call['state'] ?? 'unknown';


        // ===================================================
        // 🔹 CAUSA FINAL (Q.850)
        // ===================================================

        // 0) pega a causa do call e pode ser sobrescrita pelo Redis
        $cause     = $call['cause']     ?? null;
        $causeText = $call['cause_txt'] ?? null;

        $redisCause = $this->redis->hget("asterisk:causes", $channelId);
        if ($redisCause) {
            $parsed = json_decode($redisCause, true);
            if (is_array($parsed)) {
                $cause     = $parsed['cause']     ?? $cause;
                $causeText = $parsed['cause_txt'] ?? $causeText;
            }
        }

        // 1) normaliza para int (se vier null/'' vira 0)
        $cause = (int)($cause ?? 0);

        // 2) Converter SIP -> Q.850 (não misturar chaves)
        $sipToQ850 = [
            486 => 17, // Busy Here -> USER_BUSY
            487 => 16, // Request Terminated -> NORMAL_CLEARING (ou 31 se você preferir)
            603 => 21, // Decline -> CALL_REJECTED
        ];

        if (isset($sipToQ850[$cause])) {
            $cause = $sipToQ850[$cause];
        }

        // 3) map apenas Q.850
        $causeMap = [
            16 => ['NORMAL_CLEARING',      'ANSWER',      'Normal Clearing'],
            17 => ['USER_BUSY',            'BUSY',        'Ocupado'],
            18 => ['NO_USER_RESPONDING',   'NOANSWER',    'Sem resposta'],
            19 => ['NO_ANSWER',            'NOANSWER',    'Não Atendida'],
            21 => ['CALL_REJECTED',        'CANCEL',      'Rejeitada'],
            28 => ['INVALID_NUMBER_FORMAT','FAILED',      'Número inválido'],
            34 => ['CONGESTION',           'CONGESTION',  'Congestionamento'],
            41 => ['TEMPORARY_FAILURE',    'FAILED',      'Falha temporária'],
            47 => ['RESOURCE_UNAVAILABLE', 'FAILED',      'Recurso indisponível'],
            0  => ['UNKNOWN',              'FAILED',      'Desconhecido'],
        ];

        // 4) se atendeu, força sucesso
        if (!empty($call['answered'])) {
            $cause     = 16;
            $causeName = 'NORMAL_CLEARING';
            $dialStatus= 'ANSWER';
            $causeText = 'Normal Clearing';
        } else {
            [$causeName, $dialStatus, $mapText] = $causeMap[$cause] ?? $causeMap[0];

            // se já veio um texto do Redis/call, mantém; senão usa o do map
            $causeText = $causeText ?: $mapText;

            // se seu call já trouxe dialstatus, você pode manter como fallback:
            $dialStatus = strtoupper($call['dialstatus'] ?? $dialStatus ?? 'FAILED');
        }



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

        if (empty($answered)) {
            error_log("[CDR] ⏭ calculateTariff ignorado para canal sem ANSWER (canal={$channelId})");
            return;
        }

        // ===================================================
        // 🔥 DETECÇÃO DE RAMAL (6–8 dígitos)
        // ===================================================
        $chName = strtolower($call['name'] ?? '');
        $isRamal = preg_match('/^pjsip\/([0-9]{6,8})-/i', $chName, $match);

        if ($isRamal && !$isManual) {
            $taxaOfService = 0.0;
        }

        $channelNumber = $match[1] ?? null;

        // ===================================================
        // 🔸 Cálculo de custo
        // ===================================================
        $durationSec = 0;
        $cost = 0;

        $durationSec = max(0, $ended - $answered);

        if ($durationSec > 0) {

            if ($type === 'torpedo') {
                if (!$isRamal) {
                    $cost = $this->calculateTorpedoTariff($durationSec, $torpedoCost, $minuteCost);
                }

            } elseif ($type === 'outbound') {
                $cost = $this->calculateNormalTariff($durationSec, $minuteCost);

            } elseif (!$isRamal) {
                $cost = $this->calculateNormalTariff($durationSec, $minuteCost);
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
            'endpoint'         => $endpoint,
            'destination'      => $destination,
            'channelNumber'    => $channelNumber,
            'type'             => $type,
            'state'            => $state,
            'dialstatus'       => $dialStatus,
            'cause'            => $cause,
            'cause_txt'        => $causeText,
            'sip_code'         => !empty($call['answered']) ? 200 : null,
            'trunk'            => $vars['TRUNK'] ?? $vars['__TRUNK'] ?? null,
            'trunk_id'         => $vars['TRUNK_ID'] ?? $vars['__TRUNK_ID'] ?? ($vars['TRUNK'] ?? null),
            'techprefix'       => $vars['TECHPREFIX'] ?? $vars['__TECHPREFIX'] ?? null,
            'duration'         => $durationSec,
            'billsec'          => $durationSec,
            'value'            => number_format($cost, 4, '.', ''),
            'taxa_of_service'  => number_format($taxaOfService, 4, '.', ''),
            'call_minute_cost' => $minuteCost,
            'sms_cost'         => $smsCost,
            'torpedo_cost'     => $torpedoCost,
            'started'          => $started,
            'answered'         => $answered,
            'ended'            => $ended,
            'timestamp'        => date('Y-m-d H:i:s'),
            'application'      => 'app-asterisk',
        ];

        $this->redis->rpush('asterisk:tarifacoes', json_encode($cdr, JSON_UNESCAPED_UNICODE));

        // ✅ sempre dedupe por canal
        $this->redis->setex($dupKey, 180, 1);

        // ✅ somente manual grava dedupe por call_id
        if ($dupKeyCall) {
            $this->redis->setex($dupKeyCall, 180, 1);
        }

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
                //$destination = $c['destination'] ?? null;
                $finalDestination = $destination ?? $dest ?? $extension;

                // 🔥 remove techprefix se existir
                $techPrefix = $vars['TECHPREFIX'] ?? null;

                if ($techPrefix && str_starts_with($finalDestination, $techPrefix)) {
                    $finalDestination = substr($finalDestination, strlen($techPrefix));
                }

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
                    'destination' => $finalDestination,
                    'status'      => $c['status'] ?? ($c['state'] ?? '—'),
                    'audio_executando' => $c['audio_executando'] ?? false,
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
$listener = new StasisListenerAsterisk(
    TelephonyConfig::ariUser(),
    TelephonyConfig::ariPass(),
    TelephonyConfig::ariHost(),
    TelephonyConfig::stasisApp()
);
$listener->run();
