<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Service\PlatformConsumptionDashboardService;
use App\Session\User as SessionUser;
use App\Utils\View;

class PlatformConsumptionDashboard extends ViewComponents
{
    public static function getPage(): string
    {
        PlatformConsumptionDashboardService::ensureRouteCatalog();

        if (!self::isCurrentUserSuperAdmin()) {
            return parent::getComponentsUsers('Maxx Solutions | Dashboard Financeiro Global', self::renderAccessDenied());
        }

        return parent::getComponentsUsers(
            'Maxx Solutions | Dashboard Financeiro Global',
            View::render('/platform-consumption/index', [])
        );
    }

    public static function data($request): Response
    {
        if ($guard = self::guardSuperAdminJson()) {
            return $guard;
        }

        $query = method_exists($request, 'getQueryParams') ? (array)$request->getQueryParams() : ($_GET ?? []);

        return new Response(200, [
            'status' => 'ok',
            'data' => PlatformConsumptionDashboardService::dashboard($query),
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
                                <p class="mt-2 text-sm text-slate-500">O dashboard consolidado de consumo da plataforma é exclusivo do super administrador.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>';
    }
}
