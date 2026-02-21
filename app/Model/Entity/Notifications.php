<?php

namespace App\Model\Entity;
use WilliamCosta\DatabaseManager\Database;
class Notifications
{
    public int $id;
    public int $user_id;
    public string $tenancy_id;
    public string $title;
    public string $message;
    public string $type;
    public string $read_at;
    public string $created_at;
    public string $updated_at;

    /**
     * Insere uma nova notificação no banco
     *
     * @param string $tenancyId
     * @param int $userId
     * @param string $title
     * @param string $message
     * @param string $type
     * @return int|false  ID inserido ou false em caso de erro
     */
    public static function insertNotifications(string $tenancyId, int $userId, string $title, string $message ,string $type): false|int
    {
        return (new Database('notifications'))->insert([
            'tenancy_id' => $tenancyId,
            'user_id'    => $userId,
            'title'      => $title,
            'message'    => $message,
            'type'       => $type,
            'read_at'    => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }
}