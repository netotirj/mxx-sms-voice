<?php

namespace App\Model\Entity;

use App\Utils\TenancyHelper;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppConversation
{
    public static function findOrCreate(array $data): int
    {
        $phone = preg_replace('/\D+/', '', (string)$data['contact_phone']);
        $existing = (new Database('whatsapp_conversations'))
            ->select(
                'account_id = :account_id AND contact_phone = :phone',
                [
                    ':account_id' => (int)$data['account_id'],
                    ':phone' => $phone,
                ],
                '',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if (!empty($data['contact_name'])) {
                self::update((int)$existing['id'], [
                    'contact_name' => $data['contact_name'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            return (int)$existing['id'];
        }

        return (int)(new Database('whatsapp_conversations'))->insert([
            'tenancy_id' => $data['tenancy_id'],
            'user_id' => (int)$data['user_id'],
            'account_id' => (int)$data['account_id'],
            'contact_phone' => $phone,
            'contact_name' => $data['contact_name'] ?? null,
            'last_message' => $data['last_message'] ?? null,
            'last_direction' => $data['last_direction'] ?? null,
            'last_message_at' => $data['last_message_at'] ?? date('Y-m-d H:i:s'),
            'unread_count' => (int)($data['unread_count'] ?? 0),
            'status' => $data['status'] ?? 'open',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function listForUser(array $user, ?int $accountId = null): array
    {
        $where = TenancyHelper::applySecurityFilter('', $user, 'user_id', 'wc');
        $params = [];

        if ($accountId !== null && $accountId > 0) {
            $where = "({$where}) AND wc.account_id = :account_id";
            $params[':account_id'] = $accountId;
        }

        return (new Database('whatsapp_conversations wc INNER JOIN whatsapp_accounts wa ON wa.id = wc.account_id'))
            ->select($where, $params, 'wc.last_message_at DESC, wc.id DESC', '', [
                'wc.*',
                'wa.label AS account_label',
                'wa.display_phone_number AS account_phone',
                "(SELECT wm.status
                    FROM whatsapp_messages wm
                    WHERE wm.conversation_id = wc.id
                      AND wm.direction = 'outbound'
                    ORDER BY wm.id DESC
                    LIMIT 1) AS last_outbound_status",
                "(SELECT wm.status
                    FROM whatsapp_messages wm
                    WHERE wm.conversation_id = wc.id
                    ORDER BY wm.id DESC
                    LIMIT 1) AS last_message_status",
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getForUser(int $id, array $user): ?array
    {
        $where = TenancyHelper::applySecurityFilter('id = :id', $user, 'user_id', 'whatsapp_conversations');
        $row = (new Database('whatsapp_conversations'))
            ->select($where, [':id' => $id], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function listMessagesForUser(int $conversationId, array $user): array
    {
        $conversation = self::getForUser($conversationId, $user);
        if (!$conversation) {
            return [];
        }

        self::update($conversationId, ['unread_count' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
        self::markInboundMessagesRead($conversationId);

        return (new Database('whatsapp_messages'))
            ->select('conversation_id = :conversation_id', [':conversation_id' => $conversationId], 'id ASC')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function markReadForUser(int $conversationId, array $user): bool
    {
        if (!self::getForUser($conversationId, $user)) {
            return false;
        }

        return self::update($conversationId, [
            'unread_count' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]) && self::markInboundMessagesRead($conversationId);
    }

    public static function markUnreadForUser(int $conversationId, array $user): bool
    {
        if (!self::getForUser($conversationId, $user)) {
            return false;
        }

        return self::update($conversationId, [
            'unread_count' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]) && self::markLastInboundMessageUnread($conversationId);
    }

    public static function deleteForUser(int $conversationId, array $user): bool
    {
        if (!self::getForUser($conversationId, $user)) {
            return false;
        }

        return (new Database('whatsapp_conversations'))->delete(
            'id = :id',
            [':id' => $conversationId]
        );
    }

    public static function addMessage(array $data): int
    {
        $id = (int)(new Database('whatsapp_messages'))->insert([
            'conversation_id' => (int)$data['conversation_id'],
            'account_id' => (int)$data['account_id'],
            'wamid' => $data['wamid'] ?? null,
            'direction' => $data['direction'],
            'message_type' => $data['message_type'] ?? 'text',
            'body' => $data['body'] ?? null,
            'status' => $data['status'] ?? 'sent',
            'error_message' => $data['error_message'] ?? null,
            'payload' => isset($data['payload']) ? json_encode($data['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        self::touchFromMessage(
            (int)$data['conversation_id'],
            (string)($data['body'] ?? ''),
            (string)$data['direction'],
            $data['direction'] === 'inbound' ? 1 : 0
        );

        return $id;
    }

    public static function touchFromMessage(int $conversationId, string $body, string $direction, int $unreadIncrement = 0): bool
    {
        $sql = "
            UPDATE whatsapp_conversations
            SET
                last_message = :last_message,
                last_direction = :last_direction,
                last_message_at = NOW(),
                unread_count = unread_count + :unread_increment,
                updated_at = NOW()
            WHERE id = :id
        ";

        (new Database('whatsapp_conversations'))->execute($sql, [
            ':id' => $conversationId,
            ':last_message' => mb_substr($body, 0, 500),
            ':last_direction' => $direction,
            ':unread_increment' => $unreadIncrement,
        ]);

        return true;
    }

    public static function updateMessageStatusByWamid(string $wamid, string $status, ?string $errorMessage = null, array $payload = []): bool
    {
        if ($wamid === '') {
            return false;
        }

        $allowed = ['pending', 'sent', 'delivered', 'read', 'failed', 'received'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $current = (new Database('whatsapp_messages'))
            ->select('wamid = :wamid', [':wamid' => $wamid], '', '1', ['status'])
            ->fetch(PDO::FETCH_ASSOC);

        if ($current && self::messageStatusRank((string)$current['status']) > self::messageStatusRank($status)) {
            return true;
        }

        $values = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($errorMessage !== null) {
            $values['error_message'] = $errorMessage;
        }

        if ($payload !== []) {
            $values['payload'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (new Database('whatsapp_messages'))->update(
            'wamid = :wamid',
            $values,
            [':wamid' => $wamid]
        );
    }

    private static function update(int $id, array $values): bool
    {
        return (new Database('whatsapp_conversations'))->update(
            'id = :id',
            $values,
            [':id' => $id]
        );
    }

    private static function markInboundMessagesRead(int $conversationId): bool
    {
        return (new Database('whatsapp_messages'))->update(
            "conversation_id = :conversation_id AND direction = 'inbound' AND status <> 'read'",
            [
                'status' => 'read',
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':conversation_id' => $conversationId]
        );
    }

    private static function markLastInboundMessageUnread(int $conversationId): bool
    {
        $row = (new Database('whatsapp_messages'))
            ->select(
                "conversation_id = :conversation_id AND direction = 'inbound'",
                [':conversation_id' => $conversationId],
                'id DESC',
                '1',
                ['id']
            )
            ->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return true;
        }

        return (new Database('whatsapp_messages'))->update(
            'id = :id',
            [
                'status' => 'received',
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':id' => (int)$row['id']]
        );
    }

    private static function messageStatusRank(string $status): int
    {
        return match ($status) {
            'failed' => 5,
            'read' => 4,
            'delivered' => 3,
            'sent' => 2,
            'received' => 2,
            'pending' => 1,
            default => 0,
        };
    }
}
