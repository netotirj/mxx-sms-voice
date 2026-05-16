<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use App\Model\Entity\CallbackSms;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected: ' . var_export($expected, true) . ' Actual: ' . var_export($actual, true));
    }
}

$callbackReflection = new ReflectionClass(CallbackSms::class);
$scopeMethod = $callbackReflection->getMethod('buildScopeConditions');
$scopeMethod->setAccessible(true);
$distinctMethod = $callbackReflection->getMethod('distinctInboundMoExpression');
$distinctMethod->setAccessible(true);

assertSameValue('CLARO', CallbackSms::pickInboundMoOperator(null, 'CLARO'), 'Inbound MO should inherit the outbound operator when payload operator is missing.');
assertSameValue('TIM', CallbackSms::pickInboundMoOperator('TIM', 'CLARO'), 'Inbound MO should prefer the payload operator when it is valid.');
assertSameValue('VIVO', CallbackSms::pickInboundMoOperator('MO', 'VIVO'), 'Inbound MO should ignore pseudo-operator MO and fallback to the outbound operator.');
assertSameValue(null, CallbackSms::normalizeOperatorForDashboard('MO'), 'Operator MO must not be treated as a real carrier in dashboard aggregation.');

[$conditions, $params] = $scopeMethod->invoke(null, 'tenant-1', null, 55, 'c');
$scopeSql = implode(' ', $conditions);
assertTrue(str_contains($scopeSql, 'c.tenancy_id = :tenancy_id'), 'Scope builder must filter the tenant for reseller queries.');
assertTrue(str_contains($scopeSql, 'SELECT u.id'), 'Scope builder must include reseller subusers in the hierarchy filter.');
assertTrue(str_contains($scopeSql, 'u.tenancy_id = :reseller_tenancy_id'), 'Scope builder must isolate reseller hierarchy by tenant.');
assertSameValue('tenant-1', $params[':tenancy_id'] ?? null, 'Scope builder must bind tenant_id.');
assertSameValue('tenant-1', $params[':reseller_tenancy_id'] ?? null, 'Scope builder must bind reseller tenant_id.');

$distinctSql = $distinctMethod->invoke(null, 'c');
assertTrue(str_contains($distinctSql, 'c.sms_reference_id'), 'Distinct MO expression must prefer sms_reference_id when available.');
assertTrue(str_contains($distinctSql, 'c.origin_id'), 'Distinct MO expression must fallback to origin_id when sms_reference_id is absent.');
assertTrue(str_contains($distinctSql, 'c.response_text'), 'Distinct MO expression must include response_text for deterministic deduplication.');

$dashboardView = file_get_contents(__DIR__ . '/../../resources/view/dashboard/index.html');
assertTrue($dashboardView !== false, 'Dashboard view must be readable.');
assertTrue(str_contains($dashboardView, "line: smsPeriod.status"), 'SMS line chart must continue to use the full status payload.');
assertTrue(str_contains($dashboardView, "doughnut: smsPeriod.status"), 'SMS doughnut chart must continue to use the full status payload.');
assertTrue(str_contains($dashboardView, "title: operatorLabels.length ? 'SMS por operadora'"), 'SMS secondary chart must remain configured as operator-based.');

$callbackSource = file_get_contents(__DIR__ . '/../../app/Model/Entity/CallbackSms.php');
assertTrue($callbackSource !== false, 'CallbackSms source must be readable.');
assertTrue(str_contains($callbackSource, 'SELECT TRIM(out.operator)'), 'MO operator aggregation must fallback to the original outbound operator for legacy inbound rows.');

echo "sms_dashboard_audit: ok\n";
