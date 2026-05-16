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
use App\Service\PlanRuntimeService;
use App\Service\DashboardService;
use App\Service\AuthContext;
use GuzzleHttp\Client;
use WilliamCosta\DatabaseManager\Database;

class Dashboard extends ViewComponents
{
    public static function getDashboard($request): string
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        $content = View::render('/dashboard/index', [
            'dashboardBuild' => self::dashboardBuildVersion(),
        ]);
        return parent::getComponentsDashboard('Maxx Solutions - SMS | Dashboard', $content);
    }

    private static function dashboardBuildVersion(): string
    {
        $files = [
            __FILE__,
            __DIR__ . '/../../Model/Entity/CallbackSms.php',
            __DIR__ . '/../../../resources/view/dashboard/index.html',
        ];

        $timestamps = array_map(static fn(string $file): int => is_file($file) ? (int)filemtime($file) : 0, $files);
        $latest = max($timestamps ?: [time()]);

        return 'build ' . gmdate('Ymd-His', $latest);
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
        $whatsappDayCost = self::costMapWhatsAppForPeriod($obUser, 'day');
        $whatsappTotalCost = (float)($whatsappDayCost['total'] ?? 0);
        $smsTotal = max(0, (int)($data['smsEnviados'] ?? 0));
        $smsDelivered = max(0, (int)($data['smsEntregues'] ?? 0));
        $smsResponses = max(0, (int)($data['smsRespostas'] ?? 0));
        $smsDeliveryRate = $smsTotal > 0 ? round(($smsDelivered / $smsTotal) * 100, 1) : 0;

        $data['whatsappEnviados'] = $whatsappSummary['sent_messages'];
        $data['whatsappConversas'] = $whatsappSummary['conversations'];
        $data['whatsappNaoLidas'] = $whatsappSummary['unread'];
        $data['whatsappLidas'] = $whatsappSummary['read'];
        $data['whatsappVoz'] = $whatsappSummary['voice_calls'];
        $data['whatsappVozEntrada'] = $whatsappSummary['voice_inbound'];
        $data['whatsappVozSaida'] = $whatsappSummary['voice_outbound'];
        $data['whatsappCampanhas'] = $whatsappSummary['campaigns'];
        $data['whatsappContas'] = $whatsappSummary['accounts'];
        $data['whatsappConsumoCategorias'] = $whatsappDayCost['categories'] ?? [];
        $data['smsTaxaEntrega'] = $smsDeliveryRate;
        $data['consumoWhats'] = number_format($whatsappTotalCost, 2, ',', '.');

        $currentTotalConsumption = self::normalizeDashboardMoney($data['totalConsumo'] ?? 0);
        $data['totalConsumo'] = 'R$ ' . number_format($currentTotalConsumption + $whatsappTotalCost, 4, ',', '.');

        $totalVoice = max(0, (int)($cdr->total ?? 0));
        $answeredVoice = max(0, (int)($cdr->answer ?? 0));
        $asr = $totalVoice > 0 ? round(($answeredVoice / $totalVoice) * 100, 1) : 0;
        $acd = $answeredVoice > 0 ? intdiv((int)($cdr->duration_total ?? 0), $answeredVoice) : 0;

        $whatsTotal = max(0, (int)($data['whatsappConversas'] ?? 0));
        $whatsUnread = max(0, (int)($data['whatsappNaoLidas'] ?? 0));
        $whatsRead = max(0, (int)($data['whatsappLidas'] ?? 0));
        $whatsVoice = max(0, (int)($data['whatsappVoz'] ?? 0));
        $whatsVoiceInbound = max(0, (int)($data['whatsappVozEntrada'] ?? 0));
        $whatsVoiceOutbound = max(0, (int)($data['whatsappVozSaida'] ?? 0));

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
                'title' => 'WhatsApp',
                'metric_label' => 'Lidas / Voz',
                'primary_value' => $whatsRead,
                'secondary_text' => $whatsVoice . ' voz | E ' . $whatsVoiceInbound . ' / S ' . $whatsVoiceOutbound,
                'progress' => min(100, $whatsTotal > 0 ? ($whatsRead / max(1, $whatsTotal)) * 100 : ($whatsVoice > 0 ? 100 : 0)),
                'status' => $whatsappSummary['accounts'] > 0 ? (($whatsRead > 0 || $whatsVoice > 0) ? 'ok' : ($whatsUnread > 0 ? 'warning' : 'warning')) : 'critical'
            ]
        ];

        return $data;
    }

    private static function normalizeDashboardMoney(mixed $value): float
    {
        if (is_numeric($value)) {
            return round((float)$value, 4);
        }

        $normalized = preg_replace('/[^\d,.\-]/', '', (string)$value) ?: '0';
        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return round((float)$normalized, 4);
    }

    private static function countSmsResponses(?string $tenancyId, ?int $userId = null): int
    {
        try {
            return CallbackSms::countInboundMoDistinct($tenancyId, $userId, null);
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function buildWhatsAppScopeWhere(array $obUser, string $alias = 'wc'): array
    {
        $role = self::dashboardRole($obUser);
        $params = [];

        if ($role === 'super_admin') {
            return ['1=1', $params];
        }

        $where = "{$alias}.tenancy_id = :wa_tenancy_id";
        $params[':wa_tenancy_id'] = (string)($obUser['tenancy_id'] ?? '');

        if ($role === 'reseller') {
            $where .= " AND (
                {$alias}.user_id = :wa_reseller_id
                OR {$alias}.user_id IN (
                    SELECT u.id
                    FROM users u
                    WHERE u.user_id = :wa_reseller_id
                      AND u.tenancy_id = :wa_reseller_tenancy_id
                )
            )";
            $params[':wa_reseller_id'] = (int)($obUser['id'] ?? 0);
            $params[':wa_reseller_tenancy_id'] = (string)($obUser['tenancy_id'] ?? '');
        } elseif (!self::isDashboardTenantWideRole($role)) {
            $where .= " AND {$alias}.user_id = :wa_user_id";
            $params[':wa_user_id'] = (int)($obUser['id'] ?? 0);
        }

        return [$where, $params];
    }

    private static function buildWhatsAppCdrScopeWhere(array $obUser, string $alias = 'c'): array
    {
        $role = self::dashboardRole($obUser);
        $params = [];

        if ($role === 'super_admin') {
            return ['1=1', $params];
        }

        $where = "{$alias}.tenancy_id = :wa_cdr_tenancy_id";
        $params[':wa_cdr_tenancy_id'] = (string)($obUser['tenancy_id'] ?? '');

        if ($role === 'reseller') {
            $where .= " AND (
                {$alias}.client_id = :wa_cdr_reseller_id
                OR {$alias}.client_id IN (
                    SELECT u.id
                    FROM users u
                    WHERE u.user_id = :wa_cdr_reseller_id
                      AND u.tenancy_id = :wa_cdr_reseller_tenancy_id
                )
            )";
            $params[':wa_cdr_reseller_id'] = (int)($obUser['id'] ?? 0);
            $params[':wa_cdr_reseller_tenancy_id'] = (string)($obUser['tenancy_id'] ?? '');
        } elseif (!self::isDashboardTenantWideRole($role)) {
            $where .= " AND {$alias}.client_id = :wa_cdr_user_id";
            $params[':wa_cdr_user_id'] = (int)($obUser['id'] ?? 0);
        }

        return [$where, $params];
    }

    private static function dashboardRole(array $user): string
    {
        return strtolower(trim((string)($user['user_function'] ?? $user['function'] ?? '')));
    }

    private static function isDashboardTenantWideRole(string $role): bool
    {
        return in_array($role, [
            'admin',
            'manager',
            'supervisor',
            'rh',
            'financial',
            'reception',
            'monitor',
            'support_l2',
            'support_ticket_manager',
        ], true);
    }

    private static function canSwapPlan(array $user): bool
    {
        return in_array(self::dashboardRole($user), ['admin', 'super_admin'], true);
    }

    private static function whatsCategoryLabel(string $category): string
    {
        return match (strtolower(trim($category))) {
            'utility' => 'Utilitario',
            'authentication' => 'Autenticacao',
            'service' => 'Atendimento',
            default => 'Marketing',
        };
    }

    private static function dashboardScalar(string $sql, array $params = []): int
    {
        $row = (new Database())->execute($sql, $params)->fetch(\PDO::FETCH_ASSOC);
        return (int)($row['total'] ?? 0);
    }

    private static function formatWhatsAppAccountLabel(array $row, bool $withPhone = true): string
    {
        $label = trim((string)($row['label'] ?? ''));
        $phone = trim((string)($row['display_phone_number'] ?? ''));

        if ($label === '') {
            $label = $phone !== '' ? $phone : 'Conta WhatsApp';
        }

        if ($withPhone && $phone !== '' && $phone !== $label) {
            return $label . ' (' . $phone . ')';
        }

        return $label;
    }

    private static function whatsappTablesReady(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        $tables = ['whatsapp_accounts', 'whatsapp_campaigns', 'whatsapp_conversations', 'whatsapp_messages', 'whatsapp_message_cdr'];
        $ready = self::databaseTablesReady($tables);
        return $ready;
    }

    private static function whatsappCallCdrTableReady(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        $ready = self::databaseTablesReady(['whatsapp_call_cdr']);
        return $ready;
    }

    private static function databaseTablesReady(array $tables): bool
    {
        $placeholders = [];
        $params = [];

        foreach ($tables as $index => $table) {
            $key = ':table_' . $index;
            $placeholders[] = $key;
            $params[$key] = $table;
        }

        $rows = (new Database())->execute(
            'SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN (' . implode(', ', $placeholders) . ')',
            $params
        )->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        return count(array_unique(array_map('strval', $rows))) === count($tables);
    }

    private static function buildWhatsAppCallScopeWhere(array $obUser): array
    {
        $role = self::dashboardRole($obUser);
        $where = ['1=1'];
        $params = [];

        if ($role !== 'super_admin') {
            $where[] = 'wc.tenancy_id = :wa_call_tenancy_id';
            $params[':wa_call_tenancy_id'] = (string)($obUser['tenancy_id'] ?? '');
        }

        if ($role === 'reseller') {
            $where[] = "(
                wa.user_id = :wa_call_reseller_id
                OR wa.user_id IN (
                    SELECT u.id
                    FROM users u
                    WHERE u.user_id = :wa_call_reseller_id
                      AND u.tenancy_id = :wa_call_reseller_tenancy_id
                )
            )";
            $params[':wa_call_reseller_id'] = (int)($obUser['id'] ?? 0);
            $params[':wa_call_reseller_tenancy_id'] = (string)($obUser['tenancy_id'] ?? '');
        } elseif (!self::isDashboardTenantWideRole($role) && $role !== 'super_admin') {
            $where[] = 'wa.user_id = :wa_call_user_id';
            $params[':wa_call_user_id'] = (int)($obUser['id'] ?? 0);
        }

        return [implode(' AND ', $where), $params];
    }

    private static function buildDashboardWhatsAppSummary(array $obUser): array
    {
        $summary = [
            'sent_messages' => 0,
            'conversations' => 0,
            'unread' => 0,
            'read' => 0,
            'voice_calls' => 0,
            'voice_inbound' => 0,
            'voice_outbound' => 0,
            'campaigns' => 0,
            'accounts' => 0,
        ];

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

            $read = self::dashboardScalar(
                "SELECT COUNT(*) AS total
                 FROM whatsapp_messages wm
                 INNER JOIN whatsapp_conversations wc ON wc.id = wm.conversation_id
                 WHERE {$conversationWhere}
                   AND wm.direction = 'outbound'
                   AND wm.status = 'read'",
                $conversationParams
            );

            $summary['sent_messages'] = $sentMessages;
            $summary['conversations'] = $conversations;
            $summary['unread'] = $unread;
            $summary['read'] = $read;
            $summary['campaigns'] = $campaigns;
            $summary['accounts'] = $accounts;
        } catch (\Throwable) {
        }

        try {
            if (self::whatsappCallCdrTableReady()) {
                [$callWhere, $callParams] = self::buildWhatsAppCallScopeWhere($obUser);

                $voiceCalls = self::dashboardScalar(
                    "SELECT COUNT(*) AS total
                     FROM whatsapp_call_cdr wc
                     LEFT JOIN whatsapp_accounts wa ON wa.id = wc.account_id
                     WHERE {$callWhere}",
                    $callParams
                );

                $voiceInbound = self::dashboardScalar(
                    "SELECT COUNT(*) AS total
                     FROM whatsapp_call_cdr wc
                     LEFT JOIN whatsapp_accounts wa ON wa.id = wc.account_id
                     WHERE {$callWhere}
                       AND LOWER(COALESCE(wc.direction, '')) = 'inbound'",
                    $callParams
                );

                $voiceOutbound = self::dashboardScalar(
                    "SELECT COUNT(*) AS total
                     FROM whatsapp_call_cdr wc
                     LEFT JOIN whatsapp_accounts wa ON wa.id = wc.account_id
                     WHERE {$callWhere}
                       AND LOWER(COALESCE(wc.direction, '')) = 'outbound'",
                    $callParams
                );

                $summary['voice_calls'] = $voiceCalls;
                $summary['voice_inbound'] = $voiceInbound;
                $summary['voice_outbound'] = $voiceOutbound;
            }
        } catch (\Throwable) {
        }

        return $summary;
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

    private static function whatsCallPeriodCondition(string $period, string $mode, string $field = 'COALESCE(wc.started_at, wc.answered_at, wc.created_at)'): string
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

    private static function countWhatsAppCallsForPeriod(array $obUser, string $period, string $mode): int
    {
        if (!self::whatsappCallCdrTableReady()) {
            return 0;
        }

        [$where, $params] = self::buildWhatsAppCallScopeWhere($obUser);
        $periodWhere = self::whatsCallPeriodCondition($period, $mode);

        return self::dashboardScalar(
            "SELECT COUNT(*) AS total
             FROM whatsapp_call_cdr wc
             LEFT JOIN whatsapp_accounts wa ON wa.id = wc.account_id
             WHERE {$where} AND {$periodWhere}",
            $params
        );
    }

    private static function statusMapWhatsAppVoiceForPeriod(array $obUser, string $period, string $mode = 'current'): array
    {
        if (!self::whatsappCallCdrTableReady()) {
            return [];
        }

        [$where, $params] = self::buildWhatsAppCallScopeWhere($obUser);
        $periodWhere = self::whatsCallPeriodCondition($period, $mode);

        try {
            $rows = (new Database())->execute(
                "SELECT UPPER(COALESCE(wc.direction, '')) AS direction, COUNT(*) AS total
                 FROM whatsapp_call_cdr wc
                 LEFT JOIN whatsapp_accounts wa ON wa.id = wc.account_id
                 WHERE {$where} AND {$periodWhere}
                 GROUP BY direction",
                $params
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }

        $map = [
            'Entrada' => 0,
            'Saida' => 0,
        ];

        foreach ($rows as $row) {
            $direction = strtoupper(trim((string)($row['direction'] ?? '')));
            $total = (int)($row['total'] ?? 0);

            if ($direction === 'INBOUND') {
                $map['Entrada'] += $total;
            } elseif ($direction === 'OUTBOUND') {
                $map['Saida'] += $total;
            }
        }

        return $map;
    }

    private static function costMapWhatsAppForPeriod(array $obUser, string $period, string $mode = 'current'): array
    {
        [$where, $params] = self::buildWhatsAppCdrScopeWhere($obUser, 'c');
        $periodWhere = self::whatsPeriodCondition($period, $mode, 'COALESCE(c.delivered_at, c.timestamp, c.created_at)');

        try {
            $rows = (new Database())->execute(
                "SELECT c.message_category,
                        COUNT(*) AS quantity,
                        COALESCE(SUM(c.price_brl), 0) AS total_cost
                 FROM whatsapp_message_cdr c
                 WHERE {$where}
                   AND {$periodWhere}
                   AND c.direction = 'outbound'
                   AND c.billed = 1
                 GROUP BY c.message_category
                 ORDER BY total_cost DESC",
                $params
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [
                'map' => [],
                'message_total' => 0,
                'voice_total' => 0,
                'total' => 0,
            ];
        }

        $map = [];
        $messageTotal = 0.0;
        $categories = [];
        foreach ($rows as $row) {
            $category = strtolower((string)($row['message_category'] ?? 'marketing'));
            $label = self::whatsCategoryLabel($category);
            $cost = (float)($row['total_cost'] ?? 0);
            $quantity = (int)($row['quantity'] ?? 0);

            $map[$label] = (float)($map[$label] ?? 0) + $cost;
            $categories[$category] = [
                'label' => $label,
                'quantity' => $quantity,
                'cost' => round($cost, 4),
            ];
            $messageTotal += $cost;
        }

        $voiceTotal = 0.0;
        if (self::whatsappCallCdrTableReady()) {
            [$callWhere, $callParams] = self::buildWhatsAppCallScopeWhere($obUser);
            $callPeriodWhere = self::whatsCallPeriodCondition($period, $mode);

            try {
                $voiceRow = (new Database())->execute(
                    "SELECT
                        COUNT(*) AS quantity,
                        COALESCE(SUM(wc.final_price), 0) AS total_cost
                     FROM whatsapp_call_cdr wc
                     LEFT JOIN whatsapp_accounts wa ON wa.id = wc.account_id
                     WHERE {$callWhere}
                       AND {$callPeriodWhere}
                       AND COALESCE(wc.final_price, 0) > 0",
                    $callParams
                )->fetch(\PDO::FETCH_ASSOC) ?: [];

                $voiceQuantity = (int)($voiceRow['quantity'] ?? 0);
                $voiceTotal = (float)($voiceRow['total_cost'] ?? 0);

                if ($voiceQuantity > 0 || $voiceTotal > 0) {
                    $map['Voz'] = (float)($map['Voz'] ?? 0) + $voiceTotal;
                    $categories['voice'] = [
                        'label' => 'Voz',
                        'quantity' => $voiceQuantity,
                        'cost' => round($voiceTotal, 4),
                    ];
                }
            } catch (\Throwable) {
                $voiceTotal = 0.0;
            }
        }

        return [
            'map' => $map,
            'categories' => $categories,
            'message_total' => round($messageTotal, 4),
            'voice_total' => round($voiceTotal, 4),
            'total' => round($messageTotal + $voiceTotal, 4),
        ];
    }

    private static function companyCostMapWhatsAppForPeriod(array $obUser, string $period): array
    {
        [$where, $params] = self::buildWhatsAppCdrScopeWhere($obUser, 'c');
        $periodWhere = self::whatsPeriodCondition($period, 'current', 'COALESCE(c.delivered_at, c.timestamp, c.created_at)');

        try {
            $rows = (new Database())->execute(
                "SELECT
                    COALESCE(NULLIF(u.name, ''), CONCAT('Cliente ', c.client_id)) AS company_name,
                    COUNT(*) AS quantity,
                    COALESCE(SUM(c.price_brl), 0) AS cost
                 FROM whatsapp_message_cdr c
                 LEFT JOIN users u ON u.id = c.client_id
                 WHERE {$where}
                   AND {$periodWhere}
                   AND c.direction = 'outbound'
                   AND c.billed = 1
                 GROUP BY c.client_id, company_name
                 ORDER BY quantity DESC, cost DESC",
                $params
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $label = trim((string)($row['company_name'] ?? 'Empresa'));
            $label = preg_split('/\s+/', $label)[0] ?? $label;

            if (!isset($map[$label])) {
                $map[$label] = [
                    'quantity' => 0,
                    'cost' => 0.0,
                ];
            }

            $map[$label]['quantity'] += (int)($row['quantity'] ?? 0);
            $map[$label]['cost'] += (float)($row['cost'] ?? 0);
            $map[$label]['cost'] = round($map[$label]['cost'], 4);
        }

        if (self::whatsappCallCdrTableReady()) {
            [$callWhere, $callParams] = self::buildWhatsAppCallScopeWhere($obUser);
            $callPeriodWhere = self::whatsCallPeriodCondition($period, 'current');

            try {
                $voiceRows = (new Database())->execute(
                    "SELECT
                        COALESCE(NULLIF(u.name, ''), CONCAT('Cliente ', wc.customer_id)) AS company_name,
                        COUNT(*) AS quantity,
                        COALESCE(SUM(wc.final_price), 0) AS cost
                     FROM whatsapp_call_cdr wc
                     LEFT JOIN whatsapp_accounts wa ON wa.id = wc.account_id
                     LEFT JOIN users u ON u.id = wc.customer_id
                     WHERE {$callWhere}
                       AND {$callPeriodWhere}
                       AND COALESCE(wc.final_price, 0) > 0
                     GROUP BY wc.customer_id, company_name
                     ORDER BY quantity DESC, cost DESC",
                    $callParams
                )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

                foreach ($voiceRows as $row) {
                    $label = trim((string)($row['company_name'] ?? 'Empresa'));
                    $label = preg_split('/\s+/', $label)[0] ?? $label;

                    if (!isset($map[$label])) {
                        $map[$label] = [
                            'quantity' => 0,
                            'cost' => 0.0,
                        ];
                    }

                    $map[$label]['quantity'] += (int)($row['quantity'] ?? 0);
                    $map[$label]['cost'] += (float)($row['cost'] ?? 0);
                    $map[$label]['cost'] = round($map[$label]['cost'], 4);
                }
            } catch (\Throwable) {
            }
        }

        return $map;
    }

    private static function buildDashboardWhatsAppCharts(array $obUser): array
    {
        $cacheKey = 'dashboard:whatsapp_charts:' . md5(json_encode([
            'role' => strtolower((string)($obUser['function'] ?? '')),
            'tenancy_id' => (string)($obUser['tenancy_id'] ?? ''),
            'user_id' => (int)($obUser['id'] ?? 0),
        ]));

        return DashboardService::remember($cacheKey, 20, static function () use ($obUser): array {
            try {
            if (!self::whatsappTablesReady()) {
                throw new \RuntimeException('WhatsApp tables not ready');
            }

            $costDiaAtual = self::costMapWhatsAppForPeriod($obUser, 'day');
            $costDiaAnterior = self::costMapWhatsAppForPeriod($obUser, 'day', 'previous');
            $costSemanaAtual = self::costMapWhatsAppForPeriod($obUser, 'week');
            $costSemanaAnterior = self::costMapWhatsAppForPeriod($obUser, 'week', 'previous');
            $costMesAtual = self::costMapWhatsAppForPeriod($obUser, 'month');
            $costMesAnterior = self::costMapWhatsAppForPeriod($obUser, 'month', 'previous');
            $voiceDayAtual = self::statusMapWhatsAppVoiceForPeriod($obUser, 'day');
            $voiceDayAnterior = self::statusMapWhatsAppVoiceForPeriod($obUser, 'day', 'previous');
            $voiceSemanaAtual = self::statusMapWhatsAppVoiceForPeriod($obUser, 'week');
            $voiceSemanaAnterior = self::statusMapWhatsAppVoiceForPeriod($obUser, 'week', 'previous');
            $voiceMesAtual = self::statusMapWhatsAppVoiceForPeriod($obUser, 'month');
            $voiceMesAnterior = self::statusMapWhatsAppVoiceForPeriod($obUser, 'month', 'previous');

            return [
                'statusMapDiaWhats' => self::statusMapWhatsAppForPeriod($obUser, 'day'),
                'statusMapSemanaWhats' => self::statusMapWhatsAppForPeriod($obUser, 'week'),
                'statusMapMesWhats' => self::statusMapWhatsAppForPeriod($obUser, 'month'),
                'statusMapDiaWhatsVoice' => $voiceDayAtual,
                'statusMapSemanaWhatsVoice' => $voiceSemanaAtual,
                'statusMapMesWhatsVoice' => $voiceMesAtual,
                'statusMapDiaAnteriorWhatsVoice' => $voiceDayAnterior,
                'statusMapSemanaAnteriorWhatsVoice' => $voiceSemanaAnterior,
                'statusMapMesAnteriorWhatsVoice' => $voiceMesAnterior,
                'costMapDiaWhats' => $costDiaAtual['map'],
                'costMapSemanaWhats' => $costSemanaAtual['map'],
                'costMapMesWhats' => $costMesAtual['map'],
                'categoryCostDiaWhats' => $costDiaAtual['categories'],
                'categoryCostSemanaWhats' => $costSemanaAtual['categories'],
                'categoryCostMesWhats' => $costMesAtual['categories'],
                'companyCostMapDiaWhats' => self::companyCostMapWhatsAppForPeriod($obUser, 'day'),
                'companyCostMapSemanaWhats' => self::companyCostMapWhatsAppForPeriod($obUser, 'week'),
                'companyCostMapMesWhats' => self::companyCostMapWhatsAppForPeriod($obUser, 'month'),
                'totalDiaAtualWhats' => self::countWhatsAppMessagesForPeriod($obUser, 'day', 'current'),
                'totalDiaAnteriorWhats' => self::countWhatsAppMessagesForPeriod($obUser, 'day', 'previous'),
                'totalSemanaAtualWhats' => self::countWhatsAppMessagesForPeriod($obUser, 'week', 'current'),
                'totalSemanaAnteriorWhats' => self::countWhatsAppMessagesForPeriod($obUser, 'week', 'previous'),
                'totalMesAtualWhats' => self::countWhatsAppMessagesForPeriod($obUser, 'month', 'current'),
                'totalMesAnteriorWhats' => self::countWhatsAppMessagesForPeriod($obUser, 'month', 'previous'),
                'totalDiaAtualWhatsVoice' => self::countWhatsAppCallsForPeriod($obUser, 'day', 'current'),
                'totalDiaAnteriorWhatsVoice' => self::countWhatsAppCallsForPeriod($obUser, 'day', 'previous'),
                'totalSemanaAtualWhatsVoice' => self::countWhatsAppCallsForPeriod($obUser, 'week', 'current'),
                'totalSemanaAnteriorWhatsVoice' => self::countWhatsAppCallsForPeriod($obUser, 'week', 'previous'),
                'totalMesAtualWhatsVoice' => self::countWhatsAppCallsForPeriod($obUser, 'month', 'current'),
                'totalMesAnteriorWhatsVoice' => self::countWhatsAppCallsForPeriod($obUser, 'month', 'previous'),
                'totalCustoDiaAtualWhats' => $costDiaAtual['total'],
                'totalCustoDiaAnteriorWhats' => $costDiaAnterior['total'],
                'totalCustoSemanaAtualWhats' => $costSemanaAtual['total'],
                'totalCustoSemanaAnteriorWhats' => $costSemanaAnterior['total'],
                'totalCustoMesAtualWhats' => $costMesAtual['total'],
                'totalCustoMesAnteriorWhats' => $costMesAnterior['total'],
            ];
        } catch (\Throwable) {
            return [
                'statusMapDiaWhats' => [],
                'statusMapSemanaWhats' => [],
                'statusMapMesWhats' => [],
                'statusMapDiaWhatsVoice' => [],
                'statusMapSemanaWhatsVoice' => [],
                'statusMapMesWhatsVoice' => [],
                'statusMapDiaAnteriorWhatsVoice' => [],
                'statusMapSemanaAnteriorWhatsVoice' => [],
                'statusMapMesAnteriorWhatsVoice' => [],
                'costMapDiaWhats' => [],
                'costMapSemanaWhats' => [],
                'costMapMesWhats' => [],
                'categoryCostDiaWhats' => [],
                'categoryCostSemanaWhats' => [],
                'categoryCostMesWhats' => [],
                'companyCostMapDiaWhats' => [],
                'companyCostMapSemanaWhats' => [],
                'companyCostMapMesWhats' => [],
                'totalDiaAtualWhats' => 0,
                'totalDiaAnteriorWhats' => 0,
                'totalSemanaAtualWhats' => 0,
                'totalSemanaAnteriorWhats' => 0,
                'totalMesAtualWhats' => 0,
                'totalMesAnteriorWhats' => 0,
                'totalDiaAtualWhatsVoice' => 0,
                'totalDiaAnteriorWhatsVoice' => 0,
                'totalSemanaAtualWhatsVoice' => 0,
                'totalSemanaAnteriorWhatsVoice' => 0,
                'totalMesAtualWhatsVoice' => 0,
                'totalMesAnteriorWhatsVoice' => 0,
                'totalCustoDiaAtualWhats' => 0,
                'totalCustoDiaAnteriorWhats' => 0,
                'totalCustoSemanaAtualWhats' => 0,
                'totalCustoSemanaAnteriorWhats' => 0,
                'totalCustoMesAtualWhats' => 0,
                'totalCustoMesAnteriorWhats' => 0,
            ];
        }
        });
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
        static $columns = null;
        if (is_array($columns)) {
            return isset($columns[$column]);
        }

        try {
            $rows = (new Database())->execute('SHOW COLUMNS FROM cdr')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $columns = [];
            foreach ($rows as $row) {
                $field = trim((string)($row['Field'] ?? ''));
                if ($field !== '') {
                    $columns[$field] = true;
                }
            }
        } catch (\Throwable) {
            $columns = [];
        }

        return isset($columns[$column]);
    }

    private static function cdrPeriodDateExpression(string $alias = 'cdr'): string
    {
        $parts = [];

        if (self::hasCdrColumn('started')) {
            $parts[] = "NULLIF({$alias}.started, '0000-00-00 00:00:00')";
        }

        if (self::hasCdrColumn('cdr_timestamp')) {
            $parts[] = "NULLIF({$alias}.cdr_timestamp, '0000-00-00 00:00:00')";
        }

        if (self::hasCdrColumn('created_at')) {
            $parts[] = "{$alias}.created_at";
        }

        if (!$parts) {
            return 'NOW()';
        }

        return count($parts) === 1 ? $parts[0] : 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private static function countSipCodes(array $filters, string $period = 'month', string $mode = 'current'): array
    {
        $where = self::dashboardSecurityFilter($filters, 'cdr');
        $periodDate = self::cdrPeriodDateExpression('cdr');
        $dateCondition = match ($period . ':' . $mode) {
            'day:previous' => "DATE({$periodDate}) = CURDATE() - INTERVAL 1 DAY",
            'week:previous' => "YEARWEEK({$periodDate}, 1) = YEARWEEK(CURDATE() - INTERVAL 1 WEEK, 1)",
            'month:previous' => "MONTH({$periodDate}) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR({$periodDate}) = YEAR(CURDATE() - INTERVAL 1 MONTH)",
            'day:current' => "DATE({$periodDate}) = CURDATE()",
            'week:current' => "YEARWEEK({$periodDate}, 1) = YEARWEEK(CURDATE(), 1)",
            default => "MONTH({$periodDate}) = MONTH(CURDATE()) AND YEAR({$periodDate}) = YEAR(CURDATE())",
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

    private static function countGroupedByTrunk(array $filters, string $period = 'month', string $mode = 'current'): array
    {
        $where = self::dashboardSecurityFilter($filters, 'cdr');
        $periodDate = self::cdrPeriodDateExpression('cdr');
        $dateCondition = match ($period . ':' . $mode) {
            'day:previous' => "DATE({$periodDate}) = CURDATE() - INTERVAL 1 DAY",
            'week:previous' => "YEARWEEK({$periodDate}, 1) = YEARWEEK(CURDATE() - INTERVAL 1 WEEK, 1)",
            'month:previous' => "MONTH({$periodDate}) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR({$periodDate}) = YEAR(CURDATE() - INTERVAL 1 MONTH)",
            'day:current' => "DATE({$periodDate}) = CURDATE()",
            'week:current' => "YEARWEEK({$periodDate}, 1) = YEARWEEK(CURDATE(), 1)",
            default => "MONTH({$periodDate}) = MONTH(CURDATE()) AND YEAR({$periodDate}) = YEAR(CURDATE())",
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
        $startedAt = microtime(true);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        DashboardService::sseRetryLine(DashboardService::CARDS_RETRY_MS);

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
        $cacheKey = 'dashboard:cards:' . md5(json_encode([
            'role' => $role,
            'tenancy_id' => $obUser['tenancy_id'] ?? null,
            'user_id' => $obUser['id'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $data = DashboardService::remember($cacheKey, 10, function () use ($obUser, $role, $isReseller, $isAdmin, $isSuperAdmin, $calcBalanceVariation) {
            // ============================
            // SUPER ADMIN
            // ============================
            if ($isSuperAdmin) {
                $currentBalance = DashboardService::cachedSmsBalance(
                    (string)($obUser['tenancy_id'] ?? 'global'),
                    static fn() => DisproClient::getBalanceDISPRO()
                ) ?? 0;
                $dataPix = PixSearch::getPixLast($obUser['id'], $obUser['tenancy_id']);
                $currentPix = ($dataPix && isset($dataPix->value)) ? str_replace('.', ',', sprintf("%0.2f", (float)$dataPix->value)) : '00,00';
                $currentData = ($dataPix && !empty($dataPix->confirmed_date) && strtotime($dataPix->confirmed_date)) ? date('d/m/Y H:i', strtotime($dataPix->confirmed_date)) : '--/--/---- --:--';

                $dataSms = CallbackSms::countSentSms(null, null);
                $currentSms = $dataSms->qtd ?? 0;
                $smsResponses = self::countSmsResponses(null, null);
                $valueSms = (float)(BalanceSms::getBalanceSms(null, null)->value_sms ?? 0);
                $dataValue = $currentSms * $valueSms;

                $cdrFilters = ['user_function' => 'super_admin'];
                $cdr = CdrVoice::countCdrVoice($cdrFilters);
                $cdrDisposition = $cdr->answer ?? 0;
                $cdrValue = (float)($cdr->value_total ?? 0);
                $cdrTaxa = (float)($cdr->taxa_total ?? 0);
                $cdrTotal = $cdrValue + $cdrTaxa;

                $smsCampaignsRaw = CampaignSearch::countCampaignsByStatus(null, null);
                $voiceCampaignsRaw = CampaignVoice::countVoiceCampaignsByStatus(null, null);
                $voiceCampaignsRaw = CampaignVoiceSchedule::addPendingToVoiceCounts($voiceCampaignsRaw, null, null);
                $mergedCampaigns = self::mergeCampaignCounts($smsCampaignsRaw, $voiceCampaignsRaw);
                $totalCampaigns = array_sum($smsCampaignsRaw) + array_sum($voiceCampaignsRaw);
                $totalConsumo = $dataValue + $cdrTotal;

                $data = [
                    'saldoAtual' => number_format($currentBalance, 4, ',', '.'),
                    'saldoVariacao' => '+0%',
                    'smsEnviados' => $currentSms,
                    'smsTarifados' => $currentSms,
                    'smsEntregues' => (int)($dataSms->delivered ?? 0),
                    'smsPendentes' => (int)($dataSms->sent ?? 0),
                    'smsFalhas' => (int)(($dataSms->undeliverable ?? 0) + ($dataSms->expired ?? 0)),
                    'smsRespostas' => $smsResponses,
                    'smsCusto' => 'R$ ' . number_format($dataValue, 4, ',', '.'),
                    'ultimoPixValor' => $currentPix,
                    'ultimoPixData' => $currentData,
                    'campanhasHoje' => $totalCampaigns,
                    'campanhas' => $mergedCampaigns,
                    'totalConsumo' => 'R$ ' . number_format($totalConsumo, 4, ',', '.'),
                    'consumoSms' => number_format($dataValue, 2, ',', '.'),
                    'consumoVoz' => number_format($cdrTotal, 2, ',', '.'),
                    'consumoWhats' => '0,00',
                    'whatsappEnviados' => 0,
                    'disposition' => $cdrDisposition,
                    'cdrTaxa' => 'R$ ' . number_format($cdrTaxa, 4, ',', '.'),
                    'cdrValue' => 'R$ ' . number_format($cdrTotal, 4, ',', '.'),
                ];
                return self::enrichCardsPayload($data, $cdrFilters, $cdr, $obUser);
            }

            // ============================
            // RESELLER
            // ============================
            if ($isReseller) {
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
            return self::enrichCardsPayload($data, $cdrFilters, $cdr, $obUser);
            }

            // ============================
            // ADMIN / USER
            // ============================
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
            return self::enrichCardsPayload($data, $cdrFilters, $cdr, $obUser);
        });

        echo "data: " . json_encode($data) . "\n\n";
        DashboardService::log('/dashboard/cards', 'sse_emit', $startedAt, [
            'role' => $role,
            'tenancy_id' => $obUser['tenancy_id'] ?? null,
        ]);
        flush();
        exit;
    }


    public static function getDataChartsDashboard($request): Response
    {
        $startedAt = microtime(true);
        if (session_status() == PHP_SESSION_ACTIVE) session_write_close();

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        DashboardService::sseRetryLine(DashboardService::CHARTS_RETRY_MS);

        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            echo "event: error\n";
            echo "data: " . json_encode(['status' => 401, 'message' => 'Usuário não autenticado.']) . "\n\n";
            flush();
            exit;
        }

        try {
            $role = $obUser['function'] ?? null;
            $isReseller   = $role === 'reseller';
            $isAdmin      = $role === 'admin';
            $isSuperAdmin = $role === 'super_admin';

            $tenancyId = $obUser['tenancy_id'];
            $userId    = $obUser['id'];
            $cacheKey = 'dashboard:charts:' . md5(json_encode([
                'role' => $role,
                'tenancy_id' => $tenancyId,
                'user_id' => $userId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $response = DashboardService::remember($cacheKey, 20, function () use ($obUser, $role, $isReseller, $isAdmin, $isSuperAdmin, $tenancyId, $userId) {
                $data = [
                    'statusMes' => [],
                    'statusSemana' => [],
                    'statusDia' => [],
                    'totalMesAtual' => 0,
                    'totalMesAnterior' => 0,
                    'totalSemanaAtual' => 0,
                    'totalSemanaAnterior' => 0,
                    'totalDiaAtual' => 0,
                    'totalDiaAnterior' => 0,
                    'statusMesVoice' => [],
                    'statusSemanaVoice' => [],
                    'statusDiaVoice' => [],
                    'totalMesAtualVoice' => 0,
                    'totalMesAnteriorVoice' => 0,
                    'totalSemanaAtualVoice' => 0,
                    'totalSemanaAnteriorVoice' => 0,
                    'totalDiaAtualVoice' => 0,
                    'totalDiaAnteriorVoice' => 0,
                ];

                $dataValuesPix = [];
                $dataValuesPixRefill = [];
                $dataOperatorMonth = [];
                $dataOperatorMonthPrevious = [];
                $dataOperatorDay = [];
                $dataOperatorDayPrevious = [];
                $dataOperatorWeek = [];
                $dataOperatorWeekPrevious = [];

                if ($isSuperAdmin) {
                    $data = CallbackSms::fetchStatusCountsWithDay(null, null, null);
                    $dataOperatorMonth = CallbackSms::countGroupedByOperatorMoDistinct(null, null, null, 'month');
                    $dataOperatorMonthPrevious = CallbackSms::countGroupedByOperatorMoDistinct(null, null, null, 'month_previous');
                    $dataOperatorDay = CallbackSms::countGroupedByOperatorMoDistinct(null, null, null, 'day');
                    $dataOperatorDayPrevious = CallbackSms::countGroupedByOperatorMoDistinct(null, null, null, 'day_previous');
                    $dataOperatorWeek = CallbackSms::countGroupedByOperatorMoDistinct(null, null, null, 'week');
                    $dataOperatorWeekPrevious = CallbackSms::countGroupedByOperatorMoDistinct(null, null, null, 'week_previous');
                    $dataValuesPix = PixSearch::getValuesPixCurrentMonth(null, null);
                    $dataValuesPixRefill = RefillsResellers::getValuesRefillCurrentMonth(null, null);
                    $dataVoice = CdrVoice::fetchVoiceStatusCountsWithDay(null, null, null);

                    $dataValuesPix = array_map(fn($item) => ['data' => date('d/m', strtotime($item->confirmed_date)), 'value' => (float)$item->value], $dataValuesPix);
                    $dataValuesPixRefill = array_map(fn($item) => ['data' => date('d/m', strtotime($item->created_at)), 'value' => (float)$item->balance], $dataValuesPixRefill);
                } elseif ($isAdmin) {
                    $data = CallbackSms::fetchStatusCountsWithDay($tenancyId, null, null);
                    $dataOperatorMonth = CallbackSms::countGroupedByOperatorMoDistinct($tenancyId, null, null, 'month');
                    $dataOperatorMonthPrevious = CallbackSms::countGroupedByOperatorMoDistinct($tenancyId, null, null, 'month_previous');
                    $dataOperatorDay = CallbackSms::countGroupedByOperatorMoDistinct($tenancyId, null, null, 'day');
                    $dataOperatorDayPrevious = CallbackSms::countGroupedByOperatorMoDistinct($tenancyId, null, null, 'day_previous');
                    $dataOperatorWeek = CallbackSms::countGroupedByOperatorMoDistinct($tenancyId, null, null, 'week');
                    $dataOperatorWeekPrevious = CallbackSms::countGroupedByOperatorMoDistinct($tenancyId, null, null, 'week_previous');
                    $dataValuesPix = PixSearch::getValuesPixCurrentMonth($userId, $tenancyId);
                    $dataValuesPixRefill = RefillsResellers::getValuesRefillCurrentMonth(null, $tenancyId);
                    $dataVoice = CdrVoice::fetchVoiceStatusCountsWithDay($tenancyId, null, null);

                    $dataValuesPix = array_map(fn($item) => ['data' => date('d/m', strtotime($item->confirmed_date)), 'value' => (float)$item->value], $dataValuesPix);
                    $dataValuesPixRefill = array_map(fn($item) => ['data' => date('d/m', strtotime($item->created_at)), 'value' => (float)$item->balance], $dataValuesPixRefill);
                } else {
                    if ($isReseller) {
                        $data = CallbackSms::fetchStatusCountsWithDay($tenancyId, null, $userId);
                        $dataOperatorMonth = CallbackSms::countGroupedByOperatorMoDistinct($tenancyId, $userId, null, 'month');
                        $dataOperatorMonthPrevious = CallbackSms::countGroupedByOperatorMoDistinct($tenancyId, $userId, null, 'month_previous');
                        $dataOperatorDay = CallbackSms::countGroupedByOperatorMoDistinct($tenancyId, $userId, null, 'day');
                        $dataOperatorDayPrevious = CallbackSms::countGroupedByOperatorMoDistinct($tenancyId, $userId, null, 'day_previous');
                        $dataOperatorWeek = CallbackSms::countGroupedByOperatorMoDistinct($tenancyId, $userId, null, 'week');
                        $dataOperatorWeekPrevious = CallbackSms::countGroupedByOperatorMoDistinct($tenancyId, $userId, null, 'week_previous');
                        $dataValuesPix = RefillsResellers::getValuesRefillCurrentMonth($userId, $tenancyId);
                        $dataVoice = CdrVoice::fetchVoiceStatusCountsWithDay($tenancyId, $userId, $userId);
                    } else {
                        $data = CallbackSms::fetchStatusCountsWithDay($tenancyId, $userId, null);
                        $dataVoice = CdrVoice::fetchVoiceStatusCountsWithDay($tenancyId, $userId, null);
                    }

                    $dataValuesPix = array_map(fn($item) => ['data' => date('d/m', strtotime($item->created_at)), 'value' => (float)$item->balance], $dataValuesPix);
                }

                $cdrFilters = [
                    'tenancy_id' => $isSuperAdmin ? null : $tenancyId,
                    'user_id' => ($isAdmin || $isSuperAdmin) ? null : $userId,
                    'user_function' => $isSuperAdmin ? 'super_admin' : ($role ?: 'agent'),
                ];

                $sipCodesDay = self::countSipCodes($cdrFilters, 'day');
                $sipCodesWeek = self::countSipCodes($cdrFilters, 'week');
                $sipCodesMonth = self::countSipCodes($cdrFilters, 'month');
                $sipCodesDayPrevious = self::countSipCodes($cdrFilters, 'day', 'previous');
                $sipCodesWeekPrevious = self::countSipCodes($cdrFilters, 'week', 'previous');
                $sipCodesMonthPrevious = self::countSipCodes($cdrFilters, 'month', 'previous');
                $trunkNameMap = self::buildTrunkNameMap($obUser);
                $trunkCountsDay = self::labelTrunkCounts(self::countGroupedByTrunk($cdrFilters, 'day'), $trunkNameMap);
                $trunkCountsWeek = self::labelTrunkCounts(self::countGroupedByTrunk($cdrFilters, 'week'), $trunkNameMap);
                $trunkCountsMonth = self::labelTrunkCounts(self::countGroupedByTrunk($cdrFilters, 'month'), $trunkNameMap);
                $trunkCountsDayPrevious = self::labelTrunkCounts(self::countGroupedByTrunk($cdrFilters, 'day', 'previous'), $trunkNameMap);
                $trunkCountsWeekPrevious = self::labelTrunkCounts(self::countGroupedByTrunk($cdrFilters, 'week', 'previous'), $trunkNameMap);
                $trunkCountsMonthPrevious = self::labelTrunkCounts(self::countGroupedByTrunk($cdrFilters, 'month', 'previous'), $trunkNameMap);
                $whatsappCharts = self::buildDashboardWhatsAppCharts($obUser);

                return [
                'statusMapMes'           => $data['statusMes'],
                'statusMapDia'           => $data['statusDia'],
                'statusMapSemana'        => $data['statusSemana'] ?? $data['statusDia'],
                'statusMapMesAnterior'   => $data['statusMesAnterior'] ?? [],
                'statusMapDiaAnterior'   => $data['statusDiaAnterior'] ?? [],
                'statusMapSemanaAnterior'=> $data['statusSemanaAnterior'] ?? [],
                'totalMesAtual'          => $data['totalMesAtual'],
                'totalMesAnterior'       => $data['totalMesAnterior'],
                'totalDiaAtual'          => $data['totalDiaAtual'],
                'totalDiaAnterior'       => $data['totalDiaAnterior'],
                'totalSemanaAtual'       => $data['totalSemanaAtual'] ?? $data['totalDiaAtual'],
                'totalSemanaAnterior'    => $data['totalSemanaAnterior'] ?? $data['totalDiaAnterior'],
                'totalCustoMesAtualSms'  => $data['totalCustoMesAtual'] ?? 0,
                'totalCustoMesAnteriorSms' => $data['totalCustoMesAnterior'] ?? 0,
                'totalCustoDiaAtualSms'  => $data['totalCustoDiaAtual'] ?? 0,
                'totalCustoDiaAnteriorSms' => $data['totalCustoDiaAnterior'] ?? 0,
                'totalCustoSemanaAtualSms' => $data['totalCustoSemanaAtual'] ?? 0,
                'totalCustoSemanaAnteriorSms' => $data['totalCustoSemanaAnterior'] ?? 0,

                'statusMapMesVoice'      => $dataVoice['statusMes'],
                'statusMapDiaVoice'      => $dataVoice['statusDia'],
                'statusMapSemanaVoice'   => $dataVoice['statusSemana'] ?? $dataVoice['statusDia'],
                'statusMapMesAnteriorVoice' => $dataVoice['statusMesAnterior'] ?? [],
                'statusMapDiaAnteriorVoice' => $dataVoice['statusDiaAnterior'] ?? [],
                'statusMapSemanaAnteriorVoice' => $dataVoice['statusSemanaAnterior'] ?? [],
                'totalMesAtualVoice'     => $dataVoice['totalMesAtual'],
                'totalMesAnteriorVoice'  => $dataVoice['totalMesAnterior'],
                'totalDiaAtualVoice'     => $dataVoice['totalDiaAtual'],
                'totalDiaAnteriorVoice'  => $dataVoice['totalDiaAnterior'],
                'totalSemanaAtualVoice'  => $dataVoice['totalSemanaAtual'] ?? $dataVoice['totalDiaAtual'],
                'totalSemanaAnteriorVoice'=> $dataVoice['totalSemanaAnterior'] ?? $dataVoice['totalDiaAnterior'],
                'totalCustoMesAtualVoice' => $dataVoice['totalCustoMesAtual'] ?? 0,
                'totalCustoMesAnteriorVoice' => $dataVoice['totalCustoMesAnterior'] ?? 0,
                'totalCustoDiaAtualVoice' => $dataVoice['totalCustoDiaAtual'] ?? 0,
                'totalCustoDiaAnteriorVoice' => $dataVoice['totalCustoDiaAnterior'] ?? 0,
                'totalCustoSemanaAtualVoice' => $dataVoice['totalCustoSemanaAtual'] ?? 0,
                'totalCustoSemanaAnteriorVoice' => $dataVoice['totalCustoSemanaAnterior'] ?? 0,

                'statusMapMesWhats'       => $whatsappCharts['statusMapMesWhats'],
                'statusMapDiaWhats'       => $whatsappCharts['statusMapDiaWhats'],
                'statusMapSemanaWhats'    => $whatsappCharts['statusMapSemanaWhats'],
                'statusMapMesWhatsVoice'  => $whatsappCharts['statusMapMesWhatsVoice'],
                'statusMapDiaWhatsVoice'  => $whatsappCharts['statusMapDiaWhatsVoice'],
                'statusMapSemanaWhatsVoice' => $whatsappCharts['statusMapSemanaWhatsVoice'],
                'statusMapMesAnteriorWhatsVoice' => $whatsappCharts['statusMapMesAnteriorWhatsVoice'],
                'statusMapDiaAnteriorWhatsVoice' => $whatsappCharts['statusMapDiaAnteriorWhatsVoice'],
                'statusMapSemanaAnteriorWhatsVoice' => $whatsappCharts['statusMapSemanaAnteriorWhatsVoice'],
                'costMapMesWhats'         => $whatsappCharts['costMapMesWhats'],
                'costMapDiaWhats'         => $whatsappCharts['costMapDiaWhats'],
                'costMapSemanaWhats'      => $whatsappCharts['costMapSemanaWhats'],
                'categoryCostMesWhats'     => $whatsappCharts['categoryCostMesWhats'],
                'categoryCostDiaWhats'     => $whatsappCharts['categoryCostDiaWhats'],
                'categoryCostSemanaWhats'  => $whatsappCharts['categoryCostSemanaWhats'],
                'companyCostMapMesWhats'  => $whatsappCharts['companyCostMapMesWhats'],
                'companyCostMapDiaWhats'  => $whatsappCharts['companyCostMapDiaWhats'],
                'companyCostMapSemanaWhats' => $whatsappCharts['companyCostMapSemanaWhats'],
                'totalMesAtualWhats'      => $whatsappCharts['totalMesAtualWhats'],
                'totalMesAnteriorWhats'   => $whatsappCharts['totalMesAnteriorWhats'],
                'totalDiaAtualWhats'      => $whatsappCharts['totalDiaAtualWhats'],
                'totalDiaAnteriorWhats'   => $whatsappCharts['totalDiaAnteriorWhats'],
                'totalSemanaAtualWhats'   => $whatsappCharts['totalSemanaAtualWhats'],
                'totalSemanaAnteriorWhats'=> $whatsappCharts['totalSemanaAnteriorWhats'],
                'totalMesAtualWhatsVoice' => $whatsappCharts['totalMesAtualWhatsVoice'],
                'totalMesAnteriorWhatsVoice' => $whatsappCharts['totalMesAnteriorWhatsVoice'],
                'totalDiaAtualWhatsVoice' => $whatsappCharts['totalDiaAtualWhatsVoice'],
                'totalDiaAnteriorWhatsVoice' => $whatsappCharts['totalDiaAnteriorWhatsVoice'],
                'totalSemanaAtualWhatsVoice' => $whatsappCharts['totalSemanaAtualWhatsVoice'],
                'totalSemanaAnteriorWhatsVoice' => $whatsappCharts['totalSemanaAnteriorWhatsVoice'],
                'totalCustoMesAtualWhats' => $whatsappCharts['totalCustoMesAtualWhats'],
                'totalCustoMesAnteriorWhats' => $whatsappCharts['totalCustoMesAnteriorWhats'],
                'totalCustoDiaAtualWhats' => $whatsappCharts['totalCustoDiaAtualWhats'],
                'totalCustoDiaAnteriorWhats' => $whatsappCharts['totalCustoDiaAnteriorWhats'],
                'totalCustoSemanaAtualWhats' => $whatsappCharts['totalCustoSemanaAtualWhats'],
                'totalCustoSemanaAnteriorWhats' => $whatsappCharts['totalCustoSemanaAnteriorWhats'],

                'sipCodeCountsDay'        => $sipCodesDay,
                'sipCodeCountsWeek'       => $sipCodesWeek,
                'sipCodeCountsMonth'      => $sipCodesMonth,
                'sipCodeCountsDayPrevious' => $sipCodesDayPrevious,
                'sipCodeCountsWeekPrevious' => $sipCodesWeekPrevious,
                'sipCodeCountsMonthPrevious' => $sipCodesMonthPrevious,
                'trunkCountsDay'          => $trunkCountsDay,
                'trunkCountsWeek'         => $trunkCountsWeek,
                'trunkCountsMonth'        => $trunkCountsMonth,
                'trunkCountsDayPrevious'  => $trunkCountsDayPrevious,
                'trunkCountsWeekPrevious' => $trunkCountsWeekPrevious,
                'trunkCountsMonthPrevious'=> $trunkCountsMonthPrevious,

                'totalOperator'          => $dataOperatorMonth ?? [],
                'totalOperatorDay'       => $dataOperatorDay ?? [],
                'totalOperatorWeek'      => $dataOperatorWeek ?? [],
                'totalOperatorMonth'     => $dataOperatorMonth ?? [],
                'totalOperatorDayPrevious' => $dataOperatorDayPrevious ?? [],
                'totalOperatorWeekPrevious' => $dataOperatorWeekPrevious ?? [],
                'totalOperatorMonthPrevious' => $dataOperatorMonthPrevious ?? [],
                'totalPixValueMonth'     => $dataValuesPix,
                'pixValues'              => $dataValuesPix,
                'refillValues'           => $dataValuesPixRefill
                ];
            });

            echo "data: " . json_encode($response) . "\n\n";
            DashboardService::log('/dashboard/charts', 'sse_emit', $startedAt, [
                'role' => $role,
                'tenancy_id' => $tenancyId ?? null,
            ]);
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

        if (!self::canSwapPlan($obUser)) {
            return new Response(403, [
                'success' => false,
                'message' => 'Você não tem permissão para alterar o plano ativo.'
            ], 'application/json');
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }

        if (empty($input['plan_id'])) {
            $input = array_merge($request->getPostVars(), $input);
        }

        $tenancyId = $obUser['tenancy_id'] ?? null;
        $planId    = $input['plan_id'] ?? null;

        if (!$tenancyId || !$planId) {
            return new Response(400, [
                'success' => false,
                'message' => 'Dados inválidos'
            ], 'application/json');
        }

        $allowedPlans = UserPlans::getAllActivePlansByUser((int)($obUser['id'] ?? 0), (string)$tenancyId);
        $allowedPlanIds = [];
        foreach ((array)$allowedPlans as $plan) {
            if (is_object($plan)) {
                $allowedPlanIds[] = (int)($plan->plan_id ?? 0);
                continue;
            }

            if (is_array($plan)) {
                $allowedPlanIds[] = (int)($plan['plan_id'] ?? 0);
            }
        }

        $allowedPlanIds = array_values(array_unique(array_filter($allowedPlanIds, static fn (int $id): bool => $id > 0)));
        if (!in_array((int)$planId, $allowedPlanIds, true)) {
            return new Response(403, [
                'success' => false,
                'message' => 'O plano informado não está disponível para troca nesta conta.'
            ], 'application/json');
        }

        // 1) Atualiza plano ativo no painel
        RegisterTenancies::updateActivePlan($tenancyId, $planId);
        PlanRuntimeService::refreshPlanRuntime((string)$tenancyId);
        AuthContext::invalidate((string)$tenancyId, (int)($obUser['id'] ?? 0));

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

            $adminBalance        = (float)($panelAdmin->balance ?? 0);
            $adminValueVoice     = (float)($panelAdmin->value_voice ?? 0);
            $adminVoiceOpenRate  = (float)($panelAdmin->voice_open_rate ?? $adminValueVoice);
            $adminVoiceSmartRate = (float)($panelAdmin->voice_smart_rate ?? $adminValueVoice);
            $adminServiceFee     = (float)($panelAdmin->service_fee ?? 0);

            // 3) Resellers (saldo painel + tarifa via Rates)
            $resellers = UserSearch::getResellers($tenancyId) ?? [];
            $resellerPayload = [];

            foreach ($resellers as $r) {
                $rid = (int)($r['id'] ?? 0);
                if ($rid <= 0) continue;

                $resellerPanel = BalanceSms::getBalanceSms($rid, $tenancyId, $planId);
                if (!$resellerPanel) {
                    continue;
                }

                $resellerValueVoice = (float)($resellerPanel->value_voice ?? 0);
                $serviceFee         = (float)($resellerPanel->service_fee ?? 0);
                $voiceOpenRate      = (float)($resellerPanel->voice_open_rate ?? $resellerValueVoice);
                $voiceSmartRate     = (float)($resellerPanel->voice_smart_rate ?? $resellerValueVoice);

                $resellerPayload[] = [
                    'user_id'           => $rid,
                    'balance_admin'     => $adminBalance,
                    'balance_reseller'  => (float)($r['reseller_balance'] ?? 0),
                    'value_voice'       => $resellerValueVoice,
                    'voice_open_rate'   => $voiceOpenRate,
                    'voice_smart_rate'  => $voiceSmartRate,
                    'service_fee'       => $serviceFee,
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
                    'user_id'           => $adminId,
                    'balance_admin'     => $adminBalance,
                    'value_voice'       => $adminValueVoice,
                    'voice_open_rate'   => $adminVoiceOpenRate,
                    'voice_smart_rate'  => $adminVoiceSmartRate,
                    'service_fee'       => $adminServiceFee,
                ],

                // RESELLERS
                'resellers' => $resellerPayload,
            ];

            $resp = $asterisk->updateTariff($query, $payload);

            if (!empty($resp['ok'])) {
                return new Response(200, [
                    'success' => true,
                    'message' => 'Plano alterado com sucesso.',
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
