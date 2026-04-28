<?php

namespace App\Controller\Pages;

use App\Http\Response;
use App\Model\Entity\UserSearch;
use App\Session\User as SessionUser;
use App\Model\Entity\Rates;
use App\Utils\View;

class RatesResellers extends ViewComponents
{
    // ==========================================================
    // 🔐 PERMISSÃO CENTRAL
    // ==========================================================
    private static function canManageRate(array $obUser, array $rate): bool
    {
        $role = $obUser['function'] ?? '';

        if (in_array($role, ['admin', 'super_admin'])) {
            return true;
        }

        if ($role === 'reseller') {
            return (int)$rate['user_id'] === (int)$obUser['id'];
        }

        return false;
    }

    public static function getRates(): Response|string
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $content = View::render('/rates/index', []);
        return parent::getComponentsUsers('Maxx Solutions - SMS | Users', $content);
    }

    public static function getAllRatesUsers(): Response|array
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $tenancyId = $obUser['tenancy_id'];
        $role      = $obUser['function'] ?? 'agent';

        $filterId = ($role === 'admin' || $role === 'super_admin') ? null : (int)$obUser['id'];

        $usersResellers = UserSearch::getResellers($tenancyId, $filterId);
        $ratesResellers = Rates::getRates($tenancyId, $filterId);

        $ratesByUser = [];
        foreach ($ratesResellers as $rate) {
            $ratesByUser[$rate['user_id']][] = $rate;
        }

        $result = [];
        foreach ($usersResellers as $reseller) {
            $userId = $reseller['id'];
            $result[] = [
                'reseller_id'    => $userId,
                'reseller_name'  => $reseller['name'],
                'reseller_email' => $reseller['email'],
                'rates'          => $ratesByUser[$userId] ?? []
            ];
        }

        return new Response(200, [
            'status' => 200,
            'data'   => $result,
            'meta'   => [
                'role' => $role,
                'id'   => $obUser['id']
            ]
        ], 'application/json');
    }

    // ==========================================================
    // CREATE
    // ==========================================================
    public static function setNewRatesUsers($request): Response|array
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, ['status' => 401, 'message' => 'Usuário não autenticado.'], 'application/json');
        }

        $ratesData = $request->getPostVars();

        try {

            $role = $obUser['function'] ?? '';

            // 🔒 TRAVA: reseller só cria para ele mesmo
            if ($role === 'reseller') {
                $userId = (int)$obUser['id'];
            } else {
                $userId = (int) ($ratesData['usuario_id'] ?? 0);
            }

            $rateType  = (string) ($ratesData['type'] ?? '');
            $rateName  = (string) ($ratesData['nome_tarifa'] ?? '');
            $rateValue = (float) ($ratesData['valor_tarifa'] ?? 0);

            if ($userId <= 0) throw new \Exception("Usuário inválido.");
            if ($rateType === '') throw new \Exception("Tipo da tarifa é obrigatório.");
            if (trim($rateName) === '') throw new \Exception("Nome da tarifa é obrigatório.");

            $serviceEvent = $ratesData['service_event'] ?? null;
            $serviceScope = $ratesData['service_scope'] ?? null;

            if ($rateType === 'service_fee') {
                $allowedEvent = ['answered'];
                $allowedScope = ['reseller_trunks', 'all_trunks'];

                if (!in_array($serviceEvent, $allowedEvent, true)) {
                    throw new \Exception("Evento inválido.");
                }
                if (!in_array($serviceScope, $allowedScope, true)) {
                    throw new \Exception("Escopo inválido.");
                }
            } else {
                $serviceEvent = null;
                $serviceScope = null;
            }

            $rateId = Rates::createRate(
                $userId,
                $rateType,
                $rateName,
                $rateValue,
                $obUser['tenancy_id'],
                $serviceEvent,
                $serviceScope
            );

            return new Response(200, [
                'status'  => 200,
                'message' => 'Tarifa criada com sucesso.',
                'rate-id' => $rateId
            ], 'application/json');

        } catch (\Exception $e) {
            return new Response(500, [
                'status'  => 500,
                'message' => $e->getMessage()
            ], 'application/json');
        }
    }

    // ==========================================================
    // UPDATE
    // ==========================================================
    public static function setUpdateRatesUsers($request): Response|array
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, ['status' => 401, 'message' => 'Usuário não autenticado.'], 'application/json');
        }

        $dataRates = $request->getPostVars();

        $rateId = (int)$dataRates['id'] ?? 0;
        $current = Rates::getRateById($rateId);

        if (!$current || !self::canManageRate($obUser, (array)$current)) {
            return new Response(403, [
                'status' => 403,
                'message' => 'Sem permissão para editar.'
            ], 'application/json');
        }

        unset($dataRates['id']);

        $fields = [];
        if (isset($dataRates['nome_tarifa'])) $fields['name'] = $dataRates['nome_tarifa'];
        if (isset($dataRates['type'])) $fields['type'] = $dataRates['type'];
        if (isset($dataRates['valor_tarifa'])) $fields['rate'] = $dataRates['valor_tarifa'];

        $success = Rates::updateRate($rateId, $fields);

        return new Response(200, [
            'status' => 200,
            'message' => 'Atualizado com sucesso.'
        ], 'application/json');
    }

    public static function getUsersForRateSelect(): Response|array
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $tenancyId = $obUser['tenancy_id'];
        $role      = $obUser['function'] ?? 'agent';
        $userId    = (int)$obUser['id'];

        try {
            if (in_array($role, ['admin', 'super_admin'], true)) {
                $users = UserSearch::getResellers($tenancyId, null);
            } elseif ($role === 'reseller') {
                $users = UserSearch::getUsersByReseller($tenancyId, $userId);
            } else {
                $users = [];
            }

            return new Response(200, [
                'status' => 200,
                'data'   => $users,
                'meta'   => [
                    'role' => $role,
                    'id'   => $userId
                ]
            ], 'application/json');

        } catch (\Exception $e) {
            return new Response(500, [
                'status' => 500,
                'message' => 'Erro ao buscar usuários: ' . $e->getMessage()
            ], 'application/json');
        }
    }

    // ==========================================================
    // DELETE
    // ==========================================================
    public static function setDeleteRatesUsers($request, int $Id): Response|array
    {
        $obUser = SessionUser::getLogged();

        $current = Rates::getRateById($Id);

        if (!$current || !self::canManageRate($obUser, (array)$current)) {
            return new Response(403, [
                'status' => 403,
                'message' => 'Sem permissão para deletar.'
            ], 'application/json');
        }

        Rates::deleteRate($Id, $obUser['tenancy_id']);

        return new Response(200, [
            'status' => 200,
            'message' => 'Deletado com sucesso.'
        ], 'application/json');
    }

    // ==========================================================
    // STATUS
    // ==========================================================
    public static function setStatusRatesUsers($request): Response
    {
        $obUser = SessionUser::getLogged();
        $data = $request->getPostVars();

        $rateId = (int)$data['id'];
        $status = $data['status'];

        $current = Rates::getRateById($rateId);

        if (!$current || !self::canManageRate($obUser, (array)$current)) {
            return new Response(403, [
                'status' => 403,
                'message' => 'Sem permissão.'
            ], 'application/json');
        }

        Rates::updateStatusRate($rateId, $obUser['tenancy_id'], $status);

        return new Response(200, [
            'status' => 200,
            'message' => 'Status atualizado.'
        ], 'application/json');
    }
}