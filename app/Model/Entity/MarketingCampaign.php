<?php

namespace App\Model\Entity;

use App\Utils\TenancyHelper;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class MarketingCampaign
{
    public static function syncSchema(): void
    {
        self::ensureSchema();
    }

    public static function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        $db = new Database();

        $db->execute("
            CREATE TABLE IF NOT EXISTS marketing_campaigns (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenancy_id VARCHAR(64) NOT NULL,
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                name VARCHAR(160) NOT NULL,
                description TEXT NULL,
                objective VARCHAR(60) NOT NULL,
                campaign_type VARCHAR(60) NOT NULL DEFAULT 'video',
                primary_text TEXT NULL,
                ad_title VARCHAR(160) NULL,
                call_to_action VARCHAR(80) NULL,
                destination_link VARCHAR(500) NULL,
                whatsapp_number VARCHAR(30) NULL,
                city VARCHAR(120) NULL,
                state VARCHAR(80) NULL,
                age_start SMALLINT UNSIGNED NULL,
                age_end SMALLINT UNSIGNED NULL,
                interests TEXT NULL,
                budget_daily DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                budget_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                start_date DATE NULL,
                end_date DATE NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                thumbnail_path VARCHAR(500) NULL,
                video_path VARCHAR(500) NULL,
                account_id VARCHAR(120) NULL,
                meta_campaign_id VARCHAR(120) NULL,
                meta_adset_id VARCHAR(120) NULL,
                meta_ad_id VARCHAR(120) NULL,
                meta_creative_id VARCHAR(120) NULL,
                sync_status VARCHAR(30) NOT NULL DEFAULT 'pending',
                sync_response_raw LONGTEXT NULL,
                deleted_at DATETIME NULL,
                archived_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_marketing_campaigns_tenancy (tenancy_id),
                KEY idx_marketing_campaigns_status (status),
                KEY idx_marketing_campaigns_sync_status (sync_status),
                KEY idx_marketing_campaigns_created_by (created_by),
                KEY idx_marketing_campaigns_dates (start_date, end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->execute("
            CREATE TABLE IF NOT EXISTS marketing_campaign_assets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                campaign_id INT UNSIGNED NOT NULL,
                tenancy_id VARCHAR(64) NOT NULL,
                asset_type VARCHAR(30) NOT NULL,
                original_name VARCHAR(255) NULL,
                storage_path VARCHAR(500) NOT NULL,
                public_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(120) NULL,
                extension VARCHAR(16) NULL,
                size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                duration_seconds DECIMAL(10,2) NULL,
                width INT UNSIGNED NULL,
                height INT UNSIGNED NULL,
                generated_thumbnail_path VARCHAR(500) NULL,
                metadata_json LONGTEXT NULL,
                uploaded_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_marketing_assets_campaign (campaign_id),
                KEY idx_marketing_assets_tenancy (tenancy_id),
                KEY idx_marketing_assets_type (asset_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->execute("
            CREATE TABLE IF NOT EXISTS marketing_campaign_budgets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                campaign_id INT UNSIGNED NOT NULL,
                tenancy_id VARCHAR(64) NOT NULL,
                budget_daily DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                budget_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                currency_code CHAR(3) NOT NULL DEFAULT 'BRL',
                budget_status VARCHAR(30) NOT NULL DEFAULT 'planned',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_marketing_budget_campaign (campaign_id),
                KEY idx_marketing_budget_tenancy (tenancy_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->execute("
            CREATE TABLE IF NOT EXISTS marketing_campaign_audiences (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                campaign_id INT UNSIGNED NOT NULL,
                tenancy_id VARCHAR(64) NOT NULL,
                audience_name VARCHAR(160) NULL,
                city VARCHAR(120) NULL,
                state VARCHAR(80) NULL,
                age_start SMALLINT UNSIGNED NULL,
                age_end SMALLINT UNSIGNED NULL,
                interests_json LONGTEXT NULL,
                details_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_marketing_audience_campaign (campaign_id),
                KEY idx_marketing_audience_tenancy (tenancy_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->execute("
            CREATE TABLE IF NOT EXISTS marketing_campaign_metrics (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                campaign_id INT UNSIGNED NOT NULL,
                tenancy_id VARCHAR(64) NOT NULL,
                impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
                clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
                cost_per_click DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
                leads BIGINT UNSIGNED NOT NULL DEFAULT 0,
                conversations_started BIGINT UNSIGNED NOT NULL DEFAULT 0,
                investment_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                conversions BIGINT UNSIGNED NOT NULL DEFAULT 0,
                estimated_return DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                sync_source VARCHAR(40) NOT NULL DEFAULT 'manual',
                snapshot_date DATE NULL,
                last_synced_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_marketing_metrics_campaign (campaign_id),
                KEY idx_marketing_metrics_tenancy (tenancy_id),
                KEY idx_marketing_metrics_snapshot (snapshot_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->execute("
            CREATE TABLE IF NOT EXISTS marketing_campaign_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                campaign_id INT UNSIGNED NULL,
                tenancy_id VARCHAR(64) NOT NULL,
                user_id INT UNSIGNED NULL,
                action VARCHAR(60) NOT NULL,
                action_label VARCHAR(160) NULL,
                details_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_marketing_history_campaign (campaign_id),
                KEY idx_marketing_history_tenancy (tenancy_id),
                KEY idx_marketing_history_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->execute("
            CREATE TABLE IF NOT EXISTS marketing_meta_settings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenancy_id VARCHAR(64) NOT NULL,
                meta_access_token LONGTEXT NULL,
                meta_ad_account_id VARCHAR(120) NULL,
                meta_pixel_id VARCHAR(120) NULL,
                meta_page_id VARCHAR(120) NULL,
                whatsapp_destination VARCHAR(30) NULL,
                meta_business_id VARCHAR(120) NULL,
                connection_status VARCHAR(30) NOT NULL DEFAULT 'not_configured',
                last_connection_check_at DATETIME NULL,
                last_error TEXT NULL,
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_marketing_meta_settings_tenancy (tenancy_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->execute("
            CREATE TABLE IF NOT EXISTS marketing_meta_sync_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                campaign_id INT UNSIGNED NULL,
                tenancy_id VARCHAR(64) NOT NULL,
                user_id INT UNSIGNED NULL,
                action VARCHAR(60) NOT NULL,
                request_payload LONGTEXT NULL,
                response_payload LONGTEXT NULL,
                success TINYINT(1) NOT NULL DEFAULT 0,
                status_code INT NULL,
                error_message TEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_marketing_sync_campaign (campaign_id),
                KEY idx_marketing_sync_tenancy (tenancy_id),
                KEY idx_marketing_sync_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $checked = true;
    }

    public static function listForUser(array $user): array
    {
        self::ensureSchema();

        $where = self::scopeWhere($user, 'mc', false);
        $params = self::scopeParams($user);
        $where .= ' AND mc.deleted_at IS NULL';

        return (new Database('marketing_campaigns mc'))
            ->select($where, $params, 'mc.updated_at DESC, mc.id DESC', '', [
                'mc.*',
                '(SELECT COUNT(*) FROM marketing_campaign_assets ma WHERE ma.campaign_id = mc.id) AS asset_count',
                '(SELECT COUNT(*) FROM marketing_campaign_history mh WHERE mh.campaign_id = mc.id) AS history_count',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function summarizeForUser(array $user): array
    {
        self::ensureSchema();

        $where = self::scopeWhere($user, 'mc', false) . ' AND mc.deleted_at IS NULL';
        $params = self::scopeParams($user);

        $row = (new Database())->execute(
            "SELECT
                COUNT(*) AS total_campaigns,
                SUM(CASE WHEN mc.status IN ('draft', 'planned', 'review') THEN 1 ELSE 0 END) AS planned_campaigns,
                SUM(CASE WHEN mc.status = 'active' THEN 1 ELSE 0 END) AS active_campaigns,
                SUM(CASE WHEN mc.status = 'paused' THEN 1 ELSE 0 END) AS paused_campaigns,
                SUM(CASE WHEN mc.status = 'archived' THEN 1 ELSE 0 END) AS archived_campaigns,
                SUM(COALESCE(mm.impressions, 0)) AS impressions,
                SUM(COALESCE(mm.clicks, 0)) AS clicks,
                SUM(COALESCE(mm.cost_per_click, 0)) AS cost_per_click,
                SUM(COALESCE(mm.leads, 0)) AS leads,
                SUM(COALESCE(mm.conversations_started, 0)) AS conversations_started,
                SUM(COALESCE(mm.investment_total, 0)) AS investment_total,
                SUM(COALESCE(mm.conversions, 0)) AS conversions,
                SUM(COALESCE(mm.estimated_return, 0)) AS estimated_return
             FROM marketing_campaigns mc
             LEFT JOIN marketing_campaign_metrics mm
               ON mm.campaign_id = mc.id
              AND mm.tenancy_id = mc.tenancy_id
             WHERE {$where}",
            $params
        )->fetch(PDO::FETCH_ASSOC);

        return $row ?: [];
    }

    public static function getForUser(int $id, array $user): ?array
    {
        self::ensureSchema();

        $params = self::scopeParams($user);
        $params[':id'] = $id;
        $where = self::scopeWhere($user, 'mc') . ' AND mc.deleted_at IS NULL';

        $row = (new Database())->execute(
            "SELECT mc.*,
                    mb.budget_daily AS budget_daily_row,
                    mb.budget_total AS budget_total_row,
                    maud.audience_name,
                    maud.interests_json,
                    maud.details_json,
                    mm.impressions,
                    mm.clicks,
                    mm.cost_per_click,
                    mm.leads,
                    mm.conversations_started,
                    mm.investment_total,
                    mm.conversions,
                    mm.estimated_return
             FROM marketing_campaigns mc
             LEFT JOIN marketing_campaign_budgets mb
               ON mb.campaign_id = mc.id
              AND mb.tenancy_id = mc.tenancy_id
             LEFT JOIN marketing_campaign_audiences maud
               ON maud.campaign_id = mc.id
              AND maud.tenancy_id = mc.tenancy_id
             LEFT JOIN marketing_campaign_metrics mm
               ON mm.campaign_id = mc.id
              AND mm.tenancy_id = mc.tenancy_id
             WHERE mc.id = :id
               AND {$where}
             LIMIT 1",
            $params
        )->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['assets'] = self::listAssets((int)$row['id'], (string)$row['tenancy_id']);
        $row['history'] = self::listHistory((int)$row['id'], (string)$row['tenancy_id']);
        $row['interests_list'] = self::decodeJsonArray($row['interests_json'] ?? null);

        return $row;
    }

    public static function createCampaign(array $values): int
    {
        self::ensureSchema();

        return (int)(new Database('marketing_campaigns'))->insert($values);
    }

    public static function updateCampaign(int $id, array $values, array $user): bool
    {
        self::ensureSchema();

        $params = self::scopeParams($user);
        $params[':id'] = $id;

        return (bool)(new Database('marketing_campaigns'))->update(
            'id = :id AND deleted_at IS NULL AND (' . self::scopeWhere($user, '', false) . ')',
            $values,
            $params
        );
    }

    public static function updateStatus(int $id, string $status, array $user, array $extra = []): bool
    {
        $values = [
            'status' => $status,
            'updated_by' => (int)($user['id'] ?? 0),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        foreach ($extra as $key => $value) {
            $values[$key] = $value;
        }

        return self::updateCampaign($id, $values, $user);
    }

    public static function deleteSafely(int $id, array $user): bool
    {
        return self::updateCampaign($id, [
            'status' => 'deleted',
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_by' => (int)($user['id'] ?? 0),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $user);
    }

    public static function upsertBudget(int $campaignId, string $tenancyId, array $data): void
    {
        self::ensureSchema();

        $existing = (new Database('marketing_campaign_budgets'))
            ->select('campaign_id = :campaign_id AND tenancy_id = :tenancy_id', [
                ':campaign_id' => $campaignId,
                ':tenancy_id' => $tenancyId,
            ], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        $values = [
            'campaign_id' => $campaignId,
            'tenancy_id' => $tenancyId,
            'budget_daily' => $data['budget_daily'] ?? 0,
            'budget_total' => $data['budget_total'] ?? 0,
            'currency_code' => $data['currency_code'] ?? 'BRL',
            'budget_status' => $data['budget_status'] ?? 'planned',
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            (new Database('marketing_campaign_budgets'))->update(
                'id = :id',
                $values,
                [':id' => (int)$existing['id']]
            );
            return;
        }

        $values['created_at'] = date('Y-m-d H:i:s');
        (new Database('marketing_campaign_budgets'))->insert($values);
    }

    public static function upsertAudience(int $campaignId, string $tenancyId, array $data): void
    {
        self::ensureSchema();

        $existing = (new Database('marketing_campaign_audiences'))
            ->select('campaign_id = :campaign_id AND tenancy_id = :tenancy_id', [
                ':campaign_id' => $campaignId,
                ':tenancy_id' => $tenancyId,
            ], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        $values = [
            'campaign_id' => $campaignId,
            'tenancy_id' => $tenancyId,
            'audience_name' => $data['audience_name'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'age_start' => $data['age_start'] ?? null,
            'age_end' => $data['age_end'] ?? null,
            'interests_json' => self::encodeJson($data['interests'] ?? []),
            'details_json' => self::encodeJson($data['details'] ?? []),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            (new Database('marketing_campaign_audiences'))->update(
                'id = :id',
                $values,
                [':id' => (int)$existing['id']]
            );
            return;
        }

        $values['created_at'] = date('Y-m-d H:i:s');
        (new Database('marketing_campaign_audiences'))->insert($values);
    }

    public static function upsertMetrics(int $campaignId, string $tenancyId, array $data): void
    {
        self::ensureSchema();

        $existing = (new Database('marketing_campaign_metrics'))
            ->select('campaign_id = :campaign_id AND tenancy_id = :tenancy_id', [
                ':campaign_id' => $campaignId,
                ':tenancy_id' => $tenancyId,
            ], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        $values = [
            'campaign_id' => $campaignId,
            'tenancy_id' => $tenancyId,
            'impressions' => $data['impressions'] ?? 0,
            'clicks' => $data['clicks'] ?? 0,
            'cost_per_click' => $data['cost_per_click'] ?? 0,
            'leads' => $data['leads'] ?? 0,
            'conversations_started' => $data['conversations_started'] ?? 0,
            'investment_total' => $data['investment_total'] ?? 0,
            'conversions' => $data['conversions'] ?? 0,
            'estimated_return' => $data['estimated_return'] ?? 0,
            'sync_source' => $data['sync_source'] ?? 'manual',
            'snapshot_date' => $data['snapshot_date'] ?? null,
            'last_synced_at' => $data['last_synced_at'] ?? null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            (new Database('marketing_campaign_metrics'))->update(
                'id = :id',
                $values,
                [':id' => (int)$existing['id']]
            );
            return;
        }

        $values['created_at'] = date('Y-m-d H:i:s');
        (new Database('marketing_campaign_metrics'))->insert($values);
    }

    public static function createAsset(array $values): int
    {
        self::ensureSchema();
        return (int)(new Database('marketing_campaign_assets'))->insert($values);
    }

    public static function listAssets(int $campaignId, string $tenancyId): array
    {
        self::ensureSchema();

        return (new Database('marketing_campaign_assets'))
            ->select('campaign_id = :campaign_id AND tenancy_id = :tenancy_id', [
                ':campaign_id' => $campaignId,
                ':tenancy_id' => $tenancyId,
            ], 'id DESC')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function createHistory(array $values): int
    {
        self::ensureSchema();
        return (int)(new Database('marketing_campaign_history'))->insert($values);
    }

    public static function listHistory(int $campaignId, string $tenancyId): array
    {
        self::ensureSchema();

        return (new Database('marketing_campaign_history'))
            ->select('campaign_id = :campaign_id AND tenancy_id = :tenancy_id', [
                ':campaign_id' => $campaignId,
                ':tenancy_id' => $tenancyId,
            ], 'id DESC', '25')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function upsertMetaSettings(string $tenancyId, array $values): void
    {
        self::ensureSchema();

        $existing = (new Database('marketing_meta_settings'))
            ->select('tenancy_id = :tenancy_id', [':tenancy_id' => $tenancyId], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            (new Database('marketing_meta_settings'))->update(
                'id = :id',
                $values,
                [':id' => (int)$existing['id']]
            );
            return;
        }

        $values['tenancy_id'] = $tenancyId;
        $values['created_at'] = date('Y-m-d H:i:s');
        (new Database('marketing_meta_settings'))->insert($values);
    }

    public static function getMetaSettings(string $tenancyId): ?array
    {
        self::ensureSchema();

        $row = (new Database('marketing_meta_settings'))
            ->select('tenancy_id = :tenancy_id', [':tenancy_id' => $tenancyId], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function createSyncLog(array $values): int
    {
        self::ensureSchema();
        return (int)(new Database('marketing_meta_sync_logs'))->insert($values);
    }

    private static function scopeWhere(array $user, string $alias = 'mc', bool $withId = true): string
    {
        if (TenancyHelper::isSuperAdmin($user)) {
            return '1=1';
        }

        $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';
        $where = $prefix . 'tenancy_id = :tenancy_id';

        return $withId ? $where : $where;
    }

    private static function scopeParams(array $user): array
    {
        if (TenancyHelper::isSuperAdmin($user)) {
            return [];
        }

        return [
            ':tenancy_id' => (string)($user['tenancy_id'] ?? ''),
        ];
    }

    private static function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $parts = preg_split('/[\r\n,;]+/', $trimmed) ?: [];
            $parts = array_values(array_filter(array_map(static fn ($item) => trim((string)$item), $parts), static fn ($item) => $item !== ''));
            return json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
