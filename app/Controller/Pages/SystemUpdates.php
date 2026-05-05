<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Model\Entity\SystemUpdate;
use App\Session\User as SessionUser;
use App\Utils\View;

class SystemUpdates extends ViewComponents
{
    public static function getComponentsSystemUpdates(): Response|string
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!self::canManage($user)) {
            return new Response(403, 'Sem permissão para acessar atualizações do sistema.');
        }

        $content = View::render('/system-updates/index', []);
        return parent::getComponentsUsers('Maxx Solutions - Atualizações', $content);
    }

    public static function list($request): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        return self::json(200, [
            'success' => true,
            'data' => self::canManage($user) ? SystemUpdate::listForAdmin($user) : SystemUpdate::listForHeader($user),
            'can_manage' => self::canManage($user),
        ]);
    }

    public static function headerList($request): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        return self::json(200, [
            'success' => true,
            'data' => SystemUpdate::listForHeader($user),
        ]);
    }

    public static function users($request): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!self::canManage($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        return self::json(200, [
            'success' => true,
            'data' => SystemUpdate::listTargetUsers($user),
        ]);
    }

    public static function create(): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!self::canManage($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        try {
            $id = SystemUpdate::create($user, self::jsonInput());
            return self::json(201, ['success' => true, 'id' => $id, 'message' => 'Atualização criada.']);
        } catch (\Throwable $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function update($request, int|string $id): Response
    {
        $user = self::requireUser();
        if ($user instanceof Response) {
            return $user;
        }

        if (!self::canManage($user)) {
            return self::json(403, ['success' => false, 'message' => 'Sem permissão.']);
        }

        try {
            $ok = SystemUpdate::update($user, (int)$id, self::jsonInput());
            return self::json($ok ? 200 : 404, [
                'success' => $ok,
                'message' => $ok ? 'Atualização salva.' : 'Atualização não encontrada.',
            ]);
        } catch (\Throwable $e) {
            return self::json(422, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private static function canManage(array $user): bool
    {
        $role = strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));
        return in_array($role, ['super_admin', 'admin', 'developer'], true);
    }

    private static function requireUser(): array|Response
    {
        $user = SessionUser::getLogged();
        if (!$user) {
            return self::json(401, ['success' => false, 'message' => 'Usuário não autenticado.']);
        }

        return $user;
    }

    private static function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        return is_array($data) ? $data : ($_POST ?: []);
    }

    private static function json(int $status, array $payload): Response
    {
        return new Response($status, $payload, 'application/json');
    }
}
