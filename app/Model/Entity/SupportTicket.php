<?php

namespace App\Model\Entity;

use App\Service\PermissionResolver;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class SupportTicket
{
    private const SUPPORT_ROLES = ['super_admin', 'support_ticket_manager', 'ticket_support'];
    private const STATUS_MANAGER_ROLES = ['super_admin', 'support_ticket_manager', 'ticket_support'];
    private const STATUS_ALIASES = [
        'pending' => 'waiting_customer',
        'in_progress' => 'in_progress',
        'waiting_customer' => 'waiting_customer',
        'closure_requested' => 'closure_requested',
        'closed' => 'closed',
        'open' => 'open',
    ];
    private const PERMISSION_KEYS = [
        'view_own' => 'ticket.view_own',
        'view_all' => 'ticket.view_all',
        'create' => 'ticket.create',
        'reply' => 'ticket.reply',
        'update' => 'ticket.update',
        'change_status' => 'ticket.change_status',
        'assign' => 'ticket.assign',
        'change_priority' => 'ticket.change_priority',
        'delete' => 'ticket.delete',
    ];

    public static function create(array $user, array $data): int
    {
        $department = self::normalizeDepartment((string)($data['department'] ?? 'support'));
        $message = trim((string)($data['message'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string)($data['requester_phone'] ?? '')) ?: null;

        $id = (int)(new Database('support_tickets'))->insert([
            'tenancy_id' => $user['tenancy_id'],
            'user_id' => (int)$user['id'],
            'requester_name' => self::normalizeRequesterName((string)($data['requester_name'] ?? ''), $user),
            'requester_phone' => $phone,
            'department' => $department,
            'subject' => self::normalizeSubject((string)($data['subject'] ?? ''), $department),
            'status' => 'open',
            'priority' => 'normal',
            'last_message' => mb_substr($message, 0, 500),
            'last_message_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if ($message !== '') {
            self::addMessage($id, $user, 'customer', $message);
        }

        return $id;
    }

    public static function listForUser(array $user, ?string $status = null, ?string $department = null): array
    {
        [$where, $params] = self::scopeForUser($user, 'st');

        if ($status !== null && $status !== '') {
            $where = "({$where}) AND st.status = :status";
            $params[':status'] = self::normalizeStatus($status);
        }

        if ($department !== null && $department !== '') {
            $where = "({$where}) AND st.department = :department";
            $params[':department'] = self::normalizeDepartment($department);
        }

        return (new Database('support_tickets st LEFT JOIN users close_u ON close_u.id = st.closed_by_user_id'))
            ->select($where, $params, 'st.last_message_at DESC, st.id DESC', '', [
                'st.*',
                'close_u.name AS closed_by_name',
                'close_u.last_name AS closed_by_last_name',
                "(SELECT stm.body
                    FROM support_ticket_messages stm
                   WHERE stm.ticket_id = st.id
                   ORDER BY stm.id ASC
                   LIMIT 1) AS opening_message",
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getForUser(int $id, array $user): ?array
    {
        [$scope, $params] = self::scopeForUser($user, 'st');
        $params[':id'] = $id;

        $row = (new Database('support_tickets st LEFT JOIN users close_u ON close_u.id = st.closed_by_user_id'))
            ->select("(st.id = :id) AND ({$scope})", $params, '', '1', [
                'st.*',
                'close_u.name AS closed_by_name',
                'close_u.last_name AS closed_by_last_name',
            ])
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function getById(int $id): ?array
    {
        $row = (new Database('support_tickets'))
            ->select('id = :id', [':id' => $id], '', '1')
            ->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function listMessagesForUser(int $ticketId, array $user): array
    {
        if (!self::getForUser($ticketId, $user)) {
            return [];
        }

        return (new Database('support_ticket_messages stm LEFT JOIN users u ON u.id = stm.sender_user_id'))
            ->select('stm.ticket_id = :ticket_id', [':ticket_id' => $ticketId], 'stm.id ASC', '', [
                'stm.*',
                'u.name AS sender_name',
                'u.last_name AS sender_last_name',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function listAuditForUser(int $ticketId, array $user): array
    {
        if (!self::getForUser($ticketId, $user)) {
            return [];
        }

        return (new Database('support_ticket_audit_logs al LEFT JOIN users u ON u.id = al.user_id'))
            ->select('al.ticket_id = :ticket_id', [':ticket_id' => $ticketId], 'al.id ASC', '', [
                'al.*',
                'u.name AS user_name',
                'u.last_name AS user_last_name',
                'u.email AS user_email',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function addMessage(int $ticketId, array $user, string $senderType, string $body): int
    {
        $body = trim($body);
        $id = (int)(new Database('support_ticket_messages'))->insert([
            'ticket_id' => $ticketId,
            'sender_user_id' => (int)($user['id'] ?? 0),
            'sender_type' => $senderType,
            'body' => $body,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        (new Database('support_tickets'))->update(
            'id = :id',
            [
                'last_message' => mb_substr($body, 0, 500),
                'last_message_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':id' => $ticketId]
        );

        return $id;
    }

    public static function updateStatus(int $ticketId, string $status, ?array $actor = null): bool
    {
        $status = self::normalizeStatus($status);
        $values = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($status === 'closure_requested') {
            $ticket = self::getById($ticketId);
            $values['closure_requested_at'] = $ticket['closure_requested_at'] ?? date('Y-m-d H:i:s');
            $values['closure_requested_by'] = $ticket['closure_requested_by'] ?? null;
            $values['closed_at'] = null;
            $values['closed_by_user_id'] = null;
        } elseif ($status === 'closed') {
            $ticket = self::getById($ticketId);
            $values['closure_requested_at'] = null;
            $values['closure_requested_by'] = null;
            $values['closed_at'] = $ticket['closed_at'] ?? date('Y-m-d H:i:s');
            $values['closed_by_user_id'] = (int)($actor['id'] ?? ($ticket['closed_by_user_id'] ?? 0)) ?: null;
        } else {
            $values['closure_requested_at'] = null;
            $values['closure_requested_by'] = null;
            $values['closed_at'] = null;
            $values['closed_by_user_id'] = null;
        }

        return (new Database('support_tickets'))->update(
            'id = :id',
            $values,
            [':id' => $ticketId]
        );
    }

    public static function requestClosure(int $ticketId, array $user): bool
    {
        $updated = (new Database('support_tickets'))->update(
            'id = :id',
            [
                'status' => 'closure_requested',
                'closure_requested_at' => date('Y-m-d H:i:s'),
                'closure_requested_by' => (int)($user['id'] ?? 0),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':id' => $ticketId]
        );

        if ($updated) {
            self::addMessage(
                $ticketId,
                $user,
                'customer',
                'Solicito o encerramento deste atendimento quando a equipe validar que esta tudo certo.'
            );
        }

        return $updated;
    }

    public static function normalizeStatusValue(string $status): string
    {
        return self::normalizeStatus($status);
    }

    public static function normalizeDepartmentValue(string $department): string
    {
        return self::normalizeDepartment($department);
    }

    public static function can(array $user, string $permission, ?array $ticket = null): bool
    {
        $role = self::role($user);
        if ($role === 'super_admin') {
            return true;
        }

        $permission = self::PERMISSION_KEYS[$permission] ?? $permission;
        $isOwnTicket = $ticket !== null
            && (string)($ticket['tenancy_id'] ?? '') === (string)($user['tenancy_id'] ?? '')
            && (int)($ticket['user_id'] ?? 0) === (int)($user['id'] ?? 0);

        if ($permission === self::PERMISSION_KEYS['create']) {
            return true;
        }

        if ($permission === self::PERMISSION_KEYS['view_own']) {
            return $ticket === null || $isOwnTicket;
        }

        if ($permission === self::PERMISSION_KEYS['reply']) {
            if ($ticket === null) {
                return true;
            }
            return ($ticket !== null && $isOwnTicket) || self::isTicketSupportRole($user);
        }

        if ($permission === 'ticket.request_close') {
            return $ticket !== null
                && $isOwnTicket
                && !self::isTicketSupportRole($user)
                && (string)($ticket['status'] ?? '') !== 'closed';
        }

        if ($permission === self::PERMISSION_KEYS['change_status']) {
            return self::canManageTicketStatus($user);
        }

        if ($permission === self::PERMISSION_KEYS['update']) {
            return self::canManageTicketStatus($user);
        }

        if ($permission === self::PERMISSION_KEYS['view_all']) {
            return self::isTicketSupportRole($user) || self::hasAclPermission($user, $permission);
        }

        return self::hasAclPermission($user, $permission);
    }

    public static function capabilities(array $user): array
    {
        return [
            'view_own' => self::can($user, 'view_own'),
            'view_all' => self::can($user, 'view_all'),
            'create' => self::can($user, 'create'),
            'reply' => self::can($user, 'reply'),
            'update' => self::can($user, 'update'),
            'change_status' => self::can($user, 'change_status'),
            'request_close' => self::can($user, 'ticket.request_close'),
            'assign' => self::can($user, 'assign'),
            'change_priority' => self::can($user, 'change_priority'),
            'delete' => self::can($user, 'delete'),
            'role' => self::role($user),
        ];
    }

    public static function audit(
        int $ticketId,
        array $user,
        string $action,
        ?string $field,
        mixed $oldValue,
        mixed $newValue,
        ?string $ipAddress = null
    ): void {
        (new Database('support_ticket_audit_logs'))->insert([
            'ticket_id' => $ticketId,
            'user_id' => (int)($user['id'] ?? 0),
            'tenancy_id' => (string)($user['tenancy_id'] ?? ''),
            'action' => $action,
            'field' => $field,
            'old_value' => $oldValue !== null ? (string)$oldValue : null,
            'new_value' => $newValue !== null ? (string)$newValue : null,
            'ip_address' => $ipAddress,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function scopeForUser(array $user, string $alias = 'st'): array
    {
        $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';
        $role = self::role($user);

        if ($role === 'super_admin') {
            return ['1=1', []];
        }

        $where = "{$prefix}tenancy_id = :scope_tenancy_id";
        $params = [':scope_tenancy_id' => (string)($user['tenancy_id'] ?? '')];

        if (self::isTicketSupportRole($user)) {
            return ['1=1', []];
        }

        if (self::can($user, 'view_all')) {
            return [$where, $params];
        }

        $where .= " AND {$prefix}user_id = :scope_user_id";
        $params[':scope_user_id'] = (int)($user['id'] ?? 0);

        return [$where, $params];
    }

    private static function role(array $user): string
    {
        return strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));
    }

    private static function isTicketSupportRole(array $user): bool
    {
        return in_array(self::role($user), self::SUPPORT_ROLES, true);
    }

    private static function canManageTicketStatus(array $user): bool
    {
        return in_array(self::role($user), self::STATUS_MANAGER_ROLES, true);
    }

    private static function hasAclPermission(array $user, string $permission): bool
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        return PermissionResolver::userHasPermission($user, $permission);
    }

    private static function ensureAuditTable(): void
    {
        (new Database())->execute("
            CREATE TABLE IF NOT EXISTS support_ticket_audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ticket_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NULL,
                tenancy_id VARCHAR(64) NOT NULL,
                action VARCHAR(64) NOT NULL,
                field VARCHAR(64) NULL,
                old_value TEXT NULL,
                new_value TEXT NULL,
                ip_address VARCHAR(64) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_support_ticket_audit_ticket (ticket_id, created_at),
                KEY idx_support_ticket_audit_user (user_id, created_at),
                KEY idx_support_ticket_audit_tenancy (tenancy_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private static function ensureClosureRequestColumns(): void
    {
        static $ensured = false;
        if ($ensured) {
            self::ensureStatusColumnSupportsWorkflow();
            self::syncLegacyClosureStatuses();
            return;
        }

        $db = new Database();
        $rows = $db->execute("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'support_tickets'
              AND COLUMN_NAME IN ('closure_requested_at', 'closure_requested_by', 'closed_at', 'closed_by_user_id')
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $missing = array_diff(['closure_requested_at', 'closure_requested_by', 'closed_at', 'closed_by_user_id'], $rows);
        if ($missing === []) {
            $ensured = true;
            return;
        }

        $alterParts = [];
        if (in_array('closure_requested_at', $missing, true)) {
            $alterParts[] = 'ADD COLUMN closure_requested_at DATETIME NULL AFTER priority';
            $alterParts[] = 'ADD KEY idx_support_tickets_closure_requested_at (closure_requested_at)';
        }
        if (in_array('closure_requested_by', $missing, true)) {
            $alterParts[] = 'ADD COLUMN closure_requested_by INT UNSIGNED NULL AFTER closure_requested_at';
            $alterParts[] = 'ADD KEY idx_support_tickets_closure_requested_by (closure_requested_by)';
        }
        if (in_array('closed_at', $missing, true)) {
            $alterParts[] = 'ADD COLUMN closed_at DATETIME NULL AFTER closure_requested_by';
            $alterParts[] = 'ADD KEY idx_support_tickets_closed_at (closed_at)';
        }
        if (in_array('closed_by_user_id', $missing, true)) {
            $alterParts[] = 'ADD COLUMN closed_by_user_id INT UNSIGNED NULL AFTER closed_at';
            $alterParts[] = 'ADD KEY idx_support_tickets_closed_by_user_id (closed_by_user_id)';
        }

        if ($alterParts !== []) {
            $db->execute('ALTER TABLE support_tickets ' . implode(', ', $alterParts));
        }

        $ensured = true;
        self::ensureStatusColumnSupportsWorkflow();
        self::syncLegacyClosureStatuses();
    }

    private static function ensureStatusColumnSupportsWorkflow(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $column = (new Database())->execute("SHOW COLUMNS FROM support_tickets LIKE 'status'")->fetch(PDO::FETCH_ASSOC) ?: [];
        $type = strtolower((string)($column['Type'] ?? ''));
        $requiredStatuses = ['open', 'in_progress', 'waiting_customer', 'closure_requested', 'closed'];

        $missing = [];
        foreach ($requiredStatuses as $status) {
            if (!str_contains($type, "'" . $status . "'")) {
                $missing[] = $status;
            }
        }

        if ($missing !== []) {
            (new Database())->execute("
                ALTER TABLE support_tickets
                MODIFY COLUMN status ENUM('open','in_progress','waiting_customer','closure_requested','closed','pending')
                NOT NULL DEFAULT 'open'
            ");
        }

        $ensured = true;
    }

    private static function syncLegacyClosureStatuses(): void
    {
        static $synced = false;
        if ($synced) {
            return;
        }

        (new Database())->execute("
            UPDATE support_tickets
               SET status = 'closure_requested',
                   updated_at = NOW()
             WHERE closure_requested_at IS NOT NULL
               AND status NOT IN ('closure_requested', 'closed')
        ");

        (new Database())->execute("
            UPDATE support_tickets
               SET status = 'waiting_customer',
                   updated_at = NOW()
             WHERE status = 'pending'
        ");

        $synced = true;
    }

    public static function syncSchema(): void
    {
        self::ensureClosureRequestColumns();
        self::ensureAuditTable();
    }

    private static function normalizeDepartment(string $department): string
    {
        return in_array($department, ['support', 'commercial', 'sales', 'finance'], true) ? $department : 'support';
    }

    private static function normalizeStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        return self::STATUS_ALIASES[$normalized] ?? 'open';
    }

    private static function subjectFromDepartment(string $department): string
    {
        return match ($department) {
            'commercial' => 'Atendimento comercial',
            'sales' => 'Vendas',
            'finance' => 'Financeiro',
            default => 'Suporte tecnico',
        };
    }

    private static function normalizeSubject(string $subject, string $department): string
    {
        $subject = trim($subject);
        return $subject !== ''
            ? mb_substr($subject, 0, 160)
            : self::subjectFromDepartment($department);
    }

    private static function normalizeRequesterName(string $name, array $user): ?string
    {
        $name = trim($name);
        return $name !== '' ? mb_substr($name, 0, 160) : ($user['name'] ?? null);
    }
}
