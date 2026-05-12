<?php

namespace App\Service;

use App\Controller\Pages\AsteriskExtensionsSip;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\Notifications;
use App\Model\Entity\PlanCatalog;
use App\Model\Entity\PixSearch;
use App\Model\Entity\RegisterTenancies;
use App\Model\Entity\UserPlans;
use App\Model\Entity\UserSearch;

class PixService
{
    private const PAID_EVENTS = ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'];
    private const SUPPORTED_EVENTS = ['PAYMENT_CREATED', 'PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED', 'PAYMENT_REFUNDED'];

    public static function success(array $data = [], string $message = 'OK', int $status = 200): array
    {
        return [
            'success' => true,
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ];
    }

    public static function error(string $error, int $status = 400, array $data = []): array
    {
        return [
            'success' => false,
            'status' => $status,
            'error' => $error,
            'data' => $data,
        ];
    }

    public static function normalizeEvent(?string $event): string
    {
        return strtoupper(trim((string)$event));
    }

    public static function isSupportedEvent(string $event): bool
    {
        return in_array($event, self::SUPPORTED_EVENTS, true);
    }

    public static function isPaidEvent(string $event): bool
    {
        return in_array($event, self::PAID_EVENTS, true);
    }

    public static function log(string $message, array $context = []): void
    {
        PixSearch::ensureSchema();
        $safe = self::sanitizeLogContext($context);
        error_log('[pix] ' . $message . ' ' . json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        PixSearch::appendApiLog($message, $safe, self::inferLogLevel($message));
    }

    public static function validateProviderPayment(array $payment, string $event): array
    {
        PixSearch::ensureSchema();
        $invoice = trim((string)($payment['invoiceNumber'] ?? ''));
        $pixQrCodeId = trim((string)($payment['pixQrCodeId'] ?? ''));
        $externalReference = trim((string)($payment['externalReference'] ?? ''));

        if ($invoice === '' && $pixQrCodeId === '' && $externalReference === '') {
            return self::error('Webhook sem identificador de pagamento reconhecido.', 422);
        }

        $pix = PixSearch::findByProviderPayload($payment);
        if (!$pix) {
            return self::error('Pagamento nao encontrado para os identificadores recebidos.', 404, [
                'invoiceNumber' => $invoice,
                'pixQrCodeId' => $pixQrCodeId,
                'externalReference' => $externalReference,
            ]);
        }

        $providerValue = isset($payment['value']) ? round((float)$payment['value'], 2) : null;
        $expectedValue = round((float)$pix->value, 2);
        if ($providerValue === null || $providerValue !== $expectedValue) {
            return self::error('Valor recebido diverge do valor esperado.', 422, [
                'invoiceNumber' => $invoice ?: $pix->invoiceNumber,
                'pixQrCodeId' => $pixQrCodeId ?: $pix->pixQrCodeId,
                'expected' => $expectedValue,
                'received' => $providerValue,
            ]);
        }

        if ($externalReference !== '' && (string)$pix->external_Reference !== '' && $externalReference !== (string)$pix->external_Reference) {
            return self::error('externalReference nao corresponde ao registro PIX.', 422, [
                'invoiceNumber' => $invoice ?: $pix->invoiceNumber,
                'pixQrCodeId' => $pixQrCodeId ?: $pix->pixQrCodeId,
            ]);
        }

        if (!self::isSupportedEvent($event)) {
            return self::success([
                'invoiceNumber' => $invoice ?: $pix->invoiceNumber,
                'pixQrCodeId' => $pixQrCodeId ?: $pix->pixQrCodeId,
                'event' => $event,
                'ignored' => true,
            ], 'Evento ignorado com seguranca.');
        }

        return self::success([
            'pix' => $pix,
            'invoiceNumber' => $invoice ?: (string)($pix->invoiceNumber ?? ''),
            'pixQrCodeId' => $pixQrCodeId ?: (string)($pix->pixQrCodeId ?? ''),
        ]);
    }

    public static function processWebhookPayment(string $event, array $payment): array
    {
        PixSearch::ensureSchema();
        $event = self::normalizeEvent($event);
        self::log('webhook_received', [
            'event' => $event,
            'invoiceNumber' => $payment['invoiceNumber'] ?? null,
            'pixQrCodeId' => $payment['pixQrCodeId'] ?? null,
            'paymentId' => $payment['id'] ?? null,
            'value' => $payment['value'] ?? null,
        ]);

        if (!self::isSupportedEvent($event)) {
            return self::success(['event' => $event, 'ignored' => true], 'Evento ignorado com seguranca.');
        }

        $validation = self::validateProviderPayment($payment, $event);
        if (!$validation['success']) {
            self::log('webhook_rejected', $validation);
            return $validation;
        }

        /** @var PixSearch $pix */
        $pix = $validation['data']['pix'];
        $invoice = (string)$validation['data']['invoiceNumber'];

        if ($event === 'PAYMENT_CREATED') {
            PixSearch::updateProviderStatusForPix($pix, $event, $payment, false);
            return self::success([
                'invoiceNumber' => $invoice,
                'pixQrCodeId' => $validation['data']['pixQrCodeId'] ?? null,
                'event' => $event
            ], 'Pagamento criado registrado.');
        }

        if ($event === 'PAYMENT_REFUNDED') {
            return self::processRefund($pix, $payment);
        }

        if (self::isPaidEvent($event)) {
            return self::confirmPayment($pix, $event, $payment);
        }

        return self::success(['invoiceNumber' => $invoice, 'event' => $event], 'Evento sem acao.');
    }

    public static function syncProviderStatusPayment(PixSearch $pix, array $payment): array
    {
        $providerStatus = strtoupper(trim((string)($payment['status'] ?? '')));
        $event = match ($providerStatus) {
            'RECEIVED' => 'PAYMENT_RECEIVED',
            'CONFIRMED' => 'PAYMENT_CONFIRMED',
            'REFUNDED' => 'PAYMENT_REFUNDED',
            default => 'PAYMENT_CREATED',
        };

        $payment['pixQrCodeId'] = $payment['pixQrCodeId'] ?? $pix->pixQrCodeId;
        $payment['invoiceNumber'] = $payment['invoiceNumber'] ?? $pix->invoiceNumber;
        $payment['externalReference'] = $payment['externalReference'] ?? $pix->external_Reference;
        $payment['value'] = $payment['value'] ?? $pix->value;

        self::log('status_sync_provider_payment', [
            'event' => $event,
            'providerStatus' => $providerStatus,
            'invoiceNumber' => $payment['invoiceNumber'] ?? null,
            'pixQrCodeId' => $payment['pixQrCodeId'] ?? null,
            'paymentId' => $payment['id'] ?? null,
            'webhook_id' => $pix->webhook_id,
            'user_id' => $pix->user_id,
            'tenancy_id' => $pix->tenancy_id,
        ]);

        return self::processWebhookPayment($event, $payment);
    }

    public static function confirmPayment(PixSearch $pix, string $event, array $payment = []): array
    {
        $invoice = (string)$pix->invoiceNumber;
        $paymentReference = $invoice !== '' && $invoice !== '0' ? $invoice : (string)$pix->pixQrCodeId;
        $payment['value'] = $payment['value'] ?? $pix->value;
        $planInfo = UserPlans::getUserPlanInfo(
            (int)$pix->user_plain_id,
            (int)$pix->user_id,
            (string)$pix->tenancy_id
        );

        if (!$planInfo) {
            self::log('plan_not_found_after_payment', [
                'invoiceNumber' => $invoice,
                'user_id' => $pix->user_id,
                'tenancy_id' => $pix->tenancy_id,
                'plan_id' => $pix->user_plain_id,
            ]);

            return self::error('Plano vinculado ao PIX nao encontrado.', 500);
        }

        $claimed = PixSearch::markPaidOnceForPix($pix, $event, $payment);

        if (!$claimed) {
            self::log('payment_already_processed', [
                'invoiceNumber' => $invoice,
                'pixQrCodeId' => $pix->pixQrCodeId,
                'event' => $event,
            ]);

            return self::success([
                'invoiceNumber' => $invoice,
                'pixQrCodeId' => $pix->pixQrCodeId,
                'processed' => false,
                'idempotent' => true,
            ], 'Pagamento ja processado anteriormente.');
        }

        $credited = BalanceSms::insertBalance(
            (int)$planInfo->user_id,
            (int)$planInfo->plan_id,
            (string)$planInfo->tenancy_id,
            (float)$planInfo->amount_plan,
            (float)($planInfo->value_sms ?? 0),
            (float)($planInfo->value_voice ?? 0),
            (float)($planInfo->value_torpedo ?? 0),
            $paymentReference,
            (float)($planInfo->voice_open_rate ?? $planInfo->value_voice ?? 0),
            (float)($planInfo->voice_smart_rate ?? $planInfo->value_voice ?? 0),
            (float)($planInfo->value_whatsapp ?? 0),
            (float)($planInfo->service_fee ?? 0),
            self::planSnapshotJson((int)$planInfo->plan_id),
            (string)($planInfo->name_plan ?? ''),
            (string)(PlanCatalog::findById((int)$planInfo->plan_id)['billing_cycle'] ?? 'monthly'),
            (float)($planInfo->amount_plan ?? 0)
        );

        UserPlans::updateUserPlan((int)$planInfo->user_plan_id, [
            'status_payment' => 'confirmed'
        ]);

        RegisterTenancies::updateActivePlan((string)$planInfo->tenancy_id, (int)$planInfo->plan_id);
        PlanRuntimeService::refreshPlanRuntime((string)$planInfo->tenancy_id);

        self::syncAdminBalance((int)$planInfo->user_id, (string)$planInfo->tenancy_id, (float)$pix->value);

        Notifications::insertNotifications(
            (string)$planInfo->tenancy_id,
            (int)$planInfo->user_id,
            'Pagamento confirmado',
            'O pagamento referente a fatura <b>#' . $paymentReference . '</b> foi confirmado, seu plano e saldo foram atualizados.',
            'notice'
        );

        self::log('payment_confirmed', [
            'invoiceNumber' => $invoice,
            'user_id' => $planInfo->user_id,
            'tenancy_id' => $planInfo->tenancy_id,
            'credited' => $credited,
        ]);

        return self::success([
            'invoiceNumber' => $invoice,
            'pixQrCodeId' => $pix->pixQrCodeId,
            'paymentReference' => $paymentReference,
            'processed' => true,
            'credited' => $credited,
        ], 'Pagamento confirmado com sucesso.');
    }

    private static function planSnapshotJson(int $planId): ?string
    {
        $plan = PlanCatalog::findById($planId);
        if (!$plan) {
            return null;
        }

        return json_encode(
            PlanCatalog::buildSnapshotPayload($plan),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    private static function processRefund(PixSearch $pix, array $payment): array
    {
        $invoice = (string)$pix->invoiceNumber;
        $paymentReference = $invoice !== '' && $invoice !== '0' ? $invoice : (string)$pix->pixQrCodeId;
        $claimed = PixSearch::markRefundedOnceForPix($pix, $payment);

        if (!$claimed) {
            return self::success([
                'invoiceNumber' => $invoice,
                'processed' => false,
                'idempotent' => true,
            ], 'Estorno ja processado anteriormente.');
        }

        BalanceSms::decrementBalance((int)$pix->user_id, (string)$pix->tenancy_id, (float)$pix->value, $paymentReference);
        self::syncAdminBalance((int)$pix->user_id, (string)$pix->tenancy_id, -1 * (float)$pix->value);

        $planInfo = UserPlans::getUserPlanInfo(
            (int)$pix->user_plain_id,
            (int)$pix->user_id,
            (string)$pix->tenancy_id
        );

        $revertedToBootstrap = false;
        $bootstrapPlanId = self::resolveBootstrapPlanId();

        if ($planInfo && !empty($planInfo->user_plan_id)) {
            UserPlans::markRefunded((int)$planInfo->user_plan_id);

            $currentActivePlanId = RegisterTenancies::getActivePlanId((string)$pix->tenancy_id);
            if (
                $bootstrapPlanId > 0
                && $currentActivePlanId !== null
                && (int)$currentActivePlanId === (int)$planInfo->plan_id
                && (int)$planInfo->plan_id !== $bootstrapPlanId
            ) {
                RegisterTenancies::updateActivePlan((string)$pix->tenancy_id, $bootstrapPlanId);
                PlanRuntimeService::refreshPlanRuntime((string)$pix->tenancy_id);
                $revertedToBootstrap = true;
            }
        }

        Notifications::insertNotifications(
            (string)$pix->tenancy_id,
            (int)$pix->user_id,
            'Pagamento estornado',
            'O pagamento referente a fatura <b>#' . $paymentReference . '</b> foi estornado. O valor foi debitado do seu saldo.' .
            ($revertedToBootstrap ? ' O plano ativo foi revertido para o bootstrap.' : ''),
            'alert'
        );

        self::log('payment_refunded', [
            'invoiceNumber' => $invoice,
            'user_id' => $pix->user_id,
            'tenancy_id' => $pix->tenancy_id,
            'reverted_to_bootstrap' => $revertedToBootstrap,
            'bootstrap_plan_id' => $bootstrapPlanId,
        ]);

        return self::success([
            'invoiceNumber' => $invoice,
            'pixQrCodeId' => $pix->pixQrCodeId,
            'paymentReference' => $paymentReference,
            'processed' => true,
            'revertedToBootstrap' => $revertedToBootstrap,
        ], 'Estorno processado com sucesso.');
    }

    private static function resolveBootstrapPlanId(): int
    {
        $plans = PlanCatalog::all(['type_plan' => 'bootstrap']);

        foreach ($plans as $plan) {
            $slug = strtolower(trim((string)($plan['slug'] ?? '')));
            $name = strtolower(trim((string)($plan['name_plan'] ?? '')));
            if ($slug === 'bootstrap-admin-bootstrap' || $name === 'bootstrap') {
                return (int)($plan['id'] ?? 0);
            }
        }

        return 37;
    }

    private static function syncAdminBalance(int $userId, string $tenancyId, float $amount): void
    {
        $admin = UserSearch::getUserById($tenancyId, $userId);
        $role = $admin['user_function'] ?? 'admin';

        try {
            (new AsteriskExtensionsSip())->updateBalance(
                ['user_id' => $userId, 'tenant_id' => $tenancyId],
                [
                    'user_id' => $userId,
                    'tenant_id' => $tenancyId,
                    'balance_admin' => $amount,
                    'role' => $role,
                ]
            );
        } catch (\Throwable $e) {
            self::log('asterisk_balance_sync_failed', [
                'user_id' => $userId,
                'tenancy_id' => $tenancyId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function sanitizeLogContext(array $context): array
    {
        $safe = $context;
        unset(
            $safe['access_token'],
            $safe['apiKey'],
            $safe['clientSecret'],
            $safe['encodedImage'],
            $safe['pix_encoded_image'],
            $safe['payload'],
            $safe['pix_payload'],
            $safe['last_webhook_payload']
        );

        return $safe;
    }

    private static function inferLogLevel(string $message): string
    {
        $normalized = strtolower($message);
        if (str_contains($normalized, 'error') || str_contains($normalized, 'failed') || str_contains($normalized, 'rejected') || str_contains($normalized, 'unauthorized')) {
            return 'error';
        }

        if (str_contains($normalized, 'created') || str_contains($normalized, 'confirmed') || str_contains($normalized, 'received')) {
            return 'success';
        }

        return 'info';
    }
}
