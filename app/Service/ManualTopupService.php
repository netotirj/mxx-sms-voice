<?php

namespace App\Service;

use App\Model\Entity\Notifications;
use App\Model\Entity\RefillsResellers;
use App\Model\Entity\UserSearch;
use App\Utils\TenancyHelper;
use WilliamCosta\DatabaseManager\Database;

class ManualTopupService
{
    public const SOURCE_SUPER_ADMIN_ADMIN_TOPUP = 'MANUAL_SUPER_ADMIN_ADMIN_TOPUP';
    public const SOURCE_RESELLER_TOPUP = 'manual_refill';
    private const CSRF_SESSION_KEY = 'manual_topup_csrf';
    private const AUDIT_TABLE = 'manual_balance_topup_audit';

    public static function issueCsrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $token = trim((string)($_SESSION[self::CSRF_SESSION_KEY] ?? ''));
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::CSRF_SESSION_KEY] = $token;
        }

        return $token;
    }

    public static function isValidCsrfToken(?string $providedToken): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $sessionToken = trim((string)($_SESSION[self::CSRF_SESSION_KEY] ?? ''));
        $providedToken = trim((string)$providedToken);

        return $sessionToken !== '' && $providedToken !== '' && hash_equals($sessionToken, $providedToken);
    }

    public static function authorizeTopup(array $actor, array $target): array
    {
        $actorRole = self::normalizeRole($actor['user_function'] ?? $actor['function'] ?? '');
        $targetRole = self::normalizeRole($target['user_function'] ?? '');
        $sameTenancy = trim((string)($actor['tenancy_id'] ?? '')) !== ''
            && trim((string)($actor['tenancy_id'] ?? '')) === trim((string)($target['tenancy_id'] ?? ''));

        if ($actorRole === 'super_admin' && $targetRole === 'admin') {
            return [
                'allowed' => true,
                'flow' => 'super_admin_admin',
                'message' => '',
            ];
        }

        if (in_array($actorRole, ['admin', 'super_admin'], true) && $targetRole === 'reseller') {
            if ($actorRole === 'admin' && !$sameTenancy) {
                return [
                    'allowed' => false,
                    'flow' => null,
                    'message' => 'Administrador só pode recarregar revendedores da própria tenancy.',
                ];
            }

            return [
                'allowed' => true,
                'flow' => 'reseller',
                'message' => '',
            ];
        }

        return [
            'allowed' => false,
            'flow' => null,
            'message' => 'Você não tem permissão para realizar recarga para este perfil.',
        ];
    }

    public static function process(array $actor, array $input, array $server = []): array
    {
        if (!self::isValidCsrfToken($input['csrf_token'] ?? null)) {
            return self::response(419, 'Falha de segurança: token da sessão inválido para recarga.');
        }

        $targetUserId = (int)($input['usuario_id'] ?? 0);
        $value = round((float)($input['valor_recarga'] ?? 0), 2);
        $notes = trim((string)($input['anotacao'] ?? ''));

        if ($targetUserId <= 0) {
            return self::response(422, 'Usuário alvo inválido para recarga.');
        }

        if ($value <= 0) {
            return self::response(422, 'O valor da recarga deve ser maior que zero.');
        }

        $target = self::resolveTargetUser($actor, $targetUserId);
        if (!$target) {
            return self::response(404, 'Usuário alvo não encontrado.');
        }

        $authorization = self::authorizeTopup($actor, $target);
        if (empty($authorization['allowed'])) {
            return self::response(403, (string)($authorization['message'] ?? 'Acesso negado.'));
        }

        return match ($authorization['flow']) {
            'super_admin_admin' => self::processSuperAdminAdminTopup($actor, $target, $value, $notes, $input, $server),
            'reseller' => self::processResellerTopup($actor, $target, $value, $notes, $server),
            default => self::response(403, 'Fluxo de recarga não permitido.'),
        };
    }

    private static function processSuperAdminAdminTopup(
        array $actor,
        array $target,
        float $value,
        string $notes,
        array $input,
        array $server
    ): array {
        $operationKey = trim((string)($input['operation_key'] ?? ''));
        if ($operationKey === '') {
            return self::response(422, 'operation_key obrigatória para recarga manual do administrador.');
        }

        self::ensureAuditSchema();

        $clientIp = self::resolveClientIp($server);
        $userAgent = trim((string)($server['HTTP_USER_AGENT'] ?? ''));
        $targetTenancyId = trim((string)($target['tenancy_id'] ?? ''));
        $targetUserId = (int)($target['id'] ?? 0);
        $targetEmail = trim((string)($target['email'] ?? ''));

        try {
            $result = FinancialTransactionService::credit([
                'operation_key' => $operationKey,
                'tenancy_id' => $targetTenancyId,
                'user_id' => $targetUserId,
                'wallet' => FinancialTransactionService::WALLET_ADMIN,
                'amount' => $value,
                'source' => self::SOURCE_SUPER_ADMIN_ADMIN_TOPUP,
                'description' => 'Recarga manual de saldo para administrador por super administrador',
                'related_type' => 'manual_admin_topup',
                'related_id' => (string)$targetUserId,
                'legacy_log_amount' => $value,
                'metadata' => [
                    'executor_user_id' => (int)($actor['id'] ?? 0),
                    'executor_tenancy_id' => (string)($actor['tenancy_id'] ?? ''),
                    'executor_role' => self::normalizeRole($actor['user_function'] ?? $actor['function'] ?? ''),
                    'target_email' => $targetEmail,
                    'target_role' => self::normalizeRole($target['user_function'] ?? ''),
                    'notes' => $notes,
                    'client_ip' => $clientIp,
                    'user_agent' => $userAgent,
                ],
            ], static function (Database $db, array $operationResult) use (
                $actor,
                $target,
                $targetTenancyId,
                $targetUserId,
                $targetEmail,
                $value,
                $notes,
                $clientIp,
                $userAgent
            ): void {
                self::insertAuditRecord($db, [
                    'operation_key' => (string)($operationResult['operation_key'] ?? ''),
                    'source' => self::SOURCE_SUPER_ADMIN_ADMIN_TOPUP,
                    'actor_user_id' => (int)($actor['id'] ?? 0),
                    'actor_tenancy_id' => (string)($actor['tenancy_id'] ?? ''),
                    'actor_role' => self::normalizeRole($actor['user_function'] ?? $actor['function'] ?? ''),
                    'target_user_id' => $targetUserId,
                    'target_tenancy_id' => $targetTenancyId,
                    'target_role' => self::normalizeRole($target['user_function'] ?? ''),
                    'amount' => $value,
                    'notes' => $notes,
                    'balance_before' => (float)($operationResult['balance_before'] ?? 0),
                    'balance_after' => (float)($operationResult['balance_after'] ?? 0),
                    'client_ip' => $clientIp,
                    'user_agent' => $userAgent,
                    'ledger_id' => (int)($operationResult['ledger_id'] ?? 0),
                ]);

                $refill = new RefillsResellers();
                $refill->user_id = $targetUserId;
                $refill->tenancy_id = $targetTenancyId;
                $refill->email = $targetEmail;
                $refill->balance = (string)$value;
                $refill->notes = $notes;
                $refill->type = 'manual_super_admin_admin_topup';
                $refill->transaction_id = (string)($operationResult['operation_key'] ?? '');
                $refill->client_ip = $clientIp;
                $refill->status = 'completed';
                $refill->created_at = date('Y-m-d H:i:s');
                $refill->updated_at = date('Y-m-d H:i:s');
                $refill->insertRefill();
            });
        } catch (\InvalidArgumentException $e) {
            return self::response(422, $e->getMessage());
        } catch (\Throwable $e) {
            error_log(json_encode([
                'event' => 'manual_super_admin_admin_topup_failed',
                'operation_key' => $operationKey,
                'target_user_id' => $targetUserId,
                'target_tenancy_id' => $targetTenancyId,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::response(500, 'Erro ao processar a recarga manual do administrador.');
        }

        if (empty($result['ok'])) {
            $operation = $result;
            return self::response(409, 'Não foi possível concluir a recarga manual do administrador.', [
                'finance_status' => $operation['status'] ?? 'failed',
            ]);
        }

        if (empty($result['already_applied'])) {
            Notifications::insertNotifications(
                $targetTenancyId,
                $targetUserId,
                'Recarga manual confirmada',
                'Seu saldo administrativo recebeu uma recarga manual de <b>R$ ' . number_format($value, 2, ',', '.') . '</b>.',
                'notice'
            );
        }

        return self::response(200, !empty($result['already_applied'])
            ? 'Recarga já processada anteriormente para esta operação.'
            : 'Recarga manual do administrador concluída com sucesso.', [
            'operation_key' => $result['operation_key'] ?? $operationKey,
            'ledger_id' => $result['ledger_id'] ?? null,
            'saldo_antes' => $result['balance_before'] ?? null,
            'saldo_depois' => $result['balance_after'] ?? null,
            'already_applied' => !empty($result['already_applied']),
        ]);
    }

    private static function processResellerTopup(
        array $actor,
        array $target,
        float $value,
        string $notes,
        array $server
    ): array {
        $transactionId = str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $clientIp = self::resolveClientIp($server);

        $refill = new RefillsResellers();
        $refill->user_id = (int)($target['id'] ?? 0);
        $refill->tenancy_id = (string)($target['tenancy_id'] ?? '');
        $refill->email = (string)($target['email'] ?? '');
        $refill->balance = (string)$value;
        $refill->notes = $notes;
        $refill->type = 'manual';
        $refill->transaction_id = $transactionId;
        $refill->client_ip = $clientIp;
        $refill->status = 'completed';
        $refill->created_at = date('Y-m-d H:i:s');
        $refill->updated_at = date('Y-m-d H:i:s');
        $refill->insertRefill();

        $reseller = new UserSearch();
        $reseller->id = (int)($target['id'] ?? 0);
        $reseller->tenancy_id = (string)($target['tenancy_id'] ?? '');

        $updated = $reseller->updateRefillReseller($value);
        if (!$updated) {
            return self::response(500, 'Erro ao processar atualização de saldo.');
        }

        $syncResult = AsteriskBalanceSyncService::enqueueAndProcess([
            'sync_key' => 'manual_refill:' . $transactionId,
            'tenancy_id' => (string)($target['tenancy_id'] ?? ''),
            'user_id' => (int)($target['id'] ?? 0),
            'wallet' => 'reseller',
            'source' => self::SOURCE_RESELLER_TOPUP,
            'amount' => $value,
            'metadata' => [
                'transaction_id' => $transactionId,
                'notes' => $notes,
                'mutation' => 'credit',
                'actor_user_id' => (int)($actor['id'] ?? 0),
                'actor_role' => self::normalizeRole($actor['user_function'] ?? $actor['function'] ?? ''),
            ],
        ]);

        if (empty($syncResult['ok'])) {
            return self::response(500, 'Erro ao sincronizar saldo do revendedor.');
        }

        Notifications::insertNotifications(
            (string)($target['tenancy_id'] ?? ''),
            (int)($target['id'] ?? 0),
            'Recarga Confirmada',
            'A recarga no valor de <b>R$ ' . number_format($value, 2, ',', '.') . '</b> foi confirmada com sucesso. Seu saldo foi atualizado.',
            'notice'
        );

        return self::response(200, 'Recarga registrada e saldo atualizado com sucesso!', [
            'transactionId' => $transactionId,
            'clientIp' => $clientIp,
        ]);
    }

    private static function resolveTargetUser(array $actor, int $targetUserId): ?array
    {
        if (TenancyHelper::isSuperAdmin($actor)) {
            return UserSearch::getUserByIdGlobal($targetUserId);
        }

        return UserSearch::getUserById((string)($actor['tenancy_id'] ?? ''), $targetUserId);
    }

    private static function ensureAuditSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        (new Database())->execute(
            "CREATE TABLE IF NOT EXISTS " . self::AUDIT_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                operation_key VARCHAR(191) NOT NULL,
                source VARCHAR(64) NOT NULL,
                actor_user_id INT UNSIGNED NOT NULL,
                actor_tenancy_id VARCHAR(64) NULL,
                actor_role VARCHAR(64) NOT NULL,
                target_user_id INT UNSIGNED NOT NULL,
                target_tenancy_id VARCHAR(64) NOT NULL,
                target_role VARCHAR(64) NOT NULL,
                amount DECIMAL(14,4) NOT NULL,
                notes TEXT NULL,
                balance_before DECIMAL(14,4) NULL,
                balance_after DECIMAL(14,4) NULL,
                client_ip VARCHAR(64) NULL,
                user_agent TEXT NULL,
                ledger_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY unq_manual_topup_operation_key (operation_key),
                KEY idx_manual_topup_target (target_tenancy_id, target_user_id),
                KEY idx_manual_topup_actor (actor_tenancy_id, actor_user_id),
                KEY idx_manual_topup_source_created (source, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function insertAuditRecord(Database $db, array $data): void
    {
        $db->run(
            "INSERT INTO " . self::AUDIT_TABLE . " (
                operation_key,
                source,
                actor_user_id,
                actor_tenancy_id,
                actor_role,
                target_user_id,
                target_tenancy_id,
                target_role,
                amount,
                notes,
                balance_before,
                balance_after,
                client_ip,
                user_agent,
                ledger_id,
                created_at
            ) VALUES (
                :operation_key,
                :source,
                :actor_user_id,
                :actor_tenancy_id,
                :actor_role,
                :target_user_id,
                :target_tenancy_id,
                :target_role,
                :amount,
                :notes,
                :balance_before,
                :balance_after,
                :client_ip,
                :user_agent,
                :ledger_id,
                NOW()
            )",
            [
                ':operation_key' => (string)($data['operation_key'] ?? ''),
                ':source' => (string)($data['source'] ?? ''),
                ':actor_user_id' => (int)($data['actor_user_id'] ?? 0),
                ':actor_tenancy_id' => self::nullableString($data['actor_tenancy_id'] ?? null),
                ':actor_role' => (string)($data['actor_role'] ?? ''),
                ':target_user_id' => (int)($data['target_user_id'] ?? 0),
                ':target_tenancy_id' => (string)($data['target_tenancy_id'] ?? ''),
                ':target_role' => (string)($data['target_role'] ?? ''),
                ':amount' => round((float)($data['amount'] ?? 0), 4),
                ':notes' => self::nullableString($data['notes'] ?? null),
                ':balance_before' => isset($data['balance_before']) ? round((float)$data['balance_before'], 4) : null,
                ':balance_after' => isset($data['balance_after']) ? round((float)$data['balance_after'], 4) : null,
                ':client_ip' => self::nullableString($data['client_ip'] ?? null),
                ':user_agent' => self::nullableString($data['user_agent'] ?? null),
                ':ledger_id' => !empty($data['ledger_id']) ? (int)$data['ledger_id'] : null,
            ]
        );
    }

    private static function resolveClientIp(array $server): string
    {
        $forwarded = trim((string)($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            $parts = array_filter(array_map('trim', explode(',', $forwarded)));
            if ($parts !== []) {
                return (string)reset($parts);
            }
        }

        return trim((string)($server['HTTP_CLIENT_IP'] ?? $server['REMOTE_ADDR'] ?? '0.0.0.0'));
    }

    private static function normalizeRole(mixed $role): string
    {
        return strtolower(trim((string)$role));
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private static function response(int $statusCode, string $message, array $extra = []): array
    {
        return [
            'status_code' => $statusCode,
            'payload' => array_merge([
                'status' => $statusCode,
                'message' => $message,
            ], $extra),
        ];
    }
}
