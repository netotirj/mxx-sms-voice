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
                'wn.display_name',
                'wn.origin',
                'wn.status',
                'wn.meta_id',
                'wn.waba_id',
                'wn.verification_method',
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
                'display_name',
                'origin',
                'status',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function listNumberRequests(array $user): array
    {
        [$where, $params] = self::requestOwnerScope($user, 'nr');

        return (new Database('number_requests nr LEFT JOIN users u ON u.id = nr.requested_by_user_id LEFT JOIN whatsapp_numbers wn ON wn.id = nr.whatsapp_number_id'))
            ->select($where, $params, 'nr.created_at DESC, nr.id DESC', '', [
                'nr.id',
                'nr.company_id',
                'nr.requested_by_user_id',
                'nr.owner_type',
                'nr.owner_id',
                'nr.phone_number',
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
                'wn.verified_at',
                'wn.updated_at AS number_updated_at',
                "CASE WHEN wn.status = 'code_sent' THEN DATE_ADD(wn.updated_at, INTERVAL 5 MINUTE) ELSE NULL END AS code_expires_at",
                'u.name AS requested_by_name',
                'u.email AS requested_by_email',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

        $id = (int)(new Database('number_requests'))->insert([
            'company_id' => $targetOwner['tenancy_id'],
            'requested_by_user_id' => (int)$user['id'],
            'owner_type' => $targetOwner['owner_type'],
            'owner_id' => (int)$targetOwner['id'],
            'phone_number' => $phone,
            'display_name' => self::nullableString($input['display_name'] ?? $input['label'] ?? null) ?: self::defaultDisplayName($targetOwner),
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
            throw new \RuntimeException('Envie a solicitação para a Meta antes de aprovar para o solicitante.');
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
            'Solicitação aprovada. O número foi aceito na Meta e o código de confirmação será enviado agora.'
        );

        $number = self::sendVerificationCode($user, (int)$request['whatsapp_number_id'], 'SMS');

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

        self::assertApprovedRequestForNumber($numberId);

        return self::requestVerificationCodeForNumber($numberId, $number, $method);
    }

    public static function resendNumberRequestVerificationCode(array $user, int $requestId, string $method = 'SMS'): array
    {
        $request = self::getRequestForUser($requestId, $user);
        if (!$request || (string)$request['status'] !== 'approved' || empty($request['whatsapp_number_id'])) {
            throw new \RuntimeException('Código disponível apenas após aprovação da solicitação.');
        }
        self::assertCanHandleRequestCode($user, $request);

        $number = self::getForUser((int)$request['whatsapp_number_id'], $user);
        if (!$number) {
            throw new \RuntimeException('Número da solicitação não encontrado.');
        }

        $number = self::requestVerificationCodeForNumber((int)$number['id'], $number, $method);

        self::appendNumberRequestTicketMessage(
            $request,
            $user,
            'customer',
            'Reenvio do código de verificação solicitado.'
        );

        return [
            'request' => self::getRequestForUser($requestId, $user),
            'number' => $number,
        ];
    }

    public static function confirmVerificationCode(array $user, int $numberId, string $code): array
    {
        self::assertPlatformAdmin($user);

        $number = self::getForUser($numberId, $user);
        if (!$number) {
            throw new \RuntimeException('Número não encontrado.');
        }

        $code = preg_replace('/\D+/', '', $code) ?: '';
        if (strlen($code) < 4) {
            throw new \InvalidArgumentException('Informe o código recebido.');
        }

        self::assertApprovedRequestForNumber($numberId);

        return self::verifyAndAttachNumber($user, $number, $code);
    }

    public static function confirmNumberRequestVerificationCode(array $user, int $requestId, string $code): array
    {
        $request = self::getRequestForUser($requestId, $user);
        if (!$request || (string)$request['status'] !== 'approved' || empty($request['whatsapp_number_id'])) {
            throw new \RuntimeException('Código disponível apenas após aprovação da solicitação.');
        }
        self::assertCanHandleRequestCode($user, $request);

        $number = self::getForUser((int)$request['whatsapp_number_id'], $user);
        if (!$number) {
            throw new \RuntimeException('Número da solicitação não encontrado.');
        }

        $connected = self::verifyAndAttachNumber($user, $number, $code);

        self::appendNumberRequestTicketMessage(
            $request,
            $user,
            'customer',
            'Código confirmado. Número WhatsApp conectado.'
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
        $owner = self::ownerFromNumber($number);
        self::assertOwnerHasNoActiveNumber($owner, $numberId);

        $accountId = (int)($number['whatsapp_account_id'] ?? 0);
        if ($accountId <= 0) {
            $accountId = WhatsAppAccount::create([
                'tenancy_id' => $owner['tenancy_id'],
                'user_id' => (int)$owner['id'],
                'label' => $number['display_name'] ?: ('WhatsApp ' . $number['phone_number']),
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
            'last_error' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $numberId]);

        return self::getForUser($numberId, $user) ?: $number;
    }

    public static function createPlatformNumber(array $user, array $input): array
    {
        self::assertPlatformAdmin($user);

        $phone = self::normalizePhone((string)($input['phone_number'] ?? $input['phone'] ?? ''));
        $metaId = trim((string)($input['meta_id'] ?? $input['phone_number_id'] ?? ''));
        $displayName = self::nullableString($input['display_name'] ?? $input['label'] ?? null) ?: ('WhatsApp ' . $phone);

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
        }

        $accountId = WhatsAppAccount::create([
            'tenancy_id' => $targetOwner['tenancy_id'] ?? 'platform',
            'user_id' => (int)($targetOwner['id'] ?? $user['id']),
            'label' => $displayName,
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
            'display_name' => $displayName,
            'origin' => 'platform',
            'status' => $targetOwner ? 'active' : 'available',
            'meta_id' => $metaId,
            'waba_id' => self::nullableString($input['waba_id'] ?? null) ?: self::platformWabaId(),
            'verification_method' => null,
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

        if (!$result['ok']) {
            self::markFailed($numberId, $result['error'] ?: 'Falha ao remover número da Meta.');
            throw new \RuntimeException($result['error'] ?: 'Falha ao remover número da Meta.');
        }

        self::revokeAccess($number);

        (new Database('whatsapp_numbers'))->update('id = :id', [
            'status' => 'removed',
            'owner_type' => null,
            'owner_id' => null,
            'removed_at' => date('Y-m-d H:i:s'),
            'last_error' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $numberId]);

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
        if (!in_array((string)$number['status'], ['pending', 'code_sent', 'failed'], true)) {
            throw new \RuntimeException('Este número não está aguardando código.');
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
            self::markFailed($numberId, $result['error'] ?: 'Falha ao enviar código.');
            throw new \RuntimeException($result['error'] ?: 'Não foi possível enviar o código.');
        }

        (new Database('whatsapp_numbers'))->update('id = :id', [
            'status' => 'code_sent',
            'verification_method' => $method,
            'last_error' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $numberId]);

        return self::getForUser($numberId, self::ownerFromNumber($number)) ?: $number;
    }

    private static function verifyAndAttachNumber(array $user, array $number, string $code): array
    {
        $numberId = (int)($number['id'] ?? 0);
        $code = preg_replace('/\D+/', '', $code) ?: '';
        if (strlen($code) < 4) {
            throw new \InvalidArgumentException('Informe o código recebido.');
        }

        self::assertVerificationCodeFresh($number);

        $api = new MetaWhatsAppCloudApi();
        $verified = $api->verifyPhoneNumberCode(self::platformAccessToken(), (string)$number['meta_id'], $code);
        if (!$verified['ok']) {
            self::markFailed($numberId, $verified['error'] ?: 'Código inválido.');
            throw new \RuntimeException($verified['error'] ?: 'Código inválido ou expirado.');
        }

        $pin = self::platformPin();
        $registered = $api->registerPhoneNumber(self::platformAccessToken(), (string)$number['meta_id'], $pin);
        if (!$registered['ok']) {
            self::markFailed($numberId, $registered['error'] ?: 'Número validado, mas não conectado para envio.');
            throw new \RuntimeException($registered['error'] ?: 'Número validado, mas não conectado para envio.');
        }

        return self::attachNumberToCompanyInternal($user, $numberId, $number);
    }

    private static function assertVerificationCodeFresh(array $number): void
    {
        if ((string)($number['status'] ?? '') !== 'code_sent') {
            throw new \RuntimeException('Solicite o código de verificação antes de confirmar.');
        }

        $sentAt = strtotime((string)($number['updated_at'] ?? ''));
        if (!$sentAt || $sentAt + 300 < time()) {
            throw new \RuntimeException('Código expirado. Solicite um novo código.');
        }
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

        $displayName = self::nullableString($metaRequest['display_name'] ?? null)
            ?: self::defaultDisplayName($owner);
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
            'display_name' => $displayName,
            'origin' => 'client',
            'status' => 'pending',
            'meta_id' => $metaId,
            'waba_id' => $wabaId,
            'verification_method' => null,
            'verified_at' => null,
            'removed_at' => null,
            'last_error' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return self::getForUser($id, $user) ?: ['id' => $id, 'phone_number' => $phone, 'status' => 'pending'];
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

    private static function findApprovedRequestForNumber(int $numberId): ?array
    {
        $row = (new Database('number_requests'))
            ->select(
                "whatsapp_number_id = :number_id AND status = 'approved'",
                [':number_id' => $numberId],
                '',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function assertApprovedRequestForNumber(int $numberId): void
    {
        if (!self::findApprovedRequestForNumber($numberId)) {
            throw new \RuntimeException('Integração bloqueada: número sem solicitação aprovada.');
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
            throw new \RuntimeException('Você não pode validar o código desta solicitação.');
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
            'status' => 'failed',
            'last_error' => $error,
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $numberId]);
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
        $name = trim((string)($user['tenancy_name'] ?? $user['name'] ?? 'Atendimento'));
        return mb_substr($name, 0, 160);
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
}
