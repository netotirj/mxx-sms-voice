<?php

namespace App\Http\Middleware;

use App\Model\Entity\RegisterTenancies;
use App\Session\User as SessionLogin;
use App\Model\Entity\PermissionsRules;
use App\Model\Entity\UserPlans;
use App\Http\Response;
use Closure;

class PermissionMiddleware
{
    /**
     * Rotas que serão controladas por permissão de plano
     */
    private array $planRestrictedRoutes = [
        '/users'         => 'can_users',
        '/users/new'     => 'can_create_users',
        '/rates'         => 'can_rates',
        '/permissions'   => 'can_permissions',
    ];

    /**
     * Manipula a verificação de permissões e planos
     */
    public function handle($request, Closure $next)
    {
        // 1️⃣ Usuário logado
        $obUser     = SessionLogin::getLogged();
        $userId     = $obUser['id']          ?? null;
        $tenancyId  = $obUser['tenancy_id']  ?? null;
        $userFunc   = $obUser['function']    ?? 'user';

        if (!$userId || !$tenancyId) {
            return new Response(401, ['error' => 'Usuário não autenticado']);
        }

        // 2️⃣ Se for SUPER ADMIN → ignora todas as restrições
        if ($userFunc === 'super_admin') {
            return $next($request);
        }

        // 3️⃣ Nome da rota atual
        $routeName = $request->getRouteName();
        if (!$routeName) {
            return new Response(400, ['error' => 'Rota não informada']);
        }

        // Normaliza rota (remove IDs numéricos)
        $routeName = preg_replace('/\/\d+/', '/{id}', $routeName);

        // 4️⃣ Verifica permissão padrão do usuário
        if (!PermissionsRules::userCanAccessRoute($userId, $routeName, $tenancyId)) {
            echo "<script>
                localStorage.setItem('flash_error', 'Permissão de acesso negada!');
                window.location.href = '/dashboard';
            </script>";
            exit;
        }

        // 5️⃣ Verifica se a rota é restrita por plano
        if (isset($this->planRestrictedRoutes[$routeName])) {
            $permissionKey = $this->planRestrictedRoutes[$routeName];

            // 🔄 Pega o plano ativo da TENANCY
            $activePlanId = RegisterTenancies::getActivePlanId($tenancyId);
            if (!$activePlanId) {
                echo "<script>
                    localStorage.setItem('flash_error', 'Nenhum plano ativo encontrado!');
                    window.location.href = '/dashboard';
                </script>";
                exit;
            }

            // 🔍 Detalhes do plano ativo
            $userPlan = UserPlans::getUserPlanInfoByPlanId($activePlanId, $tenancyId);
            if (!$userPlan || strtolower($userPlan->status_payment) !== 'confirmed') {
                echo "<script>
                    localStorage.setItem('flash_error', 'Plano inválido ou não confirmado!');
                    window.location.href = '/dashboard';
                </script>";
                exit;
            }

            // 6️⃣ Permissões por plano
            $planPermissions = [
                'basic' => [
                    'can_users'        => false,
                    'can_create_users' => false,
                    'can_rates'        => false,
                    'can_permissions'  => false,
                ],
                'standard' => [
                    'can_users'        => true,
                    'can_create_users' => true,
                    'can_rates'        => true,
                    'can_permissions'  => true,
                ],
                'plus' => [
                    'can_users'        => true,
                    'can_create_users' => true,
                    'can_rates'        => true,
                    'can_permissions'  => true,
                ],
                'basic - users' => [
                    'can_users'        => true,
                    'can_create_users' => true,
                    'can_rates'        => true,
                    'can_permissions'  => true,
                ],
            ];

            // Nome do plano normalizado
            $planName = strtolower(trim($userPlan->name_plan));
            $permissions = $planPermissions[$planName] ?? [];

            // 🔒 Bloqueia se o plano não permitir a ação
            if (empty($permissions[$permissionKey])) {
                echo "<script>
                    localStorage.setItem('flash_error', 'Seu plano não permite executar esta ação!');
                    window.location.href = '/dashboard';
                </script>";
                exit;
            }
        }

        // 🔓 Tudo OK → continua o fluxo
        return $next($request);
    }
}






