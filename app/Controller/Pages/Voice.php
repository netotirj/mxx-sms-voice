<?php

namespace App\Controller\Pages;

use App\Config\TelephonyConfig;
use App\Model\Entity\AgentsPortal;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\CallCenterQueues;
use App\Model\Entity\CdrVoice;
use App\Model\Entity\Notifications;
use App\Model\Entity\Rates;
use App\Model\Entity\RegisterTenancies;
use App\Model\Entity\UserAuthentication;
use App\Model\Entity\UserPlans;
use App\Model\Entity\UserSearch;
use App\Service\PlanAccessPolicy;
use App\Service\FinancialHierarchyBillingService;
use App\Service\FinancialHierarchyResolver;
use App\Service\FinancialTransactionService;
use App\Service\PlanRuntimeService;
use App\Service\WhatsAppBilling;
use App\Service\VoicePricingService;
use App\RedisConn;
use Exception;
use GuzzleHttp\Client;
use Predis\Client as RedisClient;
use App\Http\Response;
use App\Session\User as SessionUser;
use App\Utils\CampaignNameCode;
use App\Utils\View;
use App\Model\Entity\CampaignVoice;
use App\Model\Entity\CampaignVoiceSchedule;
use GuzzleHttp\Exception\GuzzleException;
use Random\RandomException;
use Throwable;

class Voice extends ViewComponents
{
    private static function estimatedCampaignTotalForUser(
        int $targetUserId,
        string $tenantId,
        ?int $planId,
        array $trunk,
        string $variableType,
        int $contactsCount,
        bool $hasSmsDirect,
        array $dtmf
    ): float {
        $context = FinancialHierarchyResolver::resolveContext($targetUserId, $tenantId);
        $role = strtolower((string)$context->actor_role);
        $voiceRate = 0.0;
        $smsRate = 0.0;
        $torpedoRate = 0.0;
        $serviceFee = 0.0;
        $whatsRate = (float)(WhatsAppBilling::categoryPricesForUser($targetUserId, $tenantId)['marketing'] ?? 0);

        if ($role === 'reseller') {
            $ratesAll = Rates::getActiveRatesByUser($tenantId, $targetUserId);
            $voiceRate = (float)($ratesAll['voice'] ?? 0);
            $smsRate = (float)($ratesAll['sms'] ?? 0);
            $torpedoRate = (float)($ratesAll['torpedo'] ?? 0);
            $serviceFeeRate = Rates::getLatestActiveRate($tenantId, $targetUserId, 'service_fee');
            $serviceFee = (float)($serviceFeeRate['rate'] ?? 0);
        } else {
            $quote = VoicePricingService::quote([
                'trunk' => $trunk,
                'user_id' => $targetUserId,
                'tenancy_id' => $tenantId,
                'plan_id' => $planId,
            ]);
            $planRates = VoicePricingService::planRates($targetUserId, $tenantId, $quote['plan_id'] ?? $planId);
            $voiceRate = (float)($quote['call_minute_cost'] ?? 0);
            $smsRate = (float)($quote['sms_cost'] ?? 0);
            $torpedoRate = (float)($quote['torpedo_cost'] ?? 0);
            $serviceFee = (float)($planRates['service_fee'] ?? 0);
        }

        $primaryRate = match ($variableType) {
            'service_fee' => $serviceFee,
            'sms' => $smsRate,
            'torpedo' => $torpedoRate,
            'whatsapp' => $whatsRate,
            default => $voiceRate,
        };

        return VoicePricingService::estimateCampaignTotal(
            $contactsCount,
            $primaryRate,
            $smsRate,
            $hasSmsDirect,
            $dtmf
        );
    }

    private static function buildVoicePreflightPlan(
        int $actorUserId,
        string $tenantId,
        ?int $planId,
        array $trunk,
        string $variableType,
        int $contactsCount,
        bool $hasSmsDirect,
        array $dtmf,
        float $retailAmount
    ): array {
        $context = FinancialHierarchyResolver::resolveContext($actorUserId, $tenantId);
        $resellerAmount = 0.0;
        $adminAmount = 0.0;

        if ($context->reseller_id && (int)$context->reseller_id !== $actorUserId) {
            $resellerAmount = self::estimatedCampaignTotalForUser(
                (int)$context->reseller_id,
                $tenantId,
                $planId,
                $trunk,
                $variableType,
                $contactsCount,
                $hasSmsDirect,
                $dtmf
            );
        }

        if ($context->owner_admin_id > 0 && (int)$context->owner_admin_id !== $actorUserId) {
            $adminAmount = self::estimatedCampaignTotalForUser(
                (int)$context->owner_admin_id,
                $tenantId,
                $planId,
                $trunk,
                $variableType,
                $contactsCount,
                $hasSmsDirect,
                $dtmf
            );
        }

        return FinancialHierarchyBillingService::buildDebitPlan([
            'actor_user_id' => $actorUserId,
            'tenancy_id' => $tenantId,
            'module' => 'voice',
            'event' => 'campaign_preflight',
            'retail_amount' => round($retailAmount, 4),
            'reseller_amount' => round($resellerAmount, 4),
            'admin_amount' => round($adminAmount, 4),
            'related_type' => 'voice_campaign',
            'related_id' => sprintf('%s:%d:%d', $tenantId, $actorUserId, $contactsCount),
            'operation_key_base' => sprintf('voice:preflight:%s:%d:%d', $tenantId, $actorUserId, $contactsCount),
            'description_prefix' => 'VOICE PREFLIGHT',
            'metadata' => [
                'contacts_count' => $contactsCount,
                'variable_type' => $variableType,
            ],
        ]);
    }

