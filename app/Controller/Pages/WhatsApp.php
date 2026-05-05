<?php

namespace App\Controller\Pages;


use App\Config\WhatsAppConfig;
use App\Http\Response;
use App\Model\Entity\WhatsAppAccount;
use App\Model\Entity\WhatsAppCampaign;
use App\Model\Entity\WhatsAppConversation;
use App\Model\Entity\WhatsAppOutbox;
use App\Model\Entity\WhatsAppTemplate;
use App\Service\MetaWhatsAppCloudApi;
use App\Service\WhatsAppBilling;
use App\Service\WhatsAppCostPolicy;
use App\Service\WhatsAppDefaultTemplateManager;
use App\Service\WhatsAppDynamicPricing;
use App\Service\WhatsAppMessagePlanner;
use App\Service\WhatsAppNumberManager;
use App\Service\WhatsAppNumberSafety;
use App\Service\WhatsAppOutboxWorker;
use App\Service\WhatsAppSupportDesk;
use App\Service\WhatsAppTemplateBlueprintLibrary;
use App\Service\WhatsAppTemplateVariableResolver;
use App\Session\User as SessionUser;
use App\Utils\View;
use PhpOffice\PhpSpreadsheet\IOFactory;

class WhatsApp extends ViewComponents
{
    public static function getComponentsWhatsApp(): Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/whatsapp/index', []);
        return parent::getComponentsUsers('Maxx Solutions - SMS | VOZ', $content);
    }

    public static function listAccounts(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        try {
            WhatsAppNumberSafety::syncAllForUser($obUser);
            self::syncBusinessProfilesForUser($obUser);
        } catch (\Throwable $e) {
            error_log('[whatsapp_accounts_sync] ' . $e->getMessage());
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppAccount::listForUser($obUser),
        ]);
    }

    public static function listNumbers(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppNumberManager::listForUser($obUser),
            'available_platform_numbers' => self::canManageWhatsAppNumbers($obUser)
                ? WhatsAppNumberManager::listAvailablePlatformNumbers()
                : [],
        ]);
    }

    public static function listNumberHealth(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppNumberSafety::listForUser($obUser),
        ]);
    }

    public static function syncNumberHealth(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'message' => 'Qualidade dos números atualizada.',
            'data' => WhatsAppNumberSafety::syncAllForUser($obUser),
        ]);
    }

    public static function registerClientNumber(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        try {
            $number = WhatsAppNumberManager::registerClientNumber($obUser, self::jsonInput());

            return self::json(201, [
                'success' => true,
                'message' => 'Solicitação recebida. O administrador fará a conexão do número.',
                'data' => $number,
            ]);
        } catch (\InvalidArgumentException $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return self::json(409, [
                'success' => false,
                'message' => $e->getMessage() ?: 'Não foi possível salvar a solicitação do número.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function listNumberRequests(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppNumberManager::listNumberRequests($obUser),
            'can_manage_numbers' => self::canManageWhatsAppNumbers($obUser),
        ]);
    }

    public static function approveNumberRequest($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        if (!self::canManageWhatsAppNumbers($obUser)) {
            return self::json(403, [
                'success' => false,
                'message' => 'Ação permitida apenas para administrador.',
            ]);
        }

        try {
            return self::json(200, [
                'success' => true,
                'message' => 'Solicitação aprovada. Número liberado para uso.',
                'data' => WhatsAppNumberManager::approveNumberRequest($obUser, (int)$id),
            ]);
        } catch (\Throwable $e) {
            return self::json(409, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function sendNumberRequestToMeta($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        if (!self::canManageWhatsAppNumbers($obUser)) {
            return self::json(403, [
                'success' => false,
                'message' => 'Ação permitida apenas para administrador.',
            ]);
        }

        try {
            return self::json(200, [
                'success' => true,
                'message' => 'Solicitação enviada para a Meta.',
                'data' => WhatsAppNumberManager::submitNumberRequestToMeta($obUser, (int)$id),
            ]);
        } catch (\Throwable $e) {
            return self::json(409, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function rejectNumberRequest($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        if (!self::canManageWhatsAppNumbers($obUser)) {
            return self::json(403, [
                'success' => false,
                'message' => 'Ação permitida apenas para administrador.',
            ]);
        }

        try {
            $input = self::jsonInput();
            return self::json(200, [
                'success' => true,
                'message' => 'Solicitação recusada.',
                'data' => WhatsAppNumberManager::rejectNumberRequest($obUser, (int)$id, (string)($input['reason'] ?? '')),
            ]);
        } catch (\Throwable $e) {
            return self::json(409, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function resendNumberRequestCode($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $input = self::jsonInput();

        try {
            return self::json(200, [
                'success' => true,
                'message' => 'Confirmação automática solicitada.',
                'data' => WhatsAppNumberManager::resendNumberRequestVerificationCode(
                    $obUser,
                    (int)$id,
                    (string)($input['method'] ?? 'SMS')
                ),
            ]);
        } catch (\Throwable $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function confirmNumberRequestCode($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $input = self::jsonInput();
        $code = $input['code'] ?? '';

        try {
            return self::json(200, [
                'success' => true,
                'message' => 'Código confirmado. Aguarde a aprovação final.',
                'data' => WhatsAppNumberManager::confirmNumberRequestVerificationCode(
                    $obUser,
                    (int)$id,
                    is_string($code) ? $code : ''
                ),
            ]);
        } catch (\InvalidArgumentException $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return self::json(409, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function sendNumberVerificationCode($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $input = self::jsonInput();
        try {
            if (!self::canManageWhatsAppNumbers($obUser)) {
                return self::json(403, [
                    'success' => false,
                    'message' => 'Ação permitida apenas para administrador.',
                ]);
            }

            $number = WhatsAppNumberManager::sendVerificationCode(
                $obUser,
                (int)$id,
                (string)($input['method'] ?? 'SMS')
            );

            return self::json(200, [
                'success' => true,
                'message' => 'Código solicitado na Meta. Aguarde a confirmação do cliente.',
                'data' => $number,
            ]);
        } catch (\Throwable $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function confirmNumberVerificationCode($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        try {
            if (!self::canManageWhatsAppNumbers($obUser)) {
                return self::json(403, [
                    'success' => false,
                    'message' => 'Ação permitida apenas para administrador.',
                ]);
            }

            $number = WhatsAppNumberManager::confirmVerificationCode(
                $obUser,
                (int)$id
            );

            return self::json(200, [
                'success' => true,
                'message' => 'Número conectado.',
                'data' => $number,
            ]);
        } catch (\InvalidArgumentException $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return self::json(409, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function createPlatformNumber(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        if (!self::canManageWhatsAppNumbers($obUser)) {
            return self::json(403, [
                'success' => false,
                'message' => 'Você não tem permissão para cadastrar números da plataforma.',
            ]);
        }

        try {
            $number = WhatsAppNumberManager::createPlatformNumber($obUser, self::jsonInput());

            return self::json(201, [
                'success' => true,
                'message' => 'Número da plataforma cadastrado.',
                'data' => $number,
            ]);
        } catch (\InvalidArgumentException $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return self::json(409, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function assignPlatformNumber($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        try {
            if (!self::canManageWhatsAppNumbers($obUser)) {
                return self::json(403, [
                    'success' => false,
                    'message' => 'Ação permitida apenas para administrador.',
                ]);
            }

            $number = WhatsAppNumberManager::assignPlatformNumber($obUser, (int)$id, self::jsonInput());

            return self::json(200, [
                'success' => true,
                'message' => 'Número conectado.',
                'data' => $number,
            ]);
        } catch (\Throwable $e) {
            return self::json(409, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function removeNumber($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        try {
            if (!self::canManageWhatsAppNumbers($obUser)) {
                return self::json(403, [
                    'success' => false,
                    'message' => 'Ação permitida apenas para administrador.',
                ]);
            }

            $number = WhatsAppNumberManager::removeNumberFromMeta($obUser, (int)$id);

            return self::json(200, [
                'success' => true,
                'message' => 'Número removido.',
                'data' => $number,
            ]);
        } catch (\Throwable $e) {
            return self::json(409, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function createAccount(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        if (!self::canManageWhatsAppNumbers($obUser)) {
            return self::json(403, [
                'success' => false,
                'message' => 'Ação permitida apenas para administrador.',
            ]);
        }

        $input = self::jsonInput();
        $required = ['label', 'phone_number_id', 'display_phone_number', 'access_token'];
        foreach ($required as $field) {
            if (trim((string)($input[$field] ?? '')) === '') {
                return self::json(422, [
                    'success' => false,
                    'message' => "Campo obrigatório: {$field}.",
                ]);
            }
        }

        try {
            $phoneNumberId = trim((string)$input['phone_number_id']);
            $accessToken = trim((string)$input['access_token']);
            $verification = WhatsAppNumberSafety::fetchVerificationStatus($accessToken, $phoneNumberId);
            if (!$verification['verified']) {
                return self::json(422, [
                    'success' => false,
                    'message' => 'Número WhatsApp bloqueado: status de verificação na Meta é '
                        . $verification['status']
                        . '. Confirme o código na Meta antes de cadastrar/liberar este número.',
                    'meta_status' => $verification['status'],
                ]);
            }

            $id = WhatsAppAccount::create([
                'tenancy_id' => $obUser['tenancy_id'],
                'user_id' => (int)$obUser['id'],
                'label' => trim((string)$input['label']),
                'waba_id' => self::nullableString($input['waba_id'] ?? null),
                'business_id' => self::nullableString($input['business_id'] ?? null),
                'phone_number_id' => $phoneNumberId,
                'display_phone_number' => self::normalizePhone((string)$input['display_phone_number']),
                'access_token' => $accessToken,
                'app_secret' => self::nullableString($input['app_secret'] ?? null),
                'verify_token' => self::nullableString($input['verify_token'] ?? null),
                'status' => 'active',
            ]);

            return self::json(201, [
                'success' => true,
                'message' => 'Número WhatsApp cadastrado.',
                'id' => $id,
            ]);
        } catch (\Throwable $e) {
            return self::json(500, [
                'success' => false,
                'message' => 'Falha ao cadastrar número WhatsApp.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function testAccount($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $account = WhatsAppAccount::getForUser((int)$id, $obUser);
        if (!$account) {
            return self::json(404, [
                'success' => false,
                'message' => 'Número WhatsApp não encontrado.',
            ]);
        }

        try {
            $result = (new MetaWhatsAppCloudApi())->getPhoneNumber(
                (string)$account['access_token'],
                (string)$account['phone_number_id']
            );
            $metaStatus = strtoupper((string)($result['data']['code_verification_status'] ?? ''));
            $verified = $result['ok'] && $metaStatus === 'VERIFIED';
            if ($result['ok'] && !$verified) {
                WhatsAppNumberSafety::syncVerificationStatus($account);
            }

            return self::json($verified ? 200 : 422, [
                'success' => $verified,
                'message' => $verified
                    ? 'Número verificado na Meta.'
                    : 'Meta retornou conexão, mas o número não está VERIFIED. O número foi desvinculado e voltou para em conexão.',
                'meta_status' => $metaStatus ?: 'UNKNOWN',
                'meta' => $result,
            ]);
        } catch (\Throwable $e) {
            return self::json(424, [
                'success' => false,
                'message' => 'Falha ao consultar dados do número.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function getBusinessProfile($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $account = WhatsAppAccount::getForUser((int)$id, $obUser);
        if (!$account) {
            return self::json(404, ['success' => false, 'message' => 'Conta WhatsApp não encontrada.']);
        }

        $result = (new MetaWhatsAppCloudApi())->getBusinessProfile(
            (string)$account['access_token'],
            (string)$account['phone_number_id']
        );

        if ($result['ok']) {
            $profile = $result['data']['data'][0] ?? $result['data'];
            self::persistBusinessProfile($obUser, (int)$account['id'], is_array($profile) ? $profile : [], null);
        }

        return self::json($result['ok'] ? 200 : 502, [
            'success' => $result['ok'],
            'message' => $result['ok'] ? 'Perfil sincronizado.' : ($result['error'] ?: 'Falha ao consultar perfil na Meta.'),
            'data' => $result['data'],
        ]);
    }

    public static function updateBusinessProfile($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $account = WhatsAppAccount::getForUser((int)$id, $obUser);
        if (!$account) {
            return self::json(404, ['success' => false, 'message' => 'Conta WhatsApp não encontrada.']);
        }

        $input = self::jsonInput();
        $payload = self::businessProfilePayload($input);
        $result = (new MetaWhatsAppCloudApi())->updateBusinessProfile(
            (string)$account['access_token'],
            (string)$account['phone_number_id'],
            $payload
        );

        self::persistBusinessProfile($obUser, (int)$account['id'], $payload, $result['ok'] ? null : $result['error']);

            return self::json($result['ok'] ? 200 : 424, [
                'success' => $result['ok'],
                'message' => $result['ok'] ? 'Perfil comercial atualizado na Meta.' : ($result['error'] ?: 'Falha ao atualizar perfil comercial.'),
                'data' => $result['data'],
            ]);
    }

    public static function updateBusinessProfilePicture($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $account = WhatsAppAccount::getForUser((int)$id, $obUser);
        if (!$account) {
            return self::json(404, ['success' => false, 'message' => 'Conta WhatsApp não encontrada.']);
        }

        try {
            if (empty($_FILES['profile_picture'])) {
                throw new \RuntimeException('Envie a imagem do perfil.');
            }

            $file = $_FILES['profile_picture'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Falha no upload da imagem.');
            }

            $tmp = (string)($file['tmp_name'] ?? '');
            $mimeType = self::detectMimeType($tmp, (string)($file['type'] ?? ''));
            if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
                throw new \RuntimeException('Envie uma imagem JPG ou PNG.');
            }

            $size = (int)($file['size'] ?? filesize($tmp) ?: 0);
            if ($size <= 0 || $size > 5 * 1024 * 1024) {
                throw new \RuntimeException('A imagem deve ter até 5MB.');
            }

            $upload = (new MetaWhatsAppCloudApi())->uploadProfilePicture((string)$account['access_token'], $tmp, $mimeType);
            if (!$upload['ok']) {
                self::persistBusinessProfile($obUser, (int)$account['id'], [], $upload['error']);
                return self::json(424, ['success' => false, 'message' => $upload['error'] ?: 'Falha ao enviar foto para a Meta.']);
            }

            $handle = (string)($upload['data']['h'] ?? $upload['data']['handle'] ?? '');
            if ($handle === '') {
                throw new \RuntimeException('Meta não retornou o identificador da foto.');
            }

            $update = (new MetaWhatsAppCloudApi())->updateBusinessProfile(
                (string)$account['access_token'],
                (string)$account['phone_number_id'],
                ['profile_picture_handle' => $handle]
            );

            $profileData = $update['data'];
            if ($update['ok']) {
                $profile = (new MetaWhatsAppCloudApi())->getBusinessProfile(
                    (string)$account['access_token'],
                    (string)$account['phone_number_id']
                );
                $profileData = $profile['ok'] ? $profile['data'] : $profileData;
                $profilePayload = $profile['data']['data'][0] ?? [];
                if (is_array($profilePayload)) {
                    $profilePayload['profile_picture_handle'] = $handle;
                    self::persistBusinessProfile($obUser, (int)$account['id'], $profilePayload, null);
                } else {
                    self::persistBusinessProfile($obUser, (int)$account['id'], ['profile_picture_handle' => $handle], null);
                }
            } else {
                self::persistBusinessProfile($obUser, (int)$account['id'], [
                    'profile_picture_handle' => $handle,
                ], $update['error']);
            }

            return self::json($update['ok'] ? 200 : 424, [
                'success' => $update['ok'],
                'message' => $update['ok'] ? 'Foto de perfil atualizada na Meta.' : ($update['error'] ?: 'Falha ao atualizar foto na Meta.'),
                'data' => $profileData,
            ]);
        } catch (\Throwable $e) {
            self::persistBusinessProfile($obUser, (int)$account['id'], [], $e->getMessage());
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function listCampaigns(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppCampaign::listForUser($obUser),
        ]);
    }

    public static function listTemplates(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppTemplate::listForUser($obUser),
        ]);
    }

    public static function listTemplateModels(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $category = strtoupper(trim((string)($_GET['category'] ?? '')));
        $source = strtolower(trim((string)($_GET['source'] ?? 'blueprints')));
        $models = WhatsAppTemplateBlueprintLibrary::list($category);
        if ($source === 'approved' || $source === 'all') {
            $systemModels = self::systemTemplateModels($obUser);
            if (in_array($category, ['UTILITY', 'MARKETING', 'AUTHENTICATION'], true)) {
                $systemModels = array_values(array_filter(
                    $systemModels,
                    static fn (array $model): bool => strtoupper((string)$model['category']) === $category
                ));
            }
            $models = $source === 'approved' ? $systemModels : array_merge($models, $systemModels);
        }

        return self::json(200, [
            'success' => true,
            'source' => $source,
            'message' => 'Modelos prontos carregados. Escolha um modelo, edite e envie para aprovação na Meta.',
            'data' => $models,
        ]);
    }

    public static function simulatePricing(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $input = self::jsonInput();
        $messageType = (string)($input['message_type'] ?? $_GET['message_type'] ?? 'marketing');
        $countryCode = (string)($input['country_code'] ?? $_GET['country_code'] ?? 'BR');
        $customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : (int)($obUser['id'] ?? 0);
        $manualDollar = isset($input['dollar_rate']) && is_numeric($input['dollar_rate'])
            ? (float)$input['dollar_rate']
            : null;

        try {
            if ($manualDollar !== null && $manualDollar > 0) {
                $current = WhatsAppDynamicPricing::calculatePrice($messageType, $countryCode, $customerId);
                $effectiveRate = round($manualDollar * (1 + ((float)$current['safety_margin_percent'] / 100)), 6);
                $costBrl = round((float)$current['cost_usd'] * $effectiveRate, 6);
                $final = self::simulateCommercialRound($costBrl * (1 + ((float)$current['margin_percent'] / 100)));
                $current['exchange_rate'] = $manualDollar;
                $current['effective_rate'] = $effectiveRate;
                $current['cost_brl'] = $costBrl;
                $current['final_price_brl'] = $final;
                $current['profit_brl'] = round($final - $costBrl, 6);
                $current['simulation_only'] = true;
                $pricing = $current;
            } else {
                $pricing = WhatsAppDynamicPricing::calculatePrice($messageType, $countryCode, $customerId);
            }

            return self::json(200, [
                'success' => true,
                'data' => $pricing,
            ]);
        } catch (\Throwable $e) {
            return self::json(422, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public static function createTemplate(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $input = self::jsonInput();
        $accountId = (int)($input['account_id'] ?? 0);
        $account = $accountId > 0 ? WhatsAppAccount::getForUser($accountId, $obUser) : null;
        if (!$account) {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe um número WhatsApp válido para enviar o template à Meta.',
            ]);
        }

        if (trim((string)($account['waba_id'] ?? '')) === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Conta WhatsApp sem WABA ID. Configure o WABA antes de criar templates.',
            ]);
        }

        $rawName = trim((string)($input['name'] ?? ''));
        $name = self::normalizeTemplateName($rawName);
        $language = trim((string)($input['language'] ?? 'pt_BR')) ?: 'pt_BR';
        $category = strtoupper(trim((string)($input['category'] ?? 'UTILITY')));
        $body = self::nullableString($input['body'] ?? null);
        $components = $input['components'] ?? null;
        $variableMap = is_array($input['variable_map'] ?? null) ? $input['variable_map'] : null;

        if ($name === '') {
            $name = 'template_' . date('YmdHis');
        }

        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            return self::json(422, [
                'success' => false,
                'message' => 'Nome técnico inválido. Use letras, números ou espaços; o sistema converte para minúsculas e underscore.',
            ]);
        }

        if (WhatsAppTemplate::getByNameForUser($name, $language, $obUser)) {
            return self::json(409, [
                'success' => false,
                'message' => 'Já existe um template local com este nome e idioma.',
            ]);
        }

        if (!in_array($category, ['MARKETING', 'UTILITY', 'AUTHENTICATION'], true)) {
            return self::json(422, [
                'success' => false,
                'message' => 'Categoria de template inválida.',
            ]);
        }

        if (is_string($components)) {
            $components = trim($components);
            if ($components === '') {
                $components = null;
            }
        }

        if (is_string($components) && trim($components) !== '') {
            $decoded = json_decode($components, true);
            if (!is_array($decoded)) {
                return self::json(422, [
                    'success' => false,
                    'message' => 'Componentes precisa ser um JSON válido.',
                ]);
            }
            $components = $decoded;
        }

        if (trim((string)($body ?? '')) === '' && (!is_array($components) || $components === [])) {
            $imported = self::importExistingMetaTemplate($account, $obUser, $name, $language);
            if ($imported !== null) {
                return $imported;
            }

            return self::json(422, [
                'success' => false,
                'message' => 'Informe o texto do template para criar na Meta, ou clique em Sincronizar para importar templates já aprovados.',
            ]);
        }

        try {
            $metaPayload = self::buildMetaTemplatePayload($name, $language, $category, $body, is_array($components) ? $components : null);
            $validationErrors = self::validateTemplateDefinition($name, $language, $category, $metaPayload);
            if ($validationErrors !== []) {
                return self::json(422, [
                    'success' => false,
                    'message' => implode(' ', $validationErrors),
                    'errors' => $validationErrors,
                ]);
            }

            if (empty($metaPayload['components'])) {
                return self::json(422, [
                    'success' => false,
                    'message' => 'A Meta exige conteúdo no template. Informe o texto da mensagem ou componentes JSON válidos.',
                ]);
            }

            self::auditTemplateWindowEvent('whatsapp_template_create_request', [
                'tenancy_id' => (string)$obUser['tenancy_id'],
                'user_id' => (int)$obUser['id'],
                'account_id' => $accountId,
                'waba_id' => (string)($account['waba_id'] ?? ''),
                'template_name' => $name,
                'payload' => $metaPayload,
            ]);

            $meta = (new MetaWhatsAppCloudApi())->createMessageTemplate(
                (string)$account['access_token'],
                (string)$account['waba_id'],
                $metaPayload
            );
            if (!$meta['ok']) {
                self::auditTemplateWindowEvent('whatsapp_template_create_error', [
                    'tenancy_id' => (string)$obUser['tenancy_id'],
                    'user_id' => (int)$obUser['id'],
                    'account_id' => $accountId,
                    'waba_id' => (string)($account['waba_id'] ?? ''),
                    'template_name' => $name,
                    'payload' => $metaPayload,
                    'response' => $meta,
                ]);

                return self::json(424, [
                    'success' => false,
                    'message' => $meta['error'] ?: 'Falha ao enviar template para aprovação na Meta.',
                    'meta' => $meta,
                ]);
            }

            $id = WhatsAppTemplate::create([
                'tenancy_id' => $obUser['tenancy_id'],
                'user_id' => (int)$obUser['id'],
                'account_id' => $accountId,
                'waba_id' => $account['waba_id'] ?? null,
                'meta_template_id' => $meta['data']['id'] ?? null,
                'name' => $name,
                'language' => $language,
                'category' => $category,
                'body' => $body,
                'components' => $metaPayload['components'] ?? null,
                'variable_map' => $variableMap ?: self::variableMapFromComponents($metaPayload['components'] ?? []),
                'is_system_template' => false,
                'template_type' => 'tenant',
                'status' => WhatsAppTemplate::normalizeMetaStatus((string)($meta['data']['status'] ?? 'pending')),
                'template_submitted_at' => date('Y-m-d H:i:s'),
                'template_last_sync_at' => date('Y-m-d H:i:s'),
                'meta_payload' => $meta['data'],
                'created_by' => (int)$obUser['id'],
                'updated_by' => (int)$obUser['id'],
            ]);
            WhatsAppTemplate::audit($id, $obUser, 'create', [
                'tenancy_id' => $obUser['tenancy_id'],
                'is_system_template' => 0,
                'template_type' => 'tenant',
            ]);
            self::auditTemplateWindowEvent('whatsapp_template_create_success', [
                'tenancy_id' => (string)$obUser['tenancy_id'],
                'user_id' => (int)$obUser['id'],
                'account_id' => $accountId,
                'waba_id' => (string)($account['waba_id'] ?? ''),
                'template_id' => $id,
                'template_name' => $name,
                'payload' => $metaPayload,
                'response' => $meta['data'],
            ]);

            return self::json(201, [
                'success' => true,
                'message' => 'Template enviado para aprovação na Meta.',
                'id' => $id,
                'meta' => $meta['data'],
            ]);
        } catch (\Throwable $e) {
            return self::json(500, [
                'success' => false,
                'message' => 'Falha ao salvar template.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function deleteTemplate($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $template = WhatsAppTemplate::getForUser((int)$id, $obUser);
        if (!$template) {
            return self::json(404, [
                'success' => false,
                'message' => 'Template não encontrado.',
            ]);
        }
        if (!WhatsAppTemplate::canDelete($template, $obUser)) {
            return self::json(403, [
                'success' => false,
                'message' => 'Você não tem permissão para excluir este template.',
            ]);
        }

        $ok = WhatsAppTemplate::deleteForUser((int)$id, $obUser);

        return self::json($ok ? 200 : 404, [
            'success' => $ok,
            'message' => $ok ? 'Template excluído.' : 'Template não encontrado.',
        ]);
    }

    public static function listConversations($request): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $query = $request->getQueryParams();
        $accountId = isset($query['account_id']) ? (int)$query['account_id'] : null;

        if ($accountId !== null && $accountId > 0 && !WhatsAppAccount::getForUser($accountId, $obUser)) {
            return self::json(404, [
                'success' => false,
                'message' => 'Conta WhatsApp não encontrada.',
            ]);
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppConversation::listForUser($obUser, $accountId),
        ]);
    }

    public static function listMessages($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $conversation = WhatsAppConversation::getForUser((int)$id, $obUser);
        if (!$conversation) {
            return self::json(404, [
                'success' => false,
                'message' => 'Conversa não encontrada.',
            ]);
        }

        return self::json(200, [
            'success' => true,
            'conversation' => $conversation,
            'data' => WhatsAppConversation::listMessagesForUser((int)$id, $obUser),
        ]);
    }

    public static function markConversationRead($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $ok = WhatsAppConversation::markReadForUser((int)$id, $obUser);

        return self::json($ok ? 200 : 404, [
            'success' => $ok,
            'message' => $ok ? 'Conversa marcada como lida.' : 'Conversa não encontrada.',
        ]);
    }

    public static function markConversationUnread($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $ok = WhatsAppConversation::markUnreadForUser((int)$id, $obUser);

        return self::json($ok ? 200 : 404, [
            'success' => $ok,
            'message' => $ok ? 'Conversa marcada como não lida.' : 'Conversa não encontrada.',
        ]);
    }

    public static function deleteConversation($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $ok = WhatsAppConversation::deleteForUser((int)$id, $obUser);

        return self::json($ok ? 200 : 404, [
            'success' => $ok,
            'message' => $ok ? 'Conversa excluída.' : 'Conversa não encontrada.',
        ]);
    }

    public static function sendDirectMessage(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        if (!self::canUseSupportAccount($obUser)) {
            return self::json(403, [
                'success' => false,
                'message' => 'Você não tem permissão para enviar pela conta central de suporte.',
            ]);
        }

        $input = self::jsonInput();
        $accountId = (int)($input['account_id'] ?? 0);
        $to = self::normalizePhone((string)($input['to'] ?? ''));
        $messageType = strtolower((string)($input['message_type'] ?? 'text'));
        $message = trim((string)($input['message'] ?? ''));
        $templateName = trim((string)($input['template_name'] ?? ''));
        $templateLanguage = trim((string)($input['template_language'] ?? 'pt_BR')) ?: 'pt_BR';
        $templateComponents = $input['template_components'] ?? [];
        $templateVariables = WhatsAppTemplateVariableResolver::normalizeInputVariables($input['template_variables'] ?? []);
        $contactName = self::nullableString($input['name'] ?? null);

        if ($accountId <= 0) {
            return self::json(422, [
                'success' => false,
                'message' => 'Selecione uma conta WhatsApp.',
            ]);
        }

        if (strlen($to) < 8 || strlen($to) > 15) {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe o número com DDI e DDD.',
            ]);
        }

        if (!in_array($messageType, ['text', 'template', 'audio'], true)) {
            return self::json(422, [
                'success' => false,
                'message' => 'Tipo de mensagem inválido.',
            ]);
        }

        if ($messageType === 'text' && $message === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Digite a mensagem.',
            ]);
        }

        if ($messageType === 'template' && $templateName === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe o template aprovado da Meta.',
            ]);
        }

        if ($messageType === 'audio' && empty($_FILES['audio']) && trim((string)($input['audio_url'] ?? '')) === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Envie um arquivo de áudio ou informe uma URL pública.',
            ]);
        }

        if ($messageType === 'template' && is_string($templateComponents) && trim($templateComponents) !== '') {
            $decodedComponents = json_decode($templateComponents, true);
            if (!is_array($decodedComponents)) {
                return self::json(422, [
                    'success' => false,
                    'message' => 'Componentes do template precisa ser um JSON válido.',
                ]);
            }
            $templateComponents = $decodedComponents;
        }

        if (!is_array($templateComponents)) {
            $templateComponents = [];
        }
        if ($templateVariables === [] && $templateComponents !== [] && !array_is_list($templateComponents)) {
            $templateVariables = $templateComponents;
        }

        $account = WhatsAppAccount::getForUser($accountId, $obUser);
        if (!$account) {
            return self::json(404, [
                'success' => false,
                'message' => 'Conta WhatsApp não encontrada.',
            ]);
        }

        try {
            WhatsAppNumberSafety::assertVerifiedForUse($account);
        } catch (\Throwable $e) {
            return self::json(409, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }

        $templateCategory = null;
        $template = null;
        $resolvedTemplate = null;
        if ($messageType === 'template') {
            $contactContext = self::findContactContextByPhone((string)$obUser['tenancy_id'], $to);
            $contactName = $contactName ?: self::nullableString($contactContext['name'] ?? null);
            $template = WhatsAppTemplate::getByNameForUser($templateName, $templateLanguage, $obUser);
            if (!$template) {
                return self::json(404, [
                    'success' => false,
                    'message' => 'Template não encontrado.',
                ]);
            }

            if (!self::templateMatchesAccount($template, $account)) {
                return self::json(403, [
                    'success' => false,
                    'message' => 'Template não pertence à WABA selecionada.',
                ]);
            }

            if ((string)$template['status'] !== 'approved') {
                return self::json(422, [
                    'success' => false,
                    'message' => 'Template ainda não aprovado pela Meta.',
                ]);
            }

            $templateCategory = WhatsAppCostPolicy::normalizeCategory($template['category'] ?? 'MARKETING');

            try {
                $resolvedTemplate = WhatsAppTemplateVariableResolver::buildSendComponents($template, [
                    'tenancy_id' => $obUser['tenancy_id'],
                    'tenant_name' => self::tenantName((string)$obUser['tenancy_id']),
                    'contact_name' => $contactName,
                    'contact_agency' => self::nullableString($contactContext['agency'] ?? $contactContext['agencia'] ?? $contactContext['branch'] ?? null),
                    'contact_name_fallback' => self::templateContactFallback(),
                    'contact_phone' => $to,
                ], $templateVariables);
                $templateComponents = $resolvedTemplate['components'];
            } catch (\Throwable $e) {
                error_log(json_encode([
                    'event' => 'whatsapp_template_variable_error',
                    'template_name' => $templateName,
                    'tenancy_id' => (string)$obUser['tenancy_id'],
                    'contact' => [
                        'name' => $contactName,
                        'phone' => self::maskPhoneForLog($to),
                    ],
                    'error' => $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return self::json(422, [
                    'success' => false,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $lastInboundAt = WhatsAppConversation::getLastInboundAt($accountId, $to);
        $serviceWindowOpen = WhatsAppCostPolicy::isServiceWindowOpen($lastInboundAt);

        if ($messageType === 'text' && !$serviceWindowOpen) {
            self::auditTemplateWindowEvent('whatsapp_free_text_blocked_window_closed', [
                'tenancy_id' => (string)$obUser['tenancy_id'],
                'user_id' => (int)$obUser['id'],
                'account_id' => $accountId,
                'conversation_id' => null,
                'contact_phone' => self::maskPhoneForLog($to),
                'last_inbound_at' => $lastInboundAt,
            ]);

            return self::json(422, [
                'success' => false,
                'message' => 'Este contato está fora da janela de 24 horas. Para iniciar uma nova conversa, utilize um template aprovado.',
                'last_inbound_at' => $lastInboundAt,
            ]);
        }

        if ($messageType === 'audio' && !$serviceWindowOpen) {
            self::auditTemplateWindowEvent('whatsapp_audio_blocked_window_closed', [
                'tenancy_id' => (string)$obUser['tenancy_id'],
                'user_id' => (int)$obUser['id'],
                'account_id' => $accountId,
                'conversation_id' => null,
                'contact_phone' => self::maskPhoneForLog($to),
                'last_inbound_at' => $lastInboundAt,
            ]);

            return self::json(422, [
                'success' => false,
                'message' => 'Este contato está fora da janela de 24 horas. Para iniciar uma nova conversa, utilize um template aprovado.',
                'last_inbound_at' => $lastInboundAt,
            ]);
        }

        if (
            $messageType === 'template'
            && $templateCategory === WhatsAppCostPolicy::CATEGORY_MARKETING
            && WhatsAppConversation::hasMarketingOptOut($accountId, $to)
        ) {
            return self::json(409, [
                'success' => false,
                'message' => 'Envio de marketing bloqueado: destinatário solicitou descadastro.',
            ]);
        }

        $messageBody = $messageType === 'template'
            ? "[Template] {$templateName} ({$templateLanguage})"
            : ($messageType === 'audio' ? '[Áudio]' : $message);

        $conversationId = WhatsAppConversation::findOrCreate([
            'tenancy_id' => $obUser['tenancy_id'],
            'user_id' => (int)$obUser['id'],
            'account_id' => $accountId,
            'contact_phone' => $to,
            'contact_name' => $contactName,
            'last_message' => $messageBody,
            'last_direction' => 'outbound',
            'unread_count' => 0,
        ]);

        if ($messageType === 'template' && !$serviceWindowOpen) {
            self::auditTemplateWindowEvent('whatsapp_template_reopen_window_closed', [
                'tenancy_id' => (string)$obUser['tenancy_id'],
                'user_id' => (int)$obUser['id'],
                'account_id' => $accountId,
                'conversation_id' => $conversationId,
                'template_name' => $templateName,
                'contact_phone' => self::maskPhoneForLog($to),
                'contact_name' => $contactName,
                'parameters' => $resolvedTemplate['resolved'] ?? [],
                'last_inbound_at' => $lastInboundAt,
            ]);
        }

        if ($messageType === 'audio') {
            try {
                $audioCategory = WhatsAppBilling::resolveCategory('audio', null, $serviceWindowOpen);
                $audioPriceBrl = WhatsAppBilling::priceForUser((int)$obUser['id'], (string)$obUser['tenancy_id'], $audioCategory);
                WhatsAppBilling::assertCanSend((int)$obUser['id'], (string)$obUser['tenancy_id'], $audioCategory, $audioPriceBrl);

                $media = !empty($_FILES['audio'])
                    ? self::storeUploadedAudio($_FILES['audio'])
                    : self::mediaFromPublicAudioUrl((string)$input['audio_url']);

                $result = (new MetaWhatsAppCloudApi())->sendAudioLink(
                    (string)$account['access_token'],
                    (string)$account['phone_number_id'],
                    $to,
                    (string)$media['url']
                );

                if (!$result['ok']) {
                    return self::json(424, [
                        'success' => false,
                        'message' => $result['error'] ?: 'Falha ao enviar áudio pela Meta.',
                        'meta' => $result,
                    ]);
                }

                $messageId = WhatsAppConversation::addMessage([
                    'conversation_id' => $conversationId,
                    'account_id' => $accountId,
                    'wamid' => $result['data']['messages'][0]['id'] ?? null,
                    'direction' => 'outbound',
                    'message_type' => 'audio',
                    'service_window_open' => 1,
                    'message_category' => $audioCategory,
                    'price_brl' => $audioPriceBrl,
                    'billed' => 0,
                    'body' => '[Áudio]',
                    'status' => 'sent',
                    'payload' => [
                        'media' => $media,
                        'meta' => $result['data'],
                        'billing' => [
                            'message_category' => strtolower($audioCategory),
                            'billed' => false,
                        ],
                    ],
                ]);

                WhatsAppBilling::recordDirectSent([
                    'user_id' => (int)$obUser['id'],
                    'tenancy_id' => (string)$obUser['tenancy_id'],
                    'contact_phone' => $to,
                    'template_name' => null,
                ], $messageId, $result['data']['messages'][0]['id'] ?? null, $audioCategory, $audioPriceBrl);

                return self::json(200, [
                    'success' => true,
                    'message' => 'Áudio enviado.',
                    'conversation_id' => $conversationId,
                    'message_id' => $messageId,
                ]);
            } catch (\Throwable $e) {
                return self::json(422, [
                    'success' => false,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $plannedMessages = $messageType === 'template'
            ? WhatsAppMessagePlanner::planTemplate(
                $templateName,
                $templateLanguage,
                $templateCategory,
                $templateComponents,
                $resolvedTemplate['preview_body'] ?? ($template['body'] ?? null)
            )
            : WhatsAppMessagePlanner::planText($message, $serviceWindowOpen);
        if ($messageType === 'template' && isset($plannedMessages[0])) {
            $plannedMessages[0]['preview_body'] = $resolvedTemplate['preview_body'] ?? $plannedMessages[0]['body'];
            $plannedMessages[0]['template_variables'] = $resolvedTemplate['resolved'] ?? [];
        }

        $billableMessages = [];
        foreach ($plannedMessages as $planned) {
            if (
                ($planned['template_category'] ?? null) === WhatsAppCostPolicy::CATEGORY_MARKETING
                && WhatsAppConversation::hasMarketingOptOut($accountId, $to)
            ) {
                continue;
            }

            $billableMessages[] = self::withWhatsAppBilling([
                'tenancy_id' => $obUser['tenancy_id'],
                'user_id' => (int)$obUser['id'],
                'account_id' => $accountId,
                'conversation_id' => $conversationId,
                'contact_phone' => $to,
                'contact_name' => $contactName,
            ], $planned, $serviceWindowOpen);
        }

        try {
            WhatsAppBilling::assertCanSendBatch((int)$obUser['id'], (string)$obUser['tenancy_id'], $billableMessages);
        } catch (\Throwable $e) {
            return self::json(402, [
                'success' => false,
                'message' => $e->getMessage() === WhatsAppBilling::ERROR_INSUFFICIENT_BALANCE
                    ? WhatsAppBilling::ERROR_INSUFFICIENT_BALANCE
                    : $e->getMessage(),
            ]);
        }

        $outboxIds = [];
        foreach ($billableMessages as $billableMessage) {
            $outboxIds[] = WhatsAppOutbox::enqueue($billableMessage);
        }

        return self::json(202, [
            'success' => true,
            'message' => 'Mensagem enviada.',
            'conversation_id' => $conversationId,
            'outbox_ids' => $outboxIds,
            'parts' => $plannedMessages,
            'counters' => WhatsAppMessagePlanner::summarize($plannedMessages),
        ]);
    }

    public static function verifyWebhook($request): Response
    {
        $query = $request->getQueryParams();
        $mode = (string)($query['hub_mode'] ?? $query['hub.mode'] ?? '');
        $token = (string)($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = (string)($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');

        if ($mode === 'subscribe' && hash_equals(WhatsAppConfig::webhookVerifyToken(), $token)) {
            return new Response(200, $challenge);
        }

        return new Response(403, 'Invalid verify token');
    }

    public static function receiveWebhook($request = null): Response
    {
        $rawPayload = file_get_contents('php://input') ?: '';
        if (!self::isValidWebhookSignature($rawPayload, $request)) {
            return self::json(403, [
                'success' => false,
                'message' => 'Assinatura do webhook inválida.',
            ]);
        }

        $payload = json_decode($rawPayload, true);
        if (!is_array($payload)) {
            $payload = $_POST ?: [];
        }
        $processed = 0;

        try {
            foreach (($payload['entry'] ?? []) as $entry) {
                foreach (($entry['changes'] ?? []) as $change) {
                    $value = $change['value'] ?? [];
                    $phoneNumberId = (string)($value['metadata']['phone_number_id'] ?? '');
                    if ($phoneNumberId === '') {
                        continue;
                    }

                    $account = WhatsAppAccount::getByPhoneNumberId($phoneNumberId);
                    if (!$account) {
                        continue;
                    }

                    foreach (($value['messages'] ?? []) as $message) {
                        if (self::storeInboundWebhookMessage($account, $message, $value)) {
                            $processed++;
                        }
                    }

                    foreach (($value['statuses'] ?? []) as $status) {
                        if (self::storeWebhookMessageStatus($status)) {
                            $processed++;
                        }
                    }

                    if (self::storeWebhookNumberQuality($value)) {
                        $processed++;
                    }
                }
            }

            return self::json(200, [
                'success' => true,
                'processed' => $processed,
            ]);
        } catch (\Throwable $e) {
            return self::json(200, [
                'success' => false,
                'processed' => $processed,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function syncTemplates(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $accounts = WhatsAppAccount::listForUser($obUser);
        $synced = 0;
        $submitted = 0;
        $errors = [];
        self::auditTemplateWindowEvent('whatsapp_template_sync_start', [
            'tenancy_id' => (string)$obUser['tenancy_id'],
            'user_id' => (int)$obUser['id'],
            'accounts' => count($accounts),
        ]);
        foreach ($accounts as $listedAccount) {
            $account = WhatsAppAccount::getForUser((int)$listedAccount['id'], $obUser);
            if (!$account || trim((string)($account['waba_id'] ?? '')) === '') {
                continue;
            }

            $result = (new MetaWhatsAppCloudApi())->listMessageTemplates(
                (string)$account['access_token'],
                (string)$account['waba_id']
            );
            if (!$result['ok']) {
                $errors[] = ['account_id' => (int)$account['id'], 'error' => $result['error']];
                self::auditTemplateWindowEvent('whatsapp_template_sync_error', [
                    'tenancy_id' => (string)$obUser['tenancy_id'],
                    'user_id' => (int)$obUser['id'],
                    'account_id' => (int)$account['id'],
                    'waba_id' => (string)($account['waba_id'] ?? ''),
                    'response' => $result,
                ]);
                continue;
            }

            $metaTemplates = is_array($result['data']['data'] ?? null) ? $result['data']['data'] : [];
            $seenMetaTemplates = $metaTemplates;
            $metaIndex = self::indexMetaTemplates($metaTemplates);
            foreach ($metaTemplates as $template) {
                if (is_array($template)) {
                    WhatsAppTemplate::upsertFromMeta($account, $template, $obUser);
                    $synced++;
                }
            }

            foreach (self::localTemplatesForAccount($account) as $template) {
                $key = self::templateIdentityKey((string)$template['name'], (string)$template['language']);
                if (isset($metaIndex[$key])) {
                    WhatsAppTemplate::updateMetaStatus(
                        (int)$template['id'],
                        (string)($metaIndex[$key]['status'] ?? 'pending'),
                        $metaIndex[$key]
                    );
                    continue;
                }

                if (!self::canSubmitTemplateStatus((string)($template['status'] ?? ''))) {
                    continue;
                }

                $submit = self::submitLocalTemplateToMeta($account, $template);
                if ($submit['ok']) {
                    $submitted++;
                    $metaData = is_array($submit['data'] ?? null) ? $submit['data'] : [];
                    if ($metaData !== []) {
                        $seenMetaTemplates[] = $metaData + [
                            'name' => (string)$template['name'],
                            'language' => (string)$template['language'],
                            'category' => (string)$template['category'],
                        ];
                    }
                    WhatsAppTemplate::updateMetaStatus(
                        (int)$template['id'],
                        (string)($metaData['status'] ?? 'pending'),
                        $metaData
                    );
                } else {
                    $errors[] = [
                        'account_id' => (int)$account['id'],
                        'template' => (string)$template['name'],
                        'error' => $submit['error'] ?: 'Falha ao enviar template para a Meta.',
                    ];
                    WhatsAppTemplate::updateMetaStatus(
                        (int)$template['id'],
                        (string)($template['status'] ?? 'draft'),
                        [],
                        $submit['error'] ?: 'Falha ao enviar template para a Meta.'
                    );
                }
            }

            WhatsAppTemplate::disableMissingFromMeta($account, $seenMetaTemplates, $obUser);
        }

        self::auditTemplateWindowEvent('whatsapp_template_sync_finish', [
            'tenancy_id' => (string)$obUser['tenancy_id'],
            'user_id' => (int)$obUser['id'],
            'synced' => $synced,
            'submitted' => $submitted,
            'errors' => $errors,
        ]);

        return self::json(200, [
            'success' => $errors === [],
            'message' => $errors === []
                ? "Templates sincronizados com a Meta. Enviados: {$submitted}."
                : "Sincronização concluída com alertas. Enviados: {$submitted}.",
            'synced' => $synced,
            'submitted' => $submitted,
            'errors' => $errors,
        ]);
    }

    public static function syncTemplate($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $template = WhatsAppTemplate::getForUser((int)$id, $obUser);
        if (!$template) {
            return self::json(404, ['success' => false, 'message' => 'Template não encontrado.']);
        }
        if (!WhatsAppTemplate::canMutate($template, $obUser)) {
            return self::json(403, ['success' => false, 'message' => 'Você não tem permissão para alterar este template.']);
        }

        $account = !empty($template['account_id'])
            ? WhatsAppAccount::getForUser((int)$template['account_id'], $obUser)
            : null;
        if (!$account || trim((string)($account['waba_id'] ?? '')) === '') {
            return self::json(422, ['success' => false, 'message' => 'Template sem conta/WABA vinculada para sincronizar.']);
        }

        $result = (new MetaWhatsAppCloudApi())->listMessageTemplates((string)$account['access_token'], (string)$account['waba_id']);
        if (!$result['ok']) {
            WhatsAppTemplate::updateMetaStatusForUser((int)$template['id'], $obUser, (string)$template['status'], [], $result['error']);
            return self::json(424, ['success' => false, 'message' => $result['error'] ?: 'Falha ao sincronizar template.']);
        }

        foreach (($result['data']['data'] ?? []) as $metaTemplate) {
            if (
                is_array($metaTemplate)
                && (string)($metaTemplate['name'] ?? '') === (string)$template['name']
                && (string)($metaTemplate['language'] ?? '') === (string)$template['language']
            ) {
                WhatsAppTemplate::updateMetaStatusForUser((int)$template['id'], $obUser, (string)($metaTemplate['status'] ?? 'pending'), $metaTemplate);
                return self::json(200, ['success' => true, 'message' => 'Template sincronizado.', 'data' => $metaTemplate]);
            }
        }

        if (self::canSubmitTemplateStatus((string)($template['status'] ?? ''))) {
            $submit = self::submitLocalTemplateToMeta($account, $template);
            if ($submit['ok']) {
                $metaData = is_array($submit['data'] ?? null) ? $submit['data'] : [];
                WhatsAppTemplate::updateMetaStatus(
                    (int)$template['id'],
                    (string)($metaData['status'] ?? 'pending'),
                    $metaData
                );
                return self::json(200, [
                    'success' => true,
                    'message' => 'Template enviado para aprovação na Meta.',
                    'data' => $metaData,
                ]);
            }

            WhatsAppTemplate::updateMetaStatus(
                (int)$template['id'],
                (string)($template['status'] ?? 'draft'),
                [],
                $submit['error'] ?: 'Falha ao enviar template para a Meta.'
            );
            return self::json(424, [
                'success' => false,
                'message' => $submit['error'] ?: 'Falha ao enviar template para a Meta.',
            ]);
        }

        WhatsAppTemplate::updateMetaStatusForUser((int)$template['id'], $obUser, 'disabled', [], 'Template não retornado pela Meta nesta WABA.');
        return self::json(404, ['success' => false, 'message' => 'Template não encontrado na Meta.']);
    }

    private static function isValidWebhookSignature(string $rawPayload, mixed $request = null): bool
    {
        $secret = WhatsAppConfig::webhookAppSecret();
        if ($secret === '') {
            return true;
        }

        $headers = is_object($request) && method_exists($request, 'getHeaders')
            ? $request->getHeaders()
            : [];
        $signature = (string)(
            $headers['X-Hub-Signature-256']
            ?? $headers['x-hub-signature-256']
            ?? $_SERVER['HTTP_X_HUB_SIGNATURE_256']
            ?? ''
        );

        if (!str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawPayload, $secret);
        return hash_equals($expected, $signature);
    }

    private static function storeInboundWebhookMessage(array $account, array $message, array $value): bool
    {
        $from = self::normalizePhone((string)($message['from'] ?? ''));
        if ($from === '') {
            return false;
        }

        $contactName = null;
        foreach (($value['contacts'] ?? []) as $contact) {
            if (($contact['wa_id'] ?? '') === $from) {
                $contactName = self::nullableString($contact['profile']['name'] ?? null);
                break;
            }
        }

        $messageType = (string)($message['type'] ?? 'unknown');
        $body = self::extractWebhookMessageBody($message);
        $payload = $message;
        if ($messageType === 'audio') {
            $media = self::storeInboundAudioMedia($account, $message);
            if ($media !== null) {
                $payload['media'] = $media;
            }
        }

        $isOptOut = $messageType === 'text' && WhatsAppCostPolicy::looksLikeOptOut($body);
        if ($isOptOut) {
            WhatsAppConversation::registerMarketingOptOut((int)$account['id'], $from, $message['id'] ?? null);
            $payload['opt_out'] = [
                'detected' => true,
                'reason' => 'keyword',
                'detected_at' => date('Y-m-d H:i:s'),
            ];
        }

        $conversationId = WhatsAppConversation::findOrCreate([
            'tenancy_id' => $account['tenancy_id'],
            'user_id' => (int)$account['user_id'],
            'account_id' => (int)$account['id'],
            'contact_phone' => $from,
            'contact_name' => $contactName,
            'last_message' => $body,
            'last_direction' => 'inbound',
            'unread_count' => 1,
        ]);

        try {
            WhatsAppConversation::addMessage([
                'conversation_id' => $conversationId,
                'account_id' => (int)$account['id'],
                'wamid' => $message['id'] ?? null,
                'direction' => 'inbound',
                'message_type' => $messageType,
                'body' => $body,
                'status' => 'received',
                'payload' => $payload,
            ]);
        } catch (\Throwable) {
            return false;
        }

        WhatsAppSupportDesk::handleInboundConversation($conversationId, $account, $body);

        return true;
    }

    private static function storeWebhookMessageStatus(array $status): bool
    {
        $wamid = (string)($status['id'] ?? '');
        $statusName = (string)($status['status'] ?? '');
        $errorMessage = null;

        if (!empty($status['errors'][0]['message'])) {
            $errorMessage = (string)$status['errors'][0]['message'];
        } elseif (!empty($status['errors'][0]['title'])) {
            $errorMessage = (string)$status['errors'][0]['title'];
        }

        $updated = WhatsAppConversation::updateMessageStatusByWamid($wamid, $statusName, $errorMessage, $status);

        if ($updated && in_array(strtolower($statusName), ['delivered', 'read'], true)) {
            WhatsAppBilling::billDeliveredByWamid($wamid);
        }

        return $updated;
    }

    private static function storeWebhookNumberQuality(array $value): bool
    {
        $hasQuality = isset($value['quality_rating'])
            || isset($value['current_quality_update_event'])
            || isset($value['messaging_limit_tier']);

        if (!$hasQuality) {
            return false;
        }

        try {
            WhatsAppNumberSafety::handleMetaWebhook($value);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function extractWebhookMessageBody(array $message): string
    {
        $type = (string)($message['type'] ?? '');

        return match ($type) {
            'text' => (string)($message['text']['body'] ?? ''),
            'button' => (string)($message['button']['text'] ?? $message['button']['payload'] ?? '[Botão]'),
            'interactive' => (string)(
                $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? '[Interativo]'
            ),
            'image' => (string)($message['image']['caption'] ?? '[Imagem]'),
            'audio' => '[Áudio]',
            'video' => (string)($message['video']['caption'] ?? '[Vídeo]'),
            'document' => (string)($message['document']['filename'] ?? '[Documento]'),
            'sticker' => '[Sticker]',
            'location' => '[Localização]',
            default => $type !== '' ? "[{$type}]" : '[Mensagem]',
        };
    }

    public static function createCampaign(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $input = self::jsonInput();
        $accountId = (int)($input['account_id'] ?? 0);
        $account = $accountId > 0 ? WhatsAppAccount::getForUser($accountId, $obUser) : null;

        if (!$account) {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe um número WhatsApp válido.',
            ]);
        }

        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe o nome da campanha.',
            ]);
        }

        $messageType = strtolower((string)($input['message_type'] ?? 'template'));
        if (!in_array($messageType, ['text', 'template'], true)) {
            return self::json(422, [
                'success' => false,
                'message' => 'Tipo de mensagem inválido.',
            ]);
        }

        if ($messageType === 'text' && trim((string)($input['message_body'] ?? '')) === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe o texto da mensagem.',
            ]);
        }

        if ($messageType === 'template' && trim((string)($input['template_name'] ?? '')) === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe o nome do template aprovado na Meta.',
            ]);
        }

        $templateComponents = $input['template_components'] ?? [];
        $templateVariables = WhatsAppTemplateVariableResolver::normalizeInputVariables($input['template_variables'] ?? []);
        if ($messageType === 'template' && is_string($templateComponents) && trim($templateComponents) !== '') {
            $decodedComponents = json_decode($templateComponents, true);
            if (!is_array($decodedComponents)) {
                return self::json(422, [
                    'success' => false,
                    'message' => 'Componentes do template precisa ser um JSON válido.',
                ]);
            }
            $templateComponents = $decodedComponents;
        }
        if (!is_array($templateComponents)) {
            $templateComponents = [];
        }
        if ($templateVariables === [] && $templateComponents !== [] && !array_is_list($templateComponents)) {
            $templateVariables = $templateComponents;
        }

        $templateCategory = null;
        $template = null;
        if ($messageType === 'template') {
            $template = WhatsAppTemplate::getByNameForUser(
                trim((string)$input['template_name']),
                trim((string)($input['template_language'] ?? 'pt_BR')) ?: 'pt_BR',
                $obUser
            );

            if (!$template) {
                return self::json(404, [
                    'success' => false,
                    'message' => 'Template não encontrado.',
                ]);
            }

            if ((string)$template['status'] !== 'approved') {
                return self::json(422, [
                    'success' => false,
                    'message' => 'Template ainda não aprovado pela Meta.',
                ]);
            }

            $templateCategory = WhatsAppCostPolicy::normalizeCategory($template['category'] ?? 'MARKETING');
        }

        $recipients = self::normalizeRecipients($input['recipients'] ?? []);
        if ($recipients === []) {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe ao menos um destinatário válido.',
            ]);
        }

        try {
            $campaignId = WhatsAppCampaign::create([
                'tenancy_id' => $obUser['tenancy_id'],
                'user_id' => (int)$obUser['id'],
                'account_id' => $accountId,
                'name' => $name,
                'message_type' => $messageType,
                'message_body' => self::nullableString($input['message_body'] ?? null),
                'template_name' => self::nullableString($input['template_name'] ?? null),
                'template_language' => self::nullableString($input['template_language'] ?? 'pt_BR'),
                'template_category' => $templateCategory,
                'template_components' => $templateVariables,
                'scheduled_at' => self::nullableString($input['scheduled_at'] ?? null),
                'total_recipients' => count($recipients),
                'status' => 'draft',
            ]);

            foreach ($recipients as $recipient) {
                WhatsAppCampaign::addRecipient($campaignId, $recipient);
            }

            return self::json(201, [
                'success' => true,
                'message' => 'Campanha WhatsApp criada.',
                'id' => $campaignId,
                'total_recipients' => count($recipients),
            ]);
        } catch (\Throwable $e) {
            return self::json(500, [
                'success' => false,
                'message' => 'Falha ao criar campanha WhatsApp.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function sendCampaign($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $campaign = WhatsAppCampaign::getForUser((int)$id, $obUser);
        if (!$campaign) {
            return self::json(404, [
                'success' => false,
                'message' => 'Campanha WhatsApp não encontrada.',
            ]);
        }

        $campaignStatus = strtolower((string)($campaign['status'] ?? 'draft'));
        if (in_array($campaignStatus, ['queued', 'sending', 'finished', 'sent', 'cancelled'], true)) {
            return self::json(409, [
                'success' => false,
                'message' => 'Esta campanha já foi enviada ou está em processamento.',
                'status' => $campaignStatus,
            ]);
        }

        $account = WhatsAppAccount::getForUser((int)$campaign['account_id'], $obUser);
        if (!$account) {
            return self::json(404, [
                'success' => false,
                'message' => 'Número WhatsApp da campanha não encontrado.',
            ]);
        }

        try {
            WhatsAppNumberSafety::assertVerifiedForUse($account);
        } catch (\Throwable $e) {
            WhatsAppCampaign::markStatus((int)$campaign['id'], 'failed');

            return self::json(409, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }

        $recipients = WhatsAppCampaign::getPendingRecipients((int)$campaign['id']);
        if ($recipients === []) {
            return self::json(200, [
                'success' => true,
                'message' => 'Campanha sem destinatários pendentes.',
            ]);
        }

        WhatsAppCampaign::markStatus((int)$campaign['id'], 'queued');

        $queued = 0;
        $failed = 0;
        $errors = [];
        $templateCategory = null;
        $template = null;

        if ($campaign['message_type'] === 'template') {
            $template = WhatsAppTemplate::getByNameForUser(
                (string)$campaign['template_name'],
                (string)($campaign['template_language'] ?: 'pt_BR'),
                $obUser
            );

            if (!$template) {
                WhatsAppCampaign::markStatus((int)$campaign['id'], 'failed');

                return self::json(404, [
                    'success' => false,
                    'message' => 'Template não encontrado.',
                ]);
            }

            if (!self::templateMatchesAccount($template, $account)) {
                WhatsAppCampaign::markStatus((int)$campaign['id'], 'failed');

                return self::json(403, [
                    'success' => false,
                    'message' => 'Template não pertence à WABA selecionada.',
                ]);
            }

            if ((string)$template['status'] !== 'approved') {
                WhatsAppCampaign::markStatus((int)$campaign['id'], 'failed');

                return self::json(422, [
                    'success' => false,
                    'message' => 'Template ainda não aprovado pela Meta.',
                ]);
            }

            $templateCategory = WhatsAppCostPolicy::normalizeCategory($template['category'] ?? 'MARKETING');
        }

        foreach ($recipients as $recipient) {
            try {
                $recipientPhone = (string)$recipient['phone'];
                $lastInboundAt = WhatsAppConversation::getLastInboundAt((int)$account['id'], $recipientPhone);
                $serviceWindowOpen = WhatsAppCostPolicy::isServiceWindowOpen($lastInboundAt);

                if ($campaign['message_type'] === 'text' && !$serviceWindowOpen) {
                    $failed++;
                    $error = 'Este contato está fora da janela de 24 horas. Para iniciar uma nova conversa, utilize um template aprovado.';
                    self::auditTemplateWindowEvent('whatsapp_campaign_free_text_blocked_window_closed', [
                        'tenancy_id' => (string)$obUser['tenancy_id'],
                        'user_id' => (int)$obUser['id'],
                        'account_id' => (int)$account['id'],
                        'campaign_id' => (int)$campaign['id'],
                        'contact_phone' => self::maskPhoneForLog($recipientPhone),
                        'last_inbound_at' => $lastInboundAt,
                    ]);
                    $errors[] = ['phone' => $recipientPhone, 'error' => $error];
                    WhatsAppCampaign::updateRecipientResult((int)$recipient['id'], 'failed', null, $error);
                    continue;
                }

                if (
                    $campaign['message_type'] === 'template'
                    && $templateCategory === WhatsAppCostPolicy::CATEGORY_MARKETING
                    && WhatsAppConversation::hasMarketingOptOut((int)$account['id'], $recipientPhone)
                ) {
                    $failed++;
                    $error = 'Marketing bloqueado: destinatário solicitou descadastro.';
                    $errors[] = ['phone' => $recipientPhone, 'error' => $error];
                    WhatsAppCampaign::updateRecipientResult((int)$recipient['id'], 'failed', null, $error);
                    continue;
                }

                $variables = json_decode((string)($campaign['template_components'] ?? '[]'), true);
                if (!is_array($variables)) {
                    $variables = [];
                }
                $recipientVariables = json_decode((string)($recipient['template_variables'] ?? '[]'), true);
                if (is_array($recipientVariables) && $recipientVariables !== []) {
                    $variables = array_replace_recursive($variables, $recipientVariables);
                }

                $templateComponents = [];
                if ($campaign['message_type'] === 'template') {
                    $contactContext = self::findContactContextByPhone((string)$obUser['tenancy_id'], $recipientPhone);
                    $resolvedTemplate = WhatsAppTemplateVariableResolver::buildSendComponents($template, [
                        'tenancy_id' => $obUser['tenancy_id'],
                        'tenant_name' => self::tenantName((string)$obUser['tenancy_id']),
                        'contact_name' => self::nullableString($recipient['name'] ?? null)
                            ?: self::nullableString($contactContext['name'] ?? null),
                        'contact_agency' => self::nullableString($contactContext['agency'] ?? $contactContext['agencia'] ?? $contactContext['branch'] ?? null),
                        'contact_name_fallback' => self::templateContactFallback(),
                        'contact_phone' => $recipientPhone,
                    ], $variables);
                    $templateComponents = $resolvedTemplate['components'];
                    if (!$serviceWindowOpen) {
                        self::auditTemplateWindowEvent('whatsapp_campaign_template_reopen_window_closed', [
                            'tenancy_id' => (string)$obUser['tenancy_id'],
                            'user_id' => (int)$obUser['id'],
                            'account_id' => (int)$account['id'],
                            'campaign_id' => (int)$campaign['id'],
                            'template_name' => (string)$campaign['template_name'],
                            'contact_phone' => self::maskPhoneForLog($recipientPhone),
                            'parameters' => $resolvedTemplate['resolved'] ?? [],
                            'last_inbound_at' => $lastInboundAt,
                        ]);
                    }
                }

                $plannedMessages = $campaign['message_type'] === 'template'
                    ? WhatsAppMessagePlanner::planTemplate(
                        (string)$campaign['template_name'],
                        (string)($campaign['template_language'] ?: 'pt_BR'),
                        $templateCategory,
                        $templateComponents,
                        $resolvedTemplate['preview_body'] ?? ($template['body'] ?? null)
                    )
                    : WhatsAppMessagePlanner::planText((string)$campaign['message_body'], $serviceWindowOpen);
                if ($campaign['message_type'] === 'template' && isset($plannedMessages[0])) {
                    $plannedMessages[0]['preview_body'] = $resolvedTemplate['preview_body'] ?? $plannedMessages[0]['body'];
                    $plannedMessages[0]['template_variables'] = $resolvedTemplate['resolved'] ?? [];
                }

                foreach ($plannedMessages as $planned) {
                    if (
                        ($planned['template_category'] ?? null) === WhatsAppCostPolicy::CATEGORY_MARKETING
                        && WhatsAppConversation::hasMarketingOptOut((int)$account['id'], $recipientPhone)
                    ) {
                        continue;
                    }

                    $billableMessage = self::withWhatsAppBilling(
                        [
                            'tenancy_id' => $obUser['tenancy_id'],
                            'user_id' => (int)$obUser['id'],
                            'account_id' => (int)$account['id'],
                            'campaign_id' => (int)$campaign['id'],
                            'campaign_recipient_id' => (int)$recipient['id'],
                            'contact_phone' => $recipientPhone,
                            'contact_name' => $recipient['name'] ?? null,
                        ],
                        $planned,
                        $serviceWindowOpen
                    );

                    WhatsAppBilling::assertCanSend(
                        (int)$obUser['id'],
                        (string)$obUser['tenancy_id'],
                        (string)$billableMessage['message_category'],
                        (float)$billableMessage['price_brl']
                    );
                    WhatsAppOutbox::enqueue($billableMessage);
                    WhatsAppCampaign::updateRecipientResult((int)$recipient['id'], 'queued', null, null);
                    $queued++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['phone' => $recipient['phone'], 'error' => $e->getMessage()];
                WhatsAppCampaign::updateRecipientResult((int)$recipient['id'], 'failed', null, $e->getMessage());
            }
        }

        WhatsAppCampaign::updateCounters((int)$campaign['id']);

        $firstError = $errors[0]['error'] ?? null;
        if ($queued === 0 && $failed > 0) {
            return self::json(422, [
                'success' => false,
                'message' => 'Nenhum contato foi enfileirado. ' . ($firstError ? 'Primeiro erro: ' . $firstError : 'Verifique os dados da campanha.'),
                'queued' => $queued,
                'failed' => $failed,
                'template_category' => $templateCategory,
                'errors' => array_slice($errors, 0, 20),
            ]);
        }

        return self::json(200, [
            'success' => true,
            'message' => $failed === 0
                ? "{$queued} contato(s) enviado(s) para a fila."
                : "{$queued} contato(s) enviado(s) para a fila. {$failed} contato(s) falharam. " . ($firstError ? 'Primeiro erro: ' . $firstError : ''),
            'queued' => $queued,
            'failed' => $failed,
            'template_category' => $templateCategory,
            'errors' => array_slice($errors, 0, 20),
        ]);
    }

    public static function cancelCampaignCategory($request, int|string $id): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $campaign = WhatsAppCampaign::getForUser((int)$id, $obUser);
        if (!$campaign) {
            return self::json(404, [
                'success' => false,
                'message' => 'Campanha WhatsApp não encontrada.',
            ]);
        }

        $input = self::jsonInput();
        $category = WhatsAppCostPolicy::normalizeCategory((string)($input['category'] ?? 'MARKETING'));
        $cancelled = WhatsAppOutbox::cancelByCampaignAndCategory((int)$campaign['id'], $category);

        return self::json(200, [
            'success' => true,
            'message' => 'Mensagens canceladas por categoria.',
            'category' => $category,
            'cancelled' => $cancelled,
        ]);
    }

    public static function listSupportQueues(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppSupportDesk::listQueuesForUser($obUser),
        ]);
    }

    public static function createSupportQueue(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        try {
            $id = WhatsAppSupportDesk::createQueue($obUser, self::jsonInput());

            return self::json(201, [
                'success' => true,
                'message' => 'Fila de atendimento criada.',
                'id' => $id,
            ]);
        } catch (\InvalidArgumentException $e) {
            return self::json(422, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            return self::json(500, [
                'success' => false,
                'message' => 'Falha ao criar fila de atendimento.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function upsertSupportQueueAgent($request, int|string $queueId): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $ok = WhatsAppSupportDesk::upsertQueueAgent($obUser, (int)$queueId, self::jsonInput());

        return self::json($ok ? 200 : 404, [
            'success' => $ok,
            'message' => $ok ? 'Atendente vinculado à fila.' : 'Fila de atendimento não encontrada.',
        ]);
    }

    public static function updateSupportAgentStatus(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $ok = WhatsAppSupportDesk::updateAgentStatus($obUser, self::jsonInput());

        return self::json($ok ? 200 : 404, [
            'success' => $ok,
            'message' => $ok ? 'Status do atendente atualizado.' : 'Fila de atendimento não encontrada.',
        ]);
    }

    public static function supportDashboard(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        return self::json(200, [
            'success' => true,
            'data' => WhatsAppSupportDesk::dashboard($obUser),
        ]);
    }

    public static function finishSupportSession($request, int|string $sessionId): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $ok = WhatsAppSupportDesk::finishSession($obUser, (int)$sessionId);

        return self::json($ok ? 200 : 404, [
            'success' => $ok,
            'message' => $ok ? 'Atendimento finalizado.' : 'Sessão de atendimento não encontrada.',
        ]);
    }

    public static function transferSupportSession($request, int|string $sessionId): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $ok = WhatsAppSupportDesk::transferSession($obUser, (int)$sessionId, self::jsonInput());

        return self::json($ok ? 200 : 404, [
            'success' => $ok,
            'message' => $ok ? 'Atendimento transferido.' : 'Sessão, fila ou atendente indisponível.',
        ]);
    }

    public static function supportEvents($request): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $query = $request->getQueryParams();
        $headers = $request->getHeaders();
        $afterId = (int)($query['after_id'] ?? $query['lastEventId'] ?? $headers['Last-Event-ID'] ?? $headers['Last-Event-Id'] ?? 0);
        $events = WhatsAppSupportDesk::events($obUser, $afterId);
        $content = "retry: 2000\n";

        foreach ($events as $event) {
            $content .= 'id: ' . (int)$event['id'] . "\n";
            $content .= 'event: ' . (string)$event['event_type'] . "\n";
            $content .= 'data: ' . ($event['payload'] ?: '{}') . "\n\n";
        }

        if ($events === []) {
            $content .= "event: heartbeat\n";
            $content .= 'data: {"ok":true}' . "\n\n";
        }

        $response = new Response(200, $content, 'text/event-stream');
        $response->addHeader('Cache-Control', 'no-cache');
        $response->addHeader('X-Accel-Buffering', 'no');

        return $response;
    }

    public static function sendSupportMessage(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $input = self::jsonInput();
        $to = self::normalizePhone((string)($input['to'] ?? ''));
        $message = trim((string)($input['message'] ?? ''));

        if (strlen($to) < 8 || strlen($to) > 15) {
            return self::json(422, [
                'success' => false,
                'message' => 'Informe seu WhatsApp com DDI e DDD.',
            ]);
        }

        if ($message === '') {
            return self::json(422, [
                'success' => false,
                'message' => 'Digite a mensagem para enviar.',
            ]);
        }

        $account = WhatsAppAccount::getSupportAccount();
        if (!$account) {
            return self::json(404, [
                'success' => false,
                'message' => 'Conta WhatsApp central do suporte não configurada.',
            ]);
        }

        try {
            WhatsAppNumberSafety::assertVerifiedForUse($account);
        } catch (\Throwable $e) {
            return self::json(409, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }

        $lastInboundAt = WhatsAppConversation::getLastInboundAt((int)$account['id'], $to);
        if (!WhatsAppCostPolicy::isServiceWindowOpen($lastInboundAt)) {
            return self::json(422, [
                'success' => false,
                'message' => 'Este contato está fora da janela de 24 horas. Para iniciar uma nova conversa, utilize um template aprovado.',
                'last_inbound_at' => $lastInboundAt,
            ]);
        }

        $serviceWindowOpen = true;
        $conversationId = WhatsAppConversation::findOrCreate([
            'tenancy_id' => $account['tenancy_id'],
            'user_id' => (int)$account['user_id'],
            'account_id' => (int)$account['id'],
            'contact_phone' => $to,
            'last_message' => $message,
            'last_direction' => 'outbound',
            'unread_count' => 0,
        ]);

        $plannedMessages = WhatsAppMessagePlanner::planText($message, $serviceWindowOpen);
        $billableMessages = [];
        foreach ($plannedMessages as $planned) {
            $billableMessages[] = self::withWhatsAppBilling([
                'tenancy_id' => $account['tenancy_id'],
                'user_id' => (int)$account['user_id'],
                'account_id' => (int)$account['id'],
                'conversation_id' => $conversationId,
                'contact_phone' => $to,
            ], $planned, $serviceWindowOpen);
        }

        try {
            WhatsAppBilling::assertCanSendBatch((int)$account['user_id'], (string)$account['tenancy_id'], $billableMessages);
        } catch (\Throwable $e) {
            return self::json(402, [
                'success' => false,
                'message' => $e->getMessage() === WhatsAppBilling::ERROR_INSUFFICIENT_BALANCE
                    ? WhatsAppBilling::ERROR_INSUFFICIENT_BALANCE
                    : $e->getMessage(),
            ]);
        }

        $outboxIds = [];
        foreach ($billableMessages as $billableMessage) {
            $outboxIds[] = WhatsAppOutbox::enqueue($billableMessage);
        }

        return self::json(202, [
            'success' => true,
            'message' => 'Mensagem enviada.',
            'conversation_id' => $conversationId,
            'outbox_ids' => $outboxIds,
            'parts' => $plannedMessages,
            'counters' => WhatsAppMessagePlanner::summarize($plannedMessages),
        ]);
    }

    private static function requireUser(): array|Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return self::json(401, [
                'success' => false,
                'message' => 'Usuário não autenticado.',
            ]);
        }

        return $obUser;
    }

    private static function canUseSupportAccount(array $user): bool
    {
        $role = strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));
        return in_array($role, ['super_admin', 'admin', 'support_l2'], true);
    }

    private static function canManageWhatsAppNumbers(array $user): bool
    {
        $role = strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));
        return $role === 'super_admin';
    }

    public static function previewCampaignRecipientsUpload(): Response
    {
        $obUser = self::requireUser();
        if ($obUser instanceof Response) {
            return $obUser;
        }

        $file = $_FILES['file'] ?? $_FILES['contacts'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return self::json(422, [
                'success' => false,
                'message' => 'Envie uma planilha CSV, XLS ou XLSX.',
            ]);
        }

        try {
            $parsed = self::parseCampaignRecipientsSpreadsheet($file);

            return self::json(200, [
                'success' => true,
                'message' => 'Planilha lida com sucesso.',
                'data' => $parsed,
            ]);
        } catch (\Throwable $e) {
            return self::json(422, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function json(int $status, array $payload): Response
    {
        return new Response($status, $payload, 'application/json');
    }

    private static function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);

        if (is_array($data)) {
            return $data;
        }

        return $_POST ?: [];
    }

    private static function storeInboundAudioMedia(array $account, array $message): ?array
    {
        $mediaId = trim((string)($message['audio']['id'] ?? ''));
        if ($mediaId === '') {
            return null;
        }

        $api = new MetaWhatsAppCloudApi();
        $media = $api->getMedia((string)$account['access_token'], $mediaId);
        if (!$media['ok']) {
            error_log('[whatsapp_audio_media] ' . ($media['error'] ?: 'Falha ao obter mídia.'));
            return null;
        }

        $mimeType = (string)($media['data']['mime_type'] ?? $message['audio']['mime_type'] ?? 'audio/ogg');
        $mediaUrl = trim((string)($media['data']['url'] ?? ''));
        if ($mediaUrl === '') {
            error_log('[whatsapp_audio_media] Meta não retornou URL para o áudio.');
            return null;
        }

        $target = self::whatsappAudioTarget($mediaId, $mimeType);
        $download = $api->downloadMediaToFile(
            (string)$account['access_token'],
            $mediaUrl,
            $target['path']
        );

        if (!$download['ok']) {
            error_log('[whatsapp_audio_download] ' . ($download['error'] ?: 'Falha ao baixar áudio.'));
            return null;
        }

        return [
            'id' => $mediaId,
            'type' => 'audio',
            'mime_type' => $mimeType,
            'sha256' => $message['audio']['sha256'] ?? $media['data']['sha256'] ?? null,
            'file_size' => $media['data']['file_size'] ?? $download['data']['size'] ?? null,
            'path' => $target['relative_path'],
            'url' => $target['url'],
        ];
    }

    private static function storeUploadedAudio(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Falha no upload do áudio.');
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Arquivo de áudio inválido.');
        }

        $mimeType = self::detectMimeType($tmp, (string)($file['type'] ?? 'audio/ogg'));
        if (!str_starts_with($mimeType, 'audio/')) {
            throw new \RuntimeException('Envie um arquivo de áudio válido.');
        }

        $target = self::whatsappAudioTarget('upload_' . bin2hex(random_bytes(8)), $mimeType);
        if (!move_uploaded_file($tmp, $target['path'])) {
            throw new \RuntimeException('Não foi possível salvar o áudio.');
        }

        return [
            'type' => 'audio',
            'mime_type' => $mimeType,
            'file_size' => filesize($target['path']) ?: null,
            'path' => $target['relative_path'],
            'url' => $target['url'],
            'original_name' => basename((string)($file['name'] ?? 'audio')),
        ];
    }

    private static function mediaFromPublicAudioUrl(string $url): array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Informe uma URL pública válida para o áudio.');
        }

        return [
            'type' => 'audio',
            'mime_type' => null,
            'path' => null,
            'url' => $url,
        ];
    }

    private static function whatsappAudioTarget(string $seed, string $mimeType): array
    {
        $relativeDir = 'public/uploads/whatsapp/audio/' . date('Y/m');
        $dir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $extension = self::audioExtension($mimeType);
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $seed) ?: 'audio';
        $filename = $name . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $relativePath = $relativeDir . '/' . $filename;

        return [
            'path' => $dir . DIRECTORY_SEPARATOR . $filename,
            'relative_path' => $relativePath,
            'url' => rtrim((string)(defined('URL') ? URL : ''), '/') . '/' . $relativePath,
        ];
    }

    private static function audioExtension(string $mimeType): string
    {
        $mimeType = strtolower($mimeType);
        return match (true) {
            str_contains($mimeType, 'mpeg') || str_contains($mimeType, 'mp3') => 'mp3',
            str_contains($mimeType, 'mp4') || str_contains($mimeType, 'aac') => 'm4a',
            str_contains($mimeType, 'amr') => 'amr',
            str_contains($mimeType, 'webm') => 'webm',
            default => 'ogg',
        };
    }

    private static function detectMimeType(string $path, string $fallback): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }

        return $fallback ?: 'audio/ogg';
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private static function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }

    private static function maskPhoneForLog(string $phone): string
    {
        $phone = self::normalizePhone($phone);
        if (strlen($phone) <= 6) {
            return '***';
        }

        return substr($phone, 0, 4) . '***' . substr($phone, -2);
    }

    private static function findContactNameByPhone(string $tenancyId, string $phone): ?string
    {
        $context = self::findContactContextByPhone($tenancyId, $phone);
        return self::nullableString($context['name'] ?? null);
    }

    private static function findContactContextByPhone(string $tenancyId, string $phone): array
    {
        $phone = self::normalizePhone($phone);
        if ($tenancyId === '' || $phone === '') {
            return [];
        }

        try {
            $row = (new \WilliamCosta\DatabaseManager\Database('contacts'))
                ->select(
                    "tenancy_id = :tenancy_id
                     AND REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '(', '') LIKE :phone
                     AND COALESCE(name, '') <> ''",
                    [
                        ':tenancy_id' => $tenancyId,
                        ':phone' => '%' . substr($phone, -8),
                    ],
                    'updated_at DESC, id DESC',
                    '1'
                )
                ->fetch(\PDO::FETCH_ASSOC);

            return is_array($row) ? $row : [];
        } catch (\Throwable $e) {
            error_log('[whatsapp_contact_lookup] ' . $e->getMessage());
            return [];
        }
    }

    private static function tenantName(string $tenancyId): ?string
    {
        if ($tenancyId === '') {
            return null;
        }

        try {
            $row = (new \WilliamCosta\DatabaseManager\Database('tenancies'))
                ->select('id = :id', [':id' => $tenancyId], '', '1', ['name'])
                ->fetch(\PDO::FETCH_ASSOC);

            return self::nullableString($row['name'] ?? null);
        } catch (\Throwable $e) {
            error_log('[whatsapp_tenant_lookup] ' . $e->getMessage());
            return null;
        }
    }

    private static function templateContactFallback(): string
    {
        $value = trim((string)(getenv('WHATSAPP_TEMPLATE_CONTACT_FALLBACK') ?: 'cliente'));
        return $value !== '' ? $value : 'cliente';
    }

    private static function auditTemplateWindowEvent(string $event, array $context): void
    {
        $maskedContext = self::maskSensitivePayload($context);
        error_log(json_encode(array_merge([
            'event' => $event,
            'created_at' => date('c'),
        ], $maskedContext), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            self::ensureTemplateEventLogTable();
            (new \WilliamCosta\DatabaseManager\Database('whatsapp_template_event_logs'))->insert([
                'event' => $event,
                'tenancy_id' => (string)($context['tenancy_id'] ?? ''),
                'user_id' => isset($context['user_id']) ? (int)$context['user_id'] : null,
                'account_id' => isset($context['account_id']) ? (int)$context['account_id'] : null,
                'conversation_id' => isset($context['conversation_id']) ? (int)$context['conversation_id'] : null,
                'contact_phone' => isset($context['contact_phone']) ? (string)$context['contact_phone'] : null,
                'template_name' => isset($context['template_name']) ? (string)$context['template_name'] : null,
                'payload_json' => isset($maskedContext['payload'])
                    ? json_encode($maskedContext['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'response_json' => isset($maskedContext['response'])
                    ? json_encode($maskedContext['response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'error_message' => isset($context['error']) ? (string)$context['error'] : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[whatsapp_template_event_log] ' . $e->getMessage());
        }
    }

    private static function ensureTemplateEventLogTable(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        (new \WilliamCosta\DatabaseManager\Database())->execute("
            CREATE TABLE IF NOT EXISTS whatsapp_template_event_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event VARCHAR(80) NOT NULL,
                tenancy_id VARCHAR(64) NOT NULL,
                user_id INT UNSIGNED NULL,
                account_id INT UNSIGNED NULL,
                conversation_id INT UNSIGNED NULL,
                contact_phone VARCHAR(32) NULL,
                template_name VARCHAR(160) NULL,
                payload_json JSON NULL,
                response_json JSON NULL,
                error_message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_whatsapp_template_event_tenancy (tenancy_id, created_at),
                KEY idx_whatsapp_template_event_template (template_name, created_at),
                KEY idx_whatsapp_template_event_account (account_id, created_at),
                KEY idx_whatsapp_template_event_event (event, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $ensured = true;
    }

    private static function maskSensitivePayload(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $masked = [];
        foreach ($value as $key => $item) {
            $lowerKey = strtolower((string)$key);
            if (str_contains($lowerKey, 'token') || str_contains($lowerKey, 'secret') || str_contains($lowerKey, 'authorization')) {
                $masked[$key] = '***';
                continue;
            }
            $masked[$key] = is_array($item) ? self::maskSensitivePayload($item) : $item;
        }

        return $masked;
    }

    private static function normalizeRecipients(mixed $raw): array
    {
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed !== '' && in_array($trimmed[0], ['[', '{'], true)) {
                $decoded = json_decode($trimmed, true);
                $raw = is_array($decoded) ? $decoded : [];
            } else {
                $csvRows = self::parseRecipientCsv($trimmed);
                $raw = $csvRows !== [] ? $csvRows : (preg_split('/[\r\n,;]+/', $raw) ?: []);
            }
        }

        if (!is_array($raw)) {
            return [];
        }

        $recipients = [];
        foreach ($raw as $item) {
            $name = null;
            $phone = '';

            if (is_array($item)) {
                $phone = self::normalizePhone((string)($item['phone'] ?? $item['telefone'] ?? $item['number'] ?? ''));
                $name = self::nullableString($item['name'] ?? $item['nome'] ?? null);
            } else {
                $phone = self::normalizePhone((string)$item);
            }

            if (strlen($phone) < 8 || strlen($phone) > 15 || isset($recipients[$phone])) {
                continue;
            }

            $recipients[$phone] = [
                'phone' => $phone,
                'name' => $name,
                'template_variables' => is_array($item) ? self::recipientTemplateVariables($item) : [],
            ];
        }

        return array_values($recipients);
    }

    private static function parseRecipientCsv(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $lines = array_values(array_filter(
            preg_split('/\r\n|\n|\r/', $raw) ?: [],
            static fn (string $line): bool => trim($line) !== ''
        ));
        if (count($lines) < 2) {
            return [];
        }

        $headerLine = $lines[0];
        $delimiter = str_contains($headerLine, ';') ? ';' : (str_contains($headerLine, "\t") ? "\t" : ',');
        $headers = array_map([self::class, 'normalizeRecipientColumn'], str_getcsv($headerLine, $delimiter));
        if (!in_array('phone', $headers, true)) {
            return [];
        }

        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $values = str_getcsv($line, $delimiter);
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $row[$header] = $values[$index] ?? '';
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function normalizeRecipientColumn(string $column): string
    {
        $column = trim(mb_strtolower($column, 'UTF-8'));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $column);
        if (is_string($ascii) && $ascii !== '') {
            $column = $ascii;
        }
        $column = trim(preg_replace('/[^a-z0-9]+/', '_', $column) ?? '', '_');

        return match ($column) {
            'telefone', 'celular', 'whatsapp', 'numero', 'number' => 'phone',
            'nome', 'cliente', 'nome_cliente' => 'name',
            default => $column,
        };
    }

    private static function recipientTemplateVariables(array $item): array
    {
        $variables = is_array($item['template_variables'] ?? null) ? $item['template_variables'] : [];
        foreach ($item as $key => $value) {
            $normalized = self::normalizeRecipientColumn((string)$key);
            if (in_array($normalized, ['phone', 'name', 'template_variables'], true)) {
                continue;
            }
            if (is_scalar($value) && trim((string)$value) !== '') {
                $variables[$normalized] = trim((string)$value);
            }
        }

        return $variables;
    }

    private static function parseCampaignRecipientsSpreadsheet(array $file): array
    {
        $originalName = (string)($file['name'] ?? 'planilha');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xls', 'xlsx'], true)) {
            throw new \RuntimeException('Tipo de arquivo não suportado. Envie CSV, XLS ou XLSX.');
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Arquivo inválido.');
        }

        $readerType = match ($extension) {
            'csv' => 'Csv',
            'xls' => 'Xls',
            default => 'Xlsx',
        };
        $reader = IOFactory::createReader($readerType);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        if ($extension === 'csv' && method_exists($reader, 'setDelimiter')) {
            $firstLine = (string)(file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)[0] ?? '');
            $delimiter = str_contains($firstLine, ';') ? ';' : (str_contains($firstLine, "\t") ? "\t" : ',');
            $reader->setDelimiter($delimiter);
        }

        $spreadsheet = $reader->load($tmp);
        $sheet = $spreadsheet->getActiveSheet();
        $matrix = $sheet->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();

        $matrix = array_values(array_filter($matrix, static function (array $row): bool {
            return count(array_filter($row, static fn ($value): bool => trim((string)$value) !== '')) > 0;
        }));

        if (count($matrix) < 2) {
            throw new \RuntimeException('A planilha precisa ter cabeçalho e ao menos um contato.');
        }

        $headerRow = array_shift($matrix);
        $headers = [];
        foreach ($headerRow as $index => $label) {
            $label = trim((string)$label);
            if ($label === '') {
                $label = 'Coluna ' . ($index + 1);
            }
            $headers[] = [
                'index' => $index,
                'label' => $label,
                'normalized' => self::normalizeRecipientColumn($label),
            ];
        }

        $rows = [];
        $maxRows = 5000;
        foreach ($matrix as $rowIndex => $row) {
            if ($rowIndex >= $maxRows) {
                break;
            }
            $values = [];
            foreach ($headers as $header) {
                $value = $row[$header['index']] ?? '';
                $values[] = trim((string)$value);
            }
            if (count(array_filter($values, static fn ($value): bool => $value !== '')) > 0) {
                $rows[] = $values;
            }
        }

        if ($rows === []) {
            throw new \RuntimeException('Nenhum contato encontrado na planilha.');
        }

        return [
            'filename' => basename($originalName),
            'headers' => $headers,
            'rows' => $rows,
            'total_rows' => count($matrix),
            'loaded_rows' => count($rows),
            'truncated' => count($matrix) > $maxRows,
            'suggested' => [
                'phone' => self::suggestRecipientColumn($headers, ['phone', 'telefone', 'celular', 'whatsapp', 'numero', 'number']),
                'name' => self::suggestRecipientColumn($headers, ['name', 'nome', 'cliente', 'nome_cliente']),
            ],
        ];
    }

    private static function suggestRecipientColumn(array $headers, array $candidates): ?int
    {
        foreach ($headers as $header) {
            if (in_array((string)($header['normalized'] ?? ''), $candidates, true)) {
                return (int)$header['index'];
            }
        }

        return null;
    }

    private static function buildMetaTemplatePayload(
        string $name,
        string $language,
        string $category,
        ?string $body,
        ?array $components
    ): array {
        $payloadComponents = [];
        if ($components !== null && $components !== []) {
            foreach ($components as $component) {
                if (is_array($component)) {
                    $component['type'] = strtoupper((string)($component['type'] ?? 'BODY'));
                    if (isset($component['text']) || isset($component['format']) || isset($component['buttons'])) {
                        $payloadComponents[] = $component;
                    }
                }
            }
        }

        if ($payloadComponents === [] && $body !== null && trim($body) !== '') {
            $bodyComponent = [
                'type' => 'BODY',
                'text' => $body,
            ];

            $variables = self::templateVariables($body);
            if ($variables !== []) {
                $bodyComponent['example'] = [
                    'body_text' => [
                        array_map(fn ($index) => 'exemplo_' . $index, $variables),
                    ],
                ];
            }

            $payloadComponents[] = $bodyComponent;
        }

        return [
            'name' => $name,
            'language' => $language,
            'category' => $category,
            'components' => $payloadComponents,
        ];
    }

    private static function importExistingMetaTemplate(array $account, array $user, string $name, string $language): ?Response
    {
        try {
            $meta = (new MetaWhatsAppCloudApi())->listMessageTemplates(
                (string)$account['access_token'],
                (string)$account['waba_id']
            );
            if (!$meta['ok']) {
                return null;
            }

            foreach (($meta['data']['data'] ?? []) as $template) {
                if (!is_array($template)
                    || (string)($template['name'] ?? '') !== $name
                    || (string)($template['language'] ?? '') !== $language
                ) {
                    continue;
                }

                WhatsAppTemplate::upsertFromMeta($account, $template, $user);
                $row = WhatsAppTemplate::getByNameForTenant($name, $language, (string)$account['tenancy_id']);

                return self::json(200, [
                    'success' => true,
                    'message' => 'Template encontrado na Meta e sincronizado na biblioteca local.',
                    'data' => $row,
                    'meta' => $template,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[whatsapp_template_import_existing] ' . $e->getMessage());
        }

        return null;
    }

    private static function localTemplatesForAccount(array $account): array
    {
        $wabaId = (string)($account['waba_id'] ?? '');
        $where = 'tenancy_id = :tenancy_id AND account_id = :account_id';
        $params = [
            ':tenancy_id' => (string)$account['tenancy_id'],
            ':account_id' => (int)$account['id'],
        ];

        if ($wabaId !== '') {
            $where .= ' AND (waba_id = :waba_id OR waba_id IS NULL)';
            $params[':waba_id'] = $wabaId;
        }

        return (new \WilliamCosta\DatabaseManager\Database('whatsapp_templates'))
            ->select($where, $params, 'id ASC')
            ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function submitLocalTemplateToMeta(array $account, array $template): array
    {
        $components = self::jsonColumnToArray($template['components'] ?? null);
        $body = self::nullableString($template['body'] ?? null);

        if (($components === null || $components === []) && $body === null) {
            return [
                'ok' => false,
                'status' => 422,
                'data' => null,
                'error' => 'Template local sem BODY/components para enviar à Meta.',
            ];
        }

        $payload = self::buildMetaTemplatePayload(
            (string)$template['name'],
            (string)($template['language'] ?: 'pt_BR'),
            strtoupper((string)($template['category'] ?: 'UTILITY')),
            $body,
            is_array($components) ? $components : null
        );

        if (empty($payload['components'])) {
            return [
                'ok' => false,
                'status' => 422,
                'data' => null,
                'error' => 'Template local sem componentes válidos para enviar à Meta.',
            ];
        }

        return (new MetaWhatsAppCloudApi())->createMessageTemplate(
            (string)$account['access_token'],
            (string)$account['waba_id'],
            $payload
        );
    }

    private static function indexMetaTemplates(array $templates): array
    {
        $index = [];
        foreach ($templates as $template) {
            if (!is_array($template)) {
                continue;
            }

            $name = (string)($template['name'] ?? '');
            $language = (string)($template['language'] ?? '');
            if ($name !== '' && $language !== '') {
                $index[self::templateIdentityKey($name, $language)] = $template;
            }
        }

        return $index;
    }

    private static function templateIdentityKey(string $name, string $language): string
    {
        return strtolower(trim($name)) . "\n" . strtolower(trim($language));
    }

    private static function templateMatchesAccount(array $template, array $account): bool
    {
        $templateTenancy = (string)($template['tenancy_id'] ?? '');
        $accountTenancy = (string)($account['tenancy_id'] ?? '');
        if ($templateTenancy === '' || $templateTenancy !== $accountTenancy) {
            return false;
        }

        $templateWaba = trim((string)($template['waba_id'] ?? ''));
        $accountWaba = trim((string)($account['waba_id'] ?? ''));
        if ($templateWaba !== '' && $accountWaba !== '' && $templateWaba !== $accountWaba) {
            return false;
        }

        $templateAccountId = (int)($template['account_id'] ?? 0);
        return $templateAccountId <= 0 || $templateAccountId === (int)($account['id'] ?? 0);
    }

    private static function canSubmitTemplateStatus(string $status): bool
    {
        return in_array($status, ['draft', 'pending', 'rejected', 'READY_TO_SUBMIT'], true);
    }

    private static function jsonColumnToArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function normalizeTemplateName(string $name): string
    {
        $name = trim(mb_strtolower($name, 'UTF-8'));
        if ($name === '') {
            return '';
        }

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if (is_string($ascii) && $ascii !== '') {
            $name = $ascii;
        }

        $name = preg_replace('/[^a-z0-9]+/', '_', $name) ?? '';
        return trim($name, '_');
    }

    private static function templateVariables(string $body): array
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $matches);
        $numbers = array_values(array_unique(array_map('intval', $matches[1] ?? [])));
        sort($numbers);
        return $numbers;
    }

    private static function validateTemplateDefinition(string $name, string $language, string $category, array $payload): array
    {
        $errors = [];
        if (!preg_match('/^[a-z0-9_]{1,512}$/', $name)) {
            $errors[] = 'Nome inválido para a Meta. Use somente letras minúsculas, números e underscore.';
        }
        if (!preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/', $language)) {
            $errors[] = 'Idioma inválido. Use códigos como pt_BR, en_US ou es.';
        }
        if (!in_array($category, ['MARKETING', 'UTILITY', 'AUTHENTICATION'], true)) {
            $errors[] = 'Categoria inválida para template.';
        }

        $components = is_array($payload['components'] ?? null) ? $payload['components'] : [];
        $hasBody = false;
        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }
            $type = strtoupper((string)($component['type'] ?? ''));
            if ($type === 'BODY' && trim((string)($component['text'] ?? '')) !== '') {
                $hasBody = true;
            }
            if ($type === 'BODY') {
                $variables = self::templateVariables((string)($component['text'] ?? ''));
                if (!self::variablesAreSequential($variables)) {
                    $errors[] = 'As variáveis do BODY devem ser sequenciais: {{1}}, {{2}}, {{3}}...';
                }
                if ($variables !== [] && empty($component['example']['body_text'][0])) {
                    $errors[] = 'A Meta exige exemplos/sample values para templates com variáveis no BODY.';
                }
            }
        }

        if (!$hasBody) {
            $errors[] = 'Template precisa ter componente BODY com texto.';
        }

        return array_values(array_unique($errors));
    }

    private static function variablesAreSequential(array $variables): bool
    {
        if ($variables === []) {
            return true;
        }

        return $variables === range(1, count($variables));
    }

    private static function simulateCommercialRound(float $value): float
    {
        $step = max(0.0001, (float)(getenv('WHATSAPP_PRICE_ROUND_STEP_BRL') ?: 0.01));
        $minimum = max(0.0, (float)(getenv('WHATSAPP_MIN_PRICE_BRL') ?: 0.05));
        return round(ceil(max($value, $minimum) / $step) * $step, 4);
    }

    private static function variableMapFromComponents(array $components): array
    {
        $text = '';
        foreach ($components as $component) {
            if (is_array($component) && strtoupper((string)($component['type'] ?? '')) === 'BODY') {
                $text .= "\n" . (string)($component['text'] ?? '');
            }
        }

        $map = [];
        foreach (self::templateVariables($text) as $position) {
            $map[(string)$position] = [
                'key' => $position === 1 ? 'nome_cliente' : 'variavel_' . $position,
                'description' => $position === 1 ? 'Nome do cliente' : 'Valor do parâmetro ' . $position,
            ];
        }

        return $map;
    }

    private static function localTemplateModels(): array
    {
        return [
            [
                'id' => 'utility_payment_due',
                'name' => 'aviso_vencimento',
                'language' => 'pt_BR',
                'category' => 'UTILITY',
                'header' => 'Aviso importante',
                'body' => 'Olá {{1}}, sua fatura vence em {{2}}. Caso já tenha pago, desconsidere esta mensagem.',
                'footer' => 'Atendimento {{3}}',
                'buttons' => [],
                'variable_map' => [
                    '1' => ['key' => 'nome_cliente', 'description' => 'Nome do cliente'],
                    '2' => ['key' => 'data_vencimento', 'description' => 'Data de vencimento'],
                    '3' => ['key' => 'nome_empresa', 'description' => 'Nome da empresa'],
                ],
            ],
            [
                'id' => 'utility_ticket_update',
                'name' => 'atualizacao_atendimento',
                'language' => 'pt_BR',
                'category' => 'UTILITY',
                'header' => 'Atualização de atendimento',
                'body' => 'Olá {{1}}, o protocolo {{2}} foi atualizado. Status atual: {{3}}.',
                'footer' => '{{4}}',
                'buttons' => [],
                'variable_map' => [
                    '1' => ['key' => 'nome_cliente', 'description' => 'Nome do cliente'],
                    '2' => ['key' => 'protocolo_atendimento', 'description' => 'Protocolo'],
                    '3' => ['key' => 'status_pedido', 'description' => 'Status'],
                    '4' => ['key' => 'nome_empresa', 'description' => 'Nome da empresa'],
                ],
            ],
            [
                'id' => 'marketing_news',
                'name' => 'novidade_cliente',
                'language' => 'pt_BR',
                'category' => 'MARKETING',
                'header' => 'Novidade para você',
                'body' => 'Olá {{1}}, temos uma novidade sobre {{2}}. Responda esta mensagem para falar com nossa equipe.',
                'footer' => 'Você pode solicitar descadastro a qualquer momento.',
                'buttons' => [],
                'variable_map' => [
                    '1' => ['key' => 'nome_cliente', 'description' => 'Nome do cliente'],
                    '2' => ['key' => 'assunto_novidade', 'description' => 'Assunto'],
                ],
            ],
            [
                'id' => 'auth_code',
                'name' => 'codigo_verificacao',
                'language' => 'pt_BR',
                'category' => 'AUTHENTICATION',
                'header' => null,
                'body' => '{{1}} é seu código de verificação. Não compartilhe este código.',
                'footer' => null,
                'buttons' => [],
                'variable_map' => [
                    '1' => ['key' => 'codigo_verificacao', 'description' => 'Código de verificação'],
                ],
            ],
        ];
    }

    private static function systemTemplateModels(array $user): array
    {
        $models = [];
        foreach (WhatsAppTemplate::listForUser($user) as $template) {
            $isSystem = (int)($template['is_system_template'] ?? 0) === 1
                || strtolower((string)($template['template_type'] ?? '')) === 'system';
            if (!$isSystem || (string)($template['status'] ?? '') !== 'approved') {
                continue;
            }

            $components = self::jsonColumnToArray($template['components'] ?? null) ?: [];
            $variableMap = self::jsonColumnToArray($template['variable_map'] ?? null) ?: [];
            $models[] = [
                'id' => 'system_' . (int)$template['id'],
                'source' => 'system_approved',
                'source_label' => 'Modelo aprovado do sistema',
                'template_id' => (int)$template['id'],
                'name' => (string)$template['name'],
                'suggested_name' => self::normalizeTemplateName((string)$template['name'] . '_cliente'),
                'language' => (string)($template['language'] ?: 'pt_BR'),
                'category' => strtoupper((string)($template['category'] ?: 'UTILITY')),
                'header' => self::componentText($components, 'HEADER'),
                'body' => (string)($template['body'] ?: self::componentText($components, 'BODY')),
                'footer' => self::componentText($components, 'FOOTER'),
                'buttons' => self::componentButtons($components),
                'components' => $components,
                'variable_map' => $variableMap,
            ];
        }

        return $models;
    }

    private static function componentText(array $components, string $type): ?string
    {
        foreach ($components as $component) {
            if (is_array($component) && strtoupper((string)($component['type'] ?? '')) === $type) {
                return self::nullableString($component['text'] ?? null);
            }
        }

        return null;
    }

    private static function componentButtons(array $components): array
    {
        foreach ($components as $component) {
            if (is_array($component) && strtoupper((string)($component['type'] ?? '')) === 'BUTTONS') {
                return is_array($component['buttons'] ?? null) ? $component['buttons'] : [];
            }
        }

        return [];
    }

    private static function businessProfilePayload(array $input): array
    {
        $payload = [];
        foreach (['about', 'description', 'email', 'address', 'vertical'] as $field) {
            $value = self::nullableString($input[$field] ?? null);
            if ($value !== null) {
                $payload[$field] = $field === 'vertical' ? strtoupper($value) : $value;
            }
        }

        $website = $input['website'] ?? $input['websites'] ?? null;
        if (is_string($website)) {
            $website = preg_split('/[\r\n,;]+/', $website) ?: [];
        }
        if (is_array($website)) {
            $websites = [];
            foreach ($website as $url) {
                $url = trim((string)$url);
                if ($url !== '' && preg_match('#^https?://#i', $url)) {
                    $websites[] = mb_substr($url, 0, 256);
                }
                if (count($websites) >= 2) {
                    break;
                }
            }
            if ($websites !== []) {
                $payload['websites'] = $websites;
            }
        }

        return $payload;
    }

    private static function persistBusinessProfile(array $user, int $accountId, array $profile, ?string $error): void
    {
        $values = [
            'profile_last_error' => $error,
        ];

        if ($error === null) {
            $values['profile_updated_at'] = date('Y-m-d H:i:s');
        }

        $map = [
            'profile_picture_url' => 'profile_picture_url',
            'profile_picture_handle' => 'profile_picture_handle',
            'about' => 'profile_about',
            'description' => 'profile_description',
            'email' => 'profile_email',
            'address' => 'profile_address',
            'vertical' => 'profile_vertical',
        ];

        foreach ($map as $source => $target) {
            if (array_key_exists($source, $profile)) {
                $values[$target] = is_scalar($profile[$source]) ? (string)$profile[$source] : null;
            }
        }

        if (array_key_exists('websites', $profile)) {
            $websites = is_array($profile['websites']) ? $profile['websites'] : [];
            $values['profile_website'] = implode(',', array_slice(array_map('strval', $websites), 0, 2));
        }

        WhatsAppAccount::updateBusinessProfile($accountId, $user, $values);
    }

    private static function syncBusinessProfilesForUser(array $user): void
    {
        foreach (WhatsAppAccount::listForUser($user) as $listedAccount) {
            $account = WhatsAppAccount::getForUser((int)$listedAccount['id'], $user);
            if (!$account || trim((string)($account['phone_number_id'] ?? '')) === '') {
                continue;
            }

            $lastSync = strtotime((string)($listedAccount['profile_updated_at'] ?? '')) ?: 0;
            $hasPhoto = trim((string)($listedAccount['profile_picture_url'] ?? '')) !== '';
            if ($hasPhoto && $lastSync > 0 && (time() - $lastSync) < 600) {
                continue;
            }

            $result = (new MetaWhatsAppCloudApi())->getBusinessProfile(
                (string)$account['access_token'],
                (string)$account['phone_number_id']
            );

            if ($result['ok']) {
                $profile = $result['data']['data'][0] ?? $result['data'];
                self::persistBusinessProfile($user, (int)$account['id'], is_array($profile) ? $profile : [], null);
            } else {
                self::persistBusinessProfile($user, (int)$account['id'], [], $result['error']);
            }
        }
    }

    private static function withWhatsAppBilling(array $base, array $planned, bool $serviceWindowOpen): array
    {
        $category = $planned['template_category'] ?? null;
        $messageCategory = WhatsAppBilling::resolveCategory(
            (string)$planned['message_type'],
            $category,
            $serviceWindowOpen
        );
        $freeByMetaPolicy = WhatsAppCostPolicy::isFreeByMetaPolicy(
            (string)$planned['message_type'],
            $category,
            $serviceWindowOpen
        );
        if ($freeByMetaPolicy) {
            $priceBrl = 0.0;
            $pricingSnapshot = WhatsAppBilling::freeMetaPolicySnapshot(
                $messageCategory,
                (string)($base['contact_phone'] ?? ''),
                $messageCategory === WhatsAppCostPolicy::CATEGORY_UTILITY
                    ? 'utility_template_customer_service_window'
                    : 'customer_service_window'
            );
        } else {
            $priceBrl = WhatsAppBilling::priceForUser(
                (int)$base['user_id'],
                (string)$base['tenancy_id'],
                $messageCategory,
                WhatsAppDynamicPricing::countryCodeFromPhone((string)($base['contact_phone'] ?? ''))
            );
            $pricingSnapshot = WhatsAppBilling::pricingSnapshot(
                (int)$base['user_id'],
                (string)$base['tenancy_id'],
                $messageCategory,
                (string)($base['contact_phone'] ?? ''),
                $priceBrl
            );
        }

        return array_merge($base, [
            'sequence' => $planned['sequence'],
            'message_type' => $planned['message_type'],
            'body' => $planned['body'],
            'preview_body' => $planned['preview_body'] ?? $planned['body'],
            'template_name' => $planned['template_name'],
            'template_language' => $planned['template_language'] ?? 'pt_BR',
            'template_category' => $category,
            'template_components' => $planned['template_components'] ?? [],
            'template_variables' => $planned['template_variables'] ?? [],
            'service_window_open' => $serviceWindowOpen ? 1 : 0,
            'message_category' => $messageCategory,
            'price_brl' => $priceBrl,
            'pricing_snapshot' => $pricingSnapshot,
            'billed' => 0,
        ]);
    }
}
