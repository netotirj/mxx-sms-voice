<?php

namespace App\Service;

use App\Model\Entity\PermissionsRules;
use App\Model\Entity\WorkerHeartbeat;
use App\RedisConn;
use Throwable;

class ServicesMonitorService
{
    private const int DEFAULT_LOG_LINES = 100;
    private const int LAST_LOG_LINES = 20;
    private const int STALE_HEARTBEAT_SECONDS = 120;
    private const array FIXED_WHITELIST = [
        'maxx-cdr-worker.service',
        'maxx-voice-worker.service',
        'maxx-whatsapp-worker.service',
    ];

    public static function ensureRouteCatalog(): void
    {
        PermissionsRules::registerGlobalRoute(
            'admin_services_monitor',
            'Administrativo: Monitoramento de Serviços',
            [
                '/admin/services-monitor',
                '/admin/services-monitor/status',
                '/admin/services-monitor/logs',
            ]
        );
    }

    public static function statusSnapshot(): array
    {
        $services = self::allowedServices();
        $heartbeats = WorkerHeartbeat::listByServices($services);
        $queueHealth = self::queueHealth();

        $items = [];
        foreach ($services as $serviceName) {
            $systemd = self::serviceSnapshot($serviceName);
            $heartbeat = $heartbeats[$serviceName] ?? null;
            $item = array_merge(
                self::baseDefinition($serviceName),
                $systemd,
                [
                    'heartbeat' => self::heartbeatSnapshot($heartbeat),
                    'queue_health' => $queueHealth[$serviceName] ?? null,
                ]
            );
            $item['alerts'] = self::buildAlerts($item);
            $items[] = $item;
        }

        return [
            'supported' => self::systemdSupported(),
            'host' => gethostname() ?: php_uname('n'),
            'generated_at' => date('Y-m-d H:i:s'),
            'services' => $items,
            'allowed_services' => $services,
        ];
    }

    public static function recentLogs(string $serviceName, int $lines = self::DEFAULT_LOG_LINES): array
    {
        $serviceName = self::assertAllowedService($serviceName);
        $lines = max(10, min(300, $lines));

        if (!self::systemdSupported()) {
            return [
                'service' => $serviceName,
                'supported' => false,
                'lines' => [],
                'last_line' => 'Host atual não suporta systemd/journalctl.',
            ];
        }

        $command = 'journalctl -u ' . escapeshellarg($serviceName) . ' -n ' . $lines . ' --no-pager 2>&1';
        $result = self::runCommand($command);
        $rawLines = preg_split('/\r\n|\r|\n/', trim((string)$result['output'])) ?: [];
        $sanitized = array_values(array_filter(array_map([self::class, 'sanitizeLogLine'], $rawLines), static fn ($line): bool => $line !== ''));

        return [
            'service' => $serviceName,
            'supported' => true,
            'command_ok' => (bool)$result['ok'],
            'lines' => $sanitized,
            'last_line' => self::lastMeaningfulLine($sanitized),
        ];
    }

    public static function allowedServices(): array
    {
        $services = array_fill_keys(self::FIXED_WHITELIST, true);

        foreach (self::discoverMaxxServices() as $serviceName) {
            $services[$serviceName] = true;
        }

        return array_keys($services);
    }

    private static function assertAllowedService(string $serviceName): string
    {
        $serviceName = trim($serviceName);
        if ($serviceName === '' || !preg_match('/^[a-zA-Z0-9._@-]+\.service$/', $serviceName)) {
            throw new \InvalidArgumentException('Serviço inválido.');
        }

        if (!in_array($serviceName, self::allowedServices(), true)) {
            throw new \InvalidArgumentException('Serviço fora da whitelist.');
        }

        return $serviceName;
    }

    private static function discoverMaxxServices(): array
    {
        if (!self::systemdSupported()) {
            return [];
        }

        $candidates = [];
        foreach ([
            "systemctl list-unit-files 'maxx-*.service' --type=service --no-legend --no-pager 2>/dev/null",
            "systemctl list-units 'maxx-*.service' --type=service --all --no-legend --no-pager 2>/dev/null",
        ] as $command) {
            $result = self::runCommand($command);
            $lines = preg_split('/\r\n|\r|\n/', trim((string)$result['output'])) ?: [];
            foreach ($lines as $line) {
                if (!preg_match('/\b(maxx-[a-z0-9._@-]+\.service)\b/i', $line, $matches)) {
                    continue;
                }
                $candidates[strtolower($matches[1])] = $matches[1];
            }
        }

        return array_values($candidates);
    }

