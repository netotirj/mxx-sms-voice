<?php

namespace App\Service;

use App\Model\Entity\SupportTicket;
use App\Model\Entity\WhatsAppAccount;
use App\Service\PerformanceTelemetry;
use App\Utils\TenancyHelper;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class WhatsAppSupportDesk
{
    private const DISPATCH_MAX_ITERATIONS = 25;
    private const STATE_WAITING = 'waiting';
    private const STATE_ACTIVE = 'active';
    private const STATE_FINISHED = 'finished';
    private const CONVERSATION_STATUS_WAITING = 'waiting';
    private const CONVERSATION_STATUS_ACTIVE = 'active';
    private const CONVERSATION_STATUS_FINISHED = 'finished';
    private const CONVERSATION_STATUS_TRANSFERRED = 'transferred';

    public static function listQueuesForUser(array $user): array
    {
        [$where, $params] = self::queueScope($user, 'q');

        $queues = (new Database('whatsapp_support_queues q LEFT JOIN whatsapp_accounts wa ON wa.id = q.account_id AND wa.tenancy_id = q.tenancy_id'))
            ->select($where, $params, 'q.priority DESC, q.name ASC', '', [
                'q.*',
                'wa.label AS account_label',
                '(SELECT COUNT(*) FROM whatsapp_support_queue_agents qa WHERE qa.queue_id = q.id) AS agents_count',
                "(SELECT COUNT(*) FROM whatsapp_support_sessions s WHERE s.queue_id = q.id AND s.state = 'waiting') AS waiting_count",
                "(SELECT COUNT(*) FROM whatsapp_support_sessions s WHERE s.queue_id = q.id AND s.state = 'active') AS active_count",
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $queueIds = array_values(array_filter(array_map(
            static fn (array $queue): int => (int)($queue['id'] ?? 0),
            $queues
        )));
        $agentsByQueue = self::listQueueAgentsBatch($user, $queueIds);

        foreach ($queues as &$queue) {
            $queue['agents'] = $agentsByQueue[(int)($queue['id'] ?? 0)] ?? [];
        }
        unset($queue);

        return $queues;
    }

    public static function createQueue(array $user, array $input): int
    {
        self::assertQueueWriteAccess($user);

        [$values, $accountId, $isDefault] = self::normalizeQueuePayload($user, $input);
        $now = date('Y-m-d H:i:s');

        if ($isDefault) {
            self::clearDefaultQueue($user, $accountId, null);
        }

        $payload = [
            'tenancy_id' => $user['tenancy_id'],
            'user_id' => (int)$user['id'],
            'account_id' => $accountId,
            'name' => $values['name'],
            'description' => $values['description'],
            'priority' => $values['priority'],
            'is_default' => $isDefault ? 1 : 0,
            'status' => $values['status'],
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (self::queueHasColumn('color')) {
            $payload['color'] = $values['color'];
        }
        if (self::queueHasColumn('greeting_message')) {
            $payload['greeting_message'] = $values['greeting_message'];
        }
        if (self::queueHasColumn('out_of_hours_message')) {
            $payload['out_of_hours_message'] = $values['out_of_hours_message'];
        }
        if (self::queueHasColumn('business_hours')) {
            $payload['business_hours'] = $values['business_hours'];
        }

        $id = (int)(new Database('whatsapp_support_queues'))->insert($payload);

        self::emitEvent((string)$user['tenancy_id'], 'queue.created', [
            'queue_id' => $id,
            'name' => $values['name'],
            'account_id' => $accountId,
            'changed_by' => (int)$user['id'],
        ]);

        return $id;
    }

    public static function updateQueue(array $user, int $queueId, array $input): bool
    {
        self::assertQueueWriteAccess($user);

        $queue = self::getQueueForUser($queueId, $user);
        if (!$queue) {
            return false;
        }

        [$values, $accountId, $isDefault] = self::normalizeQueuePayload($user, $input, $queue);
        if ($isDefault) {
            self::clearDefaultQueue($user, $accountId, $queueId);
        }

        $update = [
            'account_id' => $accountId,
            'name' => $values['name'],
            'description' => $values['description'],
            'priority' => $values['priority'],
            'is_default' => $isDefault ? 1 : 0,
            'status' => $values['status'],
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (self::queueHasColumn('color')) {
            $update['color'] = $values['color'];
        }
        if (self::queueHasColumn('greeting_message')) {
            $update['greeting_message'] = $values['greeting_message'];
        }
        if (self::queueHasColumn('out_of_hours_message')) {
            $update['out_of_hours_message'] = $values['out_of_hours_message'];
        }
        if (self::queueHasColumn('business_hours')) {
            $update['business_hours'] = $values['business_hours'];
        }

        (new Database('whatsapp_support_queues'))->update('id = :id', $update, [':id' => $queueId]);

        self::emitEvent((string)$user['tenancy_id'], 'queue.updated', [
            'queue_id' => $queueId,
            'name' => $values['name'],
            'account_id' => $accountId,
            'changed_by' => (int)$user['id'],
        ]);

        return true;
    }

    public static function deleteQueue(array $user, int $queueId): bool
    {
        self::assertQueueWriteAccess($user);

        $queue = self::getQueueForUser($queueId, $user);
        if (!$queue) {
            return false;
        }

        $activeSessions = (new Database('whatsapp_support_sessions'))
            ->select(
                "queue_id = :queue_id AND state IN ('waiting', 'active')",
                [':queue_id' => $queueId],
                '',
                '1',
                ['id']
            )
            ->fetch(PDO::FETCH_ASSOC);

        if ($activeSessions) {
            throw new \InvalidArgumentException('Finalize ou transfira os atendimentos ativos antes de excluir a fila.');
        }

        if (self::conversationHasColumn('queue_id')) {
            $resetConversation = ['queue_id' => null];
            if (self::conversationHasColumn('assigned_user_id')) {
                $resetConversation['assigned_user_id'] = null;
            }
            if (self::conversationHasColumn('queue_status')) {
                $resetConversation['queue_status'] = null;
            }
            if (self::conversationHasColumn('queued_at')) {
                $resetConversation['queued_at'] = null;
            }

            (new Database('whatsapp_conversations'))->update(
                'queue_id = :queue_id AND tenancy_id = :tenancy_id',
                $resetConversation,
                [
                    ':queue_id' => $queueId,
                    ':tenancy_id' => $user['tenancy_id'],
                ]
            );
        }

        (new Database('whatsapp_support_queue_history'))->delete(
            '(old_queue_id = :queue_id OR new_queue_id = :queue_id) AND tenancy_id = :tenancy_id',
            [
                ':queue_id' => $queueId,
                ':tenancy_id' => $user['tenancy_id'],
            ]
        );

        (new Database('whatsapp_support_sessions'))->delete(
            "queue_id = :queue_id AND tenancy_id = :tenancy_id AND state = 'finished'",
            [
                ':queue_id' => $queueId,
                ':tenancy_id' => $user['tenancy_id'],
            ]
        );
        (new Database('whatsapp_support_queue_agents'))->delete('queue_id = :queue_id', [':queue_id' => $queueId]);
        $ok = (new Database('whatsapp_support_queues'))->delete('id = :id', [':id' => $queueId]);

        if ($ok) {
            self::emitEvent((string)$user['tenancy_id'], 'queue.deleted', [
                'queue_id' => $queueId,
                'name' => (string)$queue['name'],
                'changed_by' => (int)$user['id'],
            ]);
        }

        return $ok;
    }

    public static function getQueueDetails(array $user, int $queueId): ?array
    {
        $queue = self::getQueueForUser($queueId, $user);
        if (!$queue) {
            return null;
        }

        $queue['agents'] = self::listQueueAgents($user, $queueId);
        return $queue;
    }

    public static function listAssignableUsers(array $user): array
    {
        if (!self::canViewAllQueues($user) && !self::isSuperAdmin($user)) {
            return [
                [
                    'id' => (int)$user['id'],
                    'name' => (string)($user['name'] ?? 'Você'),
                    'user_function' => (string)($user['user_function'] ?? $user['function'] ?? ''),
                ],
            ];
        }

        $params = [];
        $where = 'status_account <> :status_deleted';
        $params[':status_deleted'] = 'deleted';
        if (!self::isSuperAdmin($user)) {
            $where .= ' AND tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $user['tenancy_id'];
        }

        return (new Database('users'))
            ->select($where, $params, 'name ASC', '', ['id', 'name', 'user_function', 'email'])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function upsertQueueAgent(array $user, int $queueId, array $input): bool
    {
        self::assertQueueWriteAccess($user);

        $queue = self::getQueueForUser($queueId, $user);
        if (!$queue) {
            return false;
        }

        $agentUserId = (int)($input['agent_user_id'] ?? $user['id']);
        if (!self::userBelongsToTenancy($agentUserId, (string)$queue['tenancy_id'])) {
            throw new \InvalidArgumentException('Usuário informado não pertence a esta tenancy.');
        }

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

    public static function removeQueueAgent(array $user, int $queueId, int $agentUserId): bool
    {
        self::assertQueueWriteAccess($user);

        $queue = self::getQueueForUser($queueId, $user);
        if (!$queue) {
            return false;
        }

        $active = (new Database('whatsapp_support_sessions'))
            ->select(
                "queue_id = :queue_id AND assigned_agent_user_id = :agent_user_id AND state = 'active'",
                [
                    ':queue_id' => $queueId,
                    ':agent_user_id' => $agentUserId,
                ],
                '',
                '1',
                ['id']
            )
            ->fetch(PDO::FETCH_ASSOC);

        if ($active) {
            throw new \InvalidArgumentException('O atendente possui conversas ativas nesta fila. Transfira-as antes de removê-lo.');
        }

        return (new Database('whatsapp_support_queue_agents'))->delete(
            'queue_id = :queue_id AND agent_user_id = :agent_user_id',
            [
                ':queue_id' => $queueId,
                ':agent_user_id' => $agentUserId,
            ]
        );
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

    public static function latestEventId(array $user): int
    {
        $params = [];
        $where = '1=1';

        if (!self::isSuperAdmin($user)) {
            $where = 'tenancy_id = :tenancy_id';
            $params[':tenancy_id'] = $user['tenancy_id'];
        }

        $value = (new Database('whatsapp_support_events'))
            ->select($where, $params, '', '1', ['MAX(id) AS latest_id'])
            ->fetch(PDO::FETCH_ASSOC);

        return (int)($value['latest_id'] ?? 0);
    }

    public static function emitConversationMessageStatus(string $tenancyId, int $conversationId, int $messageId, string $status, ?string $wamid = null): void
    {
        if ($tenancyId === '' || $conversationId <= 0 || $messageId <= 0 || $status === '') {
            return;
        }

        self::emitEvent($tenancyId, 'conversation.message', [
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'wamid' => $wamid,
            'status' => $status,
        ]);
    }

    public static function handleInboundConversation(int $conversationId, array $account, string $body = ''): ?array
    {
        $shouldCreateSupportTicket = self::shouldAutoOpenSupportForAccount($account);

        $existing = self::getOpenSessionByConversation($conversationId);
        if ($existing) {
            (new Database('whatsapp_support_sessions'))->update('id = :id', [
                'last_customer_message_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], [':id' => (int)$existing['id']]);

            self::appendSessionTicketMessage($existing, self::supportActorFromAccount($account), 'customer', $body);

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
        $conversation = self::getConversationById($conversationId);
        $contactPhone = (string)($conversation['contact_phone'] ?? '');
        $ticketId = $shouldCreateSupportTicket
            ? self::createSessionSupportTicket($account, $conversationId, $contactPhone, $body)
            : null;
        $sessionId = (int)(new Database('whatsapp_support_sessions'))->insert([
            'tenancy_id' => $account['tenancy_id'],
            'user_id' => (int)$account['user_id'],
            'account_id' => (int)$account['id'],
            'conversation_id' => $conversationId,
            'support_ticket_id' => $ticketId,
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

        self::syncConversationRouting($conversationId, [
            'queue_id' => (int)$queue['id'],
            'assigned_agent_user_id' => null,
            'queue_status' => self::CONVERSATION_STATUS_WAITING,
            'queued_at' => date('Y-m-d H:i:s'),
        ]);
        self::registerQueueHistory(
            (string)$account['tenancy_id'],
            $conversationId,
            $sessionId,
            null,
            (int)$queue['id'],
            null,
            null,
            (int)($account['user_id'] ?? 0),
            'enqueue',
            ['source' => 'inbound']
        );

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

        if (!empty($session['support_ticket_id'])) {
            SupportTicket::updateStatus((int)$session['support_ticket_id'], 'closed');
            SupportTicket::addMessage(
                (int)$session['support_ticket_id'],
                $user,
                'system',
                'Atendimento WhatsApp finalizado. O ticket foi finalizado automaticamente.'
            );
        }

        self::syncConversationRouting((int)$session['conversation_id'], [
            'queue_id' => (int)$session['queue_id'],
            'assigned_agent_user_id' => null,
            'queue_status' => self::CONVERSATION_STATUS_FINISHED,
            'queued_at' => $session['queued_at'] ?? date('Y-m-d H:i:s'),
        ]);
        self::registerQueueHistory(
            (string)$session['tenancy_id'],
            (int)$session['conversation_id'],
            $sessionId,
            (int)$session['queue_id'],
            (int)$session['queue_id'],
            self::nullableInt($session['assigned_agent_user_id'] ?? null),
            null,
            (int)$user['id'],
            'finish'
        );

        self::emitEvent((string)$session['tenancy_id'], 'session.finished', [
            'session_id' => $sessionId,
            'queue_id' => (int)$session['queue_id'],
            'agent_user_id' => isset($session['assigned_agent_user_id']) ? (int)$session['assigned_agent_user_id'] : null,
        ]);

        self::attemptDispatchQueue((int)$session['queue_id']);
        return true;
    }

    public static function appendTicketMessageForConversation(array $user, int $conversationId, string $senderType, string $body): void
    {
        $session = self::getOpenSessionByConversation($conversationId);
        if (!$session) {
            return;
        }

        self::appendSessionTicketMessage($session, $user, $senderType, $body);
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
        self::syncConversationRouting((int)$session['conversation_id'], [
            'queue_id' => $targetQueueId,
            'assigned_agent_user_id' => $values['assigned_agent_user_id'],
            'queue_status' => $values['state'] === self::STATE_ACTIVE
                ? self::CONVERSATION_STATUS_ACTIVE
                : self::CONVERSATION_STATUS_WAITING,
            'queued_at' => $values['queued_at'],
        ]);
        self::registerQueueHistory(
            (string)$session['tenancy_id'],
            (int)$session['conversation_id'],
            $sessionId,
            (int)$session['queue_id'],
            $targetQueueId,
            self::nullableInt($session['assigned_agent_user_id'] ?? null),
            $targetAgentId,
            (int)$user['id'],
            'transfer'
        );

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

    public static function assignConversation(array $user, int $conversationId, array $input): ?array
    {
        $conversation = self::getConversationForUser($conversationId, $user);
        if (!$conversation) {
            return null;
        }

        $queueId = (int)($input['queue_id'] ?? 0);
        if ($queueId <= 0) {
            throw new \InvalidArgumentException('Selecione a fila de atendimento.');
        }

        $queue = self::getQueueForUser($queueId, $user);
        if (!$queue) {
            throw new \InvalidArgumentException('Fila de atendimento não encontrada.');
        }

        $existing = self::getOpenSessionByConversation($conversationId);
        if ($existing) {
            if (!self::transferSession($user, (int)$existing['id'], $input)) {
                throw new \InvalidArgumentException('Não foi possível transferir a conversa para a fila selecionada.');
            }
            return self::getConversationRoutingDetails($conversationId, $user);
        }

        $targetAgentId = self::nullableInt($input['agent_user_id'] ?? null);
        $state = self::STATE_WAITING;
        $startedAt = null;
        if ($targetAgentId !== null) {
            if (!self::agentCanReceive($queueId, $targetAgentId)) {
                throw new \InvalidArgumentException('O atendente informado não está disponível para esta fila.');
            }
            $state = self::STATE_ACTIVE;
            $startedAt = date('Y-m-d H:i:s');
        }

        $queuedAt = date('Y-m-d H:i:s');
        $sessionId = (int)(new Database('whatsapp_support_sessions'))->insert([
            'tenancy_id' => $conversation['tenancy_id'],
            'user_id' => (int)$conversation['user_id'],
            'account_id' => (int)$conversation['account_id'],
            'conversation_id' => $conversationId,
            'support_ticket_id' => null,
            'queue_id' => $queueId,
            'assigned_agent_user_id' => $targetAgentId,
            'state' => $state,
            'priority' => max(0, (int)($queue['priority'] ?? 0)),
            'is_vip' => 0,
            'active_key' => 'active',
            'queued_at' => $queuedAt,
            'started_at' => $startedAt,
            'finished_at' => null,
            'last_customer_message_at' => $conversation['last_message_at'] ?? $queuedAt,
            'created_at' => $queuedAt,
            'updated_at' => $queuedAt,
        ]);

        self::syncConversationRouting($conversationId, [
            'queue_id' => $queueId,
            'assigned_agent_user_id' => $targetAgentId,
            'queue_status' => $state === self::STATE_ACTIVE
                ? self::CONVERSATION_STATUS_ACTIVE
                : self::CONVERSATION_STATUS_WAITING,
            'queued_at' => $queuedAt,
        ]);
        self::registerQueueHistory(
            (string)$conversation['tenancy_id'],
            $conversationId,
            $sessionId,
            self::nullableInt($conversation['queue_id'] ?? null),
            $queueId,
            self::nullableInt($conversation['assigned_user_id'] ?? null),
            $targetAgentId,
            (int)$user['id'],
            $state === self::STATE_ACTIVE ? 'assign' : 'enqueue'
        );

        if ($targetAgentId !== null) {
            self::refreshAgentStatus($queueId, $targetAgentId);
        } else {
            self::attemptDispatchQueue($queueId);
        }

        self::emitEvent((string)$conversation['tenancy_id'], 'conversation.queued', [
            'conversation_id' => $conversationId,
            'session_id' => $sessionId,
            'queue_id' => $queueId,
            'agent_user_id' => $targetAgentId,
        ]);

        return self::getConversationRoutingDetails($conversationId, $user);
    }

    public static function claimConversation(array $user, int $conversationId): ?array
    {
        $conversation = self::getConversationForUser($conversationId, $user);
        if (!$conversation) {
            return null;
        }

        $session = self::getOpenSessionByConversation($conversationId);
        if (!$session) {
            return null;
        }

        $queueId = (int)($session['queue_id'] ?? 0);
        if ($queueId <= 0 || !self::queueVisibleToAgent($queueId, (int)$user['id'], (string)$conversation['tenancy_id'])) {
            return null;
        }

        if (!self::agentCanReceive($queueId, (int)$user['id'])) {
            throw new \InvalidArgumentException('Seu usuário não está disponível para assumir esta conversa.');
        }

        if ((string)$session['state'] === self::STATE_ACTIVE && (int)($session['assigned_agent_user_id'] ?? 0) === (int)$user['id']) {
            return self::getConversationRoutingDetails($conversationId, $user);
        }

        $stmt = (new Database('whatsapp_support_sessions'))->execute(
            "UPDATE whatsapp_support_sessions
             SET assigned_agent_user_id = :agent_user_id,
                 state = :state,
                 started_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id
               AND state = 'waiting'
               AND assigned_agent_user_id IS NULL",
            [
                ':agent_user_id' => (int)$user['id'],
                ':state' => self::STATE_ACTIVE,
                ':id' => (int)$session['id'],
            ]
        );

        if ($stmt->rowCount() <= 0) {
            throw new \RuntimeException('Esta conversa já foi assumida por outro atendente.');
        }

        self::syncConversationRouting($conversationId, [
            'queue_id' => $queueId,
            'assigned_agent_user_id' => (int)$user['id'],
            'queue_status' => self::CONVERSATION_STATUS_ACTIVE,
            'queued_at' => $session['queued_at'] ?? date('Y-m-d H:i:s'),
        ]);
        self::registerQueueHistory(
            (string)$conversation['tenancy_id'],
            $conversationId,
            (int)$session['id'],
            $queueId,
            $queueId,
            null,
            (int)$user['id'],
            (int)$user['id'],
            'claim'
        );
        self::refreshAgentStatus($queueId, (int)$user['id']);
        self::emitEvent((string)$conversation['tenancy_id'], 'session.claimed', [
            'session_id' => (int)$session['id'],
            'conversation_id' => $conversationId,
            'queue_id' => $queueId,
            'agent_user_id' => (int)$user['id'],
        ]);

        return self::getConversationRoutingDetails($conversationId, $user);
    }

    public static function queueHistoryForConversation(array $user, int $conversationId): array
    {
        $conversation = self::getConversationForUser($conversationId, $user);
        if (!$conversation) {
            return [];
        }

        return (new Database('whatsapp_support_queue_history h
            LEFT JOIN whatsapp_support_queues oq ON oq.id = h.old_queue_id
            LEFT JOIN whatsapp_support_queues nq ON nq.id = h.new_queue_id
            LEFT JOIN users cu ON cu.id = h.changed_by'))
            ->select(
                'h.conversation_id = :conversation_id AND h.tenancy_id = :tenancy_id',
                [
                    ':conversation_id' => $conversationId,
                    ':tenancy_id' => $conversation['tenancy_id'],
                ],
                'h.id DESC',
                '',
                [
                    'h.*',
                    'oq.name AS old_queue_name',
                    'nq.name AS new_queue_name',
                    'cu.name AS changed_by_name',
                ]
            )
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
        $connection = new Database();
        $lockName = 'maxx_whatsapp_dispatch_queue_' . $queueId;
        $acquired = (int)$connection
            ->execute('SELECT GET_LOCK(:lock_name, 2) AS acquired', [':lock_name' => $lockName])
            ->fetchColumn() === 1;

        if (!$acquired) {
            return;
        }

        try {
            $iterations = 0;
            while ($iterations < self::DISPATCH_MAX_ITERATIONS) {
                $iterations++;
                $agent = self::selectLeastLoadedAgent($queueId);
                if (!$agent) {
                    return;
                }

                $session = self::selectNextWaitingSession($queueId);
                if (!$session) {
                    return;
                }

                if (!self::assignSession((int)$session['id'], $queueId, (int)$agent['agent_user_id'])) {
                    continue;
                }
            }

            PerformanceTelemetry::log('whatsapp.dispatch_guard', [
                'queue_id' => $queueId,
                'iterations' => $iterations,
            ]);
        } finally {
            $connection->execute('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lockName]);
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

    private static function assignSession(int $sessionId, int $queueId, int $agentUserId): bool
    {
        $stmt = (new Database('whatsapp_support_sessions'))->execute(
            "UPDATE whatsapp_support_sessions
             SET assigned_agent_user_id = :agent_user_id,
                 state = :state,
                 started_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id
               AND state = 'waiting'
               AND assigned_agent_user_id IS NULL",
            [
                ':agent_user_id' => $agentUserId,
                ':state' => self::STATE_ACTIVE,
                ':id' => $sessionId,
            ]
        );

        if ($stmt->rowCount() <= 0) {
            return false;
        }

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
            self::syncConversationRouting((int)$session['conversation_id'], [
                'queue_id' => $queueId,
                'assigned_agent_user_id' => $agentUserId,
                'queue_status' => self::CONVERSATION_STATUS_ACTIVE,
                'queued_at' => $session['queued_at'] ?? date('Y-m-d H:i:s'),
            ]);
            self::registerQueueHistory(
                (string)$session['tenancy_id'],
                (int)$session['conversation_id'],
                $sessionId,
                $queueId,
                $queueId,
                null,
                $agentUserId,
                $agentUserId,
                'auto_assign'
            );
            self::emitEvent((string)$session['tenancy_id'], 'session.assigned', [
                'session_id' => $sessionId,
                'queue_id' => $queueId,
                'agent_user_id' => $agentUserId,
            ]);
        }

        return true;
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

    private static function listQueueAgents(array $user, int $queueId): array
    {
        $queue = self::getQueueForUser($queueId, $user);
        if (!$queue) {
            return [];
        }

        return (new Database('whatsapp_support_queue_agents qa
            LEFT JOIN users u ON u.id = qa.agent_user_id AND u.tenancy_id = qa.tenancy_id'))
            ->select(
                'qa.queue_id = :queue_id AND qa.tenancy_id = :tenancy_id',
                [
                    ':queue_id' => $queueId,
                    ':tenancy_id' => $queue['tenancy_id'],
                ],
                'u.name ASC, qa.agent_user_id ASC',
                '',
                [
                    'qa.*',
                    'u.name AS agent_name',
                    'u.user_function AS agent_role',
                ]
            )
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function listQueueAgentsBatch(array $user, array $queueIds): array
    {
        $queueIds = array_values(array_filter(array_map('intval', $queueIds)));
        if ($queueIds === []) {
            return [];
        }

        $params = [':tenancy_id' => $user['tenancy_id']];
        $placeholders = [];
        foreach ($queueIds as $index => $queueId) {
            $key = ':queue_id_' . $index;
            $placeholders[] = $key;
            $params[$key] = $queueId;
        }

        $rows = (new Database('whatsapp_support_queue_agents qa
            LEFT JOIN users u ON u.id = qa.agent_user_id AND u.tenancy_id = qa.tenancy_id'))
            ->execute(
                "SELECT
                    qa.*,
                    u.name AS agent_name,
                    u.user_function AS agent_role
                 FROM whatsapp_support_queue_agents qa
                 LEFT JOIN users u ON u.id = qa.agent_user_id AND u.tenancy_id = qa.tenancy_id
                 WHERE qa.tenancy_id = :tenancy_id
                   AND qa.queue_id IN (" . implode(', ', $placeholders) . ")
                 ORDER BY u.name ASC, qa.agent_user_id ASC",
                $params
            )
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int)($row['queue_id'] ?? 0)][] = $row;
        }

        return $grouped;
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

        return $row ?: null;
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

    private static function ensureDefaultCommercialQueueForUser(array $user): void
    {
        if (empty($user['tenancy_id'])) {
            return;
        }

        self::ensureLegacyDefaultQueueRenamed((string)$user['tenancy_id'], null);

        $row = (new Database('whatsapp_support_queues'))
            ->select(
                'tenancy_id = :tenancy_id',
                [':tenancy_id' => $user['tenancy_id']],
                '',
                '1',
                ['id']
            )
            ->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return;
        }

        (new Database('whatsapp_support_queues'))->insert([
            'tenancy_id' => $user['tenancy_id'],
            'user_id' => (int)$user['id'],
            'account_id' => null,
            'name' => 'Comercial',
            'description' => 'Fila padrão criada automaticamente.',
            'priority' => 0,
            'is_default' => 1,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ] + (self::queueHasColumn('color') ? ['color' => '#10b981'] : []));
    }

    private static function ensureDefaultCommercialQueueForAccount(array $account): void
    {
        $tenancyId = (string)($account['tenancy_id'] ?? '');
        $accountId = self::nullableInt($account['id'] ?? null);
        if ($tenancyId === '') {
            return;
        }

        self::ensureLegacyDefaultQueueRenamed($tenancyId, $accountId);

        $row = (new Database('whatsapp_support_queues'))
            ->select(
                'tenancy_id = :tenancy_id AND (account_id = :account_id OR account_id IS NULL)',
                [
                    ':tenancy_id' => $tenancyId,
                    ':account_id' => $accountId,
                ],
                '',
                '1',
                ['id']
            )
            ->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return;
        }

        (new Database('whatsapp_support_queues'))->insert([
            'tenancy_id' => $tenancyId,
            'user_id' => (int)($account['user_id'] ?? 0),
            'account_id' => $accountId,
            'name' => 'Comercial',
            'description' => 'Fila padrão criada automaticamente.',
            'priority' => 0,
            'is_default' => 1,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ] + (self::queueHasColumn('color') ? ['color' => '#10b981'] : []));
    }

    private static function ensureLegacyDefaultQueueRenamed(string $tenancyId, ?int $accountId): void
    {
        $where = 'tenancy_id = :tenancy_id AND is_default = 1 AND name = :legacy_name';
        $params = [
            ':tenancy_id' => $tenancyId,
            ':legacy_name' => 'Geral',
        ];

        if ($accountId !== null) {
            $where .= ' AND (account_id = :account_id OR account_id IS NULL)';
            $params[':account_id'] = $accountId;
        }

        (new Database('whatsapp_support_queues'))->update($where, [
            'name' => 'Comercial',
            'updated_at' => date('Y-m-d H:i:s'),
        ], $params);
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

    private static function getConversationById(int $conversationId): ?array
    {
        $row = (new Database('whatsapp_conversations'))
            ->select('id = :id', [':id' => $conversationId], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function createSessionSupportTicket(array $account, int $conversationId, string $contactPhone, string $body): int
    {
        $actor = self::supportActorFromAccount($account, $contactPhone);
        $message = trim($body) !== ''
            ? $body
            : 'Atendimento WhatsApp iniciado pelo cliente.';

        return SupportTicket::create($actor, [
            'department' => 'support',
            'requester_phone' => $contactPhone,
            'subject' => 'Atendimento WhatsApp #' . $conversationId,
            'message' => $message,
        ]);
    }

    private static function appendSessionTicketMessage(array $session, array $actor, string $senderType, string $body): void
    {
        $ticketId = (int)($session['support_ticket_id'] ?? 0);
        $body = trim($body);

        if ($ticketId <= 0 || $body === '') {
            return;
        }

        SupportTicket::addMessage($ticketId, $actor, $senderType, $body);
    }

    private static function supportActorFromAccount(array $account, string $contactPhone = ''): array
    {
        return [
            'id' => (int)($account['user_id'] ?? 0),
            'tenancy_id' => (string)($account['tenancy_id'] ?? ''),
            'name' => $contactPhone !== '' ? 'WhatsApp +' . $contactPhone : 'Atendimento WhatsApp',
            'user_function' => 'system',
        ];
    }

    private static function shouldAutoOpenSupportForAccount(array $account): bool
    {
        $accountId = (int)($account['id'] ?? 0);
        if ($accountId <= 0) {
            return false;
        }

        $supportAccount = WhatsAppAccount::getSupportAccount();
        if (!$supportAccount || (int)($supportAccount['id'] ?? 0) !== $accountId) {
            return false;
        }

        return self::accountOwnerIsSuperAdmin((int)($account['user_id'] ?? 0));
    }

    private static function accountOwnerIsSuperAdmin(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $row = (new Database('users'))
            ->select('id = :id', [':id' => $userId], '', '1', ['user_function'])
            ->fetch(PDO::FETCH_ASSOC);

        return strtolower((string)($row['user_function'] ?? '')) === 'super_admin';
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

    private static function getConversationForUser(int $conversationId, array $user): ?array
    {
        $conversation = self::getConversationById($conversationId);
        if (!$conversation) {
            return null;
        }

        if (self::isSuperAdmin($user)) {
            return $conversation;
        }

        if ((string)($conversation['tenancy_id'] ?? '') !== (string)($user['tenancy_id'] ?? '')) {
            return null;
        }

        if (!self::isRestrictedAgent($user)) {
            return $conversation;
        }

        $assignedUserId = (int)($conversation['assigned_user_id'] ?? 0);
        $queueId = (int)($conversation['queue_id'] ?? 0);
        $queueStatus = (string)($conversation['queue_status'] ?? '');
        if ($assignedUserId === (int)$user['id']) {
            return $conversation;
        }

        if ($queueId > 0 && $queueStatus === self::CONVERSATION_STATUS_WAITING
            && self::queueVisibleToAgent($queueId, (int)$user['id'], (string)$conversation['tenancy_id'])) {
            return $conversation;
        }

        return null;
    }

    private static function getConversationRoutingDetails(int $conversationId, array $user): ?array
    {
        $conversation = self::getConversationForUser($conversationId, $user);
        if (!$conversation) {
            return null;
        }

        $queue = !empty($conversation['queue_id']) ? self::getQueueById((int)$conversation['queue_id']) : null;
        $session = self::getOpenSessionByConversation($conversationId);
        $agentName = null;
        if (!empty($conversation['assigned_user_id'])) {
            $agentName = self::getUserNameById((int)$conversation['assigned_user_id'], (string)$conversation['tenancy_id']);
        }

        return [
            'conversation_id' => $conversationId,
            'queue_id' => self::nullableInt($conversation['queue_id'] ?? null),
            'queue_name' => $queue['name'] ?? null,
            'assigned_user_id' => self::nullableInt($conversation['assigned_user_id'] ?? null),
            'assigned_user_name' => $agentName,
            'queue_status' => $conversation['queue_status'] ?? null,
            'queued_at' => $conversation['queued_at'] ?? null,
            'support_session_id' => $session['id'] ?? null,
        ];
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

    private static function normalizeQueuePayload(array $user, array $input, ?array $existing = null): array
    {
        $name = trim((string)($input['name'] ?? ($existing['name'] ?? '')));
        if ($name === '') {
            throw new \InvalidArgumentException('Informe o nome da fila.');
        }

        $accountId = self::nullableInt($input['account_id'] ?? ($existing['account_id'] ?? null));
        if ($accountId !== null && !WhatsAppAccount::getForUser($accountId, $user)) {
            throw new \InvalidArgumentException('Conta WhatsApp não encontrada para esta tenancy.');
        }

        $status = strtolower((string)($input['status'] ?? ($existing['status'] ?? 'active')));
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        $color = self::normalizeColor((string)($input['color'] ?? ($existing['color'] ?? '#10b981')));
        $businessHours = self::normalizeBusinessHours($input['business_hours'] ?? ($existing['business_hours'] ?? null));
        $isDefault = array_key_exists('is_default', $input)
            ? !empty($input['is_default'])
            : !empty($existing['is_default']);

        return [[
            'name' => $name,
            'description' => self::nullableString($input['description'] ?? ($existing['description'] ?? null)),
            'color' => $color,
            'priority' => (int)($input['priority'] ?? ($existing['priority'] ?? 0)),
            'status' => $status,
            'greeting_message' => self::nullableString($input['greeting_message'] ?? ($existing['greeting_message'] ?? null)),
            'out_of_hours_message' => self::nullableString($input['out_of_hours_message'] ?? ($existing['out_of_hours_message'] ?? null)),
            'business_hours' => $businessHours,
        ], $accountId, $isDefault];
    }

    private static function normalizeBusinessHours(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
            $decoded = json_decode($value, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Horário de atendimento precisa ser um JSON válido.');
            }
            $value = $decoded;
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException('Horário de atendimento inválido.');
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function normalizeColor(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return '#10b981';
        }

        return strtoupper($value);
    }

    private static function clearDefaultQueue(array $user, ?int $accountId, ?int $exceptQueueId): void
    {
        $where = 'tenancy_id = :tenancy_id AND is_default = 1';
        $params = [':tenancy_id' => $user['tenancy_id']];
        if ($accountId !== null) {
            $where .= ' AND account_id = :account_id';
            $params[':account_id'] = $accountId;
        } else {
            $where .= ' AND account_id IS NULL';
        }
        if ($exceptQueueId !== null) {
            $where .= ' AND id <> :except_id';
            $params[':except_id'] = $exceptQueueId;
        }

        (new Database('whatsapp_support_queues'))->update($where, ['is_default' => 0], $params);
    }

    private static function syncConversationRouting(int $conversationId, array $values): void
    {
        $update = [];
        if (self::conversationHasColumn('queue_id')) {
            $update['queue_id'] = $values['queue_id'] ?? null;
        }
        if (self::conversationHasColumn('assigned_user_id')) {
            $update['assigned_user_id'] = $values['assigned_agent_user_id'] ?? null;
        }
        if (self::conversationHasColumn('queue_status')) {
            $update['queue_status'] = $values['queue_status'] ?? null;
        }
        if (self::conversationHasColumn('queued_at')) {
            $update['queued_at'] = $values['queued_at'] ?? null;
        }

        if ($update === []) {
            return;
        }

        (new Database('whatsapp_conversations'))->update('id = :id', $update, [':id' => $conversationId]);
    }

    private static function registerQueueHistory(
        string $tenancyId,
        int $conversationId,
        ?int $sessionId,
        ?int $oldQueueId,
        ?int $newQueueId,
        ?int $oldAssignedUserId,
        ?int $newAssignedUserId,
        int $changedBy,
        string $action,
        array $metadata = []
    ): void {
        try {
            (new Database('whatsapp_support_queue_history'))->insert([
                'tenancy_id' => $tenancyId,
                'conversation_id' => $conversationId,
                'session_id' => $sessionId,
                'old_queue_id' => $oldQueueId,
                'new_queue_id' => $newQueueId,
                'old_assigned_user_id' => $oldAssignedUserId,
                'new_assigned_user_id' => $newAssignedUserId,
                'changed_by' => $changedBy,
                'action' => $action,
                'metadata' => $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[whatsapp_queue_history] ' . $e->getMessage());
        }
    }

    private static function userBelongsToTenancy(int $userId, string $tenancyId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $row = (new Database('users'))
            ->select('id = :id AND tenancy_id = :tenancy_id', [
                ':id' => $userId,
                ':tenancy_id' => $tenancyId,
            ], '', '1', ['id'])
            ->fetch(PDO::FETCH_ASSOC);

        return (bool)$row;
    }

    private static function getUserNameById(int $userId, string $tenancyId): ?string
    {
        $row = (new Database('users'))
            ->select('id = :id AND tenancy_id = :tenancy_id', [
                ':id' => $userId,
                ':tenancy_id' => $tenancyId,
            ], '', '1', ['name'])
            ->fetch(PDO::FETCH_ASSOC);

        return $row['name'] ?? null;
    }

    private static function queueVisibleToAgent(int $queueId, int $agentUserId, string $tenancyId): bool
    {
        $row = (new Database('whatsapp_support_queue_agents'))
            ->select(
                'queue_id = :queue_id AND agent_user_id = :agent_user_id AND tenancy_id = :tenancy_id',
                [
                    ':queue_id' => $queueId,
                    ':agent_user_id' => $agentUserId,
                    ':tenancy_id' => $tenancyId,
                ],
                '',
                '1',
                ['id']
            )
            ->fetch(PDO::FETCH_ASSOC);

        return (bool)$row;
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
        return self::normalizedRole($user) === 'super_admin';
    }

    private static function canViewAllQueues(array $user): bool
    {
        return in_array(
            self::normalizedRole($user),
            ['admin', 'manager', 'supervisor', 'monitor', 'support_l2', 'support_ticket_manager'],
            true
        );
    }

    private static function assertQueueWriteAccess(array $user): void
    {
        if (self::isSuperAdmin($user)) {
            return;
        }

        $role = self::normalizedRole($user);
        if (!in_array($role, ['admin'], true)) {
            throw new \InvalidArgumentException('Apenas administradores podem alterar filas.');
        }
    }

    private static function isRestrictedAgent(array $user): bool
    {
        return in_array(self::normalizedRole($user), ['agent', 'support_l1', 'operator', 'o', 'ticket_support'], true);
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

    private static function normalizedRole(array $user): string
    {
        return strtolower(trim((string)($user['user_function'] ?? $user['function'] ?? '')));
    }
}
