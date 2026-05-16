<?php

namespace App\Service;

use WilliamCosta\DatabaseManager\Database;

class PlatformConsumptionDashboardService
{
    private const ROUTES = [
        [
            'route_path' => '/admin/platform-consumption',
            'module_name' => 'Administrativo: Consumo Global',
        ],
        [
            'route_path' => '/admin/platform-consumption/data',
            'module_name' => 'Administrativo: Consumo Global',
        ],
    ];

    private const REVENUE_SLUGS = [
        'actor_admin_retail',
        'actor_reseller_retail',
        'actor_client_wallet',
        'reseller_retail_fallback',
        'admin_retail_fallback',
    ];

    public static function ensureRouteCatalog(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            foreach (self::ROUTES as $route) {
                $exists = (new Database())->execute(
                    'SELECT id FROM sys_routes WHERE route_path = :route_path LIMIT 1',
                    [':route_path' => $route['route_path']]
                )->fetchColumn();

                if ($exists) {
                    continue;
                }

                (new Database('sys_routes'))->insert([
                    'module_name' => $route['module_name'],
                    'route_path' => $route['route_path'],
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[platform_consumption_route_catalog] ' . $e->getMessage());
        }
    }

    public static function dashboard(array $filters = []): array
    {
        $normalized = self::normalizeFilters($filters);
        $cacheKey = 'platform_consumption_dashboard:' . md5(json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return DashboardService::remember($cacheKey, 90, static function () use ($normalized): array {
            $ledgerRevenue = self::ledgerRevenueByProduct($normalized);
            $costByProduct = self::costByProduct($normalized);
            $sms = self::smsAggregate($normalized);
            $voice = self::voiceAggregate($normalized);
            $whats = self::whatsMessageAggregate($normalized);
            $whatsVoice = self::whatsVoiceAggregate($normalized);
            $series = self::dailySeries($normalized);
            $topTenants = self::topTenants($normalized);
            $failures = self::recentFailures($normalized);
            $tenants = self::tenantOptions();
            $audit = self::auditStructure();

            $revenueTotal = array_sum($ledgerRevenue);
            $costTotal = array_sum($costByProduct);
            $profitTotal = round($revenueTotal - $costTotal, 4);

            $voiceAnswered = (int)($voice['answered_calls'] ?? 0);
            $voiceTotal = max(0, (int)($voice['total_calls'] ?? 0));
            $asr = $voiceTotal > 0 ? round(($voiceAnswered / $voiceTotal) * 100, 2) : 0.0;
            $acd = $voiceAnswered > 0 ? round(((float)($voice['billed_seconds'] ?? 0)) / $voiceAnswered, 2) : 0.0;

            $summary = [
                'period' => [
                    'preset' => $normalized['preset'],
                    'date_from' => $normalized['date_from'],
                    'date_to' => $normalized['date_to'],
                    'tenant_id' => $normalized['tenant_id'],
                    'product' => $normalized['product'],
                    'status' => $normalized['status'],
                ],
                'revenue_total' => round($revenueTotal, 4),
                'cost_total' => round($costTotal, 4),
                'profit_total' => $profitTotal,
                'sms_total' => (int)($sms['total_messages'] ?? 0),
                'sms_delivered' => (int)($sms['delivered_messages'] ?? 0),
                'sms_failed' => (int)($sms['failed_messages'] ?? 0),
                'whatsapp_total' => (int)($whats['total_messages'] ?? 0),
                'whatsapp_delivered' => (int)($whats['delivered_messages'] ?? 0),
                'whatsapp_read' => (int)($whats['read_messages'] ?? 0),
                'whatsapp_failed' => (int)($whats['failed_messages'] ?? 0),
                'voice_total' => $voiceTotal,
                'voice_answered' => $voiceAnswered,
                'voice_failed' => (int)($voice['failed_calls'] ?? 0),
                'voice_cancelled' => (int)($voice['cancelled_calls'] ?? 0),
                'voice_busy' => (int)($voice['busy_calls'] ?? 0),
                'voice_billed_minutes' => round(((float)($voice['billed_seconds'] ?? 0)) / 60, 4),
                'voice_asr' => $asr,
                'voice_acd_seconds' => $acd,
                'whatsapp_voice_total' => (int)($whatsVoice['total_calls'] ?? 0),
                'whatsapp_voice_answered' => (int)($whatsVoice['answered_calls'] ?? 0),
                'active_tenants' => self::activeTenantCount($topTenants),
                'products' => [
                    'sms' => [
                        'revenue' => round((float)($ledgerRevenue['sms'] ?? 0), 4),
                        'cost' => round((float)($costByProduct['sms'] ?? 0), 4),
                        'profit' => round((float)($ledgerRevenue['sms'] ?? 0) - (float)($costByProduct['sms'] ?? 0), 4),
                    ],
                    'voice' => [
                        'revenue' => round((float)($ledgerRevenue['voice'] ?? 0), 4),
                        'cost' => round((float)($costByProduct['voice'] ?? 0), 4),
                        'profit' => round((float)($ledgerRevenue['voice'] ?? 0) - (float)($costByProduct['voice'] ?? 0), 4),
                    ],
                    'whatsapp' => [
                        'revenue' => round((float)($ledgerRevenue['whatsapp'] ?? 0), 4),
                        'cost' => round((float)($costByProduct['whatsapp'] ?? 0), 4),
                        'profit' => round((float)($ledgerRevenue['whatsapp'] ?? 0) - (float)($costByProduct['whatsapp'] ?? 0), 4),
                    ],
                    'whatsapp_voice' => [
                        'revenue' => round((float)($ledgerRevenue['whatsapp_voice'] ?? 0), 4),
                        'cost' => round((float)($costByProduct['whatsapp_voice'] ?? 0), 4),
                        'profit' => round((float)($ledgerRevenue['whatsapp_voice'] ?? 0) - (float)($costByProduct['whatsapp_voice'] ?? 0), 4),
                    ],
                ],
            ];

            return [
                'summary' => $summary,
                'series' => $series,
                'top_tenants' => $topTenants,
                'recent_failures' => $failures,
                'tenant_options' => $tenants,
                'audit' => $audit,
            ];
        });
    }

    public static function auditStructure(): array
    {
        return [
            'ready' => [
                'dashboard_operacional_existente' => [
                    'controller' => 'app/Controller/Pages/Dashboard.php',
                    'service' => 'app/Service/DashboardService.php',
                    'sse' => ['/dashboard/cards', '/dashboard/charts'],
                ],
                'ledger_financeiro' => [
                    'service' => 'app/Service/FinancialTransactionService.php',
                    'table' => 'financial_transaction_ledger',
                ],
                'cadeia_financeira' => [
                    'service' => 'app/Service/FinancialHierarchyBillingService.php',
                    'audit_table' => 'financial_hierarchy_logs',
                ],
                'relatorios_produto' => [
                    'sms' => 'app/Controller/Pages/Reports.php',
                    'voice' => 'app/Controller/Pages/Reports.php',
                    'whatsapp' => 'app/Controller/Pages/Reports.php',
                ],
            ],
            'partial' => [
                'dashboard_atual' => 'Consolida operação por perfil, mas não padroniza receita/custo/lucro globais em uma área exclusiva do superadmin.',
                'custos_globais' => 'Fonte oficial de custo global já criada, mas a visão de consumo financeiro consolidado ainda não estava fechada.',
                'whatsapp_custos' => 'Já expõe cost_brl em relatórios internos, porém não havia resumo global cruzado com receita confirmada.',
            ],
            'missing_before_this_stage' => [
                'rota_exclusiva_superadmin',
                'cards_globais_receita_custo_lucro',
                'ranking_global_por_tenant',
                'visao_unificada_sms_voz_whatsapp',
                'padrao_unico_de_filtros_periodo_tenant_produto_status',
            ],
            'duplicates_or_risks' => [
                'dashboard atual usa somatórios operacionais e financeiros em pontos diferentes',
                'voz exige agregação por call_id/channel_id para não duplicar pernas',
                'sms depende de callback final e charged do lote para reconciliação',
                'whatsapp mistura CDR operacional e cobrança confirmada por billed',
            ],
            'performance' => [
                'SSE atual não foi reaproveitado para evitar carga global contínua sobre toda a plataforma',
                'novo dashboard usa cache curto e agregações SQL fechadas',
                'listas retornam limitadas e focadas em top tenants e falhas recentes',
            ],
        ];
    }

    private static function normalizeFilters(array $filters): array
    {
        $preset = strtolower(trim((string)($filters['preset'] ?? '7d')));
        $allowedPresets = ['today', 'yesterday', '7d', '30d', 'custom'];
        if (!in_array($preset, $allowedPresets, true)) {
            $preset = '7d';
        }

        [$dateFrom, $dateTo] = self::resolveDateRange(
            $preset,
            trim((string)($filters['date_from'] ?? '')),
            trim((string)($filters['date_to'] ?? ''))
        );

        $product = strtolower(trim((string)($filters['product'] ?? 'all')));
        if (!in_array($product, ['all', 'sms', 'voice', 'whatsapp', 'whatsapp_voice'], true)) {
            $product = 'all';
        }

        return [
            'preset' => $preset,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'tenant_id' => trim((string)($filters['tenant_id'] ?? '')),
            'product' => $product,
            'status' => strtolower(trim((string)($filters['status'] ?? 'all'))),
        ];
    }

    private static function resolveDateRange(string $preset, string $dateFrom, string $dateTo): array
    {
        $today = date('Y-m-d');

        return match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
            '30d' => [date('Y-m-d', strtotime('-29 days')), $today],
            'custom' => [
                self::safeDate($dateFrom) ?: date('Y-m-d', strtotime('-6 days')),
                self::safeDate($dateTo) ?: $today,
            ],
            default => [date('Y-m-d', strtotime('-6 days')), $today],
        };
    }

    private static function safeDate(string $value): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    private static function ledgerRevenueByProduct(array $filters): array
    {
        FinancialTransactionService::ensureSchema();

        $products = [
            'sms' => ['sms_batch_callback'],
            'voice' => ['voice_cdr_usage', 'voice_service_fee'],
            'whatsapp' => ['whatsapp_whatsapp_delivered', 'whatsapp_whatsapp_auth', 'whatsapp_whatsapp_batch_auth'],
            'whatsapp_voice' => ['whatsapp_voice_call_debited'],
        ];

        $totals = [
            'sms' => 0.0,
            'voice' => 0.0,
            'whatsapp' => 0.0,
            'whatsapp_voice' => 0.0,
        ];

        foreach ($products as $product => $sources) {
            if ($filters['product'] !== 'all' && $filters['product'] !== $product) {
                continue;
            }

            $params = [
                ':date_from' => $filters['date_from'] . ' 00:00:00',
                ':date_to' => $filters['date_to'] . ' 23:59:59',
            ];

            $sourcePlaceholders = [];
            foreach ($sources as $index => $source) {
                $key = ':source_' . $product . '_' . $index;
                $sourcePlaceholders[] = $key;
                $params[$key] = $source;
            }

            $slugPlaceholders = [];
            foreach (self::REVENUE_SLUGS as $index => $slug) {
                $key = ':slug_' . $product . '_' . $index;
                $slugPlaceholders[] = $key;
                $params[$key] = $slug;
            }

            $tenantWhere = '';
            if ($filters['tenant_id'] !== '') {
                $tenantWhere = ' AND l.tenancy_id = :tenancy_id';
                $params[':tenancy_id'] = $filters['tenant_id'];
            }

            $total = (new Database())->execute(
                "SELECT COALESCE(SUM(l.amount), 0) AS total
                 FROM financial_transaction_ledger l
                 WHERE l.status = 'committed'
                   AND l.processed_at BETWEEN :date_from AND :date_to
                   {$tenantWhere}
                   AND l.source IN (" . implode(', ', $sourcePlaceholders) . ")
                   AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.metadata_json, '$.billing_leg.slug')), '') IN (" . implode(', ', $slugPlaceholders) . ")",
                $params
            )->fetchColumn();

            $totals[$product] = round((float)$total, 4);
        }

