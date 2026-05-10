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

    private static function ensureTable(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        (new Database())->execute("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                tenancy_id VARCHAR(64) NOT NULL,
                title VARCHAR(160) NOT NULL,
                message TEXT NOT NULL,
                type VARCHAR(32) NOT NULL DEFAULT 'info',
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY fk_notifications_user (user_id),
                KEY fk_notifications_tenancy (tenancy_id),
                KEY idx_notifications_unread (tenancy_id, user_id, read_at),
                KEY idx_notifications_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $checked = true;
    }

    public static function syncSchema(): void
    {
        self::ensureTable();
    }
}
