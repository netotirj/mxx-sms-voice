<?php

namespace App\Service;

use App\Model\Entity\MarketingCampaign;
use App\Model\Entity\WhatsAppAccount;
use App\Utils\TenancyHelper;

class MarketingCampaignService
{
    public static function listForDashboard(array $user): array
    {
        MarketingCampaign::ensureSchema();

        $campaigns = MarketingCampaign::listForUser($user);
        $summary = MarketingCampaign::summarizeForUser($user);
        $settings = self::getMetaSettings($user);

        return [
            'summary' => [
                'total_campaigns' => (int)($summary['total_campaigns'] ?? 0),
                'planned_campaigns' => (int)($summary['planned_campaigns'] ?? 0),
                'active_campaigns' => (int)($summary['active_campaigns'] ?? 0),
                'paused_campaigns' => (int)($summary['paused_campaigns'] ?? 0),
                'archived_campaigns' => (int)($summary['archived_campaigns'] ?? 0),
                'impressions' => (int)($summary['impressions'] ?? 0),
                'clicks' => (int)($summary['clicks'] ?? 0),
                'cost_per_click' => (float)($summary['cost_per_click'] ?? 0),
                'leads' => (int)($summary['leads'] ?? 0),
                'conversations_started' => (int)($summary['conversations_started'] ?? 0),
                'investment_total' => (float)($summary['investment_total'] ?? 0),
                'conversions' => (int)($summary['conversions'] ?? 0),
                'estimated_return' => (float)($summary['estimated_return'] ?? 0),
            ],
            'campaigns' => array_map(static fn (array $row): array => self::presentCampaignRow($row), $campaigns),
            'settings' => $settings,
            'available_accounts' => self::availableAccounts($user),
        ];
    }

    public static function getCampaign(int $id, array $user): array
    {
        $campaign = MarketingCampaign::getForUser($id, $user);
        if (!$campaign) {
            throw new \RuntimeException('Campanha não encontrada.');
        }

        return self::presentCampaignDetail($campaign);
    }

