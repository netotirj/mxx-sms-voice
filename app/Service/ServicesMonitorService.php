<?php

namespace App\Service;

use App\Config\TelephonyConfig;
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
                '/admin/services-monitor/restart',
            ]
        );
    }

    public static function serverProfiles(): array
    {
        $profiles = [];

        $profiles['local'] = [
            'key' => 'local',
            'label' => trim((string)TelephonyConfig::env('SERVICES_MONITOR_LOCAL_LABEL', 'Aplicação')),
            'mode' => 'local',
            'host' => gethostname() ?: php_uname('n'),
            'services' => self::mergeServices(
                self::FIXED_WHITELIST,
                self::parseServiceList((string)TelephonyConfig::env('SERVICES_MONITOR_LOCAL_SERVICES', ''))
            ),
            'use_sudo' => self::envBool('SERVICES_MONITOR_USE_SUDO', false),
            'sudo_bin' => trim((string)TelephonyConfig::env('SERVICES_MONITOR_SUDO_BIN', 'sudo -n')),
        ];

        $asteriskHost = trim((string)TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_HOST', ''));
        if ($asteriskHost !== '') {
            $profiles['asterisk'] = [
                'key' => 'asterisk',
                'label' => trim((string)TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_LABEL', 'Asterisk')),
                'mode' => 'ssh',
                'host' => $asteriskHost,
                'user' => trim((string)TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_USER', 'root')),
                'port' => max(1, (int)TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_PORT', 22)),
                'identity_file' => trim((string)TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_KEY', '')),
                'services' => self::mergeServices(
                    self::FIXED_WHITELIST,
                    self::parseServiceList((string)TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_SERVICES', ''))
                ),
                'use_sudo' => self::envBool('SERVICES_MONITOR_ASTERISK_USE_SUDO', false),
                'sudo_bin' => trim((string)TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_SUDO_BIN', 'sudo -n')),
                'strict_host_key' => self::envBool('SERVICES_MONITOR_ASTERISK_STRICT_HOST_KEY', false),
            ];
        }

        return $profiles;
    }

    public static function availableServers(): array
    {
        return array_values(array_map(static function (array $profile): array {
            return [
                'key' => (string)$profile['key'],
                'label' => (string)$profile['label'],
                'host' => (string)$profile['host'],
                'mode' => (string)$profile['mode'],
            ];
        }, self::serverProfiles()));
    }

    public static function statusSnapshot(?string $serverKey = null): array
    {
        $profile = self::resolveProfile($serverKey);
        $services = self::allowedServices($profile);
        $heartbeats = ($profile['mode'] ?? 'local') === 'local'
            ? WorkerHeartbeat::listByServices($services)
            : [];
        $queueHealth = ($profile['mode'] ?? 'local') === 'local'
            ? self::queueHealth()
            : [];

        $items = [];
        foreach ($services as $serviceName) {
            $systemd = self::serviceSnapshot($profile, $serviceName);
            $heartbeat = $heartbeats[$serviceName] ?? null;
            $item = array_merge(
                self::baseDefinition($serviceName),
                $systemd,
                [
                    'server_key' => (string)$profile['key'],
                    'server_label' => (string)$profile['label'],
                    'server_host' => (string)$profile['host'],
                    'heartbeat' => self::heartbeatSnapshot($heartbeat),
                    'queue_health' => $queueHealth[$serviceName] ?? null,
                ]
            );
            $item['alerts'] = self::buildAlerts($item);
            $items[] = $item;
        }

        return [
            'supported' => self::systemdSupported($profile),
            'host' => (string)$profile['host'],
            'server_key' => (string)$profile['key'],
            'server_label' => (string)$profile['label'],
            'server_mode' => (string)$profile['mode'],
            'generated_at' => date('Y-m-d H:i:s'),
            'services' => $items,
            'allowed_services' => $services,
            'available_servers' => self::availableServers(),
        ];
    }

    public static function recentLogs(string $serviceName, int $lines = self::DEFAULT_LOG_LINES, ?string $serverKey = null): array
    {
        $profile = self::resolveProfile($serverKey);
        $serviceName = self::assertAllowedService($serviceName, $profile);
        $lines = max(10, min(300, $lines));

        if (!self::systemdSupported($profile)) {
            return [
                'service' => $serviceName,
                'server_key' => (string)$profile['key'],
                'server_label' => (string)$profile['label'],
                'supported' => false,
                'lines' => [],
                'last_line' => 'Host atual não suporta systemd/journalctl.',
            ];
        }

        $command = 'journalctl -u ' . escapeshellarg($serviceName) . ' -n ' . $lines . ' --no-pager 2>&1';
        $result = self::runCommandForProfile($profile, $command);
        $rawLines = preg_split('/\r\n|\r|\n/', trim((string)$result['output'])) ?: [];
        $sanitized = array_values(array_filter(array_map([self::class, 'sanitizeLogLine'], $rawLines), static fn ($line): bool => $line !== ''));
        $permissionDenied = self::containsJournalPermissionError($sanitized);

        return [
            'service' => $serviceName,
            'server_key' => (string)$profile['key'],
            'server_label' => (string)$profile['label'],
            'supported' => true,
            'command_ok' => (bool)$result['ok'],
            'permission_denied' => $permissionDenied,
            'permission_hint' => $permissionDenied
                ? 'Falta acesso ao journalctl. Libere sudo para o usuário do monitor ou adicione o usuário aos grupos systemd-journal/adm.'
                : null,
            'lines' => $sanitized,
            'last_line' => self::lastMeaningfulLine($sanitized),
        ];
    }

    public static function restartService(string $serviceName, ?string $serverKey = null): array
    {
        $profile = self::resolveProfile($serverKey);
        $serviceName = self::assertAllowedService($serviceName, $profile);

        if (!self::systemdSupported($profile)) {
            throw new \RuntimeException('Este host não suporta systemd.');
        }

        $command = 'systemctl restart ' . escapeshellarg($serviceName) . ' 2>&1';
        $result = self::runCommandForProfile($profile, $command);

        if (!$result['ok']) {
            $message = trim((string)$result['output']);
            throw new \RuntimeException($message !== '' ? $message : 'Falha ao reiniciar o serviço.');
        }

        return [
            'service' => $serviceName,
            'server_key' => (string)$profile['key'],
            'server_label' => (string)$profile['label'],
            'message' => 'Serviço reiniciado com sucesso.',
            'status' => self::serviceSnapshot($profile, $serviceName),
        ];
    }

    public static function allowedServices(array $profile): array
    {
        $services = array_fill_keys((array)($profile['services'] ?? []), true);

        foreach (self::discoverMaxxServices($profile) as $serviceName) {
            $services[$serviceName] = true;
        }

        $normalized = [];
        foreach (array_keys($services) as $serviceName) {
            $serviceName = trim((string)$serviceName);
            if ($serviceName !== '' && preg_match('/^[a-zA-Z0-9._@-]+\.service$/', $serviceName)) {
                $normalized[$serviceName] = true;
            }
        }

        return array_keys($normalized);
    }

    private static function resolveProfile(?string $serverKey): array
    {
        $profiles = self::serverProfiles();
        $serverKey = trim((string)$serverKey);
        if ($serverKey === '' || !isset($profiles[$serverKey])) {
            return $profiles['local'];
        }

        return $profiles[$serverKey];
    }

    private static function assertAllowedService(string $serviceName, array $profile): string
    {
        $serviceName = trim($serviceName);
        if ($serviceName === '' || !preg_match('/^[a-zA-Z0-9._@-]+\.service$/', $serviceName)) {
            throw new \InvalidArgumentException('Serviço inválido.');
        }

        if (!in_array($serviceName, self::allowedServices($profile), true)) {
            throw new \InvalidArgumentException('Serviço fora da whitelist.');
        }

        return $serviceName;
    }

    private static function discoverMaxxServices(array $profile): array
    {
        if (!self::systemdSupported($profile)) {
            return [];
        }

        $candidates = [];
        foreach ([
            "systemctl list-unit-files 'maxx-*.service' --type=service --no-legend --no-pager 2>/dev/null",
            "systemctl list-units 'maxx-*.service' --type=service --all --no-legend --no-pager 2>/dev/null",
        ] as $command) {
            $result = self::runCommandForProfile($profile, $command);
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

    private static function serviceSnapshot(array $profile, string $serviceName): array
    {
        if (!self::systemdSupported($profile)) {
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
                'restart_count' => 0,
            ];
        }

        $result = self::runCommandForProfile(
            $profile,
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
            'last_log_line' => self::lastRelevantLogLine($profile, $serviceName),
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

        if (($item['service_name'] ?? null) === 'maxx-voice-worker.service' && is_array($queueHealth)) {
            if (array_key_exists('stasis_online', $queueHealth) && $queueHealth['stasis_online'] === false) {
                $alerts[] = [
                    'level' => 'critical',
                    'message' => 'Stasis/active_calls monitor offline. O worker de voz aguarda heartbeat antes de consumir a fila.',
                ];
            }
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
            $stasis = self::stasisMonitorSnapshot($redis);
            $workerUnhealthy = self::heartbeatMissingOrStale('maxx-voice-worker.service');

            $voiceMessage = $voiceQueue > 0
                ? "Fila voice:queue pendente: {$voiceQueue}"
                : 'Fila voice:queue vazia.';

            if ($voiceQueue > 0 && !$stasis['online']) {
                $voiceMessage .= ' Stasis/active_calls monitor offline; o worker de voz aguarda heartbeat.';
            } elseif ($voiceQueue > 0 && $workerUnhealthy) {
                $voiceMessage .= ' Heartbeat do worker de voz ausente ou atrasado.';
            }

            $health['maxx-voice-worker.service'] = [
                'queue_size' => $voiceQueue,
                'stuck' => $voiceQueue > 0 && (!$stasis['online'] || $workerUnhealthy),
                'message' => $voiceMessage,
                'stasis_online' => $stasis['online'],
                'stasis_last_seen_at' => $stasis['last_seen_at'],
                'stasis_last_error' => $stasis['last_error'],
                'stasis_source' => $stasis['source'],
                'active_calls_snapshot_age_s' => $stasis['active_calls_snapshot_age_s'],
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

    private static function heartbeatMissingOrStale(string $serviceName): bool
    {
        $map = WorkerHeartbeat::listByServices([$serviceName]);
        $row = $map[$serviceName] ?? null;
        if (!$row) {
            return true;
        }

        $seconds = self::secondsSince((string)($row['last_seen_at'] ?? ''));
        return $seconds === null || $seconds > self::STALE_HEARTBEAT_SECONDS;
    }

    private static function stasisMonitorSnapshot($redis): array
    {
        $hbRaw = $redis->get('voice:stasis:heartbeat');
        $hb = $hbRaw ? json_decode((string)$hbRaw, true) : null;

        $lastSeenAt = null;
        $online = false;
        if (is_array($hb)) {
            $ts = (int)($hb['ts'] ?? 0);
            if ($ts > 0) {
                $lastSeenAt = date('Y-m-d H:i:s', $ts);
                $online = (time() - $ts) <= 10;
            }
        }

        $errRaw = $redis->get('voice:stasis:last_error');
        $err = $errRaw ? json_decode((string)$errRaw, true) : null;

        $activeRaw = $redis->get('asterisk:active_calls');
        $active = $activeRaw ? json_decode((string)$activeRaw, true) : null;
        $serverNow = is_array($active) ? (int)($active['server_now'] ?? $active['ts'] ?? 0) : 0;

        return [
            'online' => $online,
            'last_seen_at' => $lastSeenAt,
            'last_error' => is_array($err) ? (string)($err['msg'] ?? '') : null,
            'source' => is_array($hb) ? (string)($hb['source'] ?? '') : null,
            'active_calls_snapshot_age_s' => $serverNow > 0 ? max(0, time() - $serverNow) : null,
        ];
    }

    private static function lastRelevantLogLine(array $profile, string $serviceName): ?string
    {
        $logs = self::recentLogs($serviceName, self::LAST_LOG_LINES, (string)$profile['key']);
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

    private static function systemdSupported(array $profile): bool
    {
        if (($profile['mode'] ?? 'local') === 'local' && PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        return self::binaryExists($profile, 'systemctl')
            && self::binaryExists($profile, 'journalctl');
    }

    private static function binaryExists(array $profile, string $binary): bool
    {
        $binary = trim($binary);
        if ($binary === '') {
            return false;
        }

        $command = 'sh -lc ' . escapeshellarg(
            'command -v ' . escapeshellarg($binary) . ' >/dev/null 2>&1 || '
            . 'test -x /bin/' . $binary . ' || '
            . 'test -x /usr/bin/' . $binary . ' || '
            . 'test -x /usr/sbin/' . $binary . ' || '
            . 'test -x /sbin/' . $binary
        );

        if (($profile['mode'] ?? 'local') === 'ssh') {
            $result = self::runSshCommand($profile, $command, false);
            return $result['ok'];
        }

        $result = self::runLocalCommand($command);
        return $result['ok'];
    }

    private static function runCommandForProfile(array $profile, string $command): array
    {
        if (($profile['mode'] ?? 'local') === 'ssh') {
            return self::runSshCommand($profile, $command);
        }

        return self::runLocalCommand(self::withSudo($profile, $command));
    }

    private static function runLocalCommand(string $command): array
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

    private static function runSshCommand(array $profile, string $command, bool $useSudo = true): array
    {
        $host = trim((string)($profile['host'] ?? ''));
        if ($host === '') {
            return ['ok' => false, 'status' => 127, 'output' => 'Host SSH do perfil não configurado.'];
        }

        $user = trim((string)($profile['user'] ?? 'root'));
        $port = max(1, (int)($profile['port'] ?? 22));
        $identityFile = trim((string)($profile['identity_file'] ?? ''));
        $strictHostKey = (bool)($profile['strict_host_key'] ?? false);
        $target = $user !== '' ? "{$user}@{$host}" : $host;

        $parts = [
            'ssh',
            '-o',
            'BatchMode=yes',
            '-o',
            'ConnectTimeout=5',
            '-p',
            (string)$port,
        ];

        if (!$strictHostKey) {
            $parts[] = '-o';
            $parts[] = 'StrictHostKeyChecking=no';
            $parts[] = '-o';
            $parts[] = 'UserKnownHostsFile=/dev/null';
        }

        if ($identityFile !== '') {
            $parts[] = '-i';
            $parts[] = $identityFile;
        }

        $sshPrefix = implode(' ', array_map('escapeshellarg', $parts));
        $remoteCommand = $useSudo ? self::withSudo($profile, $command) : $command;
        $fullCommand = $sshPrefix . ' ' . escapeshellarg($target) . ' -- ' . escapeshellarg($remoteCommand);

        return self::runLocalCommand($fullCommand);
    }

    private static function withSudo(array $profile, string $command): string
    {
        if (empty($profile['use_sudo'])) {
            return $command;
        }

        $sudoBin = trim((string)($profile['sudo_bin'] ?? 'sudo -n'));
        if ($sudoBin === '') {
            $sudoBin = 'sudo -n';
        }

        $parts = preg_split('/\s+/', $sudoBin) ?: [];
        $parts = array_values(array_filter(array_map(static fn ($value): string => trim((string)$value), $parts)));
        if ($parts === []) {
            $parts = ['sudo', '-n'];
        }

        $prefix = implode(' ', array_map('escapeshellarg', $parts));
        return $prefix . ' ' . $command;
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

    private static function containsJournalPermissionError(array $lines): bool
    {
        foreach ($lines as $line) {
            $normalized = strtolower(trim((string)$line));
            if ($normalized === '') {
                continue;
            }

            if (str_contains($normalized, 'no journal files were opened due to insufficient permissions')) {
                return true;
            }

            if (str_contains($normalized, 'you are currently not seeing messages from other users and the system')) {
                return true;
            }

            if (str_contains($normalized, 'members of the groups') && str_contains($normalized, 'can see all messages')) {
                return true;
            }
        }

        return false;
    }

    private static function parseServiceList(string $value): array
    {
        $items = preg_split('/[\s,;]+/', trim($value)) ?: [];
        return array_values(array_filter(array_map(static fn ($item): string => trim((string)$item), $items)));
    }

    private static function mergeServices(array ...$groups): array
    {
        $merged = [];
        foreach ($groups as $group) {
            foreach ($group as $serviceName) {
                $serviceName = trim((string)$serviceName);
                if ($serviceName !== '') {
                    $merged[$serviceName] = true;
                }
            }
        }

        return array_keys($merged);
    }

    private static function envBool(string $key, bool $default = false): bool
    {
        $value = strtolower(trim((string)TelephonyConfig::env($key, $default ? 'true' : 'false')));
        return in_array($value, ['1', 'true', 'yes', 'on', 'y'], true);
    }
}
