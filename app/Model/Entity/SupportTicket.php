<?php

namespace App\Model\Entity;

use App\Utils\TenancyHelper;
use PDO;
use WilliamCosta\DatabaseManager\Database;

class SupportTicket
{
    public static function create(array $user, array $data): int
    {
        $department = self::normalizeDepartment((string)($data['department'] ?? 'support'));
        $message = trim((string)($data['message'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string)($data['requester_phone'] ?? '')) ?: null;

        $id = (int)(new Database('support_tickets'))->insert([
            'tenancy_id' => $user['tenancy_id'],
            'user_id' => (int)$user['id'],
            'requester_name' => $user['name'] ?? null,
            'requester_phone' => $phone,
            'department' => $department,
            'subject' => self::subjectFromDepartment($department),
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

    public static function listForUser(array $user, ?string $status = null): array
    {
        $where = TenancyHelper::applySecurityFilter('', $user, 'user_id', 'st');
        $params = [];

        if ($status !== null && $status !== '') {
            $where = "({$where}) AND st.status = :status";
            $params[':status'] = self::normalizeStatus($status);
        }

        return (new Database('support_tickets st'))
            ->select($where, $params, 'st.last_message_at DESC, st.id DESC', '', [
                'st.*',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getForUser(int $id, array $user): ?array
    {
        $where = TenancyHelper::applySecurityFilter('id = :id', $user, 'user_id', 'support_tickets');
        $row = (new Database('support_tickets'))
            ->select($where, [':id' => $id], '', '1')
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
}
