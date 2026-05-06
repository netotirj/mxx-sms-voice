<?php

namespace App\Http\Middleware;

use App\Model\Entity\RegisterTenancies;
use App\Session\User as SessionLogin;
use App\Model\Entity\PermissionsRules;
use App\Model\Entity\UserPlans;
use App\Http\Response;
use App\Utils\TenancyHelper;
use Closure;

class PermissionMiddleware
{
    /**
     * Rotas que serão controladas por permissão de plano
     */
    private array $planRestrictedRoutes = [
        '/users'           => 'can_users',
        '/users/new'       => 'can_create_users',
        '/rates'           => 'can_rates',
        '/permissions'     => 'can_permissions',
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
        $userFunc   = $obUser['user_function'] ?? $obUser['function'] ?? 'user';

        // Identificação dinâmica da URL Base para redirecionamentos via JS
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
        $baseUrl  = $protocol . "://" . $_SERVER['HTTP_HOST'];

        if (!$userId || !$tenancyId) {
            return new Response(401, ['error' => 'Usuário não autenticado']);
        }

        // 2️⃣ Se for SUPER ADMIN → ignora todas as restrições
        if (TenancyHelper::isSuperAdmin($obUser)) {
            return $next($request);
        }

        // 3️⃣ Nome da rota atual
        $routeName = $request->getRouteName();
        if (!$routeName) {
            return new Response(400, ['error' => 'Rota não informada']);
        }

        // Normaliza rota (remove IDs numéricos)
        $routeName = preg_replace('/\/\d+/', '/{id}', $routeName);

        // 4️⃣ Verifica permissão padrão do usuário (ACL básica)
        if (!PermissionsRules::userCanAccessRoute($userId, $routeName, $tenancyId)) {
            $this->redirectWithFlash($baseUrl, 'Permissão de acesso negada!');
        }

        // 5️⃣ Verifica se a rota é restrita por plano contratado
        if (isset($this->planRestrictedRoutes[$routeName])) {
            $permissionKey = $this->planRestrictedRoutes[$routeName];

            // 🔄 Pega o plano ativo da TENANCY
            $activePlanId = RegisterTenancies::getActivePlanId($tenancyId);
            if (!$activePlanId) {
                $this->redirectWithFlash($baseUrl, 'Nenhum plano ativo encontrado!');
            }

            // 🔍 Detalhes do plano ativo
            $userPlan = UserPlans::getUserPlanInfoByPlanId($activePlanId, $tenancyId);
            if (!$userPlan || strtolower($userPlan->status_payment) !== 'confirmed') {
                $this->redirectWithFlash($baseUrl, 'Plano inválido ou não confirmado!');
            }

            // 6️⃣ Matriz de permissões por nível de plano
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

            // Nome do plano normalizado para dar match na matriz
            $planName = strtolower(trim($userPlan->name_plan));
            $permissions = $planPermissions[$planName] ?? [];

            // 🔒 Bloqueia se o plano não possuir a chave de permissão necessária
            if (empty($permissions[$permissionKey])) {
                $this->redirectWithFlash($baseUrl, 'Seu plano atual não permite esta ação!');
            }
        }

        // 🔓 Tudo OK → segue para o controlador
        return $next($request);
    }

    /**
     * Método auxiliar para disparar o JavaScript de erro e redirecionar
     */
    /**
     * Método auxiliar para disparar o JavaScript de erro e redirecionar
     */
    private function redirectWithFlash($baseUrl, $message)
    {
        // 1. Tenta pegar a URL do .env. Se não existir, usa a baseUrl detectada
        // Se no .env está http://localhost/sms, ele vai usar isso.
        $envUrl = getenv('URL') ?: getenv('BASE_URL') ?: $baseUrl;

        // 2. Remove qualquer barra no final para não duplicar (ex: http://localhost/sms/)
        $envUrl = rtrim($envUrl, '/');

        echo "<script>
        // Grava o erro no localStorage
                localStorage.setItem('flash_error', '{$message}');
                
                // Redireciona para a URL completa: http://localhost/sms/dashboard
                window.location.href = '{$envUrl}/dashboard';
             </script>";
        exit;
    }
}
