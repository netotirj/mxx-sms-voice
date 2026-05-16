<?php

namespace App\Service;

use WilliamCosta\DatabaseManager\Database;

class FinancialHierarchyBillingService
{
    private const AUDIT_TABLE = 'financial_hierarchy_logs';

    public static function buildDebitPlan(array $input): array
    {
        $actorUserId = (int)($input['actor_user_id'] ?? 0);
        $tenancyId = trim((string)($input['tenancy_id'] ?? ''));
        $module = trim((string)($input['module'] ?? 'billing'));
        $event = trim((string)($input['event'] ?? 'usage'));
        $retailAmount = round(max(0, (float)($input['retail_amount'] ?? 0)), 4);
        $resellerAmount = round(max(0, (float)($input['reseller_amount'] ?? 0)), 4);
        $adminAmount = round(max(0, (float)($input['admin_amount'] ?? 0)), 4);
        $allowActorWallet = !array_key_exists('allow_actor_wallet', $input) || !empty($input['allow_actor_wallet']);

        if ($actorUserId <= 0 || $tenancyId === '') {
            throw new \InvalidArgumentException('Contexto financeiro invalido para montar a cadeia de debito.');
        }

        $context = FinancialHierarchyResolver::resolveContext($actorUserId, $tenancyId);
        $contextArray = $context->toArray();
        $legs = [];

        switch ($context->chain_type) {
            case FinancialHierarchyResolver::CHAIN_ADMIN:
                self::appendLeg($legs, 'actor_admin_retail', $context->actor_user_id, FinancialTransactionService::WALLET_ADMIN, $retailAmount, 'ACTOR_ADMIN_RETAIL');
                break;

            case FinancialHierarchyResolver::CHAIN_RESELLER:
                self::appendLeg($legs, 'actor_reseller_retail', $context->actor_user_id, FinancialTransactionService::WALLET_RESELLER, $retailAmount, 'ACTOR_RESELLER_RETAIL');
                if ($context->owner_admin_id !== $context->actor_user_id) {
                    self::appendLeg($legs, 'admin_upstream_cost', $context->owner_admin_id, FinancialTransactionService::WALLET_ADMIN, $adminAmount, 'ADMIN_UPSTREAM_COST');
                }
                break;

            case FinancialHierarchyResolver::CHAIN_CLIENT_RESELLER:
                $clientOwnWallet = $allowActorWallet && $context->actor_has_wallet;
                if ($clientOwnWallet) {
                    self::appendLeg($legs, 'actor_client_wallet', $context->actor_user_id, FinancialTransactionService::WALLET_ADMIN, $retailAmount, 'ACTOR_CLIENT_WALLET');
                    self::appendLeg($legs, 'reseller_upstream_cost', (int)$context->reseller_id, FinancialTransactionService::WALLET_RESELLER, $resellerAmount > 0 ? $resellerAmount : $retailAmount, 'RESELLER_UPSTREAM_COST');
                } else {
                    self::appendLeg($legs, 'reseller_retail_fallback', (int)$context->reseller_id, FinancialTransactionService::WALLET_RESELLER, $resellerAmount > 0 ? $resellerAmount : $retailAmount, 'RESELLER_RETAIL_FALLBACK');
                }
                self::appendLeg($legs, 'admin_upstream_cost', $context->owner_admin_id, FinancialTransactionService::WALLET_ADMIN, $adminAmount, 'ADMIN_UPSTREAM_COST');
                break;

            case FinancialHierarchyResolver::CHAIN_CLIENT_ADMIN:
            default:
                $clientOwnWallet = $allowActorWallet && $context->actor_has_wallet;
                if ($clientOwnWallet) {
                    self::appendLeg($legs, 'actor_client_wallet', $context->actor_user_id, FinancialTransactionService::WALLET_ADMIN, $retailAmount, 'ACTOR_CLIENT_WALLET');
                    if ($context->owner_admin_id !== $context->actor_user_id) {
                        self::appendLeg($legs, 'admin_upstream_cost', $context->owner_admin_id, FinancialTransactionService::WALLET_ADMIN, $adminAmount, 'ADMIN_UPSTREAM_COST');
                    }
                } else {
                    self::appendLeg($legs, 'admin_retail_fallback', $context->owner_admin_id, FinancialTransactionService::WALLET_ADMIN, $retailAmount, 'ADMIN_RETAIL_FALLBACK');
                }
                break;
        }

        $plan = [
            'module' => $module,
            'event' => $event,
            'operation_key_base' => self::resolveOperationKeyBase($input),
            'provider_reference' => self::nullableString($input['provider_reference'] ?? null),
            'related_type' => self::nullableString($input['related_type'] ?? null),
            'related_id' => self::nullableString($input['related_id'] ?? null),
            'description_prefix' => trim((string)($input['description_prefix'] ?? strtoupper($module . '_' . $event))),
            'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
            'context' => $contextArray,
            'legs' => array_values($legs),
        ];

        self::audit('plan_built', 'ready', $plan);

        return $plan;
    }

