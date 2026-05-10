<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Service\MarketingCampaignService;
use App\Model\Entity\MarketingCampaign;
use App\Session\User as SessionUser;
use App\Utils\View;

class Marketing extends ViewComponents
{
    public static function getAdminMarketing($request): Response|string
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!MarketingCampaignService::canManageModule($user)) {
            return new Response(403, 'Sem permissão para acessar a área de marketing.');
        }

        MarketingCampaign::ensureSchema();

        $content = View::render('/marketing/index', [
            'campaign_rows' => '<div class="rounded-2xl border border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">Carregando campanhas...</div>',
        ]);

        return parent::getComponentsUsers('Maxx Solutions | Marketing Administrativo', $content);
    }

    public static function dashboard(): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!MarketingCampaignService::canManageModule($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        return self::json(200, [
            'success' => true,
            'data' => MarketingCampaignService::listForDashboard($user),
        ]);
    }

    public static function show($request, int|string $id): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!MarketingCampaignService::canManageModule($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        try {
            return self::json(200, [
                'success' => true,
                'data' => MarketingCampaignService::getCampaign((int)$id, $user),
            ]);
        } catch (\Throwable $e) {
            return self::json(404, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function create($request): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!MarketingCampaignService::canManageModule($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        try {
            $campaign = MarketingCampaignService::saveCampaign($user, self::input(), $_FILES);
            return self::json(201, [
                'success' => true,
                'message' => 'Campanha criada com sucesso.',
                'data' => $campaign,
            ]);
        } catch (\Throwable $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function update($request, int|string $id): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!MarketingCampaignService::canManageModule($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        try {
            $campaign = MarketingCampaignService::saveCampaign($user, self::input(), $_FILES, (int)$id);
            return self::json(200, [
                'success' => true,
                'message' => 'Campanha atualizada com sucesso.',
                'data' => $campaign,
            ]);
        } catch (\Throwable $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function uploadAsset($request, int|string $id): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!MarketingCampaignService::canManageModule($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        $assetType = strtolower(trim((string)($_POST['asset_type'] ?? '')));
        $fileKey = $assetType === 'thumbnail' ? 'thumbnail_file' : 'video_file';

        if (!isset($_FILES[$fileKey])) {
            return self::json(422, ['success' => false, 'message' => 'Nenhum arquivo enviado.']);
        }

        try {
            $asset = MarketingCampaignService::attachAsset((int)$id, $user, $_FILES[$fileKey], $assetType);
            return self::json(200, [
                'success' => true,
                'message' => 'Arquivo enviado com sucesso.',
                'data' => $asset,
            ]);
        } catch (\Throwable $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function status($request, int|string $id): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!MarketingCampaignService::canManageModule($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        $input = self::input();
        try {
            $campaign = MarketingCampaignService::changeStatus((int)$id, $user, (string)($input['status'] ?? 'draft'));
            return self::json(200, [
                'success' => true,
                'message' => 'Status atualizado com sucesso.',
                'data' => $campaign,
            ]);
        } catch (\Throwable $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function pause($request, int|string $id): Response
    {
        return self::statusShortcut((int)$id, 'paused');
    }

    public static function activate($request, int|string $id): Response
    {
        return self::statusShortcut((int)$id, 'active');
    }

    public static function archive($request, int|string $id): Response
    {
        return self::statusShortcut((int)$id, 'archived');
    }

    public static function delete($request, int|string $id): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!MarketingCampaignService::canManageModule($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        try {
            MarketingCampaignService::deleteCampaign((int)$id, $user);
            return self::json(200, [
                'success' => true,
                'message' => 'Campanha excluída com segurança.',
            ]);
        } catch (\Throwable $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function settings(): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!MarketingCampaignService::canManageModule($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        return self::json(200, [
            'success' => true,
            'data' => MarketingCampaignService::getMetaSettings($user),
        ]);
    }

    public static function saveSettings(): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!MarketingCampaignService::canManageModule($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        try {
            return self::json(200, [
                'success' => true,
                'message' => 'Configurações da Meta salvas.',
                'data' => MarketingCampaignService::saveMetaSettings($user, self::input()),
            ]);
        } catch (\Throwable $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function testSettings(): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!MarketingCampaignService::canManageModule($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        try {
            $result = MarketingCampaignService::testMetaSettings($user);
            return self::json($result['success'] ? 200 : 422, [
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => $result['response'],
            ]);
        } catch (\Throwable $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function syncPreview($request, int|string $id): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!MarketingCampaignService::canManageModule($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        try {
            return self::json(200, [
                'success' => true,
                'message' => 'Prévia de integração preparada. Nenhuma campanha foi publicada na Meta.',
                'data' => MarketingCampaignService::prepareMetaSyncPreview((int)$id, $user),
            ]);
        } catch (\Throwable $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private static function statusShortcut(int $campaignId, string $status): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!MarketingCampaignService::canManageModule($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        try {
            $campaign = MarketingCampaignService::changeStatus($campaignId, $user, $status);
            return self::json(200, [
                'success' => true,
                'message' => 'Status atualizado com sucesso.',
                'data' => $campaign,
            ]);
        } catch (\Throwable $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private static function requireUser(): array|Response
    {
        $user = SessionUser::getLogged();
        if (!$user) {
            return self::json(401, ['success' => false, 'message' => 'Usuário não autenticado.']);
        }

        return $user;
    }

    private static function input(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '', true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return $_POST ?: [];
    }

    private static function json(int $status, array $payload): Response
    {
        return new Response($status, $payload, 'application/json');
    }
}