        return $totals;
    }

    private static function costByProduct(array $filters): array
    {
        return [
            'sms' => self::smsPlatformCost($filters),
            'voice' => self::voicePlatformCost($filters),
            'whatsapp' => self::whatsMessageCost($filters),
            'whatsapp_voice' => self::whatsVoiceCost($filters),
        ];
    }

    private static function smsPlatformCost(array $filters): float
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];

        $tenantWhere = '';
        if ($filters['tenant_id'] !== '') {
            $tenantWhere = ' AND l.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $slugPlaceholders = [];
        foreach (self::REVENUE_SLUGS as $index => $slug) {
            $key = ':sms_cost_slug_' . $index;
            $slugPlaceholders[] = $key;
            $params[$key] = $slug;
        }

        $total = (new Database())->execute(
            "SELECT COALESCE(SUM(batch_cost), 0) AS total
             FROM (
                SELECT l.related_id,
                       MAX(CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.metadata_json, '$.admin_upstream_total_charge')), '0') AS DECIMAL(14,4))) AS batch_cost
                FROM financial_transaction_ledger l
                WHERE l.status = 'committed'
                  AND l.source = 'sms_batch_callback'
                  AND l.processed_at BETWEEN :date_from AND :date_to
                  {$tenantWhere}
                  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.metadata_json, '$.billing_leg.slug')), '') IN (" . implode(', ', $slugPlaceholders) . ")
                GROUP BY l.related_id
             ) cost_rows",
            $params
        )->fetchColumn();

        return round((float)$total, 4);
    }

    private static function voicePlatformCost(array $filters): float
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];

        $tenantWhere = '';
        if ($filters['tenant_id'] !== '') {
            $tenantWhere = ' AND l.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $slugPlaceholders = [];
        foreach (self::REVENUE_SLUGS as $index => $slug) {
            $key = ':voice_cost_slug_' . $index;
            $slugPlaceholders[] = $key;
            $params[$key] = $slug;
        }

        $total = (new Database())->execute(
            "SELECT COALESCE(SUM(call_cost), 0) AS total
             FROM (
                SELECT l.related_id,
                       MAX(CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.metadata_json, '$.admin_upstream_estimated_cost')), '0') AS DECIMAL(14,4))) AS call_cost
                FROM financial_transaction_ledger l
                WHERE l.status = 'committed'
                  AND l.source = 'voice_cdr_usage'
                  AND l.processed_at BETWEEN :date_from AND :date_to
                  {$tenantWhere}
                  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.metadata_json, '$.billing_leg.slug')), '') IN (" . implode(', ', $slugPlaceholders) . ")
                GROUP BY l.related_id
             ) cost_rows",
            $params
        )->fetchColumn();

        return round((float)$total, 4);
    }

    private static function whatsMessageCost(array $filters): float
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];

        $tenantWhere = '';
        if ($filters['tenant_id'] !== '') {
            $tenantWhere = ' AND l.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $rows = (new Database())->execute(
            "SELECT DISTINCT l.related_id
             FROM financial_transaction_ledger l
             WHERE l.status = 'committed'
               AND l.related_type = 'whatsapp_message'
               AND l.processed_at BETWEEN :date_from AND :date_to
               {$tenantWhere}",
            $params
        )->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        if (!$rows) {
            return 0.0;
        }

        $params = [];
        $placeholders = [];
        foreach ($rows as $index => $wamid) {
            $key = ':wamid_' . $index;
            $placeholders[] = $key;
            $params[$key] = (string)$wamid;
        }

        $total = (new Database())->execute(
            "SELECT COALESCE(SUM(cost_brl), 0) AS total
             FROM whatsapp_message_cdr
             WHERE wamid IN (" . implode(', ', $placeholders) . ")",
            $params
        )->fetchColumn();

        return round((float)$total, 4);
    }

    private static function whatsVoiceCost(array $filters): float
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];

        $tenantWhere = '';
        if ($filters['tenant_id'] !== '') {
            $tenantWhere = ' AND l.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $rows = (new Database())->execute(
            "SELECT DISTINCT l.related_id
             FROM financial_transaction_ledger l
             WHERE l.status = 'committed'
               AND l.related_type = 'whatsapp_voice_call'
               AND l.processed_at BETWEEN :date_from AND :date_to
               {$tenantWhere}",
            $params
        )->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        if (!$rows) {
            return 0.0;
        }

        $params = [];
        $placeholders = [];
        foreach ($rows as $index => $callId) {
            $key = ':call_' . $index;
            $placeholders[] = $key;
            $params[$key] = (string)$callId;
        }

        $total = (new Database())->execute(
            "SELECT COALESCE(SUM(base_cost), 0) AS total
             FROM whatsapp_call_cdr
             WHERE call_id IN (" . implode(', ', $placeholders) . ")",
            $params
        )->fetchColumn();

        return round((float)$total, 4);
    }

    private static function smsAggregate(array $filters): array
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            'COALESCE(c.update_date, c.date_send, c.received_at, c.webhook_created) BETWEEN :date_from AND :date_to',
        ];

        if ($filters['tenant_id'] !== '') {
            $where[] = 'c.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        if (!in_array($filters['status'], ['', 'all'], true)) {
            if ($filters['status'] === 'failed') {
                $where[] = "UPPER(COALESCE(c.status_sms, '')) IN ('UNDELIVERABLE', 'EXPIRED', 'REJECTED', 'BLACKLIST', 'UNKNOWN', 'DELETED')";
            } elseif ($filters['status'] === 'delivered') {
                $where[] = "UPPER(COALESCE(c.status_sms, '')) = 'DELIVERED'";
            } elseif ($filters['status'] === 'sent') {
                $where[] = "UPPER(COALESCE(c.status_sms, '')) = 'SENT'";
            }
        }

        $row = (new Database())->execute(
            "SELECT
                COUNT(*) AS total_messages,
                SUM(CASE WHEN UPPER(COALESCE(c.status_sms, '')) = 'DELIVERED' THEN 1 ELSE 0 END) AS delivered_messages,
                SUM(CASE WHEN UPPER(COALESCE(c.status_sms, '')) IN ('UNDELIVERABLE', 'EXPIRED', 'REJECTED', 'BLACKLIST', 'UNKNOWN', 'DELETED') THEN 1 ELSE 0 END) AS failed_messages,
                SUM(CASE WHEN UPPER(COALESCE(c.status_sms, '')) = 'SENT' THEN 1 ELSE 0 END) AS sent_messages
             FROM callback c
             WHERE " . implode(' AND ', $where),
            $params
        )->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'total_messages' => (int)($row['total_messages'] ?? 0),
            'delivered_messages' => (int)($row['delivered_messages'] ?? 0),
            'failed_messages' => (int)($row['failed_messages'] ?? 0),
            'sent_messages' => (int)($row['sent_messages'] ?? 0),
        ];
    }

    private static function voiceAggregate(array $filters): array
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            "COALESCE(NULLIF(c.started, '0000-00-00 00:00:00'), NULLIF(c.cdr_timestamp, '0000-00-00 00:00:00'), c.created_at) BETWEEN :date_from AND :date_to",
        ];

        if ($filters['tenant_id'] !== '') {
            $where[] = 'c.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        if (!in_array($filters['status'], ['', 'all'], true)) {
            $statusMap = [
                'answered' => 'ANSWER',
                'noanswer' => 'NOANSWER',
                'failed' => 'FAILED',
                'busy' => 'BUSY',
                'cancelled' => 'CANCEL',
            ];
            if (isset($statusMap[$filters['status']])) {
                $where[] = 'UPPER(COALESCE(c.dialstatus, \'\')) = :voice_status';
                $params[':voice_status'] = $statusMap[$filters['status']];
            }
        }

        $sql = "SELECT
                    COUNT(*) AS total_calls,
                    SUM(CASE WHEN status_rank = 50 THEN 1 ELSE 0 END) AS answered_calls,
                    SUM(CASE WHEN status_rank = 40 THEN 1 ELSE 0 END) AS busy_calls,
                    SUM(CASE WHEN status_rank = 30 THEN 1 ELSE 0 END) AS noanswer_calls,
                    SUM(CASE WHEN status_rank = 20 THEN 1 ELSE 0 END) AS failed_calls,
                    SUM(CASE WHEN status_rank = 10 THEN 1 ELSE 0 END) AS cancelled_calls,
                    SUM(duration_one) AS billed_seconds
                FROM (
                    SELECT
                        COALESCE(NULLIF(c.call_id, ''), c.channel_id) AS call_key,
                        MAX(
                            CASE UPPER(COALESCE(c.dialstatus, ''))
                                WHEN 'ANSWER' THEN 50
                                WHEN 'BUSY' THEN 40
                                WHEN 'NOANSWER' THEN 30
                                WHEN 'FAILED' THEN 20
                                WHEN 'CANCEL' THEN 10
                                ELSE 0
                            END
                        ) AS status_rank,
                        MAX(COALESCE(c.billsec, c.duration, 0)) AS duration_one
                    FROM cdr c
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY COALESCE(NULLIF(c.call_id, ''), c.channel_id)
                ) grouped_calls";

        $row = (new Database())->execute($sql, $params)->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'total_calls' => (int)($row['total_calls'] ?? 0),
            'answered_calls' => (int)($row['answered_calls'] ?? 0),
            'busy_calls' => (int)($row['busy_calls'] ?? 0),
            'noanswer_calls' => (int)($row['noanswer_calls'] ?? 0),
            'failed_calls' => (int)($row['failed_calls'] ?? 0),
            'cancelled_calls' => (int)($row['cancelled_calls'] ?? 0),
            'billed_seconds' => (int)($row['billed_seconds'] ?? 0),
        ];
    }

    private static function whatsMessageAggregate(array $filters): array
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            "COALESCE(c.delivered_at, c.timestamp, c.created_at) BETWEEN :date_from AND :date_to",
        ];

        if ($filters['tenant_id'] !== '') {
            $where[] = 'c.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        if (!in_array($filters['status'], ['', 'all'], true)) {
            if ($filters['status'] === 'failed') {
                $where[] = "LOWER(COALESCE(c.status, '')) = 'failed'";
            } elseif ($filters['status'] === 'delivered') {
                $where[] = "LOWER(COALESCE(c.status, '')) IN ('delivered', 'read')";
            } elseif ($filters['status'] === 'read') {
                $where[] = "LOWER(COALESCE(c.status, '')) = 'read'";
            } elseif ($filters['status'] === 'sent') {
                $where[] = "LOWER(COALESCE(c.status, '')) = 'sent'";
            }
        }

        $row = (new Database())->execute(
            "SELECT
                COUNT(*) AS total_messages,
                SUM(CASE WHEN LOWER(COALESCE(c.status, '')) IN ('delivered', 'read') THEN 1 ELSE 0 END) AS delivered_messages,
                SUM(CASE WHEN LOWER(COALESCE(c.status, '')) = 'read' THEN 1 ELSE 0 END) AS read_messages,
                SUM(CASE WHEN LOWER(COALESCE(c.status, '')) = 'failed' THEN 1 ELSE 0 END) AS failed_messages,
                SUM(CASE WHEN LOWER(COALESCE(c.message_category, 'marketing')) = 'marketing' THEN 1 ELSE 0 END) AS marketing_messages,
                SUM(CASE WHEN LOWER(COALESCE(c.message_category, '')) = 'utility' THEN 1 ELSE 0 END) AS utility_messages,
                SUM(CASE WHEN LOWER(COALESCE(c.message_category, '')) = 'authentication' THEN 1 ELSE 0 END) AS authentication_messages
             FROM whatsapp_message_cdr c
             WHERE " . implode(' AND ', $where),
            $params
        )->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'total_messages' => (int)($row['total_messages'] ?? 0),
            'delivered_messages' => (int)($row['delivered_messages'] ?? 0),
            'read_messages' => (int)($row['read_messages'] ?? 0),
            'failed_messages' => (int)($row['failed_messages'] ?? 0),
            'marketing_messages' => (int)($row['marketing_messages'] ?? 0),
            'utility_messages' => (int)($row['utility_messages'] ?? 0),
            'authentication_messages' => (int)($row['authentication_messages'] ?? 0),
        ];
    }

    private static function whatsVoiceAggregate(array $filters): array
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            "COALESCE(wc.started_at, wc.answered_at, wc.created_at) BETWEEN :date_from AND :date_to",
        ];

        if ($filters['tenant_id'] !== '') {
            $where[] = 'wc.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $row = (new Database())->execute(
            "SELECT
                COUNT(DISTINCT wc.call_id) AS total_calls,
                SUM(CASE WHEN UPPER(COALESCE(wc.status, '')) IN ('COMPLETED', 'ANSWER', 'ACCEPTED') THEN 1 ELSE 0 END) AS answered_calls,
                SUM(CASE WHEN UPPER(COALESCE(wc.status, '')) IN ('FAILED', 'NOANSWER', 'NOT_ANSWERED', 'BUSY', 'CANCELLED') THEN 1 ELSE 0 END) AS failed_calls
             FROM whatsapp_call_cdr wc
             WHERE " . implode(' AND ', $where),
            $params
        )->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'total_calls' => (int)($row['total_calls'] ?? 0),
            'answered_calls' => (int)($row['answered_calls'] ?? 0),
            'failed_calls' => (int)($row['failed_calls'] ?? 0),
        ];
    }

    private static function dailySeries(array $filters): array
    {
        $labels = [];
        $cursor = strtotime($filters['date_from']);
        $end = strtotime($filters['date_to']);

        while ($cursor <= $end) {
            $labels[] = date('Y-m-d', $cursor);
            $cursor = strtotime('+1 day', $cursor);
        }

        $series = [
            'labels' => $labels,
            'revenue' => self::emptySeries($labels),
            'cost' => self::emptySeries($labels),
            'sms' => self::emptySeries($labels),
            'voice' => self::emptySeries($labels),
            'whatsapp' => self::emptySeries($labels),
            'whatsapp_voice' => self::emptySeries($labels),
        ];

        self::fillRevenueSeries($series, $filters);
        self::fillSmsSeries($series, $filters);
        self::fillVoiceSeries($series, $filters);
        self::fillWhatsSeries($series, $filters);
        self::fillWhatsVoiceSeries($series, $filters);

        return $series;
    }

    private static function fillRevenueSeries(array &$series, array $filters): void
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $tenantWhere = '';
        if ($filters['tenant_id'] !== '') {
            $tenantWhere = ' AND l.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $slugPlaceholders = [];
        foreach (self::REVENUE_SLUGS as $index => $slug) {
            $key = ':series_slug_' . $index;
            $slugPlaceholders[] = $key;
            $params[$key] = $slug;
        }

        $rows = (new Database())->execute(
            "SELECT
                DATE(l.processed_at) AS day_ref,
                CASE
                    WHEN l.source = 'sms_batch_callback' THEN 'sms'
                    WHEN l.source IN ('voice_cdr_usage', 'voice_service_fee') THEN 'voice'
                    WHEN l.source = 'whatsapp_voice_call_debited' THEN 'whatsapp_voice'
                    ELSE 'whatsapp'
                END AS product,
                SUM(l.amount) AS total
             FROM financial_transaction_ledger l
             WHERE l.status = 'committed'
               AND l.processed_at BETWEEN :date_from AND :date_to
               {$tenantWhere}
               AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.metadata_json, '$.billing_leg.slug')), '') IN (" . implode(', ', $slugPlaceholders) . ")
             GROUP BY DATE(l.processed_at), product",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $day = (string)($row['day_ref'] ?? '');
            $product = (string)($row['product'] ?? '');
            if (!isset($series[$product][$day])) {
                continue;
            }

            $amount = round((float)($row['total'] ?? 0), 4);
            $series[$product][$day] = $amount;
            $series['revenue'][$day] = round($series['revenue'][$day] + $amount, 4);
        }

        foreach (self::smsCostSeries($filters) as $day => $amount) {
            if (isset($series['cost'][$day])) {
                $series['cost'][$day] = round($series['cost'][$day] + $amount, 4);
            }
        }

        foreach (self::voiceCostSeries($filters) as $day => $amount) {
            if (isset($series['cost'][$day])) {
                $series['cost'][$day] = round($series['cost'][$day] + $amount, 4);
            }
        }

        foreach (self::whatsCostSeries($filters) as $day => $amount) {
            if (isset($series['cost'][$day])) {
                $series['cost'][$day] = round($series['cost'][$day] + $amount, 4);
            }
        }

        foreach (self::whatsVoiceCostSeries($filters) as $day => $amount) {
            if (isset($series['cost'][$day])) {
                $series['cost'][$day] = round($series['cost'][$day] + $amount, 4);
            }
        }
    }

    private static function fillSmsSeries(array &$series, array $filters): void
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            'COALESCE(update_date, date_send, received_at, webhook_created) BETWEEN :date_from AND :date_to',
        ];
        if ($filters['tenant_id'] !== '') {
            $where[] = 'tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $rows = (new Database())->execute(
            "SELECT DATE(COALESCE(update_date, date_send, received_at, webhook_created)) AS day_ref, COUNT(*) AS total
             FROM callback
             WHERE " . implode(' AND ', $where) . "
             GROUP BY DATE(COALESCE(update_date, date_send, received_at, webhook_created))",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $day = (string)($row['day_ref'] ?? '');
            if (isset($series['sms'][$day])) {
                $series['sms'][$day] = (int)($row['total'] ?? 0);
            }
        }
    }

    private static function fillVoiceSeries(array &$series, array $filters): void
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            "COALESCE(NULLIF(started, '0000-00-00 00:00:00'), NULLIF(cdr_timestamp, '0000-00-00 00:00:00'), created_at) BETWEEN :date_from AND :date_to",
        ];
        if ($filters['tenant_id'] !== '') {
            $where[] = 'tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $rows = (new Database())->execute(
            "SELECT day_ref, COUNT(*) AS total
             FROM (
                SELECT
                    DATE(COALESCE(NULLIF(started, '0000-00-00 00:00:00'), NULLIF(cdr_timestamp, '0000-00-00 00:00:00'), created_at)) AS day_ref,
                    COALESCE(NULLIF(call_id, ''), channel_id) AS call_key
                FROM cdr
                WHERE " . implode(' AND ', $where) . "
                GROUP BY DATE(COALESCE(NULLIF(started, '0000-00-00 00:00:00'), NULLIF(cdr_timestamp, '0000-00-00 00:00:00'), created_at)), COALESCE(NULLIF(call_id, ''), channel_id)
             ) grouped_calls
             GROUP BY day_ref",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $day = (string)($row['day_ref'] ?? '');
            if (isset($series['voice'][$day])) {
                $series['voice'][$day] = (int)($row['total'] ?? 0);
            }
        }
    }

    private static function fillWhatsSeries(array &$series, array $filters): void
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            "COALESCE(delivered_at, timestamp, created_at) BETWEEN :date_from AND :date_to",
        ];
        if ($filters['tenant_id'] !== '') {
            $where[] = 'tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $rows = (new Database())->execute(
            "SELECT DATE(COALESCE(delivered_at, timestamp, created_at)) AS day_ref, COUNT(*) AS total
             FROM whatsapp_message_cdr
             WHERE " . implode(' AND ', $where) . "
             GROUP BY DATE(COALESCE(delivered_at, timestamp, created_at))",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $day = (string)($row['day_ref'] ?? '');
            if (isset($series['whatsapp'][$day])) {
                $series['whatsapp'][$day] = (int)($row['total'] ?? 0);
            }
        }
    }

    private static function fillWhatsVoiceSeries(array &$series, array $filters): void
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            "COALESCE(started_at, answered_at, created_at) BETWEEN :date_from AND :date_to",
        ];
        if ($filters['tenant_id'] !== '') {
            $where[] = 'tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $rows = (new Database())->execute(
            "SELECT DATE(COALESCE(started_at, answered_at, created_at)) AS day_ref, COUNT(DISTINCT call_id) AS total
             FROM whatsapp_call_cdr
             WHERE " . implode(' AND ', $where) . "
             GROUP BY DATE(COALESCE(started_at, answered_at, created_at))",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $day = (string)($row['day_ref'] ?? '');
            if (isset($series['whatsapp_voice'][$day])) {
                $series['whatsapp_voice'][$day] = (int)($row['total'] ?? 0);
            }
        }
    }

    private static function smsCostSeries(array $filters): array
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];

        $tenantWhere = '';
        if ($filters['tenant_id'] !== '') {
            $tenantWhere = ' AND l.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $slugPlaceholders = [];
        foreach (self::REVENUE_SLUGS as $index => $slug) {
            $key = ':sms_cost_series_slug_' . $index;
            $slugPlaceholders[] = $key;
            $params[$key] = $slug;
        }

        $rows = (new Database())->execute(
            "SELECT day_ref, COALESCE(SUM(batch_cost), 0) AS total
             FROM (
                SELECT DATE(l.processed_at) AS day_ref,
                       l.related_id,
                       MAX(CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.metadata_json, '$.admin_upstream_total_charge')), '0') AS DECIMAL(14,4))) AS batch_cost
                FROM financial_transaction_ledger l
                WHERE l.status = 'committed'
                  AND l.source = 'sms_batch_callback'
                  AND l.processed_at BETWEEN :date_from AND :date_to
                  {$tenantWhere}
                  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.metadata_json, '$.billing_leg.slug')), '') IN (" . implode(', ', $slugPlaceholders) . ")
                GROUP BY DATE(l.processed_at), l.related_id
             ) cost_rows
             GROUP BY day_ref",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return self::mapDayAmountRows($rows);
    }

    private static function voiceCostSeries(array $filters): array
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];

        $tenantWhere = '';
        if ($filters['tenant_id'] !== '') {
            $tenantWhere = ' AND l.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $slugPlaceholders = [];
        foreach (self::REVENUE_SLUGS as $index => $slug) {
            $key = ':voice_cost_series_slug_' . $index;
            $slugPlaceholders[] = $key;
            $params[$key] = $slug;
        }

        $rows = (new Database())->execute(
            "SELECT day_ref, COALESCE(SUM(call_cost), 0) AS total
             FROM (
                SELECT DATE(l.processed_at) AS day_ref,
                       l.related_id,
                       MAX(CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.metadata_json, '$.admin_upstream_estimated_cost')), '0') AS DECIMAL(14,4))) AS call_cost
                FROM financial_transaction_ledger l
                WHERE l.status = 'committed'
                  AND l.source = 'voice_cdr_usage'
                  AND l.processed_at BETWEEN :date_from AND :date_to
                  {$tenantWhere}
                  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.metadata_json, '$.billing_leg.slug')), '') IN (" . implode(', ', $slugPlaceholders) . ")
                GROUP BY DATE(l.processed_at), l.related_id
             ) cost_rows
             GROUP BY day_ref",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return self::mapDayAmountRows($rows);
    }

    private static function whatsCostSeries(array $filters): array
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            "COALESCE(c.delivered_at, c.timestamp, c.created_at) BETWEEN :date_from AND :date_to",
            'COALESCE(c.billed, 0) = 1',
        ];
        if ($filters['tenant_id'] !== '') {
            $where[] = 'c.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $rows = (new Database())->execute(
            "SELECT DATE(COALESCE(c.delivered_at, c.timestamp, c.created_at)) AS day_ref,
                    COALESCE(SUM(c.cost_brl), 0) AS total
             FROM whatsapp_message_cdr c
             WHERE " . implode(' AND ', $where) . "
             GROUP BY DATE(COALESCE(c.delivered_at, c.timestamp, c.created_at))",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return self::mapDayAmountRows($rows);
    }

    private static function whatsVoiceCostSeries(array $filters): array
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            "COALESCE(wc.started_at, wc.answered_at, wc.created_at) BETWEEN :date_from AND :date_to",
            'wc.balance_debited_at IS NOT NULL',
        ];
        if ($filters['tenant_id'] !== '') {
            $where[] = 'wc.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $rows = (new Database())->execute(
            "SELECT DATE(COALESCE(wc.started_at, wc.answered_at, wc.created_at)) AS day_ref,
                    COALESCE(SUM(wc.base_cost), 0) AS total
             FROM whatsapp_call_cdr wc
             WHERE " . implode(' AND ', $where) . "
             GROUP BY DATE(COALESCE(wc.started_at, wc.answered_at, wc.created_at))",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return self::mapDayAmountRows($rows);
    }

    private static function mapDayAmountRows(array $rows): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            $day = (string)($row['day_ref'] ?? '');
            if ($day === '') {
                continue;
            }
            $mapped[$day] = round((float)($row['total'] ?? 0), 4);
        }

        return $mapped;
    }

    private static function topTenants(array $filters): array
    {
        $tenants = [];

        foreach (self::smsTenantRows($filters) as $row) {
            $tenancyId = (string)($row['tenancy_id'] ?? '');
            if ($tenancyId === '') {
                continue;
            }
            $tenants[$tenancyId] = self::seedTenantRow($tenants[$tenancyId] ?? null, $row);
            $tenants[$tenancyId]['sms_total'] += (int)($row['sms_total'] ?? 0);
        }

        foreach (self::voiceTenantRows($filters) as $row) {
            $tenancyId = (string)($row['tenancy_id'] ?? '');
            if ($tenancyId === '') {
                continue;
            }
            $tenants[$tenancyId] = self::seedTenantRow($tenants[$tenancyId] ?? null, $row);
            $tenants[$tenancyId]['voice_total'] += (int)($row['voice_total'] ?? 0);
        }

        foreach (self::whatsTenantRows($filters) as $row) {
            $tenancyId = (string)($row['tenancy_id'] ?? '');
            if ($tenancyId === '') {
                continue;
            }
            $tenants[$tenancyId] = self::seedTenantRow($tenants[$tenancyId] ?? null, $row);
            $tenants[$tenancyId]['whatsapp_total'] += (int)($row['whatsapp_total'] ?? 0);
            $tenants[$tenancyId]['revenue'] = round($tenants[$tenancyId]['revenue'] + (float)($row['revenue'] ?? 0), 4);
            $tenants[$tenancyId]['cost'] = round($tenants[$tenancyId]['cost'] + (float)($row['cost'] ?? 0), 4);
        }

        foreach (self::whatsVoiceTenantRows($filters) as $row) {
            $tenancyId = (string)($row['tenancy_id'] ?? '');
            if ($tenancyId === '') {
                continue;
            }
            $tenants[$tenancyId] = self::seedTenantRow($tenants[$tenancyId] ?? null, $row);
            $tenants[$tenancyId]['whatsapp_voice_total'] += (int)($row['whatsapp_voice_total'] ?? 0);
            $tenants[$tenancyId]['revenue'] = round($tenants[$tenancyId]['revenue'] + (float)($row['revenue'] ?? 0), 4);
            $tenants[$tenancyId]['cost'] = round($tenants[$tenancyId]['cost'] + (float)($row['cost'] ?? 0), 4);
        }

        foreach ($tenants as $tenancyId => $row) {
            $revenue = round((float)($row['revenue'] ?? 0), 4);
            $cost = round((float)($row['cost'] ?? 0), 4);
            $tenants[$tenancyId]['profit'] = round($revenue - $cost, 4);
        }

        usort($tenants, static function (array $left, array $right): int {
            return ((float)$right['revenue'] <=> (float)$left['revenue'])
                ?: (((int)$right['sms_total'] + (int)$right['voice_total'] + (int)$right['whatsapp_total']) <=> ((int)$left['sms_total'] + (int)$left['voice_total'] + (int)$left['whatsapp_total']));
        });

        return array_slice(array_values($tenants), 0, 15);
    }

    private static function smsTenantRows(array $filters): array
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            'COALESCE(c.update_date, c.date_send, c.received_at, c.webhook_created) BETWEEN :date_from AND :date_to',
        ];
        if ($filters['tenant_id'] !== '') {
            $where[] = 'c.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        return (new Database())->execute(
            "SELECT c.tenancy_id, t.name AS tenancy_name, COUNT(*) AS sms_total
             FROM callback c
             LEFT JOIN tenancies t ON t.id = c.tenancy_id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY c.tenancy_id, t.name",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function voiceTenantRows(array $filters): array
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            "COALESCE(NULLIF(c.started, '0000-00-00 00:00:00'), NULLIF(c.cdr_timestamp, '0000-00-00 00:00:00'), c.created_at) BETWEEN :date_from AND :date_to",
        ];
        if ($filters['tenant_id'] !== '') {
            $where[] = 'c.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        return (new Database())->execute(
            "SELECT tenancy_id, tenancy_name, COUNT(*) AS voice_total
             FROM (
                SELECT c.tenancy_id, t.name AS tenancy_name, COALESCE(NULLIF(c.call_id, ''), c.channel_id) AS call_key
                FROM cdr c
                LEFT JOIN tenancies t ON t.id = c.tenancy_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY c.tenancy_id, t.name, COALESCE(NULLIF(c.call_id, ''), c.channel_id)
             ) grouped_calls
             GROUP BY tenancy_id, tenancy_name",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function whatsTenantRows(array $filters): array
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            "COALESCE(c.delivered_at, c.timestamp, c.created_at) BETWEEN :date_from AND :date_to",
        ];
        if ($filters['tenant_id'] !== '') {
            $where[] = 'c.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        return (new Database())->execute(
            "SELECT c.tenancy_id,
                    t.name AS tenancy_name,
                    COUNT(*) AS whatsapp_total,
                    COALESCE(SUM(c.final_price_brl), 0) AS revenue,
                    COALESCE(SUM(c.cost_brl), 0) AS cost
             FROM whatsapp_message_cdr c
             LEFT JOIN tenancies t ON t.id = c.tenancy_id
             WHERE " . implode(' AND ', $where) . "
               AND COALESCE(c.billed, 0) = 1
             GROUP BY c.tenancy_id, t.name",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function whatsVoiceTenantRows(array $filters): array
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            "COALESCE(wc.started_at, wc.answered_at, wc.created_at) BETWEEN :date_from AND :date_to",
        ];
        if ($filters['tenant_id'] !== '') {
            $where[] = 'wc.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        return (new Database())->execute(
            "SELECT wc.tenancy_id,
                    t.name AS tenancy_name,
                    COUNT(DISTINCT wc.call_id) AS whatsapp_voice_total,
                    COALESCE(SUM(wc.final_price), 0) AS revenue,
                    COALESCE(SUM(wc.base_cost), 0) AS cost
             FROM whatsapp_call_cdr wc
             LEFT JOIN tenancies t ON t.id = wc.tenancy_id
             WHERE " . implode(' AND ', $where) . "
               AND wc.balance_debited_at IS NOT NULL
             GROUP BY wc.tenancy_id, t.name",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function seedTenantRow(?array $current, array $row): array
    {
        if (is_array($current)) {
            return $current;
        }

        return [
            'tenancy_id' => (string)($row['tenancy_id'] ?? ''),
            'tenancy_name' => trim((string)($row['tenancy_name'] ?? '')) ?: 'Tenant',
            'sms_total' => 0,
            'voice_total' => 0,
            'whatsapp_total' => 0,
            'whatsapp_voice_total' => 0,
            'revenue' => 0.0,
            'cost' => 0.0,
            'profit' => 0.0,
        ];
    }

    private static function recentFailures(array $filters): array
    {
        $rows = array_merge(
            self::smsFailures($filters),
            self::voiceFailures($filters),
            self::whatsFailures($filters),
            self::whatsVoiceFailures($filters)
        );

        usort($rows, static function (array $left, array $right): int {
            return strcmp((string)($right['occurred_at'] ?? ''), (string)($left['occurred_at'] ?? ''));
        });

        return array_slice($rows, 0, 25);
    }

    private static function smsFailures(array $filters): array
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            'COALESCE(c.update_date, c.date_send, c.received_at, c.webhook_created) BETWEEN :date_from AND :date_to',
            "UPPER(COALESCE(c.status_sms, '')) IN ('UNDELIVERABLE', 'EXPIRED', 'REJECTED', 'BLACKLIST', 'UNKNOWN', 'DELETED')",
        ];

        if ($filters['tenant_id'] !== '') {
            $where[] = 'c.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $rows = (new Database())->execute(
            "SELECT c.tenancy_id,
                    t.name AS tenancy_name,
                    c.phone_sms AS reference_id,
                    c.status_sms AS status_label,
                    COALESCE(c.update_date, c.date_send, c.received_at, c.webhook_created) AS occurred_at
             FROM callback c
             LEFT JOIN tenancies t ON t.id = c.tenancy_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY occurred_at DESC
             LIMIT 8",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'product' => 'sms',
                'tenancy_id' => (string)($row['tenancy_id'] ?? ''),
                'tenancy_name' => trim((string)($row['tenancy_name'] ?? '')) ?: 'Tenant',
                'reference_id' => (string)($row['reference_id'] ?? ''),
                'status_label' => strtoupper((string)($row['status_label'] ?? 'FAILED')),
                'occurred_at' => (string)($row['occurred_at'] ?? ''),
            ];
        }, $rows);
    }

    private static function voiceFailures(array $filters): array
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            "COALESCE(NULLIF(c.started, '0000-00-00 00:00:00'), NULLIF(c.cdr_timestamp, '0000-00-00 00:00:00'), c.created_at) BETWEEN :date_from AND :date_to",
            "UPPER(COALESCE(c.dialstatus, '')) IN ('FAILED', 'NOANSWER', 'BUSY', 'CANCEL')",
        ];
        if ($filters['tenant_id'] !== '') {
            $where[] = 'c.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $rows = (new Database())->execute(
            "SELECT c.tenancy_id,
                    t.name AS tenancy_name,
                    COALESCE(NULLIF(c.call_id, ''), c.channel_id) AS reference_id,
                    c.dialstatus AS status_label,
                    COALESCE(NULLIF(c.started, '0000-00-00 00:00:00'), NULLIF(c.cdr_timestamp, '0000-00-00 00:00:00'), c.created_at) AS occurred_at
             FROM cdr c
             LEFT JOIN tenancies t ON t.id = c.tenancy_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY occurred_at DESC
             LIMIT 8",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'product' => 'voice',
                'tenancy_id' => (string)($row['tenancy_id'] ?? ''),
                'tenancy_name' => trim((string)($row['tenancy_name'] ?? '')) ?: 'Tenant',
                'reference_id' => (string)($row['reference_id'] ?? ''),
                'status_label' => strtoupper((string)($row['status_label'] ?? 'FAILED')),
                'occurred_at' => (string)($row['occurred_at'] ?? ''),
            ];
        }, $rows);
    }

    private static function whatsFailures(array $filters): array
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            "COALESCE(c.delivered_at, c.timestamp, c.created_at) BETWEEN :date_from AND :date_to",
            "LOWER(COALESCE(c.status, '')) = 'failed'",
        ];
        if ($filters['tenant_id'] !== '') {
            $where[] = 'c.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $rows = (new Database())->execute(
            "SELECT c.tenancy_id,
                    t.name AS tenancy_name,
                    c.wamid AS reference_id,
                    c.status AS status_label,
                    COALESCE(c.delivered_at, c.timestamp, c.created_at) AS occurred_at
             FROM whatsapp_message_cdr c
             LEFT JOIN tenancies t ON t.id = c.tenancy_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY occurred_at DESC
             LIMIT 8",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'product' => 'whatsapp',
                'tenancy_id' => (string)($row['tenancy_id'] ?? ''),
                'tenancy_name' => trim((string)($row['tenancy_name'] ?? '')) ?: 'Tenant',
                'reference_id' => (string)($row['reference_id'] ?? ''),
                'status_label' => strtoupper((string)($row['status_label'] ?? 'FAILED')),
                'occurred_at' => (string)($row['occurred_at'] ?? ''),
            ];
        }, $rows);
    }

    private static function whatsVoiceFailures(array $filters): array
    {
        $params = [
            ':date_from' => $filters['date_from'] . ' 00:00:00',
            ':date_to' => $filters['date_to'] . ' 23:59:59',
        ];
        $where = [
            "COALESCE(wc.started_at, wc.answered_at, wc.created_at) BETWEEN :date_from AND :date_to",
            "UPPER(COALESCE(wc.status, '')) IN ('FAILED', 'NOANSWER', 'NOT_ANSWERED', 'BUSY', 'CANCELLED')",
        ];
        if ($filters['tenant_id'] !== '') {
            $where[] = 'wc.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $filters['tenant_id'];
        }

        $rows = (new Database())->execute(
            "SELECT wc.tenancy_id,
                    t.name AS tenancy_name,
                    wc.call_id AS reference_id,
                    wc.status AS status_label,
                    COALESCE(wc.started_at, wc.answered_at, wc.created_at) AS occurred_at
             FROM whatsapp_call_cdr wc
             LEFT JOIN tenancies t ON t.id = wc.tenancy_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY occurred_at DESC
             LIMIT 8",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'product' => 'whatsapp_voice',
                'tenancy_id' => (string)($row['tenancy_id'] ?? ''),
                'tenancy_name' => trim((string)($row['tenancy_name'] ?? '')) ?: 'Tenant',
                'reference_id' => (string)($row['reference_id'] ?? ''),
                'status_label' => strtoupper((string)($row['status_label'] ?? 'FAILED')),
                'occurred_at' => (string)($row['occurred_at'] ?? ''),
            ];
        }, $rows);
    }

    private static function tenantOptions(): array
    {
        return (new Database())->execute(
            "SELECT id, name
             FROM tenancies
             ORDER BY name ASC"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function activeTenantCount(array $topTenants): int
    {
        return count(array_filter($topTenants, static function (array $row): bool {
            return ((int)($row['sms_total'] ?? 0)
                + (int)($row['voice_total'] ?? 0)
                + (int)($row['whatsapp_total'] ?? 0)
                + (int)($row['whatsapp_voice_total'] ?? 0)) > 0;
        }));
    }

    private static function emptySeries(array $labels): array
    {
        $series = [];
        foreach ($labels as $label) {
            $series[$label] = 0;
        }

        return $series;
    }
}
