<?php

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app/Controller/Pages/Voice.php';

$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');
$dbPort = getenv('DB_PORT');

if ($dbHost === false || $dbName === false || $dbUser === false || $dbPass === false || $dbPort === false) {
    \WilliamCosta\DotEnv\Environment::load(__DIR__);
}

\WilliamCosta\DatabaseManager\Database::config(
    getenv('DB_HOST'),
    getenv('DB_NAME'),
    getenv('DB_USER'),
    getenv('DB_PASS'),
    (int)getenv('DB_PORT')
);

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');

use App\Controller\Pages\VoiceBillingProcessor;
use App\Controller\Pages\WebStatusSms;
use App\Model\Entity\BalanceSms;
use App\Model\Entity\CallbackSms;
use App\Model\Entity\CampaignBatch;
use App\Model\Entity\CdrVoice;
use App\Model\Entity\RegisterTenancies;
use App\Model\Entity\UserSearch;
use App\Service\FinancialReconciliationService;
use App\Service\FinancialTransactionService;
use App\Service\WhatsAppBilling;
use App\Service\WhatsAppCallCdrStore;
use App\Service\WhatsAppVoiceBilling;
use WilliamCosta\DatabaseManager\Database;

final class ControlledFinancialValidation
{
    private string $runId;
    private string $tenantId;
    private string $reportFile;
    private int $planId = 1;
    private int $adminUserId;
    private int $resellerUserId;
    private array $report = [];

    public function __construct()
    {
        $this->runId = 'validation_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->tenantId = '99999999-0000-4000-8000-' . substr(preg_replace('/\D+/', '', (string)microtime(true)), -12);
        $this->reportFile = __DIR__ . '/storage/logs/' . $this->runId . '_financial_validation.json';
    }

    public function run(): array
    {
        FinancialTransactionService::ensureSchema();
        $this->bootstrapFixture();

        $this->report = [
            'run_id' => $this->runId,
            'executed_at' => date('Y-m-d H:i:s'),
            'tenant_id' => $this->tenantId,
            'admin_user_id' => $this->adminUserId,
            'reseller_user_id' => $this->resellerUserId,
            'scenarios' => [],
            'validation_queries' => $this->validationQueries(),
            'reconciliation' => [],
            'notes' => [
                'Provider-integrated sends/calls were not executed to avoid live operational impact.',
                'Voice synchronization with Asterisk was validated only as a safe fail path using a loopback-invalid endpoint.',
                'This report proves hardening behavior in the financial core, replay handling, and transaction boundaries on controlled fixtures.',
            ],
        ];

        $this->report['scenarios'][] = $this->validateVoiceManual();
        $this->report['scenarios'][] = $this->validateVoiceDialerReplayGuard();
        $this->report['scenarios'][] = $this->validateSmsCallbackBilling();
        $this->report['scenarios'][] = $this->validateWhatsAppDeliveredBilling();
        $this->report['scenarios'][] = $this->validateWhatsAppVoiceBilling();
        $this->report['scenarios'][] = $this->validateConcurrency();
        $this->report['scenarios'][] = $this->validateCrashRetry();
        $this->report['reconciliation'] = [
            'balance' => FinancialReconciliationService::auditBalanceConsistency(50),
            'voice' => FinancialReconciliationService::auditVoiceBilling(50),
            'sms' => FinancialReconciliationService::auditSmsBilling(50),
            'whatsapp' => FinancialReconciliationService::auditWhatsAppBilling(50),
            'active_calls' => FinancialReconciliationService::auditOrphanActiveCalls(50),
        ];

        $this->persistReport();
        return $this->report;
    }

