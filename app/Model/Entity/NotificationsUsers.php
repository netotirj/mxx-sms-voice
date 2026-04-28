<?php

namespace App\Model\Entity;

use App\Utils\TenancyHelper;
use WilliamCosta\DatabaseManager\Database;
use PDO;

class NotificationsUsers
{
    public int $id;
    public string $tenancy_id;
    public string $user_id; 
    public string $title;
    public string $message;
    public string $type;
    public ?string $read_at; 
    public string $created_at;
    public string $updated_at;

    /**
     * Retorna todas as notificações da tenancy com contagem de não lidas
     * @param string $tenancyId
     * @param int|null $userId
     * @param string $role
     * @return array
     */
    public static function getAllNotificationsByTenancyId(string $tenancyId, ?int $userId = null, string $role = 'agent'): array
    {
        // Preparamos o contexto para o Helper
        $userContext = [
            'tenancy_id'    => $tenancyId,
            'id'            => $userId,
            'user_function' => $role
        ];

        // Usamos o Helper para decidir quem vê o quê (passamos o alias 'n')
        $securityFilter = TenancyHelper::applyCdrSecurityFilter($userContext, 'n');

            $query = "
            SELECT 
                n.id AS notification_id,
                n.user_id,
                n.title,
                n.message,
                n.type,
                n.read_at,
                n.created_at
            FROM notifications n
            WHERE {$securityFilter}
            ORDER BY n.created_at DESC
        ";

        return (new Database)->execute($query)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Conta notificações novas (não lidas) por tenancy e usuário
     * @param string $tenancyId
     * @param string $userId
     * @return int
     */
    public static function countNewNotifications(string $tenancyId, string $userId): int
    {
        $db = new Database();
        return (int) $db->execute(
            'SELECT COUNT(*) FROM notifications WHERE tenancy_id = ? AND user_id = ? AND read_at IS NULL',
            [$tenancyId, $userId]
        )->fetchColumn();
    }

    /**
     * Retorna notificações com filtro e ordenação retornando array direto
     * @param string $where
     * @param string $order
     * @param string $limit
     * @param array $fields
     * @return array
     */
    public static function getNotifications(
        string $where = '',
        string $order = '',
        string $limit = '',
        array $fields = ['*']
    ): array {
        $stmt = (new Database('notifications'))
            ->select($where, [], $order, $limit, implode(',', $fields));

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }    

    /**
     * Marca uma notificação como lida se pertencer ao usuário e tenancy informados.
     *
     * @param int $id
     * @param int $userId
     * @param string $tenancyId
     * @return bool
     */
    public static function markNotificationAsRead(int $id, int $userId, string $tenancyId): bool
    {
        $db = new Database('notifications');

        return $db->update(
            "id = {$id} AND user_id = '{$userId}' AND tenancy_id = '{$tenancyId}' AND read_at IS NULL",
            ['read_at' => date('Y-m-d H:i:s')]
        );
    }

    /**
     * Marca todas as notificações de um usuário como lidas
     */
    public static function markAllNotificationsAsRead(int $userId, string $tenancyId): bool
    {
        $db = new Database('notifications');

        // Note que removemos o "id =" para afetar todos os registros do usuário
        return $db->update(
            "user_id = '{$userId}' AND tenancy_id = '{$tenancyId}' AND read_at IS NULL",
            ['read_at' => date('Y-m-d H:i:s')]
        );
    }

    /**
     * Apaga todas as notificações de um usuário
     */
    public static function deleteAllNotificationsByUser(int $userId, string $tenancyId): bool
    {
        $db = new Database('notifications');

        // Seguindo o padrão de filtros por string que você usa no update
        return $db->delete(
            "user_id = '{$userId}' AND tenancy_id = '{$tenancyId}'"
        );
    }

    public static function getNotificationCount(string $tenancyId, ?string $searchValue = null, ?int $userId = null, string $role = 'agent'): int
    {
        $userContext = [
            'tenancy_id'    => $tenancyId,
            'id'            => $userId,
            'user_function' => $role
        ];

        $securityFilter = TenancyHelper::applyCdrSecurityFilter($userContext, ''); // Sem alias aqui

        $query = "SELECT COUNT(*) as qtd FROM notifications WHERE {$securityFilter}";
        $params = [];

        if (!empty($searchValue)) {
            $query .= " AND (title LIKE :search OR message LIKE :search)";
            $params[':search'] = '%' . $searchValue . '%';
        }

        $result = (new Database())->execute($query, $params)->fetchObject();
        return (int)($result->qtd ?? 0);
    }



    public static function getNotificationsForRealtime(array $filters = [], string $order = "id DESC"): array
    {
        $userContext = [
            'tenancy_id'    => $filters['tenancy_id'] ?? null,
            'id'            => $filters['user_id'] ?? null,
            'user_function' => $filters['user_role'] ?? 'agent'
        ];

        // O helper garante que o Revendedor receba alertas dos seus "filhos" e dele mesmo
        $securityFilter = TenancyHelper::applyCdrSecurityFilter($userContext, '');

        $query = "
        SELECT id, title, message, type, read_at, created_at
        FROM notifications
        WHERE {$securityFilter}
        ORDER BY {$order}
    ";

        return (new Database())->execute($query)->fetchAll(\PDO::FETCH_ASSOC);
    }

}