<?php

namespace App\Controller\Pages;

use App\Config\TelephonyConfig;
use App\Http\Response;
use App\Model\Entity\CampaignVoice;
use App\Model\Entity\CdrVoice;
use App\Model\Entity\CallCenterQueues;
use App\Model\Entity\PauseConfig;
use App\Model\Entity\PauseLog;
use App\Model\Entity\RegisterTenancies;
use App\Service\AgentManager;
use App\Session\User as SessionUser;
use App\Utils\View;

class Callcenter extends ViewComponents
{

    public static function getComponentsAgents(): Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/callcenter/agents', []);
        return parent::getComponentsUsers('Maxx Solutions - Voz | Agentes', $content);
    }

    public static function getComponentsQueues(): Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/callcenter/queues', []);
        return parent::getComponentsUsers('Maxx Solutions - SMS | VOZ', $content);
    }

    public static function getComponentsBreaks(): Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/callcenter/breaks', []);
        return parent::getComponentsUsers('Maxx Solutions - SMS | VOZ', $content);
    }

    public static function getComponentsMonitoring(): Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/callcenter/monitoring', []);
        return parent::getComponentsUsers('Maxx Solutions - SMS | VOZ', $content);
    }

    public static function getComponentsCallCenterReports(): Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/callcenter/reports', []);
        return parent::getComponentsUsers('Maxx Solutions - SMS | VOZ', $content);
    }

    public static function getMonitoringAgentsData($request): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            echo "event: auth\ndata: " . json_encode(['status' => 401]) . "\n\n";
            flush();
            return;
        }

        $ariHost = TelephonyConfig::ariHost();

        try {
            $agentManager = new AgentManager([
                'ari_host' => $ariHost,
                'ari_auth' => TelephonyConfig::ariAuth()
            ]);

            $redis = $agentManager->getRedis();
            $redisTime = $redis->time();
            $now = is_array($redisTime) ? (int)$redisTime[0] : time();

            // 1. DADOS DE USUÁRIO E LÓGICA DE FILTRO
            $userId  = (int)($obUser['id'] ?? 0);
            $role    = strtolower((string)($obUser['user_function'] ?? $obUser['function'] ?? ''));
            $tenancy = (string)($obUser['tenancy_id'] ?? '');

            $filters = [
                'user_role' => $role
            ];
            $ownerId = null;

            if ($role === 'super_admin') {
                $filters = [];
                $ownerId = null;
            } elseif ($role === 'admin') {
                $filters['tenancy_id'] = $tenancy;
                $ownerId = null; // admin vê tudo da tenancy
            } else {
                $filters['tenancy_id'] = $tenancy;
                $filters['user_id']    = $userId;
                $ownerId = $userId; // reseller vê sua equipe via helper; agente vê só ele
            }

            // 2. BUSCA DE EXTENSÕES
            $apiSip = new AsteriskExtensionsSip();
            $sipQuery = ['function' => $role];

            if ($role !== 'super_admin') {
                $sipQuery['tenant_id'] = $tenancy;
            }

            if (!in_array($role, ['super_admin', 'admin'], true)) {
                $sipQuery['user_id'] = $userId;
            }

            $resSip = $apiSip->listExtensions($sipQuery);
            $extensions = $resSip['ok'] ? ($resSip['data']['data'] ?? $resSip['data'] ?? []) : [];

            $filteredExtensions = array_filter($extensions, function ($sip) use ($role, $tenancy, $userId) {
                if ($role === 'super_admin' || $role === 'root') {
                    return true;
                }

                if ($role === 'admin') {
                    $sipTenant = (string)($sip['tenant_id'] ?? $sip['tenancy_id'] ?? '');
                    return $sipTenant === (string)$tenancy;
                }

                $sipUserId = (int)($sip['user_id'] ?? $sip['owner_id'] ?? 0);
                return $sipUserId === (int)$userId;
            });

            $endpoints = array_values(array_filter(array_map(
                fn($e) => (string)($e['username'] ?? $e['extension'] ?? ''),
                $filteredExtensions
            )));

            $agentManager->addFromPayload($endpoints, false);

            // 3. PROCESSAMENTO DE CHAMADAS ATIVAS
            $rawCallsJson = $redis->get('asterisk:active_calls');
            $calls = ($rawCallsJson ? json_decode($rawCallsJson, true) : [])['chamadas'] ?? [];
            $calls = self::hydrateDtmfAcrossRelatedCalls(is_array($calls) ? $calls : []);

            $chamadasFinal = [];
            $tempoConversaPorRamal = [];
            $countTalking = 0;
            $countQueue = 0;
            static $campanhasCache = [];

            foreach ($calls as $call) {
                $vars = $call['vars'] ?? [];

                if ($role !== 'super_admin') {
                    if ($role === 'admin' && (string)($vars['TENANT_ID'] ?? '') !== (string)$tenancy) {
                        continue;
                    }

                    if (!in_array($role, ['super_admin', 'admin'], true)
                        && (string)($vars['OWNER_ID'] ?? '') !== (string)$userId) {
                        continue;
                    }
                }

                $state = strtolower((string)($call['state'] ?? ''));
                $isUp = ($state === 'up');

                if ($isUp) {
                    $countTalking++;
                } else {
                    $countQueue++;
                }

                $baseTime = !empty($call['answered'])
                    ? (int)$call['answered']
                    : (int)($call['started'] ?? $now);

                $duration = max(0, $now - $baseTime);
                $duracaoFormatada = gmdate('H:i:s', $duration);

                $ramalDaChamada = $vars['AGENT_RAMAL'] ?? $call['number'] ?? null;
                if ($isUp && $ramalDaChamada) {
                    $tempoConversaPorRamal[(string)$ramalDaChamada] = $duracaoFormatada;
                }

                $campaignId = $vars['CAMPAIGN_ID'] ?? null;
                $voiceListId = $vars['VOICE_LIST_ID'] ?? null;
                $nomeExibicao = 'Chamada Manual';

                if ($campaignId) {
                    if (!isset($campanhasCache[$campaignId])) {
                        $campanhaData = CampaignVoice::getById((int)$campaignId);
                        $campanhasCache[$campaignId] = $campanhaData
                            ? ($campanhaData['name'] ?? "Camp. #{$campaignId}")
                            : "Camp. #{$campaignId}";
                    }
                    $nomeExibicao = $campanhasCache[$campaignId];
                }

                $chamadasFinal[] = self::sanitizeMonitoringCall([
                    'id'            => $call['id'] ?? '',
                    'peer'          => $call['peer'] ?? null,
                    'linkedid'      => $call['linkedid'] ?? null,
                    'call_id'       => $call['call_id'] ?? ($vars['CALL_ID'] ?? $vars['__CALL_ID'] ?? null),
                    'state_raw'     => $call['state'] ?? null,
                    'started_at'    => $call['started'] ?? null,
                    'answered_at'   => $call['answered'] ?? null,
                    'vars'          => [
                        'CALL_ID' => $vars['CALL_ID'] ?? $vars['__CALL_ID'] ?? null,
                        'AGENT_RAMAL' => $vars['AGENT_RAMAL'] ?? null,
                        'AGENT_ID' => $vars['AGENT_ID'] ?? null,
                        '__RAMAL' => $vars['__RAMAL'] ?? null,
                    ],
                    'caller'        => $call['caller'] ?? 'Privado',
                    'dest'          => $call['destination'] ?? $call['number'] ?? '',
                    'status'        => $isUp ? 'Conversando' : 'Chamando',
                    'is_up'         => $isUp,
                    'duracao'       => $duracaoFormatada,
                    'campanha_nome' => $nomeExibicao,
                    'agente'        => $vars['__RAMAL'] ?? $vars['AGENT_RAMAL'] ?? $ramalDaChamada,
                    'voice_list_id' => $voiceListId,
                    'dtmf'          => self::dtmfString($call['dtmf'] ?? $call['last_dtmf'] ?? null),
                    'last_dtmf'     => self::dtmfString($call['last_dtmf'] ?? null),
                    'last_dtmf_at'  => $call['last_dtmf_at'] ?? null,
                    'dtmf_source'   => $call['dtmf_source'] ?? null,
                ]);
            }

            // 4. STATUS DOS AGENTES
            $allAgentsData = $redis->hgetall('discador:agentes') ?: [];
            $agentesFinal = [];
            $agentesOnline = 0;

            foreach ($filteredExtensions as $ext) {
                $ramal = (string)($ext['username'] ?? $ext['extension'] ?? '');
                if ($ramal === '') {
                    continue;
                }

                $st = isset($allAgentsData[$ramal])
                    ? (json_decode($allAgentsData[$ramal], true) ?: [])
                    : [];

                $onlineRaw = $st['online'] ?? false;
                $isOnline = ($onlineRaw === true || $onlineRaw === 1 || $onlineRaw === '1');

                if ($isOnline) {
                    $agentesOnline++;
                }

                $statusReal = strtoupper((string)($st['status'] ?? 'OFFLINE'));
                $ts = (int)($st['status_since'] ?? 0);

                $label = 'Offline';
                $color = 'slate';

                if ($statusReal === 'PAUSA') {
                    $label = 'Em Pausa';
                    $color = 'orange';
                } elseif ($isOnline) {
                    if (in_array($statusReal, ['LIVRE', 'ONLINE'], true)) {
                        $label = 'Disponível';
                        $color = 'emerald';
                    } else {
                        $label = 'Em Chamada';
                        $color = 'blue';
                    }
                }

                $diff = ($ts > 0) ? max(0, $now - $ts) : 0;
                $segundosAtuais = $diff;

                if ($label === 'Em Chamada' && isset($tempoConversaPorRamal[$ramal])) {
                    $tempoExibir = $tempoConversaPorRamal[$ramal];
                    $parts = explode(':', $tempoExibir);
                    $segundosAtuais = ((int)($parts[0] ?? 0) * 3600)
                        + ((int)($parts[1] ?? 0) * 60)
                        + (int)($parts[2] ?? 0);
                } else {
                    $tempoExibir = ($diff < 86400) ? gmdate('H:i:s', $diff) : '24h+';
                }

                $agentesFinal[] = [
                    'ramal'          => $ramal,
                    'nome'           => $ext['name'] ?? 'Sem Nome',
                    'status'         => $label,
                    'color'          => $color,
                    'tempo'          => $tempoExibir,
                    'tempo_segundos' => (int)$segundosAtuais,
                    'status_name'    => $st['status_name'] ?? null
                ];
            }

            // 4.5 LÓGICA DE PAUSAS COM FILTRO OBRIGATÓRIO
            $pausasStats = [
                'agora'   => 0,
                'media'   => '00:00:00',
                'ativas'  => 0,
                'excesso' => 0
            ];

            try {
                $pausasCadastradas = PauseConfig::getBreaksList($tenancy, $ownerId) ?: [];
                $pausasStats['ativas'] = count($pausasCadastradas);

                $pausasAtivasLogs = PauseLog::getLogsAtivos($tenancy, $ownerId) ?: [];
                $pausasStats['agora'] = count($pausasAtivasLogs);

                $alertasExcesso = 0;
                $totalSegundosFim = 0;
                $qtdFim = 0;

                $logsDoDia = PauseLog::getLogsDoDia($tenancy, $ownerId) ?: [];

                foreach ($logsDoDia as $log) {
                    $lStatus = is_array($log) ? ($log['status'] ?? null) : ($log->status ?? null);
                    $lStart  = is_array($log) ? ($log['start_time'] ?? null) : ($log->start_time ?? null);
                    $lEnd    = is_array($log) ? ($log['end_time'] ?? null) : ($log->end_time ?? null);

                    if ($lStatus === 'completed' && !empty($lEnd) && !empty($lStart)) {
                        $totalSegundosFim += (strtotime($lEnd) - strtotime($lStart));
                        $qtdFim++;
                    }
                }

                if ($pausasStats['agora'] > 0) {
                    foreach ($pausasAtivasLogs as $activeLog) {
                        $pConfigId = is_array($activeLog)
                            ? ($activeLog['pausa_config_id'] ?? null)
                            : ($activeLog->pausa_config_id ?? null);

                        $pStart = is_array($activeLog)
                            ? ($activeLog['start_time'] ?? null)
                            : ($activeLog->start_time ?? null);

                        if (empty($pConfigId) || empty($pStart)) {
                            continue;
                        }

                        foreach ($pausasCadastradas as $conf) {
                            $cId = is_array($conf) ? ($conf['id'] ?? null) : ($conf->id ?? null);

                            if ($cId == $pConfigId) {
                                $maxTime = is_array($conf)
                                    ? ($conf['max_time'] ?? '00:00:00')
                                    : ($conf->max_time ?? '00:00:00');

                                $parts = explode(':', $maxTime ?: '00:00:00');
                                $maxSegundos = ((int)($parts[0] ?? 0) * 3600)
                                    + ((int)($parts[1] ?? 0) * 60)
                                    + (int)($parts[2] ?? 0);

                                $duracaoPausaAtual = $now - strtotime($pStart);

                                if ($duracaoPausaAtual > $maxSegundos) {
                                    $alertasExcesso++;
                                }
                                break;
                            }
                        }
                    }
                }

                $pausasStats['excesso'] = $alertasExcesso;

                if ($qtdFim > 0) {
                    $pausasStats['media'] = gmdate('H:i:s', (int)floor($totalSegundosFim / $qtdFim));
                }

            } catch (\Throwable $e) {
                error_log("Erro no calculo de pausas SSE: " . $e->getMessage());
            }

            // 5. RELATÓRIOS E BI
            $slaHoje   = CdrVoice::getSlaToday($tenancy, 20, $ownerId);
            $slaTrend  = CdrVoice::getSlaTrendLastHour($tenancy, $ownerId);
            $biSummary = CdrVoice::getDailyStatsSummary($tenancy, $ownerId);

            $urlFilters = [
                'agent'     => !empty($_GET['agent']) ? trim((string)$_GET['agent']) : null,
                'status'    => !empty($_GET['status']) ? trim((string)$_GET['status']) : null,
                'date_from' => !empty($_GET['date_from']) ? (string)$_GET['date_from'] : null,
                'date_to'   => !empty($_GET['date_to']) ? (string)$_GET['date_to'] : null
            ];

            $cdrFilters = array_merge(
                $filters,
                array_filter($urlFilters, fn($value) => !is_null($value))
            );

            $cdrReport = CdrVoice::getCdrReport($cdrFilters);

            // 6. ENVIO DO PAYLOAD
            $response = [
                'stats' => [
                    'emCurso'       => $countTalking,
                    'naFila'        => $countQueue,
                    'sla'           => $slaHoje,
                    'sla_trend'     => $slaTrend,
                    'agentesOnline' => $agentesOnline,
                    'totalAgentes'  => count($agentesFinal),
                    'bi'            => $biSummary,
                    'pausas'        => $pausasStats
                ],
                'agentes'    => $agentesFinal,
                'chamadas'   => $chamadasFinal,
                'cdr_report' => $cdrReport,
                'server_now' => $now
            ];

            echo "event: update\n";
            echo "data: " . json_encode($response, JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
            exit;

        } catch (\Throwable $e) {
            echo "event: error\ndata: " . json_encode([
                    'message' => $e->getMessage()
                ]) . "\n\n";
            flush();
            exit;
        }
    }

    private static function hydrateDtmfAcrossRelatedCalls(array $calls): array
    {
        $parent = [];

        $find = static function (string $key) use (&$parent, &$find): string {
            if (!isset($parent[$key])) {
                $parent[$key] = $key;
            }

            if ($parent[$key] !== $key) {
                $parent[$key] = $find($parent[$key]);
            }

            return $parent[$key];
        };

        $union = static function (string $a, string $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };

        $keysByIndex = [];
        foreach ($calls as $i => $call) {
            if (!is_array($call)) {
                $keysByIndex[$i] = [];
                continue;
            }

            $keys = self::callRelationKeys($call);
            $keysByIndex[$i] = $keys;

            for ($j = 1; $j < count($keys); $j++) {
                $union($keys[0], $keys[$j]);
            }
        }

        $bestByRoot = [];
        foreach ($calls as $i => $call) {
            if (!is_array($call) || empty($keysByIndex[$i])) {
                continue;
            }

            $dtmf = self::dtmfString($call['dtmf'] ?? $call['last_dtmf'] ?? null);
            if ($dtmf === '') {
                continue;
            }

            $root = $find($keysByIndex[$i][0]);
            if (!isset($bestByRoot[$root]) || strlen($dtmf) >= strlen($bestByRoot[$root]['dtmf'])) {
                $bestByRoot[$root] = [
                    'dtmf' => $dtmf,
                    'source' => $call['id'] ?? $keysByIndex[$i][0],
                    'last_dtmf' => self::dtmfString($call['last_dtmf'] ?? null),
                    'last_dtmf_at' => $call['last_dtmf_at'] ?? null,
                ];
            }
        }

        foreach ($calls as $i => $call) {
            if (!is_array($call) || empty($keysByIndex[$i])) {
                continue;
            }

            $root = $find($keysByIndex[$i][0]);
            $best = $bestByRoot[$root] ?? null;
            if (!$best) {
                continue;
            }

            $current = self::dtmfString($call['dtmf'] ?? null);
            if ($current === '' || strlen($best['dtmf']) > strlen($current)) {
                $calls[$i]['dtmf'] = $best['dtmf'];
                $calls[$i]['dtmf_source'] = $best['source'];
            }

            if (empty($calls[$i]['last_dtmf']) && $best['last_dtmf'] !== '') {
                $calls[$i]['last_dtmf'] = $best['last_dtmf'];
            }

            if (empty($calls[$i]['last_dtmf_at']) && !empty($best['last_dtmf_at'])) {
                $calls[$i]['last_dtmf_at'] = $best['last_dtmf_at'];
            }
        }

        return $calls;
    }

    private static function callRelationKeys(array $call): array
    {
        $vars = is_array($call['vars'] ?? null) ? $call['vars'] : [];
        $values = [
            $call['id'] ?? null,
            $call['peer'] ?? null,
            $call['linkedid'] ?? null,
            $call['call_id'] ?? null,
            $vars['CALL_ID'] ?? null,
            $vars['__CALL_ID'] ?? null,
        ];

        $keys = [];
        foreach ($values as $value) {
            $value = trim((string)($value ?? ''));
            if ($value === '') {
                continue;
            }

            $keys[] = $value;
            $base = preg_replace('/\.\d+$/', '', $value);
            if ($base !== $value && $base !== '') {
                $keys[] = $base;
            }
        }

        return array_values(array_unique($keys));
    }

    private static function dtmfString(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if (is_array($value)) {
            $value = implode('', array_map(static fn($digit) => (string)$digit, $value));
        }

        return preg_replace('/[^\d#*ABCD]/i', '', (string)$value) ?? '';
    }

    private static function sanitizeMonitoringCall(array $call): array
    {
        $sanitized = self::sanitizeMonitoringValue($call);
        return is_array($sanitized) ? $sanitized : $call;
    }

    private static function sanitizeMonitoringValue(mixed $value, ?string $key = null): mixed
    {
        if ($key && self::shouldMaskMonitoringKey($key)) {
            return self::maskMonitoringSensitiveValue($value);
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $itemKey => $itemValue) {
                $normalizedKey = is_string($itemKey) ? strtolower($itemKey) : null;
                $sanitized[$itemKey] = self::sanitizeMonitoringValue($itemValue, $normalizedKey);
            }

            return $sanitized;
        }

        if (!is_string($value)) {
            return $value;
        }

        return preg_replace_callback(
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
            static function (array $matches): string {
                $parts = explode('.', $matches[0]);
                if (count($parts) !== 4) {
                    return $matches[0];
                }

                return sprintf('%s.%s.xxx.xxx', $parts[0], $parts[1]);
            },
            $value
        ) ?? $value;
    }

    private static function shouldMaskMonitoringKey(?string $key): bool
    {
        if (!$key) {
            return false;
        }

        return (bool)preg_match('/tech_?prefix|techprefix|dial_?prefix|prefixo_?tecnico/i', $key);
    }

    private static function maskMonitoringSensitiveValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn($item) => self::maskMonitoringSensitiveValue($item), $value);
        }

        if ($value === null || $value === '') {
            return $value;
        }

        return '[mascarado]';
    }



    public static function getRecordingsFilesAsterisk(): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, ['status' => 401, 'message' => 'Usuário não autenticado.'], 'application/json');
        }

        // 🎯 O PULO DO GATO: Pegar da URL (GET), não do body (input)
        $callId = $_GET['call_id'] ?? null;

        if (!$callId) {
            return new Response(400, ['success' => false, 'message' => 'ID da chamada não fornecido.'], 'application/json');
        }

        $tenancyId = (string)$obUser['tenancy_id'];

        // 🔗 Chama o Service (AsteriskExtensionsSip)
        $asterisk = new AsteriskExtensionsSip();

        // 🔍 Prepara a query para o Service buscar na tabela 'audios'
        $query = [
            'action' => 'list_recordings', // Ação que o index.php vai entender
            'tenant_id' => $tenancyId,
            'call_id' => $callId,
            'category'    => 'recordings'
        ];

        // Aqui o $asterisk->listRecordings vai fazer o request para o seu Backend Asterisk
        $result = $asterisk->listRecordings($query);

        if (!empty($result['ok']) && !empty($result['data'])) {
            $data = $result['data'];

            // 🔊 URL do script que lê o arquivo físico no Linux
            $baseRecordingUrl = TelephonyConfig::recordingScriptUrl();

            foreach ($data as &$rec) {
                if (!empty($rec['path'])) {
                    $token = base64_encode($rec['path']);

                    // 📝 Pegamos o nome que está no banco (Grav: 5521...)
                    // Limpamos para tirar o "Grav: " e espaços
                    $cleanName = !empty($rec['name']) ? str_replace('Grav: ', '', $rec['name']) : $callId;
                    $cleanName = preg_replace('/[^a-zA-Z0-9]/', '', $cleanName); // Só números e letras

                    // 🚀 Passamos o nome na URL também!
                    // Isso ajuda o navegador e o seu script de download
                    $rec['url'] = "{$baseRecordingUrl}?token=" . urlencode($token) . "&name=" . urlencode($cleanName);

                    $rec['display_name_clean'] = $cleanName; // Campo extra para facilitar o JS
                    $rec['token_debug'] = $token;
                }
            }
            unset($rec);

            return new Response(200, [
                'success' => true,
                'total' => count($data),
                'data' => $data,
            ], 'application/json');
        } else {
            return new Response(404, ['success' => false, 'message' => 'Áudio não encontrado.'], 'application/json');
        }
    }


    public static function getQueuesList(): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) return new Response(401, ['message' => "Não autenticado"], 'application/json');

        $userId  = (int) $obUser['id'];
        $role    = strtolower($obUser['function']);
        $tenancy = (string) $obUser['tenancy_id'];

        // Definição de Escopo (baseado na sua lógica de campanhas)
        $filterUserId = in_array($role, ['super_admin', 'admin']) ? null : $userId;

        try {
            // Chama a Model seguindo o seu padrão getRates
            $listQueues = CallCenterQueues::getQueues($tenancy, $filterUserId);

            return new Response(200, [
                'success' => true,
                'total'   => count($listQueues),
                'data'    => $listQueues
            ], 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, ['error' => $e->getMessage()], 'application/json');
        }
    }

    public static function setNewQueues($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) return new Response(401, ['status' => 401], 'application/json');

        $inputData = json_decode(file_get_contents('php://input'), true);

        //echo "<pre>";
        //print_r($inputData);
        //echo "</pre>";exit();

        try {
            // 1. Criar a Fila
            CallCenterQueues::createQueue(
                $obUser['id'],
                $obUser['tenancy_id'],
                $inputData['queue_id'],
                $inputData['name'],
                $inputData['strategy'],
                $inputData['priority'] ?? 'Média',
                $inputData['record_calls'] ?? 0 // <--- NOVO CAMPO
            );

            // 2. Limpar membros antigos (caso seja uma re-edição)
            CallCenterQueues::clearQueueMembers($inputData['queue_id'], $obUser['tenancy_id']);

            // 3. Inserir os Agentes selecionados
            if (!empty($inputData['agents'])) {
                foreach ($inputData['agents'] as $ramal) {


                    CallCenterQueues::addQueueMember(
                        $obUser['tenancy_id'],
                        $inputData['queue_id'],
                        $ramal
                    );
                }
            }

            return new Response(200, ['success' => true], 'application/json');

        } catch (\Exception $e) {
            return new Response(500, ['success' => false, 'error' => $e->getMessage()], 'application/json');
        }
    }

    public static function getQueuesListById($request, $id): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) return new Response(401, ['success' => false, 'message' => "Não autenticado"], 'application/json');

        $tenancy = (string) $obUser['tenancy_id'];

        try {
            // Busca os dados completos via Model
            $queueData = CallCenterQueues::getQueueConfig($id, $tenancy);

            if (empty($queueData)) {
                return new Response(404, ['success' => false, 'message' => 'Fila não encontrada.'], 'application/json');
            }

            return new Response(200, [
                'success' => true,
                'data'    => $queueData
            ], 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, ['success' => false, 'error' => $e->getMessage()], 'application/json');
        }
    }

    public static function updateAgentsQuick($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) return new Response(401, ['success' => false], 'application/json');

        // Captura o payload enviado pelo fetch do toggleAgent
        $input = json_decode(file_get_contents('php://input'), true);

        $queueId = $input['queue_id'] ?? null;
        $ramal   = $input['ramal'] ?? null;
        $action  = $input['action'] ?? null; // 'add' ou 'remove'

        try {
            $success = CallCenterQueues::toggleQueueMember(
                $obUser['tenancy_id'],
                $queueId,
                $ramal,
                $action,
                $obUser
            );

            if (!$success) {
                return new Response(200, [
                    'success' => false,
                    'message' => "Não foi possível processar a alteração no banco."
                ], 'application/json');
            }

            return new Response(200, [
                'success' => true,
                'message' => $action === 'add' ? "Agente vinculado com sucesso!" : "Agente removido!"
            ], 'application/json');

        } catch (\Throwable $e) {
            // Aqui ele pega a mensagem "Ação Bloqueada: Este agente não pertence..." da Model
            return new Response(200, [ // Usamos 200 para o JS ler o JSON, mas success false
                'success' => false,
                'message' => $e->getMessage()
            ], 'application/json');
        }
    }


    public static function updateFeature($request): Response
    {
        $obUser = SessionUser::getLogged();

        // 🔒 1. Verificação de Autenticação
        if (!$obUser) {
            return new Response(401, ['success' => false, 'message' => 'Não autorizado.'], 'application/json');
        }

        $inputData = json_decode(file_get_contents('php://input'), true);

        $queueId = $inputData['queue_id'] ?? null;

        if (!$queueId) {
            return new Response(400, ['success' => false, 'message' => 'ID da fila ausente.'], 'application/json');
        }

        // 🛡️ 2. Limpeza do Payload
        // Removemos o queue_id dos dados de update para que não seja enviado como coluna a atualizar
        unset($inputData['queue_id']);

        try {
            // ⚙️ 3. Chamada da Model ajustada
            // IMPORTANTE: Removemos o (int)$obUser['id'] da cláusula de POSSE (WHERE),
            // mas você pode passá-lo se sua model registrar quem foi o último editor.
            // No contexto atual, passamos apenas o Tenancy para permitir edição colaborativa.
            $success = CallCenterQueues::updateQueue(
                (string)$obUser['tenancy_id'],
                $queueId,
                $inputData
            );

            if ($success) {
                return new Response(200, [
                    'success' => true,
                    'message' => 'Fila atualizada com sucesso.'
                ], 'application/json');
            }

            return new Response(500, ['success' => false, 'message' => 'Falha ao atualizar banco ou nenhuma alteração feita.'], 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, ['success' => false, 'message' => $e->getMessage()], 'application/json');
        }
    }

    public static function deleteQueue($request, $id): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) return new Response(401, ['success' => false], 'application/json');

        try {
            // Chamada limpa para a Model
            $success = CallCenterQueues::deleteQueue(
                (string)$obUser['tenancy_id'],
                (string)$id
            );

            return new Response(200, [
                'success' => $success,
                'message' => $success ? "Fila removida com sucesso!" : "Fila não encontrada."
            ], 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, [
                'success' => false,
                'message' => "Erro ao excluir: " . $e->getMessage()
            ], 'application/json');
        }
    }





}