    private static function serviceSnapshot(string $serviceName): array
    {
        if (!self::systemdSupported()) {
            return [
                'load_state' => 'unsupported',
                'active_state' => 'unsupported',
                'sub_state' => 'unsupported',
                'unit_file_state' => 'unknown',
                'status_label' => 'unsupported',
                'status_color' => 'slate',
                'is_critical_problem' => false,
                'uptime_human' => null,
                'last_restart' => null,
                'main_pid' => null,
                'memory_mb' => null,
                'cpu_seconds' => null,
                'last_log_line' => 'Host atual não suporta systemd.',
            ];
        }

        $result = self::runCommand(
            'systemctl show ' . escapeshellarg($serviceName) . ' --no-page '
            . '--property=Id,Description,LoadState,ActiveState,SubState,UnitFileState,MainPID,ExecMainStartTimestamp,ActiveEnterTimestamp,InactiveEnterTimestamp,Result,MemoryCurrent,CPUUsageNSec,NRestarts 2>&1'
        );
        $properties = self::parseSystemctlShow((string)$result['output']);
        $loadState = strtolower((string)($properties['LoadState'] ?? 'unknown'));
        $activeState = strtolower((string)($properties['ActiveState'] ?? 'unknown'));
        $subState = strtolower((string)($properties['SubState'] ?? 'unknown'));
        $unitFileState = strtolower((string)($properties['UnitFileState'] ?? 'unknown'));

        [$statusLabel, $statusColor, $critical] = self::visualState($loadState, $activeState, $subState);

        return [
            'description' => (string)($properties['Description'] ?? self::baseDefinition($serviceName)['description']),
            'load_state' => $loadState,
            'active_state' => $activeState,
            'sub_state' => $subState,
            'unit_file_state' => $unitFileState,
            'status_label' => $statusLabel,
            'status_color' => $statusColor,
            'is_critical_problem' => $critical,
            'enabled_on_boot' => in_array($unitFileState, ['enabled', 'enabled-runtime', 'static', 'alias'], true),
            'uptime_human' => self::uptimeFromTimestamp((string)($properties['ActiveEnterTimestamp'] ?? '')),
            'last_restart' => self::normalizeTimestamp((string)($properties['ExecMainStartTimestamp'] ?? $properties['ActiveEnterTimestamp'] ?? '')),
            'main_pid' => (($properties['MainPID'] ?? '') !== '' && (int)$properties['MainPID'] > 0) ? (int)$properties['MainPID'] : null,
            'memory_mb' => isset($properties['MemoryCurrent']) && is_numeric($properties['MemoryCurrent'])
                ? round(((float)$properties['MemoryCurrent']) / 1048576, 2)
                : null,
            'cpu_seconds' => isset($properties['CPUUsageNSec']) && is_numeric($properties['CPUUsageNSec'])
                ? round(((float)$properties['CPUUsageNSec']) / 1000000000, 2)
                : null,
            'restart_count' => isset($properties['NRestarts']) && is_numeric($properties['NRestarts']) ? (int)$properties['NRestarts'] : 0,
            'last_log_line' => self::lastRelevantLogLine($serviceName),
        ];
    }