    public static function assertSufficientBalance(array $plan): void
    {
        foreach (self::summarizeRequiredBalances($plan) as $entry) {
            if ($entry['balance'] < $entry['amount']) {
                self::audit('balance_check', 'insufficient', [
                    'plan' => $plan,
                    'failed_wallet' => $entry,
                ]);

                throw new \RuntimeException(sprintf(
                    'Saldo insuficiente na cadeia financeira (%s user:%d need:%s have:%s).',
                    $entry['wallet'],
                    $entry['user_id'],
                    number_format($entry['amount'], 4, '.', ''),
                    number_format($entry['balance'], 4, '.', '')
                ));
            }
        }
    }

    public static function summarizeRequiredBalances(array $plan): array
    {
        $aggregated = [];
        $tenancyId = (string)($plan['context']['tenancy_id'] ?? '');

        foreach ((array)($plan['legs'] ?? []) as $leg) {
            $wallet = (string)($leg['wallet'] ?? '');
            $userId = (int)($leg['user_id'] ?? 0);
            $amount = round((float)($leg['amount'] ?? 0), 4);
            if ($wallet === '' || $userId <= 0 || $amount <= 0) {
                continue;
            }

            $key = $wallet . ':' . $userId;
            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'wallet' => $wallet,
                    'user_id' => $userId,
                    'amount' => 0.0,
                    'balance' => FinancialHierarchyResolver::currentWalletBalance($wallet, $userId, $tenancyId),
                    'leg_labels' => [],
                ];
            }

