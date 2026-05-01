<?php

namespace App\Service;

use App\Model\Entity\WhatsAppAccount;
use App\Utils\TenancyHelper;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppSupportDesk
{
    private const STATE_WAITING = 'waiting';
    private const STATE_ACTIVE = 'active';
    private const STATE_FINISHED = 'finished';

    public static function listQueuesForUser(array $user): array
    {
        [$where, $params] = self::queueScope($user, 'q');

        return (new Database('whatsapp_support_queues q LEFT JOIN whatsapp_accounts wa ON wa.id = q.account_id AND wa.tenancy_id = q.tenancy_id'))
            ->select($where, $params, 'q.priority DESC, q.name ASC', '', [
                'q.*',
                'wa.label AS account_label',
                '(SELECT COUNT(*) FROM whatsapp_support_queue_agents qa WHERE qa.queue_id = q.id) AS agents_count',
                "(SELECT COUNT(*) FROM whatsapp_support_sessions s WHERE s.queue_id = q.id AND s.state = 'waiting') AS waiting_count",
                "(SELECT COUNT(*) FROM whatsapp_support_sessions s WHERE s.queue_id = q.id AND s.state = 'active') AS active_count",
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function createQueue(array $user, array $input): int
    {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Informe o nome da fila.');
        }

        $accountId = self::nullableInt($input['account_id'] ?? null);
        if ($accountId !== null && !WhatsAppAccount::getForUser($accountId, $user)) {
            throw new \InvalidArgumentException('Conta WhatsApp não encontrada para esta tenancy.');
        }

        return (int)(new Database('whatsapp_support_queues'))->insert([
            'tenancy_id' => $user['tenancy_id'],
            'user_id' => (int)$user['id'],
            'account_id' => $accountId,
            'name' => $name,
            'description' => self::nullableString($input['description'] ?? null),
            'priority' => (int)($input['priority'] ?? 0),
            'is_default' => !empty($input['is_default']) ? 1 : 0,
            'status' => $input['status'] ?? 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function upsertQueueAgent(array $user, int $queueId, array $input): bool
    {
        $queue = self::getQueueForUser($queueId, $user);
        if (!$queue) {
            return false;
        }

        $agentUserId = (int)($input['agent_user_id'] ?? $user['id']);
        $maxSimultaneous = max(1, (int)($input['max_simultaneous'] ?? 3));
        $status = self::normalizeAgentStatus((string)($input['status'] ?? 'online'));
        $now = date('Y-m-d H:i:s');

        $sql = "
            INSERT INTO whatsapp_support_queue_agents
                (tenancy_id, queue_id, agent_user_id, max_simultaneous, status, created_at, updated_at)
            VALUES
                (:tenancy_id, :queue_id, :agent_user_id, :max_simultaneous, :status, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE
                max_simultaneous = VALUES(max_simultaneous),
                status = VALUES(status),
                updated_at = VALUES(updated_at)
        ";

        (new Database('whatsapp_support_queue_agents'))->execute($sql, [
            ':tenancy_id' => $queue['tenancy_id'],
            ':queue_id' => $queueId,
            ':agent_user_id' => $agentUserId,
            ':max_simultaneous' => $maxSimultaneous,
            ':status' => $status,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        self::emitEvent((string)$queue['tenancy_id'], 'agent.updated', [
            'queue_id' => $queueId,
            'agent_user_id' => $agentUserId,
            'status' => $status,
        ]);

        self::attemptDispatchQueue($queueId);
        return true;
    }

    public static function updateAgentStatus(array $user, array $input): bool
    {
        $status = self::normalizeAgentStatus((string)($input['status'] ?? 'online'));
        $agentUserId = (int)($input['agent_user_id'] ?? $user['id']);
        $queueId = (int)($input['queue_id'] ?? 0);

        $where = 'agent_user_id = :agent_user_id';
        $params = [':agent_user_id' => $agentUserId];

        if ($queueId > 0) {
            $queue = self::getQueueForUser($queueId, $user);
            if (!$queue) {
                return false;
            }
            $where .= ' AND queue_id = :queue_id';
            $params[':queue_id'] = $queueId;
        } else {
            $where = TenancyHelper::applySecurityFilter($where, $user, 'agent_user_id', 'whatsapp_support_queue_agents');
        }

        (new Database('whatsapp_support_queue_agents'))->update($where, [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], $params);

        self::emitEvent((string)$user['tenancy_id'], 'agent.status', [
            'agent_user_id' => $agentUserId,
            'queue_id' => $queueId ?: null,
            'status' => $status,
        ]);

        if ($status === 'online') {
            self::attemptDispatchForAgent($agentUserId);
        }

        return true;
    }

    public static function dashboard(array $user): array
    {
        return [
            'queues' => self::listQueuesForUser($user),
            'agents' => self::listAgentsForUser($user),
            'waiting' => self::listSessionsForUser($user, self::STATE_WAITING),
            'active' => self::listSessionsForUser($user, self::STATE_ACTIVE),
        ];
    }

    public static function events(array $user, int $afterId = 0): array
    {
        $params = [':after_id' => $afterId];
        $where = 'e.id > :after_id';
        if (!self::isSuperAdmin($user)) {
            $where .= ' AND e.tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $user['tenancy_id'];
        }

        return (new Database('whatsapp_support_events e'))
            ->select($where, $params, 'e.id ASC', '100')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function handleInboundConversation(int $conversationId, array $account, string $body = ''): ?array
    {
        $existing = self::getOpenSessionByConversation($conversationId);
        if ($existing) {
            (new Database('whatsapp_support_sessions'))->update('id = :id', [
                'last_customer_message_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], [':id' => (int)$existing['id']]);

            self::emitEvent((string)$existing['tenancy_id'], 'conversation.message', [
                'session_id' => (int)$existing['id'],
                'conversation_id' => $conversationId,
                'state' => $existing['state'],
            ]);

            return $existing;
        }

        $queue = self::selectInboundQueue($account);
        if (!$queue) {
            return null;
        }

        $priority = (int)$queue['priority'];
        $isVip = self::looksVip($body) ? 1 : 0;
        $sessionId = (int)(new Database('whatsapp_support_sessions'))->insert([
            'tenancy_id' => $account['tenancy_id'],
            'user_id' => (int)$account['user_id'],
            'account_id' => (int)$account['id'],
            'conversation_id' => $conversationId,
            'queue_id' => (int)$queue['id'],
            'assigned_agent_user_id' => null,
            'state' => self::STATE_WAITING,
            'priority' => $priority,
            'is_vip' => $isVip,
            'active_key' => 'active',
            'queued_at' => date('Y-m-d H:i:s'),
            'started_at' => null,
            'finished_at' => null,
            'last_customer_message_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        self::emitEvent((string)$account['tenancy_id'], 'session.created', [
            'session_id' => $sessionId,
            'conversation_id' => $conversationId,
            'queue_id' => (int)$queue['id'],
            'state' => self::STATE_WAITING,
        ]);

        self::attemptDispatchQueue((int)$queue['id']);
        return self::getSessionById($sessionId);
    }

    public static function finishSession(array $user, int $sessionId): bool
    {
        $session = self::getSessionForUser($sessionId, $user);
        if (!$session || $session['state'] === self::STATE_FINISHED) {
            return false;
        }

        (new Database('whatsapp_support_sessions'))->update('id = :id', [
            'state' => self::STATE_FINISHED,
            'active_key' => null,
            'finished_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $sessionId]);

        if (!empty($session['assigned_agent_user_id'])) {
            self::refreshAgentStatus((int)$session['queue_id'], (int)$session['assigned_agent_user_id']);
        }

        self::emitEvent((string)$session['tenancy_id'], 'session.finished', [
            'session_id' => $sessionId,
            'queue_id' => (int)$session['queue_id'],
            'agent_user_id' => isset($session['assigned_agent_user_id']) ? (int)$session['assigned_agent_user_id'] : null,
        ]);

        self::attemptDispatchQueue((int)$session['queue_id']);
        return true;
    }

    public static function transferSession(array $user, int $sessionId, array $input): bool
    {
        $session = self::getSessionForUser($sessionId, $user);
        if (!$session || $session['state'] === self::STATE_FINISHED) {
            return false;
        }

        $targetQueueId = (int)($input['queue_id'] ?? $session['queue_id']);
        $targetQueue = self::getQueueForUser($targetQueueId, $user);
        if (!$targetQueue) {
            return false;
        }

        $targetAgentId = self::nullableInt($input['agent_user_id'] ?? null);
        $values = [
            'queue_id' => $targetQueueId,
            'assigned_agent_user_id' => null,
            'state' => self::STATE_WAITING,
            'queued_at' => date('Y-m-d H:i:s'),
            'started_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($targetAgentId && self::agentCanReceive($targetQueueId, $targetAgentId)) {
            $values['assigned_agent_user_id'] = $targetAgentId;
            $values['state'] = self::STATE_ACTIVE;
            $values['started_at'] = date('Y-m-d H:i:s');
        }

        (new Database('whatsapp_support_sessions'))->update('id = :id', $values, [':id' => $sessionId]);

        if (!empty($session['assigned_agent_user_id'])) {
            self::refreshAgentStatus((int)$session['queue_id'], (int)$session['assigned_agent_user_id']);
        }
        if ($targetAgentId) {
            self::refreshAgentStatus($targetQueueId, $targetAgentId);
        }

        self::emitEvent((string)$session['tenancy_id'], 'session.transferred', [
            'session_id' => $sessionId,
            'from_queue_id' => (int)$session['queue_id'],
            'to_queue_id' => $targetQueueId,
            'agent_user_id' => $targetAgentId,
        ]);

        self::attemptDispatchQueue($targetQueueId);
        return true;
    }

    public static function positionForSession(int $sessionId): ?int
    {
        $session = self::getSessionById($sessionId);
        if (!$session || $session['state'] !== self::STATE_WAITING) {
            return null;
        }

        $sql = "
            SELECT COUNT(*) + 1 AS position
            FROM whatsapp_support_sessions
            WHERE queue_id = :queue_id
              AND state = 'waiting'
              AND (
                    priority > :priority
                    OR (priority = :priority AND is_vip > :is_vip)
                    OR (priority = :priority AND is_vip = :is_vip AND queued_at < :queued_at)
                    OR (priority = :priority AND is_vip = :is_vip AND queued_at = :queued_at AND id < :id)
              )
        ";

        $row = (new Database('whatsapp_support_sessions'))->execute($sql, [
            ':queue_id' => (int)$session['queue_id'],
            ':priority' => (int)$session['priority'],
            ':is_vip' => (int)$session['is_vip'],
            ':queued_at' => $session['queued_at'],
            ':id' => $sessionId,
        ])->fetch(PDO::FETCH_ASSOC);

        return isset($row['position']) ? (int)$row['position'] : null;
    }

    private static function attemptDispatchForAgent(int $agentUserId): void
    {
        $queues = (new Database('whatsapp_support_queue_agents'))
            ->select('agent_user_id = :agent_user_id AND status = :status', [
                ':agent_user_id' => $agentUserId,
                ':status' => 'online',
            ], 'updated_at ASC', '', ['queue_id'])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($queues as $queue) {
            self::attemptDispatchQueue((int)$queue['queue_id']);
        }
    }

    private static function attemptDispatchQueue(int $queueId): void
    {
        while (true) {
            $agent = self::selectLeastLoadedAgent($queueId);
            if (!$agent) {
                return;
            }

            $session = self::selectNextWaitingSession($queueId);
            if (!$session) {
                return;
            }

            self::assignSession((int)$session['id'], $queueId, (int)$agent['agent_user_id']);
        }
    }

    private static function selectLeastLoadedAgent(int $queueId): ?array
    {
        $sql = "
            SELECT
                qa.*,
                (SELECT COUNT(*)
                    FROM whatsapp_support_sessions s
                    WHERE s.queue_id = qa.queue_id
                      AND s.assigned_agent_user_id = qa.agent_user_id
                      AND s.state = 'active') AS active_count
            FROM whatsapp_support_queue_agents qa
            WHERE qa.queue_id = :queue_id
              AND qa.status = 'online'
              AND (SELECT COUNT(*)
                    FROM whatsapp_support_sessions s
                    WHERE s.queue_id = qa.queue_id
                      AND s.assigned_agent_user_id = qa.agent_user_id
                      AND s.state = 'active') < qa.max_simultaneous
            ORDER BY active_count ASC, qa.last_assigned_at ASC, qa.updated_at ASC
            LIMIT 1
        ";

        $row = (new Database('whatsapp_support_queue_agents'))->execute($sql, [
            ':queue_id' => $queueId,
        ])->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function selectNextWaitingSession(int $queueId): ?array
    {
        $row = (new Database('whatsapp_support_sessions'))
            ->select(
                "queue_id = :queue_id AND state = 'waiting'",
                [':queue_id' => $queueId],
                'priority DESC, is_vip DESC, queued_at ASC, id ASC',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function assignSession(int $sessionId, int $queueId, int $agentUserId): void
    {
        (new Database('whatsapp_support_sessions'))->update('id = :id', [
            'assigned_agent_user_id' => $agentUserId,
            'state' => self::STATE_ACTIVE,
            'started_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $sessionId]);

        (new Database('whatsapp_support_queue_agents'))->update(
            'queue_id = :queue_id AND agent_user_id = :agent_user_id',
            [
                'last_assigned_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                ':queue_id' => $queueId,
                ':agent_user_id' => $agentUserId,
            ]
        );

        self::refreshAgentStatus($queueId, $agentUserId);

        $session = self::getSessionById($sessionId);
        if ($session) {
            self::emitEvent((string)$session['tenancy_id'], 'session.assigned', [
                'session_id' => $sessionId,
                'queue_id' => $queueId,
                'agent_user_id' => $agentUserId,
            ]);
        }
    }

    private static function refreshAgentStatus(int $queueId, int $agentUserId): void
    {
        $row = (new Database('whatsapp_support_queue_agents qa'))
            ->select(
                'qa.queue_id = :queue_id AND qa.agent_user_id = :agent_user_id',
                [':queue_id' => $queueId, ':agent_user_id' => $agentUserId],
                '',
                '1',
                [
                    'qa.max_simultaneous',
                    'qa.status',
                    "(SELECT COUNT(*) FROM whatsapp_support_sessions s
                        WHERE s.queue_id = qa.queue_id
                          AND s.assigned_agent_user_id = qa.agent_user_id
                          AND s.state = 'active') AS active_count",
                ]
            )
            ->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['status'] === 'offline') {
            return;
        }

        $newStatus = ((int)$row['active_count'] >= (int)$row['max_simultaneous']) ? 'busy' : 'online';
        (new Database('whatsapp_support_queue_agents'))->update(
            'queue_id = :queue_id AND agent_user_id = :agent_user_id',
            ['status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')],
            [':queue_id' => $queueId, ':agent_user_id' => $agentUserId]
        );
    }

    private static function agentCanReceive(int $queueId, int $agentUserId): bool
    {
        return (bool)self::selectSpecificAvailableAgent($queueId, $agentUserId);
    }

    private static function selectSpecificAvailableAgent(int $queueId, int $agentUserId): ?array
    {
        $sql = "
            SELECT
                qa.*,
                (SELECT COUNT(*)
                    FROM whatsapp_support_sessions s
                    WHERE s.queue_id = qa.queue_id
                      AND s.assigned_agent_user_id = qa.agent_user_id
                      AND s.state = 'active') AS active_count
            FROM whatsapp_support_queue_agents qa
            WHERE qa.queue_id = :queue_id
              AND qa.agent_user_id = :agent_user_id
              AND qa.status = 'online'
              AND (SELECT COUNT(*)
                    FROM whatsapp_support_sessions s
                    WHERE s.queue_id = qa.queue_id
                      AND s.assigned_agent_user_id = qa.agent_user_id
                      AND s.state = 'active') < qa.max_simultaneous
            LIMIT 1
        ";

        $row = (new Database('whatsapp_support_queue_agents'))->execute($sql, [
            ':queue_id' => $queueId,
            ':agent_user_id' => $agentUserId,
        ])->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function listAgentsForUser(array $user): array
    {
        [$where, $params] = self::agentScope($user, 'qa');

        return (new Database('whatsapp_support_queue_agents qa INNER JOIN whatsapp_support_queues q ON q.id = qa.queue_id AND q.tenancy_id = qa.tenancy_id LEFT JOIN users u ON u.id = qa.agent_user_id AND u.tenancy_id = qa.tenancy_id'))
            ->select($where, $params, 'q.name ASC, u.name ASC, qa.agent_user_id ASC', '', [
                'qa.*',
                'q.name AS queue_name',
                'u.name AS agent_name',
                "(SELECT COUNT(*) FROM whatsapp_support_sessions s
                    WHERE s.queue_id = qa.queue_id
                      AND s.assigned_agent_user_id = qa.agent_user_id
                      AND s.state = 'active') AS active_count",
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function listSessionsForUser(array $user, string $state): array
    {
        [$scope, $params] = self::sessionScope($user, 's');
        $where = "s.state = :state AND ({$scope})";
        $params[':state'] = $state;

        $sessions = (new Database('whatsapp_support_sessions s INNER JOIN whatsapp_conversations wc ON wc.id = s.conversation_id AND wc.tenancy_id = s.tenancy_id INNER JOIN whatsapp_support_queues q ON q.id = s.queue_id AND q.tenancy_id = s.tenancy_id LEFT JOIN users u ON u.id = s.assigned_agent_user_id AND u.tenancy_id = s.tenancy_id'))
            ->select($where, $params, 's.priority DESC, s.is_vip DESC, s.queued_at ASC, s.id ASC', '', [
                's.*',
                'wc.contact_phone',
                'wc.contact_name',
                'wc.last_message',
                'wc.last_message_at',
                'wc.unread_count',
                'q.name AS queue_name',
                'u.name AS agent_name',
                'TIMESTAMPDIFF(SECOND, s.queued_at, COALESCE(s.started_at, NOW())) AS waiting_seconds',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($state === self::STATE_WAITING) {
            foreach ($sessions as &$session) {
                $session['queue_position'] = self::positionForSession((int)$session['id']);
            }
            unset($session);
        }

        return $sessions;
    }

    private static function selectInboundQueue(array $account): ?array
    {
        $row = (new Database('whatsapp_support_queues'))
            ->select(
                "status = 'active' AND (account_id = :account_id OR account_id IS NULL) AND tenancy_id = :tenancy_id",
                [
                    ':account_id' => (int)$account['id'],
                    ':tenancy_id' => $account['tenancy_id'],
                ],
                'is_default DESC, account_id DESC, priority DESC, id ASC',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return $row;
        }

        $id = (int)(new Database('whatsapp_support_queues'))->insert([
            'tenancy_id' => $account['tenancy_id'],
            'user_id' => (int)$account['user_id'],
            'account_id' => (int)$account['id'],
            'name' => 'Suporte',
            'description' => 'Fila padrão criada automaticamente para mensagens recebidas.',
            'priority' => 0,
            'is_default' => 1,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return self::getQueueById($id);
    }

    private static function getQueueForUser(int $queueId, array $user): ?array
    {
        [$scope, $params] = self::queueScope($user, 'q');
        $where = "q.id = :id AND ({$scope})";
        $params[':id'] = $queueId;

        $row = (new Database('whatsapp_support_queues q'))
            ->select($where, $params, '', '1', ['q.*'])
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function getQueueById(int $queueId): ?array
    {
        $row = (new Database('whatsapp_support_queues'))
            ->select('id = :id', [':id' => $queueId], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function getOpenSessionByConversation(int $conversationId): ?array
    {
        $row = (new Database('whatsapp_support_sessions'))
            ->select(
                "conversation_id = :conversation_id AND state IN ('waiting', 'active')",
                [':conversation_id' => $conversationId],
                'id DESC',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function getSessionById(int $sessionId): ?array
    {
        $row = (new Database('whatsapp_support_sessions'))
            ->select('id = :id', [':id' => $sessionId], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function getSessionForUser(int $sessionId, array $user): ?array
    {
        [$scope, $params] = self::sessionScope($user, 's');
        $where = "s.id = :id AND ({$scope})";
        $params[':id'] = $sessionId;

        $row = (new Database('whatsapp_support_sessions s'))
            ->select($where, $params, '', '1', ['s.*'])
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function emitEvent(string $tenancyId, string $type, array $payload): void
    {
        (new Database('whatsapp_support_events'))->insert([
            'tenancy_id' => $tenancyId,
            'event_type' => $type,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function normalizeAgentStatus(string $status): string
    {
        return in_array($status, ['online', 'busy', 'offline'], true) ? $status : 'offline';
    }

    private static function queueScope(array $user, string $alias): array
    {
        if (self::isSuperAdmin($user)) {
            return ['1=1', []];
        }

        $prefix = $alias !== '' ? "{$alias}." : '';
        $params = [
            ':scope_tenancy_id' => $user['tenancy_id'],
        ];
        $where = "{$prefix}tenancy_id = :scope_tenancy_id";

        if (self::isRestrictedAgent($user)) {
            $where .= " AND EXISTS (
                SELECT 1
                FROM whatsapp_support_queue_agents scope_qa
                WHERE scope_qa.queue_id = {$prefix}id
                  AND scope_qa.agent_user_id = :scope_agent_user_id
            )";
            $params[':scope_agent_user_id'] = (int)$user['id'];
        }

        return [$where, $params];
    }

    private static function agentScope(array $user, string $alias): array
    {
        if (self::isSuperAdmin($user)) {
            return ['1=1', []];
        }

        $prefix = $alias !== '' ? "{$alias}." : '';
        $params = [
            ':scope_tenancy_id' => $user['tenancy_id'],
        ];
        $where = "{$prefix}tenancy_id = :scope_tenancy_id";

        if (self::isRestrictedAgent($user)) {
            $where .= " AND {$prefix}agent_user_id = :scope_agent_user_id";
            $params[':scope_agent_user_id'] = (int)$user['id'];
        }

        return [$where, $params];
    }

    private static function sessionScope(array $user, string $alias): array
    {
        if (self::isSuperAdmin($user)) {
            return ['1=1', []];
        }

        $prefix = $alias !== '' ? "{$alias}." : '';
        $params = [
            ':scope_tenancy_id' => $user['tenancy_id'],
        ];
        $where = "{$prefix}tenancy_id = :scope_tenancy_id";

        if (self::isRestrictedAgent($user)) {
            $where .= " AND (
                {$prefix}assigned_agent_user_id = :scope_assigned_agent_user_id
                OR (
                    {$prefix}state = 'waiting'
                    AND EXISTS (
                        SELECT 1
                        FROM whatsapp_support_queue_agents scope_qa
                        WHERE scope_qa.queue_id = {$prefix}queue_id
                          AND scope_qa.agent_user_id = :scope_member_agent_user_id
                    )
                )
            )";
            $params[':scope_assigned_agent_user_id'] = (int)$user['id'];
            $params[':scope_member_agent_user_id'] = (int)$user['id'];
        }

        return [$where, $params];
    }

    private static function isSuperAdmin(array $user): bool
    {
        return strtolower((string)($user['user_function'] ?? $user['function'] ?? '')) === 'super_admin';
    }

    private static function isRestrictedAgent(array $user): bool
    {
        return in_array(strtolower((string)($user['user_function'] ?? $user['function'] ?? '')), ['agent', 'support_l1'], true);
    }

    private static function looksVip(string $body): bool
    {
        return (bool)preg_match('/\b(vip|prioridade|urgente|premium)\b/i', $body);
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        $value = (int)($value ?? 0);
        return $value > 0 ? $value : null;
    }
}
