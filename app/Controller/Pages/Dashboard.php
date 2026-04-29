<?php

namespace App\Controller\Pages;

use App\Config\TelephonyConfig;
use App\Http\Response;
use App\RedisConn;
use App\Model\Entity\CampaignSearch;
use App\Model\Entity\CampaignVoice;
use App\Model\Entity\CampaignVoiceSchedule;
use App\Model\Entity\CdrVoice;
use App\Model\Entity\Rates;
use App\Model\Entity\RegisterTenancies;
use App\Model\Entity\UserPlans;
use App\Model\Entity\UserSearch;
use App\Session\User as SessionUser;
use App\Utils\TenancyHelper;
use App\Utils\View;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\PixSearch;
use App\Model\Entity\CallbackSms;
use App\Model\Entity\RefillsResellers;
use GuzzleHttp\Client;
use WilliamCosta\DatabaseManager\Database;

class Dashboard extends ViewComponents
{
    public static function getDashboard($request): string
    {
        $content = View::render('/dashboard/index', []);
        return parent::getComponentsDashboard('Maxx Solutions - SMS | Dashboard', $content);
    }

    private static function mergeCampaignCounts(array $sms, array $voiceRaw): array
    {
        // SMS (já vem normalizado)
        $ativaSms      = (int)($sms['ativa'] ?? 0);
        $finalizadaSms = (int)($sms['finalizada'] ?? 0);
        $inativaSms    = (int)($sms['inativa'] ?? 0); // SMS pode continuar tendo inativa

        // VOZ (enum y/p/n/f/c)
        $ativaVoz        = (int)($voiceRaw['y'] ?? 0);
        $processandoVoz  = (int)($voiceRaw['p'] ?? 0);
        $pausadaVoz      = (int)($voiceRaw['n'] ?? 0);
        $finalizadaVoz   = (int)($voiceRaw['f'] ?? 0);
        $canceladaVoz    = (int)($voiceRaw['c'] ?? 0);
        $agendadaVoz     = (int)($voiceRaw['s'] ?? 0);

        return [
            // 👇 cards principais
            'ativa'       => $ativaSms + $ativaVoz + $processandoVoz,
            'finalizada'  => $finalizadaSms + $finalizadaVoz,
            'inativa'     => $inativaSms, // ⚠️ só SMS tem inativa real

            // 👇 extras (se quiser usar depois)
            'processando' => $processandoVoz,
            'pausada'     => $pausadaVoz,
            'cancelada'   => $canceladaVoz,
            'agendada'    => $agendadaVoz,
        ];
    }