    public static function saveCampaign(array $user, array $input, array $files = [], ?int $campaignId = null): array
    {
        MarketingCampaign::ensureSchema();

        $payload = self::sanitizeCampaignPayload($input, $user);
        $isCreate = $campaignId === null;
        $now = date('Y-m-d H:i:s');

        if ($isCreate) {
            $campaignId = MarketingCampaign::createCampaign([
                'tenancy_id' => (string)($user['tenancy_id'] ?? ''),
                'created_by' => (int)($user['id'] ?? 0),
                'updated_by' => (int)($user['id'] ?? 0),
                'name' => $payload['name'],
                'description' => $payload['description'],
                'objective' => $payload['objective'],
                'campaign_type' => $payload['campaign_type'],
                'primary_text' => $payload['primary_text'],
                'ad_title' => $payload['ad_title'],
                'call_to_action' => $payload['call_to_action'],
                'destination_link' => $payload['destination_link'],
                'whatsapp_number' => $payload['whatsapp_number'],
                'city' => $payload['city'],
                'state' => $payload['state'],
                'age_start' => $payload['age_start'],
                'age_end' => $payload['age_end'],
                'interests' => $payload['interests_text'],
                'budget_daily' => $payload['budget_daily'],
                'budget_total' => $payload['budget_total'],
                'start_date' => $payload['start_date'],
                'end_date' => $payload['end_date'],
                'status' => $payload['status'],
                'thumbnail_path' => null,
                'video_path' => null,
                'account_id' => $payload['account_id'],
                'meta_campaign_id' => null,
                'meta_adset_id' => null,
                'meta_ad_id' => null,
                'meta_creative_id' => null,
                'sync_status' => 'pending',
                'sync_response_raw' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            self::logHistory($campaignId, $user, 'campaign.created', 'Campanha criada', [
                'name' => $payload['name'],
                'status' => $payload['status'],
            ]);
        } else {
            $existing = MarketingCampaign::getForUser($campaignId, $user);
            if (!$existing) {
                throw new \RuntimeException('Campanha não encontrada para edição.');
            }

            MarketingCampaign::updateCampaign($campaignId, [
                'updated_by' => (int)($user['id'] ?? 0),
                'name' => $payload['name'],
                'description' => $payload['description'],
                'objective' => $payload['objective'],
                'campaign_type' => $payload['campaign_type'],
                'primary_text' => $payload['primary_text'],
                'ad_title' => $payload['ad_title'],
                'call_to_action' => $payload['call_to_action'],
                'destination_link' => $payload['destination_link'],
                'whatsapp_number' => $payload['whatsapp_number'],
                'city' => $payload['city'],
                'state' => $payload['state'],
                'age_start' => $payload['age_start'],
                'age_end' => $payload['age_end'],
                'interests' => $payload['interests_text'],
                'budget_daily' => $payload['budget_daily'],
                'budget_total' => $payload['budget_total'],
                'start_date' => $payload['start_date'],
                'end_date' => $payload['end_date'],
                'status' => $payload['status'],
                'account_id' => $payload['account_id'],
                'updated_at' => $now,
            ], $user);

            self::logHistory($campaignId, $user, 'campaign.updated', 'Campanha atualizada', [
                'name' => $payload['name'],
                'status' => $payload['status'],
            ]);
        }

        MarketingCampaign::upsertBudget($campaignId, (string)$user['tenancy_id'], [
            'budget_daily' => $payload['budget_daily'],
            'budget_total' => $payload['budget_total'],
            'currency_code' => 'BRL',
            'budget_status' => $payload['status'],
        ]);

        MarketingCampaign::upsertAudience($campaignId, (string)$user['tenancy_id'], [
            'audience_name' => $payload['audience_name'],
            'city' => $payload['city'],
            'state' => $payload['state'],
            'age_start' => $payload['age_start'],
            'age_end' => $payload['age_end'],
            'interests' => $payload['interests_list'],
            'details' => [
                'objective' => $payload['objective'],
                'campaign_type' => $payload['campaign_type'],
            ],
        ]);

        MarketingCampaign::upsertMetrics($campaignId, (string)$user['tenancy_id'], [
            'sync_source' => 'manual',
            'last_synced_at' => null,
        ]);

        if (isset($files['video_file']) && is_array($files['video_file']) && (int)($files['video_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            self::attachAsset($campaignId, $user, $files['video_file'], 'video');
        }

        if (isset($files['thumbnail_file']) && is_array($files['thumbnail_file']) && (int)($files['thumbnail_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            self::attachAsset($campaignId, $user, $files['thumbnail_file'], 'thumbnail');
        }

        return self::getCampaign($campaignId, $user);
    }

    public static function attachAsset(int $campaignId, array $user, array $file, string $assetType): array
    {
        $campaign = MarketingCampaign::getForUser($campaignId, $user);
        if (!$campaign) {
            throw new \RuntimeException('Campanha não encontrada para upload.');
        }

        $stored = MarketingMediaService::storeUpload($user, $campaignId, $file, $assetType);
        MarketingCampaign::createAsset([
            'campaign_id' => $campaignId,
            'tenancy_id' => (string)$user['tenancy_id'],
            'asset_type' => $stored['asset_type'],
            'original_name' => $stored['original_name'],
            'storage_path' => $stored['storage_path'],
            'public_path' => $stored['public_path'],
            'mime_type' => $stored['mime_type'],
            'extension' => $stored['extension'],
            'size_bytes' => $stored['size_bytes'],
            'duration_seconds' => $stored['duration_seconds'],
            'width' => $stored['width'],
            'height' => $stored['height'],
            'generated_thumbnail_path' => $stored['generated_thumbnail_path'],
            'metadata_json' => $stored['metadata_json'],
            'uploaded_by' => (int)($user['id'] ?? 0),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $update = [
            'updated_by' => (int)($user['id'] ?? 0),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($assetType === 'video') {
            $update['video_path'] = $stored['public_path'];
            if (!empty($stored['generated_thumbnail_path']) && empty($campaign['thumbnail_path'])) {
                $update['thumbnail_path'] = $stored['generated_thumbnail_path'];
            }
        } else {
            $update['thumbnail_path'] = $stored['public_path'];
        }

        MarketingCampaign::updateCampaign($campaignId, $update, $user);

        self::logHistory($campaignId, $user, 'campaign.asset_uploaded', 'Arquivo enviado para campanha', [
            'asset_type' => $assetType,
            'file' => $stored['original_name'],
        ]);

        return [
            'asset_type' => $stored['asset_type'],
            'public_url' => MarketingMediaService::publicUrl($stored['public_path']),
            'generated_thumbnail_url' => !empty($stored['generated_thumbnail_path'])
                ? MarketingMediaService::publicUrl($stored['generated_thumbnail_path'])
                : null,
        ];
    }

    public static function changeStatus(int $campaignId, array $user, string $status): array
    {
        $campaign = MarketingCampaign::getForUser($campaignId, $user);
        if (!$campaign) {
            throw new \RuntimeException('Campanha não encontrada.');
        }

        $normalized = self::normalizeStatus($status);
        $extra = [];

        if ($normalized === 'archived') {
            $extra['archived_at'] = date('Y-m-d H:i:s');
        }

        MarketingCampaign::updateStatus($campaignId, $normalized, $user, $extra);
        self::logHistory($campaignId, $user, 'campaign.status_changed', 'Status alterado', [
            'status' => $normalized,
        ]);

        return self::getCampaign($campaignId, $user);
    }

    public static function deleteCampaign(int $campaignId, array $user): void
    {
        $campaign = MarketingCampaign::getForUser($campaignId, $user);
        if (!$campaign) {
            throw new \RuntimeException('Campanha não encontrada.');
        }

        MarketingCampaign::deleteSafely($campaignId, $user);
        self::logHistory($campaignId, $user, 'campaign.deleted', 'Campanha excluída com segurança', [
            'name' => $campaign['name'] ?? '',
        ]);
    }

    public static function getMetaSettings(array $user): array
    {
        $tenancyId = self::resolveSettingsTenancy($user);
        $settings = MarketingCampaign::getMetaSettings($tenancyId);

        return [
            'meta_ad_account_id' => (string)($settings['meta_ad_account_id'] ?? ''),
            'meta_pixel_id' => (string)($settings['meta_pixel_id'] ?? ''),
            'meta_page_id' => (string)($settings['meta_page_id'] ?? ''),
            'whatsapp_destination' => (string)($settings['whatsapp_destination'] ?? ''),
            'meta_business_id' => (string)($settings['meta_business_id'] ?? ''),
            'connection_status' => (string)($settings['connection_status'] ?? 'not_configured'),
            'last_connection_check_at' => (string)($settings['last_connection_check_at'] ?? ''),
            'last_error' => (string)($settings['last_error'] ?? ''),
            'has_access_token' => trim((string)($settings['meta_access_token'] ?? '')) !== '',
            'access_token_masked' => self::maskToken((string)($settings['meta_access_token'] ?? '')),
        ];
    }

    public static function saveMetaSettings(array $user, array $input): array
    {
        $tenancyId = self::resolveSettingsTenancy($user);
        $existing = MarketingCampaign::getMetaSettings($tenancyId) ?: [];
        $newToken = trim((string)($input['meta_access_token'] ?? ''));
        $tokenToPersist = $newToken !== '' ? $newToken : (string)($existing['meta_access_token'] ?? '');
        $adAccountId = self::nullableString($input['meta_ad_account_id'] ?? null);
        $connectionStatus = ($tokenToPersist !== '' && $adAccountId !== null) ? 'configured' : 'not_configured';

        MarketingCampaign::upsertMetaSettings($tenancyId, [
            'meta_access_token' => $tokenToPersist !== '' ? $tokenToPersist : null,
            'meta_ad_account_id' => $adAccountId,
            'meta_pixel_id' => self::nullableString($input['meta_pixel_id'] ?? null),
            'meta_page_id' => self::nullableString($input['meta_page_id'] ?? null),
            'whatsapp_destination' => self::nullableString($input['whatsapp_destination'] ?? null),
            'meta_business_id' => self::nullableString($input['meta_business_id'] ?? null),
            'connection_status' => $connectionStatus,
            'last_error' => null,
            'created_by' => (int)($existing['created_by'] ?? $user['id'] ?? 0),
            'updated_by' => (int)($user['id'] ?? 0),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        self::logHistory(null, $user, 'meta.settings_saved', 'Configurações da Meta salvas', [
            'tenancy_id' => $tenancyId,
        ]);

        return self::getMetaSettings($user);
    }

    public static function testMetaSettings(array $user): array
    {
        $tenancyId = self::resolveSettingsTenancy($user);
        $settings = MarketingCampaign::getMetaSettings($tenancyId);
        if (!$settings) {
            throw new \RuntimeException('Configurações da Meta ainda não foram salvas.');
        }

        $result = MarketingMetaAdsService::testConnection($settings);

        MarketingCampaign::upsertMetaSettings($tenancyId, [
            'meta_access_token' => (string)($settings['meta_access_token'] ?? ''),
            'meta_ad_account_id' => self::nullableString($settings['meta_ad_account_id'] ?? null),
            'meta_pixel_id' => self::nullableString($settings['meta_pixel_id'] ?? null),
            'meta_page_id' => self::nullableString($settings['meta_page_id'] ?? null),
            'whatsapp_destination' => self::nullableString($settings['whatsapp_destination'] ?? null),
            'meta_business_id' => self::nullableString($settings['meta_business_id'] ?? null),
            'connection_status' => $result['success'] ? 'connected' : 'error',
            'last_connection_check_at' => date('Y-m-d H:i:s'),
            'last_error' => $result['success'] ? null : (string)$result['message'],
            'created_by' => (int)($settings['created_by'] ?? $user['id'] ?? 0),
            'updated_by' => (int)($user['id'] ?? 0),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        MarketingCampaign::createSyncLog([
            'campaign_id' => null,
            'tenancy_id' => $tenancyId,
            'user_id' => (int)($user['id'] ?? 0),
            'action' => 'meta_connection_test',
            'request_payload' => json_encode([
                'meta_ad_account_id' => (string)($settings['meta_ad_account_id'] ?? ''),
                'meta_page_id' => (string)($settings['meta_page_id'] ?? ''),
                'meta_pixel_id' => (string)($settings['meta_pixel_id'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_payload' => json_encode($result['response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'success' => $result['success'] ? 1 : 0,
            'status_code' => (int)($result['status_code'] ?? 0),
            'error_message' => $result['success'] ? null : (string)($result['message'] ?? 'Falha no teste de conexão com a Meta.'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        self::logHistory(null, $user, 'meta.connection_tested', 'Teste de conexão Meta executado', [
            'success' => $result['success'],
            'status_code' => $result['status_code'],
        ]);

        return $result;
    }

    public static function prepareMetaSyncPreview(int $campaignId, array $user): array
    {
        $campaign = MarketingCampaign::getForUser($campaignId, $user);
        if (!$campaign) {
            throw new \RuntimeException('Campanha não encontrada.');
        }

        $settings = MarketingCampaign::getMetaSettings(self::resolveSettingsTenancy($user)) ?: [];
        $preview = MarketingMetaAdsService::buildSyncPreview($campaign, $settings);

        MarketingCampaign::updateCampaign($campaignId, [
            'sync_status' => $preview['ready'] ? 'ready' : 'error',
            'sync_response_raw' => json_encode([
                'ready' => $preview['ready'],
                'errors' => $preview['errors'],
                'payload' => $preview['payload'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_by' => (int)($user['id'] ?? 0),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $user);

        MarketingCampaign::createSyncLog([
            'campaign_id' => $campaignId,
            'tenancy_id' => (string)$campaign['tenancy_id'],
            'user_id' => (int)($user['id'] ?? 0),
            'action' => 'sync_preview',
            'request_payload' => json_encode($preview['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_payload' => json_encode(['ready' => $preview['ready'], 'errors' => $preview['errors']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'success' => $preview['ready'] ? 1 : 0,
            'status_code' => $preview['ready'] ? 200 : 422,
            'error_message' => $preview['ready'] ? null : implode(' | ', $preview['errors']),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        self::logHistory($campaignId, $user, 'meta.sync_preview', 'Prévia de integração Meta gerada', [
            'ready' => $preview['ready'],
            'errors' => $preview['errors'],
        ]);

        return $preview;
    }

    public static function canManageModule(array $user): bool
    {
        $role = strtolower(trim((string)($user['user_function'] ?? $user['function'] ?? '')));
        return in_array($role, ['admin', 'super_admin', 'developer'], true);
    }

    private static function sanitizeCampaignPayload(array $input, array $user): array
    {
        $name = trim((string)($input['name'] ?? $input['campaign_name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Informe o nome da campanha.');
        }

        $objective = self::sanitizeEnum(
            (string)($input['objective'] ?? ''),
            ['lead_generation', 'whatsapp', 'sell_plan', 'schedule_demo', 'website_traffic'],
            'lead_generation'
        );

        $status = self::normalizeStatus((string)($input['status'] ?? 'draft'));
        $campaignType = self::sanitizeEnum(
            (string)($input['campaign_type'] ?? 'video'),
            ['video', 'video_whatsapp', 'video_leads', 'video_sales', 'video_traffic'],
            'video'
        );

        $ageStart = self::sanitizeInteger($input['age_start'] ?? $input['faixa_etaria_inicial'] ?? null);
        $ageEnd = self::sanitizeInteger($input['age_end'] ?? $input['faixa_etaria_final'] ?? null);
        if ($ageStart !== null && $ageEnd !== null && $ageStart > $ageEnd) {
            throw new \InvalidArgumentException('A faixa etária inicial não pode ser maior que a final.');
        }

        $startDate = self::sanitizeDate($input['start_date'] ?? $input['data_inicial'] ?? null);
        $endDate = self::sanitizeDate($input['end_date'] ?? $input['data_final'] ?? null);
        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            throw new \InvalidArgumentException('A data inicial não pode ser maior que a data final.');
        }

        $destinationLink = self::sanitizeUrl($input['destination_link'] ?? $input['link_destino'] ?? null);
        $whatsappNumber = self::sanitizePhone($input['whatsapp_number'] ?? $input['numero_whatsapp_destino'] ?? null);

        $interestsList = self::normalizeTags($input['interests'] ?? $input['interesses'] ?? []);
        $accountId = self::nullableString($input['account_id'] ?? null);

        return [
            'name' => mb_substr($name, 0, 160),
            'description' => self::nullableText($input['description'] ?? null),
            'objective' => $objective,
            'campaign_type' => $campaignType,
            'primary_text' => self::nullableText($input['primary_text'] ?? $input['texto_principal'] ?? null),
            'ad_title' => self::nullableString($input['ad_title'] ?? $input['titulo_anuncio'] ?? null, 160),
            'call_to_action' => self::nullableString($input['call_to_action'] ?? $input['chamada_para_acao'] ?? null, 80),
            'destination_link' => $destinationLink,
            'whatsapp_number' => $whatsappNumber,
            'city' => self::nullableString($input['city'] ?? $input['cidade'] ?? null, 120),
            'state' => self::nullableString($input['state'] ?? $input['estado'] ?? null, 80),
            'age_start' => $ageStart,
            'age_end' => $ageEnd,
            'interests_list' => $interestsList,
            'interests_text' => implode(', ', $interestsList),
            'budget_daily' => self::sanitizeMoney($input['budget_daily'] ?? $input['orcamento_diario'] ?? null),
            'budget_total' => self::sanitizeMoney($input['budget_total'] ?? $input['orcamento_total'] ?? null),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $status,
            'audience_name' => self::nullableString($input['audience_name'] ?? $input['publico_alvo'] ?? null, 160),
            'account_id' => $accountId,
            'created_by' => (int)($user['id'] ?? 0),
        ];
    }

    private static function presentCampaignRow(array $row): array
    {
        $objectiveLabels = [
            'lead_generation' => 'Gerar leads',
            'whatsapp' => 'Mandar para WhatsApp',
            'sell_plan' => 'Vender plano',
            'schedule_demo' => 'Agendar demonstração',
            'website_traffic' => 'Tráfego para site',
        ];

        return [
            'id' => (int)$row['id'],
            'name' => (string)($row['name'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'status' => (string)($row['status'] ?? 'draft'),
            'status_label' => self::statusLabel((string)($row['status'] ?? 'draft')),
            'campaign_type' => (string)($row['campaign_type'] ?? 'video'),
            'video_path' => (string)($row['video_path'] ?? ''),
            'video_url' => trim((string)($row['video_path'] ?? '')) !== '' ? MarketingMediaService::publicUrl((string)$row['video_path']) : '',
            'thumbnail_path' => (string)($row['thumbnail_path'] ?? ''),
            'thumbnail_url' => trim((string)($row['thumbnail_path'] ?? '')) !== '' ? MarketingMediaService::publicUrl((string)$row['thumbnail_path']) : '',
            'budget_daily' => (float)($row['budget_daily'] ?? 0),
            'budget_total' => (float)($row['budget_total'] ?? 0),
            'audience' => self::buildAudienceSummary($row),
            'audience_name' => (string)($row['audience_name'] ?? ''),
            'objective' => (string)($objectiveLabels[$row['objective'] ?? ''] ?? ($row['objective'] ?? '')),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'sync_status' => (string)($row['sync_status'] ?? 'pending'),
            'meta_campaign_id' => (string)($row['meta_campaign_id'] ?? ''),
            'asset_count' => (int)($row['asset_count'] ?? 0),
        ];
    }

    private static function presentCampaignDetail(array $row): array
    {
        $campaign = self::presentCampaignRow($row);
        $campaign += [
            'description' => (string)($row['description'] ?? ''),
            'primary_text' => (string)($row['primary_text'] ?? ''),
            'ad_title' => (string)($row['ad_title'] ?? ''),
            'call_to_action' => (string)($row['call_to_action'] ?? ''),
            'destination_link' => (string)($row['destination_link'] ?? ''),
            'whatsapp_number' => (string)($row['whatsapp_number'] ?? ''),
            'audience_name' => (string)($row['audience_name'] ?? ''),
            'city' => (string)($row['city'] ?? ''),
            'state' => (string)($row['state'] ?? ''),
            'age_start' => $row['age_start'] !== null ? (int)$row['age_start'] : null,
            'age_end' => $row['age_end'] !== null ? (int)$row['age_end'] : null,
            'interests' => (string)($row['interests'] ?? ''),
            'interests_list' => is_array($row['interests_list'] ?? null) ? $row['interests_list'] : self::normalizeTags((string)($row['interests'] ?? '')),
            'budget_daily' => (float)($row['budget_daily'] ?? $row['budget_daily_row'] ?? 0),
            'budget_total' => (float)($row['budget_total'] ?? $row['budget_total_row'] ?? 0),
            'start_date' => (string)($row['start_date'] ?? ''),
            'end_date' => (string)($row['end_date'] ?? ''),
            'account_id' => (string)($row['account_id'] ?? ''),
            'meta_campaign_id' => (string)($row['meta_campaign_id'] ?? ''),
            'meta_adset_id' => (string)($row['meta_adset_id'] ?? ''),
            'meta_ad_id' => (string)($row['meta_ad_id'] ?? ''),
            'meta_creative_id' => (string)($row['meta_creative_id'] ?? ''),
            'sync_status' => (string)($row['sync_status'] ?? 'pending'),
            'account_context' => self::resolveAccountContext((string)($row['account_id'] ?? ''), $row),
            'impressions' => (int)($row['impressions'] ?? 0),
            'clicks' => (int)($row['clicks'] ?? 0),
            'cost_per_click' => (float)($row['cost_per_click'] ?? 0),
            'leads' => (int)($row['leads'] ?? 0),
            'conversations_started' => (int)($row['conversations_started'] ?? 0),
            'investment_total' => (float)($row['investment_total'] ?? 0),
            'conversions' => (int)($row['conversions'] ?? 0),
            'estimated_return' => (float)($row['estimated_return'] ?? 0),
            'assets' => array_map(static function (array $asset): array {
                return [
                    'id' => (int)$asset['id'],
                    'asset_type' => (string)($asset['asset_type'] ?? ''),
                    'original_name' => (string)($asset['original_name'] ?? ''),
                    'public_path' => (string)($asset['public_path'] ?? ''),
                    'public_url' => trim((string)($asset['public_path'] ?? '')) !== '' ? MarketingMediaService::publicUrl((string)$asset['public_path']) : '',
                    'generated_thumbnail_url' => trim((string)($asset['generated_thumbnail_path'] ?? '')) !== '' ? MarketingMediaService::publicUrl((string)$asset['generated_thumbnail_path']) : '',
                    'size_bytes' => (int)($asset['size_bytes'] ?? 0),
                    'mime_type' => (string)($asset['mime_type'] ?? ''),
                    'created_at' => (string)($asset['created_at'] ?? ''),
                ];
            }, (array)($row['assets'] ?? [])),
            'history' => array_map(static function (array $history): array {
                return [
                    'id' => (int)$history['id'],
                    'action' => (string)($history['action'] ?? ''),
                    'action_label' => (string)($history['action_label'] ?? ''),
                    'details_json' => (string)($history['details_json'] ?? ''),
                    'created_at' => (string)($history['created_at'] ?? ''),
                ];
            }, (array)($row['history'] ?? [])),
        ];

        return $campaign;
    }

    private static function resolveAccountContext(string $accountId, array $row = []): ?array
    {
        $accountId = trim($accountId);
        if ($accountId === '' || !ctype_digit($accountId)) {
            return null;
        }

        $account = WhatsAppAccount::getById((int)$accountId);
        if (!$account) {
            return null;
        }

        return [
            'id' => (int)($account['id'] ?? 0),
            'label' => (string)($account['internal_label'] ?? $account['label'] ?? ''),
            'display_name' => (string)($account['display_name_meta'] ?? $account['display_name'] ?? ''),
            'display_phone_number' => (string)($account['display_phone_number'] ?? ''),
            'tenancy_id' => (string)($account['tenancy_id'] ?? ($row['tenancy_id'] ?? '')),
        ];
    }

    private static function logHistory(?int $campaignId, array $user, string $action, string $label, array $details = []): void
    {
        MarketingCampaign::createHistory([
            'campaign_id' => $campaignId,
            'tenancy_id' => (string)($user['tenancy_id'] ?? 'global'),
            'user_id' => (int)($user['id'] ?? 0),
            'action' => $action,
            'action_label' => $label,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function resolveSettingsTenancy(array $user): string
    {
        return TenancyHelper::isSuperAdmin($user)
            ? ((string)($user['tenancy_id'] ?? 'global') ?: 'global')
            : (string)($user['tenancy_id'] ?? 'global');
    }

    private static function maskToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        if (strlen($token) <= 8) {
            return str_repeat('*', strlen($token));
        }

        return substr($token, 0, 4) . str_repeat('*', max(4, strlen($token) - 8)) . substr($token, -4);
    }

    private static function buildAudienceSummary(array $row): string
    {
        $parts = [];

        $audienceName = trim((string)($row['audience_name'] ?? ''));
        if ($audienceName !== '') {
            $parts[] = $audienceName;
        }

        $location = trim((string)($row['city'] ?? '') . ((string)($row['state'] ?? '') !== '' ? ' / ' . (string)$row['state'] : ''));
        if ($location !== '') {
            $parts[] = $location;
        }

        return implode(' | ', $parts);
    }

    private static function availableAccounts(array $user): array
    {
        $accounts = WhatsAppAccount::listForUser($user);

        return array_map(static function (array $account): array {
            $numberId = (int)($account['number_id'] ?? 0);
            $label = trim((string)($account['internal_label'] ?? ''));
            if ($label === '') {
                $label = trim((string)($account['label'] ?? ''));
            }

            $displayName = trim((string)($account['display_name_meta'] ?? ''));
            if ($displayName === '') {
                $displayName = trim((string)($account['display_name'] ?? ''));
            }

            return [
                'id' => (int)($account['id'] ?? 0),
                'number_id' => $numberId > 0 ? $numberId : null,
                'label' => $label,
                'display_name' => $displayName,
                'display_phone_number' => (string)($account['display_phone_number'] ?? ''),
                'status' => (string)($account['status'] ?? 'active'),
                'voice_status' => (string)($account['voice_status'] ?? ''),
            ];
        }, $accounts);
    }

    private static function normalizeStatus(string $status): string
    {
        return self::sanitizeEnum($status, ['draft', 'planned', 'review', 'active', 'paused', 'archived'], 'draft');
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'planned' => 'Planejada',
            'review' => 'Em revisão',
            'active' => 'Ativa',
            'paused' => 'Pausada',
            'archived' => 'Arquivada',
            default => 'Rascunho',
        };
    }

    private static function sanitizeEnum(string $value, array $allowed, string $default): string
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, $allowed, true) ? $normalized : $default;
    }

    private static function sanitizeInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int)$value);
    }

    private static function sanitizeMoney(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^0-9,.\-]/', '', (string)$value) ?? '0';
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
        return round((float)$normalized, 2);
    }

    private static function sanitizeDate(mixed $value): ?string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw, $matches)) {
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }

        return null;
    }

    private static function sanitizeUrl(mixed $value): ?string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        $valid = filter_var($raw, FILTER_VALIDATE_URL);
        if ($valid === false) {
            throw new \InvalidArgumentException('Informe um link de destino válido.');
        }

        return mb_substr($raw, 0, 500);
    }

    private static function sanitizePhone(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string)$value) ?? '';
        return $digits !== '' ? mb_substr($digits, 0, 30) : null;
    }

    private static function normalizeTags(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[\r\n,;]+/', (string)$value) ?: [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $text = trim((string)$item);
            if ($text === '') {
                continue;
            }

            $normalized[$text] = true;
        }

        return array_keys($normalized);
    }

    private static function nullableString(mixed $value, int $limit = 255): ?string
    {
        $text = trim((string)$value);
        return $text !== '' ? mb_substr($text, 0, $limit) : null;
    }

    private static function nullableText(mixed $value): ?string
    {
        $text = trim((string)$value);
        return $text !== '' ? $text : null;
    }
}