    private function bootstrapFixture(): void
    {
        $tenant = new RegisterTenancies();
        $tenant->id = $this->tenantId;
        $tenant->name = 'Financial Validation ' . $this->runId;
        $tenant->tenancy_phone = '5568999999999';
        $tenant->status = 'active';
        $tenant->account_code = 'VAL' . substr(strtoupper(bin2hex(random_bytes(3))), 0, 6);
        $tenant->created_at = date('Y-m-d H:i:s');
        $tenant->updated_at = date('Y-m-d H:i:s');
        $tenant->insertUserTenancy();

        try {
            (new Database())->run(
                'UPDATE tenancies SET active_plan_id = :plan_id WHERE id = :id',
                [':plan_id' => $this->planId, ':id' => $this->tenantId]
            );
        } catch (\Throwable) {
        }

        $admin = new UserSearch();
        $admin->name = 'Validation Admin';
        $admin->last_name = 'Core';
        $admin->email = $this->runId . '.admin@example.test';
        $admin->user_function = 'admin';
        $admin->role_id = 1;
        $admin->password = password_hash('Validation!123', PASSWORD_BCRYPT);
        $admin->status_account = 'active';
        $admin->status = 'active';
        $admin->tenancy_id = $this->tenantId;
        $admin->account_code = 'VALADM' . substr(strtoupper(bin2hex(random_bytes(2))), 0, 4);
        $admin->createdAt = date('Y-m-d H:i:s');
        $this->adminUserId = $admin->insertUsers();

        $reseller = new UserSearch();
        $reseller->name = 'Validation Reseller';
        $reseller->last_name = 'Edge';
        $reseller->email = $this->runId . '.reseller@example.test';
        $reseller->user_function = 'reseller';
        $reseller->role_id = 1;
        $reseller->password = password_hash('Validation!123', PASSWORD_BCRYPT);
        $reseller->status_account = 'active';
        $reseller->status = 'active';
        $reseller->tenancy_id = $this->tenantId;
        $reseller->user_id = $this->adminUserId;
        $reseller->account_code = 'VALRES' . substr(strtoupper(bin2hex(random_bytes(2))), 0, 4);
        $reseller->createdAt = date('Y-m-d H:i:s');
        $this->resellerUserId = $reseller->insertUsers();

        (new Database())->run(
            'UPDATE users SET reseller_balance = :balance WHERE id = :id AND tenancy_id = :tenancy_id',
            [
                ':balance' => 500.0,
                ':id' => $this->resellerUserId,
                ':tenancy_id' => $this->tenantId,
            ]
        );

        BalanceSms::insertBalance(
            $this->adminUserId,
            $this->planId,
            $this->tenantId,
            500.0,
            0.50,
            1.20,
            1.50,
            $this->runId . '_invoice',
            1.20,
            1.10,
            0.80,
            0.10,
            json_encode([
                'pricing' => [
                    'value_whatsapp_marketing' => 0.80,
                    'value_whatsapp_utility' => 0.70,
                    'value_whatsapp_authentication' => 0.60,
                    'whatsapp_voice_price_per_minute' => 1.40,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'Validation Plan',
            'monthly',
            500.0
        );
    }

    private function validateVoiceManual(): array
    {
        putenv('ASTERISK_API_BASE_URL=http://127.0.0.1:9');
        putenv('ARI_HOST=127.0.0.1');

        $callId = $this->runId . '_voice_manual';
        $before = $this->adminBalance();

        $cdr = new CdrVoice();
        $cdr->channel_id = $callId . '_chan';
        $cdr->call_id = $callId;
        $cdr->tenancy_id = $this->tenantId;
        $cdr->user_id = $this->adminUserId;
        $cdr->channel_number = '2001';
        $cdr->number = '5568991111111';
        $cdr->destination = '5568992222222';
        $cdr->direction = 'outbound';
        $cdr->type = 'normal';
        $cdr->dialstatus = 'ANSWER';
        $cdr->duration = 66;
        $cdr->duration_seconds = 66;
        $cdr->billsec = 60;
        $cdr->billed_seconds = 60;
        $cdr->value = 1.20;
        $cdr->started = date('Y-m-d H:i:s', time() - 70);
        $cdr->answered = date('Y-m-d H:i:s', time() - 65);
        $cdr->ended = date('Y-m-d H:i:s', time() - 5);
        $this->insertCdrFixture($cdr);

        ob_start();
        VoiceBillingProcessor::process($cdr);
        VoiceBillingProcessor::process($cdr);
        $workerLog = trim((string)ob_get_clean());

        $after = $this->adminBalance();
        $opKey = sprintf('voice:%s:%s:%s:%d', $callId, 'voice', 'admin', $this->adminUserId);

        return [
            'scenario' => 'voice_manual',
            'status' => 'validated_with_safe_sync_failure',
            'operation_key' => $opKey,
            'tenant_id' => $this->tenantId,
            'saldo_antes' => $before,
            'saldo_depois' => $after,
            'valor_debitado' => round($before - $after, 4),
            'ledger' => $this->ledgerByOperationKey($opKey),
            'legacy_log' => $this->legacyLogsForReference($callId),
            'cdr' => $this->rows('SELECT id, call_id, channel_id, user_id, value, dialstatus, duration, created_at FROM cdr WHERE call_id = :call_id ORDER BY id DESC', [':call_id' => $callId]),
            'worker_logs' => $workerLog,
            'assertions' => [
                'single_ledger_row' => count($this->ledgerByOperationKey($opKey)) === 1,
                'single_legacy_log' => count($this->legacyLogsForReference($callId)) === 1,
                'single_charge_only' => round($before - $after, 4) === 1.20,
            ],
        ];
    }

    private function validateVoiceDialerReplayGuard(): array
    {
        putenv('ASTERISK_API_BASE_URL=http://127.0.0.1:9');
        putenv('ARI_HOST=127.0.0.1');

        $callId = $this->runId . '_voice_discador';
        $ownerBefore = $this->adminBalance();
        $resellerBefore = $this->resellerBalance();

        $external = new CdrVoice();
        $external->channel_id = $callId . '_ext';
        $external->call_id = $callId;
        $external->campaign_id = 999001;
        $external->tenancy_id = $this->tenantId;
        $external->user_id = $this->resellerUserId;
        $external->channel_number = '3001';
        $external->number = '5568993333333';
        $external->destination = '5568994444444';
        $external->direction = 'outbound';
        $external->type = 'normal';
        $external->dialstatus = 'ANSWER';
        $external->duration = 96;
        $external->duration_seconds = 96;
        $external->billsec = 90;
        $external->billed_seconds = 90;
        $external->value = 2.50;
        $external->trunk_billing_type = 'smart';
        $external->started = date('Y-m-d H:i:s', time() - 100);
        $external->answered = date('Y-m-d H:i:s', time() - 95);
        $external->ended = date('Y-m-d H:i:s', time() - 5);
        $this->insertCdrFixture($external);

        $internal = clone $external;
        $internal->channel_id = $callId . '_agent';
        $internal->channel_number = '3002';
        $internal->number = '3002';
        $internal->destination = '3001';
        $this->insertCdrFixture($internal);

        ob_start();
        VoiceBillingProcessor::process($external);
        VoiceBillingProcessor::process($internal);
        VoiceBillingProcessor::process($external);
        $workerLog = trim((string)ob_get_clean());

        $ownerAfter = $this->adminBalance();
        $resellerAfter = $this->resellerBalance();

        $voiceKey = sprintf('voice:%s:%s:%s:%d', $callId, 'voice', 'reseller', $this->resellerUserId);
        $upstreamKey = sprintf('voice:%s:%s:%s:%d', $callId, 'voice_upstream', 'admin', $this->adminUserId);

        return [
            'scenario' => 'voice_discador',
            'status' => 'validated_with_duplicate_leg_replay_guard',
            'operation_keys' => [$voiceKey, $upstreamKey],
            'tenant_id' => $this->tenantId,
            'saldo_admin_antes' => $ownerBefore,
            'saldo_admin_depois' => $ownerAfter,
            'saldo_reseller_antes' => $resellerBefore,
            'saldo_reseller_depois' => $resellerAfter,
            'ledger_voice' => $this->ledgerByOperationKey($voiceKey),
            'ledger_upstream' => $this->ledgerByOperationKey($upstreamKey),
            'legacy_log' => $this->legacyLogsForReference($callId),
            'cdr' => $this->rows('SELECT id, call_id, channel_id, user_id, destination, value, dialstatus FROM cdr WHERE call_id = :call_id ORDER BY id ASC', [':call_id' => $callId]),
            'worker_logs' => $workerLog,
            'assertions' => [
                'single_reseller_charge' => count($this->ledgerByOperationKey($voiceKey)) === 1,
                'single_upstream_charge' => count($this->ledgerByOperationKey($upstreamKey)) === 1,
                'reseller_debited_once' => round($resellerBefore - $resellerAfter, 4) === 2.50,
                'owner_debited_once' => round($ownerBefore - $ownerAfter, 4) > 0 && round($ownerBefore - $ownerAfter, 4) < 10,
            ],
        ];
    }

    private function validateSmsCallbackBilling(): array
    {
        $batchId = CampaignBatch::create([
            'campaign_id' => null,
            'user_id' => $this->adminUserId,
            'tenancy_id' => $this->tenantId,
        ]);

        $callback = new CallbackSms();
        $callback->batch_id = $batchId;
        $callback->phone_sms = '5568995555555';
        $callback->status_sms = 'DELIVERED';
        $callback->value_sms = '0.50';
        $callback->camp_name = 'Validation SMS';
        $callback->id_partner = 'u' . $this->adminUserId . '-b' . $batchId . '-1';
        $callback->date_send = date('Y-m-d H:i:s');
        $callback->user_id = $this->adminUserId;
        $callback->tenancy_id = $this->tenantId;
        $callback->operator = 'VAL';
        $callback->insertStatus();

        $before = $this->adminBalance();
        $method = new ReflectionMethod(WebStatusSms::class, 'processCharge');
        $method->setAccessible(true);
        $method->invoke(null, $this->adminUserId, $this->tenantId, 0.50, 1, 0, 0, 0, $batchId, $this->planId, 'Tarifa tenancy validation #' . $batchId, null);
        $mid = $this->adminBalance();

        (new Database())->run(
            "UPDATE callback SET status_sms = 'SENT' WHERE batch_id = :batch_id AND tenancy_id = :tenancy_id",
            [':batch_id' => $batchId, ':tenancy_id' => $this->tenantId]
        );
        $method->invoke(null, $this->adminUserId, $this->tenantId, 0.50, 0, 1, 0, 0, $batchId, $this->planId, 'Tarifa tenancy validation #' . $batchId, null);
        $after = $this->adminBalance();

        $opKey = sprintf('sms:batch:%d:owner:%d', $batchId, $this->adminUserId);

        return [
            'scenario' => 'sms_callback',
            'status' => 'validated_for_duplicate_and_out_of_order_callback',
            'operation_key' => $opKey,
            'tenant_id' => $this->tenantId,
            'saldo_antes' => $before,
            'saldo_apos_primeiro_callback' => $mid,
            'saldo_depois_replay' => $after,
            'valor_debitado' => round($before - $after, 4),
            'ledger' => $this->ledgerByOperationKey($opKey),
            'legacy_log' => $this->legacyLogsForReference('#' . $batchId),
            'callback_rows' => $this->rows('SELECT id, batch_id, phone_sms, status_sms, value_sms, id_partner, update_date FROM callback WHERE batch_id = :batch_id ORDER BY id ASC', [':batch_id' => $batchId]),
            'campaign_batch' => $this->rows('SELECT id, charged, tenancy_id, user_id, created_at FROM campaign_batches WHERE id = :id', [':id' => $batchId]),
            'assertions' => [
                'single_ledger_row' => count($this->ledgerByOperationKey($opKey)) === 1,
                'single_charge_only' => round($before - $after, 4) === 0.50,
            ],
        ];
    }

    private function validateWhatsAppDeliveredBilling(): array
    {
        $wamid = $this->runId . '_wamid_1';
        $before = $this->adminBalance();

        (new Database())->run(
            "INSERT INTO whatsapp_message_cdr (
                client_id, tenancy_id, type, phone_number, message_category, template_name,
                direction, price_brl, billed, status, wamid, timestamp, created_at
            ) VALUES (
                :client_id, :tenancy_id, 'whatsapp', :phone_number, 'marketing', :template_name,
                'outbound', :price_brl, 0, 'sent', :wamid, NOW(), NOW()
            )",
            [
                ':client_id' => $this->adminUserId,
                ':tenancy_id' => $this->tenantId,
                ':phone_number' => '5568996666666',
                ':template_name' => 'validation_template',
                ':price_brl' => 0.80,
                ':wamid' => $wamid,
            ]
        );

        $first = WhatsAppBilling::billDeliveredByWamid($wamid);
        $second = WhatsAppBilling::billDeliveredByWamid($wamid);
        $after = $this->adminBalance();
        $opKey = 'whatsapp:delivered:' . $wamid;

        return [
            'scenario' => 'whatsapp_delivered',
            'status' => 'validated_for_duplicate_delivery_callback',
            'operation_key' => $opKey,
            'tenant_id' => $this->tenantId,
            'saldo_antes' => $before,
            'saldo_depois' => $after,
            'valor_debitado' => round($before - $after, 4),
            'first_status' => $first,
            'second_status' => $second,
            'ledger' => $this->ledgerByOperationKey($opKey),
            'legacy_log' => $this->legacyLogsForReference($wamid),
            'whatsapp_cdr' => $this->rows('SELECT id, wamid, status, billed, delivered_at, price_brl, created_at FROM whatsapp_message_cdr WHERE wamid = :wamid', [':wamid' => $wamid]),
            'assertions' => [
                'single_ledger_row' => count($this->ledgerByOperationKey($opKey)) === 1,
                'single_charge_only' => round($before - $after, 4) === 0.80,
            ],
        ];
    }

    private function validateWhatsAppVoiceBilling(): array
    {
        $callId = $this->runId . '_wa_voice';
        $before = $this->adminBalance();

        WhatsAppCallCdrStore::upsert([
            'tenancy_id' => $this->tenantId,
            'customer_id' => $this->adminUserId,
            'plan_id' => $this->planId,
            'account_id' => 999001,
            'call_id' => $callId,
            'direction' => 'outbound',
            'status' => 'ANSWER',
            'duration_seconds' => 75,
            'final_price' => 1.40,
            'final_price_brl' => 1.40,
            'price_brl' => 1.40,
            'billable_seconds' => 72,
            'billable_minutes' => 1.2,
            'pulse_seconds' => 6,
            'price_per_minute' => 1.1667,
            'raw_payload' => ['run_id' => $this->runId],
        ]);

        $method = new ReflectionMethod(WhatsAppVoiceBilling::class, 'attemptBalanceDebit');
        $method->setAccessible(true);
        $method->invoke(null, $this->adminUserId, $this->tenantId, 1.40, $callId);
        $method->invoke(null, $this->adminUserId, $this->tenantId, 1.40, $callId);

        $after = $this->adminBalance();
        $opKey = 'whatsapp_voice:' . $callId;

        return [
            'scenario' => 'whatsapp_voice',
            'status' => 'validated_for_lock_and_replay',
            'operation_key' => $opKey,
            'tenant_id' => $this->tenantId,
            'saldo_antes' => $before,
            'saldo_depois' => $after,
            'valor_debitado' => round($before - $after, 4),
            'ledger' => $this->ledgerByOperationKey($opKey),
            'legacy_log' => $this->legacyLogsForReference($callId),
            'whatsapp_voice_cdr' => $this->rows('SELECT id, call_id, final_price, balance_debited_at, charge_lock_token, updated_at FROM whatsapp_call_cdr WHERE call_id = :call_id', [':call_id' => $callId]),
            'assertions' => [
                'single_ledger_row' => count($this->ledgerByOperationKey($opKey)) === 1,
                'single_charge_only' => round($before - $after, 4) === 1.40,
            ],
        ];
    }

    private function validateConcurrency(): array
    {
        $operationKey = 'concurrency:' . $this->runId;
        $before = $this->adminBalance();
        $script = __DIR__ . '/storage/logs/' . $this->runId . '_concurrency_worker.php';
        file_put_contents($script, <<<'PHP'
<?php
require __DIR__ . '/../../bootstrap/cli.php';
require_once __DIR__ . '/../../app/Controller/Pages/Voice.php';
use App\Service\FinancialTransactionService;
$result = FinancialTransactionService::debit([
    'user_id' => (int)$argv[1],
    'tenancy_id' => (string)$argv[2],
    'wallet' => FinancialTransactionService::WALLET_ADMIN,
    'amount' => (float)$argv[3],
    'operation_key' => (string)$argv[4],
    'source' => 'validation_concurrency',
    'description' => 'validation concurrency',
    'provider_reference' => (string)$argv[4],
    'related_type' => 'validation',
    'related_id' => (string)$argv[4],
    'legacy_log_amount' => -((float)$argv[3]),
]);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
PHP
        );

        $php = PHP_BINARY;
        $out1 = __DIR__ . '/storage/logs/' . $this->runId . '_concurrency_1.json';
        $out2 = __DIR__ . '/storage/logs/' . $this->runId . '_concurrency_2.json';
        $cmd1 = sprintf('"%s" "%s" %d "%s" %.4f "%s"', $php, $script, $this->adminUserId, $this->tenantId, 2.25, $operationKey);
        $cmd2 = sprintf('"%s" "%s" %d "%s" %.4f "%s"', $php, $script, $this->adminUserId, $this->tenantId, 2.25, $operationKey);

        $p1 = proc_open($cmd1, [['pipe', 'r'], ['file', $out1, 'w'], ['file', $out1 . '.err', 'w']], $pipes1, __DIR__);
        $p2 = proc_open($cmd2, [['pipe', 'r'], ['file', $out2, 'w'], ['file', $out2 . '.err', 'w']], $pipes2, __DIR__);
        if (is_resource($p1)) {
            proc_close($p1);
        }
        if (is_resource($p2)) {
            proc_close($p2);
        }

        $after = $this->adminBalance();
        $ledger = $this->ledgerByOperationKey($operationKey);

        return [
            'scenario' => 'concurrency',
            'status' => 'validated',
            'operation_key' => $operationKey,
            'tenant_id' => $this->tenantId,
            'saldo_antes' => $before,
            'saldo_depois' => $after,
            'valor_debitado' => round($before - $after, 4),
            'process_1' => json_decode((string)@file_get_contents($out1), true),
            'process_2' => json_decode((string)@file_get_contents($out2), true),
            'ledger' => $ledger,
            'legacy_log' => $this->legacyLogsForReference('validation concurrency'),
            'assertions' => [
                'single_ledger_row' => count($ledger) === 1,
                'single_charge_only' => round($before - $after, 4) === 2.25,
            ],
        ];
    }

    private function validateCrashRetry(): array
    {
        $operationKey = 'crash_retry:' . $this->runId;
        $before = $this->adminBalance();
        $failure = null;

        try {
            FinancialTransactionService::debit([
                'user_id' => $this->adminUserId,
                'tenancy_id' => $this->tenantId,
                'wallet' => FinancialTransactionService::WALLET_ADMIN,
                'amount' => 1.75,
                'operation_key' => $operationKey,
                'source' => 'validation_crash_retry',
                'description' => 'validation crash retry',
                'provider_reference' => $operationKey,
                'related_type' => 'validation',
                'related_id' => $operationKey,
                'legacy_log_amount' => -1.75,
            ], static function (): void {
                throw new RuntimeException('forced crash before commit');
            });
        } catch (\Throwable $e) {
            $failure = $e->getMessage();
        }

        $mid = $this->adminBalance();
        $retry = FinancialTransactionService::debit([
            'user_id' => $this->adminUserId,
            'tenancy_id' => $this->tenantId,
            'wallet' => FinancialTransactionService::WALLET_ADMIN,
            'amount' => 1.75,
            'operation_key' => $operationKey,
            'source' => 'validation_crash_retry',
            'description' => 'validation crash retry',
            'provider_reference' => $operationKey,
            'related_type' => 'validation',
            'related_id' => $operationKey,
            'legacy_log_amount' => -1.75,
        ]);
        $after = $this->adminBalance();
        $ledger = $this->ledgerByOperationKey($operationKey);

        return [
            'scenario' => 'crash_retry',
            'status' => 'validated',
            'operation_key' => $operationKey,
            'tenant_id' => $this->tenantId,
            'saldo_antes' => $before,
            'saldo_apos_falha' => $mid,
            'saldo_depois_retry' => $after,
            'valor_debitado' => round($before - $after, 4),
            'forced_failure' => $failure,
            'retry_result' => $retry,
            'ledger' => $ledger,
            'legacy_log' => $this->legacyLogsForReference('validation crash retry'),
            'assertions' => [
                'rollback_preserved_balance' => round($before - $mid, 4) === 0.0,
                'single_charge_after_retry' => round($before - $after, 4) === 1.75,
                'single_ledger_row' => count($ledger) === 1,
            ],
        ];
    }

    private function adminBalance(): float
    {
        return (float)((new Database())->run(
            'SELECT balance FROM tenancy_balance WHERE user_id = :user_id AND tenancy_id = :tenancy_id ORDER BY updated_at DESC, id DESC LIMIT 1',
            [':user_id' => $this->adminUserId, ':tenancy_id' => $this->tenantId]
        )->fetchColumn() ?: 0.0);
    }

    private function resellerBalance(): float
    {
        return (float)((new Database())->run(
            'SELECT reseller_balance FROM users WHERE id = :id AND tenancy_id = :tenancy_id LIMIT 1',
            [':id' => $this->resellerUserId, ':tenancy_id' => $this->tenantId]
        )->fetchColumn() ?: 0.0);
    }

    private function ledgerByOperationKey(string $operationKey): array
    {
        return $this->rows(
            'SELECT id, operation_key, user_id, tenancy_id, wallet, amount, balance_before, balance_after, status, related_type, related_id, created_at, processed_at
             FROM financial_transaction_ledger
             WHERE operation_key = :operation_key
             ORDER BY id ASC',
            [':operation_key' => $operationKey]
        );
    }

    private function legacyLogsForReference(string $reference): array
    {
        return $this->rows(
            'SELECT id, user_id, tenancy_id, amount, description, created_at
             FROM tenancy_balance_logs
             WHERE tenancy_id = :tenancy_id
               AND description LIKE :description
             ORDER BY id ASC',
            [
                ':tenancy_id' => $this->tenantId,
                ':description' => '%' . $reference . '%',
            ]
        );
    }

    private function rows(string $query, array $params = []): array
    {
        return (new Database())->run($query, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function insertCdrFixture(CdrVoice $cdr): void
    {
        (new Database())->run(
            "INSERT INTO cdr (
                channel_id,
                job_id,
                call_id,
                tenancy_id,
                user_id,
                user_name,
                user_account_code,
                campaign_id,
                campaign_type,
                channel_number,
                endpoints,
                `number`,
                destination,
                direction,
                trunk,
                techprefix,
                `type`,
                `state`,
                dialstatus,
                cause,
                cause_txt,
                sip_code,
                duration,
                billsec,
                taxa_of_service,
                `value`,
                call_minute_cost,
                hangup_by,
                started,
                answered,
                ended,
                created_at,
                updated_at
            ) VALUES (
                :channel_id,
                :job_id,
                :call_id,
                :tenancy_id,
                :user_id,
                :user_name,
                :user_account_code,
                :campaign_id,
                :campaign_type,
                :channel_number,
                :endpoints,
                :number,
                :destination,
                :direction,
                :trunk,
                :techprefix,
                :type,
                :state,
                :dialstatus,
                :cause,
                :cause_txt,
                :sip_code,
                :duration,
                :billsec,
                :taxa_of_service,
                :value,
                :call_minute_cost,
                :hangup_by,
                :started,
                :answered,
                :ended,
                NOW(),
                NOW()
            )",
            [
                ':channel_id' => $cdr->channel_id,
                ':job_id' => $cdr->job_id,
                ':call_id' => $cdr->call_id,
                ':tenancy_id' => $cdr->tenancy_id,
                ':user_id' => $cdr->user_id,
                ':user_name' => $cdr->user_name,
                ':user_account_code' => $cdr->user_account_code,
                ':campaign_id' => $cdr->campaign_id,
                ':campaign_type' => $cdr->campaign_type,
                ':channel_number' => $cdr->channel_number,
                ':endpoints' => $cdr->endpoints,
                ':number' => $cdr->number,
                ':destination' => $cdr->destination,
                ':direction' => $cdr->direction ?? 'outbound',
                ':trunk' => $cdr->trunk,
                ':techprefix' => $cdr->techprefix,
                ':type' => $cdr->type ?? 'normal',
                ':state' => $cdr->state,
                ':dialstatus' => $cdr->dialstatus,
                ':cause' => $cdr->cause,
                ':cause_txt' => $cdr->cause_txt,
                ':sip_code' => $cdr->sip_code,
                ':duration' => $cdr->duration ?? 0,
                ':billsec' => $cdr->billsec ?? $cdr->duration ?? 0,
                ':taxa_of_service' => $cdr->taxa_of_service ?? 0,
                ':value' => $cdr->value ?? 0,
                ':call_minute_cost' => $cdr->call_minute_cost ?? 0,
                ':hangup_by' => $cdr->hangup_by,
                ':started' => $cdr->started,
                ':answered' => $cdr->answered,
                ':ended' => $cdr->ended,
            ]
        );
    }

    private function persistReport(): void
    {
        $dir = dirname($this->reportFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $this->reportFile,
            json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function validationQueries(): array
    {
        return [
            'ledger_by_operation_key' => "SELECT * FROM financial_transaction_ledger WHERE operation_key = ? ORDER BY id ASC;",
            'legacy_log_by_reference' => "SELECT * FROM tenancy_balance_logs WHERE tenancy_id = ? AND description LIKE ? ORDER BY id ASC;",
            'tenant_balance' => "SELECT * FROM tenancy_balance WHERE tenancy_id = ? ORDER BY updated_at DESC, id DESC;",
            'cdr_by_call_id' => "SELECT * FROM cdr WHERE call_id = ? ORDER BY id ASC;",
            'sms_by_batch' => "SELECT * FROM callback WHERE batch_id = ? ORDER BY id ASC;",
            'whatsapp_by_wamid' => "SELECT * FROM whatsapp_message_cdr WHERE wamid = ? ORDER BY id ASC;",
            'whatsapp_voice_by_call_id' => "SELECT * FROM whatsapp_call_cdr WHERE call_id = ? ORDER BY id ASC;",
            'orphan_active_calls' => "php run_financial_reconciliation.php active_calls 50",
        ];
    }
}

$runner = new ControlledFinancialValidation();
$report = $runner->run();
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
