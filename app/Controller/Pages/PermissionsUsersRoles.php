<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Session\User as SessionUser;
use  App\Model\Entity\PermissionsRules;
use App\Utils\View;

class PermissionsUsersRoles extends ViewComponents
{
    public static function getPermissions()
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/permissions/index', []);
        return parent::getComponentsUsers('Maxx Solutions - SMS | Users', $content);
    }

    public static function getAllPermissionsUsers($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status'  => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        // Busca permissões padrão por role default
        $obPermissionsData = PermissionsRules::getDefaultRolePermissions();

        // Resposta padronizada em JSON
        return new Response(200, [
            'status' => 200,
            'data'   => $obPermissionsData
        ], 'application/json');
    }

    public static function getAllPermissionsUsersId($request,$id): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status'  => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        // Busca permissões padrão por role default
        $obPermissionsUsersData = PermissionsRules::getDefaultRolePermissionsById($id, $obUser['tenancy_id']);

        // Resposta padronizada em JSON
        return new Response(200, [
            'status' => 200,
            'data'   => $obPermissionsUsersData
        ], 'application/json');
    }

    public static function setPermissionsUsersId($request, int $id): Response
    {
        try {
            // 🔐 Usuário logado
            $obUser = SessionUser::getLogged();
            if (!$obUser) {
                return new Response(
                    401,
                    json_encode([
                        'status'  => 'error',
                        'message' => 'Usuário não autenticado.'
                    ]),
                    'application/json'
                );
            }

            // 🔹 Dados da sessão
            $tenancyId = $obUser['tenancy_id'] ?? null;
            $userId    = $obUser['id'] ?? null;

            // 🔹 Lê os dados do corpo da requisição (JSON do fetch)
            $data = $request->getPostVars();

            $permissionId = $data['permission_index'] ?? null; // id da role_default_permissions
            $permissionRouter = $data['permission_router'] ?? null;
            $enabled      = !empty($data['active']) ? 1 : 0;

            if (!$permissionId) {
                return new Response(
                    422,
                    json_encode([
                        'status'  => 'error',
                        'message' => 'Parâmetro permission_index é obrigatório.'
                    ]),
                    'application/json'
                );
            }

            // 🔹 Chama a Entity para salvar override
            PermissionsRules::saveOverride(
                (string)$tenancyId,
                (int)$userId,
                (int)$id,
                (string)$permissionRouter,
                (int)$enabled
            );

            return new Response(
                200,
                json_encode([
                    'status'        => 'ok',
                    'role_id'       => $id,
                    'permission_id' => $permissionId,
                    'enabled'       => $enabled
                ]),
                'application/json'
            );

        } catch (\Exception $e) {
            return new Response(
                500,
                json_encode([
                    'status'  => 'error',
                    'message' => $e->getMessage()
                ]),
                'application/json'
            );
        }
    }




}