    private static function heartbeatSnapshot(?array $row): ?array
    {
        if (!$row) {
            return null;
        }

        $lastSeen = (string)($row['last_seen_at'] ?? '');
        $seconds = self::secondsSince($lastSeen);

        return [
            'last_seen_at' => $lastSeen !== '' ? $lastSeen : null,
            'seconds_since' => $seconds,
            'is_stale' => $seconds !== null ? $seconds > self::STALE_HEARTBEAT_SECONDS : false,
            'last_status' => (string)($row['last_status'] ?? 'unknown'),
            'last_message' => (string)($row['last_message'] ?? ''),
            'processed_count' => (int)($row['processed_count'] ?? 0),
            'failed_count' => (int)($row['failed_count'] ?? 0),
            'memory_mb' => isset($row['memory_mb']) ? (float)$row['memory_mb'] : null,
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    private static function buildAlerts(array $item): array
    {
        $alerts = [];

        if (($item['sub_state'] ?? '') === 'auto-restart') {
            $alerts[] = [
                'level' => 'critical',
                'message' => 'Serviço em loop de auto-restart.',
            ];
        }

        $heartbeat = $item['heartbeat'] ?? null;
        if (is_array($heartbeat) && !empty($heartbeat['is_stale'])) {
            $alerts[] = [
                'level' => 'warning',
                'message' => 'Heartbeat atrasado há ' . (int)($heartbeat['seconds_since'] ?? 0) . 's.',
            ];
        }

        $queueHealth = $item['queue_health'] ?? null;
        if (is_array($queueHealth) && !empty($queueHealth['stuck'])) {
            $alerts[] = [
                'level' => 'critical',
                'message' => (string)($queueHealth['message'] ?? 'Fila com indício de travamento.'),
            ];
        }

        return $alerts;
    }

    private static function queueHealth(): array
    {
        try {
            $redis = RedisConn::get();
        } catch (Throwable) {
            return [];
        }

        $health = [];

        try {
            $voiceQueue = (int)$redis->llen('voice:queue');
            $health['maxx-voice-worker.service'] = [
                'queue_size' => $voiceQueue,
                'stuck' => $voiceQueue > 0 && self::heartbeatStale('maxx-voice-worker.service'),
                'message' => $voiceQueue > 0 ? "Fila voice:queue pendente: {$voiceQueue}" : 'Fila voice:queue vazia.',
            ];
        } catch (Throwable) {
        }

        try {
            $cdrQueue = (int)$redis->llen('asterisk:tarifacoes');
            $health['maxx-cdr-worker.service'] = [
                'queue_size' => $cdrQueue,
                'stuck' => $cdrQueue > 0 && self::heartbeatStale('maxx-cdr-worker.service'),
                'message' => $cdrQueue > 0 ? "Fila asterisk:tarifacoes pendente: {$cdrQueue}" : 'Fila de CDR vazia.',
            ];
        } catch (Throwable) {
        }

        return $health;
    }

    private static function heartbeatStale(string $serviceName): bool
    {
        $map = WorkerHeartbeat::listByServices([$serviceName]);
        $row = $map[$serviceName] ?? null;
        if (!$row) {
            return false;
        }

        $seconds = self::secondsSince((string)($row['last_seen_at'] ?? ''));
        return $seconds !== null && $seconds > self::STALE_HEARTBEAT_SECONDS;
    }

    private static function lastRelevantLogLine(string $serviceName): ?string
    {
        $logs = self::recentLogs($serviceName, self::LAST_LOG_LINES);
        return $logs['last_line'] ?? null;
    }

    private static function baseDefinition(string $serviceName): array
    {
        $definitions = [
            'maxx-cdr-worker.service' => [
                'service_name' => $serviceName,
                'worker_type' => 'cdr',
                'description' => 'Processa tarifações e eventos de CDR vindos do Asterisk.',
            ],
            'maxx-voice-worker.service' => [
                'service_name' => $serviceName,
                'worker_type' => 'voice',
                'description' => 'Consome a fila de voz, origina chamadas e move payloads entre filas.',
            ],
            'maxx-whatsapp-worker.service' => [
                'service_name' => $serviceName,
                'worker_type' => 'whatsapp',
                'description' => 'Processa a fila de envios do WhatsApp e registra resultado no sistema.',
            ],
        ];

        return $definitions[$serviceName] ?? [
            'service_name' => $serviceName,
            'worker_type' => 'service',
            'description' => 'Serviço Maxx descoberto automaticamente no host.',
        ];
    }

    private static function systemdSupported(): bool
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        $result = self::runCommand('command -v systemctl 2>/dev/null');
        return $result['ok'] && trim((string)$result['output']) !== '';
    }

    private static function runCommand(string $command): array
    {
        if (!function_exists('exec')) {
            return ['ok' => false, 'status' => 127, 'output' => 'exec() desabilitado neste host.'];
        }

        $output = [];
        $status = 0;
        @exec($command, $output, $status);

        return [
            'ok' => $status === 0,
            'status' => $status,
            'output' => implode("\n", $output),
        ];
    }

    private static function parseSystemctlShow(string $raw): array
    {
        $properties = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($raw)) ?: [] as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $properties[trim($key)] = trim($value);
        }

        return $properties;
    }

    private static function visualState(string $loadState, string $activeState, string $subState): array
    {
        if ($loadState === 'not-found') {
            return ['not_found', 'slate', false];
        }

        if ($subState === 'auto-restart') {
            return ['auto_restart', 'amber', true];
        }

        if ($activeState === 'active' && $subState === 'running') {
            return ['running', 'emerald', false];
        }

        if (in_array($activeState, ['activating', 'reloading'], true) || in_array($subState, ['start', 'restart', 'exited'], true)) {
            return ['starting', 'amber', false];
        }

        if (in_array($activeState, ['failed', 'inactive', 'deactivating'], true)) {
            return ['stopped', 'rose', true];
        }

        return ['unknown', 'slate', false];
    }

    private static function uptimeFromTimestamp(string $timestamp): ?string
    {
        $seconds = self::secondsSince($timestamp);
        if ($seconds === null) {
            return null;
        }

        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes . 'm';
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return $hours . 'h ' . ($minutes % 60) . 'm';
        }

        $days = intdiv($hours, 24);
        return $days . 'd ' . ($hours % 24) . 'h';
    }

    private static function secondsSince(string $timestamp): ?int
    {
        $timestamp = trim($timestamp);
        if ($timestamp === '') {
            return null;
        }

        $value = strtotime($timestamp);
        if (!$value) {
            return null;
        }

        return max(0, time() - $value);
    }

    private static function normalizeTimestamp(string $timestamp): ?string
    {
        $timestamp = trim($timestamp);
        return $timestamp !== '' ? $timestamp : null;
    }

    private static function lastMeaningfulLine(array $lines): ?string
    {
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $line = trim((string)$lines[$index]);
            if ($line !== '') {
                return $line;
            }
        }

        return null;
    }

    private static function sanitizeLogLine(string $line): string
    {
        $line = trim($line);
        if ($line === '') {
            return '';
        }

        $patterns = [
            '/(Authorization:\s*Bearer\s+)[^\s]+/i' => '$1***',
            '/(bearer\s+)[^\s]+/i' => '$1***',
            '/((?:token|secret|password|passwd|api[_-]?key)[=:]\s*)[^\s"\']+/i' => '$1***',
            '/("?(?:token|secret|password|passwd|api[_-]?key)"?\s*:\s*")[^"]+(")/i' => '$1***$2',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $line) ?? $line;
    }
}
