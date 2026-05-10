<?php

namespace App\Service;

use WilliamCosta\DatabaseManager\Database;

class WhatsAppCallCdrStore
{
    private const TABLE = 'whatsapp_call_cdr';

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            (new Database())->execute("
                CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    tenancy_id VARCHAR(64) NOT NULL,
                    customer_id INT UNSIGNED NULL,
                    plan_id INT UNSIGNED NULL,
                    account_id INT UNSIGNED NOT NULL,
                    call_id VARCHAR(191) NOT NULL,
                    direction VARCHAR(32) NULL,
                    from_number VARCHAR(32) NULL,
                    to_number VARCHAR(32) NULL,
                    status VARCHAR(64) NULL,
                    started_at DATETIME NULL,
                    answered_at DATETIME NULL,
                    ended_at DATETIME NULL,
                    duration_seconds INT UNSIGNED NULL,
                    billable_seconds INT UNSIGNED NULL,
                    billable_minutes DECIMAL(12,4) NULL,
                    country_code VARCHAR(8) NULL,
                    cost_usd DECIMAL(12,6) NULL,
                    exchange_rate DECIMAL(12,6) NULL,
                    effective_rate DECIMAL(12,6) NULL,
                    cost_brl DECIMAL(12,4) NULL,
                    margin_percent DECIMAL(8,4) NULL,
                    price_brl DECIMAL(12,4) NULL,
                    final_price_brl DECIMAL(12,4) NULL,
                    pulse_seconds INT UNSIGNED NULL,
                    price_per_minute DECIMAL(12,4) NULL,
                    base_cost DECIMAL(12,4) NULL,
                    markup_percent DECIMAL(8,4) NULL,
                    final_price DECIMAL(12,4) NULL,
                    pricing_payload LONGTEXT NULL,
                    raw_payload LONGTEXT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY unq_whatsapp_call_cdr_call_id (call_id),
                    KEY idx_whatsapp_call_cdr_tenancy (tenancy_id),
                    KEY idx_whatsapp_call_cdr_account (account_id),
                    KEY idx_whatsapp_call_cdr_customer (customer_id),
                    KEY idx_whatsapp_call_cdr_started (started_at),
                    KEY idx_whatsapp_call_cdr_status (status),
                    KEY idx_whatsapp_call_cdr_direction (direction)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            self::ensureColumn('customer_id', "ADD COLUMN customer_id INT UNSIGNED NULL AFTER tenancy_id");
            self::ensureColumn('plan_id', "ADD COLUMN plan_id INT UNSIGNED NULL AFTER customer_id");
            self::ensureColumn('from_number', "ADD COLUMN from_number VARCHAR(32) NULL AFTER direction");
            self::ensureColumn('to_number', "ADD COLUMN to_number VARCHAR(32) NULL AFTER from_number");
            self::ensureColumn('status', "ADD COLUMN status VARCHAR(64) NULL AFTER to_number");
            self::ensureColumn('answered_at', "ADD COLUMN answered_at DATETIME NULL AFTER started_at");
            self::ensureColumn('billable_seconds', "ADD COLUMN billable_seconds INT UNSIGNED NULL AFTER duration_seconds");
            self::ensureColumn('billable_minutes', "ADD COLUMN billable_minutes DECIMAL(12,4) NULL AFTER billable_seconds");
            self::ensureColumn('country_code', "ADD COLUMN country_code VARCHAR(8) NULL AFTER billable_minutes");
            self::ensureColumn('cost_usd', "ADD COLUMN cost_usd DECIMAL(12,6) NULL AFTER country_code");
            self::ensureColumn('exchange_rate', "ADD COLUMN exchange_rate DECIMAL(12,6) NULL AFTER cost_usd");
            self::ensureColumn('effective_rate', "ADD COLUMN effective_rate DECIMAL(12,6) NULL AFTER exchange_rate");
            self::ensureColumn('cost_brl', "ADD COLUMN cost_brl DECIMAL(12,4) NULL AFTER effective_rate");
            self::ensureColumn('margin_percent', "ADD COLUMN margin_percent DECIMAL(8,4) NULL AFTER cost_brl");
            self::ensureColumn('price_brl', "ADD COLUMN price_brl DECIMAL(12,4) NULL AFTER margin_percent");
            self::ensureColumn('final_price_brl', "ADD COLUMN final_price_brl DECIMAL(12,4) NULL AFTER price_brl");
            self::ensureColumn('pulse_seconds', "ADD COLUMN pulse_seconds INT UNSIGNED NULL AFTER billable_minutes");
            self::ensureColumn('price_per_minute', "ADD COLUMN price_per_minute DECIMAL(12,4) NULL AFTER pulse_seconds");
            self::ensureColumn('base_cost', "ADD COLUMN base_cost DECIMAL(12,4) NULL AFTER price_per_minute");
            self::ensureColumn('markup_percent', "ADD COLUMN markup_percent DECIMAL(8,4) NULL AFTER base_cost");
            self::ensureColumn('final_price', "ADD COLUMN final_price DECIMAL(12,4) NULL AFTER markup_percent");
            self::ensureColumn('pricing_payload', "ADD COLUMN pricing_payload LONGTEXT NULL AFTER final_price");
            self::ensureColumn('raw_payload', "ADD COLUMN raw_payload LONGTEXT NULL AFTER final_price");
            self::ensureColumn('charge_lock_token', "ADD COLUMN charge_lock_token VARCHAR(64) NULL AFTER raw_payload");
            self::ensureColumn('balance_debited_at', "ADD COLUMN balance_debited_at DATETIME NULL AFTER charge_lock_token");
            self::ensureColumn('updated_at', "ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at");
        } catch (\Throwable $e) {
            error_log('[whatsapp_call_cdr_schema] ' . $e->getMessage());
        }
    }

    public static function upsert(array $payload): void
    {
        self::ensureSchema();

        $callId = trim((string)($payload['call_id'] ?? ''));
        $tenancyId = trim((string)($payload['tenancy_id'] ?? ''));
        $accountId = (int)($payload['account_id'] ?? 0);

        if ($callId === '' || $tenancyId === '' || $accountId <= 0) {
            return;
        }

        $existing = self::findByCallId($callId);
        $now = date('Y-m-d H:i:s');

        $row = [
            'tenancy_id' => $tenancyId,
            'customer_id' => self::nullableInt($payload['customer_id'] ?? ($existing['customer_id'] ?? null)),
            'plan_id' => self::nullableInt($payload['plan_id'] ?? ($existing['plan_id'] ?? null)),
            'account_id' => $accountId,
            'call_id' => $callId,
            'direction' => self::nullableString($payload['direction'] ?? ($existing['direction'] ?? null)),
            'from_number' => self::normalizePhone($payload['from_number'] ?? ($existing['from_number'] ?? null)),
            'to_number' => self::normalizePhone($payload['to_number'] ?? ($existing['to_number'] ?? null)),
            'status' => self::nullableString($payload['status'] ?? ($existing['status'] ?? null)),
            'started_at' => self::nullableDateTime($payload['started_at'] ?? ($existing['started_at'] ?? null)),
            'answered_at' => self::nullableDateTime($payload['answered_at'] ?? ($existing['answered_at'] ?? null)),
            'ended_at' => self::nullableDateTime($payload['ended_at'] ?? ($existing['ended_at'] ?? null)),
            'duration_seconds' => self::nullableInt($payload['duration_seconds'] ?? ($existing['duration_seconds'] ?? null)),
            'billable_seconds' => self::nullableInt($payload['billable_seconds'] ?? ($existing['billable_seconds'] ?? null)),
            'billable_minutes' => self::nullableDecimal($payload['billable_minutes'] ?? ($existing['billable_minutes'] ?? null)),
            'country_code' => self::nullableString($payload['country_code'] ?? ($existing['country_code'] ?? null)),
            'cost_usd' => self::nullableDecimalPrecise($payload['cost_usd'] ?? ($existing['cost_usd'] ?? null), 6),
            'exchange_rate' => self::nullableDecimalPrecise($payload['exchange_rate'] ?? ($existing['exchange_rate'] ?? null), 6),
            'effective_rate' => self::nullableDecimalPrecise($payload['effective_rate'] ?? ($existing['effective_rate'] ?? null), 6),
            'cost_brl' => self::nullableDecimal($payload['cost_brl'] ?? ($existing['cost_brl'] ?? null)),
            'margin_percent' => self::nullableDecimal($payload['margin_percent'] ?? ($existing['margin_percent'] ?? null)),
            'price_brl' => self::nullableDecimal($payload['price_brl'] ?? ($existing['price_brl'] ?? null)),
            'final_price_brl' => self::nullableDecimal($payload['final_price_brl'] ?? ($existing['final_price_brl'] ?? null)),
            'pulse_seconds' => self::nullableInt($payload['pulse_seconds'] ?? ($existing['pulse_seconds'] ?? null)),
            'price_per_minute' => self::nullableDecimal($payload['price_per_minute'] ?? ($existing['price_per_minute'] ?? null)),
            'base_cost' => self::nullableDecimal($payload['base_cost'] ?? ($existing['base_cost'] ?? null)),
            'markup_percent' => self::nullableDecimal($payload['markup_percent'] ?? ($existing['markup_percent'] ?? null)),
            'final_price' => self::nullableDecimal($payload['final_price'] ?? ($existing['final_price'] ?? null)),
            'pricing_payload' => self::jsonOrNull($payload['pricing_payload'] ?? ($existing['pricing_payload'] ?? null)),
            'raw_payload' => self::jsonOrNull($payload['raw_payload'] ?? ($existing['raw_payload'] ?? null)),
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
        ];

        (new Database())->execute(
            "INSERT INTO " . self::TABLE . " (
                tenancy_id, customer_id, plan_id, account_id, call_id, direction, from_number, to_number, status,
                started_at, answered_at, ended_at, duration_seconds, billable_seconds, billable_minutes,
                country_code, cost_usd, exchange_rate, effective_rate, cost_brl, margin_percent, price_brl, final_price_brl,
                pulse_seconds, price_per_minute, base_cost, markup_percent, final_price, pricing_payload, raw_payload, created_at, updated_at
            ) VALUES (
                :tenancy_id, :customer_id, :plan_id, :account_id, :call_id, :direction, :from_number, :to_number, :status,
                :started_at, :answered_at, :ended_at, :duration_seconds, :billable_seconds, :billable_minutes,
                :country_code, :cost_usd, :exchange_rate, :effective_rate, :cost_brl, :margin_percent, :price_brl, :final_price_brl,
                :pulse_seconds, :price_per_minute, :base_cost, :markup_percent, :final_price, :pricing_payload, :raw_payload, :created_at, :updated_at
            )
            ON DUPLICATE KEY UPDATE
                customer_id = VALUES(customer_id),
                plan_id = VALUES(plan_id),
                account_id = VALUES(account_id),
                direction = VALUES(direction),
                from_number = VALUES(from_number),
                to_number = VALUES(to_number),
                status = VALUES(status),
                started_at = COALESCE(VALUES(started_at), started_at),
                answered_at = COALESCE(VALUES(answered_at), answered_at),
                ended_at = COALESCE(VALUES(ended_at), ended_at),
                duration_seconds = COALESCE(VALUES(duration_seconds), duration_seconds),
                billable_seconds = COALESCE(VALUES(billable_seconds), billable_seconds),
                billable_minutes = COALESCE(VALUES(billable_minutes), billable_minutes),
                country_code = COALESCE(VALUES(country_code), country_code),
                cost_usd = COALESCE(VALUES(cost_usd), cost_usd),
                exchange_rate = COALESCE(VALUES(exchange_rate), exchange_rate),
                effective_rate = COALESCE(VALUES(effective_rate), effective_rate),
                cost_brl = COALESCE(VALUES(cost_brl), cost_brl),
                margin_percent = COALESCE(VALUES(margin_percent), margin_percent),
                price_brl = COALESCE(VALUES(price_brl), price_brl),
                final_price_brl = COALESCE(VALUES(final_price_brl), final_price_brl),
                pulse_seconds = COALESCE(VALUES(pulse_seconds), pulse_seconds),
                price_per_minute = COALESCE(VALUES(price_per_minute), price_per_minute),
                base_cost = COALESCE(VALUES(base_cost), base_cost),
                markup_percent = COALESCE(VALUES(markup_percent), markup_percent),
                final_price = COALESCE(VALUES(final_price), final_price),
                pricing_payload = COALESCE(VALUES(pricing_payload), pricing_payload),
                raw_payload = COALESCE(VALUES(raw_payload), raw_payload),
                updated_at = VALUES(updated_at)",
            [
                ':tenancy_id' => $row['tenancy_id'],
                ':customer_id' => $row['customer_id'],
                ':plan_id' => $row['plan_id'],
                ':account_id' => $row['account_id'],
                ':call_id' => $row['call_id'],
                ':direction' => $row['direction'],
                ':from_number' => $row['from_number'],
                ':to_number' => $row['to_number'],
                ':status' => $row['status'],
                ':started_at' => $row['started_at'],
                ':answered_at' => $row['answered_at'],
                ':ended_at' => $row['ended_at'],
                ':duration_seconds' => $row['duration_seconds'],
                ':billable_seconds' => $row['billable_seconds'],
                ':billable_minutes' => $row['billable_minutes'],
                ':country_code' => $row['country_code'],
                ':cost_usd' => $row['cost_usd'],
                ':exchange_rate' => $row['exchange_rate'],
                ':effective_rate' => $row['effective_rate'],
                ':cost_brl' => $row['cost_brl'],
                ':margin_percent' => $row['margin_percent'],
                ':price_brl' => $row['price_brl'],
                ':final_price_brl' => $row['final_price_brl'],
                ':pulse_seconds' => $row['pulse_seconds'],
                ':price_per_minute' => $row['price_per_minute'],
                ':base_cost' => $row['base_cost'],
                ':markup_percent' => $row['markup_percent'],
                ':final_price' => $row['final_price'],
                ':pricing_payload' => $row['pricing_payload'],
                ':raw_payload' => $row['raw_payload'],
                ':created_at' => $row['created_at'],
                ':updated_at' => $row['updated_at'],
            ]
        );
    }

    public static function findByCallId(string $callId): ?array
    {
        self::ensureSchema();

        $row = (new Database())->execute(
            'SELECT * FROM ' . self::TABLE . ' WHERE call_id = :call_id LIMIT 1',
            [':call_id' => trim($callId)]
        )->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function tryAcquireChargeLock(string $callId, string $token): bool
    {
        self::ensureSchema();

        $callId = trim($callId);
        $token = trim($token);
        if ($callId === '' || $token === '') {
            return false;
        }

        try {
            $result = (new Database())->execute(
                "UPDATE " . self::TABLE . "
                 SET charge_lock_token = :token,
                     updated_at = NOW()
                 WHERE call_id = :call_id
                   AND COALESCE(final_price, 0) > 0
                   AND balance_debited_at IS NULL
                   AND (charge_lock_token IS NULL OR charge_lock_token = '')",
                [
                    ':token' => $token,
                    ':call_id' => $callId,
                ]
            );

            return $result->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('[whatsapp_call_cdr_lock] ' . $e->getMessage());
            return false;
        }
    }

    public static function markBalanceDebited(string $callId, string $token): void
    {
        self::ensureSchema();

        try {
            (new Database())->execute(
                "UPDATE " . self::TABLE . "
                 SET balance_debited_at = NOW(),
                     updated_at = NOW()
                 WHERE call_id = :call_id
                   AND charge_lock_token = :token",
                [
                    ':call_id' => trim($callId),
                    ':token' => trim($token),
                ]
            );
        } catch (\Throwable $e) {
            error_log('[whatsapp_call_cdr_mark_debited] ' . $e->getMessage());
        }
    }

    public static function releaseChargeLock(string $callId, string $token): void
    {
        self::ensureSchema();

        try {
            (new Database())->execute(
                "UPDATE " . self::TABLE . "
                 SET charge_lock_token = NULL,
                     updated_at = NOW()
                 WHERE call_id = :call_id
                   AND charge_lock_token = :token
                   AND balance_debited_at IS NULL",
                [
                    ':call_id' => trim($callId),
                    ':token' => trim($token),
                ]
            );
        } catch (\Throwable $e) {
            error_log('[whatsapp_call_cdr_release_lock] ' . $e->getMessage());
        }
    }

    private static function ensureColumn(string $column, string $definition): void
    {
        try {
            $exists = (new Database())->execute(
                'SELECT 1
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table
                   AND COLUMN_NAME = :column
                 LIMIT 1',
                [
                    ':table' => self::TABLE,
                    ':column' => $column,
                ]
            )->fetchColumn();

            if (!$exists) {
                (new Database())->execute('ALTER TABLE ' . self::TABLE . ' ' . $definition);
            }
        } catch (\Throwable $e) {
            error_log('[whatsapp_call_cdr_column] ' . $column . ' ' . $e->getMessage());
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value !== '' ? $value : null;
    }

    private static function normalizePhone(mixed $value): ?string
    {
        $normalized = preg_replace('/\D+/', '', (string)($value ?? '')) ?: '';
        return $normalized !== '' ? $normalized : null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int)$value);
    }

    private static function nullableDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float)$value, 4);
    }

    private static function nullableDecimalPrecise(mixed $value, int $precision): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float)$value, max(0, $precision));
    }

    private static function nullableDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int)$value);
        }

        try {
            return (new \DateTime((string)$value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function jsonOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
