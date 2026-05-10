<?php

namespace App\Model\Entity;

use App\Support\RequestCache;
use App\Service\CallPermissionService;
use App\Utils\TenancyHelper;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppConversation
{
    public static function findOrCreate(array $data): int
    {
        $phone = self::normalizePhone((string)$data['contact_phone']);
        $contactName = self::nullableString($data['contact_name'] ?? null);
        $allowNameOverwrite = !empty($data['overwrite_contact_name']);
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
            self::syncExistingConversationContactName($existing, $contactName, $allowNameOverwrite);

            return (int)$existing['id'];
        }

        try {
            return (int)(new Database('whatsapp_conversations'))->insert([
                'tenancy_id' => $data['tenancy_id'],
                'user_id' => (int)$data['user_id'],
                'account_id' => (int)$data['account_id'],
                'contact_phone' => $phone,
                'contact_name' => $contactName,
                'last_message' => $data['last_message'] ?? null,
                'last_direction' => $data['last_direction'] ?? null,
                'last_message_at' => $data['last_message_at'] ?? date('Y-m-d H:i:s'),
                'unread_count' => (int)($data['unread_count'] ?? 0),
                'status' => $data['status'] ?? 'open',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            if (!self::isDuplicateKeyException($e)) {
                throw $e;
            }

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

            if (!$existing) {
                throw $e;
            }

            self::syncExistingConversationContactName($existing, $contactName, $allowNameOverwrite);

            return (int)$existing['id'];
        }
    }

    public static function listForUser(array $user, ?int $accountId = null, array $filters = []): array
    {
        self::ensureProtocolTrackingColumns();
        CallPermissionService::ensureSchema();
        $isRestrictedAgent = self::isRestrictedSupportRole($user);
        $hasQueueId = self::conversationHasColumn('queue_id');
        $hasAssignedUserId = self::conversationHasColumn('assigned_user_id');
        $hasQueueStatus = self::conversationHasColumn('queue_status');
        $hasQueuedAt = self::conversationHasColumn('queued_at');
        $hasProtocolReference = self::conversationHasColumn('protocol_reference');
        $hasProtocolSentAt = self::conversationHasColumn('protocol_sent_at');
        $params = [];
        $limit = max(1, min(200, (int)($filters['limit'] ?? 120)));
        $search = trim((string)($filters['search'] ?? ''));

        if ($isRestrictedAgent) {
            if ($hasAssignedUserId && $hasQueueStatus && $hasQueueId) {
                $where = "wa.status = 'active' AND wc.tenancy_id = :tenancy_id AND (
                    wc.assigned_user_id = :agent_user_id
                    OR (
                        wc.user_id = :agent_user_id
                        AND (wc.queue_id IS NULL OR wc.queue_id = 0)
                    )
                    OR (
                        wc.queue_status = 'waiting'
                        AND wc.queue_id IS NOT NULL
                        AND EXISTS (
                            SELECT 1
                            FROM whatsapp_support_queue_agents qa
                            WHERE qa.queue_id = wc.queue_id
                              AND qa.agent_user_id = :agent_user_id
                              AND qa.tenancy_id = wc.tenancy_id
                        )
                    )
                )";
                $params[':tenancy_id'] = $user['tenancy_id'];
                $params[':agent_user_id'] = (int)$user['id'];
            } else {
                $where = TenancyHelper::applySecurityFilter("wa.status = 'active'", $user, 'user_id', 'wc');
            }
        } else {
            $where = TenancyHelper::applySecurityFilter("wa.status = 'active'", $user, 'user_id', 'wc');
        }

        if ($accountId !== null && $accountId > 0) {
            $where = "({$where}) AND wc.account_id = :account_id";
            $params[':account_id'] = $accountId;
        }

        $queueId = isset($filters['queue_id']) ? (int)$filters['queue_id'] : 0;
        if ($hasQueueId && $queueId > 0) {
            $where = "({$where}) AND wc.queue_id = :queue_id";
            $params[':queue_id'] = $queueId;
        }

        $queueStatus = strtolower(trim((string)($filters['queue_status'] ?? '')));
        if ($hasQueueStatus && in_array($queueStatus, ['waiting', 'active', 'finished', 'transferred'], true)) {
            $where = "({$where}) AND wc.queue_status = :queue_status";
            $params[':queue_status'] = $queueStatus;
        }

        if ($hasAssignedUserId && !empty($filters['assigned_only'])) {
            $where = "({$where}) AND wc.assigned_user_id IS NOT NULL AND wc.assigned_user_id > 0";
        }

        if ($hasAssignedUserId && !empty($filters['unassigned_only'])) {
            $where = "({$where}) AND (wc.assigned_user_id IS NULL OR wc.assigned_user_id = 0)";
        }

        if ($search !== '') {
            $where = "({$where}) AND (
                wc.contact_name LIKE :search
                OR wc.contact_phone LIKE :search
                OR wc.last_message LIKE :search
            )";
            $params[':search'] = '%' . $search . '%';
        }

        $joinQueue = $hasQueueId ? 'LEFT JOIN whatsapp_support_queues q ON q.id = wc.queue_id AND q.tenancy_id = wc.tenancy_id' : '';
        $joinAssigned = $hasAssignedUserId ? 'LEFT JOIN users au ON au.id = wc.assigned_user_id AND au.tenancy_id = wc.tenancy_id' : '';
        $joinSupportSession = $hasQueueId ? "LEFT JOIN (
                SELECT s.conversation_id,
                       s.id AS support_session_id,
                       TIMESTAMPDIFF(
                           SECOND,
                           s.queued_at,
                           CASE
                               WHEN s.state = 'active' THEN COALESCE(s.started_at, NOW())
                               ELSE NOW()
                           END
                       ) AS queue_waiting_seconds
                FROM whatsapp_support_sessions s
                INNER JOIN (
                    SELECT conversation_id, MAX(id) AS latest_id
                    FROM whatsapp_support_sessions
                    WHERE state IN ('waiting', 'active')
                    GROUP BY conversation_id
                ) latest_support_session ON latest_support_session.latest_id = s.id
            ) ss ON ss.conversation_id = wc.id" : '';
        $joinLastMessage = "LEFT JOIN (
                SELECT wm.conversation_id, wm.status
                FROM whatsapp_messages wm
                INNER JOIN (
                    SELECT conversation_id, MAX(id) AS latest_id
                    FROM whatsapp_messages
                    GROUP BY conversation_id
                ) latest_message ON latest_message.latest_id = wm.id
            ) lm ON lm.conversation_id = wc.id";
        $joinLastOutbound = "LEFT JOIN (
                SELECT wm.conversation_id, wm.status
                FROM whatsapp_messages wm
                INNER JOIN (
                    SELECT conversation_id, MAX(id) AS latest_id
                    FROM whatsapp_messages
                    WHERE direction = 'outbound'
                    GROUP BY conversation_id
                ) latest_outbound ON latest_outbound.latest_id = wm.id
            ) lo ON lo.conversation_id = wc.id";
        $joinLastInbound = "LEFT JOIN (
                SELECT conversation_id, MAX(created_at) AS last_inbound_at
                FROM whatsapp_messages
                WHERE direction = 'inbound'
                GROUP BY conversation_id
            ) li ON li.conversation_id = wc.id";
        $joinMarketingOptOut = "LEFT JOIN (
                SELECT account_id, contact_phone, 1 AS marketing_opt_out
                FROM whatsapp_marketing_opt_outs
                GROUP BY account_id, contact_phone
            ) woo ON woo.account_id = wc.account_id AND woo.contact_phone = wc.contact_phone";
        $joinCallPermission = "LEFT JOIN (
                SELECT cp.account_id,
                       cp.phone_number,
                       cp.contact_id,
                       cp.permission_status,
                       cp.permission_requested_at,
                       cp.permission_approved_at,
                       cp.permission_expires_at,
                       cp.last_error_code,
                       cp.last_error_message,
                       cp.is_permanent
                FROM whatsapp_call_permissions cp
                INNER JOIN (
                    SELECT account_id, phone_number, MAX(id) AS latest_id
                    FROM whatsapp_call_permissions
                    GROUP BY account_id, phone_number
                ) latest_permission ON latest_permission.latest_id = cp.id
            ) wcp ON wcp.account_id = wc.account_id AND wcp.phone_number = wc.contact_phone";
        $fields = [
            'wc.*',
            'wa.label AS account_label',
            'wa.display_phone_number AS account_phone',
        ];
        if ($hasQueueId) {
            $fields[] = 'q.name AS queue_name';
            $fields[] = self::queueHasColumn('color') ? 'q.color AS queue_color' : "NULL AS queue_color";
            $fields[] = 'q.priority AS queue_priority';
            $fields[] = 'ss.support_session_id';
            $fields[] = 'ss.queue_waiting_seconds';
        } else {
            $fields[] = 'NULL AS queue_name';
            $fields[] = 'NULL AS queue_color';
            $fields[] = 'NULL AS queue_priority';
            $fields[] = 'NULL AS support_session_id';
            $fields[] = 'NULL AS queue_waiting_seconds';
        }
        if ($hasAssignedUserId) {
            $fields[] = 'au.name AS assigned_user_name';
            $fields[] = 'au.image AS assigned_user_image';
            $fields[] = 'au.last_activity AS assigned_user_last_activity';
        } else {
            $fields[] = 'NULL AS assigned_user_name';
            $fields[] = 'NULL AS assigned_user_image';
            $fields[] = 'NULL AS assigned_user_last_activity';
        }
        if (!$hasQueueId) {
            $fields[] = 'NULL AS queue_id';
        }
        if (!$hasAssignedUserId) {
            $fields[] = 'NULL AS assigned_user_id';
        }
        if (!$hasQueueStatus) {
            $fields[] = 'NULL AS queue_status';
        }
        if (!$hasQueuedAt) {
            $fields[] = 'NULL AS queued_at';
        }
        if ($hasProtocolReference) {
            $fields[] = 'wc.protocol_reference';
        } else {
            $fields[] = 'NULL AS protocol_reference';
        }
        if ($hasProtocolSentAt) {
            $fields[] = 'wc.protocol_sent_at';
        } else {
            $fields[] = 'NULL AS protocol_sent_at';
        }
        $fields[] = "COALESCE(wcp.permission_status, 'no_permission') AS call_permission_status";
        $fields[] = 'wcp.permission_requested_at AS call_permission_requested_at';
        $fields[] = 'wcp.permission_approved_at AS call_permission_approved_at';
        $fields[] = 'wcp.permission_expires_at AS call_permission_expires_at';
        $fields[] = 'COALESCE(wcp.is_permanent, 0) AS call_permission_is_permanent';
        $fields[] = 'wcp.last_error_code AS call_permission_last_error_code';
        $fields[] = 'wcp.last_error_message AS call_permission_last_error_message';

        return (new Database("whatsapp_conversations wc
            INNER JOIN whatsapp_accounts wa ON wa.id = wc.account_id AND wa.tenancy_id = wc.tenancy_id
            {$joinQueue}
            {$joinAssigned}
            {$joinSupportSession}
            {$joinLastMessage}
            {$joinLastOutbound}
            {$joinLastInbound}
            {$joinMarketingOptOut}
            {$joinCallPermission}"))
            ->select($where, $params, 'wc.last_message_at DESC, wc.id DESC', (string)$limit, [
                ...$fields,
                'lo.status AS last_outbound_status',
                'lm.status AS last_message_status',
                'li.last_inbound_at',
                "CASE
                    WHEN li.last_inbound_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    THEN 1
                    ELSE 0
                END AS service_window_open",
                'COALESCE(woo.marketing_opt_out, 0) AS marketing_opt_out',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getForUser(int $id, array $user): ?array
    {
        $cacheKey = 'whatsapp_conversation.user.' . (int)($user['id'] ?? 0) . '.' . (string)($user['tenancy_id'] ?? '') . '.' . $id;

        return RequestCache::remember($cacheKey, function () use ($id, $user): ?array {
            if (self::isRestrictedSupportRole($user)
                && self::conversationHasColumn('assigned_user_id')
                && self::conversationHasColumn('queue_status')
                && self::conversationHasColumn('queue_id')) {
                $row = (new Database('whatsapp_conversations'))
                    ->select(
                        "id = :id
                         AND tenancy_id = :tenancy_id
                         AND (
                            assigned_user_id = :agent_user_id
                            OR (
                                user_id = :agent_user_id
                                AND (queue_id IS NULL OR queue_id = 0)
                            )
                            OR (
                                queue_status = 'waiting'
                                AND queue_id IS NOT NULL
                                AND EXISTS (
                                    SELECT 1
                                    FROM whatsapp_support_queue_agents qa
                                    WHERE qa.queue_id = whatsapp_conversations.queue_id
                                      AND qa.agent_user_id = :agent_user_id
                                      AND qa.tenancy_id = whatsapp_conversations.tenancy_id
                                )
                            )
                         )",
                        [
                            ':id' => $id,
                            ':tenancy_id' => $user['tenancy_id'],
                            ':agent_user_id' => (int)$user['id'],
                        ],
                        '',
                        '1'
                    )
                    ->fetch(PDO::FETCH_ASSOC);
            } else {
                $where = TenancyHelper::applySecurityFilter('id = :id', $user, 'user_id', 'whatsapp_conversations');
                $row = (new Database('whatsapp_conversations'))
                    ->select($where, [':id' => $id], '', '1')
                    ->fetch(PDO::FETCH_ASSOC);
            }

            return $row ?: null;
        });
    }

    public static function listMessagesForUser(int $conversationId, array $user, int $limit = 200, ?int $beforeId = null, ?array $conversation = null): array
    {
        $conversation = $conversation ?: self::getForUser($conversationId, $user);
        if (!$conversation) {
            return [];
        }

        // Evita writes e revalidação duplicada quando o usuário só está paginando histórico.
        if ($beforeId === null && (int)($conversation['unread_count'] ?? 0) > 0) {
            self::update($conversationId, ['unread_count' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
            self::markInboundMessagesRead($conversationId);
        }

        $limit = max(1, min(500, $limit));
        $where = 'conversation_id = :conversation_id';
        $params = [':conversation_id' => $conversationId];
        if ($beforeId !== null && $beforeId > 0) {
            $where .= ' AND id < :before_id';
            $params[':before_id'] = $beforeId;
        }

        $rows = (new Database('whatsapp_messages'))
            ->select($where, $params, 'id DESC', (string)$limit)
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $rows = array_reverse($rows);

        foreach ($rows as &$row) {
            $payload = json_decode((string)($row['payload'] ?? ''), true);
            $row['payload'] = is_array($payload) ? $payload : null;
            unset($row['price_brl']);
            if (is_array($row['payload']['billing'] ?? null)) {
                unset($row['payload']['billing']['price_brl']);
            }
            $row['media'] = is_array($row['payload'] ?? null) && isset($row['payload']['media']) && is_array($row['payload']['media'])
                ? $row['payload']['media']
                : null;
        }
        unset($row);

        return $rows;
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

    public static function latestUnreadInboundMessage(int $conversationId): ?array
    {
        if ($conversationId <= 0) {
            return null;
        }

        $row = (new Database('whatsapp_messages'))
            ->select(
                "conversation_id = :conversation_id
                 AND direction = 'inbound'
                 AND COALESCE(wamid, '') <> ''
                 AND status <> 'read'",
                [':conversation_id' => $conversationId],
                'id DESC',
                '1',
                ['id', 'wamid', 'status', 'account_id']
            )
            ->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
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
        $wamid = self::nullableString($data['wamid'] ?? null);
        if ($wamid !== null) {
            $existing = self::findMessageByWamid($wamid);
            if ($existing) {
                self::mergeDuplicateMessage((int)$existing['id'], $existing, $data);
                return (int)$existing['id'];
            }
        }

        $values = [
            'conversation_id' => (int)$data['conversation_id'],
            'account_id' => (int)$data['account_id'],
            'wamid' => $wamid,
            'direction' => $data['direction'],
            'message_type' => $data['message_type'] ?? 'text',
            'template_name' => $data['template_name'] ?? null,
            'template_category' => $data['template_category'] ?? null,
            'service_window_open' => (int)($data['service_window_open'] ?? 0),
            'message_category' => isset($data['message_category']) ? strtolower((string)$data['message_category']) : null,
            'price_brl' => (float)($data['price_brl'] ?? 0),
            'billed' => (int)($data['billed'] ?? 0),
            'body' => $data['body'] ?? null,
            'status' => $data['status'] ?? 'sent',
            'error_message' => $data['error_message'] ?? null,
            'payload' => isset($data['payload']) ? json_encode($data['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        foreach ([
            'preview_body' => $data['preview_body'] ?? $data['body'] ?? null,
            'template_variables' => isset($data['template_variables'])
                ? json_encode($data['template_variables'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'pricing_snapshot' => isset($data['pricing_snapshot'])
                ? json_encode($data['pricing_snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
        ] as $column => $value) {
            if (self::messageHasColumn($column)) {
                $values[$column] = $value;
            }
        }

        try {
            $id = (int)(new Database('whatsapp_messages'))->insert($values);
        } catch (\Throwable $e) {
            if ($wamid === null || !self::isDuplicateKeyException($e)) {
                throw $e;
            }

            $existing = self::findMessageByWamid($wamid);
            if (!$existing) {
                throw $e;
            }

            self::mergeDuplicateMessage((int)$existing['id'], $existing, $data);
            return (int)$existing['id'];
        }

        self::touchFromMessage(
            (int)$data['conversation_id'],
            (string)($data['body'] ?? ''),
            (string)$data['direction'],
            $data['direction'] === 'inbound' ? 1 : 0
        );

        return $id;
    }

    public static function getLastInboundAt(int $accountId, string $phone): ?string
    {
        $phone = preg_replace('/\D+/', '', $phone) ?: '';
        if ($phone === '') {
            return null;
        }

        $row = (new Database('whatsapp_messages wm INNER JOIN whatsapp_conversations wc ON wc.id = wm.conversation_id'))
            ->select(
                "wc.account_id = :account_id AND wc.contact_phone = :phone AND wm.direction = 'inbound'",
                [
                    ':account_id' => $accountId,
                    ':phone' => $phone,
                ],
                'wm.created_at DESC',
                '1',
                ['wm.created_at']
            )
            ->fetch(PDO::FETCH_ASSOC);

        return $row['created_at'] ?? null;
    }

    public static function hasMarketingOptOut(int $accountId, string $phone): bool
    {
        $phone = preg_replace('/\D+/', '', $phone) ?: '';
        if ($phone === '') {
            return false;
        }

        $optOut = (new Database('whatsapp_marketing_opt_outs'))
            ->select(
                'account_id = :account_id AND contact_phone = :phone',
                [
                    ':account_id' => $accountId,
                    ':phone' => $phone,
                ],
                '',
                '1',
                ['id']
            )
            ->fetch(PDO::FETCH_ASSOC);

        if ($optOut) {
            return true;
        }

        $rows = (new Database('whatsapp_messages wm INNER JOIN whatsapp_conversations wc ON wc.id = wm.conversation_id'))
            ->select(
                "wc.account_id = :account_id
                 AND wc.contact_phone = :phone
                 AND wm.direction = 'inbound'",
                [
                    ':account_id' => $accountId,
                    ':phone' => $phone,
                ],
                'wm.created_at DESC',
                '20',
                ['wm.body']
            )
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            if (\App\Service\WhatsAppCostPolicy::looksLikeOptOut((string)($row['body'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    public static function registerMarketingOptOut(int $accountId, string $phone, ?string $wamid = null): void
    {
        $phone = preg_replace('/\D+/', '', $phone) ?: '';
        if ($phone === '') {
            return;
        }

        $sql = "
            INSERT INTO whatsapp_marketing_opt_outs (account_id, contact_phone, source_wamid, reason, created_at)
            VALUES (:account_id, :phone, :wamid, 'keyword', NOW())
            ON DUPLICATE KEY UPDATE source_wamid = VALUES(source_wamid)
        ";

        (new Database('whatsapp_marketing_opt_outs'))->execute($sql, [
            ':account_id' => $accountId,
            ':phone' => $phone,
            ':wamid' => $wamid,
        ]);
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

    public static function reconcileContactPhone(int $conversationId, int $accountId, string $contactPhone, ?string $contactName = null): int
    {
        $canonicalPhone = self::normalizePhone($contactPhone);
        if ($conversationId <= 0 || $accountId <= 0 || $canonicalPhone === '') {
            return $conversationId;
        }

        $current = (new Database('whatsapp_conversations'))
            ->select(
                'id = :id AND account_id = :account_id',
                [
                    ':id' => $conversationId,
                    ':account_id' => $accountId,
                ],
                '',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            return $conversationId;
        }

        $canonicalName = self::nullableString($contactName);
        $currentPhone = self::normalizePhone((string)($current['contact_phone'] ?? ''));
        if ($currentPhone === $canonicalPhone) {
            self::syncExistingConversationContactName($current, $canonicalName, true);
            return (int)$current['id'];
        }

        $target = (new Database('whatsapp_conversations'))
            ->select(
                'account_id = :account_id AND contact_phone = :phone',
                [
                    ':account_id' => $accountId,
                    ':phone' => $canonicalPhone,
                ],
                '',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        if ($target && (int)$target['id'] !== (int)$current['id']) {
            return self::mergeConversations((int)$current['id'], $current, (int)$target['id'], $target, $canonicalName);
        }

        $values = [
            'contact_phone' => $canonicalPhone,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($canonicalName !== null) {
            $values['contact_name'] = $canonicalName;
        }

        self::update((int)$current['id'], $values);
        return (int)$current['id'];
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
            $existingPayload = [];
            $payloadRow = (new Database('whatsapp_messages'))
                ->select('wamid = :wamid', [':wamid' => $wamid], '', '1', ['payload'])
                ->fetch(PDO::FETCH_ASSOC);
            if (!empty($payloadRow['payload'])) {
                $decodedPayload = json_decode((string)$payloadRow['payload'], true);
                $existingPayload = is_array($decodedPayload) ? $decodedPayload : [];
            }

            $values['payload'] = json_encode(array_merge($existingPayload, $payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $ok = (new Database('whatsapp_messages'))->update(
            'wamid = :wamid',
            $values,
            [':wamid' => $wamid]
        );

        if ($ok && in_array($status, ['delivered', 'read'], true)) {
            self::markCdrDelivered($wamid);
        }

        return $ok;
    }

    public static function getMessageByWamid(string $wamid): ?array
    {
        return self::findMessageByWamid($wamid);
    }

    public static function markMessageBilled(int $id): bool
    {
        return (new Database('whatsapp_messages'))->update(
            'id = :id',
            [
                'billed' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':id' => $id]
        );
    }

    public static function ensureConversationProtocolReference(int $conversationId, string $companySeed, ?string $fallbackReference = null): string
    {
        self::ensureProtocolTrackingColumns();
        if ($conversationId <= 0) {
            return '';
        }

        $row = (new Database('whatsapp_conversations'))
            ->select('id = :id', [':id' => $conversationId], '', '1', ['protocol_reference'])
            ->fetch(PDO::FETCH_ASSOC) ?: [];

        $existing = trim((string)($row['protocol_reference'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $reference = trim((string)$fallbackReference);
        if ($reference === '') {
            $reference = self::buildProtocolReference($conversationId, $companySeed);
        }

        self::update($conversationId, [
            'protocol_reference' => $reference,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $reference;
    }

    public static function protocolAlreadySent(int $conversationId): bool
    {
        self::ensureProtocolTrackingColumns();
        if ($conversationId <= 0 || !self::conversationHasColumn('protocol_sent_at')) {
            return false;
        }

        $row = (new Database('whatsapp_conversations'))
            ->select('id = :id', [':id' => $conversationId], '', '1', ['protocol_sent_at'])
            ->fetch(PDO::FETCH_ASSOC) ?: [];

        return !empty($row['protocol_sent_at']);
    }

    public static function markConversationProtocolSent(int $conversationId, string $reference): bool
    {
        self::ensureProtocolTrackingColumns();
        if ($conversationId <= 0) {
            return false;
        }

        $values = [
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (self::conversationHasColumn('protocol_reference') && trim($reference) !== '') {
            $values['protocol_reference'] = trim($reference);
        }
        if (self::conversationHasColumn('protocol_sent_at')) {
            $values['protocol_sent_at'] = date('Y-m-d H:i:s');
        }

        return self::update($conversationId, $values);
    }

    public static function buildProtocolReference(int $conversationId, string $companySeed): string
    {
        $digits = preg_replace('/\D+/', '', $companySeed) ?: '';
        $companyPart = str_pad(substr($digits !== '' ? $digits : '0', -5), 5, '0', STR_PAD_LEFT);
        $sequencePart = str_pad((string)max(0, $conversationId), 6, '0', STR_PAD_LEFT);

        return 'ATD-' . $companyPart . '-' . $sequencePart;
    }

    private static function update(int $id, array $values): bool
    {
        return (new Database('whatsapp_conversations'))->update(
            'id = :id',
            $values,
            [':id' => $id]
        );
    }

    private static function ensureProtocolTrackingColumns(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        $checked = true;

        try {
            $rows = (new Database())->execute('SHOW COLUMNS FROM whatsapp_conversations')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $columns = array_fill_keys(array_map(static fn ($row) => (string)$row['Field'], $rows), true);
            $alter = [];

            if (!isset($columns['protocol_reference'])) {
                $alter[] = "ADD COLUMN protocol_reference VARCHAR(64) NULL AFTER status";
            }
            if (!isset($columns['protocol_sent_at'])) {
                $alter[] = "ADD COLUMN protocol_sent_at DATETIME NULL AFTER protocol_reference";
            }

            if ($alter !== []) {
                (new Database())->execute('ALTER TABLE whatsapp_conversations ' . implode(', ', $alter));
            }
        } catch (\Throwable $e) {
            error_log('[whatsapp_protocol_columns] ' . $e->getMessage());
        }
    }

    private static function messageHasColumn(string $column): bool
    {
        static $columns = null;
        if ($columns === null) {
            try {
                $rows = (new Database())->execute('SHOW COLUMNS FROM whatsapp_messages')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $columns = array_fill_keys(array_map(static fn ($row) => (string)$row['Field'], $rows), true);
            } catch (\Throwable $e) {
                $columns = [];
            }
        }

        return isset($columns[$column]);
    }

    private static function conversationHasColumn(string $column): bool
    {
        static $columns = null;
        if ($columns === null) {
            try {
                $rows = (new Database())->execute('SHOW COLUMNS FROM whatsapp_conversations')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $columns = array_fill_keys(array_map(static fn ($row) => (string)$row['Field'], $rows), true);
            } catch (\Throwable $e) {
                $columns = [];
            }
        }

        return isset($columns[$column]);
    }

    private static function findMessageByWamid(string $wamid): ?array
    {
        if ($wamid === '') {
            return null;
        }

        $row = (new Database('whatsapp_messages'))
            ->select('wamid = :wamid', [':wamid' => $wamid], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function mergeDuplicateMessage(int $id, array $existing, array $incoming): void
    {
        $values = [];
        $existingStatus = (string)($existing['status'] ?? '');
        $incomingStatus = (string)($incoming['status'] ?? '');
        if ($incomingStatus !== '' && self::messageStatusRank($incomingStatus) > self::messageStatusRank($existingStatus)) {
            $values['status'] = $incomingStatus;
        }

        $incomingError = self::nullableString($incoming['error_message'] ?? null);
        if ($incomingError !== null && self::nullableString($existing['error_message'] ?? null) !== $incomingError) {
            $values['error_message'] = $incomingError;
        }

        $incomingBody = self::nullableString($incoming['body'] ?? null);
        if ($incomingBody !== null && self::nullableString($existing['body'] ?? null) === null) {
            $values['body'] = $incomingBody;
        }

        $existingPayload = json_decode((string)($existing['payload'] ?? ''), true);
        $existingPayload = is_array($existingPayload) ? $existingPayload : [];
        $incomingPayload = is_array($incoming['payload'] ?? null) ? $incoming['payload'] : [];
        if ($incomingPayload !== []) {
            $mergedPayload = array_replace_recursive($existingPayload, $incomingPayload);
            if ($mergedPayload !== $existingPayload) {
                $values['payload'] = json_encode($mergedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        foreach ([
            'preview_body' => $incoming['preview_body'] ?? $incoming['body'] ?? null,
            'template_variables' => isset($incoming['template_variables'])
                ? json_encode($incoming['template_variables'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'pricing_snapshot' => isset($incoming['pricing_snapshot'])
                ? json_encode($incoming['pricing_snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
        ] as $column => $value) {
            if (!self::messageHasColumn($column)) {
                continue;
            }

            $existingValue = $existing[$column] ?? null;
            if (($existingValue === null || $existingValue === '') && $value !== null && $value !== '') {
                $values[$column] = $value;
            }
        }

        if ($values === []) {
            return;
        }

        $values['updated_at'] = date('Y-m-d H:i:s');
        (new Database('whatsapp_messages'))->update('id = :id', $values, [':id' => $id]);
    }

    private static function syncExistingConversationContactName(array $existing, ?string $contactName, bool $allowOverwrite): void
    {
        if ($contactName === null) {
            return;
        }

        $currentName = self::nullableString($existing['contact_name'] ?? null);
        if ($currentName !== null && !$allowOverwrite) {
            return;
        }

        if ($currentName === $contactName) {
            return;
        }

        self::update((int)$existing['id'], [
            'contact_name' => $contactName,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function mergeConversations(int $sourceId, array $source, int $targetId, array $target, ?string $contactName = null): int
    {
        if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
            return $targetId > 0 ? $targetId : $sourceId;
        }

        foreach ([
            'whatsapp_messages',
            'whatsapp_outbox',
            'whatsapp_support_sessions',
            'whatsapp_support_queue_history',
        ] as $table) {
            (new Database($table))->execute(
                "UPDATE {$table} SET conversation_id = :target_id WHERE conversation_id = :source_id",
                [
                    ':target_id' => $targetId,
                    ':source_id' => $sourceId,
                ]
            );
        }

        $sourceLastAt = strtotime((string)($source['last_message_at'] ?? '')) ?: 0;
        $targetLastAt = strtotime((string)($target['last_message_at'] ?? '')) ?: 0;

        $values = [
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($sourceLastAt > $targetLastAt) {
            $values['last_message'] = $source['last_message'] ?? $target['last_message'] ?? null;
            $values['last_direction'] = $source['last_direction'] ?? $target['last_direction'] ?? null;
            $values['last_message_at'] = $source['last_message_at'] ?? $target['last_message_at'] ?? date('Y-m-d H:i:s');
        }

        $mergedName = $contactName
            ?? self::nullableString($target['contact_name'] ?? null)
            ?? self::nullableString($source['contact_name'] ?? null);
        if ($mergedName !== null) {
            $values['contact_name'] = $mergedName;
        }

        $values['unread_count'] = max(
            (int)($target['unread_count'] ?? 0),
            (int)($source['unread_count'] ?? 0)
        );

        self::update($targetId, $values);
        (new Database('whatsapp_conversations'))->delete('id = :id', [':id' => $sourceId]);

        return $targetId;
    }

    private static function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    private static function isDuplicateKeyException(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'duplicate entry')
            || str_contains($message, 'integrity constraint violation')
            || str_contains($message, '1062');
    }

    private static function queueHasColumn(string $column): bool
    {
        static $columns = null;
        if ($columns === null) {
            try {
                $rows = (new Database())->execute('SHOW COLUMNS FROM whatsapp_support_queues')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $columns = array_fill_keys(array_map(static fn ($row) => (string)$row['Field'], $rows), true);
            } catch (\Throwable $e) {
                $columns = [];
            }
        }

        return isset($columns[$column]);
    }

    private static function markCdrDelivered(string $wamid): void
    {
        try {
            $rows = (new Database())->execute('SHOW COLUMNS FROM whatsapp_message_cdr')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $columns = array_fill_keys(array_map(static fn ($row) => (string)$row['Field'], $rows), true);
            if (!isset($columns['delivered_at'])) {
                return;
            }

            (new Database('whatsapp_message_cdr'))->update(
                'wamid = :wamid AND delivered_at IS NULL',
                ['delivered_at' => date('Y-m-d H:i:s')],
                [':wamid' => $wamid]
            );
        } catch (\Throwable $e) {
            error_log('[whatsapp_cdr_delivered_at] ' . $e->getMessage());
        }
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

    private static function normalizedRole(array $user): string
    {
        return strtolower(trim((string)($user['user_function'] ?? $user['function'] ?? '')));
    }

    private static function isRestrictedSupportRole(array $user): bool
    {
        return in_array(self::normalizedRole($user), ['agent', 'support_l1', 'operator', 'o'], true);
    }
}
