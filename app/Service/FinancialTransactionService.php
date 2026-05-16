<?php

namespace App\Service;

use WilliamCosta\DatabaseManager\Database;

class FinancialTransactionService
{
    public const WALLET_ADMIN = 'admin';
    public const WALLET_RESELLER = 'reseller';

    private const LEDGER_TABLE = 'financial_transaction_ledger';

    public static function debit(array $operation, ?callable $withinTransaction = null): array
    {
        $batch = self::debitBatch(
            [$operation],
            $withinTransaction === null
                ? null
                : static function (Database $db, array $operations) use ($withinTransaction): void {
                    $withinTransaction($db, $operations[0] ?? []);
                }
        );

        return $batch['operations'][0] ?? self::failureResult('unknown');
    }

    public static function debitBatch(array $operations, ?callable $withinTransaction = null): array
    {
        self::ensureSchema();

        if (empty($operations)) {
            throw new \InvalidArgumentException('Nenhuma operacao financeira informada para debito.');
        }

        $normalizedOperations = [];
        foreach (array_values($operations) as $index => $operation) {
            $normalizedOperations[$index] = self::normalizeOperation($operation);
        }

        $db = new Database();
        $transactionStarted = false;

        try {
            $db->beginTransaction();
            $transactionStarted = true;

            $results = [];
            $newOperations = [];

            foreach ($normalizedOperations as $index => $normalized) {
                $existing = self::findOperationForUpdate($db, $normalized['operation_key']);
                if ($existing) {
                    $results[$index] = self::resultFromExisting($existing);
                    continue;
                }

                $db->run(
                    "INSERT INTO " . self::LEDGER_TABLE . " (
                        operation_key,
                        tenancy_id,
                        user_id,
                        wallet,
                        direction,
                        source,
                        description,
                        provider_reference,
                        related_type,
                        related_id,
                        amount,
                        status,
                        metadata_json,
                        created_at,
                        updated_at
                    ) VALUES (
                        :operation_key,
                        :tenancy_id,
                        :user_id,
                        :wallet,
                        'debit',
                        :source,
                        :description,
                        :provider_reference,
                        :related_type,
                        :related_id,
                        :amount,
                        'pending',
                        :metadata_json,
                        NOW(),
                        NOW()
                    )",
                    [
                        ':operation_key' => $normalized['operation_key'],
                        ':tenancy_id' => $normalized['tenancy_id'],
                        ':user_id' => $normalized['user_id'],
                        ':wallet' => $normalized['wallet'],
                        ':source' => $normalized['source'],
                        ':description' => $normalized['description'],
                        ':provider_reference' => $normalized['provider_reference'],
                        ':related_type' => $normalized['related_type'],
                        ':related_id' => $normalized['related_id'],
                        ':amount' => $normalized['amount'],
                        ':metadata_json' => $normalized['metadata_json'],
                    ]
                );

                $ledgerId = (int)$db->run('SELECT LAST_INSERT_ID()')->fetchColumn();
                $normalized['ledger_id'] = $ledgerId;
                $newOperations[$index] = $normalized;
            }

            if (!empty($newOperations)) {
                $walletStates = [];
                $operationWalletMap = [];

                foreach ($newOperations as $index => $operation) {
                    $walletKey = self::walletKey($operation);
                    $operationWalletMap[$index] = $walletKey;

                    if (!isset($walletStates[$walletKey])) {
                        $balanceRow = self::lockWalletRow($db, $operation);
                        if (!$balanceRow) {
                            self::markRejectedOperations(
                                $db,
                                $newOperations,
                                $operationWalletMap,
                                $walletKey,
                                'rejected_wallet_not_found',
                                null,
                                null
                            );
                            $db->commit();
                            return self::batchFailureResult($results, $newOperations, $walletKey, 'wallet_not_found');
                        }

                        $walletStates[$walletKey] = [
                            'row' => $balanceRow,
                            'operation' => $operation,
                            'balance_before' => round((float)($balanceRow['balance'] ?? 0), 4),
                            'total_amount' => 0.0,
                        ];
                    }

                    $walletStates[$walletKey]['total_amount'] = round(
                        $walletStates[$walletKey]['total_amount'] + $operation['amount'],
                        4
                    );
                }

                foreach ($walletStates as $walletKey => $walletState) {
                    $balanceBefore = $walletState['balance_before'];
                    $totalAmount = $walletState['total_amount'];

                    if ($balanceBefore < $totalAmount) {
                        self::markRejectedOperations(
                            $db,
                            $newOperations,
                            $operationWalletMap,
                            $walletKey,
                            'rejected_insufficient_balance',
                            $balanceBefore,
                            $balanceBefore
                        );
                        $db->commit();
                        return self::batchFailureResult($results, $newOperations, $walletKey, 'insufficient_balance', $balanceBefore);
                    }
                }

                foreach ($walletStates as $walletKey => &$walletState) {
                    $updated = self::applyAggregatedDebitUpdate(
                        $db,
                        $walletState['operation'],
                        $walletState['row'],
                        $walletState['total_amount']
                    );

                    if ($updated !== 1) {
                        $balanceBefore = $walletState['balance_before'];
                        self::markRejectedOperations(
                            $db,
                            $newOperations,
                            $operationWalletMap,
                            $walletKey,
                            'rejected_concurrent_update',
                            $balanceBefore,
                            $balanceBefore
                        );
                        $db->commit();
                        return self::batchFailureResult($results, $newOperations, $walletKey, 'concurrent_update', $balanceBefore);
                    }

                    $walletState['running_balance'] = $walletState['balance_before'];
                }
                unset($walletState);

                $transactionPayload = [];

                foreach ($newOperations as $index => $operation) {
                    $walletKey = $operationWalletMap[$index];
                    $balanceBefore = $walletStates[$walletKey]['running_balance'];
                    $balanceAfter = round($balanceBefore - $operation['amount'], 4);
                    $walletStates[$walletKey]['running_balance'] = $balanceAfter;

                    if ($operation['legacy_log_enabled']) {
                        $db->run(
                            "INSERT INTO tenancy_balance_logs (user_id, tenancy_id, amount, description, created_at)
                             VALUES (:user_id, :tenancy_id, :amount, :description, NOW())",
                            [
                                ':user_id' => $operation['user_id'],
                                ':tenancy_id' => $operation['tenancy_id'],
                                ':amount' => $operation['legacy_log_amount'],
                                ':description' => $operation['description'],
                            ]
                        );
                    }

                    $db->run(
                        "UPDATE " . self::LEDGER_TABLE . "
                         SET status = 'committed',
                             balance_before = :balance_before,
                             balance_after = :balance_after,
                             processed_at = NOW(),
                             updated_at = NOW()
                         WHERE id = :id",
                        [
                            ':id' => $operation['ledger_id'],
                            ':balance_before' => $balanceBefore,
                            ':balance_after' => $balanceAfter,
                        ]
                    );

                    $results[$index] = [
                        'ok' => true,
                        'already_applied' => false,
                        'status' => 'committed',
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceAfter,
                        'ledger_id' => $operation['ledger_id'],
                        'operation_key' => $operation['operation_key'],
                    ];

                    $transactionPayload[] = [
                        'ledger_id' => $operation['ledger_id'],
                        'operation_key' => $operation['operation_key'],
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceAfter,
                        'amount' => $operation['amount'],
                        'wallet' => $operation['wallet'],
                        'user_id' => $operation['user_id'],
                        'tenancy_id' => $operation['tenancy_id'],
                        'source' => $operation['source'],
                    ];
                }

                if ($withinTransaction !== null) {
                    $withinTransaction($db, $transactionPayload);
                }
            }

            ksort($results);
            $db->commit();

            foreach ($newOperations as $index => $operation) {
                $result = $results[$index] ?? null;
                if ($result && !empty($result['ok'])) {
                    self::dispatchAsteriskBalanceSync(
                        $operation,
                        (int)$result['ledger_id'],
                        (float)$result['balance_after']
                    );
                }
            }

            return [
                'ok' => self::allResultsOk($results),
                'operations' => array_values($results),
            ];
        } catch (\Throwable $e) {
            if ($transactionStarted && $db->inTransaction()) {
                $db->rollBack();
            }

            $raceResults = [];
            $allCommitted = true;
            foreach ($normalizedOperations as $index => $normalized) {
                $raceResult = self::findExistingCommittedOperation($normalized['operation_key']);
                if ($raceResult) {
                    $raceResults[$index] = $raceResult;
                    continue;
                }
                $allCommitted = false;
            }

            if ($allCommitted && !empty($raceResults)) {
                ksort($raceResults);
                return [
                    'ok' => true,
                    'operations' => array_values($raceResults),
                ];
            }

            error_log(json_encode([
                'event' => 'financial_transaction_batch_debit_failed',
                'operation_keys' => array_values(array_map(
                    static fn(array $item): string => (string)($item['operation_key'] ?? ''),
                    $normalizedOperations
                )),
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            throw $e;
        }
    }

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $db = new Database();
        $db->run(
            "CREATE TABLE IF NOT EXISTS " . self::LEDGER_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                operation_key VARCHAR(191) NOT NULL,
                tenancy_id VARCHAR(64) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                wallet VARCHAR(32) NOT NULL,
                direction VARCHAR(16) NOT NULL,
                source VARCHAR(64) NOT NULL,
                description VARCHAR(255) NOT NULL,
                provider_reference VARCHAR(191) NULL,
                related_type VARCHAR(64) NULL,
                related_id VARCHAR(191) NULL,
                amount DECIMAL(14,4) NOT NULL,
                balance_before DECIMAL(14,4) NULL,
                balance_after DECIMAL(14,4) NULL,
                status VARCHAR(64) NOT NULL,
                metadata_json LONGTEXT NULL,
                processed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY unq_financial_operation_key (operation_key),
                KEY idx_financial_ledger_tenant_user (tenancy_id, user_id),
                KEY idx_financial_ledger_source_status (source, status),
                KEY idx_financial_ledger_related (related_type, related_id),
                KEY idx_financial_ledger_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function normalizeOperation(array $operation): array
    {
        $amount = round(max(0, (float)($operation['amount'] ?? 0)), 4);
        $operationKey = trim((string)($operation['operation_key'] ?? ''));
        $tenancyId = trim((string)($operation['tenancy_id'] ?? ''));
        $wallet = strtolower(trim((string)($operation['wallet'] ?? self::WALLET_ADMIN)));
        $userId = (int)($operation['user_id'] ?? 0);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Valor financeiro invalido para debito.');
        }
        if ($operationKey === '') {
            throw new \InvalidArgumentException('operation_key obrigatoria para debito financeiro.');
        }
        if ($tenancyId === '' || $userId <= 0) {
            throw new \InvalidArgumentException('Contexto financeiro invalido para debito.');
        }
        if (!in_array($wallet, [self::WALLET_ADMIN, self::WALLET_RESELLER], true)) {
            throw new \InvalidArgumentException('Carteira financeira invalida.');
        }

        $metadata = $operation['metadata'] ?? null;
        if (is_array($metadata) || is_object($metadata)) {
            $metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($metadata !== null) {
            $metadata = (string)$metadata;
        }

        $legacyLogEnabled = array_key_exists('legacy_log_amount', $operation);

        return [
            'operation_key' => $operationKey,
            'tenancy_id' => $tenancyId,
            'user_id' => $userId,
            'wallet' => $wallet,
            'amount' => $amount,
            'source' => trim((string)($operation['source'] ?? 'billing')),
            'description' => trim((string)($operation['description'] ?? 'FINANCIAL_DEBIT')),
            'provider_reference' => self::nullableString($operation['provider_reference'] ?? null),
            'related_type' => self::nullableString($operation['related_type'] ?? null),
            'related_id' => self::nullableString($operation['related_id'] ?? null),
            'metadata_json' => $metadata,
            'legacy_log_enabled' => $legacyLogEnabled,
            'legacy_log_amount' => $legacyLogEnabled ? round((float)$operation['legacy_log_amount'], 4) : 0.0,
        ];
    }

    private static function lockWalletRow(Database $db, array $operation): ?array
    {
        if ($operation['wallet'] === self::WALLET_RESELLER) {
            $row = $db->run(
                "SELECT id, reseller_balance AS balance
                 FROM users
                 WHERE id = :user_id
                   AND tenancy_id = :tenancy_id
                 LIMIT 1
                 FOR UPDATE",
                [
                    ':user_id' => $operation['user_id'],
                    ':tenancy_id' => $operation['tenancy_id'],
                ]
            )->fetch(\PDO::FETCH_ASSOC);

            return $row ?: null;
        }

        $row = $db->run(
            "SELECT id, plan_id, balance
             FROM tenancy_balance
             WHERE user_id = :user_id
               AND tenancy_id = :tenancy_id
             ORDER BY updated_at DESC, id DESC
             LIMIT 1
             FOR UPDATE",
            [
                ':user_id' => $operation['user_id'],
                ':tenancy_id' => $operation['tenancy_id'],
            ]
        )->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function applyDebitUpdate(Database $db, array $operation, array $balanceRow): int
    {
        return self::applyAggregatedDebitUpdate($db, $operation, $balanceRow, (float)$operation['amount']);
    }

    private static function applyAggregatedDebitUpdate(Database $db, array $operation, array $balanceRow, float $amount): int
    {
        if ($operation['wallet'] === self::WALLET_RESELLER) {
            return $db->run(
                "UPDATE users
                 SET reseller_balance = reseller_balance - :amount
                 WHERE id = :id
                   AND tenancy_id = :tenancy_id
                   AND reseller_balance >= :amount",
                [
                    ':amount' => $amount,
                    ':id' => (int)$balanceRow['id'],
                    ':tenancy_id' => $operation['tenancy_id'],
                ]
            )->rowCount();
        }

        return $db->run(
            "UPDATE tenancy_balance
             SET balance = balance - :amount,
                 updated_at = NOW()
             WHERE id = :id
               AND user_id = :user_id
               AND tenancy_id = :tenancy_id
               AND balance >= :amount",
            [
                ':amount' => $amount,
                ':id' => (int)$balanceRow['id'],
                ':user_id' => $operation['user_id'],
                ':tenancy_id' => $operation['tenancy_id'],
            ]
        )->rowCount();
    }

    private static function findOperationForUpdate(Database $db, string $operationKey): ?array
    {
        $row = $db->run(
            "SELECT *
             FROM " . self::LEDGER_TABLE . "
             WHERE operation_key = :operation_key
             LIMIT 1
             FOR UPDATE",
            [':operation_key' => $operationKey]
        )->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function findExistingCommittedOperation(string $operationKey): ?array
    {
        try {
            $row = (new Database())->run(
                "SELECT *
                 FROM " . self::LEDGER_TABLE . "
                 WHERE operation_key = :operation_key
                 LIMIT 1",
                [':operation_key' => $operationKey]
            )->fetch(\PDO::FETCH_ASSOC);

            return $row ? self::resultFromExisting($row) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function resultFromExisting(array $row): array
    {
        $status = (string)($row['status'] ?? 'unknown');
        $ok = $status === 'committed';

        return [
            'ok' => $ok,
            'already_applied' => $ok,
            'status' => $status,
            'balance_before' => isset($row['balance_before']) ? (float)$row['balance_before'] : null,
            'balance_after' => isset($row['balance_after']) ? (float)$row['balance_after'] : null,
            'ledger_id' => isset($row['id']) ? (int)$row['id'] : null,
            'operation_key' => (string)($row['operation_key'] ?? ''),
        ];
    }

    private static function markLedgerRejected(Database $db, int $ledgerId, string $status, ?float $before, ?float $after): void
    {
        $db->run(
            "UPDATE " . self::LEDGER_TABLE . "
             SET status = :status,
                 balance_before = :balance_before,
                 balance_after = :balance_after,
                 processed_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id",
            [
                ':id' => $ledgerId,
                ':status' => $status,
                ':balance_before' => $before,
                ':balance_after' => $after,
            ]
        );
    }

    private static function failureResult(string $status, ?float $balanceBefore = null): array
    {
        return [
            'ok' => false,
            'already_applied' => false,
            'status' => $status,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceBefore,
            'ledger_id' => null,
            'operation_key' => null,
        ];
    }

    private static function batchFailureResult(
        array $existingResults,
        array $newOperations,
        string $targetWalletKey,
        string $status,
        ?float $balanceBefore = null
    ): array {
        $results = $existingResults;

        foreach ($newOperations as $index => $operation) {
            if (self::walletKey($operation) === $targetWalletKey) {
                $results[$index] = self::failureResult($status, $balanceBefore);
                $results[$index]['operation_key'] = $operation['operation_key'];
                continue;
            }

            $results[$index] = self::failureResult('rolled_back_batch');
            $results[$index]['operation_key'] = $operation['operation_key'];
        }

        ksort($results);

        return [
            'ok' => false,
            'operations' => array_values($results),
        ];
    }

    private static function markRejectedOperations(
        Database $db,
        array $newOperations,
        array $operationWalletMap,
        string $targetWalletKey,
        string $status,
        ?float $before,
        ?float $after
    ): void {
        foreach ($newOperations as $index => $operation) {
            if (($operationWalletMap[$index] ?? null) !== $targetWalletKey) {
                self::markLedgerRejected($db, (int)$operation['ledger_id'], 'rolled_back_batch', null, null);
                continue;
            }

            self::markLedgerRejected($db, (int)$operation['ledger_id'], $status, $before, $after);
        }
    }

    private static function walletKey(array $operation): string
    {
        return implode(':', [
            (string)$operation['tenancy_id'],
            (string)$operation['wallet'],
            (string)$operation['user_id'],
        ]);
    }

    private static function allResultsOk(array $results): bool
    {
        foreach ($results as $result) {
            if (empty($result['ok'])) {
                return false;
            }
        }

        return !empty($results);
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private static function dispatchAsteriskBalanceSync(array $normalized, int $ledgerId, float $balanceAfter): void
    {
        try {
            AsteriskBalanceSyncService::enqueueAndProcess([
                'sync_key' => 'ledger:' . $ledgerId,
                'ledger_id' => $ledgerId,
                'tenancy_id' => $normalized['tenancy_id'],
                'user_id' => $normalized['user_id'],
                'wallet' => $normalized['wallet'],
                'source' => $normalized['source'],
                'amount' => $normalized['amount'],
                'metadata' => [
                    'operation_key' => $normalized['operation_key'],
                    'direction' => 'debit',
                    'balance_after' => $balanceAfter,
                ],
            ]);
        } catch (\Throwable $e) {
            error_log(json_encode([
                'event' => 'financial_transaction_asterisk_sync_dispatch_failed',
                'ledger_id' => $ledgerId,
                'operation_key' => $normalized['operation_key'] ?? null,
                'source' => $normalized['source'] ?? null,
                'tenancy_id' => $normalized['tenancy_id'] ?? null,
                'user_id' => $normalized['user_id'] ?? null,
                'wallet' => $normalized['wallet'] ?? null,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}
