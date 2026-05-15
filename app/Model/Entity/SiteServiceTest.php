<?php

namespace App\Model\Entity;

use PDO;
use WilliamCosta\DatabaseManager\Database;

class SiteServiceTest
{
    public static function ensureSchema(): void
    {
        (new Database())->execute("
            CREATE TABLE IF NOT EXISTS site_service_tests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(190) NOT NULL,
                service_type ENUM('voice','sms','whatsapp') NOT NULL,
                destination VARCHAR(30) NULL,
                status VARCHAR(30) DEFAULT 'pending',
                provider VARCHAR(80) NULL,
                provider_message_id VARCHAR(120) NULL,
                provider_response JSON NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                request_payload JSON NULL,
                error_message TEXT NULL,
                sent_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_email_service (email, service_type),
                KEY idx_service_type (service_type),
                KEY idx_status (status),
                KEY idx_created_at (created_at),
                KEY idx_ip_address (ip_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public static function findByEmailAndService(string $email, string $serviceType): ?array
    {
        self::ensureSchema();

        $row = (new Database('site_service_tests'))
            ->select(
                'email = :email AND service_type = :service_type',
                [':email' => $email, ':service_type' => $serviceType],
                '',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function findByEmail(string $email): ?array
    {
        self::ensureSchema();

        $row = (new Database('site_service_tests'))
            ->select(
                'email = :email',
                [':email' => $email],
                'id DESC',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function findByDestination(string $destination): ?array
    {
        self::ensureSchema();

        $destination = preg_replace('/\D+/', '', $destination);
        if ($destination === '') {
            return null;
        }

        $row = (new Database('site_service_tests'))
            ->select(
                'destination = :destination',
                [':destination' => $destination],
                'id DESC',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function findByDestinationAndService(string $destination, string $serviceType): ?array
    {
        self::ensureSchema();

        $destination = preg_replace('/\D+/', '', $destination);
        if ($destination === '') {
            return null;
        }

        $row = (new Database('site_service_tests'))
            ->select(
                'destination = :destination AND service_type = :service_type',
                [':destination' => $destination, ':service_type' => $serviceType],
                'id DESC',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function findByIp(string $ipAddress): ?array
    {
        self::ensureSchema();

        if (trim($ipAddress) === '') {
            return null;
        }

        $row = (new Database('site_service_tests'))
            ->select(
                'ip_address = :ip',
                [':ip' => $ipAddress],
                'id DESC',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function findByIpAndService(string $ipAddress, string $serviceType): ?array
    {
        self::ensureSchema();

        if (trim($ipAddress) === '') {
            return null;
        }

        $row = (new Database('site_service_tests'))
            ->select(
                'ip_address = :ip AND service_type = :service_type',
                [':ip' => $ipAddress, ':service_type' => $serviceType],
                'id DESC',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function findByDestinationAndServiceToday(string $destination, string $serviceType): ?array
    {
        self::ensureSchema();

        $destination = preg_replace('/\D+/', '', $destination);
        if ($destination === '') {
            return null;
        }

        $row = (new Database('site_service_tests'))
            ->select(
                'destination = :destination AND service_type = :service_type AND DATE(created_at) = CURDATE()',
                [':destination' => $destination, ':service_type' => $serviceType],
                'id DESC',
                '1'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function countDestinationToday(string $destination): int
    {
        self::ensureSchema();

        $destination = preg_replace('/\D+/', '', $destination);
        if ($destination === '') {
            return 0;
        }

        $row = (new Database('site_service_tests'))
            ->select(
                'destination = :destination AND DATE(created_at) = CURDATE()',
                [':destination' => $destination],
                '',
                '1',
                'COUNT(*) AS total'
            )
            ->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    public static function createPending(array $data): int
    {
        self::ensureSchema();

        $payload = isset($data['request_payload'])
            ? json_encode($data['request_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $stmt = (new Database())->execute("
            INSERT IGNORE INTO site_service_tests
                (email, service_type, destination, status, provider, ip_address, user_agent, request_payload)
            VALUES
                (:email, :service_type, :destination, 'pending', :provider, :ip_address, :user_agent, :request_payload)
        ", [
            ':email' => $data['email'],
            ':service_type' => $data['service_type'],
            ':destination' => $data['destination'] ?? null,
            ':provider' => $data['provider'] ?? null,
            ':ip_address' => $data['ip_address'] ?? null,
            ':user_agent' => mb_substr((string)($data['user_agent'] ?? ''), 0, 255),
            ':request_payload' => $payload,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('duplicate_site_service_test');
        }

        $row = self::findByEmailAndService((string)$data['email'], (string)$data['service_type']);
        return (int)($row['id'] ?? 0);
    }

    public static function updateResult(int $id, array $data): bool
    {
        self::ensureSchema();

        return (new Database('site_service_tests'))->update('id = :id', [
            'status' => $data['status'] ?? 'failed',
            'provider' => $data['provider'] ?? null,
            'provider_message_id' => $data['provider_message_id'] ?? null,
            'provider_response' => isset($data['provider_response'])
                ? json_encode($data['provider_response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'error_message' => $data['error_message'] ?? null,
            'sent_at' => ($data['status'] ?? '') === 'sent' ? date('Y-m-d H:i:s') : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], [':id' => $id]);
    }

    public static function list(array $filters = [], int $limit = 200): array
    {
        self::ensureSchema();

        $where = '1=1';
        $params = [];

        if (!empty($filters['service_type'])) {
            $where .= ' AND service_type = :service_type';
            $params[':service_type'] = $filters['service_type'];
        }

        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['email'])) {
            $where .= ' AND email LIKE :email';
            $params[':email'] = '%' . $filters['email'] . '%';
        }

        if (!empty($filters['destination'])) {
            $where .= ' AND destination LIKE :destination';
            $params[':destination'] = '%' . preg_replace('/\D+/', '', (string)$filters['destination']) . '%';
        }

        if (!empty($filters['date_from'])) {
            $where .= ' AND created_at >= :date_from';
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where .= ' AND created_at <= :date_to';
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        return (new Database('site_service_tests'))
            ->select($where, $params, 'created_at DESC, id DESC', (string)max(1, min($limit, 1000)))
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function countRecent(string $where, array $params, int $minutes = 60): int
    {
        self::ensureSchema();

        $params[':since'] = date('Y-m-d H:i:s', time() - (max(1, $minutes) * 60));
        $row = (new Database('site_service_tests'))
            ->select("{$where} AND created_at >= :since", $params, '', '1', 'COUNT(*) AS total')
            ->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    public static function publicStats(int $recentLimit = 8): array
    {
        self::ensureSchema();

        $totals = [
            'sms' => 0,
            'voice' => 0,
            'whatsapp' => 0,
        ];
        $statusCounts = [
            'sent' => 0,
            'failed' => 0,
            'pending' => 0,
        ];

        $groupedRows = (new Database())->execute("
            SELECT service_type, status, COUNT(*) AS total
            FROM site_service_tests
            GROUP BY service_type, status
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totals = ['sms' => 0, 'voice' => 0, 'whatsapp' => 0];
        $statusCounts = ['sent' => 0, 'failed' => 0, 'pending' => 0];
        foreach ($groupedRows as $row) {
            $serviceType = (string)($row['service_type'] ?? '');
            $status = (string)($row['status'] ?? 'pending');
            $total = (int)($row['total'] ?? 0);

            if (array_key_exists($serviceType, $totals)) {
                $totals[$serviceType] += $total;
            }

            if (array_key_exists($status, $statusCounts)) {
                $statusCounts[$status] += $total;
            }
        }

        $recentRows = (new Database('site_service_tests'))
            ->select('1=1', [], 'created_at DESC, id DESC', (string)max(1, min($recentLimit, 20)), [
                'id',
                'service_type',
                'status',
                'destination',
                'created_at',
            ])
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $recent = array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'service_type' => (string)($row['service_type'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'destination' => self::maskDestination((string)($row['destination'] ?? '')),
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }, $recentRows);

        $total = $totals['sms'] + $totals['voice'] + $totals['whatsapp'];

        return [
            'sms' => $totals['sms'],
            'voice' => $totals['voice'],
            'whatsapp' => $totals['whatsapp'],
            'total' => $total,
            'status' => [
                'success' => $statusCounts['sent'],
                'failed' => $statusCounts['failed'],
                'pending' => $statusCounts['pending'],
            ],
            'recent' => $recent,
            'generated_at' => date('c'),
        ];
    }

    private static function maskDestination(string $destination): string
    {
        $digits = preg_replace('/\D+/', '', $destination);
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }

        return substr($digits, 0, 4) . str_repeat('*', max(0, strlen($digits) - 8)) . substr($digits, -4);
    }
}
