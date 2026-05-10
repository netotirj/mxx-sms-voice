<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Model\Entity\PlanCatalog;
use App\Session\User as SessionUser;
use App\Utils\View;

class Plans extends ViewComponents
{
    public static function getPlans($request): string
    {
        PlanCatalog::ensureSchema();
        PlanCatalog::ensureRouteCatalog();

        if (!self::isCurrentUserSuperAdmin()) {
            $content = self::renderAccessDenied();
            return parent::getComponentsUsers('Maxx Solutions | Planos', $content);
        }

        $content = View::render('/plans/index', []);
        return parent::getComponentsUsers('Maxx Solutions | Planos', $content);
    }

    public static function search($request): Response
    {
        if ($guard = self::guardSuperAdminJson()) {
            return $guard;
        }

        PlanCatalog::ensureSchema();
        PlanCatalog::ensureRouteCatalog();

        $query = method_exists($request, 'getQueryParams') ? (array)$request->getQueryParams() : ($_GET ?? []);
        $plans = PlanCatalog::all([
            'status' => $query['status'] ?? '',
            'type_plan' => $query['type_plan'] ?? '',
            'search' => $query['search'] ?? '',
        ]);

        $rows = array_map([self::class, 'formatPlanRow'], $plans);
        $stats = self::buildStats($rows);

        return new Response(200, [
            'status' => 'ok',
            'data' => $rows,
            'stats' => $stats,
            'types' => PlanCatalog::availableTypes(),
            'billing_cycles' => PlanCatalog::availableBillingCycles(),
        ], 'application/json');
    }

    public static function show($request, $id): Response
    {
        if ($guard = self::guardSuperAdminJson()) {
            return $guard;
        }

        PlanCatalog::ensureSchema();
        $planId = (int)$id;
        $plan = PlanCatalog::findById($planId);

        if (!$plan || !empty($plan['deleted_at'])) {
            return new Response(404, [
                'status' => 'error',
                'message' => 'Plano não encontrado.',
            ], 'application/json');
        }

        return new Response(200, [
            'status' => 'ok',
            'data' => self::formatPlanRow($plan),
            'usage' => PlanCatalog::usageSummary($planId),
        ], 'application/json');
    }

