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
        $tenancyId = (string)($obUser['tenancy_id'] ?? '');

        if ($tenancyId === '' || (string)($rate['tenancy_id'] ?? '') !== $tenancyId) {
            return false;
        }

        if (in_array($role, ['admin', 'super_admin'])) {
            return true;
        }

        return false;
    }

    private static function isAdminUser(array $obUser): bool
    {
        return in_array($obUser['function'] ?? '', ['admin', 'super_admin'], true);
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

        $whatsAppRatesByReseller = [];
        foreach (Rates::getWhatsAppCategoryRates($tenancyId, $filterId) as $rate) {
            $resellerId = (int)$rate['reseller_id'];
            $whatsAppRatesByReseller[$resellerId][$rate['category']] = $rate;
        }

        $result = [];
        foreach ($usersResellers as $reseller) {
            $userId = $reseller['id'];
            if (!empty($whatsAppRatesByReseller[$userId])) {
                $ratesByUser[$userId][] = self::buildWhatsAppCategoryRateRow($userId, $tenancyId, $whatsAppRatesByReseller[$userId]);
            }

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

    private static function buildWhatsAppCategoryRateRow(int $resellerId, string $tenancyId, array $rows): array
    {
        $prices = [
            'marketing' => 0,
            'utility' => 0,
            'authentication' => 0,
        ];
        $status = 'active';
        $updatedAt = null;

        foreach ($prices as $category => $_) {
            if (!isset($rows[$category])) {
                continue;
            }

            $prices[$category] = round((float)$rows[$category]['price_brl'], 4);
            if (($rows[$category]['status'] ?? 'active') === 'inactive') {
                $status = 'inactive';
            }
            $updatedAt = max((string)($updatedAt ?? ''), (string)($rows[$category]['updated_at'] ?? ''));
        }

        return [
            'id' => 'wa_' . $resellerId,
            'user_id' => $resellerId,
            'tenancy_id' => $tenancyId,
            'type' => 'whatsapp',
            'name' => 'WhatsApp por template',
            'rate' => $prices['marketing'],
            'status' => $status,
            'created_at' => $updatedAt ?: date('Y-m-d H:i:s'),
            'updated_at' => $updatedAt ?: date('Y-m-d H:i:s'),
            'managed_kind' => 'whatsapp_categories',
            'whatsapp_category_rates' => $prices,
        ];
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

            if (!self::isAdminUser($obUser)) {
                return new Response(403, [
                    'status' => 403,
                    'message' => 'Sem permissão para criar tarifa.'
                ], 'application/json');
            }

            $userId = (int) ($ratesData['usuario_id'] ?? 0);

            $rateType  = (string) ($ratesData['type'] ?? '');
            $rateName  = (string) ($ratesData['nome_tarifa'] ?? '');
            $rateValue = self::parseRateValue($ratesData['valor_tarifa'] ?? 0);

            if ($userId <= 0) throw new \Exception("Usuário inválido.");
            if (!self::isResellerInTenancy($obUser['tenancy_id'], $userId)) {
                throw new \Exception("Revendedor inválido para esta empresa.");
            }
            if ($rateType === '') throw new \Exception("Tipo da tarifa é obrigatório.");
            if (!self::isAllowedRateType($rateType)) throw new \Exception("Tipo da tarifa inválido.");
            if (trim($rateName) === '') throw new \Exception("Nome da tarifa é obrigatório.");

            if ($rateType === 'whatsapp') {
                Rates::upsertWhatsAppCategoryRates(
                    $obUser['tenancy_id'],
                    $userId,
                    self::extractWhatsAppCategoryPrices($ratesData)
                );

                return new Response(200, [
                    'status'  => 200,
                    'message' => 'Tarifas WhatsApp por template criadas com sucesso.',
                    'rate-id' => 'wa_' . $userId
                ], 'application/json');
            }

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

        $rawRateId = (string)($dataRates['id'] ?? '');
        $rateId = (int)$rawRateId;

        if (($dataRates['type'] ?? '') === 'whatsapp') {
            if (!self::isAdminUser($obUser)) {
                return new Response(403, [
                    'status' => 403,
                    'message' => 'Sem permissão para editar tarifa.'
                ], 'application/json');
            }

            $resellerId = self::resolveWhatsAppRateResellerId($obUser, $dataRates, $rawRateId);

            if ($resellerId <= 0) {
                return new Response(422, [
                    'status' => 422,
                    'message' => 'Revendedor inválido para tarifa WhatsApp.'
                ], 'application/json');
            }

            if (!self::isResellerInTenancy($obUser['tenancy_id'], $resellerId)) {
                return new Response(422, [
                    'status' => 422,
                    'message' => 'Revendedor inválido para esta empresa.'
                ], 'application/json');
            }

            Rates::upsertWhatsAppCategoryRates(
                $obUser['tenancy_id'],
                $resellerId,
                self::extractWhatsAppCategoryPrices($dataRates)
            );

            return new Response(200, [
                'status' => 200,
                'message' => 'Tarifas WhatsApp por template atualizadas.'
            ], 'application/json');
        }

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
        if (isset($dataRates['type'])) {
            if (!self::isAllowedRateType((string)$dataRates['type'])) {
                return new Response(422, [
                    'status' => 422,
                    'message' => 'Tipo da tarifa inválido.'
                ], 'application/json');
            }
            $fields['type'] = $dataRates['type'];
        }
        if (isset($dataRates['valor_tarifa'])) $fields['rate'] = self::parseRateValue($dataRates['valor_tarifa']);

        $success = Rates::updateRate($rateId, $fields, $obUser['tenancy_id']);

        return new Response(200, [
            'status' => 200,
            'message' => 'Atualizado com sucesso.'
        ], 'application/json');
    }

    private static function extractWhatsAppCategoryPrices(array $data): array
    {
        $fallback = (float)($data['valor_tarifa'] ?? 0);

        return [
            'marketing' => self::parseRateValue($data['whatsapp_marketing'] ?? $fallback),
            'utility' => self::parseRateValue($data['whatsapp_utility'] ?? $fallback),
            'authentication' => self::parseRateValue($data['whatsapp_authentication'] ?? $fallback),
        ];
    }

    private static function parseRateValue(mixed $value): float
    {
        if (is_numeric($value)) {
            return round(max(0, (float)$value), 4);
        }

        $normalized = str_replace(['.', ','], ['', '.'], trim((string)$value));
        return round(max(0, (float)$normalized), 4);
    }

    private static function isAllowedRateType(string $type): bool
    {
        return in_array($type, ['sms', 'whatsapp', 'voice', 'torpedo', 'service_fee'], true);
    }

    private static function isAllowedStatus(string $status): bool
    {
        return in_array($status, ['active', 'inactive'], true);
    }

    private static function resolveWhatsAppRateResellerId(array $obUser, array $data, string $rawRateId): int
    {
        if (($obUser['function'] ?? '') === 'reseller') {
            return (int)$obUser['id'];
        }

        if (preg_match('/^wa_(\d+)$/', $rawRateId, $matches)) {
            return (int)$matches[1];
        }

        return (int)($data['usuario_id'] ?? 0);
    }

    private static function isResellerInTenancy(string $tenancyId, int $userId): bool
    {
        foreach (UserSearch::getResellers($tenancyId, $userId) as $reseller) {
            if ((int)($reseller['id'] ?? 0) === $userId) {
                return true;
            }
        }

        return false;
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
    public static function setDeleteRatesUsers($request, mixed $Id): Response|array
    {
        $obUser = SessionUser::getLogged();
        $rawId = (string)$Id;

        if (preg_match('/^wa_(\d+)$/', $rawId, $matches)) {
            if (!self::isAdminUser($obUser)) {
                return new Response(403, [
                    'status' => 403,
                    'message' => 'Sem permissão para deletar.'
                ], 'application/json');
            }

            if (!self::isResellerInTenancy($obUser['tenancy_id'], (int)$matches[1])) {
                return new Response(422, [
                    'status' => 422,
                    'message' => 'Revendedor inválido para esta empresa.'
                ], 'application/json');
            }

            Rates::deleteWhatsAppCategoryRates($obUser['tenancy_id'], (int)$matches[1]);

            return new Response(200, [
                'status' => 200,
                'message' => 'Tarifa WhatsApp deletada com sucesso.'
            ], 'application/json');
        }

        $rateId = (int)$rawId;
        $current = Rates::getRateById($rateId);

        if (!$current || !self::canManageRate($obUser, (array)$current)) {
            return new Response(403, [
                'status' => 403,
                'message' => 'Sem permissão para deletar.'
            ], 'application/json');
        }

        Rates::deleteRate($rateId, $obUser['tenancy_id']);

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

        $rawRateId = (string)($data['id'] ?? '');
        $rateId = (int)$rawRateId;
        $status = (string)($data['status'] ?? '');

        if (!self::isAllowedStatus($status)) {
            return new Response(422, [
                'status' => 422,
                'message' => 'Status inválido.'
            ], 'application/json');
        }

        if (preg_match('/^wa_(\d+)$/', $rawRateId, $matches)) {
            $resellerId = (int)$matches[1];
            if (!self::isAdminUser($obUser)) {
                return new Response(403, [
                    'status' => 403,
                    'message' => 'Sem permissão.'
                ], 'application/json');
            }

            if (!self::isResellerInTenancy($obUser['tenancy_id'], $resellerId)) {
                return new Response(422, [
                    'status' => 422,
                    'message' => 'Revendedor inválido para esta empresa.'
                ], 'application/json');
            }

            Rates::updateWhatsAppCategoryRatesStatus($obUser['tenancy_id'], $resellerId, $status);

            return new Response(200, [
                'status' => 200,
                'message' => 'Status WhatsApp atualizado.'
            ], 'application/json');
        }

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
