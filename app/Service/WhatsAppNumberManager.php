<?php

namespace App\Service;

use App\Config\WhatsAppConfig;
use App\Model\Entity\SupportTicket;
use App\Model\Entity\WhatsAppAccount;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppNumberManager
{
    public static function listForUser(array $user): array
    {
        [$where, $params] = self::numberOwnerScope($user, 'wn');

        return (new Database('whatsapp_numbers wn LEFT JOIN whatsapp_accounts wa ON wa.id = wn.whatsapp_account_id'))
            ->select($where, $params, 'wn.created_at DESC, wn.id DESC', '', [
                'wn.id',
                'wn.company_id',
                'wn.user_id',
                'wn.owner_type',
                'wn.owner_id',
                'wn.requested_by_user_id',
                'wn.whatsapp_account_id',
                'wn.phone_number',
                'wn.internal_label',
                'wn.display_name_meta',
                'wn.display_name',
                'wn.origin',
                'wn.status',
                'wn.meta_id',
                'wn.waba_id',
                'wn.verification_method',
                'wn.display_name_status',
                'wn.display_name_submitted_at',
                'wn.display_name_approved_at',
                'wn.display_name_approval_seconds',
                'wn.display_name_last_checked_at',
                'wn.display_name_rejected_at',
                'wn.otp_confirmed_at',
                'wn.connected_at',
                'wn.last_meta_error',
                'wn.last_meta_error_at',
                'wn.verified_at',
                'wn.removed_at',
                'wn.last_error',
                'wn.created_at',
                'wn.updated_at',
                'wa.label AS account_label',
                'wa.status AS account_status',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function listAvailablePlatformNumbers(): array
    {
        return (new Database('whatsapp_numbers'))
            ->select("origin = 'platform' AND status = 'available'", [], 'phone_number ASC', '', [
                'id',
                'phone_number',
                'internal_label',
                'display_name_meta',
                'display_name',
                'origin',
                'status',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function listNumberRequests(array $user): array
    {
        self::syncOpenRequestVerificationStatus($user);

        [$where, $params] = self::requestOwnerScope($user, 'nr');

        $rows = (new Database('number_requests nr LEFT JOIN users u ON u.id = nr.requested_by_user_id LEFT JOIN whatsapp_numbers wn ON wn.id = nr.whatsapp_number_id'))
            ->select($where, $params, 'nr.created_at DESC, nr.id DESC', '', [
                'nr.id',
                'nr.company_id',
                'nr.requested_by_user_id',
                'nr.owner_type',
                'nr.owner_id',
                'nr.phone_number',
                'nr.internal_label',
                'nr.display_name_meta',
                'nr.display_name',
                'nr.status',
                'nr.admin_notes',
                'nr.whatsapp_number_id',
                'nr.support_ticket_id',
                'nr.reviewed_by_user_id',
                'nr.reviewed_at',
                'nr.created_at',
                'nr.updated_at',
                'wn.status AS number_status',
                'wn.last_error AS number_last_error',
                'wn.verification_method',
                'wn.display_name_status',
                'wn.display_name_submitted_at',
                'wn.display_name_approved_at',
                'wn.display_name_approval_seconds',
                'wn.display_name_rejected_at',
                'wn.otp_confirmed_at',
                'wn.connected_at',
                'wn.last_meta_error',
                'wn.last_meta_error_at',
                'wn.verified_at',
                'wn.updated_at AS number_updated_at',
                "CASE WHEN wn.status IN ('code_sent', 'pending_verification') THEN DATE_ADD(wn.updated_at, INTERVAL 5 MINUTE) ELSE NULL END AS code_expires_at",
                'u.name AS requested_by_name',
                'u.email AS requested_by_email',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $estimate = self::displayNameApprovalEstimate();
        foreach ($rows as &$row) {
            $row['approval_estimate'] = $estimate;
        }
        unset($row);

        return $rows;
    }

    private static function syncOpenRequestVerificationStatus(array $user): void
    {
        [$where, $params] = self::requestOwnerScope($user, 'nr');
        $where = "({$where}) AND nr.status = 'meta_submitted' AND wn.id IS NOT NULL AND wn.status IN ('code_sent', 'pending_verification', 'pending_name_approval')";

        $rows = (new Database('number_requests nr INNER JOIN whatsapp_numbers wn ON wn.id = nr.whatsapp_number_id'))
            ->select($where, $params, '', '10', [
            'wn.id AS number_id',
            'wn.meta_id',
            'wn.internal_label',
            'wn.display_name_meta',
            'wn.display_name',
                'wn.status AS number_status',
                'wn.verified_at',
                'wn.display_name_status',
                'wn.display_name_submitted_at',
                'wn.display_name_approved_at',
                'wn.display_name_rejected_at',
                'wn.otp_confirmed_at',
                'wn.connected_at',
                'wn.last_meta_error',
                'wn.last_meta_error_at',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            try {
                $verification = WhatsAppNumberSafety::fetchVerificationStatus(
                    self::platformAccessToken(),
                    (string)$row['meta_id']
                );

                self::applyMetaOnboardingStatus($user, $row, $verification);
            } catch (\Throwable $e) {
                error_log('[whatsapp_number_request_sync] ' . $e->getMessage());
            }
        }
    }

    public static function registerClientNumber(array $user, array $input): array
    {
        $phone = self::normalizePhone((string)($input['phone_number'] ?? $input['phone'] ?? ''));
        if (strlen($phone) < 8 || strlen($phone) > 15) {
            throw new \InvalidArgumentException('Informe um número válido com DDI e DDD.');
        }

        $targetOwner = self::resolveTargetOwner($user, $input);
        if (!self::isPlatformAdmin($user)) {
            self::assertOwnerCanRequest($targetOwner);
        }

        $existing = self::findByPhone($phone);
        if ($existing) {
            throw new \RuntimeException('Este número já está vinculado ou em validação.');
        }

        if (self::findOpenRequestByPhone($phone)) {
            throw new \RuntimeException('Este número já está vinculado ou em validação.');
        }

        $internalLabel = self::nullableString($input['internal_label'] ?? $input['label'] ?? $input['display_name'] ?? null)
            ?: ('WhatsApp ' . $phone);
        $displayNameMeta = self::nullableString($input['display_name_meta'] ?? null)
            ?: self::defaultDisplayName($targetOwner);
        self::assertValidDisplayName($displayNameMeta);

        $id = (int)(new Database('number_requests'))->insert([
            'company_id' => $targetOwner['tenancy_id'],
            'requested_by_user_id' => (int)$user['id'],
            'owner_type' => $targetOwner['owner_type'],
            'owner_id' => (int)$targetOwner['id'],
            'phone_number' => $phone,
            'internal_label' => $internalLabel,
            'display_name_meta' => $displayNameMeta,
            'display_name' => $displayNameMeta,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $ticketId = self::createNumberRequestSupportTicket($user, $id, $targetOwner, $phone);
        (new Database('number_requests'))->update('id = :id', [
            'support_ticket_id' => $ticketId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $id]);

        return self::getRequestForUser($id, $user) ?: ['id' => $id, 'phone_number' => $phone, 'status' => 'pending'];
    }

    public static function approveNumberRequest(array $user, int $requestId): array
    {
        self::assertPlatformAdmin($user);

        $request = self::getRequestForUser($requestId, $user);
        if (!$request || $request['status'] !== 'meta_submitted' || empty($request['whatsapp_number_id'])) {
            throw new \RuntimeException('Envie a solicitação para a Meta antes da aprovação final.');
        }

        $number = self::getForUser((int)$request['whatsapp_number_id'], $user);
        if (!$number) {
            throw new \RuntimeException('Número da solicitação não encontrado.');
        }

        if (empty($number['verified_at'])) {
            throw new \RuntimeException('O cliente ainda não confirmou o código recebido. Aguarde a confirmação antes da aprovação final.');
        }

        if ((string)($number['status'] ?? '') === 'blocked') {
            throw new \RuntimeException($number['last_error'] ?: 'Nome rejeitado pela Meta. Corrija o nome exibido antes da aprovação final.');
        }

        if ((string)($number['status'] ?? '') !== 'active' && (string)($number['status'] ?? '') !== 'pending_name_approval') {
            throw new \RuntimeException('Este número ainda não está pronto para aprovação final.');
        }

        if ((string)($number['status'] ?? '') !== 'active' || empty($number['whatsapp_account_id'])) {
            $number = self::attachNumberToCompanyInternal($user, (int)$request['whatsapp_number_id'], $number);
        }

        $affected = (new Database('number_requests'))->execute(
            "UPDATE number_requests
             SET status = 'approved',
                 reviewed_by_user_id = :reviewed_by_user_id,
                 reviewed_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id AND status = 'meta_submitted'",
            [
                ':reviewed_by_user_id' => (int)$user['id'],
                ':id' => $requestId,
            ]
        )->rowCount();

        if ($affected !== 1) {
            throw new \RuntimeException('Solicitação já foi processada por outro administrador.');
        }

        self::appendNumberRequestTicketMessage(
            $request,
            $user,
            'agent',
            'Solicitação aprovada. A Meta confirmou o código e o número foi liberado no sistema.'
        );

        return [
            'request' => self::getRequestForUser($requestId, $user),
            'number' => $number,
        ];
    }

    public static function submitNumberRequestToMeta(array $user, int $requestId): array
    {
        self::assertPlatformAdmin($user);

        $request = self::getRequestForUser($requestId, $user);
        if (!$request || !in_array((string)$request['status'], ['pending', 'meta_failed'], true)) {
            throw new \RuntimeException('Solicitação não encontrada ou não está pronta para envio à Meta.');
        }

        if (!empty($request['whatsapp_number_id'])) {
            throw new \RuntimeException('Solicitação já foi enviada para a Meta.');
        }

        try {
            $number = self::provisionNumberRequestOnMeta($user, $request);

            (new Database('number_requests'))->update('id = :id', [
                'status' => 'meta_submitted',
                'whatsapp_number_id' => (int)($number['id'] ?? 0),
                'admin_notes' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ], [':id' => $requestId]);

            self::appendNumberRequestTicketMessage(
                $request,
                $user,
                'agent',
                'Solicitação enviada para a Meta. Retorno inicial: número aceito para validação. Aguardando decisão final do super administrador.'
            );

            return [
                'request' => self::getRequestForUser($requestId, $user),
                'number' => $number,
            ];
        } catch (\Throwable $e) {
            (new Database('number_requests'))->update('id = :id', [
                'status' => 'meta_failed',
                'admin_notes' => $e->getMessage(),
                'updated_at' => date('Y-m-d H:i:s'),
            ], [':id' => $requestId]);

            throw $e;
        }
    }

    public static function rejectNumberRequest(array $user, int $requestId, string $reason = ''): array
    {
        self::assertPlatformAdmin($user);

        $request = self::getRequestForUser($requestId, $user);
        if (!$request || !in_array((string)$request['status'], ['meta_submitted', 'meta_failed'], true)) {
            throw new \RuntimeException('Solicitação não encontrada ou já analisada.');
        }

        $affected = (new Database('number_requests'))->execute(
            "UPDATE number_requests
             SET status = 'rejected',
                 admin_notes = :admin_notes,
                 reviewed_by_user_id = :reviewed_by_user_id,
                 reviewed_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id AND status IN ('meta_submitted', 'meta_failed')",
            [
                ':admin_notes' => self::nullableString($reason) ?: 'Solicitação recusada.',
                ':reviewed_by_user_id' => (int)$user['id'],
                ':id' => $requestId,
            ]
        )->rowCount();

        if ($affected !== 1) {
            throw new \RuntimeException('Solicitação já foi processada por outro administrador.');
        }

        self::appendNumberRequestTicketMessage(
            $request,
            $user,
            'agent',
            'Solicitação recusada. Motivo: ' . (self::nullableString($reason) ?: 'Solicitação recusada.')
        );

        return self::getRequestForUser($requestId, $user) ?: $request;
    }

    public static function sendVerificationCode(array $user, int $numberId, string $method = 'SMS'): array
    {
        self::assertPlatformAdmin($user);

        $number = self::getForUser($numberId, $user);
        if (!$number) {
            throw new \RuntimeException('Número não encontrado.');
        }

        if ((string)($number['status'] ?? '') === 'active') {
            return $number;
        }

        self::assertSubmittedRequestForNumber($numberId);

        return self::requestVerificationCodeForNumber($numberId, $number, $method);
    }

    public static function resendNumberRequestVerificationCode(array $user, int $requestId, string $method = 'SMS'): array
    {
        $request = self::getRequestForUser($requestId, $user);
        if (!$request || !in_array((string)$request['status'], ['meta_submitted', 'approved'], true) || empty($request['whatsapp_number_id'])) {
            throw new \RuntimeException('Confirmação disponível apenas após envio da solicitação para a Meta.');
        }
        self::assertCanHandleRequestCode($user, $request);

        $number = self::getForUser((int)$request['whatsapp_number_id'], $user);
        if (!$number) {
            throw new \RuntimeException('Número da solicitação não encontrado.');
        }

        if ((string)($number['status'] ?? '') === 'active') {
            return [
                'request' => self::getRequestForUser($requestId, $user),
                'number' => $number,
            ];
        }

        $number = self::requestVerificationCodeForNumber((int)$number['id'], $number, $method);

        self::appendNumberRequestTicketMessage(
            $request,
            $user,
            'customer',
            'Confirmação automática do número WhatsApp solicitada.'
        );

        return [
            'request' => self::getRequestForUser($requestId, $user),
            'number' => $number,
        ];
    }

    public static function confirmVerificationCode(array $user, int $numberId): array
    {
        self::assertPlatformAdmin($user);

        $number = self::getForUser($numberId, $user);
        if (!$number) {
            throw new \RuntimeException('Número não encontrado.');
        }

        if ((string)($number['status'] ?? '') === 'active') {
            return $number;
        }

        self::assertSubmittedRequestForNumber($numberId);

        return self::verifyAndAttachNumber($user, $number);
    }

    public static function confirmNumberRequestVerificationCode(array $user, int $requestId, string $code): array
    {
        $request = self::getRequestForUser($requestId, $user);
        if (!$request || !in_array((string)$request['status'], ['meta_submitted', 'approved'], true) || empty($request['whatsapp_number_id'])) {
            throw new \RuntimeException('Confirmação disponível apenas após envio da solicitação para a Meta.');
        }
        self::assertCanHandleRequestCode($user, $request);

        $number = self::getForUser((int)$request['whatsapp_number_id'], $user);
        if (!$number) {
            throw new \RuntimeException('Número da solicitação não encontrado.');
        }

        if ((string)($number['status'] ?? '') === 'active') {
            return [
                'request' => self::getRequestForUser($requestId, $user),
                'number' => $number,
            ];
        }

        $connected = self::verifyNumberRequestCode($user, $number, $code);

        self::appendNumberRequestTicketMessage(
            $request,
            $user,
            'customer',
            'Código confirmado na Meta. Aguardando aprovação final do super administrador.'
        );

        return [
            'request' => self::getRequestForUser($requestId, $user),
            'number' => $connected,
        ];
    }

    public static function attachNumberToCompany(array $user, int $numberId): array
    {
        self::assertPlatformAdmin($user);

        $number = self::getForUser($numberId, $user);
        if (!$number) {
            throw new \RuntimeException('Número não encontrado.');
        }

        return self::attachNumberToCompanyInternal($user, $numberId, $number);
    }

    private static function attachNumberToCompanyInternal(array $user, int $numberId, array $number): array
    {
        $alreadyTechnicallyActive = (string)($number['status'] ?? '') === 'active' && !empty($number['verified_at']);
        if (!$alreadyTechnicallyActive) {
            self::assertMetaVerificationStatus((string)($number['meta_id'] ?? ''));
            self::registerVerifiedPhoneNumber((string)($number['meta_id'] ?? ''));
        }

        $owner = self::ownerFromNumber($number);
        self::assertOwnerHasNoActiveNumber($owner, $numberId);

        $accountId = (int)($number['whatsapp_account_id'] ?? 0);
        if ($accountId <= 0) {
            $accountId = WhatsAppAccount::create([
                'tenancy_id' => $owner['tenancy_id'],
                'user_id' => (int)$owner['id'],
                'label' => $number['internal_label'] ?: ($number['display_name'] ?: ('WhatsApp ' . $number['phone_number'])),
                'waba_id' => $number['waba_id'] ?: self::platformWabaId(),
                'business_id' => null,
                'phone_number_id' => (string)$number['meta_id'],
                'display_phone_number' => (string)$number['phone_number'],
                'access_token' => self::platformAccessToken(),
                'app_secret' => null,
                'verify_token' => WhatsAppConfig::webhookVerifyToken(),
                'status' => 'active',
            ]);
        }

        (new Database('whatsapp_numbers'))->update('id = :id', [
            'whatsapp_account_id' => $accountId,
            'status' => 'active',
            'verified_at' => date('Y-m-d H:i:s'),
            'connected_at' => $number['connected_at'] ?? date('Y-m-d H:i:s'),
            'display_name_approved_at' => $number['display_name_approved_at'] ?? null,
            'display_name_approval_seconds' => $number['display_name_approval_seconds'] ?? null,
            'last_error' => null,
            'last_meta_error' => null,
            'last_meta_error_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $numberId]);

        return self::getForUser($numberId, $user) ?: $number;
    }

    public static function createPlatformNumber(array $user, array $input): array
    {
        self::assertPlatformAdmin($user);

        $phone = self::normalizePhone((string)($input['phone_number'] ?? $input['phone'] ?? ''));
        $metaId = trim((string)($input['meta_id'] ?? $input['phone_number_id'] ?? ''));
        $displayName = self::nullableString($input['display_name_meta'] ?? $input['display_name'] ?? null) ?: ('WhatsApp ' . $phone);
        $internalLabel = self::nullableString($input['internal_label'] ?? $input['label'] ?? null) ?: $displayName;
        self::assertValidDisplayName($displayName);

        if (strlen($phone) < 8 || strlen($phone) > 15 || $metaId === '') {
            throw new \InvalidArgumentException('Informe número e ID Meta.');
        }

        if (self::findByPhone($phone)) {
            throw new \RuntimeException('Este número já está cadastrado.');
        }

        $targetOwner = !empty($input['owner_user_id']) || !empty($input['company_id'])
            ? self::resolveTargetOwner($user, $input)
            : null;
        if ($targetOwner) {
            self::assertOwnerHasNoActiveNumber($targetOwner);
            self::assertMetaVerificationStatus($metaId);
        }

        $accountId = WhatsAppAccount::create([
            'tenancy_id' => $targetOwner['tenancy_id'] ?? 'platform',
            'user_id' => (int)($targetOwner['id'] ?? $user['id']),
            'label' => $internalLabel,
            'waba_id' => self::nullableString($input['waba_id'] ?? null) ?: self::platformWabaId(),
            'business_id' => null,
            'phone_number_id' => $metaId,
            'display_phone_number' => $phone,
            'access_token' => self::platformAccessToken(),
            'app_secret' => null,
            'verify_token' => WhatsAppConfig::webhookVerifyToken(),
            'status' => $targetOwner ? 'active' : 'inactive',
        ]);

        $id = (int)(new Database('whatsapp_numbers'))->insert([
            'company_id' => (string)($targetOwner['tenancy_id'] ?? 'platform'),
            'user_id' => (int)($targetOwner['id'] ?? $user['id']),
            'owner_type' => $targetOwner['owner_type'] ?? null,
            'owner_id' => isset($targetOwner['id']) ? (int)$targetOwner['id'] : null,
            'requested_by_user_id' => (int)$user['id'],
            'whatsapp_account_id' => $accountId,
            'phone_number' => $phone,
            'internal_label' => $internalLabel,
            'display_name_meta' => $displayName,
            'display_name' => $displayName,
            'origin' => 'platform',
            'status' => $targetOwner ? 'active' : 'available',
            'meta_id' => $metaId,
            'waba_id' => self::nullableString($input['waba_id'] ?? null) ?: self::platformWabaId(),
            'verification_method' => null,
            'display_name_status' => $targetOwner ? 'APPROVED' : null,
            'display_name_submitted_at' => $targetOwner ? date('Y-m-d H:i:s') : null,
            'display_name_approved_at' => $targetOwner ? date('Y-m-d H:i:s') : null,
            'display_name_approval_seconds' => $targetOwner ? 0 : null,
            'display_name_last_checked_at' => $targetOwner ? date('Y-m-d H:i:s') : null,
            'display_name_rejected_at' => null,
            'otp_confirmed_at' => $targetOwner ? date('Y-m-d H:i:s') : null,
            'connected_at' => $targetOwner ? date('Y-m-d H:i:s') : null,
            'last_meta_error' => null,
            'last_meta_error_at' => null,
            'verified_at' => $targetOwner ? date('Y-m-d H:i:s') : null,
            'removed_at' => null,
            'last_error' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return self::getForUser($id, $user) ?: ['id' => $id];
    }

    public static function assignPlatformNumber(array $user, int $numberId, array $input = []): array
    {
        self::assertPlatformAdmin($user);
        $targetOwner = self::resolveTargetOwner($user, $input);
        self::assertOwnerHasNoActiveNumber($targetOwner);

        $number = self::getAvailablePlatformNumber($numberId);
        if (!$number || $number['origin'] !== 'platform' || $number['status'] !== 'available') {
            throw new \RuntimeException('Número não disponível.');
        }

        self::assertMetaVerificationStatus((string)($number['meta_id'] ?? ''));

        if (!empty($number['whatsapp_account_id'])) {
            (new Database('whatsapp_accounts'))->update('id = :id', [
                'tenancy_id' => $targetOwner['tenancy_id'],
                'user_id' => (int)$targetOwner['id'],
                'status' => 'active',
                'updated_at' => date('Y-m-d H:i:s'),
            ], [':id' => (int)$number['whatsapp_account_id']]);
        }

        $affected = (new Database('whatsapp_numbers'))->execute(
            "UPDATE whatsapp_numbers
             SET company_id = :company_id,
                 user_id = :user_id,
                 owner_type = :owner_type,
                 owner_id = :owner_id,
                 requested_by_user_id = :requested_by_user_id,
                 status = 'active',
                 verified_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id
               AND origin = 'platform'
               AND status = 'available'"
            ,
            [
                ':company_id' => $targetOwner['tenancy_id'],
                ':user_id' => (int)$targetOwner['id'],
                ':owner_type' => $targetOwner['owner_type'],
                ':owner_id' => (int)$targetOwner['id'],
                ':requested_by_user_id' => (int)$user['id'],
                ':id' => $numberId,
            ]
        )->rowCount();

        if ($affected !== 1) {
            throw new \RuntimeException('Número não disponível.');
        }

        return self::getForUser($numberId, $user) ?: $number;
    }

    public static function removeNumberFromMeta(array $user, int $numberId): array
    {
        self::assertPlatformAdmin($user);

        $number = self::getForUser($numberId, $user);
        if (!$number) {
            throw new \RuntimeException('Número não encontrado.');
        }

        if ($number['origin'] === 'platform') {
            if (!empty($number['whatsapp_account_id'])) {
                (new Database('whatsapp_accounts'))->update('id = :id', [
                    'tenancy_id' => 'platform',
                    'access_token' => SecretBox::encrypt(self::platformAccessToken()),
                    'status' => 'inactive',
                    'updated_at' => date('Y-m-d H:i:s'),
                ], [':id' => (int)$number['whatsapp_account_id']]);
            }

            (new Database('whatsapp_numbers'))->update('id = :id', [
                'company_id' => 'platform',
                'user_id' => (int)$user['id'],
                'owner_type' => null,
                'owner_id' => null,
                'status' => 'available',
                'removed_at' => null,
                'last_error' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ], [':id' => $numberId]);

            return self::getAvailablePlatformNumber($numberId) ?: $number;
        }

        (new Database('whatsapp_numbers'))->update('id = :id', [
            'status' => 'removing',
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $numberId]);

        $result = (new MetaWhatsAppCloudApi())->deregisterPhoneNumber(
            self::platformAccessToken(),
            (string)$number['meta_id']
        );

        $metaError = null;
        if (!$result['ok']) {
            $metaError = $result['error'] ?: 'Falha ao remover número da Meta.';
        }

        self::revokeAccess($number);

        (new Database('whatsapp_numbers'))->update('id = :id', [
            'status' => 'removed',
            'owner_type' => null,
            'owner_id' => null,
            'removed_at' => date('Y-m-d H:i:s'),
            'last_error' => $metaError,
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $numberId]);

        (new Database('number_requests'))->update('whatsapp_number_id = :number_id', [
            'status' => 'rejected',
            'admin_notes' => $metaError
                ? 'Número removido localmente. Meta retornou: ' . $metaError
                : 'Número removido da Meta e desvinculado pelo super administrador.',
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':number_id' => $numberId]);

        return self::getForUser($numberId, $user) ?: $number;
    }

    public static function revokeAccess(array $number): void
    {
        $accountId = (int)($number['whatsapp_account_id'] ?? 0);
        if ($accountId <= 0) {
            return;
        }

        (new Database('whatsapp_accounts'))->update('id = :id', [
            'access_token' => '',
            'status' => 'inactive',
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $accountId]);
    }

    private static function createNumberRequestSupportTicket(array $requester, int $requestId, array $owner, string $phone): int
    {
        $displayName = self::defaultDisplayName($owner);
        $message = implode("\n", [
            'Solicitação de número WhatsApp registrada.',
            '',
            'Solicitação: #' . $requestId,
            'Número: +' . $phone,
            'Status: pendente de aprovação do super administrador.',
            'Solicitante: ' . trim((string)($requester['name'] ?? 'Usuário #' . ($requester['id'] ?? ''))),
            'Dono do número: ' . $displayName . ' (' . (string)($owner['owner_type'] ?? 'client') . ' #' . (int)($owner['id'] ?? 0) . ')',
        ]);

        return SupportTicket::create($requester, [
            'department' => 'support',
            'requester_phone' => $phone,
            'subject' => 'Solicitação de número WhatsApp #' . $requestId,
            'message' => $message,
        ]);
    }

    private static function appendNumberRequestTicketMessage(array $request, array $actor, string $senderType, string $body): void
    {
        $ticketId = (int)($request['support_ticket_id'] ?? 0);
        if ($ticketId <= 0) {
            return;
        }

        SupportTicket::addMessage($ticketId, $actor, $senderType, $body);
    }

    private static function requestVerificationCodeForNumber(int $numberId, array $number, string $method = 'SMS'): array
    {
        if ((string)($number['status'] ?? '') === 'active') {
            return self::getForUser($numberId, self::ownerFromNumber($number)) ?: $number;
        }

        if (!in_array((string)$number['status'], ['pending', 'code_sent', 'failed', 'pending_verification'], true)) {
            throw new \RuntimeException('Este número não está aguardando confirmação automática.');
        }

        if (empty($number['meta_id'])) {
            throw new \RuntimeException('Solicitação ainda não foi registrada na Meta pelo administrador.');
        }

        $method = strtoupper($method) === 'VOICE' ? 'VOICE' : 'SMS';
        $result = (new MetaWhatsAppCloudApi())->requestVerificationCode(
            self::platformAccessToken(),
            (string)$number['meta_id'],
            $method,
            'pt_BR'
        );

        if (!$result['ok']) {
            self::markFailed($numberId, $result['error'] ?: 'Falha ao solicitar confirmação automática.');
            throw new \RuntimeException($result['error'] ?: 'Não foi possível solicitar a confirmação automática.');
        }

        (new Database('whatsapp_numbers'))->update('id = :id', [
            'status' => 'pending_verification',
            'verification_method' => $method,
            'verified_at' => null,
            'last_error' => null,
            'last_meta_error' => null,
            'last_meta_error_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $numberId]);

        return self::getForUser($numberId, self::ownerFromNumber($number)) ?: $number;
    }

    private static function assertMetaVerificationStatus(string $phoneNumberId): void
    {
        $verification = self::metaVerificationStatus($phoneNumberId);
        if (!$verification['verified']) {
            throw new \RuntimeException(self::metaVerificationBlockedMessage($verification['status']));
        }
    }

    private static function updateNumberDisplayNameState(
        int $numberId,
        string $status,
        string $displayNameStatus,
        ?string $lastError,
        array $payload,
        ?string $approvedAt = null,
        ?int $approvalSeconds = null
    ): void {
        $data = [
            'status' => $status,
            'display_name_status' => $displayNameStatus,
            'display_name_last_checked_at' => date('Y-m-d H:i:s'),
            'last_error' => $lastError,
            'last_meta_error' => $lastError,
            'last_meta_error_at' => $lastError !== null ? date('Y-m-d H:i:s') : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($status !== 'pending_verification') {
            $data['verified_at'] = date('Y-m-d H:i:s');
        }

        if (self::isMetaNameRejected($displayNameStatus)) {
            $data['display_name_rejected_at'] = date('Y-m-d H:i:s');
        }

        if ($approvedAt !== null) {
            $data['display_name_approved_at'] = $approvedAt;
            $data['display_name_approval_seconds'] = $approvalSeconds;
        }

        (new Database('whatsapp_numbers'))->update('id = :id', $data, [':id' => $numberId]);
    }

    private static function recordDisplayNameApprovalEvent(
        int $numberId,
        array $row,
        string $status,
        ?int $approvalSeconds,
        array $payload
    ): void {
        (new Database('whatsapp_display_name_approval_events'))->insert([
            'whatsapp_number_id' => $numberId,
            'phone_number_id' => (string)($row['meta_id'] ?? ''),
            'display_name' => self::nullableString($row['display_name_meta'] ?? $row['display_name'] ?? null),
            'status' => $status,
            'approval_seconds' => $approvalSeconds,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function registerVerifiedPhoneNumber(string $phoneNumberId): void
    {
        if ($phoneNumberId === '') {
            throw new \RuntimeException('Número WhatsApp sem ID Meta para registrar na Cloud API.');
        }

        $result = (new MetaWhatsAppCloudApi())->registerPhoneNumber(
            self::platformAccessToken(),
            $phoneNumberId,
            self::platformPin()
        );

        error_log(json_encode([
            'event' => 'meta_whatsapp_register_response',
            'phone_number_id' => $phoneNumberId,
            'status' => $result['status'] ?? null,
            'ok' => $result['ok'] ?? false,
            'error' => $result['error'] ?? null,
            'response' => $result['data'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (!$result['ok'] && !self::isAlreadyRegisteredMetaResponse($result)) {
            throw new \RuntimeException($result['error'] ?: 'A Meta não registrou o número na Cloud API.');
        }
    }

    private static function metaVerificationStatus(string $phoneNumberId): array
    {
        if ($phoneNumberId === '') {
            throw new \RuntimeException('Número WhatsApp sem ID Meta para validar verificação.');
        }

        return WhatsAppNumberSafety::fetchVerificationStatus(
            self::platformAccessToken(),
            $phoneNumberId
        );
    }

    private static function metaVerificationBlockedMessage(string $status): string
    {
        return 'Número WhatsApp bloqueado: status de verificação na Meta é '
            . ($status !== '' ? $status : 'UNKNOWN')
            . '. Confirme o código na Meta antes de usar este número.';
    }

    private static function isMetaNameReviewPending(string $status): bool
    {
        $status = strtoupper(trim($status));
        return in_array($status, ['PENDING', 'PENDING_REVIEW', 'IN_REVIEW'], true);
    }

    private static function isMetaNameApproved(string $status): bool
    {
        $status = strtoupper(trim($status));
        return in_array($status, ['APPROVED', 'AVAILABLE_WITHOUT_REVIEW'], true);
    }

    private static function isMetaNameRejected(string $status): bool
    {
        $status = strtoupper(trim($status));
        return in_array($status, ['REJECTED', 'DECLINED'], true);
    }

    private static function currentMetaDisplayNameStatus(array $verification): string
    {
        $newStatus = strtoupper((string)($verification['new_name_status'] ?? ''));
        if ($newStatus !== '' && $newStatus !== 'UNKNOWN') {
            return $newStatus;
        }

        $status = strtoupper((string)($verification['name_status'] ?? ''));
        return $status !== '' ? $status : 'UNKNOWN';
    }

    private static function verifyAndAttachNumber(array $user, array $number): array
    {
        $numberId = (int)($number['id'] ?? 0);
        if (!in_array((string)($number['status'] ?? ''), ['code_sent', 'pending_verification'], true)) {
            throw new \RuntimeException('Solicite a confirmação automática antes de concluir.');
        }

        return self::attachNumberToCompanyInternal($user, $numberId, $number);
    }

    private static function verifyNumberRequestCode(array $user, array $number, string $code): array
    {
        $numberId = (int)($number['id'] ?? 0);
        if (!in_array((string)($number['status'] ?? ''), ['code_sent', 'pending_verification'], true)) {
            throw new \RuntimeException('Solicite o envio do código antes de confirmar.');
        }

        if (strlen($code) < 4) {
            throw new \InvalidArgumentException('Informe o código recebido.');
        }

        $api = new MetaWhatsAppCloudApi();
        $verified = $api->verifyPhoneNumberCode(self::platformAccessToken(), (string)$number['meta_id'], $code);
        if (!$verified['ok']) {
            if (self::isAlreadyVerifiedMetaResponse($verified)) {
                return self::markNumberCodeVerified($user, $numberId, $number);
            }

            self::markFailed($numberId, $verified['error'] ?: 'Código inválido.');
            throw new \RuntimeException($verified['error'] ?: 'Código inválido ou expirado.');
        }

        return self::markNumberCodeVerified($user, $numberId, $number);
    }

    private static function markNumberCodeVerified(array $user, int $numberId, array $number): array
    {
        (new Database('whatsapp_numbers'))->update('id = :id', [
            'status' => 'pending_name_approval',
            'verified_at' => date('Y-m-d H:i:s'),
            'otp_confirmed_at' => date('Y-m-d H:i:s'),
            'display_name_status' => 'PENDING_REVIEW',
            'last_error' => 'Seu número já está conectado e pode ser utilizado. O nome comercial ainda está em análise pela Meta e será exibido após aprovação.',
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $numberId]);

        $updated = self::getForUser($numberId, $user) ?: $number;

        try {
            return self::attachNumberToCompanyInternal($user, $numberId, $updated);
        } catch (\Throwable $e) {
            (new Database('whatsapp_numbers'))->update('id = :id', [
                'last_error' => $e->getMessage(),
                'last_meta_error' => $e->getMessage(),
                'last_meta_error_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], [':id' => $numberId]);
            throw $e;
        }
    }

    private static function isAlreadyVerifiedMetaResponse(array $response): bool
    {
        $error = $response['data']['error'] ?? null;
        if (is_array($error) && (string)($error['error_subcode'] ?? '') === '2388366') {
            return true;
        }

        $message = strtolower((string)($response['error'] ?? ''));
        return str_contains($message, '2388366')
            || str_contains($message, 'já verificou')
            || str_contains($message, 'already verified');
    }

    private static function isAlreadyRegisteredMetaResponse(array $response): bool
    {
        $message = strtolower((string)($response['error'] ?? $response['data']['error']['message'] ?? ''));

        return str_contains($message, 'already registered')
            || str_contains($message, 'already been registered')
            || str_contains($message, 'já registrado')
            || str_contains($message, 'já foi registrado');
    }

    private static function provisionNumberRequestOnMeta(array $user, array $request): array
    {
        self::assertPlatformAdmin($user);

        $requestId = (int)($request['id'] ?? 0);
        $metaRequest = $requestId > 0 ? self::getRequestForUser($requestId, $user) : null;
        if (!$metaRequest || !in_array((string)$metaRequest['status'], ['pending', 'meta_failed'], true)) {
            throw new \RuntimeException('A integração com a Meta exige uma solicitação pendente.');
        }

        if (!empty($metaRequest['whatsapp_number_id'])) {
            throw new \RuntimeException('Solicitação já possui número vinculado.');
        }

        $phone = self::normalizePhone((string)$metaRequest['phone_number']);
        if (self::findByPhone($phone)) {
            throw new \RuntimeException('Este número já está vinculado ou em validação.');
        }

        $owner = self::ownerFromRequest($metaRequest);
        self::assertOwnerHasNoActiveNumber($owner);

        $internalLabel = self::nullableString($metaRequest['internal_label'] ?? null)
            ?: ('WhatsApp ' . $phone);
        $displayName = self::nullableString($metaRequest['display_name_meta'] ?? null)
            ?: self::nullableString($metaRequest['display_name'] ?? null)
            ?: self::defaultDisplayName($owner);
        self::assertValidDisplayName($displayName);
        $countryCode = self::extractCountryCode($phone, '55');
        $nationalNumber = substr($phone, strlen($countryCode));

        $accessToken = self::platformAccessToken();
        $wabaId = self::platformWabaId();
        $api = new MetaWhatsAppCloudApi();
        $created = self::findExistingMetaPhoneNumber($api, $accessToken, $wabaId, $phone);

        if ($created === null) {
            $created = $api->createPhoneNumber(
                $accessToken,
                $wabaId,
                $countryCode,
                $nationalNumber,
                $displayName
            );
        } else {
            $created = self::ensureMetaDisplayName($api, $accessToken, $created, $displayName);
        }

        if (!$created['ok']) {
            throw new \RuntimeException($created['error'] ?: 'Não foi possível iniciar a conexão do número.');
        }

        $metaId = (string)($created['data']['id'] ?? '');
        if ($metaId === '') {
            throw new \RuntimeException('A Meta não retornou o identificador do número.');
        }

        $id = (int)(new Database('whatsapp_numbers'))->insert([
            'company_id' => (string)$metaRequest['company_id'],
            'user_id' => (int)$owner['id'],
            'owner_type' => (string)$metaRequest['owner_type'],
            'owner_id' => (int)$metaRequest['owner_id'],
            'requested_by_user_id' => (int)$metaRequest['requested_by_user_id'],
            'whatsapp_account_id' => null,
            'phone_number' => $phone,
            'internal_label' => $internalLabel,
            'display_name_meta' => $displayName,
            'display_name' => $displayName,
            'origin' => 'client',
            'status' => 'pending_verification',
            'meta_id' => $metaId,
            'waba_id' => $wabaId,
            'verification_method' => null,
            'display_name_status' => (string)($created['data']['new_name_status'] ?? $created['data']['name_status'] ?? 'PENDING_REVIEW'),
            'display_name_submitted_at' => date('Y-m-d H:i:s'),
            'display_name_approved_at' => null,
            'display_name_approval_seconds' => null,
            'display_name_last_checked_at' => null,
            'display_name_rejected_at' => null,
            'otp_confirmed_at' => null,
            'connected_at' => null,
            'last_meta_error' => null,
            'last_meta_error_at' => null,
            'verified_at' => null,
            'removed_at' => null,
            'last_error' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return self::getForUser($id, $user) ?: ['id' => $id, 'phone_number' => $phone, 'status' => 'pending'];
    }

    private static function applyMetaOnboardingStatus(array $user, array $row, array $verification): void
    {
        $numberId = (int)($row['number_id'] ?? 0);
        if ($numberId <= 0) {
            return;
        }

        $displayNameStatus = self::currentMetaDisplayNameStatus($verification);
        $payload = $verification['payload'] ?? [];

        if (!$verification['verified']) {
            self::updateNumberDisplayNameState($numberId, 'pending_verification', $displayNameStatus, null, $payload);
            return;
        }

        if (self::isMetaNameRejected($displayNameStatus)) {
            self::updateNumberDisplayNameState(
                $numberId,
                'active',
                $displayNameStatus,
                'Número conectado, mas o nome comercial foi rejeitado pela Meta. Corrija o nome exibido.',
                $payload
            );
            self::recordDisplayNameApprovalEvent($numberId, $row, $displayNameStatus, null, $payload);
            return;
        }

        if (self::isMetaNameApproved($displayNameStatus)) {
            $approvedAt = date('Y-m-d H:i:s');
            $submittedAt = self::nullableString($row['display_name_submitted_at'] ?? null);
            $approvalSeconds = $submittedAt ? max(0, strtotime($approvedAt) - strtotime($submittedAt)) : null;

            $wasApprovalRecorded = !empty($row['display_name_approved_at']);
            self::updateNumberDisplayNameState($numberId, 'active', $displayNameStatus, null, $payload, $approvedAt, $approvalSeconds);
            if (!$wasApprovalRecorded) {
                self::recordDisplayNameApprovalEvent($numberId, $row, $displayNameStatus, $approvalSeconds, $payload);
            }

            $number = self::getForUser($numberId, $user);
            if ($number && ((string)($number['status'] ?? '') !== 'active' || empty($number['whatsapp_account_id']))) {
                try {
                    self::attachNumberToCompanyInternal($user, $numberId, $number);
                } catch (\Throwable $e) {
                    (new Database('whatsapp_numbers'))->update('id = :id', [
                        'last_error' => $e->getMessage(),
                        'last_meta_error' => $e->getMessage(),
                        'last_meta_error_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], [':id' => $numberId]);
                    throw $e;
                }
                $request = self::findSubmittedRequestForNumber($numberId);
                if ($request && (string)$request['status'] === 'meta_submitted') {
                    (new Database('number_requests'))->update('id = :id', [
                        'status' => 'approved',
                        'reviewed_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], [':id' => (int)$request['id']]);
                }
            }
            return;
        }

        self::updateNumberDisplayNameState(
            $numberId,
            'active',
            $displayNameStatus,
            'Seu número já está conectado e pode ser utilizado. O nome comercial ainda está em análise pela Meta e será exibido após aprovação.',
            $payload
        );
    }

    private static function findExistingMetaPhoneNumber(
        MetaWhatsAppCloudApi $api,
        string $accessToken,
        string $wabaId,
        string $phone
    ): ?array {
        $listed = $api->listPhoneNumbers($accessToken, $wabaId);
        if (!$listed['ok']) {
            return null;
        }

        foreach (($listed['data']['data'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $displayPhone = self::normalizePhone((string)($item['display_phone_number'] ?? ''));
            $metaId = trim((string)($item['id'] ?? ''));
            if ($displayPhone === $phone && $metaId !== '') {
                return [
                    'ok' => true,
                    'status' => 200,
                    'data' => $item + [
                        'id' => $metaId,
                        'existing_meta_number' => true,
                    ],
                    'error' => null,
                ];
            }
        }

        return null;
    }

    private static function ensureMetaDisplayName(
        MetaWhatsAppCloudApi $api,
        string $accessToken,
        array $created,
        string $displayName
    ): array {
        $metaId = (string)($created['data']['id'] ?? '');
        if ($metaId === '') {
            return $created;
        }

        $currentName = self::normalizeDisplayNameForCompare((string)($created['data']['verified_name'] ?? ''));
        $pendingName = self::normalizeDisplayNameForCompare((string)($created['data']['new_display_name'] ?? ''));
        $requestedName = self::normalizeDisplayNameForCompare($displayName);

        if ($requestedName === '' || $requestedName === $currentName || $requestedName === $pendingName) {
            return $created;
        }

        $updated = $api->updatePhoneNumberDisplayName($accessToken, $metaId, $displayName);
        if (!$updated['ok']) {
            throw new \RuntimeException($updated['error'] ?: 'A Meta não aceitou a atualização do nome exibido.');
        }

        $created['data']['new_display_name'] = $displayName;
        $created['data']['new_name_status'] = 'PENDING_REVIEW';

        return $created;
    }

    private static function getForUser(int $id, array $user): ?array
    {
        [$scope, $params] = self::numberOwnerScope($user, 'whatsapp_numbers');
        $where = 'whatsapp_numbers.id = :id AND ' . $scope;
        $params[':id'] = $id;

        $row = (new Database('whatsapp_numbers'))
            ->select($where, $params, '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function getRequestForUser(int $id, array $user): ?array
    {
        [$scope, $params] = self::requestOwnerScope($user, 'number_requests');
        $where = 'number_requests.id = :id AND ' . $scope;
        $params[':id'] = $id;

        $row = (new Database('number_requests'))
            ->select($where, $params, '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function findSubmittedRequestForNumber(int $numberId): ?array
    {
        $row = (new Database('number_requests'))
            ->select(
                "whatsapp_number_id = :number_id AND status IN ('meta_submitted', 'approved')",
                [':number_id' => $numberId],
                '',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function assertSubmittedRequestForNumber(int $numberId): void
    {
        if (!self::findSubmittedRequestForNumber($numberId)) {
            throw new \RuntimeException('Integração bloqueada: envie a solicitação para a Meta antes de conectar.');
        }
    }

    private static function assertCanHandleRequestCode(array $user, array $request): void
    {
        if (self::isPlatformAdmin($user)) {
            return;
        }

        $userId = (int)($user['id'] ?? 0);
        if (
            $userId !== (int)($request['requested_by_user_id'] ?? 0)
            && $userId !== (int)($request['owner_id'] ?? 0)
        ) {
            throw new \RuntimeException('Você não pode confirmar esta solicitação.');
        }
    }

    private static function findByPhone(string $phone): ?array
    {
        $row = (new Database('whatsapp_numbers'))
            ->select('phone_number = :phone', [':phone' => $phone], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function findOpenRequestByPhone(string $phone): ?array
    {
        $row = (new Database('number_requests'))
            ->select(
                "phone_number = :phone AND status IN ('pending', 'meta_submitted', 'meta_failed')",
                [':phone' => $phone],
                '',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function getAvailablePlatformNumber(int $numberId): ?array
    {
        $row = (new Database('whatsapp_numbers'))
            ->select(
                "id = :id AND origin = 'platform' AND status = 'available'",
                [':id' => $numberId],
                '',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function resolveTargetOwner(array $actor, array $input): array
    {
        $targetUserId = (int)($input['owner_user_id'] ?? $input['target_user_id'] ?? 0);

        if ($targetUserId <= 0) {
            $targetUserId = (int)$actor['id'];
        }

        if (!self::isPlatformAdmin($actor) && $targetUserId !== (int)$actor['id']) {
            throw new \RuntimeException('Você não pode solicitar número para outro usuário.');
        }

        $row = (new Database('users'))
            ->select('id = :id', [':id' => $targetUserId], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new \RuntimeException('Dono do número não encontrado.');
        }

        if (!self::isPlatformAdmin($actor) && (string)$row['tenancy_id'] !== (string)$actor['tenancy_id']) {
            throw new \RuntimeException('Dono do número não pertence à sua empresa.');
        }

        $role = strtolower((string)($row['user_function'] ?? ''));
        $row['owner_type'] = match ($role) {
            'admin' => 'admin',
            'reseller' => 'reseller',
            default => 'client',
        };

        return $row;
    }

    private static function ownerFromNumber(array $number): array
    {
        if (empty($number['owner_id']) || empty($number['owner_type'])) {
            throw new \RuntimeException('Número sem dono definido.');
        }

        $row = (new Database('users'))
            ->select('id = :id', [':id' => (int)$number['owner_id']], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new \RuntimeException('Dono do número não encontrado.');
        }

        $row['owner_type'] = $number['owner_type'];
        return $row;
    }

    private static function ownerFromRequest(array $request): array
    {
        if (empty($request['owner_id']) || empty($request['owner_type'])) {
            throw new \RuntimeException('Solicitação sem dono definido.');
        }

        $row = (new Database('users'))
            ->select('id = :id', [':id' => (int)$request['owner_id']], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new \RuntimeException('Dono do número não encontrado.');
        }

        if ((string)$row['tenancy_id'] !== (string)$request['company_id']) {
            throw new \RuntimeException('Dono do número não pertence à empresa da solicitação.');
        }

        $row['owner_type'] = (string)$request['owner_type'];
        return $row;
    }

    private static function assertOwnerCanRequest(array $owner): void
    {
        self::assertOwnerHasNoActiveNumber($owner);
    }

    private static function assertOwnerHasNoActiveNumber(array $owner, ?int $ignoreNumberId = null): void
    {
        $where = "owner_type = :owner_type AND owner_id = :owner_id AND status = 'active'";
        $params = [
            ':owner_type' => $owner['owner_type'],
            ':owner_id' => (int)$owner['id'],
        ];

        if ($ignoreNumberId !== null) {
            $where .= ' AND id <> :ignore_id';
            $params[':ignore_id'] = $ignoreNumberId;
        }

        $existing = (new Database('whatsapp_numbers'))
            ->select($where, $params, '', '1', ['id'])
            ->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            throw new \RuntimeException('Este usuário já possui um número WhatsApp ativo.');
        }
    }

    private static function assertPlatformAdmin(array $user): void
    {
        if (!self::isPlatformAdmin($user)) {
            throw new \RuntimeException('Ação permitida apenas para o administrador da plataforma.');
        }
    }

    private static function numberOwnerScope(array $user, string $alias = ''): array
    {
        $prefix = $alias !== '' ? $alias . '.' : '';
        $role = strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));

        if ($role === 'super_admin') {
            return ['1=1', []];
        }

        if ($role === 'admin') {
            return [
                $prefix . 'company_id = :scope_tenancy_id',
                [':scope_tenancy_id' => (string)($user['tenancy_id'] ?? '')],
            ];
        }

        if ($role === 'reseller') {
            return [
                $prefix . 'company_id = :scope_tenancy_id AND (' .
                $prefix . 'user_id = :scope_user_id OR ' .
                $prefix . 'requested_by_user_id = :scope_user_id OR ' .
                $prefix . 'user_id IN (SELECT id FROM users WHERE user_id = :scope_child_owner_id AND tenancy_id = :scope_child_tenancy_id)' .
                ')',
                [
                    ':scope_tenancy_id' => (string)($user['tenancy_id'] ?? ''),
                    ':scope_user_id' => (int)($user['id'] ?? 0),
                    ':scope_child_owner_id' => (int)($user['id'] ?? 0),
                    ':scope_child_tenancy_id' => (string)($user['tenancy_id'] ?? ''),
                ],
            ];
        }

        return [
            $prefix . 'company_id = :scope_tenancy_id AND ' .
            $prefix . 'owner_type = :scope_owner_type AND ' .
            $prefix . 'owner_id = :scope_owner_id',
            [
                ':scope_tenancy_id' => (string)($user['tenancy_id'] ?? ''),
                ':scope_owner_type' => match ($role) {
                    'admin' => 'admin',
                    'reseller' => 'reseller',
                    default => 'client',
                },
                ':scope_owner_id' => (int)($user['id'] ?? 0),
            ],
        ];
    }

    private static function requestOwnerScope(array $user, string $alias = ''): array
    {
        $prefix = $alias !== '' ? $alias . '.' : '';
        $role = strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));

        if ($role === 'super_admin') {
            return ['1=1', []];
        }

        if ($role === 'admin') {
            return [
                $prefix . 'company_id = :scope_tenancy_id',
                [':scope_tenancy_id' => (string)($user['tenancy_id'] ?? '')],
            ];
        }

        if ($role === 'reseller') {
            return [
                $prefix . 'company_id = :scope_tenancy_id AND (' .
                $prefix . 'requested_by_user_id = :scope_user_id OR ' .
                '(' . $prefix . "owner_type IN ('client', 'reseller') AND " .
                $prefix . 'owner_id IN (SELECT id FROM users WHERE user_id = :scope_child_owner_id AND tenancy_id = :scope_child_tenancy_id))' .
                ')',
                [
                    ':scope_tenancy_id' => (string)($user['tenancy_id'] ?? ''),
                    ':scope_user_id' => (int)($user['id'] ?? 0),
                    ':scope_child_owner_id' => (int)($user['id'] ?? 0),
                    ':scope_child_tenancy_id' => (string)($user['tenancy_id'] ?? ''),
                ],
            ];
        }

        return [
            $prefix . 'company_id = :scope_tenancy_id AND ' .
            $prefix . 'requested_by_user_id = :scope_user_id',
            [
                ':scope_tenancy_id' => (string)($user['tenancy_id'] ?? ''),
                ':scope_user_id' => (int)($user['id'] ?? 0),
            ],
        ];
    }

    private static function isPlatformAdmin(array $user): bool
    {
        return strtolower((string)($user['user_function'] ?? $user['function'] ?? '')) === 'super_admin';
    }

    private static function markFailed(int $numberId, string $error): void
    {
        (new Database('whatsapp_numbers'))->update('id = :id', [
            'status' => 'blocked',
            'last_error' => $error,
            'last_meta_error' => $error,
            'last_meta_error_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $numberId]);
    }

    private static function displayNameApprovalEstimate(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $row = (new Database('whatsapp_numbers'))->execute(
            "SELECT
                COUNT(*) AS total,
                AVG(display_name_approval_seconds) AS avg_seconds,
                MIN(display_name_approval_seconds) AS min_seconds,
                MAX(display_name_approval_seconds) AS max_seconds
             FROM (
                SELECT display_name_approval_seconds
                FROM whatsapp_numbers
                WHERE display_name_approval_seconds IS NOT NULL
                  AND display_name_approval_seconds > 0
                ORDER BY display_name_approved_at DESC
                LIMIT {$limit}
             ) recent"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $total = (int)($row['total'] ?? 0);
        if ($total === 0) {
            return [
                'sample_size' => 0,
                'min_hours' => 1,
                'max_hours' => 24,
                'avg_hours' => null,
                'message' => 'A Meta normalmente leva entre 1 e 24 horas para aprovar o nome.',
            ];
        }

        $avgHours = max(1, (int)ceil(((float)$row['avg_seconds']) / 3600));
        $minHours = max(1, (int)floor(((float)$row['min_seconds']) / 3600));
        $maxHours = max($avgHours, (int)ceil(((float)$row['max_seconds']) / 3600));

        return [
            'sample_size' => $total,
            'min_hours' => $minHours,
            'max_hours' => $maxHours,
            'avg_hours' => $avgHours,
            'message' => "A Meta normalmente leva entre {$minHours} e {$maxHours} horas para aprovar o nome.",
        ];
    }

    private static function platformWabaId(): string
    {
        $wabaId = WhatsAppConfig::platformWabaId();
        if ($wabaId === '') {
            throw new \RuntimeException('Conta central de WhatsApp não configurada.');
        }

        return $wabaId;
    }

    private static function platformAccessToken(): string
    {
        $token = WhatsAppConfig::platformAccessToken();
        if ($token === '') {
            throw new \RuntimeException('Token da plataforma não configurado.');
        }

        return $token;
    }

    private static function platformPin(): string
    {
        $pin = WhatsAppConfig::defaultTwoStepPin();
        if (!preg_match('/^\d{6}$/', $pin)) {
            throw new \RuntimeException('PIN de 2FA da plataforma não configurado.');
        }

        return $pin;
    }

    private static function defaultDisplayName(array $user): string
    {
        $name = self::nullableString($user['tenancy_name'] ?? null);
        if (!$name && !empty($user['tenancy_id'])) {
            $tenancy = (new Database('tenancies'))
                ->select('id = :id', [':id' => (string)$user['tenancy_id']], '', '1', ['name'])
                ->fetch(PDO::FETCH_ASSOC);
            $name = self::nullableString($tenancy['name'] ?? null);
        }

        $fullName = trim((string)($user['name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        $name = $name ?: self::nullableString($fullName) ?: self::nullableString($user['name'] ?? null) ?: '';

        return mb_substr(trim(preg_replace('/\s+/', ' ', $name) ?: ''), 0, 128, 'UTF-8');
    }

    private static function extractCountryCode(string $phone, string $fallback): string
    {
        $fallback = preg_replace('/\D+/', '', $fallback) ?: '55';
        return str_starts_with($phone, $fallback) ? $fallback : substr($phone, 0, 2);
    }

    private static function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private static function assertValidDisplayName(string $displayName): void
    {
        $name = trim(preg_replace('/\s+/', ' ', $displayName) ?: '');
        if (mb_strlen($name, 'UTF-8') < 5) {
            throw new \InvalidArgumentException('O nome de exibição precisa ter pelo menos 5 caracteres.');
        }

        if (mb_strlen($name, 'UTF-8') > 128) {
            throw new \InvalidArgumentException('O nome de exibição precisa ter no máximo 128 caracteres.');
        }

        $lettersOnly = preg_replace('/[^A-Za-zÀ-ÿ]/u', '', $name) ?: '';
        if (mb_strlen($lettersOnly, 'UTF-8') <= 4 && mb_strtoupper($lettersOnly, 'UTF-8') === $lettersOnly) {
            throw new \InvalidArgumentException('Use um nome de exibição claro, não uma sigla curta.');
        }

        $normalized = self::normalizeDisplayNameForCompare($name);
        $genericTerms = ['teste', 'api', 'bot', 'whatsapp', 'suporte', 'atendimento', 'comercial', 'cobranca', 'cobrança', 'vendas'];
        if (in_array($normalized, $genericTerms, true)) {
            throw new \InvalidArgumentException('Use o nome real da empresa, marca ou pessoa. Nomes genéricos como Teste, API, Bot ou Atendimento não são aceitos.');
        }
    }

    private static function normalizeDisplayNameForCompare(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?: ''), 'UTF-8');
    }
}
