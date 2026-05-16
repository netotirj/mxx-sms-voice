<?php

namespace App\Service;

use App\Model\Entity\RegisterTenancies;
use WilliamCosta\DatabaseManager\Database;

class FinancialHierarchyResolver
{
    public const CHAIN_ADMIN = 'admin_self';
    public const CHAIN_RESELLER = 'reseller_self';
    public const CHAIN_CLIENT_ADMIN = 'client_of_admin';
    public const CHAIN_CLIENT_RESELLER = 'client_of_reseller';

    public static function resolveContext(int $actorUserId, string $tenancyId): FinancialHierarchyContext
    {
        if ($actorUserId <= 0 || trim($tenancyId) === '') {
            throw new \InvalidArgumentException('Contexto hierarquico financeiro invalido.');
        }

        $db = new Database();
        $actor = $db->execute(
            "SELECT id, user_id, user_function
             FROM users
             WHERE id = :id
               AND tenancy_id = :tenancy_id
             LIMIT 1",
            [
                ':id' => $actorUserId,
                ':tenancy_id' => $tenancyId,
            ]
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$actor) {
            throw new \RuntimeException('Ator financeiro nao encontrado na tenancy informada.');
        }

        $ownerAdminId = (int)(RegisterTenancies::getTenancyOwnerUserId($tenancyId) ?? 0);
        if ($ownerAdminId <= 0) {
            throw new \RuntimeException('Administrador owner da tenancy nao encontrado.');
        }

        $actorRole = strtolower(trim((string)($actor['user_function'] ?? '')));
        $parentUserId = !empty($actor['user_id']) ? (int)$actor['user_id'] : null;
        $resellerId = null;

        if ($actorRole === 'reseller') {
            $resellerId = (int)$actor['id'];
        } elseif ($parentUserId) {
            $parent = $db->execute(
                "SELECT id, user_function
                 FROM users
                 WHERE id = :id
                   AND tenancy_id = :tenancy_id
                 LIMIT 1",
                [
                    ':id' => $parentUserId,
                    ':tenancy_id' => $tenancyId,
                ]
            )->fetch(\PDO::FETCH_ASSOC);

            if ($parent && strtolower(trim((string)($parent['user_function'] ?? ''))) === 'reseller') {
                $resellerId = (int)$parent['id'];
            }
        }

        $isAdminActor = $actorRole === 'admin' || (int)$actor['id'] === $ownerAdminId;
        $chainType = self::CHAIN_CLIENT_ADMIN;

        if ($isAdminActor) {
            $chainType = self::CHAIN_ADMIN;
        } elseif ($actorRole === 'reseller') {
            $chainType = self::CHAIN_RESELLER;
        } elseif ($resellerId) {
            $chainType = self::CHAIN_CLIENT_RESELLER;
        }

        $context = FinancialHierarchyContext::fromArray([
            'actor_user_id' => (int)$actor['id'],
            'actor_role' => $actorRole,
            'tenancy_id' => $tenancyId,
            'owner_admin_id' => $ownerAdminId,
            'parent_user_id' => $parentUserId,
            'reseller_id' => $resellerId,
            'actor_has_wallet' => self::hasTenancyWallet((int)$actor['id'], $tenancyId),
            'owner_has_wallet' => self::hasTenancyWallet($ownerAdminId, $tenancyId),
            'reseller_has_wallet' => $resellerId ? self::hasResellerWallet($resellerId, $tenancyId) : false,
            'chain_type' => $chainType,
            'billing_chain' => self::buildBillingChain($chainType, (int)$actor['id'], $resellerId, $ownerAdminId),
        ]);

        return $context;
    }

    public static function currentWalletBalance(string $wallet, int $userId, string $tenancyId): float
    {
        $wallet = strtolower(trim($wallet));
        if ($wallet === FinancialTransactionService::WALLET_RESELLER) {
            $row = (new Database())->execute(
                "SELECT reseller_balance
                 FROM users
                 WHERE id = :id
                   AND tenancy_id = :tenancy_id
                 LIMIT 1",
                [
                    ':id' => $userId,
                    ':tenancy_id' => $tenancyId,
                ]
            )->fetch(\PDO::FETCH_ASSOC);

            return round((float)($row['reseller_balance'] ?? 0), 4);
        }

        $row = (new Database())->execute(
            "SELECT balance
             FROM tenancy_balance
             WHERE user_id = :user_id
               AND tenancy_id = :tenancy_id
             ORDER BY updated_at DESC, id DESC
             LIMIT 1",
            [
                ':user_id' => $userId,
                ':tenancy_id' => $tenancyId,
            ]
        )->fetch(\PDO::FETCH_ASSOC);

        return round((float)($row['balance'] ?? 0), 4);
    }

    public static function hasTenancyWallet(int $userId, string $tenancyId): bool
    {
        if ($userId <= 0 || trim($tenancyId) === '') {
            return false;
        }

        $row = (new Database())->execute(
            "SELECT id
             FROM tenancy_balance
             WHERE user_id = :user_id
               AND tenancy_id = :tenancy_id
             ORDER BY updated_at DESC, id DESC
             LIMIT 1",
            [
                ':user_id' => $userId,
                ':tenancy_id' => $tenancyId,
            ]
        )->fetchColumn();

        return !empty($row);
    }

    public static function hasResellerWallet(int $userId, string $tenancyId): bool
    {
        if ($userId <= 0 || trim($tenancyId) === '') {
            return false;
        }

        $row = (new Database())->execute(
            "SELECT id
             FROM users
             WHERE id = :id
               AND tenancy_id = :tenancy_id
               AND user_function = 'reseller'
             LIMIT 1",
            [
                ':id' => $userId,
                ':tenancy_id' => $tenancyId,
            ]
        )->fetchColumn();

        return !empty($row);
    }

    private static function buildBillingChain(string $chainType, int $actorUserId, ?int $resellerId, int $ownerAdminId): array
    {
        return match ($chainType) {
            self::CHAIN_ADMIN => [$actorUserId],
            self::CHAIN_RESELLER => [$actorUserId, $ownerAdminId],
            self::CHAIN_CLIENT_RESELLER => array_values(array_filter([$actorUserId, $resellerId, $ownerAdminId])),
            default => [$actorUserId, $ownerAdminId],
        };
    }
}