    public static function save($request): Response
    {
        if ($guard = self::guardSuperAdminJson()) {
            return $guard;
        }

        PlanCatalog::ensureSchema();
        $data = (array)$request->getPostVars();
        $planId = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : null;

        $validationError = self::validatePayload($data, $planId);
        if ($validationError !== null) {
            return new Response(422, [
                'status' => 'error',
                'message' => $validationError,
            ], 'application/json');
        }

        try {
            $saved = PlanCatalog::save($data, $planId);

            return new Response(200, [
                'status' => 'ok',
                'message' => $planId ? 'Plano atualizado com sucesso.' : 'Plano criado com sucesso.',
                'data' => self::formatPlanRow($saved),
            ], 'application/json');
        } catch (\Throwable $exception) {
            return new Response(500, [
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 'application/json');
        }
    }

    public static function updateStatus($request, $id): Response
    {
        if ($guard = self::guardSuperAdminJson()) {
            return $guard;
        }

        PlanCatalog::ensureSchema();
        $payload = (array)$request->getPostVars();
        $status = (string)($payload['status'] ?? 'inactive');
        $planId = (int)$id;

        if (!PlanCatalog::findById($planId)) {
            return new Response(404, [
                'status' => 'error',
                'message' => 'Plano não encontrado.',
            ], 'application/json');
        }

        $updated = PlanCatalog::setStatus($planId, $status);

        return new Response($updated ? 200 : 400, [
            'status' => $updated ? 'ok' : 'error',
            'message' => $updated ? 'Status do plano atualizado.' : 'Não foi possível atualizar o status do plano.',
        ], 'application/json');
    }

    public static function delete($request, $id): Response
    {
        if ($guard = self::guardSuperAdminJson()) {
            return $guard;
        }

        PlanCatalog::ensureSchema();
        $planId = (int)$id;

        if (!PlanCatalog::findById($planId)) {
            return new Response(404, [
                'status' => 'error',
                'message' => 'Plano não encontrado.',
            ], 'application/json');
        }

        $result = PlanCatalog::softDelete($planId);
        $httpCode = !empty($result['allowed']) ? 200 : 409;

        return new Response($httpCode, [
            'status' => !empty($result['allowed']) ? 'ok' : 'error',
            'message' => (string)($result['message'] ?? 'Não foi possível remover o plano.'),
            'usage' => $result['usage'] ?? [],
        ], 'application/json');
    }

    private static function guardSuperAdminJson(): ?Response
    {
        if (self::isCurrentUserSuperAdmin()) {
            return null;
        }

        return new Response(403, [
            'status' => 'error',
            'message' => 'Acesso restrito ao super administrador.',
        ], 'application/json');
    }

    private static function isCurrentUserSuperAdmin(): bool
    {
        $user = SessionUser::getLogged();
        $function = strtolower(trim((string)($user['function'] ?? $user['user_function'] ?? '')));
        return $function === 'super_admin';
    }

    private static function renderAccessDenied(): string
    {
        return '
        <div class="w-full px-6 py-6 mx-auto">
            <div class="flex flex-wrap -mx-3">
                <div class="w-full max-w-full px-3">
                    <div class="rounded-2xl border border-rose-100 bg-white p-8 shadow-xl">
                        <div class="flex items-start gap-4">
                            <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-100 text-rose-600">
                                <i class="ni ni-lock-circle-open text-xl"></i>
                            </span>
                            <div>
                                <h2 class="text-lg font-bold text-slate-800">Acesso restrito</h2>
                                <p class="mt-2 text-sm text-slate-500">O catálogo global de planos só pode ser gerenciado pelo super administrador.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>';
    }

    private static function validatePayload(array $data, ?int $planId = null): ?string
    {
        $name = trim((string)($data['name_plan'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        $status = trim((string)($data['status'] ?? ''));
        $amount = $data['amount_plan'] ?? null;

        if ($name === '') {
            return 'Informe o nome do plano.';
        }

        if ($slug === '') {
            return 'Informe o código interno do plano.';
        }

        if ($amount === null || !is_numeric((string)$amount)) {
            return 'Informe um valor numérico válido para o plano.';
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            return 'Status inválido para o plano.';
        }

        $numericFields = [
            'simultaneous_access',
            'users_limit',
            'sms_limit',
            'voice_limit',
            'campaigns_limit',
            'trunks',
            'whatsapp_accounts',
            'templates_limit',
            'whatsapp_voice_price_per_minute',
            'whatsapp_voice_markup_percent',
            'whatsapp_voice_billing_pulse_seconds',
        ];

        foreach ($numericFields as $field) {
            if (isset($data[$field]) && $data[$field] !== '' && !is_numeric((string)$data[$field])) {
                return 'O campo ' . $field . ' precisa ser numérico.';
            }
        }

        if (PlanCatalog::slugExists($slug, $planId)) {
            return 'Já existe um plano com este código interno.';
        }

        return null;
    }

    private static function buildStats(array $plans): array
    {
        $active = 0;
        $inactive = 0;
        $modules = [];
        $visibleModules = [
            'administrative',
            'users',
            'permissions',
            'rates',
            'reports',
            'sms',
            'voice',
            'callcenter',
            'whatsapp',
            'templates',
            'trunks',
        ];

        foreach ($plans as $plan) {
            if (($plan['status'] ?? '') === 'active') {
                $active++;
            } else {
                $inactive++;
            }

            foreach ((array)($plan['modules'] ?? []) as $key => $enabled) {
                if ($enabled && in_array((string)$key, $visibleModules, true)) {
                    $modules[$key] = true;
                }
            }
        }

        return [
            'total' => count($plans),
            'active' => $active,
            'inactive' => $inactive,
            'modules_enabled' => count($modules),
        ];
    }

    private static function formatPlanRow(array $plan): array
    {
        $usage = PlanCatalog::usageSummary((int)$plan['id']);

        return [
            'id' => (int)$plan['id'],
            'name_plan' => (string)$plan['name_plan'],
            'slug' => (string)$plan['slug'],
            'description' => (string)$plan['description'],
            'amount_plan' => (float)$plan['amount_plan'],
            'amount_label' => 'R$ ' . number_format((float)$plan['amount_plan'], 2, ',', '.'),
            'billing_cycle' => (string)$plan['billing_cycle'],
            'status' => (string)$plan['status'],
            'type_plan' => (string)$plan['type_plan'],
            'payment_type' => (string)$plan['payment_type'],
            'simultaneous_access' => (int)$plan['simultaneous_access'],
            'users_create' => (string)$plan['users_create'],
            'users_limit' => (int)$plan['users_limit'],
            'sms_limit' => (int)$plan['sms_limit'],
            'voice_limit' => (int)$plan['voice_limit'],
            'campaigns_limit' => (int)$plan['campaigns_limit'],
            'trunks' => (int)$plan['trunks'],
            'whatsapp_accounts' => (int)$plan['whatsapp_accounts'],
            'templates_limit' => (int)$plan['templates_limit'],
            'webrtc_enabled' => !empty($plan['webrtc_enabled']),
            'modules' => (array)($plan['modules'] ?? []),
            'modules_label' => self::formatModulesLabel((array)($plan['modules'] ?? [])),
            'internal_notes' => (string)$plan['internal_notes'],
            'reports_label' => (string)$plan['reports_label'],
            'service_fee' => (float)$plan['service_fee'],
            'service_fee_label' => number_format((float)$plan['service_fee'], 4, ',', '.'),
            'value_sms' => (float)$plan['value_sms'],
            'value_voice' => (float)$plan['value_voice'],
            'voice_open_rate' => (float)$plan['voice_open_rate'],
            'voice_smart_rate' => (float)$plan['voice_smart_rate'],
            'value_torpedo' => (float)$plan['value_torpedo'],
            'value_whatsapp' => (float)$plan['value_whatsapp'],
            'value_whatsapp_marketing' => (float)$plan['value_whatsapp_marketing'],
            'value_whatsapp_utility' => (float)$plan['value_whatsapp_utility'],
            'value_whatsapp_authentication' => (float)$plan['value_whatsapp_authentication'],
            'whatsapp_voice_enabled' => !empty($plan['whatsapp_voice_enabled']),
            'whatsapp_voice_price_per_minute' => (float)$plan['whatsapp_voice_price_per_minute'],
            'whatsapp_voice_markup_percent' => (float)$plan['whatsapp_voice_markup_percent'],
            'whatsapp_voice_billing_pulse_seconds' => (int)$plan['whatsapp_voice_billing_pulse_seconds'],
            'created_at' => (string)$plan['created_at'],
            'updated_at' => (string)$plan['updated_at'],
            'usage' => $usage,
        ];
    }

    private static function formatModulesLabel(array $modules): string
    {
        $labels = [
            'administrative' => 'Administrativo',
            'sms' => 'SMS',
            'callcenter' => 'Call Center',
            'voice' => 'Voz',
            'whatsapp' => 'WhatsApp',
            'templates' => 'Templates',
            'reports' => 'Relatórios',
            'users' => 'Usuários',
            'permissions' => 'Permissões',
            'rates' => 'Tarifas',
            'trunks' => 'Trunks',
        ];

        $enabled = [];
        foreach ($modules as $key => $value) {
            if ($value && isset($labels[$key])) {
                $enabled[] = $labels[$key] ?? ucfirst(str_replace('_', ' ', (string)$key));
            }
        }

        return $enabled !== [] ? implode(', ', $enabled) : 'Nenhum módulo liberado';
    }
}
