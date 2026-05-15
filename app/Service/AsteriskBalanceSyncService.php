<?php

namespace App\Service;

use App\Controller\Pages\AsteriskExtensionsSip;
use WilliamCosta\DatabaseManager\Database;

class AsteriskBalanceSyncService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SYNCED = 'synced';

    private const QUEUE_TABLE = 'asterisk_balance_sync_queue';
    private const LOG_TABLE = 'asterisk_balance_sync_logs';

    /** @var callable|null */
    private static $dispatcher = null;

    public static function setDispatcher(?callable $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
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
            "CREATE TABLE IF NOT EXISTS " . self::QUEUE_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                sync_key VARCHAR(191) NOT NULL,
                ledger_id BIGINT UNSIGNED NULL,
                tenancy_id VARCHAR(64) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                wallet VARCHAR(32) NOT NULL,
                source VARCHAR(64) NOT NULL,
                amount DECIMAL(14,4) NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                balance_snapshot DECIMAL(14,4) NULL,
                metadata_json LONGTEXT NULL,
                last_error TEXT NULL,
                last_attempt_at DATETIME NULL,
                next_retry_at DATETIME NULL,
                synced_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY unq_asterisk_balance_sync_key (sync_key),
                KEY idx_asterisk_balance_sync_pending (status, next_retry_at),
                KEY idx_asterisk_balance_sync_wallet (tenancy_id, user_id, wallet),
                KEY idx_asterisk_balance_sync_ledger (ledger_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->run(
            "CREATE TABLE IF NOT EXISTS " . self::LOG_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                queue_id BIGINT UNSIGNED NOT NULL,
                attempt_no INT UNSIGNED NOT NULL,
                status VARCHAR(32) NOT NULL,
                message TEXT NULL,
                request_json LONGTEXT NULL,
                response_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_asterisk_balance_sync_log_queue (queue_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function enqueueAndProcess(array $payload): array
    {
        $queued = self::enqueue($payload);
        if (empty($queued['queue_id'])) {
            return $queued;
        }

        $processed = self::processQueueItem((int)$queued['queue_id']);
        $queued['processed'] = $processed;

        return $queued;
    }

    public static function enqueue(array $payload): array
    {
        self::ensureSchema();

        $normalized = self::normalizePayload($payload);
        $db = new Database();
        $db->beginTransaction();

        try {
            $existing = $db->run(
                "SELECT id, status
                 FROM " . self::QUEUE_TABLE . "
                 WHERE sync_key = :sync_key
                 LIMIT 1
                 FOR UPDATE",
                [':sync_key' => $normalized['sync_key']]
            )->fetch(\PDO::FETCH_ASSOC);

            if ($existing) {
                $db->commit();
                return [
                    'ok' => true,
                    'already_queued' => true,
                    'queue_id' => (int)$existing['id'],
                    'status' => (string)$existing['status'],
                    'sync_key' => $normalized['sync_key'],
                ];
            }

            $db->run(
                "INSERT INTO " . self::QUEUE_TABLE . " (
                    sync_key,
                    ledger_id,
                    tenancy_id,
                    user_id,
                    wallet,
                    source,
                    amount,
                    status,
                    metadata_json,
                    created_at,
                    updated_at
                ) VALUES (
                    :sync_key,
                    :ledger_id,
                    :tenancy_id,
                    :user_id,
                    :wallet,
                    :source,
                    :amount,
                    'pending',
                    :metadata_json,
                    NOW(),
                    NOW()
                )",
                [
                    ':sync_key' => $normalized['sync_key'],
                    ':ledger_id' => $normalized['ledger_id'],
                    ':tenancy_id' => $normalized['tenancy_id'],
                    ':user_id' => $normalized['user_id'],
                    ':wallet' => $normalized['wallet'],
                    ':source' => $normalized['source'],
                    ':amount' => $normalized['amount'],
                    ':metadata_json' => $normalized['metadata_json'],
                ]
            );

            $queueId = (int)$db->run('SELECT LAST_INSERT_ID()')->fetchColumn();
            $db->commit();

            return [
                'ok' => true,
                'already_queued' => false,
                'queue_id' => $queueId,
                'status' => self::STATUS_PENDING,
                'sync_key' => $normalized['sync_key'],
            ];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log(json_encode([
                'event' => 'asterisk_balance_sync_enqueue_failed',
                'sync_key' => $normalized['sync_key'] ?? null,
                'tenancy_id' => $normalized['tenancy_id'] ?? null,
                'user_id' => $normalized['user_id'] ?? null,
                'wallet' => $normalized['wallet'] ?? null,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return [
                'ok' => false,
                'queue_id' => null,
                'status' => 'enqueue_failed',
                'sync_key' => $normalized['sync_key'] ?? null,
                'error' => $e->getMessage(),
            ];
        }
    }

    public static function processPending(int $limit = 50): array
    {
        self::ensureSchema();

        $limit = max(1, $limit);
        $ids = (new Database())->run(
            "SELECT id
             FROM " . self::QUEUE_TABLE . "
             WHERE status = :status
               AND (next_retry_at IS NULL OR next_retry_at <= NOW())
             ORDER BY created_at ASC, id ASC
             LIMIT " . $limit,
            [':status' => self::STATUS_PENDING]
        )->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        $results = [];
        foreach ($ids as $id) {
            $results[] = self::processQueueItem((int)$id);
        }

        return [
            'ok' => true,
            'processed' => count($results),
            'results' => $results,
        ];
    }

    public static function processQueueItem(int $queueId): array
    {
        self::ensureSchema();

        $db = new Database();
        $db->beginTransaction();

        try {
            $row = $db->run(
                "SELECT *
                 FROM " . self::QUEUE_TABLE . "
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE",
                [':id' => $queueId]
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                $db->commit();
                return [
                    'ok' => false,
                    'queue_id' => $queueId,
                    'status' => 'not_found',
                ];
            }

            if ((string)$row['status'] === self::STATUS_SYNCED) {
                $db->commit();
                return [
                    'ok' => true,
                    'queue_id' => $queueId,
                    'status' => self::STATUS_SYNCED,
                    'already_synced' => true,
                ];
            }

            $attemptNo = ((int)($row['attempts'] ?? 0)) + 1;
            $db->run(
                "UPDATE " . self::QUEUE_TABLE . "
                 SET status = :status,
                     attempts = :attempts,
                     last_attempt_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id",
                [
                    ':id' => $queueId,
                    ':status' => self::STATUS_PROCESSING,
                    ':attempts' => $attemptNo,
                ]
            );
            $db->commit();

            $snapshot = self::readWalletSnapshot(
                (string)$row['tenancy_id'],
                (int)$row['user_id'],
                (string)$row['wallet']
            );

            if (!$snapshot) {
                $message = 'Carteira nao encontrada para sincronizacao com Asterisk.';
                self::markPending($queueId, $attemptNo, $message, null, null);
                return [
                    'ok' => false,
                    'queue_id' => $queueId,
                    'status' => self::STATUS_PENDING,
                    'error' => $message,
                ];
            }

            $request = self::buildRequestPayload(
                (string)$row['tenancy_id'],
                (int)$row['user_id'],
                (string)$row['wallet'],
                $snapshot
            );

            $response = self::dispatch($request['query'], $request['payload']);
            $ok = !empty($response['ok']) && (int)($response['status'] ?? 0) >= 200 && (int)($response['status'] ?? 0) < 300;

            if ($ok) {
                self::markSynced($queueId, $attemptNo, (float)$snapshot['balance'], $request, $response);
                return [
                    'ok' => true,
                    'queue_id' => $queueId,
                    'status' => self::STATUS_SYNCED,
                    'balance' => (float)$snapshot['balance'],
                ];
            }

            $message = self::extractResponseError($response);
            self::markPending($queueId, $attemptNo, $message, $request, $response);

            return [
                'ok' => false,
                'queue_id' => $queueId,
                'status' => self::STATUS_PENDING,
                'error' => $message,
            ];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            self::markPending($queueId, null, $e->getMessage(), null, null);

            return [
                'ok' => false,
                'queue_id' => $queueId,
                'status' => self::STATUS_PENDING,
                'error' => $e->getMessage(),
            ];
        }
    }

    private static function normalizePayload(array $payload): array
    {
        $syncKey = trim((string)($payload['sync_key'] ?? ''));
        $tenancyId = trim((string)($payload['tenancy_id'] ?? ''));
        $wallet = strtolower(trim((string)($payload['wallet'] ?? 'admin')));
        $userId = (int)($payload['user_id'] ?? 0);

        if ($syncKey === '') {
            throw new \InvalidArgumentException('sync_key obrigatoria para a fila de sincronizacao do Asterisk.');
        }
        if ($tenancyId === '' || $userId <= 0) {
            throw new \InvalidArgumentException('Contexto invalido para sincronizacao do Asterisk.');
        }
        if (!in_array($wallet, [
            FinancialTransactionService::WALLET_ADMIN,
            FinancialTransactionService::WALLET_RESELLER,
        ], true)) {
            throw new \InvalidArgumentException('Carteira invalida para sincronizacao do Asterisk.');
        }

        $metadata = $payload['metadata'] ?? null;
        if (is_array($metadata) || is_object($metadata)) {
            $metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($metadata !== null) {
            $metadata = (string)$metadata;
        }

        return [
            'sync_key' => $syncKey,
            'ledger_id' => isset($payload['ledger_id']) ? (int)$payload['ledger_id'] : null,
            'tenancy_id' => $tenancyId,
            'user_id' => $userId,
            'wallet' => $wallet,
            'source' => trim((string)($payload['source'] ?? 'financial_change')),
            'amount' => isset($payload['amount']) ? round((float)$payload['amount'], 4) : null,
            'metadata_json' => $metadata,
        ];
    }

    private static function readWalletSnapshot(string $tenancyId, int $userId, string $wallet): ?array
    {
        $db = new Database();

        if ($wallet === FinancialTransactionService::WALLET_RESELLER) {
            $row = $db->run(
                "SELECT id AS user_id, tenancy_id, reseller_balance AS balance
                 FROM users
                 WHERE id = :id
                   AND tenancy_id = :tenancy_id
                 LIMIT 1",
                [
                    ':id' => $userId,
                    ':tenancy_id' => $tenancyId,
                ]
            )->fetch(\PDO::FETCH_ASSOC);

            return $row ?: null;
        }

        $row = $db->run(
            "SELECT user_id, tenancy_id, balance
             FROM tenancy_balance
             WHERE user_id = :user_id
               AND tenancy_id = :tenancy_id
             ORDER BY updated_at DESC, id DESC
             LIMIT 1",
            [
                ':user_id' => $userId,
                ':tenancy_id' => $tenancyId,
            ]
        )->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function buildRequestPayload(string $tenancyId, int $userId, string $wallet, array $snapshot): array
    {
        $query = [
            'tenant_id' => $tenancyId,
            'user_id' => $userId,
        ];

        $payload = [
            'tenant_id' => $tenancyId,
            'user_id' => $userId,
        ];

        if ($wallet === FinancialTransactionService::WALLET_RESELLER) {
            $payload['balance_reseller'] = round((float)$snapshot['balance'], 4);
        } else {
            $payload['balance_admin'] = round((float)$snapshot['balance'], 4);
        }

        return [
            'query' => $query,
            'payload' => $payload,
        ];
    }

    private static function dispatch(array $query, array $payload): array
    {
        if (is_callable(self::$dispatcher)) {
            return (array)call_user_func(self::$dispatcher, $query, $payload);
        }

        return (new AsteriskExtensionsSip())->updateTariff($query, $payload);
    }

    private static function markSynced(int $queueId, int $attemptNo, float $balance, array $request, array $response): void
    {
        (new Database())->run(
            "UPDATE " . self::QUEUE_TABLE . "
             SET status = :status,
                 balance_snapshot = :balance_snapshot,
                 last_error = NULL,
                 next_retry_at = NULL,
                 synced_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id",
            [
                ':id' => $queueId,
                ':status' => self::STATUS_SYNCED,
                ':balance_snapshot' => $balance,
            ]
        );

        self::insertLog($queueId, $attemptNo, self::STATUS_SYNCED, 'Saldo sincronizado com sucesso no Asterisk.', $request, $response);
    }

    private static function markPending(int $queueId, ?int $attemptNo, string $message, ?array $request, ?array $response): void
    {
        $attempts = $attemptNo ?: (int)((new Database())->run(
            "SELECT attempts FROM " . self::QUEUE_TABLE . " WHERE id = :id LIMIT 1",
            [':id' => $queueId]
        )->fetchColumn() ?: 0);

        $retryAt = date('Y-m-d H:i:s', time() + self::retryDelaySeconds($attempts));

        (new Database())->run(
            "UPDATE " . self::QUEUE_TABLE . "
             SET status = :status,
                 last_error = :last_error,
                 next_retry_at = :next_retry_at,
                 updated_at = NOW()
             WHERE id = :id",
            [
                ':id' => $queueId,
                ':status' => self::STATUS_PENDING,
                ':last_error' => $message,
                ':next_retry_at' => $retryAt,
            ]
        );

        self::insertLog($queueId, $attempts, self::STATUS_PENDING, $message, $request, $response);

        error_log(json_encode([
            'event' => 'asterisk_balance_sync_pending',
            'queue_id' => $queueId,
            'attempts' => $attempts,
            'retry_at' => $retryAt,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function insertLog(int $queueId, int $attemptNo, string $status, ?string $message, ?array $request, ?array $response): void
    {
        (new Database())->run(
            "INSERT INTO " . self::LOG_TABLE . " (
                queue_id,
                attempt_no,
                status,
                message,
                request_json,
                response_json,
                created_at
            ) VALUES (
                :queue_id,
                :attempt_no,
                :status,
                :message,
                :request_json,
                :response_json,
                NOW()
            )",
            [
                ':queue_id' => $queueId,
                ':attempt_no' => $attemptNo,
                ':status' => $status,
                ':message' => $message,
                ':request_json' => $request ? json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ':response_json' => $response ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]
        );
    }

    private static function retryDelaySeconds(int $attempt): int
    {
        return match (true) {
            $attempt <= 1 => 60,
            $attempt === 2 => 300,
            $attempt === 3 => 900,
            default => 3600,
        };
    }

    private static function extractResponseError(array $response): string
    {
        $status = (int)($response['status'] ?? 0);
        $error = trim((string)($response['error'] ?? ''));
        if ($error !== '') {
            return $status > 0 ? "HTTP {$status}: {$error}" : $error;
        }

        return $status > 0
            ? "Falha ao sincronizar saldo com o Asterisk. HTTP {$status}."
            : 'Falha ao sincronizar saldo com o Asterisk.';
    }
}