    public static function getComponentsVoice(): Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/voice/index', []);
        return parent::getComponentsUsers('Maxx Solutions - SMS | VOZ', $content);
    }

    public static function getComponentsVoiceList($request): Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/voice/list', []);
        return parent::getComponentsUsers('Maxx Solutions - SMS | VOZ', $content);
    }

    public static function getComponentsAudioList($request): Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/voice/audios', []);
        return parent::getComponentsUsers('Maxx Solutions - SMS | VOZ', $content);
    }

    public static function getComponentsActiveCalls($request): Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/voice/live-calls', []);
        return parent::getComponentsUsers('Maxx Solutions - SMS | VOZ', $content);
    }

    public static function getComponentsListExtensions($request): Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $planWebrtcEnabled = strtolower((string)($obUser['function'] ?? '')) === 'super_admin'
            ? 'true'
            : (PlanRuntimeService::canUseFeature((string)($obUser['tenancy_id'] ?? ''), 'webrtc') ? 'true' : 'false');

        $content = View::render('/voice/sip-devices', [
            'plan_webrtc_enabled' => $planWebrtcEnabled,
        ]);
        return parent::getComponentsUsers('Maxx Solutions - SMS | VOZ', $content);
    }

    public static function getComponentsVoiceTrunks($request): Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/voice/trunks', []);
        return parent::getComponentsUsers('Maxx Solutions - SMS | VOZ', $content);
    }

    public static function getComponentsVoiceSearch(): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, ['message' => "Usuário não autenticado"], 'application/json');
        }

        $userId  = (int) $obUser['id'];
        $role    = strtolower($obUser['function']);
        $tenancy = (string) $obUser['tenancy_id'];

        // ==============================
        // 🔎 DEFINIÇÃO DE ESCOPO
        // ==============================
        $filterUserId  = null;
        $filterTenancy = null;

        if ($role === 'super_admin') {
            // vê tudo → sem filtros
            $filterUserId  = null;
            $filterTenancy = null;

        } elseif ($role === 'admin') {
            // vê tudo da tenancy
            $filterTenancy = $tenancy;

        } elseif ($role === 'reseller') {
            // vê somente dele
            $filterUserId  = $userId;
            $filterTenancy = $tenancy;

        } else {
            // usuário comum
            $filterUserId  = $userId;
            $filterTenancy = $tenancy;
        }

        // ==============================
        // 🔽 BUSCA NO BANCO
        // ==============================
        try {
            $listVoice = CampaignVoice::getVoiceListsDetail(
                $filterUserId,
                $filterTenancy,
                $role
            );
        } catch (\Throwable $e) {
            return new Response(500, [
                'message' => "Erro ao consultar chamadas",
                'error'   => $e->getMessage()
            ], 'application/json');
        }

        self::processCdrFromRedis(false);

        // ==============================
        // 🔄 FORMATANDO OS DADOS
        // ==============================
        $formatted = array_map(function ($row) {

            return [
                'id'             => (int)$row['id'],
                'source'         => 'campaign',
                'job_id'         => (string)($row['job_id'] ?? ''),   // ✅ ADD
                'name'           => $row['name'],
                'type'           => $row['type'],
                'total_contacts' => (int) ($row['total_contacts'] ?? 0),
                'status'         => $row['status'],
                'total_calls'    => (int) ($row['total_calls'] ?? 0),
                'answered_calls' => (int) ($row['answered_calls'] ?? 0),
                'failed_calls'   => (int) ($row['failed_calls'] ?? 0),
                'created_at'     => $row['created_at'],
            ];
        }, $listVoice);

        try {
            $redis = RedisConn::get();
            $formatted = self::applyCampaignRuntimeState($redis, $formatted);
        } catch (\Throwable) {
            // Se o Redis de telefonia falhar, a listagem continua com o status persistido no banco.
        }

        try {
            $scheduled = CampaignVoiceSchedule::getPendingForList($filterUserId, $filterTenancy);

            foreach ($scheduled as $row) {
                $formatted[] = [
                    'id'             => 'schedule_' . (int)$row['id'],
                    'schedule_id'    => (int)$row['id'],
                    'source'         => 'schedule',
                    'job_id'         => (string)($row['job_id'] ?? ''),
                    'name'           => $row['name'],
                    'type'           => $row['type'],
                    'total_contacts' => (int)($row['total_contacts'] ?? 0),
                    'status'         => 's',
                    'total_calls'    => (int)($row['total_calls'] ?? 0),
                    'answered_calls' => (int)($row['answered_calls'] ?? 0),
                    'failed_calls'   => (int)($row['failed_calls'] ?? 0),
                    'created_at'     => $row['scheduled_at'],
                    'scheduled_at'   => $row['scheduled_at'],
                ];
            }

            usort($formatted, static function ($a, $b) {
                return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
            });
        } catch (\Throwable $e) {
            // Não derruba a listagem normal se a tabela de agendamentos ainda não existir.
        }

        $monitor = [
            'has_alert' => false,
            'alerts'    => [],
            'stats'     => [],
            'window_s'  => 120,
        ];

        try {
            // se você já tem um singleton/conn global, use ele.
            $redis = RedisConn::get();

            $alerts = self::getVoiceHttpAlerts($redis, 120, 20);
            $stats  = self::getVoiceHttpErrorStats($redis);
            $stasis = self::getStasisStatus($redis, 10);

            $requeue = [];

            foreach ($formatted as $c) {
                $jobId = $c['job_id'] ?? '';
                if (!$jobId) continue;

                $requeue[$jobId] = self::getRequeueStatus($redis, $jobId, 120, 20);
            }

            $monitor = [
                'has_alert' => self::hasCriticalHttpAlert($alerts)
                    || !$stasis['online']
                    || self::hasCriticalRequeue($requeue),
                'alerts'    => $alerts,
                'stats'     => $stats,
                'window_s'  => 120,
                'stasis'    => $stasis,
                'requeue'   => $requeue, // ✅ ADD
            ];
        } catch (\Throwable $e) {
            // não quebra a tela por causa do monitor
            $monitor['error'] = $e->getMessage();
        }

        // ==============================
        // ✅ RETORNO
        // ==============================
        return new Response(200, [
            'success' => true,
            'total'   => count($formatted),
            'data'    => $formatted,
            'monitor' => $monitor,
        ], 'application/json');
    }


    private static function getRequeueStatus( RedisClient $redis, string $jobId, int $windowSeconds = 120, int $maxEvents = 20): array
    {
        $now = time();

        // status atual do job (badge)
        $raw = $redis->get("campaign:{$jobId}:runtime_status");
        $st  = $raw ? json_decode((string)$raw, true) : null;

        $lastTs = is_array($st) ? (int)($st['ts'] ?? 0) : 0;
        $code   = is_array($st) ? (string)($st['code'] ?? '') : '';
        $msg    = is_array($st) ? ($st['msg'] ?? null) : null;

        $active = $lastTs > 0 && ($now - $lastTs) <= 20; // combina com TTL 20s do worker

        // contadores
        $countsRaw = $redis->hgetall("campaign:{$jobId}:requeue_counts");
        $counts = [];
        if (is_array($countsRaw)) {
            foreach ($countsRaw as $k => $v) $counts[(string)$k] = (int)$v;
        }

        // últimos eventos (janela)
        $events = [];
        $rawList = $redis->lrange("campaign:{$jobId}:events", 0, $maxEvents - 1);
        if (is_array($rawList)) {
            foreach ($rawList as $rawEv) {
                $it = json_decode((string)$rawEv, true);
                if (!is_array($it)) continue;

                $ts = (int)($it['ts'] ?? 0);
                if ($ts <= 0) continue;
                if (($now - $ts) > $windowSeconds) continue;

                $events[] = [
                    'ts'   => $ts,
                    'code' => (string)($it['code'] ?? ''),
                    'msg'  => (string)($it['msg'] ?? ''),
                    'ctx'  => $it['ctx'] ?? [],
                ];
            }
        }

        return [
            'active'   => $active,
            'last_ts'  => $lastTs,
            'window_s' => $windowSeconds,
            'status'   => [
                'code' => $code ?: null,
                'msg'  => $msg,
            ],
            'counts'   => $counts,
            'events'   => $events,
        ];
    }

    private static function hasCriticalRequeue(array $requeueByJob): bool
    {
        foreach ($requeueByJob as $r) {
            $code = $r['status']['code'] ?? null;
            if (in_array($code, ['no_agents_online','no_agents_available'], true)) return true;
        }
        return false;
    }

    private static function getVoiceHttpAlerts(RedisClient $redis, int $seconds = 120, int $max = 20): array
    {
        $rawList = $redis->lrange('voice:errors:http', 0, $max - 1);
        if (!is_array($rawList)) return [];

        $now = time();
        $alerts = [];

        foreach ($rawList as $raw) {
            $item = json_decode((string)$raw, true);
            if (!is_array($item)) continue;

            $ts = (int)($item['ts'] ?? 0);
            if ($ts <= 0) continue;
            if (($now - $ts) > $seconds) continue; // só recentes

            $alerts[] = [
                'ts'          => $ts,
                'class'       => (string)($item['class'] ?? 'unknown_http'),
                'http_status' => $item['http']['status'] ?? null,
                'error'       => $item['http']['error'] ?? null,
                'endpoint'    => $item['endpoint'] ?? null,
                'call_id'     => $item['call_id'] ?? null,
                'job_id'      => $item['job_id'] ?? null,
            ];
        }

        return $alerts;
    }

    private static function getVoiceHttpErrorStats(RedisClient $redis): array
    {
        $stats = $redis->hgetall('voice:http_errors_by_class');
        if (!is_array($stats)) return [];

        $out = [];
        foreach ($stats as $k => $v) $out[(string)$k] = (int)$v;

        return $out;
    }

    private static function hasCriticalHttpAlert(array $alerts): bool
    {
        foreach ($alerts as $a) {
            $cls = (string)($a['class'] ?? '');
            if (in_array($cls, ['timeout','connect_fail','conn_refused','ari_5xx','auth','dns','ssl'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function getStasisStatus(RedisClient $redis, int $ttlSeconds = 10): array
    {
        $hbRaw = $redis->get('voice:stasis:heartbeat');
        $hb    = $hbRaw ? json_decode((string)$hbRaw, true) : null;

        $lastTs = is_array($hb) ? (int)($hb['ts'] ?? 0) : 0;
        $online = $lastTs > 0 && (time() - $lastTs) <= $ttlSeconds;

        $errRaw = $redis->get('voice:stasis:last_error');
        $err    = $errRaw ? json_decode((string)$errRaw, true) : null;

        return [
            'online'   => $online,
            'last_ts'  => $lastTs,
            'ttl_s'    => $ttlSeconds,
            'last_err' => is_array($err) ? ($err['msg'] ?? null) : null,
            'err_ts'   => is_array($err) ? (int)($err['ts'] ?? 0) : null,
            'pid'      => is_array($hb) ? ($hb['pid'] ?? null) : null,
            'host'     => is_array($hb) ? ($hb['host'] ?? null) : null,
            'app'      => is_array($hb) ? ($hb['app'] ?? null) : null,
        ];
    }



    /**
     * @throws Exception
     */
    public static function getComponentsVoiceListSearch($request): Response|string
    {
        // 🔹 Verifica se o usuário está logado
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $userId = $obUser['id'] ?? null;
        $tenancyId = $obUser['tenancy_id'] ?? null;
        $userFunc = $obUser['function'] ?? null;

        // ======================================================
        // 🔹 Busca listas conforme o tipo de usuário
        // ======================================================
        if ($userFunc === 'super_admin') {

            // Super admin pode ver tudo
            $lists = CampaignVoice::getVoiceLists(null, null, 'super_admin');

        } elseif ($userFunc === 'admin') {

            // Admin vê apenas pelo tenancy
            $lists = CampaignVoice::getVoiceLists(null, $tenancyId, 'admin');

        } else {

            // Usuário comum: filtra por tenancy e user_id
            $lists = CampaignVoice::getVoiceLists($userId, $tenancyId, (string)$userFunc);
        }


        // ======================================================
        // 🔹 Monta as listas formatadas
        // ======================================================
        $formattedLists = [];

        foreach ($lists as $list) {
            $contacts = CampaignVoice::getContactsByListId($list['id']);
            $numContacts = count($contacts);

            $formattedLists[] = [
                'id' => (int)$list['id'],
                'nome_lista' => $list['name'] ?? 'Sem nome',
                'num_contatos' => $numContacts,
                'status' => $list['status'] === 'active' ? 'Ativa' : 'Inativa',
                'total_contacts' => (int)($list['total_contacts'] ?? $numContacts),
                'created' => !empty($list['created_at'])
                    ? (new \DateTime($list['created_at']))->format('d/m/Y H:i')
                    : '--/--/---- --:--'
            ];
        }

        // ======================================================
        // 🔹 Monta a resposta JSON final
        // ======================================================
        return new Response(200, [
            'status' => 200,
            'message' => 'Listas de voz encontradas com sucesso.',
            'data' => $formattedLists
        ], 'application/json');
    }

    public static function getVoiceListening($request): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        // 🔹 Lê JSON recebido
        $rawInput = file_get_contents('php://input');

        $params = json_decode($rawInput, true);
        $spyMode = $params['spy_mode'] ?? 'listen';
        $spyOpts = match ($spyMode) {
            'whisper' => 'bqW',
            default => 'bq',
        };

        if (!is_array($params)) {
            return new Response(400, [
                'status' => 400,
                'message' => 'JSON inválido no corpo da requisição'
            ], 'application/json');
        }

        // 🔹 Parâmetros obrigatórios
        $adminExtension = trim($params['admin_extension'] ?? '');
        $callId = trim($params['call_id'] ?? '');

        if ($adminExtension === '' || $callId === '') {
            return new Response(400, [
                'status' => 400,
                'message' => 'Parâmetros obrigatórios ausentes: admin_extension, call_id'
            ], 'application/json');
        }

        // 🔹 Demais dados opcionais
        $spyNumber = $params['call_number'] ?? 'Desconhecido';
        $spyDestination = $params['call_destination'] ?? 'Desconhecido';
        $spyTrunk = $params['call_trunk'] ?? '—';
        $spyType = $params['call_type'] ?? 'NORMAL';

        // 🔹 Configuração ARI
        $ariBase = TelephonyConfig::ariBaseUrl();
        [$ariUser, $ariPass] = TelephonyConfig::ariAuth();
        $stasisApp = TelephonyConfig::stasisApp();

        $client = new Client([
            'auth' => [$ariUser, $ariPass],
            'timeout' => 10,
            'http_errors' => false // evita exception automática
        ]);

        try {

            // -------------------------------
            //   CRIA CANAL DE ESCUTA (Spy)
            // -------------------------------

            $targetChannel = self::resolveSpyChannelName($client, $ariBase, $callId);
            $spyChannel = "PJSIP/{$adminExtension}";

            // Variáveis enviadas ao dialplan
            $variables = [
                'SPY_CHANNEL' => $targetChannel,
                'SPY_CHANNEL_ID' => $callId,
                'SPY_OPTS' => $spyOpts,   // ← Aqui você coloca as opções desejadas
                'SPY_NUMBER' => $spyNumber,
                'SPY_DESTINATION' => $spyDestination,
                'SPY_TRUNK' => $spyTrunk,
                'SPY_TYPE' => $spyType,
                'SPY_OPERATOR' => $obUser->name ?? 'Administrador',
            ];

            $payload = [
                'endpoint' => $spyChannel,
                'extension' => '9999',     // extensão do dialplan que executa ChanSpy
                'context' => 'spy-control',
                'priority' => 1,
                'timeout' => 60,
                'callerId' => "Escuta <{$adminExtension}>",
                'variables' => $variables
            ];

            // Envia originate
            $res = $client->post("{$ariBase}channels", [
                'json' => $payload
            ]);

            $body = json_decode($res->getBody(), true);

            if ($res->getStatusCode() >= 300) {
                return new Response(500, [
                    'status' => 500,
                    'message' => 'ARI retornou erro ao iniciar escuta.',
                    'ari_status' => $res->getStatusCode(),
                    'ari_response' => $body
                ], 'application/json');
            }

            return new Response(200, [
                'status' => 200,
                'success' => true,
                'message' => '🎧 Escuta iniciada com sucesso!',
                'data' => [
                    'spy_channel_id' => $body['id'] ?? null,
                    'admin_extension' => $adminExtension,
                    'target_channel' => $targetChannel,
                    'target_channel_id' => $callId,
                    'variables_sent' => $variables,
                ]
            ], 'application/json');

        } catch (Exception $e) {

            return new Response(500, [
                'status' => 500,
                'message' => 'Erro ao enviar Originate via ARI.',
                'error' => $e->getMessage()
            ], 'application/json');
        } catch (GuzzleException $e) {
            return new Response(500, [
                'status' => 500,
                'message' => 'Erro ao enviar Originate via ARI.',
                'error' => $e->getMessage()
            ], 'application/json');
        }

    }

    private static function resolveSpyChannelName(Client $client, string $ariBase, string $callId): string
    {
        if (str_contains($callId, '/')) {
            return $callId;
        }

        try {
            $res = $client->get("{$ariBase}channels/" . rawurlencode($callId), [
                'http_errors' => false,
            ]);

            if ($res->getStatusCode() >= 200 && $res->getStatusCode() < 300) {
                $body = json_decode((string)$res->getBody(), true);
                $name = trim((string)($body['name'] ?? ''));

                if ($name !== '') {
                    return $name;
                }
            }
        } catch (GuzzleException) {
            // Mantem o fluxo atual: se nao conseguir resolver, usa o valor original.
        }

        return $callId;
    }


    public static function setVoiceHangup($request): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, [
                'status'  => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $params = json_decode(file_get_contents('php://input'), true);
        $callId = $params['call_id'] ?? null;

        if (!$callId) {
            return new Response(400, [
                'success' => false,
                'message' => 'ID da chamada não informado.'
            ], 'application/json');
        }

        try {
            $client = new Client(['auth' => TelephonyConfig::ariAuth(), 'timeout' => 5]);
            $ariBase = TelephonyConfig::ariBaseUrl();

            $client->delete("{$ariBase}channels/{$callId}");

            return new Response(200, ['success' => true, 'message' => 'Chamada encerrada.'], 'application/json');

        } catch (GuzzleException $e) {
            // VERIFICAÇÃO CRÍTICA: Se o erro for 404, o canal já se foi.
            if ($e->getCode() === 404) {
                return new Response(200, [
                    'success' => true,
                    'message' => 'Chamada já havia sido encerrada ou não existe mais.'
                ], 'application/json');
            }

            return new Response(500, [
                'success' => false,
                'message' => 'Erro ARI: ' . $e->getMessage()
            ], 'application/json');
        }
    }

    public static function setVoiceListeningHangup($request): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, [
                'success' => false,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $params = json_decode(file_get_contents('php://input'), true);
        if (!is_array($params)) {
            $params = [];
        }

        $spyChannelId = trim((string)($params['spy_channel_id'] ?? ''));

        if ($spyChannelId === '') {
            return new Response(400, [
                'success' => false,
                'message' => 'Canal de escuta não informado.'
            ], 'application/json');
        }

        try {
            $client = new Client([
                'auth' => TelephonyConfig::ariAuth(),
                'timeout' => 5,
                'http_errors' => false
            ]);
            $ariBase = TelephonyConfig::ariBaseUrl();

            $res = $client->delete("{$ariBase}channels/{$spyChannelId}");
            $status = $res->getStatusCode();

            if ($status >= 200 && $status < 300 || $status === 404) {
                return new Response(200, [
                    'success' => true,
                    'message' => 'Escuta finalizada.'
                ], 'application/json');
            }

            return new Response(500, [
                'success' => false,
                'message' => 'ARI retornou erro ao finalizar escuta.',
                'ari_status' => $status,
                'ari_response' => json_decode((string)$res->getBody(), true)
            ], 'application/json');
        } catch (GuzzleException $e) {
            return new Response(500, [
                'success' => false,
                'message' => 'Erro ARI ao finalizar escuta: ' . $e->getMessage()
            ], 'application/json');
        }
    }

    public static function setVoiceAnswer($request): Response
    {
        return new Response(410, json_encode([
            'success' => false,
            'message' => 'Atendimento por ARI desativado. Atenda a chamada pelo WebRTC.'
        ]), 'application/json');
    }

    public static function makeCallManual($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, json_encode(['success' => false, 'message' => 'Usuário não autenticado.']), 'application/json');
        }

        $params = json_decode(file_get_contents('php://input'), true);
        $origem  = $params['origem'] ?? null;  // Ex: 50642430
        $destino = $params['destino'] ?? null; // Ex: 5521975643710
        $sipTrunkId = $params['sip_trunk_id'] ?? null;

        if (!$origem || !$destino) {
            return new Response(400, json_encode(['success' => false, 'message' => 'Dados incompletos']), 'application/json');
        }

        $pricingVariables = [];
        $isExternalDestination = strlen(preg_replace('/\D+/', '', (string)$destino)) > 4;

        if ($isExternalDestination) {
            if (empty($sipTrunkId)) {
                return new Response(400, json_encode([
                    'success' => false,
                    'message' => 'Informe o tronco para chamada externa manual.'
                ]), 'application/json');
            }

            $tenantId = (string)$obUser['tenancy_id'];
            $userId = (int)$obUser['id'];
            $planId = RegisterTenancies::getActivePlanId($tenantId);
            $asterisk = new AsteriskExtensionsSip();
            $trunkResponse = $asterisk->getTrunkById([
                'user_id' => $userId,
                'tenant_id' => $tenantId,
            ], (int)$sipTrunkId);

            $trunk = $trunkResponse['data']['data'] ?? $trunkResponse['data'] ?? null;
            if (!$trunk || !is_array($trunk)) {
                return new Response(404, json_encode([
                    'success' => false,
                    'message' => 'Tronco não encontrado para chamada manual.'
                ]), 'application/json');
            }

            [$isApto, $reason] = self::trunkIsApto($trunk);
            if (!$isApto) {
                return new Response(422, json_encode([
                    'success' => false,
                    'message' => "SIP Trunk Error. Motivo: {$reason}"
                ]), 'application/json');
            }

            try {
                $quote = VoicePricingService::quote([
                    'trunk' => $trunk,
                    'user_id' => $userId,
                    'tenancy_id' => $tenantId,
                    'plan_id' => $planId ? (int)$planId : null,
                ]);
            } catch (Throwable $e) {
                return new Response(422, json_encode([
                    'success' => false,
                    'message' => 'Falha na tarifação de voz: ' . $e->getMessage()
                ]), 'application/json');
            }

            $pricingVariables = [
                'TRUNK_ID' => (string)($trunk['trunk_id'] ?? $sipTrunkId),
                'TRUNK' => (string)($trunk['name'] ?? $trunk['trunk_id'] ?? $sipTrunkId),
                'TRUNK_BILLING_TYPE' => (string)$quote['trunk_billing_type'],
                'PLAN_ID' => (string)$quote['plan_id'],
                'TARIFF_USED' => (string)$quote['tariff_used'],
                'CALL_MINUTE_COST' => (string)$quote['call_minute_cost'],
            ];
        }

        try {
            $client = new Client([
                'auth' => TelephonyConfig::ariAuth(),
                'timeout' => 10,
            ]);

            $ariBase = TelephonyConfig::ariBaseUrl();

            // --- ESTRATÉGIA ARI ORIGINATE ---
            $payload = [
                'endpoint' => "PJSIP/{$origem}", // Liga para o seu WebRTC
                'extension' => $destino,         // Destino que vai para o Dialplan
                'context' => 'from-internal',    // Seu contexto de discagem manual
                'priority' => 1,
                'callerId' => $origem,           // BINA que aparece para o agente
                'timeout' => 30,
                'variables' => [
                    'CALL_TYPE' => 'MANUAL',
                    'AGENT_RAMAL' => $origem
                ] + $pricingVariables
            ];

            // Dispara a originação
            $resp = $client->post("{$ariBase}channels", [
                'json' => $payload
            ]);

            $status = $resp->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return new Response(200, json_encode(['success' => true, 'message' => 'Chamada iniciada']), 'application/json');
            }

            return new Response($status, (string)$resp->getBody(), 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, json_encode(['success' => false, 'message' => $e->getMessage()]), 'application/json');
        }
    }

    private static function trunkIsApto(array $trunk): array
    {
        if (($trunk['status'] ?? '') !== 'active') {
            return [false, 'Trunk inativo'];
        }

        // 🔄 valida se permite saída
        if (!in_array($trunk['direction'] ?? '', ['outbound', 'both'], true)) {
            return [false, 'Trunk não aceita chamadas de saída'];
        }

        // 🔒 Valida Asterisk apenas se existir
        if (array_key_exists('asterisk_up', $trunk) && !$trunk['asterisk_up']) {
            return [false, 'Asterisk indisponível'];
        }

        // 🔒 Valida SIP apenas se existir
        if (array_key_exists('sip_status', $trunk) && $trunk['sip_status'] !== 'OK') {
            return [false, 'SIP não está OK'];
        }

        $authType  = $trunk['auth_type'] ?? null;
        $sipDetail = $trunk['sip_detail'] ?? null;

        if ($authType === 'register' && $sipDetail !== null && $sipDetail !== 'Registered') {
            return [false, 'Trunk não está registrado'];
        }

        if ($authType === 'ip' && $sipDetail !== null && $sipDetail !== 'Avail') {
            return [false, 'Trunk por IP indisponível'];
        }

        return [true, 'Trunk apto para uso'];
    }

    /**
     * @throws RandomException
     */

    public static function sendVoiceAsterisk($request): Response
    {
        header('Content-Type: application/json');

        // -----------------------
        // conectar Redis
        // -----------------------
        try {
            $redis = new RedisClient(TelephonyConfig::redisConfig());
        } catch (Throwable $e) {
            return new Response(500, [
                'error' => 'Falha ao conectar no Redis.',
                'details' => $e->getMessage()
            ], 'application/json');
        }

        // -----------------------
        // usuário logado
        // -----------------------
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, ['status' => 401, 'message' => 'Usuário não autenticado.'], 'application/json');
        }

        $tenantId = $obUser['tenancy_id'];
        $userId = (int)$obUser['id'];
        $userRole = strtolower($obUser['function']);
        $currentPlan = RegisterTenancies::getActivePlanId($tenantId);
        $isReseller = ($userRole === 'reseller');

        // -----------------------
        // POST / inputs
        // -----------------------
        $data = $request->getPostVars();

        //echo "<pre>";
        //print_r($data);
        //echo "</pre>";exit();

        //$action = $data['action'] ?? 'dtmf'; // compatibilidade com campanhas antigas
        $idList = $data['contact_list'] ?? 0;
        $audioFiles = $_FILES['audios'] ?? null;
        $audiosOrigin = $data['audios_origin'] ?? [];
        $rate = max(1, (int)($data['rate'] ?? 1));
        $baseName = trim((string)($data['campaign_name'] ?? ''));
        $name = CampaignNameCode::generate(
            $baseName,
            static function (string $candidate) use ($tenantId): bool {
                $voiceExists = (new \WilliamCosta\DatabaseManager\Database('campaign_voice'))
                    ->select(
                        'tenancy_id = :tenancy_id AND name = :name',
                        [
                            ':tenancy_id' => (string)$tenantId,
                            ':name' => $candidate,
                        ],
                        '',
                        '1',
                        ['id']
                    )
                    ->fetch(\PDO::FETCH_ASSOC);

                if ($voiceExists) {
                    return true;
                }

                return (bool) (new \WilliamCosta\DatabaseManager\Database('campaign_voice_schedules'))
                    ->select(
                        'tenancy_id = :tenancy_id AND name = :name',
                        [
                            ':tenancy_id' => (string)$tenantId,
                            ':name' => $candidate,
                        ],
                        '',
                        '1',
                        ['id']
                    )
                    ->fetch(\PDO::FETCH_ASSOC);
            }
        );
        $sip_trunk = (string)($data['sip_trunk'] ?? '');
        $queueId = $data['queue_id'] ?? null;
        $strategy = (string)$data['dial_strategy']?? 'rrmemory';
        $sip_trunk_id = $data['sip_trunk_id'] ?? null;
        $cliType = $data['cli_type'] ?? null;
        $totalGeralFrontend = (float)($data['totalGeral'] ?? 0);
        $scheduleMode = strtolower((string)($data['schedule_mode'] ?? 'now'));
        $scheduledAt = trim((string)($data['scheduled_at'] ?? ''));

        if ($scheduleMode === 'scheduled') {
            if ($scheduledAt === '') {
                return new Response(400, ['success' => false, 'message' => 'Informe a data e hora do agendamento.'], 'application/json');
            }

            try {
                $scheduledDate = new \DateTime($scheduledAt);
                if ($scheduledDate <= new \DateTime()) {
                    return new Response(400, ['success' => false, 'message' => 'O agendamento precisa ser para uma data futura.'], 'application/json');
                }
                $scheduledAt = $scheduledDate->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                return new Response(400, ['success' => false, 'message' => 'Data de agendamento inválida.'], 'application/json');
            }
        }

        // =============================
        // 1️⃣ Caller ID Number
        // =============================
        $callerIdNumber = $data['caller_id'] ?? null;

        // se bina_inteligente → usa account_code da sessão
        if ($cliType === 'bina_inteligente') {
            $sessAcc = $_SESSION['user']['account_code'] ?? null;

            if (is_string($sessAcc) && trim($sessAcc) !== '') {
                $callerIdNumber = trim($sessAcc);
            }
        }

        // =============================
        // 2️⃣ Nome vindo da sessão
        // =============================
        $sessionName = $_SESSION['user']['name'] ?? null;
        $sessionName = is_string($sessionName) ? trim($sessionName) : null;

        if ($sessionName === '') {
            $sessionName = null;
        }

        // =============================
        // 3️⃣ Caller ID Name
        // =============================
        if (!empty($data['caller_id_name'])) {
            $callerIdName = $data['caller_id_name'];
        } elseif ($cliType === 'bina_inteligente' && !empty($sessionName)) {
            $callerIdName = $sessionName;   // ✅ usa nome da sessão
        } else {
            $callerIdName = $callerIdNumber ?: 'Discador';
        }

        // endpoints (ramais)
        $endpoints = [];
        if (!empty($queueId)) {
            // Chamamos o método que criamos na QueueModel
            $endpoints = CallCenterQueues::getQueueMembersExtensions($queueId, $tenantId);
        }

        //echo "<pre>";
        //print_r($data);
        //echo "</pre>";exit();

        // =======================
        // ACTION
        // =======================
        $action = $data['action'] ?? 'dtmf';

        // =======================
        // SMS direto
        // =======================
        $smsText    = $data['sms_text']    ?? null;
        $smsService = $data['sms_service'] ?? null;
        $smsStatus  = $data['sms_status']  ?? null;

        $hasSmsDirect =
            $smsText !== null &&
            $smsService !== null &&
            $smsStatus !== null;

        // =======================
        // DTMF
        // =======================
        $dtmfRaw = $data['dtmf'] ?? '[]';
        $dtmf = is_array($dtmfRaw) ? $dtmfRaw : json_decode($dtmfRaw, true);
        if (!is_array($dtmf)) $dtmf = [];

        // ⛔ blindagem total
        if ($action !== 'dtmf') {
            $dtmf = [];
        }

        // =======================
        // SMS em DTMF
        // =======================
        $hasSmsInDtmf = false;

        if ($action === 'dtmf') {
            foreach ($dtmf as $item) {
                if (($item['action'] ?? null) === 'sms') {
                    $hasSmsInDtmf = true;
                    break;
                }
            }
        }

        //echo "<pre>";
        //print_r($action);
        //echo "</pre>";exit;

        // =======================
        // Tipo real da operação
        // =======================
        $variableType = strtolower($data['variable_type'] ?? 'voice');
        if ($variableType === 'normal') $variableType = 'voice';

        // ✅ este é o tipo solicitado pelo frontend (NÃO muda)
        $campaignTypeRequested = $variableType;

        // tipo REAL (pode virar sms/service_fee)
        $variableTypeReal = ($hasSmsDirect || $hasSmsInDtmf) ? 'sms' : $variableType;

        $recordCalls = 0; // Por padrão, não grava

        if (!empty($queueId)) {
            // 🔍 Busca se a fila selecionada deve gravar as chamadas
            $queueConfig = CallCenterQueues::getQueueConfig($queueId, $tenantId);

            // Se existir a config, pegamos o valor de record_calls (0 ou 1)
            if (!empty($queueConfig)) {
                $recordCalls = (int)($queueConfig['record_calls'] ?? 0);
            }
        }

        // =======================
        // Buscar contatos
        // =======================
        if ($obUser['function'] === 'admin') {
            $listInfo = CampaignVoice::getPhonesByListId($idList, $tenantId, null);
        } else {
            $listInfo = CampaignVoice::getPhonesByListId($idList, $tenantId, $userId);
        }

        if (empty($listInfo)) {
            return new Response(404, ['status' => 404, 'message' => 'Lista de contatos vazia.'], 'application/json');
        }

        // 1. Pegamos o ID da lista do primeiro registro (já que é igual para todos)
        $voiceListId = $listInfo[0]['voice_list_id'] ?? null;

        // 2. Criamos a $contactList exatamente como o seu código original espera (array simples de strings)
        $contactList = array_values(
            array_unique(
                array_filter(
                    array_map(function ($item) {
                        // $item agora é um array ['phone' => '...', 'voice_list_id' => '...']
                        $p = preg_replace('/\D+/', '', $item['phone']);

                        if (!str_starts_with($p, '55') && strlen($p) >= 10 && strlen($p) <= 11) {
                            $p = '55' . $p;
                        }
                        return $p;
                    }, $listInfo)
                )
            )
        );

        // Se a lista de telefones estiver vazia após o filtro
        if (empty($contactList)) {
            return new Response(400, ['status' => 400, 'message' => 'Nenhum contato válido.'], 'application/json');
        }

        // =======================
        // Tarifas (reseller x user)
        // =======================
        $isAdmin = (strtolower((string)($obUser['function'] ?? '')) === 'admin');

        // tarifas normais
        if ($isReseller) {
            $ratesAll = Rates::getActiveRatesByUser($tenantId, $userId);
            if (empty($ratesAll)) {
                return new Response(404, ['status' => 404, 'message' => 'Nenhuma tarifa configurada para o revendedor.'], 'application/json');
            }

            $rateVoice    = (float)($ratesAll['voice'] ?? 0);
            $rateSms      = (float)($ratesAll['sms'] ?? 0);
            $rateTorpedo  = (float)($ratesAll['torpedo'] ?? 0);

        } else {
            $obBalanceTariffs = BalanceSms::getBalanceSms($userId, $tenantId, $currentPlan);
            if (!$obBalanceTariffs) {
                return new Response(404, ['status' => 404, 'message' => 'Nenhum saldo disponível para o usuário.'], 'application/json');
            }

            $rateVoice    = (float)($obBalanceTariffs->value_voice ?? 0);
            $rateSms      = (float)($obBalanceTariffs->value_sms ?? 0);
            $rateTorpedo  = (float)($obBalanceTariffs->value_torpedo ?? 0);
        }
        $rateWhatsAppCategories = WhatsAppBilling::categoryPricesForUser((int)$userId, (string)$tenantId);
        $rateWhatsApp = (float)($rateWhatsAppCategories['marketing'] ?? 0);

        // =======================
        // Verificação de plano ativo (PRECISA VIR ANTES do trunk p/ ter $adminId)
        // =======================
        if ($isReseller) {
            $obPlan = UserPlans::getActivePlanByTenancy($currentPlan, $tenantId);
            if (!$obPlan || empty($obPlan->id) || $obPlan->status !== 'active') {
                return new Response(404, ['status' => 404, 'message' => 'Ocorreu um erro! Favor contate o administrador do sistema.'], 'application/json');
            }
        } else {
            // O discador nao pode depender exclusivamente de um vinculo por usuario,
            // porque o plano ativo da conta e da tenancy e outros modulos usam esse escopo.
            $obPlan = UserPlans::getActivePlanByUser($currentPlan, $tenantId, $userId)
                ?: UserPlans::getActivePlanByTenancy($currentPlan, $tenantId);
            if (!$obPlan || empty($obPlan->id) || $obPlan->status !== 'active') {
                return new Response(404, ['status' => 404, 'message' => 'Nenhum plano habilitado ou plano inativo.'], 'application/json');
            }
        }

        $adminId = (int)$obPlan->user_id;

        // =======================
        // Service Fee config (reseller via Rates / admin via BalanceSms)
        // =======================
        $taxaOfService   = 0.0;          // default: sem taxa
        $serviceFeeValue = 0.0;
        $serviceEvent    = 'answered';
        $serviceScope    = 'reseller_trunks';

        if ($isReseller) {
            // busca o service_fee completo do reseller
            $sf = Rates::getLatestActiveRate($tenantId, $userId, 'service_fee'); // precisa aceitar type
            if (!empty($sf)) {
                $serviceFeeValue = (float)($sf['rate'] ?? 0);
                $serviceEvent    = (string)($sf['service_event'] ?? 'answered');
                $serviceScope    = (string)($sf['service_scope'] ?? 'reseller_trunks');
            }
        } elseif ($isAdmin) {
            // admin usa BalanceSms->service_fee
            // garante que $obBalanceTariffs exista (admin cai no else acima)
            $serviceFeeValue = (float)($obBalanceTariffs->service_fee ?? 0);
            $serviceEvent    = 'answered';
            $serviceScope    = 'reseller_trunks'; // por enquanto (balance não tem scope)
        }

        // =====================================================
        // 2) Busca trunk e aplica regra is_system + scope
        // =====================================================
        $query = [
            'user_id'   => $userId,
            'tenant_id' => $tenantId
        ];

        $asterisk = new AsteriskExtensionsSip();
        if (empty($sip_trunk_id)) {
            $listTrunks = $asterisk->listTrunks($query);
            $trunks = $listTrunks['data']['data'] ?? $listTrunks['data'] ?? [];
            $desiredBillingType = VoicePricingService::normalizeBillingType($cliType ?? null);

            foreach ((array)$trunks as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                $candidateBillingType = VoicePricingService::normalizeBillingType($candidate['billing_type'] ?? null)
                    ?? VoicePricingService::normalizeBillingType($candidate['cli_type'] ?? null);
                $candidateStatus = strtoupper((string)($candidate['sip_status'] ?? 'OK'));

                if (
                    $desiredBillingType !== null
                    && $candidateBillingType === $desiredBillingType
                    && ($candidate['status'] ?? '') === 'active'
                    && in_array(($candidate['direction'] ?? ''), ['outbound', 'both'], true)
                    && $candidateStatus === 'OK'
                ) {
                    $sip_trunk_id = $candidate['id'] ?? null;
                    $sip_trunk = (string)($candidate['trunk_id'] ?? $candidate['name'] ?? $sip_trunk);
                    break;
                }
            }
        }

        if (empty($sip_trunk_id)) {
            return new Response(400, [
                'status' => 400,
                'message' => 'Nenhum tronco apto foi selecionado para a campanha.'
            ], 'application/json');
        }

        $resTrunk = $asterisk->getTrunkById($query, (int)$sip_trunk_id);

        if (empty($resTrunk['ok']) || empty($resTrunk['data'])) {
            return new Response(
                $resTrunk['status'] ?? 404,
                ['status' => ($resTrunk['status'] ?? 404), 'message' => 'Trunk não encontrado ou erro ao consultar trunk.'],
                'application/json'
            );
        }

        $trunk = $resTrunk['data']['data'] ?? $resTrunk['data'];
        $techPrefix = $trunk['techprefix'];
        $trunkId = (string)($trunk['trunk_id'] ?? $sip_trunk);
        $trunkName = (string)($trunk['name'] ?? $trunkId);

        //echo "<pre>";
        //print_r($techPrefix);
        //echo "</pre>";exit();

        [$isApto, $reason] = self::trunkIsApto($trunk);

        if (!$isApto) {
            return new Response(
                422,
                [
                    'status'  => 422,
                    'message' => "SIP Trunk Error. Motivo: {$reason}",
                    'reason'  => $reason,
                ],
                'application/json'
            );
        }

        $isSystem        = (int)($trunk['is_system'] ?? 0);
        $isCustomerTrunk = ($isSystem === 0);
        $trunkOwnerId    = (int)($trunk['user_id'] ?? 0);
        // Usa o ID do plano comercial (mxx_plans.id), nao o ID do vinculo em mxx_user_plans.
        $planIdUsed = (int)($obPlan->plan_id ?? $currentPlan);

        try {
            $voiceQuote = VoicePricingService::quote([
                'trunk' => $trunk,
                'user_id' => $userId,
                'tenancy_id' => $tenantId,
                'plan_id' => $planIdUsed,
            ]);
        } catch (Throwable $e) {
            return new Response(422, [
                'status' => 422,
                'message' => 'Falha na tarifação de voz: ' . $e->getMessage(),
            ], 'application/json');
        }

        $trunkBillingType = (string)$voiceQuote['trunk_billing_type'];
        $planIdUsed = (int)$voiceQuote['plan_id'];
        $tariffUsed = (float)$voiceQuote['tariff_used'];
        $rateVoice = (float)$voiceQuote['call_minute_cost'];
        $rateSms = (float)$voiceQuote['sms_cost'];
        $rateTorpedo = (float)$voiceQuote['torpedo_cost'];

        $applyServiceFee = false;

        // Regra FINAL:
        // is_system=1 => tarifa normal (sem taxa)
        // is_system=0 => taxa por evento (service_fee), com scope (reseller) / sempre (admin)
        if ($isCustomerTrunk) {

            if ($isAdmin) {
                $applyServiceFee = true;

            } elseif ($isReseller) {
                // scope só vale para reseller
                if ($serviceScope === 'all_trunks') {
                    // trunk do reseller OU trunk do admin do reseller
                    $applyServiceFee = ($trunkOwnerId === (int)$userId || $trunkOwnerId === (int)$adminId);
                } else {
                    // reseller_trunks (default)
                    $applyServiceFee = ($trunkOwnerId === (int)$userId);
                }

            } else {
                // user comum (se existir)
                $applyServiceFee = true;
            }

            if ($applyServiceFee) {

                // por enquanto só answered
                if ($serviceEvent !== 'answered') {
                    return new Response(400, [
                        'status' => 400,
                        'message' => 'Evento de cobrança inválido (somente answered por enquanto).'
                    ], 'application/json');
                }

                $taxaOfService = (float)$serviceFeeValue;

                if ($taxaOfService <= 0) {
                    return new Response(
                        404,
                        ['status' => 404, 'message' => 'Trunk do cliente sem taxa de serviço configurada.'],
                        'application/json'
                    );
                }

                // trunk do cliente => zera por minuto
                $rateVoice   = 0.0;
                $rateTorpedo = 0.0;

                // ✅ ajustar tipo real (se não for sms)
                if ($variableTypeReal !== 'sms') {
                    $variableTypeReal = 'service_fee';
                }

            } else {
                // trunk é do cliente mas scope não permite
                return new Response(403, [
                    'status'  => 403,
                    'message' => 'Este trunk não está habilitado para taxa de serviço conforme o escopo configurado.'
                ], 'application/json');
            }
        }

        // =====================================================
        // 3) Tarifa por tipo (somente se NÃO for service_fee)
        // =====================================================
        $typeMap = [
            'voice'    => $rateVoice,
            'sms'      => $rateSms,
            'torpedo'  => $rateTorpedo,
            'whatsapp' => $rateWhatsApp,
        ];

        $rateValue = ($variableTypeReal === 'service_fee')
            ? 0.0
            : (float)($typeMap[$variableTypeReal] ?? 0);

        // is_system=1 => exige tarifa normal
        if (!$isCustomerTrunk && $rateValue <= 0) {
            return new Response(
                404,
                ['status' => 404, 'message' => "Nenhuma tarifa configurada para o tipo {$variableTypeReal}"],
                'application/json'
            );
        }

        // valores usados no payload
        $smsCost        = (float)$rateSms;
        $callMinuteCost = ($variableTypeReal === 'service_fee') ? 0.0 : (float)$rateVoice;
        $valorTorpedo   = (float)$rateTorpedo;
        $tariffUsedForCdr = ($variableTypeReal === 'service_fee') ? (float)$taxaOfService : (float)$rateValue;

        // (opcional) meta para worker cobrar na answered
        /*$serviceFeeMeta = [
            'apply' => ($variableTypeReal === 'service_fee'),
            'value' => $taxaOfService,
            'event' => $serviceEvent,
            'scope' => $serviceScope,
            'trunk_owner_id' => $trunkOwnerId,
            'is_system' => $isSystem
        ];*/


        // =======================
        // Verificação de plano ativo
        // =======================
        if ($isReseller) {
            $obPlan = UserPlans::getActivePlanByTenancy($currentPlan, $tenantId);
            if (!$obPlan || empty($obPlan->id) || $obPlan->status !== 'active') {
                return new Response(404, ['status' => 404, 'message' => 'Ocorreu um erro! Favor contate o administrador do sistema.'], 'application/json');
            }
        } else {
            // Mantem o discador alinhado com os demais canais: se o plano esta ativo
            // para a tenancy, a voz nao deve falhar por ausencia de uma linha por usuario.
            $obPlan = UserPlans::getActivePlanByUser($currentPlan, $tenantId, $userId)
                ?: UserPlans::getActivePlanByTenancy($currentPlan, $tenantId);
            if (!$obPlan || empty($obPlan->id) || $obPlan->status !== 'active') {
                return new Response(404, ['status' => 404, 'message' => 'Nenhum plano habilitado ou plano inativo.'], 'application/json');
            }
        }

        $adminId = $obPlan->user_id;

        //echo "<pre>";
        //print_r($adminId);
        //echo "</pre>";exit();

        $primaryEstimateRate = ($variableTypeReal === 'service_fee') ? (float)$taxaOfService : (float)$rateValue;
        $totalGeral = VoicePricingService::estimateCampaignTotal(
            count($contactList),
            $primaryEstimateRate,
            $smsCost,
            $hasSmsDirect,
            $dtmf
        );

        // =======================
        // Validação de saldo
        // =======================
        $preflightPlan = self::buildVoicePreflightPlan(
            $userId,
            $tenantId,
            $planIdUsed,
            $trunk,
            (string)$variableTypeReal,
            count($contactList),
            $hasSmsDirect,
            $dtmf,
            $totalGeral
        );

        try {
            FinancialHierarchyBillingService::assertSufficientBalance($preflightPlan);
        } catch (\Throwable $e) {
            $requirements = FinancialHierarchyBillingService::summarizeRequiredBalances($preflightPlan);

            foreach ($requirements as $entry) {
                if ((float)$entry['balance'] >= (float)$entry['amount']) {
                    continue;
                }

                if ((int)$entry['user_id'] === (int)$adminId && (string)$entry['wallet'] === FinancialTransactionService::WALLET_ADMIN) {
                    $adminBalanceFormatted = number_format((float)$entry['balance'], 2, ',', '.');
                    Notifications::insertNotifications(
                        $tenantId,
                        $adminId,
                        "Saldo insuficiente",
                        "Seu saldo atual é de <b>R$ {$adminBalanceFormatted}</b>. 
                              É necessário realizar uma nova recarga para continuar utilizando os serviços.",
                        'alert'
                    );

                    return new Response(403, [
                        'status' => 403,
                        'message' => 'Ocorreu um erro! Favor contate o administrador do sistema.',
                        'admin_balance' => (float)$entry['balance'],
                        'need' => (float)$entry['amount'],
                        'frontend_estimate' => $totalGeralFrontend
                    ], 'application/json');
                }

                if ((string)$entry['wallet'] === FinancialTransactionService::WALLET_RESELLER && (int)$entry['user_id'] === (int)$userId) {
                    return new Response(403, [
                        'status' => 403,
                        'message' => 'Saldo insuficiente para o revendedor.',
                        'reseller_balance' => (float)$entry['balance'],
                        'need' => (float)$entry['amount'],
                        'frontend_estimate' => $totalGeralFrontend
                    ], 'application/json');
                }

                if ((int)$entry['user_id'] === (int)$userId && (string)$entry['wallet'] === FinancialTransactionService::WALLET_ADMIN) {
                    UserPlans::deactivatePlan($currentPlan, $tenantId, $userId);

                    return new Response(403, [
                        'status' => 403,
                        'message' => 'Saldo insuficiente. Plano desativado.',
                        'balance' => (float)$entry['balance'],
                        'necessary' => (float)$entry['amount'],
                        'frontend_estimate' => $totalGeralFrontend
                    ], 'application/json');
                }
            }

            return new Response(403, [
                'status' => 403,
                'message' => 'Saldo insuficiente na cadeia financeira da campanha.',
                'error' => $e->getMessage(),
                'frontend_estimate' => $totalGeralFrontend
            ], 'application/json');
        }

        // =======================
        // 🔹 Campanha sem áudio → ignora toda a lógica de áudio
        // =======================
        $hasAudio =
            !empty($audioFiles) ||
            (is_array($audiosOrigin) && count($audiosOrigin) > 0);

        if (!$hasAudio) {
            // campanha sem áudio, segue o fluxo normalmente
            $allAudios = [];
            $currentAudios = 0;
            // NÃO retorna erro
        } else {

            // =======================
            // Verificar áudios já existentes no Asterisk (limite máximo)
            // =======================
            $maxAudios = 5;

            try {
                $asterisk = new AsteriskExtensionsSip();
                $result = $asterisk->listAudios();

                if (
                    !isset($result['ok'], $result['status']) ||
                    (int)$result['ok'] !== 1 ||
                    (int)$result['status'] !== 200
                ) {
                    return new Response(
                        502,
                        ['status' => 502, 'message' => 'Erro ao consultar Asterisk.'],
                        'application/json'
                    );
                }

                $dataAsterisk = is_array($result['data'] ?? null) ? $result['data'] : [];

                $filtered = array_filter($dataAsterisk, function ($audio) use ($tenantId, $userId) {
                    return isset($audio['tenant_id'], $audio['user_id']) &&
                        $audio['tenant_id'] === $tenantId &&
                        (int)$audio['user_id'] === (int)$userId;
                });

                $currentAudios = count($filtered);

            } catch (Throwable $e) {
                return new Response(
                    500,
                    [
                        'status' => 500,
                        'message' => 'Erro ao consultar Asterisk.',
                        'details' => $e->getMessage()
                    ],
                    'application/json'
                );
            }

            // =======================
            // Processar áudios (upload + existentes)
            // =======================
            $allAudios = self::processAudios(
                $audioFiles,
                $audiosOrigin,
                (string)$tenantId,
                $userId,
                $userRole
            );

            if (empty($allAudios)) {
                return new Response(
                    400,
                    ['status' => 400, 'message' => 'Nenhum áudio válido foi enviado.'],
                    'application/json'
                );
            }

            $totalAfter = $currentAudios + count($allAudios);

            if ($totalAfter > $maxAudios) {
                return new Response(
                    400,
                    [
                        'status' => 400,
                        'message' =>
                            "Limite de {$maxAudios} áudios atingido. Você já possui {$currentAudios}."
                    ],
                    'application/json'
                );
            }
        }


        // =======================
        // Principal e DTMF (somente se houver áudio)
        // =======================

        // ⚠️ garanta qual variável é a lista final de áudios válidos
        // aqui vou assumir que a lista final é $allAudios (que vem do processAudios)
        // se você usa $validatedAudios em outro lugar, ajuste para apontar para a correta:
        $validatedAudios = $allAudios ?? [];

        $mainAudio = null; // ou "sound:..." quando houver áudio

        if (!empty($validatedAudios)) {

            // principal
            $mainAudioInfo = $validatedAudios[0];
            $relativePath = str_replace('/var/lib/asterisk/sounds/', '', $mainAudioInfo['path']);
            $mainAudioNoExt = preg_replace('/\.(wav|ulaw|gsm|alaw)$/i', '', $relativePath);
            $mainAudio = "sound:" . $mainAudioNoExt;

            // dtmf
            $dtmfAudios = array_slice($validatedAudios, 1);
            foreach ($dtmf as $i => &$item) {
                if (!isset($dtmfAudios[$i])) continue;

                $relDtmf = str_replace('/var/lib/asterisk/sounds/', '', $dtmfAudios[$i]['path']);
                $item['audio'] = "sound:" . preg_replace('/\.(wav|ulaw|gsm|alaw)$/i', '', $relDtmf);
            }

        } else {
            // campanha sem áudio: garante que não fica lixo nos dtmfs
            foreach ($dtmf as &$item) {
                if (isset($item['audio'])) unset($item['audio']); // ou $item['audio'] = null;
            }
        }
        unset($item);


        // =======================
        // Criar job no Redis e enfileirar contatos
        // =======================
        $jobId = "job:voice:" . bin2hex(random_bytes(10));
        $jobIdCampaign = bin2hex(random_bytes(10));

        if ($scheduleMode === 'scheduled') {
            $scheduledPayload = [
                'job_id' => $jobId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role' => $userRole,
                'campaign_name' => $name,
                'campaign_type' => $campaignTypeRequested,
                'variable_type' => $variableTypeReal,
                'voice_list_id' => $voiceListId,
                'contact_list_id' => $idList,
                'contacts' => $contactList,
                'rate' => $rate,
                'queue_id' => $queueId,
                'dial_strategy' => $strategy,
                'record_calls' => $recordCalls,
                'sip_trunk' => $sip_trunk,
                'trunk_id' => $trunkId,
                'trunk_name' => $trunkName,
                'tech_prefix' => $techPrefix,
                'caller_id' => $callerIdNumber,
                'caller_id_name' => $callerIdName,
                'call_minute_cost' => $callMinuteCost,
                'torpedo_cost' => $valorTorpedo,
                'taxa_of_service' => $taxaOfService,
                'sms_cost' => $smsCost,
                'trunk_billing_type' => $trunkBillingType,
                'plan_id' => $planIdUsed,
                'tariff_used' => $tariffUsedForCdr,
                'audio' => [
                    'main' => $mainAudio,
                    'dtmf' => $dtmf
                ],
                'sms' => $hasSmsDirect ? [
                    'text' => $smsText,
                    'service' => $smsService,
                    'status' => $smsStatus
                ] : null,
                'action' => $action,
                'endpoints' => $endpoints,
                'scheduled_at' => $scheduledAt,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $scheduleId = CampaignVoiceSchedule::create([
                'user_id' => $userId,
                'tenancy_id' => $tenantId,
                'name' => $name,
                'type' => ($campaignTypeRequested === 'torpedo') ? 'torpedo' : 'voice',
                'total_contacts' => count($contactList),
                'job_id' => $jobId,
                'queue_id' => $queueId ?? '',
                'status' => 'pending',
                'scheduled_at' => $scheduledAt,
                'payload_json' => json_encode($scheduledPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'note' => 'Agendamento criado pelo assistente de campanha de voz'
            ]);

            return new Response(200, [
                'success' => true,
                'scheduled' => true,
                'schedule_id' => $scheduleId,
                'scheduled_at' => $scheduledAt,
                'message' => 'Campanha agendada com sucesso.'
            ], 'application/json');
        }

        $redis->hMSet("campaign:{$jobId}", [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'type' => 'voice',
            'created' => time(),
            'total' => count($contactList),
            'processed' => 0,
            'status' => 'pending',
            'campaign_name' => $name
        ]);
        $redis->expire("campaign:{$jobId}", 86400);

        $payloadBackupKey = "campaign:{$jobId}:payload_backup";
        $payloadBackupTtl = 86400 * 7;
        $redis->del($payloadBackupKey);

        $campaign = new CampaignVoice();
        $campaign->user_id        = $userId;
        $campaign->tenancy_id     = $tenantId;
        $campaign->name           = $name;
        $campaign->type           = $campaignTypeRequested;
        $campaign->job_id         = $jobId;
        $campaign->queue_id       = $queueId ?? '';
        $campaign->total_contacts = count($contactList);
        $campaign->status         = 'y';

        if (!$campaign->create()) {

            return new Response(500, [
                'status' => 500,
                'message' => 'Erro ao criar campanha'
            ], 'application/json');
        }

        $campaignId = $campaign->id; // 👈 ISSO É O PONTO-CHAVE

        //echo "<pre>";
        //print_r($recordCalls);
        //echo "</pre>";exit();

        // payload comum para cada contato
        foreach ($contactList as $dest) {
            $callId = 'call:' . bin2hex(random_bytes(12));
            $payload = [
                'job_id' => $jobId,
                'call_id' => $callId,
                'voice_list_id' => $voiceListId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'campaign_id'   => $campaignId,
                'campaign_type' => $campaignTypeRequested,
                'queue_id' => $queueId,

                'record_calls' => $recordCalls,

                'phone' => $dest,
                'extension' => $dest,

                'role' => $userRole,
                'trunk' => $sip_trunk,
                'trunk_id' => $trunkId,
                'trunk_name' => $trunkName,
                'tech_prefix' => $techPrefix,
                'strategy' => $strategy,

                'call_minute_cost' => $callMinuteCost,
                'torpedo_cost' => $valorTorpedo,
                'taxa_of_service' => $taxaOfService,
                'sms_cost' => $smsCost,
                'trunk_billing_type' => $trunkBillingType,
                'plan_id' => $planIdUsed,
                'tariff_used' => $tariffUsedForCdr,

                'variable_type' => $variableTypeReal,
                'rate' => $rate,
                'caller_id' => $callerIdNumber,
                'caller_id_name' => $callerIdName,

                'audio' => [
                    'main' => $mainAudio,
                    'dtmf' => $dtmf
                ],

                'sms' => $hasSmsDirect ? [
                    'text' => $smsText,
                    'service' => $smsService,
                    'status' => $smsStatus
                ] : null,

                'action' => $action,

                'endpoints' => $endpoints,

                'timestamp' => time()
            ];

            $rawPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $redis->rPush("voice:queue", $rawPayload);
            $redis->rPush("queue:originate:{$jobId}", $dest);
            $redis->rPush($payloadBackupKey, $rawPayload);
        }

        $redis->expire($payloadBackupKey, $payloadBackupTtl);

        //echo "<pre>";
        //print_r($payload);
        //echo "</pre>";exit();

        //exit;

        // =======================
        // Retorno final
        // =======================
        return new Response(200, [
            'success' => true,
            //'campaign_name' => $name,
            //'campaign_job_id' => $jobId,
            //'contacts' => count($contactList),
            //'main_audio' => $mainAudio,
            //'all_audios' => $validatedAudios,
            //'dtmf' => $dtmf
        ], 'application/json');
    }


    private static function processAudios(?array $audioFiles, array $audiosOrigin, string $tenantId, int $userId, string $userRole): array
    {
        /*
        |--------------------------------------------------------------------------
        | Diretório remoto baseado no perfil
        |--------------------------------------------------------------------------
        */
        $remoteDir = match ($userRole) {
            'super_admin' => "/var/lib/asterisk/sounds/voice/super_admin/",
            'admin' => "/var/lib/asterisk/sounds/voice/tenant_{$tenantId}/admin_{$userId}/",
            'reseller' => "/var/lib/asterisk/sounds/voice/tenant_{$tenantId}/reseller_{$userId}/",
            default => "/var/lib/asterisk/sounds/voice/tenant_{$tenantId}/user_{$userId}/",
        };

        $maxAudios = 5;
        $uploadedAudios = [];

        /*
        |--------------------------------------------------------------------------
        | UPLOAD DE NOVOS ÁUDIOS
        |--------------------------------------------------------------------------
        */
        if ($audioFiles && isset($audioFiles['tmp_name'])) {

            foreach ($audioFiles['tmp_name'] as $i => $tmpName) {

                if (count($uploadedAudios) >= $maxAudios) {
                    break;
                }

                // extensão
                $ext = strtolower(pathinfo($audioFiles['name'][$i], PATHINFO_EXTENSION));
                if (!in_array($ext, ['wav', 'ulaw', 'gsm', 'alaw'])) {
                    $ext = 'wav';
                }

                $base = pathinfo($audioFiles['name'][$i], PATHINFO_FILENAME);

                // nome único
                try {
                    $unique = "{$base}-" . bin2hex(random_bytes(6)) . ".{$ext}";
                } catch (\Exception) {
                    $unique = "{$base}-" . uniqid() . ".{$ext}";
                }

                $file = [
                    'name' => $unique,
                    'tmp_name' => $tmpName,
                    'type' => $audioFiles['type'][$i] ?? 'audio/wav',
                    'size' => $audioFiles['size'][$i] ?? 0,
                    'error' => $audioFiles['error'][$i] ?? 0,
                ];

                $uploader = new AudioUploadFtpAsterisk(
                    $file,
                    TelephonyConfig::ftpHost(),
                    TelephonyConfig::ftpUser(),
                    TelephonyConfig::ftpPass(),
                    $remoteDir
                );

                if (!$uploader->upload()) {
                    continue;
                }

                $uploadedAudios[] = [
                    'file_name' => $unique,
                    'path' => "{$remoteDir}{$unique}",
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'role' => $userRole
                ];

                // registra no banco
                self::registerAsteriskAudio(
                    $tenantId,
                    $userId,
                    $base,
                    $userRole,
                    $unique,
                    $remoteDir
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | TRATAMENTO DOS ÁUDIOS EXISTENTES ENVIADOS PELO FRONT
        |--------------------------------------------------------------------------
        | Pode vir:
        | - URL com token (legado)
        | - Caminho direto "tenant_32/admin_9/audio.wav"
        |--------------------------------------------------------------------------
        */
        $existingAudios = [];

        foreach ($audiosOrigin as $origin) {

            if (empty($origin)) {
                continue;
            }

            // ---------------------------------------------------------
            // 1) Formato legado: URL com token
            // ---------------------------------------------------------
            if (str_contains($origin, '/audio.php?token=')) {

                $parts = parse_url($origin);
                parse_str($parts['query'] ?? '', $params);

                $token = $params['token'] ?? null;
                if (!$token) continue;

                $decoded = base64_decode($token, true);
                if (!$decoded) continue;

                $clean = ltrim($decoded, '/');

                $existingAudios[] = [
                    'file_name' => basename($clean),
                    'path' => "/var/lib/asterisk/sounds/voice/{$clean}",
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'role' => $userRole
                ];

                continue;
            }

            // ---------------------------------------------------------
            // 2) Novo formato: caminho direto
            // Ex: "tenant_32/admin_9/audio.wav"
            // ---------------------------------------------------------
            $clean = trim($origin, '/');

            $existingAudios[] = [
                'file_name' => basename($clean),
                'path' => "/var/lib/asterisk/sounds/voice/{$clean}",
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role' => $userRole
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Retorno final consolidado
        |--------------------------------------------------------------------------
        */
        return array_merge($uploadedAudios, $existingAudios);
    }

    public static function getPriceVoiceList($request): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, ['error' => 'Unauthorized'], 'application/json');
        }

        $tenantId = $obUser['tenancy_id'];
        $userId = (int)$obUser['id'];
        $planId = RegisterTenancies::getActivePlanId($tenantId) ?: ($obUser['plan_id'] ?? null);

        // 🔍 Verifica se é RESELLER
        $isReseller = strtolower($obUser['function']) === 'reseller';

        // =========================================
        // 👉 1. RESELLER → BUSCA TARIFA NA RATES
        // =========================================
        if ($isReseller) {

            $whatsappCategories = WhatsAppBilling::categoryPricesForUser((int)$userId, (string)$tenantId);

            try {
                $planRates = VoicePricingService::planRates($userId, (string)$tenantId, $planId ? (int)$planId : null);
            } catch (Throwable $e) {
                return new Response(422, [
                    'status' => 422,
                    'message' => 'Falha ao carregar tarifas reais do plano: ' . $e->getMessage(),
                ], 'application/json');
            }

            return new Response(200, [
                'success' => true,
                'type' => 'reseller',
                'data' => [
                    'voice' => (float)$planRates['voice_smart_rate'],
                    'voice_open_rate' => (float)$planRates['voice_open_rate'],
                    'voice_smart_rate' => (float)$planRates['voice_smart_rate'],
                    'sms' => (float)$planRates['sms'],
                    'torpedo' => (float)$planRates['torpedo'],
                    'whatsapp' => (float)($whatsappCategories['marketing'] ?? 0),
                    'whatsapp_categories' => $whatsappCategories
                ]
            ], 'application/json');
        }

        // =========================================
        // 👉 2. USUÁRIO NORMAL / ADMIN → BUSCA NO BALANCE
        // =========================================
        $whatsappCategories = WhatsAppBilling::categoryPricesForUser((int)$userId, (string)$tenantId);

        try {
            $planRates = VoicePricingService::planRates($userId, (string)$tenantId, $planId ? (int)$planId : null);
        } catch (Throwable $e) {
            return new Response(422, [
                'status' => 422,
                'message' => 'Falha ao carregar tarifas reais do plano: ' . $e->getMessage(),
            ], 'application/json');
        }

        return new Response(200, [
            'success' => true,
            'type' => 'balance',
            'data' => [
                'voice' => (float)$planRates['voice_smart_rate'],
                'voice_open_rate' => (float)$planRates['voice_open_rate'],
                'voice_smart_rate' => (float)$planRates['voice_smart_rate'],
                'sms' => (float)$planRates['sms'],
                'torpedo' => (float)$planRates['torpedo'],
                'whatsapp' => (float)($whatsappCategories['marketing'] ?? 0),
                'whatsapp_categories' => $whatsappCategories
            ]
        ], 'application/json');
    }

    private static function registerAsteriskAudio(
        string $tenantId,
        int $userId,
        string $name,
        string $role,
        string $uniqueName,
        string $fullPath,
        ?int $sizeBytes = null,
        ?float $durationSeconds = null,
        ?string $extension = null
    ): void {
        $client = new AsteriskExtensionsSip();

        $payload = [
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'name' => $name,
            'role' => $role,
            'file_name' => $uniqueName,
            'path' => $fullPath,
            'size_bytes' => $sizeBytes,
            'duration_seconds' => $durationSeconds,
            'extension' => $extension,
        ];

        $client->createAudio([], $payload);
    }

    /**
     * @return Response função resposavel pelo audios fora da campanha     *
     * @throws RandomException
     */

    public static function setUploadAudiosAsterisk(): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $tenantId = $obUser['tenancy_id'];
        $userId = $obUser['id'];
        $userRole = $obUser['function'];

        if (!isset($_FILES['file'])) {
            return new Response(400, [
                'error' => 'Nenhum arquivo recebido.'
            ], 'application/json');
        }

        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new Response(400, ['error' => 'Falha no upload.'], 'application/json');
        }

        $baseName = $_POST['name'] ?? pathinfo($file['name'], PATHINFO_FILENAME);
        $baseName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $baseName);

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['wav', 'ulaw', 'gsm', 'alaw', 'mp3'])) {
            $ext = 'wav';
        }

        $uniqueName = "{$baseName}-" . bin2hex(random_bytes(5)) . ".{$ext}";

        $remoteDir = match ($userRole) {
            'super_admin' => "/var/lib/asterisk/sounds/voice/super_admin/",
            'admin' => "/var/lib/asterisk/sounds/voice/tenant_{$tenantId}/admin_{$userId}/",
            'reseller' => "/var/lib/asterisk/sounds/voice/tenant_{$tenantId}/reseller_{$userId}/",
            default => "/var/lib/asterisk/sounds/voice/tenant_{$tenantId}/user_{$userId}/",
        };

        try {
            $asterisk = new AsteriskExtensionsSip();
            $result = $asterisk->listAudios();

            if (!isset($result['ok'], $result['status']) ||
                $result['ok'] != 1 || $result['status'] != 200) {
                return new Response(502, ['details' => $result], 'application/json');
            }

            $dataAsterisk = $result['data'] ?? [];

            $filtered = array_filter($dataAsterisk, function ($audio) use ($tenantId, $userId) {
                return $audio['tenant_id'] === $tenantId &&
                    (int)$audio['user_id'] === (int)$userId;
            });

            $currentAudios = count($filtered);

        } catch (\Throwable $e) {
            return new Response(500, ['error' => 'Erro ao consultar Asterisk.'], 'application/json');
        }

        $maxAudios = 5;
        if ($currentAudios >= $maxAudios) {
            return new Response(400, [
                'error' => "Limite de {$maxAudios} áudios atingido. Você já possui {$currentAudios}."
            ], 'application/json');
        }

        // METADADOS DO ARQUIVO LOCAL
        $tmpFile = $file['tmp_name'];
        $sizeBytes = (int) ($file['size'] ?? 0);
        $durationSeconds = self::getAudioDuration($tmpFile);
        $extension = $ext;

        $uploadFile = [
            'name' => $uniqueName,
            'type' => $file['type'],
            'tmp_name' => $tmpFile,
            'error' => $file['error'],
            'size' => $file['size'],
        ];

        $uploader = new AudioUploadFtpAsterisk(
            $uploadFile,
            TelephonyConfig::ftpHost(),
            TelephonyConfig::ftpUser(),
            TelephonyConfig::ftpPass(),
            $remoteDir
        );

        if (!$uploader->upload()) {
            return new Response(500, [
                'error' => "Falha ao enviar via FTP."
            ], 'application/json');
        }

        $fullPath = "{$remoteDir}{$uniqueName}";

        self::registerAsteriskAudio(
            $tenantId,
            $userId,
            $baseName,
            $userRole,
            $uniqueName,
            $fullPath,
            $sizeBytes,
            $durationSeconds,
            $extension
        );

        return new Response(200, [
            'status' => 'success',
            'file' => $uniqueName,
            'path' => $fullPath,
            'size_bytes' => $sizeBytes,
            'duration_seconds' => $durationSeconds,
            'extension' => $extension
        ], 'application/json');
    }

    private static function getAudioDuration(string $filePath): ?float
    {
        if (!is_file($filePath)) {
            return null;
        }

        if (!function_exists('shell_exec')) {
            return null;
        }

        $ffprobe = 'C:\\Users\\netoa\\Downloads\\ffmpeg-2026-03-15-git-6ba0b59d8b-essentials_build\\ffmpeg-2026-03-15-git-6ba0b59d8b-essentials_build\\bin\\ffprobe.exe';

        if (!is_file($ffprobe)) {
            return null;
        }

        $cmd = '"' . $ffprobe . '" -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "' . $filePath . '"';

        $output = shell_exec($cmd);

        if (!is_string($output) || trim($output) === '') {
            return null;
        }

        return round((float) trim($output), 2);
    }


    public static function getAudiosFilesAsterisk(): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        // ⚙️ Parâmetros básicos da requisição
        $query = [
            'tenant_id' => $obUser['tenancy_id'],
            'user_id' => $obUser['id'],
            'role' => strtolower($obUser['function']),
            'function' => $obUser['function'],
            'category'    => 'upload'
        ];

        // 🔗 Chama o cliente Asterisk
        $asterisk = new AsteriskExtensionsSip();
        $result = $asterisk->listAudios($query);

        // ❌ Falha na requisição
        if (empty($result['ok'])) {
            return new Response(
                $result['status'] ?? 500,
                [
                    'status' => $result['status'] ?? 500,
                    'ok' => false,
                    'error' => $result['error'] ?? 'Falha ao consultar áudios no Asterisk',
                    'info' => $result['info'] ?? null,
                ],
                'application/json'
            );
        }

        // ✅ Corrige camada aninhada (caso o retorno venha dentro de 'data' → 'data')
        $data = $result['data']['data'] ?? $result['data'] ?? [];

        // 🧩 Filtro local conforme função do usuário
        switch (strtolower($obUser['function'])) {
            case 'super_admin':
                break;

            case 'admin':
                $data = array_filter($data, fn($audio) => isset($audio['tenant_id']) && $audio['tenant_id'] === $obUser['tenancy_id']
                );
                break;

            case 'reseller':
            default:
                $data = array_filter($data, fn($audio) => isset($audio['user_id']) && (int)$audio['user_id'] === (int)$obUser['id']
                );
                break;
        }

        // 🔹 Reindexa os resultados após o filtro
        $data = array_values(array_map(static function (array $trunk): array {
            $billingType = VoicePricingService::normalizeBillingType($trunk['billing_type'] ?? null)
                ?? VoicePricingService::normalizeBillingType($trunk['cli_type'] ?? null);

            if ($billingType !== null) {
                $trunk['billing_type'] = $billingType;
                $trunk['cli_type'] = VoicePricingService::cliTypeForBilling($billingType);
            }

            return $trunk;
        }, $data));

        // ============================================================
        // 🔊 Gera URL pública acessível via audio.php
        // ============================================================

        $baseUrl = TelephonyConfig::audioScriptUrl();

        foreach ($data as &$audio) {
            if (!empty($audio['path'])) {
                // remove prefixo absoluto
                $relativePath = str_replace('/var/lib/asterisk/sounds/voice/', '', $audio['path']);

                // remove o tenant_id do início (ex: tenant_xxxxx/)
                $relativePathClean = preg_replace('/^tenant_[a-z0-9\-]+\//i', '', $relativePath);

                // gera token codificado (para não expor caminho real)
                $token = base64_encode($relativePath); // pode usar hash_hmac se quiser mais seguro

                // 🔹 URL final com token seguro
                $audio['url'] = "{$baseUrl}?token=" . urlencode($token);
                $audio['display_name'] = basename($relativePathClean); // exibe só o nome do arquivo no front
                $audio['token'] = $token;
                $audio['duration'] = $audio['duration_seconds'] ?? 0;

            } else {
                $audio['url'] = null;
                $audio['display_name'] = null;
                $audio['token'] = null;
            }
        }
        unset($audio);


        // ✅ Retorna formato padronizado
        return new Response(200, [
            'status' => 200,
            'success' => true,
            'message' => 'Áudios encontrados com sucesso.',
            'total' => count($data),
            'data' => $data,
        ], 'application/json');
    }


    public static function setAudiosDelete($Request): Response
    {
        // ============================================================
        // 🔐 0. Usuário logado
        // ============================================================
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'success' => false,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        // ============================================================
        // 📥 1. Leitura do JSON + validações básicas
        // ============================================================
        $inputData = json_decode(file_get_contents('php://input'), true);

        if (!is_array($inputData)) {
            return new Response(400, [
                'status' => 400,
                'success' => false,
                'message' => 'Payload inválido.'
            ], 'application/json');
        }

        if (empty($inputData['token'])) {
            return new Response(400, [
                'status' => 400,
                'success' => false,
                'message' => 'Token não enviado.'
            ], 'application/json');
        }

        // id obrigatório e válido
        $id = isset($inputData['id']) ? (int)$inputData['id'] : 0;
        if ($id <= 0) {
            return new Response(400, [
                'status' => 400,
                'success' => false,
                'message' => 'ID inválido.'
            ], 'application/json');
        }

        $token = trim($inputData['token']);
        if ($token === '') {
            return new Response(400, [
                'status' => 400,
                'success' => false,
                'message' => 'Token vazio.'
            ], 'application/json');
        }

        // ============================================================
        // 🔐 2. Decodificação segura do token
        // ============================================================
        // Mantemos base64_decode com strict, mas garantimos que result será tratado
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return new Response(400, [
                'status' => 400,
                'success' => false,
                'message' => 'Token inválido (base64).'
            ], 'application/json');
        }

        // Trim, remover bytes nulos e normalizar separadores
        $decoded = str_replace("\0", '', trim($decoded));
        $decoded = str_replace('\\', '/', $decoded); // normaliza barras

        // ============================================================
        // 🔧 2.a Normalizar para caminho relativo seguro
        // ============================================================
        // Base do armazenamento (sempre com barra final)
        $basePath = '/var/lib/asterisk/sounds/voice/';
        $basePathNormalized = rtrim($basePath, '/') . '/';

        // Se o token continha o basePath inteiro, removemos para garantir relativo
        if (str_starts_with($decoded, $basePathNormalized)) {
            $decoded = substr($decoded, strlen($basePathNormalized));
        }

        // Remove possíveis "/" iniciais
        $relativePathRaw = ltrim($decoded, '/');

        // Função de normalização que resolve "." e ".." sem usar realpath
        $normalizeSegments = function (string $path) {
            $parts = explode('/', $path);
            $stack = [];
            foreach ($parts as $part) {
                if ($part === '' || $part === '.') continue;
                if ($part === '..') {
                    // descarta um segmento anterior se houver
                    if (!empty($stack)) array_pop($stack);
                    // se não houver, ignora (não permite subir acima)
                    continue;
                }
                $stack[] = $part;
            }
            return implode('/', $stack);
        };

        $relativePath = $normalizeSegments($relativePathRaw);

        // Recheca se ficou vazia
        if ($relativePath === '') {
            return new Response(400, [
                'status' => 400,
                'success' => false,
                'message' => 'Caminho inválido após normalização.'
            ], 'application/json');
        }

        // ============================================================
        // 🛡️ 3. Validação da estrutura do caminho (tenant / user / admin)
        // ============================================================
        $role = strtolower($obUser['function']);
        $tenant = $obUser['tenancy_id'];
        $userId = (int)$obUser['id'];

        // Validar extensão permitida
        $allowedExtensions = ['wav', 'gsm', 'mp3', 'ulaw', 'alaw', 'sln'];
        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION) ?: '');

        if ($ext === '' || !in_array($ext, $allowedExtensions, true)) {
            return new Response(400, [
                'status' => 400,
                'success' => false,
                'message' => 'Extensão de arquivo não permitida.'
            ], 'application/json');
        }

        // Prefixo fixo do tenant
        $tenantPrefix = "tenant_{$tenant}/";

        // ============================================================
        // SUPER ADMIN → Acesso total
        // ============================================================
        if ($role === 'super_admin') {
            // Nenhuma validação extra
        }

        // ============================================================
        // ADMIN → Pode acessar *qualquer coisa* dentro do tenant
        // ============================================================
        elseif ($role === 'admin') {

            // Deve começar com tenant_<id>/
            if (!preg_match("#^{$tenantPrefix}#i", $relativePath)) {
                return new Response(403, [
                    'status' => 403,
                    'success' => false,
                    'message' => 'Você não pode acessar áudios de outro tenant.'
                ], 'application/json');
            }

            // Admin pode acessar qualquer pasta dentro do próprio tenant
            // Ex: admin_6, reseller_7, user_XXX, etc.
            // Portanto -> nada mais a validar
        }

        // ============================================================
        // RESELLER → Pode acessar apenas sua pasta reseller_<id>
        // ============================================================
        elseif ($role === 'reseller') {

            $pattern = "#^{$tenantPrefix}reseller_{$userId}(/.*)?$#i";

            if (!preg_match($pattern, $relativePath)) {
                return new Response(403, [
                    'status' => 403,
                    'success' => false,
                    'message' => 'Você só pode acessar seus próprios áudios de reseller.'
                ], 'application/json');
            }
        }

        // ============================================================
        // USER COMUM → Pode acessar apenas admin_<admin_id>
        // ============================================================
        else {

            $adminId = $obUser['admin_id'] ?? null;

            if (!$adminId) {
                return new Response(403, [
                    'status' => 403,
                    'success' => false,
                    'message' => 'Admin responsável não encontrado.'
                ], 'application/json');
            }

            $pattern = "#^{$tenantPrefix}admin_{$adminId}(/.*)?$#i";

            if (!preg_match($pattern, $relativePath)) {
                return new Response(403, [
                    'status' => 403,
                    'success' => false,
                    'message' => 'Você só pode acessar áudios dentro do admin responsável.'
                ], 'application/json');
            }
        }

        // ============================================================
        // 🔍 4. Verificar existência local (opcional)
        // ============================================================
        $fullPath = $basePathNormalized . $relativePath;
        $fileExistsLocal = file_exists($fullPath);

        // ============================================================
        // 🧹 5. Validar ID e preparar payload para Asterisk
        // ============================================================
        // Observação: ideal é verificar no banco se o registro com esse ID pertence ao tenant/user.
        // Se você tiver acesso ao DB, faça essa checagem aqui (recomendo fortemente).
        // Por ora, apenas garantimos que id > 0 (já feito), e passamos os dados para o Asterisk.

        $query = [
            'user_id' => $obUser['id'],
            'tenant_id' => $obUser['tenancy_id'],
        ];

        $payload = [
            'id' => $id,
            'tenant_id' => $obUser['tenancy_id'],
            'user_id' => $obUser['id'],
            'user_function' => strtolower($obUser['function']),
        ];

        // ============================================================
        // 🔌 6. Excluir no Asterisk (API externa)
        // ============================================================
        try {
            $asterisk = new AsteriskExtensionsSip();
            $response = $asterisk->deleteAudio($query, $payload);
        } catch (\Throwable $e) {
            error_log("[setAudiosListDelete] Erro ao chamar deleteAudio: " . $e->getMessage());
            return new Response(500, [
                'status' => 500,
                'success' => false,
                'message' => 'Erro ao excluir áudio no Asterisk (exceção).',
                'error' => $e->getMessage()
            ], 'application/json');
        }

        // Checagens robustas na resposta
        $asteriskOk = false;
        $asteriskStatus = $response['status'] ?? 500;
        $asteriskOk = array_key_exists('ok', $response) && (bool)$response['ok'];
        $asteriskError = $response['error'] ?? null;

        if (!$asteriskOk) {
            // Se resposta explicitamente indica falha, aborta
            return new Response($asteriskStatus ?: 500, [
                'status' => $asteriskStatus ?: 500,
                'success' => false,
                'message' => 'Erro ao excluir áudio no Asterisk.',
                'error' => $asteriskError ?? 'Resposta inválida da API Asterisk.'
            ], 'application/json');
        }

        // ============================================================
        // 🗂️ 7. Excluir fisicamente via FTP/SFTP (AsteriskUploadFtpAsterisk)
        // ============================================================
        // Preferível usar SFTP. Se sua classe já suporta SFTP, configure aqui.
        $ftpServer = TelephonyConfig::ftpHost();
        $ftpUser = TelephonyConfig::ftpUser();
        $ftpPass = TelephonyConfig::ftpPass();

        $ftp = new AudioUploadFtpAsterisk([], $ftpServer, $ftpUser, $ftpPass);

        // Caminho REAL usado no FTP (mesmo basePath)
        $ftpBaseDir = '/var/lib/asterisk/sounds/voice/';
        $ftpFullPath = rtrim($ftpBaseDir, '/') . '/' . $relativePath;

        $deletedFtp = false;
        $ftpWarning = null;

        try {
            $deletedFtp = $ftp->delete($ftpFullPath);
        } catch (\Throwable $e) {
            error_log("[setAudiosListDelete] Erro FTP ao deletar {$ftpFullPath}: " . $e->getMessage());
            $ftpWarning = 'Erro ao tentar excluir via FTP/SFTP: ' . $e->getMessage();
            //$deletedFtp = false;
        }

        // Se FTP retornou false, pode ser que o arquivo já não exista no servidor FTP.
        // Não tratamos isso como falha crítica se a API do Asterisk devolveu ok.
        if (!$deletedFtp) {
            // opcional: se sua classe tem método exists(), podemos checar para diferenciar "não encontrado" x "erro"
            if ($fileExistsLocal) {
                // Se arquivo estava local e ainda existe, tentamos remover local e retornamos aviso
                @unlink($fullPath);
                $fileExistsLocal = file_exists($fullPath);
            }

            // Se ainda existe local, consideramos warning.
            if ($fileExistsLocal) {
                // Não abortamos imediatamente, retornamos aviso ao cliente
                $ftpWarning = $ftpWarning ?? 'Arquivo não removido via FTP e ainda existe no disco.';
            } else {
                // arquivo local removido ou inexistente -> podemos prosseguir com sucesso
                $ftpWarning = $ftpWarning ?? 'Arquivo não encontrado no FTP (ou já removido).';
            }
        } else {
            // sucesso na exclusão via FTP — também tenta remover local (caso exista)
            if ($fileExistsLocal && file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }

        // ============================================================
        // ✅ 8. Resposta final (padronizada)
        // ============================================================
        $responsePayload = [
            'status' => 200,
            'success' => true,
            'message' => 'Áudio excluído com sucesso.',
            'file' => $relativePath
        ];

        if ($ftpWarning !== null) {
            // incluímos um campo warning para informar o usuário
            $responsePayload['warning'] = $ftpWarning;
        }

        return new Response(200, $responsePayload, 'application/json');
    }


    public static function setUploadVoiceList($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, json_encode([
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ]), 'application/json');
        }

        $file = $_FILES['fileInput'] ?? null;
        $voiceListName = $request->getPostVars()['listName'] ?? null;

        if (!$file || empty($file['tmp_name'])) {
            return new Response(422, json_encode([
                'status' => 422,
                'message' => 'Nenhum arquivo enviado.'
            ]), 'application/json');
        }

        if (!$voiceListName) {
            return new Response(422, json_encode([
                'status' => 422,
                'message' => 'A lista precisa ter um nome.'
            ]), 'application/json');
        }

        try {
            $service = new ImportContactsService($obUser, null, false, 'voice', (string)$voiceListName);
            $count = $service->import($file);

            return new Response(200, json_encode([
                'status' => 200,
                'message' => "Lista '{$voiceListName}' criada com {$count} contatos importados com sucesso.",
            ]), 'application/json');

        } catch (Exception $e) {
            return new Response(500, json_encode([
                'status' => 500,
                'message' => $e->getMessage()
            ]), 'application/json');
        }
    }


    public static function setVoiceListDelete($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, ([
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ]), 'application/json');
        }

        // Lê o JSON enviado
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['ids']) || !is_array($input['ids'])) {
            return new Response(400, ([
                'success' => false,
                'message' => 'Nenhuma lista informada para exclusão.'
            ]), 'application/json');
        }

        $deleted = 0;

        foreach ($input['ids'] as $voiceListId) {
            if (CampaignVoice::deleteVoiceList((int)$voiceListId)) {
                $deleted++;
            }
        }

        return new Response(200, ([
            'success' => true,
            'deleted' => $deleted,
            'message' => "$deleted lista(s) excluída(s) com sucesso."
        ]), 'application/json');
    }


    public static function getActiveCalls($request): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $user = SessionUser::getLogged();
        if (!$user) {
            echo "event: auth\n";
            echo 'data: ' . json_encode([
                    'status' => 401,
                    'message' => 'Usuário não autenticado'
                ]) . "\n\n";
            flush();
            return;
        }

        try {
            // ===== Conexão Redis =====
            $redis = new RedisClient(TelephonyConfig::redisConfig());

            $data = $redis->get('asterisk:active_calls');
            $payload = $data ? json_decode($data, true) : null;
            $calls = $payload['chamadas'] ?? [];
            $calls = self::hydrateDtmfAcrossRelatedCalls(is_array($calls) ? $calls : []);
            $calls = self::hydrateAnsweredStateAcrossRelatedCalls($calls);

            // ===== Normaliza dados do usuário =====
            $userRole = strtolower((string)($user['function'] ?? 'reseller'));
            $userIdStr = (string)($user['id'] ?? '');
            $tenantStr = (string)($user['tenancy_id'] ?? '');

            // ===== Helper p/ extrair identidade da chamada =====
            $extractIdentity = static function (array $call): array {
                $vars = $call['vars'] ?? [];
                $owner = $call['owner_id'] ?? ($vars['OWNER_ID'] ?? null);
                $tenant = $call['tenant_id'] ?? ($vars['TENANT_ID'] ?? null);
                $role = $call['role'] ?? ($vars['ROLE'] ?? null);

                return [
                    'owner' => (string)($owner ?? ''),
                    'tenant' => (string)($tenant ?? ''),
                    'role' => strtolower((string)($role ?? '')),
                ];
            };

            // ===== Filtro conforme papel =====
            $filtered = array_filter($calls, function (array $call) use ($userRole, $userIdStr, $tenantStr, $extractIdentity) {
                $id = $extractIdentity($call);

                return match ($userRole) {
                    'super_admin', 'root' => true,
                    'admin' => $id['tenant'] !== '' && $id['tenant'] === $tenantStr,
                    'reseller' => $id['owner'] !== '' && $id['owner'] === $userIdStr,
                    default => false,
                };
            });

            // ===== Normaliza dados e remove vars =====
            $formatted = array_map(function (array $call) use ($extractIdentity) {
                $id = $extractIdentity($call);
                $call['owner_id'] = $call['owner_id'] ?? $id['owner'];
                $call['tenant_id'] = $call['tenant_id'] ?? $id['tenant'];
                $call['role'] = $call['role'] ?? $id['role'];
                $call['dtmf'] = self::dtmfString($call['dtmf'] ?? $call['last_dtmf'] ?? null);
                $call['last_dtmf'] = self::dtmfString($call['last_dtmf'] ?? null);
                unset($call['vars']); // resposta mais leve
                return $call;
            }, $filtered);

            // ===== Envia evento SSE =====
            $response = [
                'statusGeral' => 'OK',
                'totalChamadas' => count($formatted),
                'chamadas' => array_values($formatted),
                'timestamp' => date('Y-m-d H:i:s'),
                'server_now' => time(),
            ];

            echo "event: calls\n";
            echo 'data: ' . json_encode($response, JSON_UNESCAPED_UNICODE) . "\n\n";
            ob_flush();
            flush();

            echo ": ping\n\n";
            ob_flush();
            flush();

            usleep(800000); // 0.8s

            // 🔹 Após enviar os dados, processa novas tarifações do Redis e grava na tabela CDR
            //self::processCdrFromRedis();

        } catch (Exception $e) {
            echo "event: error\n";
            echo 'data: ' . json_encode([
                    'message' => 'Erro ao conectar/ler Redis',
                    'erro' => $e->getMessage()
                ]) . "\n\n";
            ob_flush();
            flush();
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

    private static function hydrateAnsweredStateAcrossRelatedCalls(array $calls): array
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

        $answeredByRoot = [];
        foreach ($calls as $i => $call) {
            if (!is_array($call) || empty($keysByIndex[$i])) {
                continue;
            }

            if (!self::isConnectedLiveCall($call)) {
                continue;
            }

            $root = $find($keysByIndex[$i][0]);
            $answeredAt = (int)($call['answered'] ?? 0);
            $startedAt = (int)($call['started'] ?? 0);
            $score = max($answeredAt, $startedAt);

            if (!isset($answeredByRoot[$root]) || $score >= $answeredByRoot[$root]['score']) {
                $answeredByRoot[$root] = [
                    'score' => $score,
                    'answered' => $answeredAt,
                    'started' => $startedAt,
                ];
            }
        }

        foreach ($calls as $i => $call) {
            if (!is_array($call) || empty($keysByIndex[$i])) {
                continue;
            }

            $root = $find($keysByIndex[$i][0]);
            $groupAnswered = $answeredByRoot[$root] ?? null;
            $agentStatus = strtolower(trim((string)($call['agent_call_status'] ?? '')));
            $agentConfirmed = in_array($agentStatus, ['answered', 'up', 'bridged'], true)
                || !empty($call['agent_answered_at']);

            if (
                !$groupAnswered
                || self::hasAnsweredStatusLabel($call)
                || !self::isLikelyAgentLiveCallLeg($call)
                || !$agentConfirmed
            ) {
                continue;
            }

            $calls[$i]['status'] = 'Atendida';
            $calls[$i]['state'] = 'up';

            if (empty($calls[$i]['answered']) && !empty($groupAnswered['answered'])) {
                $calls[$i]['answered'] = $groupAnswered['answered'];
            }

            if (empty($calls[$i]['started']) && !empty($groupAnswered['started'])) {
                $calls[$i]['started'] = $groupAnswered['started'];
            }
        }

        return $calls;
    }

    private static function isConnectedLiveCall(array $call): bool
    {
        $state = strtolower(trim((string)($call['state'] ?? '')));
        if ($state === 'up') {
            return true;
        }

        if (!empty($call['in_bridge'])) {
            return true;
        }

        $agentStatus = strtolower(trim((string)($call['agent_call_status'] ?? '')));
        if (in_array($agentStatus, ['answered', 'up', 'bridged'], true)) {
            return true;
        }

        if (!empty($call['agent_answered_at']) || !empty($call['answered'])) {
            return true;
        }

        $status = mb_strtolower(trim((string)($call['status'] ?? '')));
        return str_contains($status, 'atendida');
    }

    private static function hasAnsweredStatusLabel(array $call): bool
    {
        $status = mb_strtolower(trim((string)($call['status'] ?? '')));
        return str_contains($status, 'atendida');
    }

    private static function isLikelyAgentLiveCallLeg(array $call): bool
    {
        $trunk = strtoupper(trim((string)($call['trunk'] ?? '')));
        if ($trunk === 'RAMAL') {
            return true;
        }

        $destination = self::onlyDigits((string)($call['destination'] ?? ''));
        if ($destination !== '' && self::isExtension($destination)) {
            return true;
        }

        $number = self::onlyDigits((string)($call['number'] ?? ''));
        return $number !== '' && self::isExtension($number);
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

    private static function onlyDigits(?string $v): string
    {
        return preg_replace('/\D+/', '', (string)$v);
    }

    private static function normalizeDigits(string $digits): string
    {
        // remove zeros à esquerda, mas mantém "0" se for tudo zero
        $n = ltrim($digits, '0');
        return $n === '' ? '0' : $n;
    }

    private static function isExtension(string $digits): bool
    {
        $digits = self::normalizeDigits($digits);

        if ($digits === '' || $digits === '0') return false;

        $len = strlen($digits);
        return ($len >= 3 && $len <= 8);
    }



    public static function isRamalCdr(?string $channelNumber, ?string $number, ?string $destination): bool
    {
        $ch = self::onlyDigits($channelNumber);
        $nu = self::onlyDigits($number);
        $ds = self::onlyDigits($destination);

        if ($ch !== '' && self::isExtension($ch)) return true;

        // comparação também normalizada (pra 01109739 == 1109739)
        $nuN = $nu !== '' ? self::normalizeDigits($nu) : '';
        $dsN = $ds !== '' ? self::normalizeDigits($ds) : '';

        // fallback MAIS SEGURO: só considera ramal quando number == destination
        if ($dsN !== '' && self::isExtension($ds) && $nuN !== '' && $nuN === $dsN) return true;

        // remove este aqui se quiser mais segurança:
        // if ($ds !== '' && self::isExtension($ds)) return true;

        return false;
    }

    /**
     * Consome a fila de tarifações gerada pelo Asterisk.
     *
     * Mantido como fachada para não quebrar chamadas existentes em rotas/telas.
     */
    public static function processCdrFromRedis(bool $emitDebug = false): void
    {
        VoiceCdrRedisProcessor::process(null, $emitDebug);
    }

    private static function applyCampaignRuntimeState(RedisClient $redis, array $campaigns): array
    {
        if ($campaigns === []) {
            return $campaigns;
        }

        $activeCallsByJob = self::activeCallsByJob($redis);
        $queuedByJob = self::queuedCallsByJob($redis, 'voice:queue');

        foreach ($campaigns as &$campaign) {
            if (($campaign['source'] ?? 'campaign') !== 'campaign') {
                continue;
            }

            $jobId = trim((string)($campaign['job_id'] ?? ''));
            if ($jobId === '') {
                continue;
            }

            $runtime = self::campaignRuntimeSnapshot(
                $redis,
                $jobId,
                $campaign,
                (int)($activeCallsByJob[$jobId] ?? 0),
                (int)($queuedByJob[$jobId] ?? 0)
            );

            $campaign = self::reconcileCampaignCountersFromCdr($campaign, $runtime);

            self::synchronizeCampaignRuntimeState($redis, $jobId, $campaign, $runtime);

            $campaign['status'] = $runtime['effective_status'];
            $campaign['runtime'] = $runtime;
        }
        unset($campaign);

        return $campaigns;
    }

    private static function activeCallsByJob(RedisClient $redis): array
    {
        $raw = $redis->get('asterisk:active_calls');
        $payload = $raw ? json_decode((string)$raw, true) : null;
        $calls = is_array($payload) ? (array)($payload['chamadas'] ?? []) : [];

        $counts = [];
        foreach ($calls as $call) {
            if (!is_array($call)) {
                continue;
            }

            $vars = is_array($call['vars'] ?? null) ? $call['vars'] : [];
            $jobId = trim((string)($call['job_id'] ?? $vars['JOB_ID'] ?? ''));
            if ($jobId === '') {
                continue;
            }

            $counts[$jobId] = (int)($counts[$jobId] ?? 0) + 1;
        }

        return $counts;
    }

    private static function queuedCallsByJob(RedisClient $redis, string $queueKey): array
    {
        $items = $redis->lrange($queueKey, 0, -1);
        if (!is_array($items) || $items === []) {
            return [];
        }

        $counts = [];
        foreach ($items as $raw) {
            $payload = json_decode((string)$raw, true);
            if (!is_array($payload)) {
                continue;
            }

            $jobId = trim((string)($payload['job_id'] ?? ''));
            if ($jobId === '') {
                continue;
            }

            $counts[$jobId] = (int)($counts[$jobId] ?? 0) + 1;
        }

        return $counts;
    }

    private static function campaignRuntimeSnapshot(
        RedisClient $redis,
        string $jobId,
        array $campaign,
        int $activeCalls,
        int $queuedCalls
    ): array {
        $pauseKey = "campaign:pause:job:{$jobId}";
        $jobHashKey = "campaign:{$jobId}";
        $pausedQueue = "voice:paused:job:{$jobId}";
        $finishedKey = "campaign:{$jobId}:finished";

        $jobHash = $redis->hgetall($jobHashKey);
        $jobHash = is_array($jobHash) ? $jobHash : [];

        $pausedCalls = (int)$redis->llen($pausedQueue);
        $pauseRequested = (bool)$redis->get($pauseKey);
        $finishedAt = (int)$redis->get($finishedKey);
        $backupTotal = (int)$redis->llen("campaign:{$jobId}:payload_backup");

        $totalContacts = max(
            (int)($campaign['total_contacts'] ?? 0),
            (int)($jobHash['total'] ?? 0)
        );
        $processed = (int)($jobHash['processed'] ?? 0);

        $dbStatus = strtolower(trim((string)($campaign['status'] ?? '')));
        $dbTotalCalls = (int)($campaign['total_calls'] ?? 0);

        $effectiveStatus = $dbStatus;
        $recoverablePayloads = 0;
        $knownCalls = 0;
        $orphaned = false;
        if ($pauseRequested || $pausedCalls > 0) {
            $effectiveStatus = 'n';
        } elseif ($queuedCalls > 0 || $activeCalls > 0) {
            $effectiveStatus = 'p';
        } elseif ($finishedAt > 0 || ($totalContacts > 0 && $dbTotalCalls >= $totalContacts)) {
            $effectiveStatus = 'f';
        } else {
            $knownCalls = self::countKnownCampaignCallIds($redis, $jobId);
            $recoverablePayloads = self::countRecoverableCampaignPayloads($redis, $jobId);

            if (
                $queuedCalls === 0
                && $activeCalls === 0
                && $pausedCalls === 0
                && $recoverablePayloads === 0
            ) {
                $effectiveStatus = 'f';
            } elseif ($backupTotal > 0 && $knownCalls >= $backupTotal) {
                $effectiveStatus = 'f';
            } else {
                if ($recoverablePayloads > 0) {
                    $effectiveStatus = 'n';
                    $orphaned = true;
                } elseif (in_array($dbStatus, ['p', 'y'], true) && $queuedCalls === 0 && $activeCalls === 0) {
                    $effectiveStatus = 'f';
                }
            }
        }

        if ($effectiveStatus === 'f') {
            $recoverablePayloads = 0;
            $orphaned = false;
        }

        return [
            'effective_status' => $effectiveStatus,
            'db_status' => $dbStatus,
            'active_calls' => $activeCalls,
            'queued_calls' => $queuedCalls,
            'paused_calls' => $pausedCalls,
            'pause_requested' => $pauseRequested,
            'processed' => $processed,
            'total' => $totalContacts,
            'backup_total' => $backupTotal,
            'known_calls' => $knownCalls,
            'recoverable_payloads' => $recoverablePayloads,
            'orphaned' => $orphaned,
            'finished_at' => $finishedAt > 0 ? date('Y-m-d H:i:s', $finishedAt) : null,
        ];
    }

    private static function reconcileCampaignCountersFromCdr(array $campaign, array $runtime): array
    {
        if (($campaign['source'] ?? 'campaign') !== 'campaign') {
            return $campaign;
        }

        $campaignId = (int)($campaign['id'] ?? 0);
        $jobId = trim((string)($campaign['job_id'] ?? ''));
        if ($campaignId <= 0 || $jobId === '') {
            return $campaign;
        }

        $dbTotalCalls = (int)($campaign['total_calls'] ?? 0);
        $effectiveStatus = strtolower(trim((string)($runtime['effective_status'] ?? '')));
        $shouldSync = $dbTotalCalls === 0
            || $effectiveStatus === 'f';

        if (!$shouldSync) {
            return $campaign;
        }

        $counts = CampaignVoice::syncCountersFromCdr($campaignId, $jobId);
        $campaign['total_calls'] = (int)($counts['total_calls'] ?? 0);
        $campaign['answered_calls'] = (int)($counts['answered_calls'] ?? 0);
        $campaign['failed_calls'] = (int)($counts['failed_calls'] ?? 0);

        return $campaign;
    }

    private static function synchronizeCampaignRuntimeState(
        RedisClient $redis,
        string $jobId,
        array $campaign,
        array $runtime
    ): void {
        $effectiveStatus = strtolower(trim((string)($runtime['effective_status'] ?? '')));
        if ($effectiveStatus === '') {
            return;
        }

        $dbStatus = strtolower(trim((string)($campaign['status'] ?? '')));
        if ($dbStatus !== $effectiveStatus) {
            CampaignVoice::updateStatusByJob($jobId, $effectiveStatus);
        }

        $jobHashKey = "campaign:{$jobId}";
        $finishedKey = "campaign:{$jobId}:finished";

        if ($effectiveStatus === 'f') {
            if (!$redis->get($finishedKey)) {
                $redis->set($finishedKey, (string)time());
                $redis->expire($finishedKey, 86400 * 7);
            }

            $redis->hset($jobHashKey, 'status', 'finished');
            $redis->del("campaign:pause:job:{$jobId}");
            return;
        }

        if ($effectiveStatus === 'n' && !empty($runtime['orphaned'])) {
            $redis->hset($jobHashKey, 'status', 'paused_orphaned');
            return;
        }

        if ($effectiveStatus === 'p') {
            $redis->hset($jobHashKey, 'status', 'processing');
        }
    }

    private static function countRecoverableCampaignPayloads(RedisClient $redis, string $jobId): int
    {
        $backupKey = "campaign:{$jobId}:payload_backup";
        $backupItems = $redis->lrange($backupKey, 0, -1);
        if (!is_array($backupItems) || $backupItems === []) {
            return 0;
        }

        $knownCallIds = self::knownCampaignCallIds($redis, $jobId);
        $recoverable = 0;

        foreach ($backupItems as $raw) {
            $payload = json_decode((string)$raw, true);
            if (!is_array($payload)) {
                continue;
            }

            if (trim((string)($payload['job_id'] ?? '')) !== $jobId) {
                continue;
            }

            $callId = trim((string)($payload['call_id'] ?? ''));
            if ($callId === '' || isset($knownCallIds[$callId])) {
                continue;
            }

            $recoverable++;
        }

        return $recoverable;
    }

    private static function countKnownCampaignCallIds(RedisClient $redis, string $jobId): int
    {
        return count(self::knownCampaignCallIds($redis, $jobId));
    }

    private static function moveCampaignPayloadsBetweenQueues(
        RedisClient $redis,
        string $sourceQueue,
        string $targetQueue,
        string $jobId
    ): int {
        $items = $redis->lrange($sourceQueue, 0, -1);
        if (!is_array($items) || $items === []) {
            return 0;
        }

        $moved = 0;
        foreach ($items as $raw) {
            $payload = json_decode((string)$raw, true);
            if (!is_array($payload)) {
                continue;
            }

            if (trim((string)($payload['job_id'] ?? '')) !== $jobId) {
                continue;
            }

            $removed = (int)$redis->lrem($sourceQueue, 1, (string)$raw);
            if ($removed <= 0) {
                continue;
            }

            $redis->rpush($targetQueue, (string)$raw);
            $moved++;
        }

        return $moved;
    }

    private static function recoverMissingCampaignPayloads(RedisClient $redis, string $jobId): int
    {
        $backupKey = "campaign:{$jobId}:payload_backup";
        $backupItems = $redis->lrange($backupKey, 0, -1);
        if (!is_array($backupItems) || $backupItems === []) {
            return 0;
        }

        $knownCallIds = self::knownCampaignCallIds($redis, $jobId);
        $recovered = 0;

        foreach ($backupItems as $raw) {
            $payload = json_decode((string)$raw, true);
            if (!is_array($payload)) {
                continue;
            }

            if (trim((string)($payload['job_id'] ?? '')) !== $jobId) {
                continue;
            }

            $callId = trim((string)($payload['call_id'] ?? ''));
            if ($callId === '' || isset($knownCallIds[$callId])) {
                continue;
            }

            $redis->rpush('voice:queue', (string)$raw);
            $knownCallIds[$callId] = true;
            $recovered++;
        }

        return $recovered;
    }

    private static function knownCampaignCallIds(RedisClient $redis, string $jobId): array
    {
        $callIds = [];

        foreach (self::activeCallIdsByJob($redis, $jobId) as $callId) {
            $callIds[$callId] = true;
        }

        foreach (self::queuedCallIdsByJob($redis, $jobId, 'voice:queue') as $callId) {
            $callIds[$callId] = true;
        }

        foreach (self::queuedCallIdsByJob($redis, $jobId, "voice:paused:job:{$jobId}") as $callId) {
            $callIds[$callId] = true;
        }

        foreach (self::processedCallIdsByJob($jobId) as $callId) {
            $callIds[$callId] = true;
        }

        return $callIds;
    }

    private static function activeCallIdsByJob(RedisClient $redis, string $jobId): array
    {
        $raw = $redis->get('asterisk:active_calls');
        $payload = $raw ? json_decode((string)$raw, true) : null;
        $calls = is_array($payload) ? (array)($payload['chamadas'] ?? []) : [];

        $callIds = [];
        foreach ($calls as $call) {
            if (!is_array($call)) {
                continue;
            }

            $vars = is_array($call['vars'] ?? null) ? $call['vars'] : [];
            $currentJobId = trim((string)($call['job_id'] ?? $vars['JOB_ID'] ?? ''));
            if ($currentJobId !== $jobId) {
                continue;
            }

            $callId = trim((string)($call['call_id'] ?? $vars['CALL_ID'] ?? ''));
            if ($callId !== '') {
                $callIds[] = $callId;
            }
        }

        return array_values(array_unique($callIds));
    }

    private static function queuedCallIdsByJob(RedisClient $redis, string $jobId, string $queueKey): array
    {
        $items = $redis->lrange($queueKey, 0, -1);
        if (!is_array($items) || $items === []) {
            return [];
        }

        $callIds = [];
        foreach ($items as $raw) {
            $payload = json_decode((string)$raw, true);
            if (!is_array($payload)) {
                continue;
            }

            if (trim((string)($payload['job_id'] ?? '')) !== $jobId) {
                continue;
            }

            $callId = trim((string)($payload['call_id'] ?? ''));
            if ($callId !== '') {
                $callIds[] = $callId;
            }
        }

        return array_values(array_unique($callIds));
    }

    private static function processedCallIdsByJob(string $jobId): array
    {
        try {
            $rows = (new \WilliamCosta\DatabaseManager\Database())->execute(
                "SELECT DISTINCT call_id
                   FROM cdr
                  WHERE job_id = :job_id
                    AND call_id IS NOT NULL
                    AND call_id <> ''",
                [':job_id' => $jobId]
            )->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string)$value),
            $rows
        ))));
    }




    public static function getListSipDevices(): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) return new Response(401, ['status' => 401], 'application/json');

        $role = strtolower($obUser['user_function'] ?? $obUser['function'] ?? '');

        // Super admin ve tudo. Os demais seguem restritos ao escopo da tenancy.
        $query = [
            'function' => $role,
        ];

        if ($role !== 'super_admin') {
            $query['tenant_id'] = $obUser['tenancy_id'];
        }

        // Para RESELLER ou OPERADOR, enviamos o ID dele para a Model filtrar
        // ramais onde ele é o dono (user_id) ou o criador (creator_id)
        if ($role !== 'super_admin' && $role !== 'admin') {
            $query['user_id'] = (int)$obUser['id'];
        }

        $asterisk = new AsteriskExtensionsSip();
        $result = $asterisk->listExtensions($query);
        $data = $result['data']['data'] ?? $result['data'] ?? [];

        // Filtro de Segurança Local (Redundância)
        switch ($role) {
            case 'super_admin': break;
            case 'admin':
                $data = array_filter($data, fn($sip) => $sip['tenant_id'] == $obUser['tenancy_id']);
                break;
            case 'reseller':
                // O revendedor vê o que ele criou ou o que é dele
                $data = array_filter($data, fn($sip) =>
                    (int)($sip['creator_id'] ?? 0) === (int)$obUser['id'] ||
                    (int)$sip['user_id'] === (int)$obUser['id']
                );
                break;
            default:
                // Agente vê só o dele
                $data = array_filter($data, fn($sip) => (int)$sip['user_id'] === (int)$obUser['id']);
                break;
        }

        return new Response(200, [
            'status'  => 200,
            'success' => true,
            'total'   => count($data),
            'data'    => array_values($data)
        ], 'application/json');
    }

    public static function setNewSipDevices(): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, json_encode([
                'status'  => 401,
                'success' => false,
                'message' => 'Usuário não autenticado.'
            ]), 'application/json');
        }

        $inputData = json_decode(file_get_contents('php://input'), true);

        //echo "<pre>";
        //print_r($inputData);
        //echo "</pre>";exit();

        if (empty($inputData['username']) || empty($inputData['password'])) {
            return new Response(400, json_encode([
                'status'  => 400,
                'success' => false,
                'message' => 'Campos obrigatórios ausentes: username e password.'
            ]), 'application/json');
        }

        // ✅ Ramal deve ser 8 dígitos numéricos
        $ext = preg_replace('/\D+/', '', (string)$inputData['username']);
        if (!preg_match('/^\d{8}$/', $ext)) {
            return new Response(422, json_encode([
                'success' => false,
                'message' => 'O ramal (username) deve conter exatamente 8 dígitos.'
            ]), 'application/json');
        }
        $inputData['username'] = $ext;

        $userRole = strtolower($obUser['function'] ?? '');
        $allowedRoles = ['super_admin', 'admin', 'reseller'];

        if (!in_array($userRole, $allowedRoles, true)) {
            return new Response(403, json_encode([
                'success' => false,
                'message' => 'Seu perfil não tem permissão para criar ramais SIP.'
            ]), 'application/json');
        }

        $tenantId = (string)$obUser['tenancy_id'];
        $userId   = (int)$obUser['id'];
        $isReseller = ($userRole === 'reseller');
        $targetUserId = (!empty($inputData['user_id'])) ? (int)$inputData['user_id'] : $userId;
        $webrtcRequested = strtolower((string)($inputData['webrtc'] ?? 'no')) === 'yes';

        if ($webrtcRequested) {
            $webrtcAccess = PlanRuntimeService::assertCanUseFeature($tenantId, 'webrtc');
            if (empty($webrtcAccess['allowed'])) {
                return new Response(403, json_encode([
                    'success' => false,
                    'message' => 'Seu plano atual não permite criar ramal WebRTC. Crie um SIP comum ou libere o módulo no plano.'
                ]), 'application/json');
            }
        }

        // 1. Lógica para definir a ROLE REAL do dono do ramal
        if ($targetUserId === $userId) {
            // Se o ID alvo é o meu próprio, a role é a minha da sessão
            $finalRole = $userRole;
        } else {
            // Se estou atribuindo a outro, busco a role dele no banco
            $targetUserData = UserSearch::getUserById($tenantId, $targetUserId);
            $finalRole = ($targetUserData && isset($targetUserData['function']))
                ? strtolower($targetUserData['function'])
                : 'agent';
        }


        // =========================================================
        // ✅ Admin owner do tenant (sempre)
        // =========================================================
        $adminId = RegisterTenancies::getTenancyOwnerUserId($tenantId);
        if (!$adminId) {
            return new Response(500, json_encode([
                'success' => false,
                'message' => 'Admin owner da tenancy não encontrado.'
            ]), 'application/json');
        }

        $adminWallet         = BalanceSms::getBalanceSms($adminId, $tenantId);
        $balanceAdmin        = (float)($adminWallet->balance ?? 0);
        $adminValueVoice     = (float)($adminWallet->value_voice ?? 0);
        $adminVoiceOpenRate  = (float)($adminWallet->voice_open_rate ?? $adminValueVoice);
        $adminVoiceSmartRate = (float)($adminWallet->voice_smart_rate ?? $adminValueVoice);
        $adminServiceFee     = (float)($adminWallet->service_fee ?? 0);

        // =========================================================
        // ✅ Valores padrão (admin)
        // =========================================================
        $balanceReseller   = 0.0;
        $valueVoice        = $adminValueVoice;
        $voiceOpenRate     = $adminVoiceOpenRate;
        $voiceSmartRate    = $adminVoiceSmartRate;
        $serviceFee        = $adminServiceFee;

        // =========================================================
        // ✅ Se for reseller
        // =========================================================
        if ($isReseller) {

            $resellerWallet   = BalanceSms::getBalanceSms($userId, $tenantId);
            $balanceReseller  = (float)($resellerWallet->balance ?? 0);

            $resellerValueVoice = (float)($resellerWallet->value_voice ?? 0);
            $resellerVoiceOpenRate = (float)($resellerWallet->voice_open_rate ?? $resellerValueVoice);
            $resellerVoiceSmartRate = (float)($resellerWallet->voice_smart_rate ?? $resellerValueVoice);

            if ($resellerVoiceOpenRate <= 0) {
                return new Response(400, json_encode([
                    'success' => false,
                    'message' => 'Tarifa aberta de voz não configurada para o revendedor. Operação interrompida.'
                ]), 'application/json');
            }

            if ($resellerVoiceSmartRate <= 0) {
                return new Response(400, json_encode([
                    'success' => false,
                    'message' => 'Tarifa inteligente de voz não configurada para o revendedor. Operação interrompida.'
                ]), 'application/json');
            }

            if (!isset($resellerWallet->service_fee) || (float)$resellerWallet->service_fee <= 0) {
                return new Response(400, json_encode([
                    'success' => false,
                    'message' => 'Taxa de serviço não configurada para o revendedor. Operação interrompida.'
                ]), 'application/json');
            }

            $valueVoice     = $resellerValueVoice;
            $voiceOpenRate  = $resellerVoiceOpenRate;
            $voiceSmartRate = $resellerVoiceSmartRate;
            $serviceFee     = (float)$resellerWallet->service_fee;
        }

        try {
            $query = [
                'user_id'   => $targetUserId,
                'tenant_id' => $tenantId,
            ];

            $asterisk = new AsteriskExtensionsSip();

            $payload = [
                'extension'        => $inputData['username'],
                'name'             => $inputData['name'] ?? $inputData['username'],
                'password'         => $inputData['password'],
                'caller_number'    => $inputData['caller_number'] ?? $inputData['username'],
                'account_status'   => $inputData['account_status'] ?? 'active',
                'webrtc'           => $inputData['webrtc'] ?? 'no',

                // Configs WebRTC que a sua Model vai usar
                'transport'        => ($inputData['webrtc'] === 'yes') ? 'transport-wss' : 'transport-udp',
                'allow'            => ($inputData['webrtc'] === 'yes') ? 'ulaw,alaw,opus' : 'ulaw,alaw',
                'media_encryption' => ($inputData['webrtc'] === 'yes') ? 'dtls' : 'no',

                // Financeiro e IDs
                'balance_admin'    => $balanceAdmin,
                'balance_reseller' => $balanceReseller,
                'value_voice'      => $valueVoice,
                'voice_open_rate'  => $voiceOpenRate,
                'voice_smart_rate' => $voiceSmartRate,
                'service_fee'      => $serviceFee,
                'role'             => $finalRole,
                'tenant_id'        => $tenantId,
                'user_id'          => $targetUserId,
                'creator_id'       => $obUser['id'],
            ];

            // 1. Executa a criação no Asterisk
            $response = $asterisk->createExtension($query, $payload);

            // 2. Verifica se o Asterisk deu OK (usando as duas checagens que você tinha)
            $asteriskSuccess = (
                (isset($response['success']) && $response['success'] === true) ||
                (!empty($response['ok']) && $response['ok'] === true) ||
                (isset($response['status']) && (int)$response['status'] >= 200 && (int)$response['status'] < 300)
            );

            if ($asteriskSuccess) {
                // 3. SE criou no Asterisk, criamos o registro no Agentes Portal
                $obAgente = new AgentsPortal();
                $obAgente->tenancy_id = $tenantId;
                $obAgente->user_id    = $targetUserId;
                $obAgente->name       = $inputData['name'] ?? $inputData['username'];
                $obAgente->extension  = $inputData['username'];

                // ✅ Ajustado para HASH (Segurança do Portal)
                $obAgente->password   = password_hash($inputData['password'], PASSWORD_DEFAULT);

                // ✅ Ajustado para seu ENUM ('y','n')
                $obAgente->status     = ($inputData['account_status'] === 'active') ? 'y' : 'n';

                if ($obAgente->cadastrar()) {
                    return new Response(200, json_encode([
                        'success' => true,
                        'message' => 'Ramal SIP e Agente de Portal criados com sucesso!',
                        'data'    => $response['data'] ?? $response
                    ]), 'application/json');
                }

                // Se chegar aqui, o Asterisk criou, mas o banco do portal falhou
                return new Response(500, json_encode([
                    'success' => false,
                    'message' => 'Ramal criado no Asterisk, mas falhou ao salvar Agente no Portal.'
                ]), 'application/json');
            }

            // Retorno de erro caso o Asterisk falhe
            return new Response($response['status'] ?? 500, json_encode([
                'success' => false,
                'message' => $response['error'] ?? 'Falha ao criar ramal no Asterisk.',
                'data'    => $response,
            ]), 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, json_encode([
                'success' => false,
                'message' => 'Erro interno: ' . $e->getMessage()
            ]), 'application/json');
        }

    }


    public static function getEditSipDevices($request, $id): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $query = [
            'user_id' => $obUser['id'],
            'tenant_id' => $obUser['tenancy_id'],
        ];

        $asterisk = new AsteriskExtensionsSip();
        $response = $asterisk->getExtensionById($query, $id);

        if (empty($response['ok'])) {
            return new Response(404, [
                'success' => false,
                'message' => $response['error'] ?? "Ramal SIP #{$id} não encontrado.",
            ], 'application/json');
        }

        $data = $response['data']['data'] ?? $response['data'] ?? [];

        return new Response(200, [
            'success' => true,
            'message' => 'Ramal SIP encontrado.',
            'data' => $data,
        ], 'application/json');
    }


    public static function setEditSipDevices($request, $id): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) return new Response(401, json_encode(['status' => 401]), 'application/json');

        $inputData = json_decode(file_get_contents('php://input'), true);
        if (empty($inputData['password'])) return new Response(400, json_encode(['success' => false, 'message' => 'Senha obrigatória.']), 'application/json');

        try {
            $tenantId = $obUser['tenancy_id'];
            $userId   = $obUser['id'];

            // 1. Quem é o usuário que o Admin quer agora?
            $targetUserId = (!empty($inputData['user_id'])) ? (int)$inputData['user_id'] : $userId;

            // 2. Busca o nome desse usuário no banco (A Fonte da Verdade)
            $userData = UserSearch::getUserById($tenantId, $targetUserId);
            $nomeUsuarioBanco = ($userData && isset($userData['name'])) ? strtoupper((string)$userData['name']) : '';

            // 3. Pega o que está no input de texto
            $nomeInput = strtoupper(trim($inputData['name'] ?? ''));

            // 🚀 LÓGICA DE PRIORIDADE DEFINITIVA:
            // Se temos um usuário selecionado e o nome no banco dele é conhecido, usamos ele.
            // A MENOS que o nome no input seja DIFERENTE do nome desse usuário (indica edição manual).
            if (!empty($nomeUsuarioBanco)) {
                // Se o que está no input for igual ao que já estava no banco de usuários,
                // ou se o input estiver vazio, usamos o nome do banco.
                $nomeFinal = $nomeUsuarioBanco;

                // Mas, se o Admin digitou um "Apelido" manual diferente do nome do usuário:
                if (!empty($nomeInput) && $nomeInput !== $nomeUsuarioBanco) {
                    $nomeFinal = $nomeInput;
                }
            } else {
                $nomeFinal = (!empty($nomeInput)) ? $nomeInput : $id;
            }

            $query = ['extension' => $id, 'tenant_id' => $tenantId];
            $payload = [
                'extension'      => $id,
                'password'       => $inputData['password'],
                'name'           => $nomeFinal,
                'caller_number'  => $inputData['caller_number'] ?? $id,
                'account_status' => $inputData['account_status'] ?? 'active',
                'tenant_id'      => $tenantId,
                'user_id'        => $targetUserId,
            ];

            $asterisk = new AsteriskExtensionsSip();
            $response = $asterisk->updateExtension($query, $payload);

            if (!empty($response['ok']) && $response['ok'] === true) {
                $obAgente = AgentsPortal::getAgentByExtension($id, $tenantId);
                if ($obAgente instanceof AgentsPortal) {
                    $obAgente->name     = $nomeFinal;
                    $obAgente->user_id  = $targetUserId;
                    $obAgente->status   = ($inputData['account_status'] === 'active') ? 'y' : 'n';
                    $obAgente->password = password_hash($inputData['password'], PASSWORD_DEFAULT);
                    $obAgente->atualizar();
                }

                return new Response(200, json_encode([
                    'success' => true,
                    'message' => "Atualizado para $nomeFinal"
                ]), 'application/json');
            }

            return new Response(500, json_encode(['success' => false, 'message' => 'Erro Asterisk']));
        } catch (\Throwable $e) {
            return new Response(500, json_encode(['success' => false, 'message' => $e->getMessage()]));
        }
    }

    public static function setDeleteSipDevices(): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, json_encode([
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ]), 'application/json');
        }

        $inputData = json_decode(file_get_contents('php://input'), true);

        if (empty($inputData['ids']) || !is_array($inputData['ids'])) {
            return new Response(400, json_encode([
                'success' => false,
                'message' => 'Nenhum ramal informado para exclusão.'
            ]), 'application/json');
        }

        $tenantId = $obUser['tenancy_id'];
        $userId   = $obUser['id'];
        $query    = ['user_id' => $userId, 'tenant_id' => $tenantId];

        $asterisk = new AsteriskExtensionsSip();
        $deleted  = [];
        $failed   = [];

        foreach ($inputData['ids'] as $extensionId) {
            try {
                $payload = [
                    'extension' => $extensionId,
                    'tenant_id' => $tenantId,
                    'user_id'   => $userId,
                ];

                // 1. Tenta deletar no Asterisk
                $response = $asterisk->deleteExtension($query, $payload);

                if (!empty($response['ok']) && $response['ok'] === true) {
                    $deleted[] = $extensionId;

                    // 2. Se deletou no Asterisk, remove do Portal também
                    $obAgente = AgentsPortal::getAgentByExtension($extensionId, $tenantId);
                    if ($obAgente instanceof AgentsPortal) {
                        $obAgente->excluir();
                    }
                } else {
                    $failed[] = [
                        'id'    => $extensionId,
                        'error' => $response['error'] ?? 'Erro retornado pelo Asterisk.'
                    ];
                }
            } catch (\Throwable $e) {
                $failed[] = [
                    'id'    => $extensionId,
                    'error' => $e->getMessage()
                ];
            }
        }

        $statusCode = empty($failed) ? 200 : 207;
        return new Response($statusCode, json_encode([
            'success' => empty($failed),
            'message' => empty($failed)
                ? count($deleted) . ' ramal(is) removido(s) com sucesso.'
                : 'Algumas exclusões falharam.',
            'deleted' => $deleted,
            'failed'  => $failed
        ]), 'application/json');
    }


    public static function setStatusSipDevices($request, $id): Response
    {
        $obUser = SessionUser::getLogged();

        // 🔒 Verifica autenticação
        if (!$obUser) {
            return new Response(401, [
                'success' => false,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        // 📥 Lê o corpo JSON enviado (contém o novo status)
        $inputData = json_decode(file_get_contents('php://input'), true);
        $newStatus = $inputData['account_status'] ?? null;

        // ⚠️ Valida status
        if (!in_array($newStatus, ['active', 'inactive'])) {
            return new Response(400, [
                'success' => false,
                'message' => 'Status inválido. Use "active" ou "inactive".'
            ], 'application/json');
        }

        try {
            // ⚙️ Query padrão com dados do usuário autenticado
            $query = [
                'user_id' => $obUser['id'],
                'tenant_id' => $obUser['tenancy_id'],
            ];

            // 🧱 Payload enviado para o Asterisk
            $payload = [
                'extension' => $id,
                'account_status' => $newStatus,
                'tenant_id' => $obUser['tenancy_id'],
                'user_id' => $obUser['id'],
            ];

            // 🔗 Chama a API do Asterisk
            $asterisk = new AsteriskExtensionsSip();
            $response = $asterisk->updateStatus($query, $payload);


            // ✅ Se tudo deu certo
            if (!empty($response['ok']) && $response['ok'] === true) {
                return new Response(200, [
                    'success' => true,
                    'message' => "Status do ramal {$id} atualizado para '{$newStatus}'.",
                    'data' => $response['data'] ?? []
                ], 'application/json');
            }

            // ❌ Erro retornado pela API
            return new Response($response['status'] ?? 500, [
                'success' => false,
                'message' => $response['error'] ?? 'Falha ao atualizar o status do ramal.',
                'data' => $response
            ], 'application/json');

        } catch (\Throwable $e) {
            // ❌ Erro interno
            return new Response(500, [
                'success' => false,
                'message' => 'Erro ao atualizar status: ' . $e->getMessage()
            ], 'application/json');
        }
    }


    public static function getVoiceTrunksView(): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'success' => false,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $role = strtolower((string)($obUser['function'] ?? ''));

        // ⚙️ Query: super_admin vê tudo (não manda tenant/user)
        $query = [
            'role' => $role,
        ];

        if ($role !== 'super_admin') {
            $query['tenant_id'] = $obUser['tenancy_id'];

            // 🔥 NÃO manda user_id aqui, deixa o controller filtrar
            // (senão a model restringe e pode voltar vazio pro reseller)
            // $query['user_id'] = $obUser['id'];
        }

        $asterisk = new AsteriskExtensionsSip();
        $result   = $asterisk->listTrunks($query);

        if (empty($result['ok']) || !$result['ok']) {
            return new Response(
                $result['status'] ?? 500,
                [
                    'status'  => $result['status'] ?? 500,
                    'success' => false,
                    'error'   => $result['error'] ?? 'Falha ao consultar SIP Trunks',
                    'info'    => $result['info'] ?? null,
                ],
                'application/json'
            );
        }

        // 🧩 Normaliza retorno
        $data = $result['data']['data'] ?? $result['data'] ?? [];

        // 🔐 Filtro por perfil (com is_system liberado)
        switch ($role) {

            case 'super_admin':
                // vê tudo
                break;

            case 'admin':
                // ✅ system + tenant + trunks do próprio admin
                $data = array_filter($data, fn($t) =>
                    (int)($t['is_system'] ?? 0) === 1
                    || (isset($t['tenant_id']) && $t['tenant_id'] === $obUser['tenancy_id'])
                    || (isset($t['user_id']) && (int)$t['user_id'] === (int)$obUser['id'])
                );
                break;

            case 'reseller':
                // ✅ system + tenant + trunks do próprio reseller
                $data = array_filter($data, fn($t) =>
                    (int)($t['is_system'] ?? 0) === 1
                    || (isset($t['tenant_id']) && $t['tenant_id'] === $obUser['tenancy_id'])
                    || (isset($t['user_id']) && (int)$t['user_id'] === (int)$obUser['id'])
                );
                break;


            default:
                // user comum: só system (ou vazio, escolha sua regra)
                $data = array_filter($data, fn($t) =>
                    (int)($t['is_system'] ?? 0) === 1
                );
                break;
        }

        $data = array_values($data);

        //echo "<pre>";
        //print_r($data);
        //echo "</pre>";exit();

        $response = new Response(200, [
            'status'  => 200,
            'success' => true,
            'message' => 'SIP Trunks encontrados com sucesso.',
            'total'   => count($data),
            'meta'    => [
                'role' => strtolower((string)$obUser['function']),
            ],
            'data'    => $data,
        ], 'application/json');

        $response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->addHeader('Pragma', 'no-cache');
        $response->addHeader('Expires', '0');

        return $response;
    }




    public static function setNewVoiceTrunks(): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, [
                'success' => false,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $input = json_decode(file_get_contents('php://input'), true);

        //echo "<pre>";
        //print_r($input);
        //echo "</pre>";exit;

        // 🔎 Validação mínima
        if (empty($input['name']) || empty($input['host']) || empty($input['auth_type'])) {
            return new Response(400, [
                'success' => false,
                'message' => 'Campos obrigatórios: name, host, auth_type.'
            ], 'application/json');
        }

        if ($input['auth_type'] === 'register' &&
            (empty($input['username']) || empty($input['password']))) {
            return new Response(400, [
                'success' => false,
                'message' => 'REGISTER exige username e password.'
            ], 'application/json');
        }

        $userRole = strtolower($obUser['function']);

        $allowedRoles = ['super_admin', 'admin', 'reseller'];

        $serviceSee = 0.03;

        if (!in_array($userRole, $allowedRoles, true)) {
            return new Response(403, [
                'success' => false,
                'message' => 'Você não tem permissão para criar SIP Trunks.'
            ], 'application/json');
        }

        $billingType = VoicePricingService::normalizeBillingType($input['billing_type'] ?? $input['cli_type'] ?? null);
        if ($billingType === null) {
            return new Response(400, [
                'success' => false,
                'message' => 'Tipo de tarifação do tronco é obrigatório. Use CLI Aberta ou Bina Inteligente.'
            ], 'application/json');
        }

        $planAccess = self::assertTrunkCreationAllowedForTenancy($obUser);
        if ($planAccess instanceof Response) {
            return $planAccess;
        }

        try {

            $query = [
                'user_id'   => $obUser['id'],
                'tenant_id' => $obUser['tenancy_id'],
            ];

            $asterisk = new AsteriskExtensionsSip();

            // 🎯 PAYLOAD DE TRUNK
            $payload = [
                'name'       => $input['name'],
                'host'       => $input['host'],
                'auth_type'  => $input['auth_type'], // register | ip
                'username'   => $input['username'] ?? null,
                'password'   => $input['password'] ?? null,
                'port'       => (int)($input['port'] ?? 5060),
                'transport'  => $input['transport'] ?? 'udp',
                'direction'  => $input['direction'] ?? 'both',
                'cli_type'   => VoicePricingService::cliTypeForBilling($billingType),
                'billing_type' => $billingType,
                'techprefix' => $input['techprefix'] ?? '',
                'dial_prefix' => $input['dial_prefix'] ?? '',
                'service_fee' => $serviceSee,
                'status'     => $input['status'] ?? 'active',
                'tenant_id'  => $obUser['tenancy_id'],
                'user_id'    => $obUser['id'],
            ];

            // 🔥 CHAMADA CERTA (não é extension)
            $response = $asterisk->createSipTrunk($query, $payload);

            if (!empty($response['ok'])) {
                return new Response(200, [
                    'success' => true,
                    'message' => 'SIP Trunk criado com sucesso!',
                    'data'    => $response['data'] ?? []
                ], 'application/json');
            }

            return new Response(500, [
                'success' => false,
                'message' => $response['error'] ?? 'Erro ao criar SIP Trunk.'
            ], 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, [
                'success' => false,
                'message' => $e->getMessage()
            ], 'application/json');
        }
    }

    private static function assertTrunkCreationAllowedForTenancy(array $user): ?Response
    {
        $tenancyId = (string)($user['tenancy_id'] ?? '');
        if ($tenancyId === '') {
            return null;
        }

        try {
            $currentCount = self::currentTenantTrunkCount($user);
        } catch (\Throwable $e) {
            return new Response(409, [
                'success' => false,
                'message' => 'Não foi possível validar o limite de trunks do plano.',
                'error' => $e->getMessage(),
            ], 'application/json');
        }

        $access = PlanAccessPolicy::assertCanCreateTrunk($tenancyId, $currentCount);
        if (!($access['allowed'] ?? false)) {
            return new Response(409, [
                'success' => false,
                'message' => (string)($access['message'] ?? 'Limite de trunks do plano atingido.'),
            ], 'application/json');
        }

        return null;
    }

    private static function currentTenantTrunkCount(array $user): int
    {
        $tenancyId = (string)($user['tenancy_id'] ?? '');
        if ($tenancyId === '') {
            return 0;
        }

        $asterisk = new AsteriskExtensionsSip();
        $result = $asterisk->listTrunks([
            'tenant_id' => $tenancyId,
            'role' => 'admin',
        ]);

        if (empty($result['ok']) || !$result['ok']) {
            throw new \RuntimeException((string)($result['error'] ?? 'Falha ao consultar trunks da tenancy.'));
        }

        $data = $result['data']['data'] ?? $result['data'] ?? [];
        $count = 0;

        foreach ((array)$data as $trunk) {
            if (!is_array($trunk)) {
                continue;
            }

            $trunkTenancyId = (string)($trunk['tenant_id'] ?? $trunk['tenancy_id'] ?? '');
            $isSystem = (int)($trunk['is_system'] ?? 0) === 1;

            if ($trunkTenancyId !== $tenancyId || $isSystem) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    public static function setEditSipTrunks($request, $id): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, [
                'success' => false,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        // 📥 Lê o corpo da requisição
        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        // 🧩 validações básicas
        $authType = strtolower(trim((string)($input['auth_type'] ?? 'register'))); // register | ip

        if (empty($input['name'])) {
            return new Response(400, [
                'success' => false,
                'message' => 'O campo "name" é obrigatório.'
            ], 'application/json');
        }

        if (empty($input['host'])) {
            return new Response(400, [
                'success' => false,
                'message' => 'O campo "host" é obrigatório.'
            ], 'application/json');
        }

        // Se for register, normalmente precisa username e password
        if ($authType === 'register') {
            if (empty($input['username'])) {
                return new Response(400, [
                    'success' => false,
                    'message' => 'O campo "username" é obrigatório quando auth_type = register.'
                ], 'application/json');
            }

            if (!array_key_exists('password', $input) || $input['password'] === '') {
                return new Response(400, [
                    'success' => false,
                    'message' => 'O campo "password" é obrigatório quando auth_type = register.'
                ], 'application/json');
            }
        }

        // Se for ip, não força username/password
        if ($authType !== 'register' && $authType !== 'ip') {
            return new Response(400, [
                'success' => false,
                'message' => 'auth_type inválido. Use "register" ou "ip".'
            ], 'application/json');
        }

        $query = [
            'user_id'   => $obUser['id'],
            'tenant_id' => $obUser['tenancy_id'],
        ];

        $asterisk = new AsteriskExtensionsSip();
        $response = $asterisk->getTrunkById($query, $id);

        $current = $response['data']['data'] ?? $response['data'] ?? null;
        if (!$current || !is_array($current)) {
            return new Response(404, [
                'success' => false,
                'message' => "SIP Trunk #{$id} não encontrado.",
            ], 'application/json');
        }

        $role = strtolower((string)($obUser['function'] ?? ''));

        // 🔒 1) trava trunk de sistema
        if ((int)($current['is_system'] ?? 0) === 1 && $role !== 'super_admin') {
            return new Response(403, [
                'success' => false,
                'message' => 'Você não tem permissão para editar este trunk.',
            ], 'application/json');
        }

        // 🔒 2) autorização por perfil
        if ($role === 'admin') {
            if (($current['tenant_id'] ?? null) !== $obUser['tenancy_id']) {
                return new Response(403, [
                    'success' => false,
                    'message' => 'Você não tem permissão para editar este trunk.',
                ], 'application/json');
            }
        } elseif ($role === 'reseller') {
            if ((int)($current['user_id'] ?? 0) !== (int)$obUser['id']) {
                return new Response(403, [
                    'success' => false,
                    'message' => 'Você não tem permissão para editar este trunk.',
                ], 'application/json');
            }
        } elseif ($role !== 'super_admin') {
            return new Response(403, [
                'success' => false,
                'message' => 'Você não tem permissão para editar trunks.',
            ], 'application/json');
        }

        $serviceSee = 0.03;
        $billingType = VoicePricingService::normalizeBillingType($input['billing_type'] ?? $input['cli_type'] ?? null);
        if ($billingType === null) {
            return new Response(400, [
                'success' => false,
                'message' => 'Tipo de tarifação do tronco é obrigatório. Use CLI Aberta ou Bina Inteligente.',
            ], 'application/json');
        }


        try {


            // ✅ payload (trunk)
            $payload = [
                'id'         => (int)$id, // importante pro update
                'trunk_id'  => (string)$input['trunk_id'],
                'name'       => (string)$input['name'],
                'host'       => (string)$input['host'],
                'auth_type'  => $authType,
                'username'   => $input['username'] ?? null,
                'password'   => $input['password'] ?? null,
                'port'       => (int)($input['port'] ?? 5060),
                'transport'  => strtolower((string)($input['transport'] ?? 'udp')),
                'direction'  => strtolower((string)($input['direction'] ?? 'outbound')),
                'cli_type'   => VoicePricingService::cliTypeForBilling($billingType),
                'billing_type' => $billingType,
                'techprefix' => (string)($input['techprefix'] ?? ''),
                'dial_prefix' => $input['dial_prefix'] ?? '',
                'service_fee' => $serviceSee,
                'status'     => (string)($input['status'] ?? 'active'),
                'tenant_id'  => (string)$obUser['tenancy_id'],
                'user_id'    => (int)$obUser['id'],
            ];

            $response = $asterisk->updateSipTrunks($query, $payload);

            if (!empty($response['ok']) && $response['ok'] === true) {
                return new Response(200, [
                    'success' => true,
                    'message' => 'SIP Trunk atualizado com sucesso!',
                    'data'    => $response['data'] ?? $response
                ], 'application/json');
            }

            return new Response($response['status'] ?? 500, [
                'success' => false,
                'message' => $response['error'] ?? 'Falha ao atualizar o SIP Trunk.',
                'data'    => $response
            ], 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, [
                'success' => false,
                'message' => 'Erro ao editar SIP Trunk: ' . $e->getMessage()
            ], 'application/json');
        }
    }

    public static function setStatusSipTrunks($request, $id): Response
    {
        $obUser = SessionUser::getLogged();

        if (!$obUser) {
            return new Response(401, [
                'success' => false,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $inputData = json_decode(file_get_contents('php://input'), true) ?: [];
        $newStatus = $inputData['status'] ?? null;

        if (!in_array($newStatus, ['active', 'inactive'], true)) {
            return new Response(400, [
                'success' => false,
                'message' => 'Status inválido. Use "active" ou "inactive".'
            ], 'application/json');
        }

        try {
            $query = [
                'user_id'   => $obUser['id'],
                'tenant_id' => $obUser['tenancy_id'],
            ];

            $asterisk = new AsteriskExtensionsSip();

            // 1) Busca trunk
            $check = $asterisk->getTrunkById($query, $id);

            if (($check['ok'] ?? false) !== true) {
                return new Response($check['status'] ?? 404, [
                    'success' => false,
                    'message' => $check['error'] ?? "SIP Trunk #{$id} não encontrado.",
                    'data'    => $check
                ], 'application/json');
            }


            // 2) Extrai trunk_id com fallback
            $row = $check['data'] ?? [];
            // se seu getTrunkById retornar { data: { trunk_id: ... } }:
            if (isset($row['data']) && is_array($row['data'])) {
                $row = $row['data'];
            }

            $trunkId = (string)($row['trunk_id'] ?? $row['id'] ?? '');

            if ($trunkId === '') {
                return new Response(500, [
                    'success' => false,
                    'message' => 'Falha ao obter trunk_id do trunk.',
                    'data'    => $check
                ], 'application/json');
            }

            // 3) Atualiza status
            $payload = [
                'trunk_id'  => $trunkId,
                'status'    => $newStatus,
                'tenant_id' => $obUser['tenancy_id'],
                'user_id'   => $obUser['id'],
            ];

            $response = $asterisk->updateStatusSipTrunks($query, $payload);


            if (!empty($response['ok']) && $response['ok'] === true) {
                return new Response(200, [
                    'success' => true,
                    'message' => "Status do trunk {$trunkId} atualizado para '{$newStatus}'.",
                    'data'    => $response['data'] ?? []
                ], 'application/json');
            }

            return new Response($response['status'] ?? 500, [
                'success' => false,
                'message' => $response['error'] ?? 'Falha ao atualizar o status do trunk.',
                'data'    => $response
            ], 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, [
                'success' => false,
                'message' => 'Erro ao atualizar status: ' . $e->getMessage()
            ], 'application/json');
        }
    }



    public static function setDeleteSipTrunks(): Response
    {
        $obUser = SessionUser::getLogged();

        // 🔒 Verifica autenticação
        if (!$obUser) {
            return new Response(401, [
                'status'  => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        // 📥 Lê corpo JSON
        $inputData = json_decode(file_get_contents('php://input'), true) ?: [];

        // ✅ Aceita 1 id ou array
        $idsRaw = $inputData['ids'] ?? null;

        if (empty($idsRaw)) {
            return new Response(400, [
                'success' => false,
                'message' => 'Nenhum trunk informado para exclusão.'
            ], 'application/json');
        }

        $ids = is_array($idsRaw) ? $idsRaw : [$idsRaw];

        // ⚙️ Parâmetros básicos do usuário logado (vai no query string)
        $query = [
            'user_id'   => $obUser['id'],
            'tenant_id' => $obUser['tenancy_id'],
        ];

        $asterisk = new AsteriskExtensionsSip();

        $deleted = [];
        $failed  = [];

        // 🔍 Normaliza ids e remove duplicados
        $normalizedIds = [];
        foreach ($ids as $idRaw) {
            $id = (int)$idRaw;

            if ($id <= 0) {
                $failed[] = [
                    'id'    => $idRaw,
                    'error' => 'id inválido.'
                ];
                continue;
            }

            $normalizedIds[] = $id;
        }
        $normalizedIds = array_values(array_unique($normalizedIds));

        if (empty($normalizedIds)) {
            return new Response(400, [
                'success' => false,
                'message' => 'Nenhum id válido informado para exclusão.',
                'deleted' => [],
                'failed'  => $failed,
            ], 'application/json');
        }

        // 🔁 Processa cada trunk
        foreach ($normalizedIds as $id) {
            try {
                // 1) Confirma se existe pelo ID (int)
                $check = $asterisk->getTrunkById($query, $id);

                if (($check['ok'] ?? false) !== true || empty($check['data'])) {
                    $failed[] = [
                        'id'    => $id,
                        'error' => $check['error'] ?? "SIP Trunk #{$id} não encontrado.",
                    ];
                    continue;
                }

                // 2) Pega trunk_id (string) para deletar de verdade
                $body = $check['data'] ?? [];            // aqui vem: success/message/data
                $row  = $body['data'] ?? [];             // aqui vem: id, trunk_id, name...

                $trunkId = (string)($row['trunk_id'] ?? '');

                if ($trunkId === '') {
                    $failed[] = [
                        'id'    => $id,
                        'error' => 'Registro encontrado, mas trunk_id não veio no retorno (formato inesperado).',
                    ];
                    continue;
                }

                // 3) Chama delete (API espera trunk_id)
                $payload = [
                    'trunk_id'  => $trunkId,
                    'tenant_id' => $obUser['tenancy_id'],
                    'user_id'   => $obUser['id'],
                ];

                $resp = $asterisk->deleteSipTrunks($query, $payload);

                $status = (int)($resp['status'] ?? 0);
                $okHttp = $status >= 200 && $status < 300;

                // sua request() parece retornar algo como ['status'=>..., 'data'=>..., 'error'=>...]
                // então consideramos sucesso se HTTP 2xx ou se vier success=true no data
                $okApp = (bool)($resp['data']['success'] ?? false);

                if ($okHttp && ($okApp || empty($resp['data']) === false)) {
                    // Marca como deletado pelo ID local (pra casar com o front)
                    $deleted[] = $id;
                } else {
                    $failed[] = [
                        'id'    => $id,
                        'error' => $resp['data']['message'] ?? $resp['error'] ?? 'Falha desconhecida ao excluir trunk.',
                    ];
                }
            } catch (\Throwable $e) {
                $failed[] = [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // ✅ Status code final
        // 200: tudo ok
        // 207: parcial
        // 404: nenhum deletado (todos falharam / não encontrados)
        if (!empty($deleted) && empty($failed)) {
            $statusCode = 200;
        } elseif (!empty($deleted) && !empty($failed)) {
            $statusCode = 207;
        } else {
            $statusCode = 404;
        }

        return new Response($statusCode, [
            'success' => !empty($deleted) && empty($failed),
            'message' => !empty($deleted) && empty($failed)
                ? count($deleted) . ' trunk(s) excluído(s) com sucesso.'
                : (!empty($deleted)
                    ? 'Alguns trunks não puderam ser excluídos.'
                    : 'Nenhum trunk pôde ser excluído.'),
            'deleted' => $deleted,
            'failed'  => $failed
        ], 'application/json');
    }

    public static function setActionVoiceCampaign($id, $action): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, ['status' => 401, 'message' => 'Usuário não autenticado.'], 'application/json');
        }

        $action = strtolower((string)$action);

        if (!in_array($action, ['pause', 'resume', 'resend'], true)) {
            return new Response(400, ['status' => 400, 'message' => 'Ação inválida'], 'application/json');
        }

        $campaign = CampaignVoice::getById((int)$id);
        if (!$campaign) {
            return new Response(404, ['status' => 404, 'message' => 'Campanha não encontrada'], 'application/json');
        }

        // 🔒 Permissão (tenancy + opcional user_id p/ reseller/user)
        $role = strtolower((string)($obUser['function'] ?? ''));
        if ($role !== 'super_admin') {
            if ((string)$campaign['tenancy_id'] !== (string)$obUser['tenancy_id']) {
                return new Response(403, ['status' => 403, 'message' => 'Sem permissão'], 'application/json');
            }

            // opcional (recomendado): reseller/user só mexe nas próprias
            if (in_array($role, ['reseller', 'user'], true)) {
                if ((int)$campaign['user_id'] !== (int)$obUser['id']) {
                    return new Response(403, ['status' => 403, 'message' => 'Sem permissão'], 'application/json');
                }
            }
        }

        $jobId = (string)($campaign['job_id'] ?? '');
        if ($jobId === '') {
            return new Response(400, ['status' => 400, 'message' => 'Campanha sem job_id'], 'application/json');
        }

        try {
            $redis = new RedisClient(TelephonyConfig::redisConfig());
        } catch (Throwable $e) {
            return new Response(500, [
                'status'  => 500,
                'message' => 'Falha ao conectar no Redis.',
                'details' => $e->getMessage()
            ], 'application/json');
        }

        $pauseKey     = "campaign:pause:job:{$jobId}";
        $jobHashKey   = "campaign:{$jobId}";
        $pausedQueue  = "voice:paused:job:{$jobId}";
        $dlqQueue     = "voice:dlq:job:{$jobId}";
        $retryPrefix  = "campaign:{$jobId}:retry:"; // onde você grava retry por call_id

        // ======================
        // PAUSE
        // ======================
        if ($action === 'pause') {
            $redis->set($pauseKey, '1');
            $redis->expire($pauseKey, 86400);

            $movedToPaused = self::moveCampaignPayloadsBetweenQueues($redis, 'voice:queue', $pausedQueue, $jobId);
            $redis->hset($jobHashKey, 'status', 'paused');
            $redis->expire($pausedQueue, 86400);

            CampaignVoice::updateStatusByJob($jobId, 'n');

            $activeCalls = (int)(self::activeCallsByJob($redis)[$jobId] ?? 0);

            return new Response(200, [
                'ok' => true,
                'status' => 'n',
                'message' => 'Campanha pausada com sucesso.',
                'meta' => [
                    'moved_to_paused_queue' => $movedToPaused,
                    'active_calls' => $activeCalls,
                    'paused_queue_size' => (int)$redis->llen($pausedQueue),
                ],
            ], 'application/json');
        }

        // ======================
        // RESUME
        // ======================
        if ($action === 'resume') {
            $redis->del($pauseKey);
            $redis->hset($jobHashKey, 'status', 'pending');
            $redis->del("campaign:{$jobId}:finished");

            CampaignVoice::updateStatusByJob($jobId, 'y');

            // devolve payloads segurados
            $max = 20000;
            $movedToMainQueue = 0;
            for ($i = 0; $i < $max; $i++) {
                $p = $redis->lpop($pausedQueue);
                if (!$p) break;
                $redis->rpush('voice:queue', $p);
                $movedToMainQueue++;
            }

            $recoveredPayloads = 0;
            if ($movedToMainQueue === 0) {
                $recoveredPayloads = self::recoverMissingCampaignPayloads($redis, $jobId);
            }

            $runtime = self::campaignRuntimeSnapshot(
                $redis,
                $jobId,
                $campaign,
                (int)(self::activeCallsByJob($redis)[$jobId] ?? 0),
                (int)(self::queuedCallsByJob($redis, 'voice:queue')[$jobId] ?? 0)
            );
            self::synchronizeCampaignRuntimeState($redis, $jobId, $campaign, $runtime);

            $status = $runtime['effective_status'] ?? 'y';
            $message = $movedToMainQueue > 0
                ? 'Campanha retomada com sucesso.'
                : ($recoveredPayloads > 0
                    ? 'Campanha retomada com sucesso.'
                    : (($status === 'f')
                        ? 'Campanha finalizada com sucesso.'
                        : 'Campanha retomada com sucesso.'));

            return new Response(200, [
                'ok' => true,
                'status' => $status,
                'message' => $message,
                'meta' => [
                    'moved_to_main_queue' => $movedToMainQueue,
                    'recovered_payloads' => $recoveredPayloads,
                    'orphaned' => (bool)($runtime['orphaned'] ?? false),
                    'recoverable_payloads' => (int)($runtime['recoverable_payloads'] ?? 0),
                    'remaining_paused_queue' => (int)$redis->llen($pausedQueue),
                ],
            ], 'application/json');
        }

        // ======================
        // RESEND (DLQ -> QUEUE)
        // ======================

        if ($action === 'resend') {

            $st = strtolower((string)($campaign['status'] ?? ''));
            if ($st !== 'c') {
                return new Response(400, [
                    'status'  => 400,
                    'message' => 'Somente campanhas canceladas podem ser reenviadas.'
                ], 'application/json');
            }

            // =========================
            // RESEND RATE LIMIT (5 tentativas / 60s)
            // =========================
            $limitKey = "campaign:{$jobId}:resend:attempts";
            $blockKey = "campaign:{$jobId}:resend:blocked";

            // se já estiver bloqueada
            $blocked = (int)$redis->get($blockKey);
            if ($blocked > 0) {
                return new Response(429, [
                    'status'  => 429,
                    'message' => 'Reenvio temporariamente bloqueado. Tente novamente em 60s.',
                    'blocked' => true,
                    'retry_in'=> $blocked
                ], 'application/json');
            }

            // incrementa tentativas
            $attempts = (int)$redis->incr($limitKey);

            // janela de contagem = 60s
            if ($attempts === 1) {
                $redis->expire($limitKey, 60);
            }

            // estourou limite
            if ($attempts > 5) {

                // bloqueia por 60s
                $redis->setex($blockKey, 60, 60);

                return new Response(429, [
                    'status'  => 429,
                    'message' => 'Você tentou reenviar muitas vezes. Aguarde 1 minuto.',
                    'blocked' => true,
                    'retry_in'=> 60
                ], 'application/json');
            }

            $redis->del($pauseKey);

            $redis->hset($jobHashKey, 'status', 'pending');
            $redis->hset($jobHashKey, 'failed_calls', 0);

            // limpa retry keys
            $it = null;
            $keysToDelete = [];
            do {
                $keys = $redis->scan($it, ['match' => $retryPrefix . '*', 'count' => 200]);
                if (is_array($keys)) foreach ($keys as $k) if ($k) $keysToDelete[] = $k;
            } while ($it !== 0 && $it !== null);

            if (!empty($keysToDelete)) $redis->del(...$keysToDelete);

            // =========================
            // DLQ -> QUEUE (SEM CONSUMIR)
            // =========================
            $sources = [
                $dlqQueue,   // voice:dlq:job:{jobId}
                'voice:dlq', // fallback global
            ];
            $sources = array_values(array_unique(array_filter($sources)));

            $moved = 0;
            $invalid = 0;
            $badjson = 0;

            // dedupe durante ESTE resend (60s)
            $seenSet = "campaign:{$jobId}:resend:seen";
            $redis->del($seenSet);
            $redis->expire($seenSet, 60);

            foreach ($sources as $src) {

                // pega snapshot do conteúdo sem consumir
                $items = $redis->lrange($src, 0, -1);
                if (!is_array($items) || count($items) === 0) continue;

                foreach ($items as $p) {

                    if (!is_string($p)) $p = (string)$p;

                    $dlqItem = json_decode($p, true);
                    if (!is_array($dlqItem)) { $invalid++; continue; }

                    $raw = $dlqItem['raw'] ?? null;
                    if (!is_string($raw) || $raw === '') { $invalid++; continue; }

                    $data = json_decode($raw, true);
                    if (!is_array($data)) { $badjson++; continue; }

                    $callId = trim((string)($data['call_id'] ?? ''));
                    if ($callId === '') $callId = sha1($raw);

                    // evita duplicar dentro do mesmo clique
                    if ($redis->sadd($seenSet, $callId) !== 1) continue;

                    $redis->rpush('voice:queue', $raw);
                    $moved++;
                }

                if ($moved > 0) break;
            }

            // ✅ agora NÃO É ERRO: só não tinha nada novo a reenviar
            if ($moved <= 0) {
                $lenJob = $redis->llen($dlqQueue);
                $lenAll = $redis->llen('voice:dlq');

                return new Response(200, [
                    'ok'     => true,
                    'status' => 'y',
                    'moved'  => 0,
                    'message'=> 'Nenhum item novo para reenviar. Campanha continua reenviável.',
                    'meta'   => [
                        'invalid_raw' => $invalid,
                        'badjson'     => $badjson,
                        'len_job'     => $lenJob,
                        'len_global'  => $lenAll,
                    ]
                ], 'application/json');
            }

            // volta pra ativa
            CampaignVoice::updateStatusByJob($jobId, 'y');

            return new Response(200, [
                'ok'     => true,
                'status' => 'y',
                'moved'  => $moved,
            ], 'application/json');
        }

        // fallback (não deve cair aqui)
        return new Response(400, ['status' => 400, 'message' => 'Ação inválida'], 'application/json');
    }

}

final class VoiceCdrRedisProcessor
{
    private const QUEUE_KEY = 'asterisk:tarifacoes';

    public static function process(?RedisClient $redis = null, bool $emitDebug = true): void
    {
        try {
            $redis ??= self::redis();

            while (true) {
                $json = $redis->lpop(self::QUEUE_KEY);

                if (!$json) {
                    usleep(200000);
                    break;
                }

                $tariff = json_decode((string)$json, true);
                if (!is_array($tariff) || empty($tariff['channel_id'])) {
                    VoiceCdrDebug::send([
                        'skip' => true,
                        'reason' => 'invalid_tariff_payload',
                    ], $emitDebug);
                    continue;
                }

                self::processPayload($redis, $tariff, $emitDebug);
            }
        } catch (Throwable $e) {
            VoiceCdrDebug::send([
                'erro' => true,
                'mensagem' => 'Erro ao gravar CDR',
                'detalhes' => $e->getMessage(),
            ], $emitDebug);
        }
    }

    private static function processPayload(RedisClient $redis, array $tariff, bool $emitDebug): void
    {
        $tariff = self::hydrateTariffFromRedisContext($redis, $tariff);

        $cdr = VoiceCdrMapper::fromTariff($tariff);

        VoiceCdrUserSnapshot::apply($cdr);
        $cdr->insertCdr();

        VoiceRecordingIndexer::indexIfNeeded($redis, $cdr);
        VoiceCampaignCdrUpdater::update($cdr);
        VoiceBillingProcessor::process($cdr);

        VoiceCdrDebug::send([
            'mensagem' => 'CDR gravado com sucesso',
            'canal' => $cdr->channel_id,
            'numero' => $cdr->number,
            'destino' => $cdr->destination,
            'valor' => number_format((float)$cdr->value, 4, '.', ''),
            'duracao' => $cdr->duration,
            'status' => $cdr->dialstatus,
            'motivo' => $cdr->cause_txt,
        ], $emitDebug);
    }

    private static function redis(): RedisClient
    {
        return new RedisClient([
            'scheme' => 'tcp',
            'host' => getenv('REDIS_HOST') ?: TelephonyConfig::redisHost(),
            'port' => (int)(getenv('REDIS_PORT') ?: TelephonyConfig::redisPort()),
            'password' => getenv('REDIS_PASSWORD') ?: TelephonyConfig::redisPassword(),
        ]);
    }

    private static function hydrateTariffFromRedisContext(RedisClient $redis, array $tariff): array
    {
        $callContext = null;
        $channelContext = null;
        $isRamalLeg = self::isManualTariff($tariff) || (float)($tariff['value'] ?? 0) <= 0.0000;

        if (empty($tariff['channel_number'])) {
            $tariff['channel_number'] = $tariff['channelNumber']
                ?? $tariff['endpoint']
                ?? $tariff['endpoints']
                ?? $tariff['reserved_ramal']
                ?? $tariff['ramal']
                ?? null;
        }

        if (empty($tariff['endpoints'])) {
            $tariff['endpoints'] = $tariff['endpoint']
                ?? $tariff['channelNumber']
                ?? $tariff['channel_number']
                ?? $tariff['reserved_ramal']
                ?? $tariff['ramal']
                ?? null;
        }

        $callId = trim((string)($tariff['call_id'] ?? ''));
        if ($callId !== '') {
            $callContext = self::decodeRedisJson($redis->get("voice:call_context:{$callId}"));
        }

        $channelId = trim((string)($tariff['channel_id'] ?? ''));
        if ($channelId !== '') {
            $channelContext = self::decodeRedisJson($redis->get("voice:channel_context:{$channelId}"));
        }

        foreach ([$callContext, $channelContext] as $context) {
            if (!is_array($context)) {
                continue;
            }

            if (empty($tariff['callerid_num'])) {
                foreach (['callerid_num', 'CALLERID(num)', 'CALLERID_NUM', 'caller_number', 'caller_id'] as $alias) {
                    if (!empty($context[$alias])) {
                        $tariff['callerid_num'] = $context[$alias];
                        break;
                    }
                }
            }

            if (empty($tariff['number'])) {
                foreach (['number', 'NUMBER', 'client_number', 'customer_number', 'phone'] as $alias) {
                    if (!empty($context[$alias])) {
                        $tariff['number'] = $context[$alias];
                        break;
                    }
                }
            }

            if (empty($tariff['destination'])) {
                foreach (['destination', 'DESTINATION', 'dst', 'client_number', 'customer_number', 'phone'] as $alias) {
                    if (!empty($context[$alias])) {
                        $tariff['destination'] = $context[$alias];
                        break;
                    }
                }
            }

            if ($isRamalLeg && empty($tariff['channelNumber']) && empty($tariff['channel_number'])) {
                foreach (['channel_number', 'channelNumber', 'endpoint', 'endpoints', 'AGENT_RAMAL', 'reserved_ramal', 'ramal'] as $alias) {
                    if (!empty($context[$alias])) {
                        $value = $context[$alias];
                        if (is_array($value)) {
                            $value = $value[0] ?? null;
                        }
                        $tariff['channelNumber'] = $value;
                        break;
                    }
                }
            }

            if ($isRamalLeg && empty($tariff['endpoints'])) {
                foreach (['endpoints', 'endpoint', 'AGENT_RAMAL', 'reserved_ramal', 'ramal'] as $alias) {
                    if (!empty($context[$alias])) {
                        $value = $context[$alias];
                        if (is_array($value)) {
                            $value = $value[0] ?? null;
                        }
                        $tariff['endpoints'] = $value;
                        break;
                    }
                }
            }
        }

        return $tariff;
    }

    private static function decodeRedisJson(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function isManualTariff(array $tariff): bool
    {
        $callType = strtoupper(trim((string)($tariff['call_type'] ?? $tariff['CALL_TYPE'] ?? '')));
        if ($callType === 'MANUAL') {
            return true;
        }

        return empty($tariff['job_id'])
            && empty($tariff['call_id'])
            && empty($tariff['campaign_id']);
    }
}

final class VoiceCdrMapper
{
    public static function fromTariff(array $tariff): CdrVoice
    {
        $cdr = new CdrVoice();
        $isManual = self::isManualTariff($tariff);
        $shouldAttachAgentLeg = self::shouldAttachAgentLeg($tariff, $isManual);

        $cdr->channel_id = (string)($tariff['channel_id'] ?? '');
        $cdr->job_id = $tariff['job_id'] ?? null;
        $cdr->call_id = $tariff['call_id'] ?? null;
        $cdr->campaign_id = $tariff['campaign_id'] ?? null;
        $cdr->campaign_type = self::normalizeCampaignType($tariff['campaign_type'] ?? null);
        $cdr->tenancy_id = $tariff['tenant_id'] ?? $tariff['tenancy_id'] ?? null;
        $cdr->user_id = $tariff['owner_id'] ?? $tariff['user_id'] ?? null;
        $ramal = $shouldAttachAgentLeg ? self::resolveAgentExtension($tariff) : null;
        $destination = self::resolveDestination($tariff, $isManual);
        $clientNumber = self::resolveClientNumber($tariff, $isManual);
        $callerId = self::resolveCallerId($tariff);
        $endpointIdentity = $shouldAttachAgentLeg ? self::resolveEndpointIdentity($tariff, $ramal) : null;

        $cdr->channel_number = $ramal;
        $cdr->endpoints = $endpointIdentity;
        $cdr->callerid_num = $callerId;
        $cdr->number = $clientNumber;
        $cdr->destination = $destination;
        $cdr->techprefix = $tariff['techprefix'] ?? null;
        $cdr->direction = $tariff['direction'] ?? 'outbound';
        $cdr->trunk = $tariff['trunk_name'] ?? $tariff['trunk'] ?? $tariff['TRUNK'] ?? null;
        $cdr->trunk_id = $tariff['trunk_id'] ?? $tariff['TRUNK_ID'] ?? null;
        $cdr->trunk_billing_type = $tariff['trunk_billing_type'] ?? $tariff['TRUNK_BILLING_TYPE'] ?? null;
        $cdr->plan_id = $tariff['plan_id'] ?? $tariff['PLAN_ID'] ?? null;

        $cdr->type = strtolower((string)($tariff['type'] ?? 'normal'));
        $cdr->taxa_of_service = round((float)($tariff['taxa_of_service'] ?? 0), 4);
        $cdr->state = $tariff['state'] ?? null;
        $cdr->dialstatus = $tariff['dialstatus'] ?? null;
        $cdr->cause = $tariff['cause'] ?? null;
        $cdr->cause_txt = $tariff['cause_txt'] ?? null;
        $cdr->sip_code = $tariff['sip_code'] ?? null;
        $cdr->duration = (int)($tariff['duration'] ?? 0);
        $cdr->duration_seconds = (int)($tariff['duration_seconds'] ?? $tariff['duration'] ?? 0);
        $cdr->billsec = (int)($tariff['billsec'] ?? $tariff['duration'] ?? 0);
        $cdr->billed_seconds = (int)($tariff['billed_seconds'] ?? $tariff['billsec'] ?? $tariff['duration'] ?? 0);
        $cdr->value = round((float)($tariff['value'] ?? 0), 4);
        $cdr->final_price = round((float)($tariff['final_price'] ?? $tariff['value'] ?? 0), 4);
        $cdr->agent_abandoned = (int)($tariff['agent_abandoned'] ?? 0);
        $cdr->agent_abandon_reason = $tariff['agent_abandon_reason'] ?? null;
        $cdr->call_minute_cost = round((float)($tariff['call_minute_cost'] ?? 0), 4);
        $cdr->tariff_used = round((float)($tariff['tariff_used'] ?? $tariff['TARIFF_USED'] ?? $cdr->call_minute_cost), 4);
        $cdr->hangup_by = $tariff['hangup_by'] ?? null;
        $cdr->sms_cost = round((float)($tariff['sms_cost'] ?? 0), 4);
        $cdr->torpedo_cost = round((float)($tariff['torpedo_cost'] ?? 0), 4);
        $cdr->application = $tariff['application'] ?? null;
        $cdr->cdr_timestamp = $tariff['cdr_timestamp'] ?? ($tariff['timestamp'] ?? null);

        // Snapshot apenas: a cobrança é resolvida por tenant/owner, não por role.
        $cdr->role = strtolower((string)($tariff['role'] ?? 'user'));

        $cdr->started = self::timestampToDate($tariff['started'] ?? null);
        $cdr->answered = self::timestampToDate($tariff['answered'] ?? null);
        $cdr->ended = self::timestampToDate($tariff['ended'] ?? null);
        $cdr->charged_at = $tariff['charged_at'] ?? $cdr->answered ?? $cdr->ended ?? $cdr->started ?? date('Y-m-d H:i:s');

        self::removeTechPrefix($cdr);
        self::normalizeExtensionLeg($cdr);
        self::normalizeAnsweredOutcome($cdr, $tariff);

        return $cdr;
    }

    private static function normalizeCampaignType(mixed $value): ?string
    {
        $type = strtolower(trim((string)($value ?? '')));
        if ($type === '') {
            return null;
        }

        return match ($type) {
            'normal', 'voice' => 'voice',
            'torpedo' => 'torpedo',
            default => $type,
        };
    }

    private static function shouldAttachAgentLeg(array $tariff, bool $isManual): bool
    {
        if ($isManual) {
            return true;
        }

        $hasCampaignContext = !empty($tariff['job_id']) || !empty($tariff['campaign_id']);
        if (!$hasCampaignContext) {
            return true;
        }

        $value = round((float)($tariff['value'] ?? 0), 4);
        if ($value <= 0.0001) {
            return true;
        }

        $channel = self::onlyDigits(
            $tariff['channel_number']
            ?? $tariff['channelNumber']
            ?? $tariff['endpoint']
            ?? $tariff['endpoints']
            ?? $tariff['reserved_ramal']
            ?? $tariff['ramal']
            ?? ''
        );

        $callerId = self::onlyDigits(
            $tariff['callerid_num']
            ?? $tariff['CALLERID(num)']
            ?? $tariff['CALLERID_NUM']
            ?? $tariff['caller_number']
            ?? $tariff['caller_id']
            ?? ''
        );

        $destination = self::onlyDigits($tariff['destination'] ?? $tariff['number'] ?? '');

        if ($channel !== '' && self::isExtension($channel)) {
            $callerIsExtension = $callerId !== '' && self::isExtension($callerId);
            $destinationIsExtension = $destination !== '' && self::isExtension($destination);

            if (!$callerIsExtension && !$destinationIsExtension && $value > 0.0001) {
                return false;
            }
        }

        return true;
    }

    private static function isManualTariff(array $tariff): bool
    {
        $callType = strtoupper(trim((string)($tariff['call_type'] ?? $tariff['CALL_TYPE'] ?? '')));
        if ($callType === 'MANUAL') {
            return true;
        }

        return empty($tariff['job_id'])
            && empty($tariff['call_id'])
            && empty($tariff['campaign_id']);
    }

    private static function timestampToDate(mixed $timestamp): ?string
    {
        return !empty($timestamp) ? date('Y-m-d H:i:s', (int)$timestamp) : null;
    }

    private static function normalizeNullable(mixed $value): ?string
    {
        $normalized = trim((string)($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    private static function resolveAgentExtension(array $tariff): ?string
    {
        return self::firstNonEmpty([
            $tariff['channel_number'] ?? null,
            $tariff['channelNumber'] ?? null,
            $tariff['endpoint'] ?? null,
            $tariff['endpoints'] ?? null,
            $tariff['AGENT_RAMAL'] ?? null,
            $tariff['reserved_ramal'] ?? null,
            $tariff['ramal'] ?? null,
        ]);
    }

    private static function resolveClientNumber(array $tariff, bool $isManual): ?string
    {
        $candidates = [
            $tariff['number'] ?? null,
            $tariff['NUMBER'] ?? null,
            $tariff['client_number'] ?? null,
            $tariff['customer_number'] ?? null,
            $tariff['phone'] ?? null,
        ];

        if (!$isManual) {
            $candidates[] = $tariff['destination'] ?? null;
            $candidates[] = $tariff['DESTINATION'] ?? null;
        }

        return self::firstExternalNumber($candidates) ?? self::firstNonEmpty($candidates);
    }

    private static function resolveDestination(array $tariff, bool $isManual): ?string
    {
        $candidates = [
            $tariff['destination'] ?? null,
            $tariff['DESTINATION'] ?? null,
        ];

        if ($isManual) {
            $candidates[] = $tariff['number'] ?? null;
            $candidates[] = $tariff['NUMBER'] ?? null;
        } else {
            $candidates[] = $tariff['client_number'] ?? null;
            $candidates[] = $tariff['customer_number'] ?? null;
            $candidates[] = $tariff['number'] ?? null;
            $candidates[] = $tariff['NUMBER'] ?? null;
            $candidates[] = $tariff['phone'] ?? null;
        }

        return self::firstExternalNumber($candidates) ?? self::firstNonEmpty($candidates);
    }

    private static function resolveCallerId(array $tariff): ?string
    {
        return self::firstNonEmpty([
            $tariff['callerid_num'] ?? null,
            $tariff['CALLERID(num)'] ?? null,
            $tariff['CALLERID_NUM'] ?? null,
            $tariff['caller_number'] ?? null,
            $tariff['caller_id'] ?? null,
        ]);
    }

    private static function resolveEndpointIdentity(array $tariff, ?string $ramal): ?string
    {
        $raw = self::firstNonEmpty([
            $tariff['endpoints'] ?? null,
            $tariff['endpoint'] ?? null,
            $tariff['channel_endpoint'] ?? null,
            $tariff['endpoint_name'] ?? null,
            $tariff['channel'] ?? null,
            $tariff['channel_name'] ?? null,
            $tariff['reserved_ramal'] ?? null,
            $tariff['ramal'] ?? null,
        ]);

        if ($raw !== null) {
            return $raw;
        }

        return $ramal;
    }

    private static function firstExternalNumber(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $normalized = self::normalizeNullable($candidate);
            if ($normalized === null) {
                continue;
            }

            if (!self::isExtension(self::onlyDigits($normalized))) {
                return $normalized;
            }
        }

        return null;
    }

    private static function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $normalized = self::normalizeNullable($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private static function removeTechPrefix(CdrVoice $cdr): void
    {
        if (
            !empty($cdr->destination) &&
            !empty($cdr->techprefix) &&
            str_starts_with((string)$cdr->destination, (string)$cdr->techprefix)
        ) {
            $cdr->destination = substr((string)$cdr->destination, strlen((string)$cdr->techprefix));
        }
    }

    private static function normalizeExtensionLeg(CdrVoice $cdr): void
    {
        $extension = self::onlyDigits($cdr->channel_number);

        if ($extension === '' || !self::isExtension($extension)) {
            return;
        }

        $cdr->channel_number = $extension;
    }

    private static function normalizeAnsweredOutcome(CdrVoice $cdr, array $tariff): void
    {
        $dialstatus = strtoupper(trim((string)($cdr->dialstatus ?? '')));
        if ($dialstatus === 'ANSWER') {
            return;
        }

        $cause = (int)($cdr->cause ?? 0);
        $sipCode = trim((string)($cdr->sip_code ?? ''));
        $billsec = (int)($cdr->billsec ?? 0);
        $billedSeconds = (int)($cdr->billed_seconds ?? 0);
        $answeredAt = trim((string)($cdr->answered ?? ''));
        $state = strtolower(trim((string)($cdr->state ?? '')));
        $agentStatus = strtolower(trim((string)($tariff['agent_call_status'] ?? $tariff['agent_call_outcome'] ?? '')));
        $inBridge = (bool)($tariff['in_bridge'] ?? false);
        $agentAnsweredAt = (int)($tariff['agent_answered_at'] ?? 0);
        $externalLeg = !Voice::isRamalCdr($cdr->channel_number, $cdr->number, $cdr->destination);

        if (!$externalLeg) {
            return;
        }

        $answeredSignals = [
            $billsec > 0,
            $billedSeconds > 0,
            $answeredAt !== '' && $answeredAt !== '0000-00-00 00:00:00',
            $sipCode === '200',
            $cause === 16,
            $state === 'up',
            $inBridge,
            in_array($agentStatus, ['answered', 'up', 'bridged'], true),
            $agentAnsweredAt > 0,
        ];

        if (in_array(true, $answeredSignals, true)) {
            $cdr->dialstatus = 'ANSWER';

            if (empty($cdr->cause_txt) && ($cause === 16 || $sipCode === '200')) {
                $cdr->cause_txt = 'Normal Clearing';
            }
        }
    }

    private static function onlyDigits(mixed $value): string
    {
        return preg_replace('/\D+/', '', (string)($value ?? '')) ?: '';
    }

    private static function isExtension(string $value): bool
    {
        $digits = self::onlyDigits($value);
        $normalized = ltrim($digits, '0');

        if ($normalized === '') {
            $normalized = '0';
        }

        return strlen($normalized) >= 3 && strlen($normalized) <= 8;
    }
}

final class VoiceCdrUserSnapshot
{
    public static function apply(CdrVoice $cdr): void
    {
        static $cache = [];

        if (empty($cdr->user_id)) {
            return;
        }

        $userId = (int)$cdr->user_id;

        if (!array_key_exists($userId, $cache)) {
            $user = UserAuthentication::getUserById($userId);

            $cache[$userId] = $user ? [
                'name' => $user->name ?? null,
                'account_code' => $user->account_code ?? null,
            ] : null;
        }

        if ($cache[$userId]) {
            $cdr->user_name = $cache[$userId]['name'];
            $cdr->user_account_code = $cache[$userId]['account_code'];
        }
    }
}

final class VoiceRecordingIndexer
{
    public static function indexIfNeeded(RedisClient $redis, CdrVoice $cdr): void
    {
        if ($cdr->dialstatus !== 'ANSWER' || empty($cdr->call_id)) {
            return;
        }

        if (Voice::isRamalCdr($cdr->channel_number, $cdr->number, $cdr->destination)) {
            echo " [AUDIO-INDEX] Pulando indexacao: perna identificada como RAMAL.\n";
            return;
        }

        $contextRaw = $redis->get("voice:call_context:{$cdr->call_id}");
        if (!$contextRaw) {
            return;
        }

        $context = json_decode((string)$contextRaw, true);
        if (!is_array($context) || (int)($context['record_calls'] ?? 0) !== 1) {
            return;
        }

        $fileName = "{$cdr->call_id}.wav";
        $fullPath = "/var/spool/asterisk/recording/tenant_{$cdr->tenancy_id}/user_{$cdr->user_id}/{$fileName}";

        (new AsteriskExtensionsSip())->createAudio(
            [
                'user_id' => $cdr->user_id,
                'tenant_id' => $cdr->tenancy_id,
            ],
            [
                'tenant_id' => $cdr->tenancy_id,
                'user_id' => $cdr->user_id,
                'name' => 'Grav: ' . ($cdr->destination ?? $cdr->call_id),
                'role' => $cdr->role,
                'file_name' => $fileName,
                'path' => $fullPath,
                'category' => 'recording',
            ]
        );

        echo " [AUDIO-INDEX] Sucesso (destino externo): {$fileName}\n";
    }
}

final class VoiceCampaignCdrUpdater
{
    public static function update(CdrVoice $cdr): void
    {
        if (empty($cdr->campaign_id)) {
            return;
        }

        if (!self::shouldCountCampaignCdr($cdr)) {
            return;
        }

        $status = self::normalizeCampaignDialstatus($cdr);

        CampaignVoice::incrementCampaignCounters(
            (int)$cdr->campaign_id,
            $status
        );
    }

    private static function normalizeCampaignDialstatus(CdrVoice $cdr): string
    {
        $dialstatus = strtoupper(trim((string)($cdr->dialstatus ?? '')));
        if ($dialstatus === 'ANSWER') {
            return 'ANSWER';
        }

        $answeredAt = trim((string)($cdr->answered ?? ''));
        $billsec = (int)($cdr->billsec ?? 0);
        $billedSeconds = (int)($cdr->billed_seconds ?? 0);
        $sipCode = trim((string)($cdr->sip_code ?? ''));
        $cause = (int)($cdr->cause ?? 0);

        if (
            ($answeredAt !== '' && $answeredAt !== '0000-00-00 00:00:00')
            || $billsec > 0
            || $billedSeconds > 0
            || $sipCode === '200'
            || $cause === 16
        ) {
            return 'ANSWER';
        }

        return $dialstatus !== '' ? $dialstatus : 'FAILED';
    }

    private static function shouldCountCampaignCdr(CdrVoice $cdr): bool
    {
        $value = round((float)($cdr->value ?? 0), 4);
        if ($value > 0.0001) {
            return true;
        }

        return !Voice::isRamalCdr($cdr->channel_number, $cdr->number, $cdr->destination);
    }
}

final class VoiceBillingProcessor
{
    public static function process(CdrVoice $cdr): void
    {
        if ($cdr->type === 'service_fee') {
            self::processServiceFee($cdr);
            return;
        }

        if ((float)$cdr->value <= 0) {
            return;
        }

        foreach (self::resolveUsageCharges($cdr) as $charge) {
            self::applyCharge($cdr, $charge);
        }
    }

    private static function processServiceFee(CdrVoice $cdr): void
    {
        if ((float)$cdr->taxa_of_service <= 0) {
            return;
        }

        $plan = self::buildServiceFeePlan($cdr);
        if (empty($plan['legs'])) {
            return;
        }

        $result = FinancialHierarchyBillingService::debitPlan($plan);
        if (empty($result['ok'])) {
            VoiceCdrDebug::send([
                'financial_debit' => false,
                'reason' => 'service_fee_batch_failed',
                'channel_id' => $cdr->channel_id,
                'call_id' => $cdr->call_id,
                'user_id' => (int)$cdr->user_id,
                'amount' => round((float)$cdr->taxa_of_service, 4),
            ]);
        }
    }

    /**
     * Resolve quem paga sem usar role como decisor.
     *
     * Compatibilidade inicial:
     * - owner_id paga o valor de varejo calculado pelo Asterisk;
     * - se owner_id não for o dono do tenant, o dono do tenant paga o custo upstream.
     */
    private static function resolveUsageCharges(CdrVoice $cdr): array
    {
        try {
            $plan = self::buildUsagePlan($cdr);
        } catch (\Throwable $e) {
            VoiceCdrDebug::send([
                'skip' => true,
                'reason' => 'hierarchy_plan_failed',
                'tenancy_id' => $cdr->tenancy_id,
                'channel_id' => $cdr->channel_id,
                'user_id' => $cdr->user_id,
                'value' => $cdr->value,
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        return [[
            'billing_plan' => $plan,
        ]];
    }

    private static function applyCharge(CdrVoice $cdr, array $charge): void
    {
        if (isset($charge['billing_plan']) && is_array($charge['billing_plan'])) {
            $result = FinancialHierarchyBillingService::debitPlan($charge['billing_plan']);
            if (!empty($result['ok'])) {
                return;
            }

            VoiceCdrDebug::send([
                'financial_debit' => false,
                'reason' => 'hierarchy_batch_failed',
                'channel_id' => $cdr->channel_id,
                'call_id' => $cdr->call_id,
                'user_id' => (int)$cdr->user_id,
                'amount' => round((float)$cdr->value, 4),
            ]);
            return;
        }

        $userId = (int)($charge['user_id'] ?? 0);
        $amount = round((float)($charge['amount'] ?? 0), 4);
        $wallet = (string)($charge['wallet'] ?? 'admin');

        if (!$userId || $amount <= 0) {
            return;
        }

        $operationKey = sprintf(
            'voice:%s:%s:%s:%d',
            (string)($cdr->call_id ?: $cdr->channel_id),
            strtolower((string)($charge['description'] ?? 'voice')),
            $wallet,
            $userId
        );

        $result = FinancialTransactionService::debit([
            'user_id' => $userId,
            'tenancy_id' => (string)$cdr->tenancy_id,
            'wallet' => $wallet === 'reseller'
                ? FinancialTransactionService::WALLET_RESELLER
                : FinancialTransactionService::WALLET_ADMIN,
            'amount' => $amount,
            'operation_key' => $operationKey,
            'source' => 'voice_cdr',
            'description' => sprintf(
                "%s | channel_id:%s dst:%s dur:%ss status:%s type:%s cost:%s",
                (string)($charge['description'] ?? 'VOICE'),
                $cdr->channel_id,
                $cdr->destination,
                $cdr->duration,
                $cdr->dialstatus,
                $cdr->type,
                number_format($amount, 4, '.', '')
            ),
            'provider_reference' => (string)($cdr->call_id ?: $cdr->channel_id),
            'related_type' => 'voice_call',
            'related_id' => (string)($cdr->call_id ?: $cdr->channel_id),
            'metadata' => [
                'channel_id' => $cdr->channel_id,
                'call_id' => $cdr->call_id,
                'dialstatus' => $cdr->dialstatus,
                'voice_type' => $cdr->type,
                'wallet' => $wallet,
            ],
            'legacy_log_amount' => $amount,
        ]);

        if (!$result['ok']) {
            VoiceCdrDebug::send([
                'financial_debit' => false,
                'reason' => $result['status'] ?? 'unknown',
                'channel_id' => $cdr->channel_id,
                'call_id' => $cdr->call_id,
                'user_id' => $userId,
                'wallet' => $wallet,
                'amount' => $amount,
            ]);
            return;
        }
    }

    private static function buildUsagePlan(CdrVoice $cdr): array
    {
        $context = FinancialHierarchyResolver::resolveContext((int)$cdr->user_id, (string)$cdr->tenancy_id);
        $resellerAmount = 0.0;
        $adminAmount = 0.0;

        if ($context->reseller_id && (int)$context->reseller_id !== (int)$cdr->user_id) {
            $resellerAmount = self::calculateUsageAmountForUser($cdr, (int)$context->reseller_id);
        }

        if ($context->owner_admin_id > 0 && (int)$context->owner_admin_id !== (int)$cdr->user_id) {
            $adminAmount = self::calculateUsageAmountForUser($cdr, (int)$context->owner_admin_id);
        }

        return FinancialHierarchyBillingService::buildDebitPlan([
            'actor_user_id' => (int)$cdr->user_id,
            'tenancy_id' => (string)$cdr->tenancy_id,
            'module' => 'voice',
            'event' => 'cdr_usage',
            'retail_amount' => round((float)$cdr->value, 4),
            'reseller_amount' => $resellerAmount,
            'admin_amount' => $adminAmount,
            'provider_reference' => (string)($cdr->call_id ?: $cdr->channel_id),
            'related_type' => 'voice_call',
            'related_id' => (string)($cdr->call_id ?: $cdr->channel_id),
            'operation_key_base' => sprintf('voice:%s:usage', (string)($cdr->call_id ?: $cdr->channel_id)),
            'description_prefix' => 'VOICE CDR USAGE',
            'metadata' => [
                'channel_id' => $cdr->channel_id,
                'call_id' => $cdr->call_id,
                'dialstatus' => $cdr->dialstatus,
                'voice_type' => $cdr->type,
                'duration' => (float)$cdr->duration,
                'trunk_billing_type' => $cdr->trunk_billing_type,
            ],
        ]);
    }

    private static function buildServiceFeePlan(CdrVoice $cdr): array
    {
        $context = FinancialHierarchyResolver::resolveContext((int)$cdr->user_id, (string)$cdr->tenancy_id);
        $retailAmount = round((float)$cdr->taxa_of_service, 4);
        $resellerAmount = 0.0;
        $adminAmount = 0.0;

        if ($context->reseller_id && (int)$context->reseller_id !== (int)$cdr->user_id) {
            $resellerAmount = self::serviceFeeAmountForUser((string)$cdr->tenancy_id, (int)$context->reseller_id);
        }

        if ($context->owner_admin_id > 0 && (int)$context->owner_admin_id !== (int)$cdr->user_id) {
            $adminAmount = self::serviceFeeAmountForUser((string)$cdr->tenancy_id, (int)$context->owner_admin_id);
        }

        return FinancialHierarchyBillingService::buildDebitPlan([
            'actor_user_id' => (int)$cdr->user_id,
            'tenancy_id' => (string)$cdr->tenancy_id,
            'module' => 'voice',
            'event' => 'service_fee',
            'retail_amount' => $retailAmount,
            'reseller_amount' => $resellerAmount,
            'admin_amount' => $adminAmount,
            'provider_reference' => (string)($cdr->call_id ?: $cdr->channel_id),
            'related_type' => 'voice_call',
            'related_id' => (string)($cdr->call_id ?: $cdr->channel_id),
            'operation_key_base' => sprintf('voice:%s:service_fee', (string)($cdr->call_id ?: $cdr->channel_id)),
            'description_prefix' => 'VOICE SERVICE FEE',
            'metadata' => [
                'channel_id' => $cdr->channel_id,
                'call_id' => $cdr->call_id,
                'dialstatus' => $cdr->dialstatus,
                'voice_type' => $cdr->type,
            ],
        ]);
    }

    private static function calculateUpstreamCost(CdrVoice $cdr, int $tenantOwnerId): float
    {
        return self::calculateUsageAmountForUser($cdr, $tenantOwnerId);
    }

    private static function calculateUsageAmountForUser(CdrVoice $cdr, int $userId): float
    {
        if ($userId <= 0) {
            return 0.0;
        }

        $planId = RegisterTenancies::getActivePlanId((string)$cdr->tenancy_id);
        try {
            $rates = VoicePricingService::planRates($userId, (string)$cdr->tenancy_id, $planId);
        } catch (\Throwable) {
            return 0.0;
        }

        $billingType = VoicePricingService::normalizeBillingType($cdr->trunk_billing_type ?? null);
        $voiceCost = match ($billingType) {
            VoicePricingService::BILLING_OPEN => (float)($rates['voice_open_rate'] ?? 0),
            VoicePricingService::BILLING_SMART => (float)($rates['voice_smart_rate'] ?? 0),
            default => (float)($rates['voice_smart_rate'] ?? 0),
        };
        $smsCost = (float)($rates['sms'] ?? 0);
        $torpedoCost = (float)($rates['torpedo'] ?? 0);
        $whatsCost = (float)(WhatsAppBilling::categoryPricesForUser($userId, (string)$cdr->tenancy_id)['marketing'] ?? 0);

        return round(match ($cdr->type) {
            'normal',
            'voice',
            'outbound' => self::calcNormal((float)$cdr->duration, $voiceCost),
            'torpedo' => self::calcTorpedo((float)$cdr->duration, $torpedoCost, $voiceCost),
            'sms' => $smsCost,
            'whatsapp' => $whatsCost,
            default => 0.0,
        }, 4);
    }

    private static function serviceFeeAmountForUser(string $tenancyId, int $userId): float
    {
        if ($userId <= 0 || trim($tenancyId) === '') {
            return 0.0;
        }

        $planId = RegisterTenancies::getActivePlanId($tenancyId);
        try {
            $rates = VoicePricingService::planRates($userId, $tenancyId, $planId);
            return round((float)($rates['service_fee'] ?? 0), 4);
        } catch (\Throwable) {
            $balance = BalanceSms::getBalanceSms($userId, $tenancyId);
            return round((float)($balance->service_fee ?? 0), 4);
        }
    }

    private static function tenantOwnerId(CdrVoice $cdr): ?int
    {
        if (empty($cdr->tenancy_id)) {
            return null;
        }

        $ownerId = RegisterTenancies::getTenancyOwnerUserId($cdr->tenancy_id);

        return $ownerId ? (int)$ownerId : null;
    }

    private static function calcNormal(float $durationSec, float $minuteCost): float
    {
        $halfRate = $minuteCost / 2;

        if ($durationSec <= 30) {
            return round($halfRate, 4);
        }

        if ($durationSec <= 60) {
            $extra = ceil(($durationSec - 30) / 6) * 6;
            $billedDuration = 30 + $extra;
            $progress = ($billedDuration - 30) / 30;

            return round($halfRate + ($progress * $halfRate), 4);
        }

        $extra = ceil(($durationSec - 60) / 6) * 6;
        $billedDuration = 60 + $extra;

        return round(($billedDuration / 60) * $minuteCost, 4);
    }

    private static function calcTorpedo(float $durationSec, float $torpedoCost, float $minuteCost): float
    {
        if ($durationSec <= 60) {
            return round($torpedoCost, 4);
        }

        $extraSec = max(0, $durationSec - 60);
        $steps = (int)ceil($extraSec / 6);

        return round($torpedoCost + ($steps * ($minuteCost / 10.0)), 4);
    }
}

final class VoiceCdrDebug
{
    public static function send(array $payload, bool $emit = true): void
    {
        error_log('[voice_cdr_debug] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (!$emit) {
            return;
        }

        echo "event: debug\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

        @ob_flush();
        @flush();
    }
}

