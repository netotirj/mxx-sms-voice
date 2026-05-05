<?php

namespace App\Model\Entity;

use PDO;
use WilliamCosta\DatabaseManager\Database;

class SupportTicket
{
    private const SUPPORT_ROLES = ['support_ticket_manager', 'ticket_support'];
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

        return (new Database('support_tickets st'))
            ->select($where, $params, 'st.last_message_at DESC, st.id DESC', '', [
                'st.*',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getForUser(int $id, array $user): ?array
    {
        [$scope, $params] = self::scopeForUser($user, 'support_tickets');
        $params[':id'] = $id;

        $row = (new Database('support_tickets'))
            ->select("(id = :id) AND ({$scope})", $params, '', '1')
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

        return (new Database('support_ticket_messages'))
            ->select('ticket_id = :ticket_id', [':ticket_id' => $ticketId], 'id ASC')
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

    public static function updateStatus(int $ticketId, string $status): bool
    {
        return (new Database('support_tickets'))->update(
            'id = :id',
            [
                'status' => self::normalizeStatus($status),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [':id' => $ticketId]
        );
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
        self::ensureAuditTable();

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

    private static function hasAclPermission(array $user, string $permission): bool
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        return (bool)(new Database())->execute("
            SELECT srp.id
            FROM user_roles ur
            INNER JOIN sys_role_permissions srp ON srp.role_id = ur.role_id
            INNER JOIN sys_routes sr ON sr.id = srp.route_id
            WHERE ur.user_id = :user_id
              AND ur.tenancy_id = :tenancy_id
              AND srp.tenancy_id = :tenancy_id
              AND sr.route_path = :permission
            LIMIT 1
        ", [
            ':user_id' => $userId,
            ':tenancy_id' => (string)($user['tenancy_id'] ?? ''),
            ':permission' => $permission,
        ])->fetch(PDO::FETCH_ASSOC);
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

    private static function normalizeDepartment(string $department): string
    {
        return in_array($department, ['support', 'commercial', 'sales', 'finance'], true) ? $department : 'support';
    }

    private static function normalizeStatus(string $status): string
    {
        return in_array($status, ['open', 'pending', 'closed'], true) ? $status : 'open';
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
