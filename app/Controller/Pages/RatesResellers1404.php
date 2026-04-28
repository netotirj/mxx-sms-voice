<?php

namespace App\Controller\Pages;


use App\Http\Response;
use App\Model\Entity\UserSearch;
use App\Session\User as SessionUser;
use App\Model\Entity\Rates;
use App\Utils\View;

class RatesResellers1404 extends ViewComponents
{
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

        // Busca todos os resellers
        $usersResellers = UserSearch::getResellers($obUser['tenancy_id']);

        // Busca todas as tarifas do tenancy
        $ratesResellers = Rates::getRates($obUser['tenancy_id']);

        // Indexa tarifas por user_id para facilitar a junção
        $ratesByUser = [];
        foreach ($ratesResellers as $rate) {
            $ratesByUser[$rate['user_id']][] = $rate;
        }

        // Monta resultado final: cada reseller com suas tarifas
        $result = [];
        foreach ($usersResellers as $reseller) {
            $userId = $reseller['id'];
            $result[] = [
                'reseller_id'    => $userId,
                'reseller_name'  => $reseller['name'],
                'reseller_email' => $reseller['email'],
                'rates'          => $ratesByUser[$userId] ?? [] // pode ser vazio
            ];
        }

        return new Response(200, [
            'status' => 200,
            'data'   => $result
        ], 'application/json');
    }

    /**
     * Criar nova tarifa
     */
    public static function setNewRatesUsers($request): Response|array
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $ratesData = $request->getPostVars();

        //echo "<pre>";
        //print_r($ratesData);
        //echo "</pre>";exit();

        try {

            $userId    = (int) ($ratesData['usuario_id'] ?? 0);
            $rateType  = (string) ($ratesData['type'] ?? '');
            $rateName  = (string) ($ratesData['nome_tarifa'] ?? '');
            $rateValue = (float) ($ratesData['valor_tarifa'] ?? 0);

            if ($userId <= 0) {
                throw new \Exception("Usuário inválido.");
            }
            if ($rateType === '') {
                throw new \Exception("Tipo da tarifa é obrigatório.");
            }
            if (trim($rateName) === '') {
                throw new \Exception("Nome da tarifa é obrigatório.");
            }

            // Campos extras (só usados para service_fee)
            $serviceEvent = isset($ratesData['service_event']) ? (string) $ratesData['service_event'] : null;
            $serviceScope = isset($ratesData['service_scope']) ? (string) $ratesData['service_scope'] : null;

            if ($rateType === 'service_fee') {
                // Por enquanto só "answered"
                $allowedEvent = ['answered'];
                $allowedScope = ['reseller_trunks', 'all_trunks'];

                if (!$serviceEvent || !in_array($serviceEvent, $allowedEvent, true)) {
                    throw new \Exception("Evento de cobrança inválido.");
                }
                if (!$serviceScope || !in_array($serviceScope, $allowedScope, true)) {
                    throw new \Exception("Escopo da taxa inválido.");
                }
            } else {
                // qualquer outro tipo: força NULL no banco
                $serviceEvent = null;
                $serviceScope = null;
            }

            $tenancyId = $obUser['tenancy_id'];

            $rateId = Rates::createRate(
                $userId,
                $rateType,
                $rateName,
                $rateValue,
                $tenancyId,
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
                'message' => 'Erro ao criar tarifa: ' . $e->getMessage()
            ], 'application/json');
        }

    }

    /**
     * @param $request
     * @return Response|array
     */

    public static function setUpdateRatesUsers($request): Response|array
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $dataRates = $request->getPostVars();

        if (empty($dataRates['id'])) {
            return new Response(400, [
                'status' => 400,
                'message' => 'ID da tarifa não fornecido.'
            ], 'application/json');
        }

        $rateId = (int)$dataRates['id'];
        unset($dataRates['id']);

        // 🔎 descobrir o type efetivo (se não vier no POST, pega do banco)
        $incomingType = isset($dataRates['type']) ? (string)$dataRates['type'] : null;

        // você precisa de um método para pegar a tarifa atual
        // (se já existir, use o seu; aqui está o nome sugerido)
        $current = Rates::getRateById($rateId);
        if (!$current) {
            return new Response(404, [
                'status' => 404,
                'message' => 'Tarifa não encontrada.'
            ], 'application/json');
        }

        $effectiveType = $incomingType ?: (string)($current->type ?? $current['type'] ?? '');

        // Mapear os campos para o banco
        $fields = [];

        if (isset($dataRates['nome_tarifa']))  $fields['name'] = $dataRates['nome_tarifa'];
        if (isset($dataRates['type']))        $fields['type'] = $dataRates['type'];
        if (isset($dataRates['valor_tarifa'])) $fields['rate'] = $dataRates['valor_tarifa'];
        if (isset($dataRates['usuario_id']))  $fields['user_id'] = $dataRates['usuario_id'];

        // ✅ novos campos (service_fee)
        $serviceEvent = isset($dataRates['service_event']) ? (string)$dataRates['service_event'] : null;
        $serviceScope = isset($dataRates['service_scope']) ? (string)$dataRates['service_scope'] : null;

        if ($effectiveType === 'service_fee') {
            // por enquanto só answered
            $allowedEvent = ['answered'];
            $allowedScope = ['reseller_trunks', 'all_trunks'];

            // se não veio no POST, tenta manter o atual do banco
            $currentEvent = $current->service_event ?? $current['service_event'] ?? null;
            $currentScope = $current->service_scope ?? $current['service_scope'] ?? null;

            $finalEvent = $serviceEvent ?: $currentEvent;
            $finalScope = $serviceScope ?: $currentScope;

            if (!$finalEvent || !in_array($finalEvent, $allowedEvent, true)) {
                return new Response(400, [
                    'status' => 400,
                    'message' => 'Evento de cobrança inválido.'
                ], 'application/json');
            }

            if (!$finalScope || !in_array($finalScope, $allowedScope, true)) {
                return new Response(400, [
                    'status' => 400,
                    'message' => 'Escopo da taxa inválido.'
                ], 'application/json');
            }

            $fields['service_event'] = $finalEvent;
            $fields['service_scope'] = $finalScope;

        } else {
            // se mudou de service_fee para outro tipo, zera campos no banco
            $fields['service_event'] = null;
            $fields['service_scope'] = null;
        }

        if (empty($fields)) {
            return new Response(400, [
                'status' => 400,
                'message' => 'Nenhum campo para atualizar.'
            ], 'application/json');
        }

        try {
            $success = Rates::updateRate($rateId, $fields);

            if (!$success) {
                return new Response(404, [
                    'status' => 404,
                    'message' => 'Tarifa não encontrada.'
                ], 'application/json');
            }

            return new Response(200, [
                'status' => 200,
                'message' => 'Tarifa atualizada com sucesso.'
            ], 'application/json');

        } catch (\Exception $e) {
            return new Response(500, [
                'status' => 500,
                'message' => 'Erro ao atualizar tarifa: ' . $e->getMessage()
            ], 'application/json');
        }
    }



    /**
     * Deletar tarifa
     */
    public static function setDeleteRatesUsers($request, int $Id): Response|array
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $Id = (int)$Id;
        if ($Id <= 0) {
            return new Response(400, [
                'status' => 400,
                'message' => 'ID inválido.'
            ], 'application/json');
        }

        try {
            $success = Rates::deleteRate($Id, $obUser['tenancy_id']);

            if (!$success) {
                return new Response(404, [
                    'status' => 404,
                    'message' => 'Tarifa não encontrada.'
                ], 'application/json');
            }

            return new Response(200, [
                'status' => 200,
                'message' => 'Tarifa deletada com sucesso.'
            ], 'application/json');

        } catch (\Exception $e) {
            return new Response(500, [
                'status' => 500,
                'message' => 'Erro ao deletar tarifa: ' . $e->getMessage()
            ], 'application/json');
        }
    }


    public static function setStatusRatesUsers($request): Response
    {
        $obUser = SessionUser::getLogged();
        if (!$obUser) {
            return new Response(401, [
                'status' => 401,
                'message' => 'Usuário não autenticado.'
            ], 'application/json');
        }

        $dataStatus = $request->getPostVars();

        $rateId = isset($dataStatus['id']) ? (int)$dataStatus['id'] : 0;
        $status = isset($dataStatus['status']) ? strtolower(trim((string)$dataStatus['status'])) : '';

        if ($rateId <= 0) {
            return new Response(400, [
                'status' => 400,
                'message' => 'ID inválido.'
            ], 'application/json');
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            return new Response(400, [
                'status' => 400,
                'message' => 'Status inválido.'
            ], 'application/json');
        }

        try {
            $success = Rates::updateStatusRate($rateId, $obUser['tenancy_id'], $status);

            if (!$success) {
                return new Response(404, [
                    'status' => 404,
                    'message' => 'Rate não encontrada.'
                ], 'application/json');
            }

            return new Response(200, [
                'status' => 200,
                'message' => 'Status atualizado com sucesso.'
            ], 'application/json');

        } catch (\Exception $e) {
            return new Response(500, [
                'status' => 500,
                'message' => 'Erro ao atualizar o status: ' . $e->getMessage()
            ], 'application/json');
        }
    }


}