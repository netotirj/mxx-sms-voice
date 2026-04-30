<?php

namespace App\Service;

use App\Model\Entity\BalanceSms;
use App\Model\Entity\RegisterTenancies;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppBilling
{
    public const ERROR_INSUFFICIENT_BALANCE = 'Saldo insuficiente';
    public const ERROR_MISSING_CATEGORY = 'Categoria da mensagem WhatsApp não informada.';

    public static function resolveCategory(string $messageType, ?string $category, bool $serviceWindowOpen): string
    {
        if (trim((string)$category) === '' && $messageType === 'template') {
            throw new \RuntimeException(self::ERROR_MISSING_CATEGORY);
        }

        return WhatsAppCostPolicy::billingCategory($messageType, $category, $serviceWindowOpen);
    }

    public static function priceForUser(int $userId, string $tenancyId, string $category): float
    {
        $category = WhatsAppCostPolicy::normalizeCategory($category);
        if ($category === WhatsAppCostPolicy::CATEGORY_SERVICE) {
            return 0.0;
        }

        $planId = self::resolvePlanId($userId, $tenancyId);
        if (!$planId) {
            return WhatsAppCostPolicy::defaultPriceBrl($category);
        }

        self::ensureDefaultPricing($planId);

        $row = (new Database('plan_whatsapp_pricing'))
            ->select(
                'plan_id = :plan_id AND category = :category',
                [
                    ':plan_id' => $planId,
                    ':category' => strtolower($category),
                ],
                '',
                '1',
                ['price_brl']
            )
            ->fetch(\PDO::FETCH_ASSOC);

        return $row ? round((float)$row['price_brl'], 4) : WhatsAppCostPolicy::defaultPriceBrl($category);
    }

    public static function assertCanSend(int $userId, string $tenancyId, string $category, float $priceBrl): void
    {
        $category = WhatsAppCostPolicy::normalizeCategory($category);
        $priceBrl = round(max(0, $priceBrl), 4);

        if ($category === WhatsAppCostPolicy::CATEGORY_SERVICE || $priceBrl <= 0) {
            return;
        }

        $balance = BalanceSms::getBalanceSms($userId, $tenancyId, self::resolvePlanId($userId, $tenancyId));
        if (!$balance || (float)$balance->balance < $priceBrl) {
            throw new \RuntimeException(self::ERROR_INSUFFICIENT_BALANCE);
        }
    }

    public static function assertCanSendBatch(int $userId, string $tenancyId, array $messages): void
    {
        $total = 0.0;
        foreach ($messages as $message) {
            $category = WhatsAppCostPolicy::normalizeCategory((string)($message['message_category'] ?? ''));
            $priceBrl = round((float)($message['price_brl'] ?? 0), 4);
            if ($category !== WhatsAppCostPolicy::CATEGORY_SERVICE) {
                $total += $priceBrl;
            }
        }

        if ($total <= 0) {
            return;
        }

        $balance = BalanceSms::getBalanceSms($userId, $tenancyId, self::resolvePlanId($userId, $tenancyId));
        if (!$balance || (float)$balance->balance < round($total, 4)) {
            throw new \RuntimeException(self::ERROR_INSUFFICIENT_BALANCE);
        }
    }

    public static function authorizeOutbox(array $row): array
    {
        $category = (string)($row['message_category'] ?? '');
        if ($category === '') {
            $category = self::resolveCategory(
                (string)$row['message_type'],
                $row['template_category'] ?? null,
                (bool)$row['service_window_open']
            );
        }

        $priceBrl = isset($row['price_brl'])
            ? round((float)$row['price_brl'], 4)
            : self::priceForUser((int)$row['user_id'], (string)$row['tenancy_id'], $category);

        self::assertCanSend((int)$row['user_id'], (string)$row['tenancy_id'], $category, $priceBrl);

        return [
            'message_category' => $category,
            'price_brl' => $priceBrl,
            'billed' => false,
        ];
    }

    public static function billSent(array $row, ?int $messageId, ?string $wamid, array $billing): bool
    {
        $category = WhatsAppCostPolicy::normalizeCategory((string)$billing['message_category']);
        $priceBrl = round((float)$billing['price_brl'], 4);
        $billed = false;

        if ($category !== WhatsAppCostPolicy::CATEGORY_SERVICE && $priceBrl > 0) {
            $billed = self::debitBalance((int)$row['user_id'], (string)$row['tenancy_id'], $priceBrl);
            if ($billed) {
                BalanceSms::insertBalanceLog([
                    'user_id' => (int)$row['user_id'],
                    'tenancy_id' => (string)$row['tenancy_id'],
                    'amount' => $priceBrl,
                    'description' => sprintf(
                        'WHATSAPP | phone:%s category:%s template:%s cost:%s',
                        (string)$row['contact_phone'],
                        strtolower($category),
                        (string)($row['template_name'] ?? ''),
                        number_format($priceBrl, 4, '.', '')
                    ),
                ]);
            } else {
                error_log('[whatsapp_billing] Falha ao debitar saldo apos envio WhatsApp outbox_id=' . (int)$row['id']);
            }
        }

        self::generateCdr($row, 'sent', $messageId, $wamid, $category, $priceBrl, $billed);
        return $billed || $category === WhatsAppCostPolicy::CATEGORY_SERVICE || $priceBrl <= 0;
    }

    public static function recordBlocked(array $row, string $error): void
    {
        try {
            $category = self::resolveCategory(
                (string)$row['message_type'],
                $row['template_category'] ?? null,
                (bool)($row['service_window_open'] ?? false)
            );
            $priceBrl = self::priceForUser((int)$row['user_id'], (string)$row['tenancy_id'], $category);
            self::generateCdr($row, 'blocked', null, null, $category, $priceBrl, false, $error);
        } catch (\Throwable $e) {
            error_log('[whatsapp_billing_cdr_blocked] ' . $e->getMessage());
        }
    }

    public static function recordFailed(array $row, string $error): void
    {
        try {
            $billing = self::authorizeOutbox($row);
            self::generateCdr(
                $row,
                'failed',
                null,
                null,
                (string)$billing['message_category'],
                (float)$billing['price_brl'],
                false,
                $error
            );
        } catch (\Throwable $e) {
            error_log('[whatsapp_billing_cdr_failed] ' . $e->getMessage());
        }
    }

    public static function recordDirectSent(array $row, ?int $messageId, ?string $wamid, string $category, float $priceBrl): void
    {
        $category = WhatsAppCostPolicy::normalizeCategory($category);
        $priceBrl = round(max(0, $priceBrl), 4);
        $billed = false;

        if ($category !== WhatsAppCostPolicy::CATEGORY_SERVICE && $priceBrl > 0) {
            $billed = self::debitBalance((int)$row['user_id'], (string)$row['tenancy_id'], $priceBrl);
            if ($billed) {
                BalanceSms::insertBalanceLog([
                    'user_id' => (int)$row['user_id'],
                    'tenancy_id' => (string)$row['tenancy_id'],
                    'amount' => $priceBrl,
                    'description' => sprintf(
                        'WHATSAPP | phone:%s category:%s template:%s cost:%s',
                        (string)$row['contact_phone'],
                        strtolower($category),
                        (string)($row['template_name'] ?? ''),
                        number_format($priceBrl, 4, '.', '')
                    ),
                ]);
            }
        }

        self::generateCdr($row, 'sent', $messageId, $wamid, $category, $priceBrl, $billed);
    }

    private static function resolvePlanId(int $userId, string $tenancyId): ?int
    {
        $planId = RegisterTenancies::getActivePlanId($tenancyId);
        if ($planId) {
            return $planId;
        }

        $balance = BalanceSms::getBalanceSms($userId, $tenancyId);
        return $balance && $balance->plan_id ? (int)$balance->plan_id : null;
    }

    private static function ensureDefaultPricing(int $planId): void
    {
        foreach ([WhatsAppCostPolicy::CATEGORY_MARKETING, WhatsAppCostPolicy::CATEGORY_UTILITY] as $category) {
            (new Database())->execute(
                "INSERT INTO plan_whatsapp_pricing (plan_id, category, price_brl, created_at, updated_at)
                 VALUES (:plan_id, :category, :price_brl, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE price_brl = price_brl",
                [
                    ':plan_id' => $planId,
                    ':category' => strtolower($category),
                    ':price_brl' => WhatsAppCostPolicy::defaultPriceBrl($category),
                ]
            );
        }
    }

    private static function debitBalance(int $userId, string $tenancyId, float $amount): bool
    {
        $amount = round(max(0, $amount), 4);
        if ($amount <= 0) {
            return true;
        }

        $balance = BalanceSms::getBalanceSms($userId, $tenancyId, self::resolvePlanId($userId, $tenancyId));
        if (!$balance || (float)$balance->balance < $amount) {
            return false;
        }

        if (isset($balance->id, $balance->plan_id) && $balance->id && $balance->plan_id) {
            return (new Database())->execute(
                "UPDATE tenancy_balance
                 SET balance = balance - :amount, updated_at = NOW()
                 WHERE id = :id
                   AND user_id = :user_id
                   AND tenancy_id = :tenancy_id
                   AND balance >= :amount",
                [
                    ':amount' => $amount,
                    ':id' => (int)$balance->id,
                    ':user_id' => $userId,
                    ':tenancy_id' => $tenancyId,
                ]
            )->rowCount() === 1;
        }

        return BalanceSms::decrementResellerBalance($amount, $userId, $tenancyId);
    }

    private static function generateCdr(
        array $row,
        string $status,
        ?int $messageId,
        ?string $wamid,
        string $category,
        float $priceBrl,
        bool $billed,
        ?string $error = null
    ): void {
        (new Database('whatsapp_message_cdr'))->insert([
            'client_id' => (int)$row['user_id'],
            'tenancy_id' => (string)$row['tenancy_id'],
            'type' => 'whatsapp',
            'phone_number' => (string)$row['contact_phone'],
            'message_category' => strtolower(WhatsAppCostPolicy::normalizeCategory($category)),
            'template_name' => $row['template_name'] ?? null,
            'direction' => 'outbound',
            'price_brl' => round(max(0, $priceBrl), 4),
            'billed' => $billed ? 1 : 0,
            'status' => $status,
            'whatsapp_outbox_id' => (int)($row['id'] ?? 0) ?: null,
            'whatsapp_message_id' => $messageId,
            'wamid' => $wamid,
            'error_message' => $error,
            'timestamp' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