    private static function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        return sprintf('%02d:%02ds', $minutes, $remaining);
    }

    private static function countFreshActiveCalls(array $activeData, bool $asteriskOnline): int
    {
        if (!$asteriskOnline) {
            return 0;
        }

        $snapshotTs = (int)($activeData['server_now'] ?? 0);

        if ($snapshotTs <= 0 && !empty($activeData['timestamp'])) {
            $snapshotTs = strtotime((string)$activeData['timestamp']) ?: 0;
        }

        if ($snapshotTs <= 0 || (time() - $snapshotTs) > 20) {
            return 0;
        }

        $calls = is_array($activeData['chamadas'] ?? null) ? $activeData['chamadas'] : [];
        $uniqueCalls = [];

        foreach ($calls as $call) {
            if (!is_array($call) || !empty($call['ended'])) {
                continue;
            }

            $vars = is_array($call['vars'] ?? null) ? $call['vars'] : [];
            $logicalId = trim((string)(
                $call['call_id']
                ?? $vars['CALL_ID']
                ?? $vars['__CALL_ID']
                ?? ''
            ));

            if ($logicalId === '') {
                $logicalId = trim(implode('|', array_filter([
                    (string)($call['caller'] ?? ''),
                    (string)($call['destination'] ?? $call['number'] ?? ''),
                    (string)($call['started'] ?? ''),
                ], static fn($value) => $value !== '')));
            }

            if ($logicalId === '') {
                $logicalId = (string)($call['id'] ?? spl_object_id((object)$call));
            }

            $uniqueCalls[$logicalId] = true;
        }

        return count($uniqueCalls);
    }

    private static function measureAsteriskLatencyMs(): ?int
    {
        try {
            $client = new Client([
                'base_uri' => 'http://' . TelephonyConfig::ariHost() . ':' . TelephonyConfig::ariPort() . '/',
                'timeout' => 1.5,
                'connect_timeout' => 1.0,
                'http_errors' => false,
            ]);

            $start = microtime(true);
            $response = $client->get('ari/asterisk/info', [
                'auth' => [TelephonyConfig::ariUser(), TelephonyConfig::ariPass()],
                'query' => ['only' => 'system'],
                'headers' => ['Accept' => 'application/json'],
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 500) {
                return null;
            }

            return max(1, (int)round((microtime(true) - $start) * 1000));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function buildDashboardHealth(array $filters, object $cdr, array $obUser): array
    {
        $redisStatus = 'warning';
        $redisDetail = 'Sem conexao';
        $activeCalls = 0;
        $lastCheck = date('H:i:s');

        try {
            $redis = RedisConn::get();
            $redis->ping();
            $redisStatus = 'ok';
            $redisDetail = 'Conectado';

            $activeRaw = $redis->get('asterisk:active_calls');
            $activeData = $activeRaw ? json_decode($activeRaw, true) : [];

            $asteriskLatencyMs = self::measureAsteriskLatencyMs();
            $asteriskOnline = $asteriskLatencyMs !== null;
            $activeCalls = self::countFreshActiveCalls(is_array($activeData) ? $activeData : [], $asteriskOnline);
        } catch (\Throwable) {
            $asteriskOnline = false;
            $asteriskLatencyMs = null;
        }

        $trunkSummary = self::buildTrunkSummary($obUser);
        $trunkOnline = (int)($trunkSummary['online'] ?? 0);
        $trunkTotal = (int)($trunkSummary['total'] ?? 0);
        $trunkStatus = $trunkOnline > 0 ? 'ok' : 'critical';
        $trunkDetail = $trunkTotal > 0
            ? $trunkOnline . ' tronco(s) online'
            : 'Nenhum tronco cadastrado';
        $lastCallDetail = self::buildLastCallDetail(self::getLastCallSummary($filters));

        return [
            'last_check' => $lastCheck,
            'metrics' => [
                'asterisk' => [
                    'status' => $asteriskOnline ? 'ok' : 'warning',
                    'detail' => $asteriskLatencyMs !== null ? $asteriskLatencyMs . ' ms' : '-- ms'
                ],
                'redis' => [
                    'status' => $redisStatus,
                    'detail' => $redisDetail
                ],
                'trunks' => [
                    'status' => $trunkStatus,
                    'detail' => $trunkDetail,
                    'items' => []
                ],
                'calls' => [
                    'status' => $activeCalls > 0 ? 'ok' : 'warning',
                    'value' => $activeCalls,
                    'detail' => $activeCalls > 0
                        ? $activeCalls . ' chamada(s) em andamento'
                        : $lastCallDetail
                ]
            ]
        ];
    }

    private static function enrichCardsPayload(array $data, array $filters, object $cdr, array $obUser): array
    {
        $whatsappSummary = self::buildDashboardWhatsAppSummary($obUser);
        $smsTotal = max(0, (int)($data['smsEnviados'] ?? 0));
        $smsDelivered = max(0, (int)($data['smsEntregues'] ?? 0));
        $smsResponses = max(0, (int)($data['smsRespostas'] ?? 0));
        $smsDeliveryRate = $smsTotal > 0 ? round(($smsDelivered / $smsTotal) * 100, 1) : 0;

        $data['whatsappEnviados'] = $whatsappSummary['sent_messages'];
        $data['whatsappConversas'] = $whatsappSummary['conversations'];
        $data['whatsappNaoLidas'] = $whatsappSummary['unread'];
        $data['whatsappCampanhas'] = $whatsappSummary['campaigns'];
        $data['whatsappContas'] = $whatsappSummary['accounts'];
        $data['smsTaxaEntrega'] = $smsDeliveryRate;
        $data['consumoWhats'] = $data['consumoWhats'] ?? '0,00';

        $totalVoice = max(0, (int)($cdr->total ?? 0));
        $answeredVoice = max(0, (int)($cdr->answer ?? 0));
        $asr = $totalVoice > 0 ? round(($answeredVoice / $totalVoice) * 100, 1) : 0;
        $acd = $answeredVoice > 0 ? intdiv((int)($cdr->duration_total ?? 0), $answeredVoice) : 0;

        $whatsTotal = max(0, (int)($data['whatsappConversas'] ?? 0));
        $whatsUnread = max(0, (int)($data['whatsappNaoLidas'] ?? 0));

        $data['health'] = self::buildDashboardHealth($filters, $cdr, $obUser);
        $data['operational_cards'] = [
            'voip' => [
                'title' => 'VoIP Performance',
                'metric_label' => 'ASR / ACD',
                'primary_value' => $asr,
                'secondary_text' => self::formatDuration($acd),
                'progress' => $asr,
                'status' => $asr >= 60 ? 'ok' : ($asr > 0 ? 'warning' : 'critical')
            ],
            'sms' => [
                'title' => 'SMS Gateway',
                'metric_label' => 'Entrega / Respostas',
                'primary_value' => $smsDeliveryRate,
                'secondary_text' => $smsResponses . ' respostas',
                'progress' => $smsDeliveryRate,
                'status' => $smsTotal > 0 ? ($smsDeliveryRate >= 80 ? 'ok' : 'warning') : 'warning'
            ],
            'whatsapp' => [
                'title' => 'WhatsApp Central',
                'metric_label' => 'Conversas / Não lidas',
                'primary_value' => $whatsTotal,
                'secondary_text' => $whatsUnread . ' não lidas',
                'progress' => min(100, $whatsTotal > 0 ? (($whatsTotal - $whatsUnread) / max(1, $whatsTotal)) * 100 : 0),
                'status' => $whatsappSummary['accounts'] > 0 ? ($whatsUnread > 0 ? 'warning' : 'ok') : 'critical'
            ]
        ];

        return $data;
    }

    private static function countSmsResponses(?string $tenancyId, ?int $userId = null): int
    {
        $where = "webhook_action = 'mo'";
        $params = [];

        if (!empty($tenancyId)) {
            $where .= ' AND tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $tenancyId;
        }

        if ($userId !== null) {
            $where .= ' AND user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        try {
            $row = (new Database())->execute(
                "SELECT COUNT(*) AS total FROM callback WHERE {$where}",
                $params
            )->fetch(\PDO::FETCH_ASSOC);

            return (int)($row['total'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function buildWhatsAppScopeWhere(array $obUser, string $alias = 'wc'): array
    {
        $role = strtolower((string)($obUser['function'] ?? ''));
        $params = [];

        if ($role === 'super_admin') {
            return ['1=1', $params];
        }

        $where = "{$alias}.tenancy_id = :wa_tenancy_id";
        $params[':wa_tenancy_id'] = (string)($obUser['tenancy_id'] ?? '');

        if ($role !== 'admin') {
            $where .= " AND {$alias}.user_id = :wa_user_id";
            $params[':wa_user_id'] = (int)($obUser['id'] ?? 0);
        }

        return [$where, $params];
    }

    private static function dashboardScalar(string $sql, array $params = []): int
    {
        $row = (new Database())->execute($sql, $params)->fetch(\PDO::FETCH_ASSOC);
        return (int)($row['total'] ?? 0);
    }

    private static function whatsappTablesReady(): bool
    {
        foreach (['whatsapp_accounts', 'whatsapp_campaigns', 'whatsapp_conversations', 'whatsapp_messages'] as $table) {
            $row = (new Database())->execute(
                'SELECT COUNT(*) AS total
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table',
                [':table' => $table]
            )->fetch(\PDO::FETCH_ASSOC);

            if ((int)($row['total'] ?? 0) === 0) {
                return false;
            }
        }

        return true;
    }

    private static function buildDashboardWhatsAppSummary(array $obUser): array
    {
        try {
            if (!self::whatsappTablesReady()) {
                throw new \RuntimeException('WhatsApp tables not ready');
            }

            [$conversationWhere, $conversationParams] = self::buildWhatsAppScopeWhere($obUser, 'wc');
            [$campaignWhere, $campaignParams] = self::buildWhatsAppScopeWhere($obUser, 'wcamp');
            [$accountWhere, $accountParams] = self::buildWhatsAppScopeWhere($obUser, 'wa');

            $sentMessages = self::dashboardScalar(
                "SELECT COUNT(*) AS total
                 FROM whatsapp_messages wm
                 INNER JOIN whatsapp_conversations wc ON wc.id = wm.conversation_id
                 WHERE {$conversationWhere}
                   AND wm.direction = 'outbound'
                   AND wm.status IN ('sent', 'delivered', 'read')",
                $conversationParams
            );

            $conversations = self::dashboardScalar(
                "SELECT COUNT(*) AS total FROM whatsapp_conversations wc WHERE {$conversationWhere}",
                $conversationParams
            );

            $unread = self::dashboardScalar(
                "SELECT COALESCE(SUM(wc.unread_count), 0) AS total FROM whatsapp_conversations wc WHERE {$conversationWhere}",
                $conversationParams
            );

            $campaigns = self::dashboardScalar(
                "SELECT COUNT(*) AS total FROM whatsapp_campaigns wcamp WHERE {$campaignWhere}",
                $campaignParams
            );

            $accounts = self::dashboardScalar(
                "SELECT COUNT(*) AS total FROM whatsapp_accounts wa WHERE {$accountWhere} AND wa.status = 'active'",
                $accountParams
            );

            return [
                'sent_messages' => $sentMessages,
                'conversations' => $conversations,
                'unread' => $unread,
                'campaigns' => $campaigns,
                'accounts' => $accounts,
            ];
        } catch (\Throwable) {
            return [
                'sent_messages' => 0,
                'conversations' => 0,
                'unread' => 0,
                'campaigns' => 0,
                'accounts' => 0,
            ];
        }
    }

    private static function whatsPeriodCondition(string $period, string $mode, string $field = 'wm.created_at'): string
    {
        if ($period === 'week') {
            return $mode === 'current'
                ? "YEARWEEK({$field}, 1) = YEARWEEK(CURDATE(), 1)"
                : "YEARWEEK({$field}, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)";
        }

        if ($period === 'month') {
            return $mode === 'current'
                ? "DATE_FORMAT({$field}, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')"
                : "DATE_FORMAT({$field}, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m')";
        }

        return $mode === 'current'
            ? "DATE({$field}) = CURDATE()"
            : "DATE({$field}) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
    }

    private static function whatsStatusLabel(string $direction, string $status): string
    {
        if ($status === 'failed') return 'Falhas';
        if ($status === 'pending') return 'Pendentes';
        if ($direction === 'inbound' && $status === 'received') return 'Recebidas';
        if ($status === 'delivered') return 'Entregues';
        if ($status === 'read') return 'Lidas';
        return $direction === 'outbound' ? 'Enviadas' : 'Recebidas';
    }

    private static function countWhatsAppMessagesForPeriod(array $obUser, string $period, string $mode): int
    {
        [$where, $params] = self::buildWhatsAppScopeWhere($obUser, 'wc');
        $periodWhere = self::whatsPeriodCondition($period, $mode);

        return self::dashboardScalar(
            "SELECT COUNT(*) AS total
             FROM whatsapp_messages wm
             INNER JOIN whatsapp_conversations wc ON wc.id = wm.conversation_id
             WHERE {$where} AND {$periodWhere}",
            $params
        );
    }

    private static function statusMapWhatsAppForPeriod(array $obUser, string $period): array
    {
        [$where, $params] = self::buildWhatsAppScopeWhere($obUser, 'wc');
        $periodWhere = self::whatsPeriodCondition($period, 'current');

        $rows = (new Database())->execute(
            "SELECT wm.direction, wm.status, COUNT(*) AS total
             FROM whatsapp_messages wm
             INNER JOIN whatsapp_conversations wc ON wc.id = wm.conversation_id
             WHERE {$where} AND {$periodWhere}
             GROUP BY wm.direction, wm.status
             ORDER BY total DESC",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $direction = (string)($row['direction'] ?? '');
            $status = (string)($row['status'] ?? '');
            $label = self::whatsStatusLabel($direction, $status);
            $series = $direction === 'outbound' ? 'Enviadas' : 'Recebidas';

            if (!isset($map[$label])) {
                $map[$label] = [];
            }

            $map[$label][$series] = (int)($map[$label][$series] ?? 0) + (int)($row['total'] ?? 0);
        }

        return $map;
    }

    private static function buildDashboardWhatsAppCharts(array $obUser): array
    {
        try {
            if (!self::whatsappTablesReady()) {
                throw new \RuntimeException('WhatsApp tables not ready');
            }

            return [
                'statusMapDiaWhats' => self::statusMapWhatsAppForPeriod($obUser, 'day'),
                'statusMapSemanaWhats' => self::statusMapWhatsAppForPeriod($obUser, 'week'),
                'statusMapMesWhats' => self::statusMapWhatsAppForPeriod($obUser, 'month'),
                'totalDiaAtualWhats' => self::countWhatsAppMessagesForPeriod($obUser, 'day', 'current'),
                'totalDiaAnteriorWhats' => self::countWhatsAppMessagesForPeriod($obUser, 'day', 'previous'),
                'totalSemanaAtualWhats' => self::countWhatsAppMessagesForPeriod($obUser, 'week', 'current'),
                'totalSemanaAnteriorWhats' => self::countWhatsAppMessagesForPeriod($obUser, 'week', 'previous'),
                'totalMesAtualWhats' => self::countWhatsAppMessagesForPeriod($obUser, 'month', 'current'),
                'totalMesAnteriorWhats' => self::countWhatsAppMessagesForPeriod($obUser, 'month', 'previous'),
            ];
        } catch (\Throwable) {
            return [
                'statusMapDiaWhats' => [],
                'statusMapSemanaWhats' => [],
                'statusMapMesWhats' => [],
                'totalDiaAtualWhats' => 0,
                'totalDiaAnteriorWhats' => 0,
                'totalSemanaAtualWhats' => 0,
                'totalSemanaAnteriorWhats' => 0,
                'totalMesAtualWhats' => 0,
                'totalMesAnteriorWhats' => 0,
            ];
        }
    }

    private static function buildTrunkNameMap(array $obUser): array
    {
        $role = strtolower((string)($obUser['function'] ?? ''));
        $query = ['role' => $role];

        if ($role !== 'super_admin') {
            $query['tenant_id'] = $obUser['tenancy_id'] ?? null;
        }

        try {
            $result = (new AsteriskExtensionsSip())->listTrunks($query);
            if (empty($result['ok'])) {
                return [];
            }

            $rows = $result['data']['data'] ?? $result['data'] ?? [];
            $map = [];

            foreach ($rows as $trunk) {
                $name = trim((string)($trunk['name'] ?? $trunk['trunk_id'] ?? ''));
                if ($name === '') {
                    continue;
                }

                foreach (['trunk_id', 'id', 'name'] as $field) {
                    $key = trim((string)($trunk[$field] ?? ''));
                    if ($key !== '' && $key !== '-' && !isset($map[$key])) {
                        $map[$key] = $name;
                    }
                }
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    private static function labelTrunkCounts(array $counts, array $nameMap): array
    {
        $labeled = [];

        foreach ($counts as $key => $total) {
            $label = $nameMap[(string)$key] ?? (string)$key;
            $labeled[$label] = (int)$total;
        }

        foreach (array_unique(array_values($nameMap)) as $label) {
            if ($label !== '' && !isset($labeled[$label])) {
                $labeled[$label] = 0;
            }
        }

        return $labeled;
    }

    private static function isTrunkOnline(array $trunk): bool
    {
        $truthyFields = ['online', 'is_online', 'asterisk_up'];
        foreach ($truthyFields as $field) {
            if (isset($trunk[$field]) && filter_var($trunk[$field], FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        $status = strtoupper((string)($trunk['sip_status'] ?? $trunk['sip_status_text'] ?? ''));
        return in_array($status, ['OK', 'ONLINE', 'UP', 'REGISTERED'], true);
    }

    private static function buildTrunkSummary(array $obUser): array
    {
        $role = strtolower((string)($obUser['function'] ?? ''));
        $query = ['role' => $role];

        if ($role !== 'super_admin') {
            $query['tenant_id'] = $obUser['tenancy_id'] ?? null;
        }

        try {
            $result = (new AsteriskExtensionsSip())->listTrunks($query);
            if (empty($result['ok'])) {
                return ['online' => 0, 'total' => 0];
            }

            $rows = $result['data']['data'] ?? $result['data'] ?? [];
            $online = 0;

            foreach ($rows as $trunk) {
                if (is_array($trunk) && self::isTrunkOnline($trunk)) {
                    $online++;
                }
            }

            return ['online' => $online, 'total' => count($rows)];
        } catch (\Throwable) {
            return ['online' => 0, 'total' => 0];
        }
    }

    private static function buildLastCallDetail(?array $lastCall): string
    {
        if (!$lastCall || empty($lastCall['started'])) {
            return 'Sem chamadas registradas';
        }

        $time = date('H:i', strtotime((string)$lastCall['started']));
        $status = strtoupper((string)($lastCall['dialstatus'] ?? ''));
        $status = $status !== '' ? $status : 'SEM STATUS';

        return "Ultima {$time} - {$status}";
    }

    private static function dashboardSecurityFilter(array $filters, string $alias = 'cdr'): string
    {
        $userContext = [
            'tenancy_id'    => $filters['tenancy_id'] ?? null,
            'id'            => $filters['user_id'] ?? null,
            'user_function' => $filters['user_function'] ?? ''
        ];

        $where = TenancyHelper::applyCdrSecurityFilter($userContext, $alias);
        return $where . " AND {$alias}.type IN ('normal','outbound','inbound','service_fee')";
    }

    private static function hasCdrColumn(string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }

        try {
            $row = (new Database())->execute("SHOW COLUMNS FROM cdr LIKE :column", [
                ':column' => $column
            ])->fetch(\PDO::FETCH_ASSOC);
            $cache[$column] = !empty($row);
        } catch (\Throwable) {
            $cache[$column] = false;
        }

        return $cache[$column];
    }

    private static function countSipCodes(array $filters, string $period = 'month'): array
    {
        $where = self::dashboardSecurityFilter($filters, 'cdr');
        $dateCondition = match ($period) {
            'day' => 'DATE(cdr.started) = CURDATE()',
            'week' => 'YEARWEEK(cdr.started, 1) = YEARWEEK(CURDATE(), 1)',
            default => 'MONTH(cdr.started) = MONTH(CURDATE()) AND YEAR(cdr.started) = YEAR(CURDATE())',
        };

        $sipCodeExpression = self::hasCdrColumn('sip_code')
            ? "COALESCE(NULLIF(cdr.sip_code, ''), CAST(cdr.cause AS CHAR), '0')"
            : "COALESCE(NULLIF(CAST(cdr.cause AS CHAR), ''), '0')";

        $query = "
            SELECT sip_code, cause_txt, COUNT(*) AS total
            FROM (
                SELECT
                    {$sipCodeExpression} AS sip_code,
                    COALESCE(NULLIF(cdr.cause_txt, ''), NULLIF(cdr.dialstatus, ''), 'Desconhecido') AS cause_txt
                FROM cdr
                WHERE {$dateCondition} AND {$where}
            ) t
            GROUP BY sip_code, cause_txt
            ORDER BY total DESC
            LIMIT 8
        ";

        try {
            $rows = (new Database())->execute($query)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[(string)($row['sip_code'] ?? '0')] = [
                'qtd' => (int)($row['total'] ?? 0),
                'label' => (string)($row['cause_txt'] ?? 'Desconhecido')
            ];
        }

        return $result;
    }

    private static function countGroupedByTrunk(array $filters, string $period = 'month'): array
    {
        $where = self::dashboardSecurityFilter($filters, 'cdr');
        $dateCondition = match ($period) {
            'day' => 'DATE(cdr.started) = CURDATE()',
            'week' => 'YEARWEEK(cdr.started, 1) = YEARWEEK(CURDATE(), 1)',
            default => 'MONTH(cdr.started) = MONTH(CURDATE()) AND YEAR(cdr.started) = YEAR(CURDATE())',
        };

        $query = "
            SELECT
                COALESCE(
                    NULLIF(NULLIF(TRIM(cdr.trunk), ''), '-'),
                    NULLIF(NULLIF(TRIM(cdr.trunk_id), ''), '-')
                ) AS trunk,
                COUNT(*) AS total
            FROM cdr
            WHERE {$dateCondition}
              AND {$where}
              AND COALESCE(
                    NULLIF(NULLIF(TRIM(cdr.trunk), ''), '-'),
                    NULLIF(NULLIF(TRIM(cdr.trunk_id), ''), '-')
                  ) IS NOT NULL
            GROUP BY trunk
            ORDER BY total DESC
            LIMIT 8
        ";

        try {
            $rows = (new Database())->execute($query)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[(string)($row['trunk'] ?? 'Sem tronco')] = (int)($row['total'] ?? 0);
        }

        return $result;
    }

    private static function getLastCallSummary(array $filters): ?array
    {
        $where = self::dashboardSecurityFilter($filters, 'cdr');
        $query = "
            SELECT cdr.started, cdr.dialstatus, cdr.destination, cdr.number
            FROM cdr
            WHERE {$where}
              AND cdr.started IS NOT NULL
            ORDER BY cdr.started DESC
            LIMIT 1
        ";

        try {
            $row = (new Database())->execute($query)->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }

        return $row ?: null;
    }



    public static function getDataViewDash($request)
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, ['status' => 401, 'message' => 'Usuário não autenticado.'], 'application/json');
        }

        $role         = $obUser['function'] ?? null;
        $isReseller   = $role === 'reseller';
        $isAdmin      = $role === 'admin';
        $isSuperAdmin = $role === 'super_admin';

        $calcBalanceVariation = function(array $previousBalances, float $currentBalance): string {
            $gastos = array_map(fn($log) => abs((float)($log->gasto_mes ?? 0)), $previousBalances);
            if (count($gastos) === 0) return '+0%';
            $average = array_sum($gastos) / count($gastos);
            if ($average <= 0) return '+0%';
            $percent = (($currentBalance - $average) / $average) * 100;
            $percent = max(-100, min(100, $percent));
            return ($percent >= 0 ? '+' : '') . round($percent) . '%';
        };

        // ============================
        // SUPER ADMIN
        // ============================
        if ($isSuperAdmin) {
            $currentBalance = DisproClient::getBalanceDISPRO() ?: 0;
            $dataPix = PixSearch::getPixLast($obUser['id'], $obUser['tenancy_id']);
            $currentPix = ($dataPix && isset($dataPix->value)) ? str_replace('.', ',', sprintf("%0.2f", (float)$dataPix->value)) : '00,00';
            $currentData = ($dataPix && !empty($dataPix->confirmed_date) && strtotime($dataPix->confirmed_date)) ? date('d/m/Y H:i', strtotime($dataPix->confirmed_date)) : '--/--/---- --:--';

            $dataSms = CallbackSms::countSentSms(null, null);
            $currentSms = $dataSms->qtd ?? 0;
            $smsResponses = self::countSmsResponses(null, null);
            $valueSms   = (float)(BalanceSms::getBalanceSms(null, null)->value_sms ?? 0);
            $dataValue  = $currentSms * $valueSms;

            $cdrFilters = ['user_function' => 'super_admin'];
            $cdr = CdrVoice::countCdrVoice($cdrFilters);
            $cdrDisposition = $cdr->answer ?? 0;
            $cdrValue = (float)($cdr->value_total ?? 0);
            $cdrTaxa  = (float)($cdr->taxa_total ?? 0);
            $cdrTotal = $cdrValue + $cdrTaxa;

            $smsCampaignsRaw   = CampaignSearch::countCampaignsByStatus(null, null);
            $voiceCampaignsRaw = CampaignVoice::countVoiceCampaignsByStatus(null, null);
            $voiceCampaignsRaw = CampaignVoiceSchedule::addPendingToVoiceCounts($voiceCampaignsRaw, null, null);
            $mergedCampaigns = self::mergeCampaignCounts($smsCampaignsRaw, $voiceCampaignsRaw);
            $totalCampaigns = array_sum($smsCampaignsRaw) + array_sum($voiceCampaignsRaw);
            $totalConsumo   = $dataValue + $cdrTotal;

            $data = [
                'saldoAtual'     => number_format($currentBalance, 4, ',', '.'),
                'saldoVariacao'  => $percentChange ?? '',
                'smsEnviados'    => $currentSms,
                'smsTarifados'   => $currentSms,
                'smsEntregues'    => (int)($dataSms->delivered ?? 0),
                'smsPendentes'    => (int)($dataSms->sent ?? 0),
                'smsFalhas'       => (int)(($dataSms->undeliverable ?? 0) + ($dataSms->expired ?? 0)),
                'smsRespostas'    => $smsResponses,
                'smsCusto'       => 'R$ ' . number_format($dataValue, 4, ',', '.'),
                'ultimoPixValor' => $currentPix,
                'ultimoPixData'  => $currentData,

                'campanhasHoje'  => $totalCampaigns,
                'campanhas'      => $mergedCampaigns,

                'totalConsumo'   => 'R$ ' . number_format($totalConsumo, 4, ',', '.'),

                // 🚀 ADICIONE ESTAS 3 LINHAS AQUI ABAIXO:
                'consumoSms'     => number_format($dataValue, 2, ',', '.'),
                'consumoVoz'     => number_format($cdrTotal, 2, ',', '.'),
                'consumoWhats'   => '0,00',
                'whatsappEnviados'=> 0,

                'disposition'    => $cdrDisposition,
                'cdrTaxa'        => 'R$ ' . number_format($cdrTaxa, 4, ',', '.'),
                'cdrValue'       => 'R$ ' . number_format($cdrTotal, 4, ',', '.'),
            ];
            $data = self::enrichCardsPayload($data, $cdrFilters, $cdr, $obUser);
        }

        // ============================
        // RESELLER
        // ============================
        elseif ($isReseller) {
            $resellerBalance  = BalanceSms::getBalanceSms($obUser['id'], $obUser['tenancy_id']);
            $currentBalance   = (float)($resellerBalance->balance ?? 0);
            $previousBalances = BalanceSms::getBalanceSmsForPreviousMonths($obUser['id'], $obUser['tenancy_id']);
            $percentChange    = $calcBalanceVariation($previousBalances, $currentBalance);

            $rateData  = Rates::getLatestActiveRate($obUser['tenancy_id'], $obUser['id']);
            $valueSms  = (float)($rateData['rate'] ?? 0);
            $dataSms   = CallbackSms::countSentSms($obUser['id'], $obUser['tenancy_id']);
            $currentSms = $dataSms->qtd ?? 0;
            $smsResponses = self::countSmsResponses($obUser['tenancy_id'], $obUser['id']);
            $dataValue  = $currentSms * $valueSms;

            $cdrFilters = [
                'tenancy_id'    => $obUser['tenancy_id'],
                'user_id'       => $obUser['id'],
                'user_function' => 'reseller'
            ];
            $cdr = CdrVoice::countCdrVoice($cdrFilters);

            $cdrDisposition = $cdr->answer ?? 0;
            $cdrValue = (float)($cdr->value_total ?? 0);
            $cdrTaxa  = (float)($cdr->taxa_total ?? 0);
            $cdrTotal = $cdrValue + $cdrTaxa;

            $smsCampaignsRaw   = CampaignSearch::countCampaignsByStatus($obUser['tenancy_id'], $obUser['id']);
            $voiceCampaignsRaw = CampaignVoice::countVoiceCampaignsByStatus($obUser['tenancy_id'], $obUser['id']);
            $voiceCampaignsRaw = CampaignVoiceSchedule::addPendingToVoiceCounts($voiceCampaignsRaw, $obUser['tenancy_id'], $obUser['id']);
            $mergedCampaigns = self::mergeCampaignCounts($smsCampaignsRaw, $voiceCampaignsRaw);
            $totalCampaigns  = array_sum($smsCampaignsRaw) + array_sum($voiceCampaignsRaw);
            $totalConsumo = $dataValue + $cdrTotal;

            $dataPix = RefillsResellers::getLastRefill($obUser['id'], $obUser['tenancy_id']);
            $currentPix  = $dataPix && isset($dataPix->balance) ? number_format((float)$dataPix->balance, 2, ',', '') : '00,00';
            $currentData = (!empty($dataPix->created_at) && strtotime($dataPix->created_at)) ? date('d/m/Y H:i', strtotime($dataPix->created_at)) : '--/--/---- --:--';

            $data = [
                'saldoAtual'     => number_format($currentBalance, 4, ',', '.'),
                'saldoVariacao'  => $percentChange,
                'smsEnviados'    => $currentSms,
                'smsTarifados'   => $currentSms,
                'smsEntregues'    => (int)($dataSms->delivered ?? 0),
                'smsPendentes'    => (int)($dataSms->sent ?? 0),
                'smsFalhas'       => (int)(($dataSms->undeliverable ?? 0) + ($dataSms->expired ?? 0)),
                'smsRespostas'    => $smsResponses,
                'smsCusto'       => 'R$ ' . number_format($dataValue, 4, ',', '.'),
                'ultimoPixValor' => $currentPix,
                'ultimoPixData'  => $currentData,
                'campanhasHoje'  => $totalCampaigns,
                'campanhas'      => $mergedCampaigns,
                'totalConsumo'   => 'R$ ' . number_format($totalConsumo, 4, ',', '.'),

                // 🔥 ADICIONADO PARA O CARD DE CONSUMO PEQUENO
                'consumoSms'     => number_format($dataValue, 2, ',', '.'),
                'consumoVoz'     => number_format($cdrTotal, 2, ',', '.'),
                'consumoWhats'   => '0,00',
                'whatsappEnviados'=> 0,

                'disposition'    => $cdrDisposition,
                'cdrTaxa'        => 'R$ ' . number_format($cdrTaxa, 4, ',', '.'),
                'cdrValue'       => 'R$ ' . number_format($cdrTotal, 4, ',', '.'),
            ];
            $data = self::enrichCardsPayload($data, $cdrFilters, $cdr, $obUser);
        }

        // ============================
        // ADMIN / USER
        // ============================
        else {
            $currentBalance   = BalanceSms::getSumBalanceSms($obUser['id'], $obUser['tenancy_id']);
            $previousBalances = BalanceSms::getBalanceSmsForPreviousMonths($obUser['id'], $obUser['tenancy_id']);
            $percentChange    = $calcBalanceVariation($previousBalances, $currentBalance);

            $dataPix = PixSearch::getPixLast($obUser['id'], $obUser['tenancy_id']);
            $currentPix  = $dataPix && isset($dataPix->value) ? str_replace('.', ',', sprintf("%05.2f", (float)$dataPix->value)) : '00,00';
            $currentData = (!empty($dataPix->confirmed_date) && strtotime($dataPix->confirmed_date)) ? date('d/m/Y H:i', strtotime($dataPix->confirmed_date)) : '--/--/---- --:--';

            $dataSms = CallbackSms::countSentSms($isAdmin ? null : $obUser['id'], $obUser['tenancy_id']);
            $currentSms = $dataSms->qtd ?? 0;
            $smsResponses = self::countSmsResponses($obUser['tenancy_id'], $isAdmin ? null : $obUser['id']);
            $valueSms   = (float)(BalanceSms::getBalanceSms($obUser['id'], $obUser['tenancy_id'])->value_sms ?? 0);
            $dataValue  = $currentSms * $valueSms;

            $cdrFilters = [
                'tenancy_id'    => $obUser['tenancy_id'],
                'user_id'       => $isAdmin ? null : $obUser['id'],
                'user_function' => $role
            ];
            $cdr = CdrVoice::countCdrVoice($cdrFilters);

            $cdrDisposition = $cdr->answer ?? 0;
            $cdrValue = (float)($cdr->value_total ?? 0);
            $cdrTaxa  = BalanceSms::sumAdminServiceFeeFromLogs($obUser['tenancy_id'], (int)$obUser['id']);
            $cdrTotal = $cdrValue + $cdrTaxa;
            $totalConsumo = $dataValue + $cdrTotal;

            $smsCampaignsRaw   = CampaignSearch::countCampaignsByStatus($obUser['tenancy_id'], null);
            $voiceCampaignsRaw = CampaignVoice::countVoiceCampaignsByStatus($obUser['tenancy_id'], null);
            $voiceCampaignsRaw = CampaignVoiceSchedule::addPendingToVoiceCounts($voiceCampaignsRaw, $obUser['tenancy_id'], null);
            $mergedCampaigns = self::mergeCampaignCounts($smsCampaignsRaw, $voiceCampaignsRaw);
            $totalCampaigns  = array_sum($smsCampaignsRaw) + array_sum($voiceCampaignsRaw);

            $data = [
                'saldoAtual'     => number_format($currentBalance, 4, ',', '.'),
                'saldoVariacao'  => $percentChange,
                'smsEnviados'    => $currentSms,
                'smsTarifados'   => $currentSms,
                'smsEntregues'    => (int)($dataSms->delivered ?? 0),
                'smsPendentes'    => (int)($dataSms->sent ?? 0),
                'smsFalhas'       => (int)(($dataSms->undeliverable ?? 0) + ($dataSms->expired ?? 0)),
                'smsRespostas'    => $smsResponses,
                'smsCusto'       => 'R$ ' . number_format($dataValue, 4, ',', '.'),
                'ultimoPixValor' => $currentPix,
                'ultimoPixData'  => $currentData,
                'campanhasHoje'  => $totalCampaigns,
                'campanhas'      => $mergedCampaigns,
                'totalConsumo'   => 'R$ ' . number_format($totalConsumo, 4, ',', '.'),

                // 🔥 ADICIONADO PARA O CARD DE CONSUMO PEQUENO
                'consumoSms'     => number_format($dataValue, 2, ',', '.'),
                'consumoVoz'     => number_format($cdrTotal, 2, ',', '.'),
                'consumoWhats'   => '0,00',
                'whatsappEnviados'=> 0,

                'disposition'    => $cdrDisposition,
                'cdrTaxa'        => 'R$ ' . number_format($cdrTaxa, 4, ',', '.'),
                'cdrValue'       => 'R$ ' . number_format($cdrTotal, 4, ',', '.'),
            ];
            $data = self::enrichCardsPayload($data, $cdrFilters, $cdr, $obUser);
        }

        echo "data: " . json_encode($data) . "\n\n";
        flush();
        exit;
    }


    public static function getDataChartsDashboard($request): Response
    {
        if (session_status() == PHP_SESSION_ACTIVE) session_write_close();

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            echo "event: error\n";
            echo "data: " . json_encode(['status' => 401, 'message' => 'Usuário não autenticado.']) . "\n\n";
            flush();
            exit;
        }

        Voice::processCdrFromRedis();

        try {
            $role = $obUser['function'] ?? null;
            $isReseller   = $role === 'reseller';
            $isAdmin      = $role === 'admin';
            $isSuperAdmin = $role === 'super_admin';

            $tenancyId = $obUser['tenancy_id'];
            $userId    = $obUser['id'];

            $data = [
                'statusMes'       => [],
                'statusSemana'    => [],
                'statusDia'       => [],
                'totalMesAtual'   => 0,
                'totalMesAnterior'=> 0,
                'totalSemanaAtual'=> 0,
                'totalSemanaAnterior'=> 0,
                'totalDiaAtual'   => 0,
                'totalDiaAnterior'=> 0,
                'statusMesVoice'  => [],
                'statusSemanaVoice' => [],
                'statusDiaVoice'  => [],
                'totalMesAtualVoice'   => 0,
                'totalMesAnteriorVoice'=> 0,
                'totalSemanaAtualVoice'=> 0,
                'totalSemanaAnteriorVoice'=> 0,
                'totalDiaAtualVoice'   => 0,
                'totalDiaAnteriorVoice'=> 0
            ];

            $dataOperator = [];
            $dataValuesPix = [];
            $dataValuesPixRefill = [];

            // ======================================================
            // 🔹 SUPER ADMIN
            // ======================================================
            if ($isSuperAdmin) {
                $data = CallbackSms::fetchStatusCountsWithDay(null, null, null);
                $dataOperatorMonth = CallbackSms::countGroupedByOperatorAllStatus(null, null, null, 'month');
                $dataOperatorDay = CallbackSms::countGroupedByOperatorAllStatus(null, null, null, 'day');
                $dataOperatorWeek = CallbackSms::countGroupedByOperatorAllStatus(null, null, null, 'week');
                $dataValuesPix = PixSearch::getValuesPixCurrentMonth(null, null);
                $dataValuesPixRefill = RefillsResellers::getValuesRefillCurrentMonth(null, null);
                $dataVoice = CdrVoice::fetchVoiceStatusCountsWithDay(null, null, null);

                $dataValuesPix = array_map(fn($item) => ['data' => date('d/m', strtotime($item->confirmed_date)), 'value' => (float)$item->value], $dataValuesPix);
                $dataValuesPixRefill = array_map(fn($item) => ['data' => date('d/m', strtotime($item->created_at)), 'value' => (float)$item->balance], $dataValuesPixRefill);
            }

            // ======================================================
            // 🔹 ADMIN
            // ======================================================
            elseif ($isAdmin) {
                $data = CallbackSms::fetchStatusCountsWithDay($tenancyId, null, null);
                $dataOperatorMonth = CallbackSms::countGroupedByOperatorAllStatus($tenancyId, null, null, 'month');
                $dataOperatorDay = CallbackSms::countGroupedByOperatorAllStatus($tenancyId, null, null, 'day');
                $dataOperatorWeek = CallbackSms::countGroupedByOperatorAllStatus($tenancyId, null, null, 'week');
                $dataValuesPix = PixSearch::getValuesPixCurrentMonth($userId, $tenancyId);
                $dataValuesPixRefill = RefillsResellers::getValuesRefillCurrentMonth(null, $tenancyId);

                // 🔥 Filtro ADMIN: Tenancy preenchida, mas IDs nulos para ver tudo da empresa
                $dataVoice = CdrVoice::fetchVoiceStatusCountsWithDay($tenancyId, null, null);

                $dataValuesPix = array_map(fn($item) => ['data' => date('d/m', strtotime($item->confirmed_date)), 'value' => (float)$item->value], $dataValuesPix);
                $dataValuesPixRefill = array_map(fn($item) => ['data' => date('d/m', strtotime($item->created_at)), 'value' => (float)$item->balance], $dataValuesPixRefill);
            }

            // ======================================================
            // 🔹 RESELLER / USUÁRIO (Lívia)
            // ======================================================
            else {
                if ($isReseller) {
                    $data = CallbackSms::fetchStatusCountsWithDay($tenancyId, null, $userId);
                    $dataOperatorMonth = CallbackSms::countGroupedByOperatorAllStatus($tenancyId, $userId, null, 'month');
                    $dataOperatorDay = CallbackSms::countGroupedByOperatorAllStatus($tenancyId, $userId, null, 'day');
                    $dataOperatorWeek = CallbackSms::countGroupedByOperatorAllStatus($tenancyId, $userId, null, 'week');
                    $dataValuesPix = RefillsResellers::getValuesRefillCurrentMonth($userId, $tenancyId);

                    // 🔥 Filtro RESELLER: Trava no ID dele para ver ele + clientes dele
                    $dataVoice = CdrVoice::fetchVoiceStatusCountsWithDay($tenancyId, $userId, $userId);
                } else {
                    // Aqui entra a LÍVIA (usuário comum)
                    $data = CallbackSms::fetchStatusCountsWithDay($tenancyId, $userId, null);
                    $dataOperatorDay = [];
                    $dataOperatorWeek = [];
                    $dataOperatorMonth = [];
                    $dataValuesPix = [];

                    // 🔥 Filtro LÍVIA: Trava no ID dela para ela não ver os outros agentes
                    $dataVoice = CdrVoice::fetchVoiceStatusCountsWithDay($tenancyId, $userId, null);
                }

                $dataValuesPix = array_map(fn($item) => ['data' => date('d/m', strtotime($item->created_at)), 'value' => (float)$item->balance], $dataValuesPix);
            }

            $cdrFilters = [
                'tenancy_id' => $isSuperAdmin ? null : $tenancyId,
                'user_id' => ($isAdmin || $isSuperAdmin) ? null : $userId,
                'user_function' => $isSuperAdmin ? 'super_admin' : ($role ?: 'agent')
            ];

            $sipCodesDay = self::countSipCodes($cdrFilters, 'day');
            $sipCodesWeek = self::countSipCodes($cdrFilters, 'week');
            $sipCodesMonth = self::countSipCodes($cdrFilters, 'month');
            $trunkNameMap = self::buildTrunkNameMap($obUser);
            $trunkCountsDay = self::labelTrunkCounts(self::countGroupedByTrunk($cdrFilters, 'day'), $trunkNameMap);
            $trunkCountsWeek = self::labelTrunkCounts(self::countGroupedByTrunk($cdrFilters, 'week'), $trunkNameMap);
            $trunkCountsMonth = self::labelTrunkCounts(self::countGroupedByTrunk($cdrFilters, 'month'), $trunkNameMap);
            $whatsappCharts = self::buildDashboardWhatsAppCharts($obUser);

            $response = [
                'statusMapMes'           => $data['statusMes'],
                'statusMapDia'           => $data['statusDia'],
                'statusMapSemana'        => $data['statusSemana'] ?? $data['statusDia'],
                'totalMesAtual'          => $data['totalMesAtual'],
                'totalMesAnterior'       => $data['totalMesAnterior'],
                'totalDiaAtual'          => $data['totalDiaAtual'],
                'totalDiaAnterior'       => $data['totalDiaAnterior'],
                'totalSemanaAtual'       => $data['totalSemanaAtual'] ?? $data['totalDiaAtual'],
                'totalSemanaAnterior'    => $data['totalSemanaAnterior'] ?? $data['totalDiaAnterior'],

                'statusMapMesVoice'      => $dataVoice['statusMes'],
                'statusMapDiaVoice'      => $dataVoice['statusDia'],
                'statusMapSemanaVoice'   => $dataVoice['statusSemana'] ?? $dataVoice['statusDia'],
                'totalMesAtualVoice'     => $dataVoice['totalMesAtual'],
                'totalMesAnteriorVoice'  => $dataVoice['totalMesAnterior'],
                'totalDiaAtualVoice'     => $dataVoice['totalDiaAtual'],
                'totalDiaAnteriorVoice'  => $dataVoice['totalDiaAnterior'],
                'totalSemanaAtualVoice'  => $dataVoice['totalSemanaAtual'] ?? $dataVoice['totalDiaAtual'],
                'totalSemanaAnteriorVoice'=> $dataVoice['totalSemanaAnterior'] ?? $dataVoice['totalDiaAnterior'],

                'statusMapMesWhats'       => $whatsappCharts['statusMapMesWhats'],
                'statusMapDiaWhats'       => $whatsappCharts['statusMapDiaWhats'],
                'statusMapSemanaWhats'    => $whatsappCharts['statusMapSemanaWhats'],
                'totalMesAtualWhats'      => $whatsappCharts['totalMesAtualWhats'],
                'totalMesAnteriorWhats'   => $whatsappCharts['totalMesAnteriorWhats'],
                'totalDiaAtualWhats'      => $whatsappCharts['totalDiaAtualWhats'],
                'totalDiaAnteriorWhats'   => $whatsappCharts['totalDiaAnteriorWhats'],
                'totalSemanaAtualWhats'   => $whatsappCharts['totalSemanaAtualWhats'],
                'totalSemanaAnteriorWhats'=> $whatsappCharts['totalSemanaAnteriorWhats'],

                'sipCodeCountsDay'        => $sipCodesDay,
                'sipCodeCountsWeek'       => $sipCodesWeek,
                'sipCodeCountsMonth'      => $sipCodesMonth,
                'trunkCountsDay'          => $trunkCountsDay,
                'trunkCountsWeek'         => $trunkCountsWeek,
                'trunkCountsMonth'        => $trunkCountsMonth,

                'totalOperator'          => $dataOperatorMonth ?? [],
                'totalOperatorDay'       => $dataOperatorDay ?? [],
                'totalOperatorWeek'      => $dataOperatorWeek ?? [],
                'totalOperatorMonth'     => $dataOperatorMonth ?? [],
                'totalPixValueMonth'     => $dataValuesPix,
                'pixValues'              => $dataValuesPix,
                'refillValues'           => $dataValuesPixRefill
            ];

            echo "data: " . json_encode($response) . "\n\n";
            flush();

        } catch (\Exception $e) {
            error_log("Erro Dashboard: " . $e->getMessage());
            echo "event: error\ndata: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
            flush();
        }
        exit;
    }

    public static function setUpdatePlan($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'success' => false,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $input = json_decode(file_get_contents('php://input'), true);

        $tenancyId = $obUser['tenancy_id'] ?? null;
        $planId    = $input['plan_id'] ?? null;

        if (!$tenancyId || !$planId) {
            return new Response(400, [
                'success' => false,
                'message' => 'Dados inválidos'
            ], 'application/json');
        }

        // 1) Atualiza plano ativo no painel
        RegisterTenancies::updateActivePlan($tenancyId, $planId);

        try {
            $asterisk = new AsteriskExtensionsSip();

            // 2) ADMIN (saldo e tarifa do painel)
            $adminId = (int)($obUser['id']);

            $panelAdmin = BalanceSms::getBalanceSms($adminId, $tenancyId, $planId);
            if (!$panelAdmin) {
                return new Response(404, [
                    'success' => false,
                    'message' => 'Saldo do admin não encontrado.'
                ], 'application/json');
            }

            $adminBalance    = (float)($panelAdmin->balance ?? 0);
            $adminTariff     = (float)($panelAdmin->value_voice ?? 0);
            $adminServiceFee = (float)($panelAdmin->service_fee ?? 0); // ✅ NOVO

            // 3) Resellers (saldo painel + tarifa via Rates)
            $resellers = UserSearch::getResellers($tenancyId) ?? [];
            $resellerPayload = [];

            foreach ($resellers as $r) {
                $rid = (int)($r['id'] ?? 0);
                if ($rid <= 0) continue;

                // ⚠️ Se seu método retorna array com voice + service_fee, melhor pegar tudo:
                $ratesAll = Rates::getActiveRatesByUser($tenancyId, $rid);

                $tariff      = (float)($ratesAll['voice'] ?? 0);
                $serviceFee  = (float)($ratesAll['service_fee'] ?? 0);

                $resellerPayload[] = [
                    'user_id'          => $rid,
                    'balance_admin'    => $adminBalance,
                    'balance_reseller' => (float)($r['reseller_balance'] ?? 0),
                    'call_minute_cost' => $tariff,
                    'service_fee'      => $serviceFee, // ✅ NOVO
                ];
            }

            // ✅ Query padrão (igual trunk)
            $query = [
                'user_id'   => $adminId,
                'tenant_id' => $tenancyId,
            ];

            // ✅ Payload único “reajusta tudo”
            $payload = [
                'tenant_id' => $tenancyId,

                // ADMIN
                'admin' => [
                    'user_id'          => $adminId,
                    'balance_admin'    => $adminBalance,
                    'call_minute_cost' => $adminTariff,
                    'service_fee'      => $adminServiceFee, // ✅ NOVO
                ],

                // RESELLERS
                'resellers' => $resellerPayload,
            ];

            $resp = $asterisk->updateTariff($query, $payload);

            if (!empty($resp['ok'])) {
                return new Response(200, [
                    'success' => true,
                    'message' => 'Plano alterado e Asterisk sincronizado com sucesso!',
                    'data'    => $resp['data'] ?? []
                ], 'application/json');
            }

            return new Response(500, [
                'success' => false,
                'message' => $resp['error'] ?? 'Falha ao sincronizar Asterisk.'
            ], 'application/json');

        } catch (\Throwable $e) {
            return new Response(500, [
                'success' => false,
                'message' => $e->getMessage()
            ], 'application/json');
        }
    }

}
