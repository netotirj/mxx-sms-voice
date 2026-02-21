<?php

namespace App\Model\Entity;

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
     * @return array
     */
    public static function getAllNotificationsByTenancyId(string $tenancyId): array
    {
        $query = "
            SELECT 
                n.id AS notification_id,
                n.user_id,
                n.title,
                n.message,
                n.type,
                n.read_at,
                n.created_at,
                COUNT(IF(n.read_at IS NULL, 1, NULL)) AS qtd_unread
            FROM notifications n
            WHERE n.tenancy_id = ?
            GROUP BY n.id
            ORDER BY n.created_at DESC
        ";

        return (new Database)->execute($query, [$tenancyId])
                             ->fetchAll(PDO::FETCH_ASSOC);
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

    public static function getNotificationCount(string $tenancyId, ?string $searchValue = null, ?int $userId = null): int
    {
        $query = "SELECT COUNT(*) as qtd FROM notifications WHERE tenancy_id = :tenancy_id";
        $params = [':tenancy_id' => $tenancyId];

        if ($userId !== null) {
            $query .= " AND user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        if (!empty($searchValue)) {
            $query .= " AND (
            id LIKE :search OR 
            title LIKE :search OR 
            message LIKE :search OR 
            type LIKE :search OR 
            read_at LIKE :search OR 
            created_at LIKE :search
        )";
            $params[':search'] = '%' . $searchValue . '%';
        }

        $result = (new Database())->execute($query, $params)->fetchObject();
        return (int)($result->qtd ?? 0);
    }



    public static function getNotificationsPaginated(
        string  $tenancyId,
        ?string $searchValue,
        int     $start,
        int     $length,
        string  $orderColumn = 'id',
        string  $orderDir = 'DESC',
        ?int    $userId = null
    ): array {
        $where = "tenancy_id = :tenancy_id";
        $params = [':tenancy_id' => $tenancyId];

        if ($userId !== null) {
            $where .= " AND user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        if (!empty($searchValue)) {
            $where .= " AND (
            id LIKE :search OR 
            title LIKE :search OR 
            message LIKE :search OR 
            type LIKE :search OR 
            read_at LIKE :search OR 
            created_at LIKE :search
        )";
            $params[':search'] = '%' . $searchValue . '%';
        }

        $order = "{$orderColumn} {$orderDir}";
        $limit = "{$start}, {$length}";

        $query = "
        SELECT 
            id,
            title,
            message,
            type,
            read_at,
            created_at
        FROM notifications
        WHERE {$where}
        ORDER BY {$order}
        LIMIT {$limit}
    ";

        return (new Database())->execute($query, $params)->fetchAll(\PDO::FETCH_OBJ);
    }


}