            $aggregated[$key]['amount'] = round($aggregated[$key]['amount'] + $amount, 4);
            $aggregated[$key]['leg_labels'][] = (string)($leg['description_code'] ?? $leg['slug'] ?? 'LEG');
        }

        return array_values($aggregated);
    }

    public static function debitPlan(array $plan, ?callable $withinTransaction = null): array
    {
        $operations = [];
        $module = (string)($plan['module'] ?? 'billing');
        $event = (string)($plan['event'] ?? 'usage');
        $descriptionPrefix = trim((string)($plan['description_prefix'] ?? strtoupper($module . '_' . $event)));
        $context = (array)($plan['context'] ?? []);
        $metadata = is_array($plan['metadata'] ?? null) ? $plan['metadata'] : [];
        $operationBase = trim((string)($plan['operation_key_base'] ?? ''));
        $providerReference = self::nullableString($plan['provider_reference'] ?? null);
        $relatedType = self::nullableString($plan['related_type'] ?? null);
        $relatedId = self::nullableString($plan['related_id'] ?? null);

        foreach ((array)($plan['legs'] ?? []) as $leg) {
            $amount = round((float)($leg['amount'] ?? 0), 4);
            $userId = (int)($leg['user_id'] ?? 0);
            $wallet = (string)($leg['wallet'] ?? '');

            if ($amount <= 0 || $userId <= 0 || $wallet === '') {
                continue;
            }

            $legSlug = strtolower(trim((string)($leg['slug'] ?? 'leg')));
            $descriptionCode = trim((string)($leg['description_code'] ?? strtoupper($legSlug)));
            $operationKey = sprintf(
                '%s:%s:u%d:w%s',
                $operationBase,
                $legSlug,
                $userId,
                $wallet
            );

            $operations[] = [
                'user_id' => $userId,
                'tenancy_id' => (string)($context['tenancy_id'] ?? ''),
                'wallet' => $wallet,
                'amount' => $amount,
                'operation_key' => $operationKey,
                'source' => $module . '_' . $event,
                'description' => $descriptionPrefix . ' | ' . $descriptionCode,
                'provider_reference' => $providerReference,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'metadata' => array_merge($metadata, [
                    'billing_leg' => $leg,
                    'billing_context' => $context,
                    'module' => $module,
                    'event' => $event,
                ]),
                'legacy_log_amount' => self::resolveLegacyLogAmount($wallet, $amount),
            ];
        }

        self::audit('debit_plan', 'pending', [
            'plan' => $plan,
            'operations' => $operations,
        ]);

        $result = FinancialTransactionService::debitBatch($operations, $withinTransaction);

        self::audit('debit_plan', !empty($result['ok']) ? 'committed' : 'failed', [
            'plan' => $plan,
            'result' => $result,
        ]);

        return $result;
    }

    private static function appendLeg(array &$legs, string $slug, int $userId, string $wallet, float $amount, string $descriptionCode): void
    {
        $amount = round(max(0, $amount), 4);
        if ($userId <= 0 || $amount <= 0) {
            return;
        }

        $key = $wallet . ':' . $userId . ':' . $slug;
        $legs[$key] = [
            'slug' => $slug,
            'user_id' => $userId,
            'wallet' => $wallet,
            'amount' => $amount,
            'description_code' => $descriptionCode,
        ];
    }

    private static function resolveOperationKeyBase(array $input): string
    {
        $base = trim((string)($input['operation_key_base'] ?? ''));
        if ($base !== '') {
            return $base;
        }

        $module = trim((string)($input['module'] ?? 'billing'));
        $event = trim((string)($input['event'] ?? 'usage'));
        $reference = trim((string)(
            $input['related_id']
            ?? $input['provider_reference']
            ?? ($input['tenancy_id'] ?? '') . ':' . ($input['actor_user_id'] ?? '')
        ));

        return strtolower($module . ':' . $event . ':' . $reference);
    }

    private static function resolveLegacyLogAmount(string $wallet, float $amount): float
    {
        return $wallet === FinancialTransactionService::WALLET_RESELLER
            ? round(-$amount, 4)
            : round($amount, 4);
    }

    private static function ensureAuditSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        (new Database())->run(
            "CREATE TABLE IF NOT EXISTS " . self::AUDIT_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                tenancy_id VARCHAR(64) NULL,
                actor_user_id INT UNSIGNED NULL,
                module_name VARCHAR(64) NULL,
                reference_id VARCHAR(191) NULL,
                payload_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_fin_hierarchy_event_status (event, status),
                KEY idx_fin_hierarchy_tenancy_actor (tenancy_id, actor_user_id),
                KEY idx_fin_hierarchy_reference (reference_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function audit(string $event, string $status, array $payload): void
    {
        try {
            self::ensureAuditSchema();

            $context = is_array($payload['plan']['context'] ?? null)
                ? $payload['plan']['context']
                : [];

            (new Database())->run(
                "INSERT INTO " . self::AUDIT_TABLE . " (
                    event,
                    status,
                    tenancy_id,
                    actor_user_id,
                    module_name,
                    reference_id,
                    payload_json,
                    created_at
                ) VALUES (
                    :event,
                    :status,
                    :tenancy_id,
                    :actor_user_id,
                    :module_name,
                    :reference_id,
                    :payload_json,
                    NOW()
                )",
                [
                    ':event' => $event,
                    ':status' => $status,
                    ':tenancy_id' => $context['tenancy_id'] ?? null,
                    ':actor_user_id' => $context['actor_user_id'] ?? null,
                    ':module_name' => $payload['plan']['module'] ?? null,
                    ':reference_id' => $payload['plan']['related_id'] ?? $payload['plan']['provider_reference'] ?? null,
                    ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
        } catch (\Throwable $e) {
            error_log('[financial_hierarchy_audit] ' . $e->getMessage());
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }
}
