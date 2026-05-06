<?php

namespace App\Service;

use RuntimeException;
use WilliamCosta\DatabaseManager\Database;

class VoicePricingService
{
    public const BILLING_OPEN = 'tarifa_aberta';
    public const BILLING_SMART = 'tarifa_inteligente';

    private static array $columnCache = [];

    public static function trunkBillingType(array $trunk): string
    {
        foreach (['billing_type', 'tariff_type', 'voice_tariff_type'] as $field) {
            $normalized = self::normalizeBillingType($trunk[$field] ?? null);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $normalized = self::normalizeBillingType($trunk['cli_type'] ?? null);
        if ($normalized !== null) {
            return $normalized;
        }

        throw new RuntimeException('Tronco sem tipo de tarifacao de voz configurado.');
    }

    public static function normalizeBillingType(mixed $value): ?string
    {
        $value = strtolower(trim((string)$value));
        if ($value === '') {
            return null;
        }

        return match ($value) {
            self::BILLING_OPEN, 'cli_aberta', 'cli_open', 'open', 'aberta' => self::BILLING_OPEN,
            self::BILLING_SMART, 'bina_inteligente', 'smart', 'inteligente', 'bina' => self::BILLING_SMART,
            default => null,
        };
    }

    public static function cliTypeForBilling(string $billingType): string
    {
        return match ($billingType) {
            self::BILLING_OPEN => 'cli_aberta',
            self::BILLING_SMART => 'bina_inteligente',
            default => throw new RuntimeException('Tipo de tarifacao de voz invalido.'),
        };
    }

    public static function planRates(int $userId, string $tenancyId, ?int $planId): array
    {
        self::assertPricingColumns();

        $wherePlan = $planId ? 'p.id = :plan_id' : '1=1';
        $params = [
            ':user_id' => $userId,
            ':tenancy_id' => $tenancyId,
        ];

        if ($planId) {
            $params[':plan_id'] = $planId;
        }

        $row = (new Database())->execute(
            "
            SELECT
                p.id AS plan_id,
                COALESCE(b.value_sms, p.value_sms, 0) AS sms,
                COALESCE(b.value_torpedo, p.value_torpedo, 0) AS torpedo,
                COALESCE(b.voice_open_rate, p.voice_open_rate, 0) AS voice_open_rate,
                COALESCE(b.voice_smart_rate, p.voice_smart_rate, 0) AS voice_smart_rate,
                COALESCE(b.service_fee, 0) AS service_fee
            FROM mxx_plans p
            LEFT JOIN tenancy_balance b
                ON b.id = (
                    SELECT tb.id
                    FROM tenancy_balance tb
                    WHERE tb.plan_id = p.id
                      AND tb.user_id = :user_id
                      AND tb.tenancy_id = :tenancy_id
                    ORDER BY tb.updated_at DESC
                    LIMIT 1
                )
            WHERE {$wherePlan}
            ORDER BY p.id DESC
            LIMIT 1
            ",
            $params
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Plano ativo nao encontrado para tarifacao de voz.');
        }

        return [
            'plan_id' => (int)$row['plan_id'],
            'sms' => round((float)$row['sms'], 4),
            'torpedo' => round((float)$row['torpedo'], 4),
            'voice_open_rate' => round((float)$row['voice_open_rate'], 4),
            'voice_smart_rate' => round((float)$row['voice_smart_rate'], 4),
            'service_fee' => round((float)$row['service_fee'], 4),
        ];
    }

    public static function quote(array $args): array
    {
        $trunk = $args['trunk'] ?? [];
        if (!is_array($trunk)) {
            throw new RuntimeException('Tronco invalido para cotacao de voz.');
        }

        $billingType = self::trunkBillingType($trunk);
        $rates = self::planRates(
            (int)$args['user_id'],
            (string)$args['tenancy_id'],
            isset($args['plan_id']) ? (int)$args['plan_id'] : null
        );

        $voiceRate = match ($billingType) {
            self::BILLING_OPEN => $rates['voice_open_rate'],
            self::BILLING_SMART => $rates['voice_smart_rate'],
        };

        if ($voiceRate <= 0) {
            throw new RuntimeException('Tarifa de voz nao configurada no plano ativo.');
        }

        return [
            'plan_id' => $rates['plan_id'],
            'trunk_billing_type' => $billingType,
            'tariff_used' => $voiceRate,
            'call_minute_cost' => $voiceRate,
            'sms_cost' => $rates['sms'],
            'torpedo_cost' => $rates['torpedo'],
            'voice_open_rate' => $rates['voice_open_rate'],
            'voice_smart_rate' => $rates['voice_smart_rate'],
            'service_fee' => $rates['service_fee'],
        ];
    }

    public static function estimateCampaignTotal(int $contacts, float $primaryRate, float $smsRate, bool $hasSmsDirect, array $dtmf): float
    {
        $smsEvents = $hasSmsDirect ? 1 : 0;

        foreach ($dtmf as $item) {
            if (is_array($item) && ($item['action'] ?? null) === 'sms') {
                $smsEvents++;
            }
        }

        return round(max(0, $contacts) * ($primaryRate + ($smsEvents * $smsRate)), 4);
    }

    private static function assertPricingColumns(): void
    {
        $required = [
            'mxx_plans' => ['voice_open_rate', 'voice_smart_rate'],
            'tenancy_balance' => ['voice_open_rate', 'voice_smart_rate'],
        ];

        foreach ($required as $table => $columns) {
            foreach ($columns as $column) {
                if (!self::columnExists($table, $column)) {
                    throw new RuntimeException("Migracao de tarifas de voz pendente: {$table}.{$column} nao existe.");
                }
            }
        }
    }

    private static function columnExists(string $table, string $column): bool
    {
        $key = "{$table}.{$column}";
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }

        $row = (new Database())->execute("SHOW COLUMNS FROM {$table} LIKE :column", [
            ':column' => $column,
        ])->fetch();

        return self::$columnCache[$key] = (bool)$row;
    }
}
