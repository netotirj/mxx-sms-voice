<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Service\PlatformGlobalCostService;
use App\Session\User as SessionUser;
use App\Utils\View;

class GlobalCosts extends ViewComponents
{
    public static function getPage(): string
    {
        PlatformGlobalCostService::ensureSchema();
        PlatformGlobalCostService::ensureRouteCatalog();

        if (!self::isCurrentUserSuperAdmin()) {
            return parent::getComponentsUsers('Maxx Solutions | Custos Globais', self::renderAccessDenied());
        }

        return parent::getComponentsUsers(
            'Maxx Solutions | Custos Globais',
            View::render('/global-costs/index', [])
        );
    }

    public static function search($request): Response
    {
        if ($guard = self::guardSuperAdminJson()) {
            return $guard;
        }

        $query = method_exists($request, 'getQueryParams') ? (array)$request->getQueryParams() : ($_GET ?? []);
        $rows = PlatformGlobalCostService::currentRows($query);

        return new Response(200, [
            'status' => 'ok',
            'data' => $rows,
            'summary' => PlatformGlobalCostService::auditSummary(),
        ], 'application/json');
    }

    public static function history($request): Response
    {
        if ($guard = self::guardSuperAdminJson()) {
            return $guard;
        }

        $query = method_exists($request, 'getQueryParams') ? (array)$request->getQueryParams() : ($_GET ?? []);
        return new Response(200, [
            'status' => 'ok',
            'data' => PlatformGlobalCostService::history($query),
        ], 'application/json');
    }

    public static function save($request): Response
    {
        if ($guard = self::guardSuperAdminJson()) {
            return $guard;
        }

        $user = SessionUser::getLogged();
        $payload = (array)$request->getPostVars();

        try {
            $row = PlatformGlobalCostService::save($payload, (int)($user['id'] ?? 0));
            return new Response(200, [
                'status' => 'ok',
                'message' => !empty($payload['id']) ? 'Custo global atualizado.' : 'Custo global criado.',
                'data' => $row,
            ], 'application/json');
        } catch (\Throwable $e) {
            return new Response(422, [
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 'application/json');
        }
    }

    public static function updateStatus($request, $id): Response
    {
        if ($guard = self::guardSuperAdminJson()) {
            return $guard;
        }

        $user = SessionUser::getLogged();
        $payload = (array)$request->getPostVars();

        try {
            $row = PlatformGlobalCostService::updateStatus(
                (int)$id,
                !empty($payload['active']),
                (int)($user['id'] ?? 0)
            );

            return new Response(200, [
                'status' => 'ok',
                'message' => 'Status do custo global atualizado.',
                'data' => $row,
            ], 'application/json');
        } catch (\Throwable $e) {
            return new Response(422, [
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 'application/json');
        }
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
        $role = strtolower(trim((string)($user['function'] ?? $user['user_function'] ?? '')));
        return $role === 'super_admin';
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
                                <p class="mt-2 text-sm text-slate-500">A fonte oficial de custo global da plataforma só pode ser gerenciada pelo super administrador.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>';
    }
}
