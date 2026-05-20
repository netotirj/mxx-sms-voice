<?php

namespace App\Service;

use App\Config\TelephonyConfig;
use App\Model\Entity\PermissionsRules;
use App\Model\Entity\SipMonitorLog;
use App\Model\Entity\SipMonitorSession;

class SipMonitorSessionService
{
    private const MAX_LOG_ROWS_PER_SESSION = 400;
    private const STREAM_META_REFRESH_SECONDS = 15;

    public static function ensureRouteCatalog(): void
    {
        PermissionsRules::registerGlobalRoute(
            'admin_sip_monitor',
            'Administrativo: Monitor SIP / SNGREP',
            [
                '/admin/sip-monitor',
                '/admin/sip-monitor/status',
                '/admin/sip-monitor/test-connection',
                '/admin/sip-monitor/start',
                '/admin/sip-monitor/stop',
                '/admin/sip-monitor/stream',
                '/admin/sip-monitor/emergency-stop',
            ]
        );
    }

    public static function capabilities(): array
    {
        return SipMonitorCommandRunner::capabilities();
    }

    public static function statusForUser(array $user): array
    {
        self::cleanupExpiredSessions();

        $active = SipMonitorSession::findActiveByUser((string)($user['tenancy_id'] ?? ''), (int)($user['id'] ?? 0));
        $snapshot = SipMonitorCommandRunner::cachedDiagnosticsSnapshot() ?? SipMonitorCommandRunner::emptyDiagnosticsSnapshot();
        $connection = (array)($snapshot['connection'] ?? []);

        return [
            'capabilities' => (array)($snapshot['capabilities'] ?? []),
            'connection' => self::sanitizeExecutionResult($connection),
            'active_session' => $active ? self::decorateSession($active) : null,
            'recent_sessions' => array_map([self::class, 'decorateSession'], SipMonitorSession::listRecent(10)),
            'limits' => [
                'max_simultaneous' => self::maxSimultaneousSessions(),
                'durations' => [5, 10, 15, 30],
            ],
        ];
    }

    public static function start(array $user, array $input): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(30);
        }

        self::cleanupExpiredSessions();

        $tenancyId = (string)($user['tenancy_id'] ?? '');
        $userId = (int)($user['id'] ?? 0);
        if ($tenancyId === '' || $userId <= 0) {
            throw new \RuntimeException('Usuário inválido para iniciar a captura SIP.');
        }

        $existing = SipMonitorSession::findActiveByUser($tenancyId, $userId);
        if ($existing) {
            return self::decorateSession($existing);
        }

        if (SipMonitorSession::countActive() >= self::maxSimultaneousSessions()) {
            throw new \RuntimeException('Já existe o número máximo de sessões SIP ativas no momento.');
        }

        $request = SipMonitorCommandBuilder::normalizeRequest($input);
        self::guardRateLimit($tenancyId, $userId);
        $resolvedMode = self::resolveStartMode($request);
        $connection = SipMonitorCommandRunner::testConnection();
        if (empty($connection['ok'])) {
            throw new \RuntimeException((string)($connection['friendly_message'] ?? 'Falha ao conectar no servidor SSH.'));
        }

        $now = date('Y-m-d H:i:s');
        $captureSeconds = SipMonitorCommandBuilder::captureTimeoutSeconds($request);
        $expiresAt = date('Y-m-d H:i:s', time() + $captureSeconds + 5);

        $sessionId = SipMonitorSession::create([
            'tenancy_id' => $tenancyId,
            'user_id' => $userId,
            'mode' => $request['mode'],
            'resolved_mode' => $resolvedMode,
            'filter_type' => $request['filter_type'],
            'filter_value' => $request['filter_value'] !== '' ? $request['filter_value'] : null,
            'network_interface' => $request['interface'] !== '' ? $request['interface'] : null,
            'port' => (int)$request['port'],
            'tls_port' => !empty($request['include_tls']) ? (int)$request['tls_port'] : null,
            'include_tls' => !empty($request['include_tls']) ? 1 : 0,
            'duration_minutes' => (int)$request['duration_minutes'],
            'status' => 'starting',
            'pid' => null,
            'notes' => json_encode([
                'created_by' => [
                    'user_name' => (string)($user['name'] ?? ''),
                    'user_email' => (string)($user['email'] ?? ''),
                ],
                'profile' => MonitorSshService::maskProfile(SipMonitorCommandRunner::profile()),
                'request' => $request,
                'connection' => self::sanitizeExecutionResult($connection),
                'capture_seconds' => $captureSeconds,
                'cursors' => ['sngrep' => 0, 'pjsip' => 0],
                'streams' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'started_at' => $now,
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $session = SipMonitorSession::findById($sessionId);
        if (!$session) {
            throw new \RuntimeException('Falha ao criar a sessão do monitor SIP.');
        }

        try {
            $session = self::bootstrapSessionStreams($session);
        } catch (\Throwable $e) {
            self::appendSessionError($sessionId, $e->getMessage());
            throw $e;
        }

        return self::decorateSession($session);
    }

    public static function stop(int $sessionId, ?array $user = null, string $reason = 'stopped'): ?array
    {
        $session = SipMonitorSession::findById($sessionId);
        if (!$session) {
            return null;
        }

        if ($user) {
            $tenancyId = (string)($user['tenancy_id'] ?? '');
            $userId = (int)($user['id'] ?? 0);
            $role = strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));
            $isPrivileged = in_array($role, ['super_admin', 'admin', 'developer', 'support_l1', 'support_l2'], true);
            if (!$isPrivileged && ((string)$session['tenancy_id'] !== $tenancyId || (int)$session['user_id'] !== $userId)) {
                throw new \RuntimeException('Sem permissão para encerrar esta captura SIP.');
            }
        }

        $notes = self::sessionNotes($session);
        foreach ((array)($notes['streams'] ?? []) as $stream) {
            SipMonitorCommandRunner::killProcess((int)($stream['pid'] ?? 0), true);
        }

        SipMonitorCommandRunner::run('asterisk -rx "pjsip set logger off" >/dev/null 2>&1 || true', true);

        SipMonitorSession::update($sessionId, [
            'status' => $reason === 'expired' ? 'expired' : 'stopped',
            'ended_at' => date('Y-m-d H:i:s'),
            'notes' => json_encode($notes + ['stop_reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return self::decorateSession(SipMonitorSession::findById($sessionId) ?: $session);
    }

    public static function emergencyStop(array $user): array
    {
        $role = strtolower((string)($user['user_function'] ?? $user['function'] ?? ''));
        if (!in_array($role, ['super_admin', 'admin', 'developer'], true)) {
            throw new \RuntimeException('Sem permissão para o stop de emergência.');
        }

        self::cleanupExpiredSessions();
        foreach (SipMonitorSession::listRecent(50) as $session) {
            if (in_array((string)($session['status'] ?? ''), ['starting', 'running'], true)) {
                self::stop((int)$session['id'], $user, 'emergency');
            }
        }

        SipMonitorCommandRunner::run('asterisk -rx "pjsip set logger off" 2>&1 || true', true);

        return [
            'success' => true,
            'message' => 'Sessões SIP ativas encerradas e PJSIP logger desligado.',
        ];
    }

    public static function stream(int $sessionId): array
    {
        self::cleanupExpiredSessions();

        $session = SipMonitorSession::findById($sessionId);
        if (!$session) {
            throw new \RuntimeException('Sessão SIP não encontrada.');
        }

        $notes = self::sessionNotes($session);
        $streams = (array)($notes['streams'] ?? []);
        $cursors = (array)($notes['cursors'] ?? []);
        $streamStateCache = (array)($notes['stream_state'] ?? []);

        if ($streams === [] && in_array((string)($session['status'] ?? ''), ['starting', 'running'], true)) {
            $session = self::bootstrapSessionStreams($session);
            $notes = self::sessionNotes($session);
            $streams = (array)($notes['streams'] ?? []);
            $cursors = (array)($notes['cursors'] ?? []);
            $streamStateCache = (array)($notes['stream_state'] ?? []);
        }

        $payload = [
            'session' => self::decorateSession($session),
            'chunks' => [
                'sngrep' => '',
                'pjsip' => '',
            ],
            'events' => [],
            'stream_state' => [],
            'polled_at' => date('Y-m-d H:i:s'),
        ];

        foreach (['sngrep', 'pjsip'] as $source) {
            $stream = (array)($streams[$source] ?? []);
            $logPath = (string)($stream['log_path'] ?? '');
            if ($logPath === '') {
                continue;
            }

            if ((int)($stream['pid'] ?? 0) <= 0 && !empty($stream['pid_path'])) {
                $pidValue = SipMonitorCommandRunner::readSmallFile((string)$stream['pid_path'], true, 64);
                if (preg_match('/(\d+)/', $pidValue, $matches)) {
                    $streams[$source]['pid'] = (int)$matches[1];
                }
            }

            $cursor = (int)($cursors[$source] ?? 0);
            $read = SipMonitorCommandRunner::readSince($logPath, $cursor, 65536);
            $chunk = (string)($read['chunk'] ?? '');
            $payload['chunks'][$source] = $chunk;
            $cursors[$source] = (int)($read['cursor'] ?? $cursor);

            $cachedState = (array)($streamStateCache[$source] ?? []);
            $lastCheckedAt = !empty($cachedState['checked_at']) ? strtotime((string)$cachedState['checked_at']) : false;
            $shouldInspect = $chunk !== ''
                || $cachedState === []
                || $lastCheckedAt === false
                || (time() - $lastCheckedAt) >= self::STREAM_META_REFRESH_SECONDS;

            $fileState = [
                'exists' => (bool)($cachedState['exists'] ?? false),
                'size' => (int)($cachedState['size'] ?? 0),
                'updated_at' => $cachedState['updated_at'] ?? null,
            ];
            if ($shouldInspect) {
                $fileState = SipMonitorCommandRunner::inspectPath($logPath, true);
            }

            $payload['stream_state'][$source] = [
                'pid' => (int)($streams[$source]['pid'] ?? 0),
                'running' => SipMonitorCommandRunner::processAlive((int)($streams[$source]['pid'] ?? 0), true),
                'started_ok' => (bool)($stream['started_ok'] ?? false),
                'exists' => (bool)($fileState['exists'] ?? false),
                'size' => (int)($fileState['size'] ?? 0),
                'updated_at' => $fileState['updated_at'] ?? null,
                'cursor' => $cursors[$source],
                'last_chunk_bytes' => strlen($chunk),
                'filter' => (string)($stream['filter'] ?? ''),
                'checked_at' => date('Y-m-d H:i:s'),
            ];
            $streamStateCache[$source] = $payload['stream_state'][$source];

            $remainingSlots = max(0, self::MAX_LOG_ROWS_PER_SESSION - SipMonitorLog::countBySession($sessionId));
            $rows = SipMonitorCommandBuilder::parseSipLines($sessionId, $source, $chunk, $remainingSlots);
            SipMonitorLog::insertMany($rows);
        }

        $allStopped = true;
        foreach ($payload['stream_state'] as $state) {
            if (!empty($state['running'])) {
                $allStopped = false;
                break;
            }
        }

        $newStatus = in_array((string)$session['status'], ['stopped', 'expired', 'failed'], true)
            ? (string)$session['status']
            : ($allStopped ? 'completed' : 'running');

        $notes['cursors'] = $cursors;
        $notes['streams'] = $streams;
        $notes['stream_state'] = $streamStateCache;

        $primaryPid = 0;
        foreach ($streams as $stream) {
            $streamPid = (int)($stream['pid'] ?? 0);
            if ($streamPid > 0) {
                $primaryPid = $streamPid;
                break;
            }
        }

        SipMonitorSession::update($sessionId, [
            'pid' => $primaryPid > 0 ? $primaryPid : null,
            'notes' => json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $newStatus,
            'ended_at' => $newStatus === 'completed' ? date('Y-m-d H:i:s') : ($session['ended_at'] ?? null),
        ]);

        $payload['session'] = self::decorateSession(SipMonitorSession::findById($sessionId) ?: $session);
        $payload['events'] = self::buildEventSummary($sessionId);
        $payload['message'] = self::sessionMessage($payload['session']);
        return $payload;
    }

    public static function testConnection(): array
    {
        return self::sanitizeExecutionResult(SipMonitorCommandRunner::testConnection(true));
    }

    public static function cleanupExpiredSessions(): void
    {
        SipMonitorSession::ensureTable();
        SipMonitorLog::ensureTable();

        foreach (SipMonitorSession::listExpiredActive(date('Y-m-d H:i:s')) as $session) {
            self::stop((int)$session['id'], null, 'expired');
        }
    }

    private static function buildEventSummary(int $sessionId): array
    {
        $logs = array_reverse(SipMonitorLog::latestBySession($sessionId, 200));
        $byCallId = [];

        foreach ($logs as $row) {
            $callId = trim((string)($row['detected_call_id'] ?? ''));
            if ($callId === '') {
                continue;
            }

            if (!isset($byCallId[$callId])) {
                $byCallId[$callId] = [
                    'call_id' => $callId,
                    'methods' => [],
                    'statuses' => [],
                    'first_seen_at' => (string)$row['created_at'],
                    'last_seen_at' => (string)$row['created_at'],
                    'invite_to_first_response_ms' => null,
                    'invite_to_180_183_ms' => null,
                    'invite_to_200_ms' => null,
                ];
            }

            $entry = &$byCallId[$callId];
            $entry['last_seen_at'] = (string)$row['created_at'];

            if (!empty($row['detected_method'])) {
                $entry['methods'][] = (string)$row['detected_method'];
            }
            if (!empty($row['detected_status'])) {
                $entry['statuses'][] = (string)$row['detected_status'];
            }
        }

        foreach ($byCallId as &$entry) {
            $timings = self::calculateTimingsForCall($sessionId, $entry['call_id']);
            $entry += $timings;
            $entry['possible_timeout'] = $entry['invite_to_200_ms'] === null && in_array('INVITE', $entry['methods'], true);
            $entry['possible_operator_delay'] = $entry['invite_to_first_response_ms'] !== null && $entry['invite_to_first_response_ms'] > 3000;
            $entry['methods'] = array_values(array_unique($entry['methods']));
            $entry['statuses'] = array_values(array_unique($entry['statuses']));
        }

        return array_values($byCallId);
    }

    private static function calculateTimingsForCall(int $sessionId, string $callId): array
    {
        $rows = array_reverse(array_filter(
            SipMonitorLog::latestBySession($sessionId, 300),
            static fn (array $row): bool => (string)($row['detected_call_id'] ?? '') === $callId
        ));

        $inviteAt = null;
        $firstResponseAt = null;
        $firstProgressAt = null;
        $okAt = null;

        foreach ($rows as $row) {
            $createdAt = strtotime((string)($row['created_at'] ?? ''));
            if (!$createdAt) {
                continue;
            }

            if ($inviteAt === null && (string)($row['detected_method'] ?? '') === 'INVITE') {
                $inviteAt = $createdAt;
            }

            $status = (string)($row['detected_status'] ?? '');
            if ($inviteAt !== null && $firstResponseAt === null && preg_match('/^(100 TRYING|180 RINGING|183 SESSION PROGRESS|200 OK|4\d\d|5\d\d|6\d\d)$/i', $status)) {
                $firstResponseAt = $createdAt;
            }
            if ($inviteAt !== null && $firstProgressAt === null && preg_match('/^(180 RINGING|183 SESSION PROGRESS)$/i', $status)) {
                $firstProgressAt = $createdAt;
            }
            if ($inviteAt !== null && $okAt === null && strtoupper($status) === '200 OK') {
                $okAt = $createdAt;
            }
        }

        return [
            'invite_to_first_response_ms' => ($inviteAt && $firstResponseAt) ? (($firstResponseAt - $inviteAt) * 1000) : null,
            'invite_to_180_183_ms' => ($inviteAt && $firstProgressAt) ? (($firstProgressAt - $inviteAt) * 1000) : null,
            'invite_to_200_ms' => ($inviteAt && $okAt) ? (($okAt - $inviteAt) * 1000) : null,
        ];
    }

    private static function decorateSession(array $session): array
    {
        $notes = self::sessionNotes($session);
        $remaining = null;
        if (!empty($session['expires_at'])) {
            $remaining = max(0, strtotime((string)$session['expires_at']) - time());
        }

        $publicNotes = $notes;
        if (isset($publicNotes['profile']) && is_array($publicNotes['profile'])) {
            unset($publicNotes['profile']['identity_file'], $publicNotes['profile']['sudo_bin'], $publicNotes['profile']['remote_dir'], $publicNotes['profile']['local_dir']);
        }
        if (isset($publicNotes['streams']) && is_array($publicNotes['streams'])) {
            foreach ($publicNotes['streams'] as $key => $stream) {
                if (!is_array($stream)) {
                    continue;
                }
                unset($publicNotes['streams'][$key]['command'], $publicNotes['streams'][$key]['log_path'], $publicNotes['streams'][$key]['pcap_path']);
            }
        }

        $session['notes'] = $publicNotes;
        $session['remaining_seconds'] = $remaining;
        $session['message'] = self::sessionMessage($session);
        return $session;
    }

    private static function sessionNotes(array $session): array
    {
        $notes = json_decode((string)($session['notes'] ?? ''), true);
        return is_array($notes) ? $notes : [];
    }

    private static function maxSimultaneousSessions(): int
    {
        $value = (int)TelephonyConfig::env('SIP_MONITOR_MAX_SIMULTANEOUS', 1);
        return max(1, min(10, $value));
    }

    private static function guardRateLimit(string $tenancyId, int $userId): void
    {
        $interval = max(0, min(600, (int)TelephonyConfig::env('SIP_MONITOR_MIN_INTERVAL_SECONDS', 10)));
        if ($interval <= 0) {
            return;
        }

        $latest = SipMonitorSession::findLatestByUser($tenancyId, $userId);
        if (!$latest || empty($latest['created_at'])) {
            return;
        }

        $createdAt = strtotime((string)$latest['created_at']);
        if (!$createdAt) {
            return;
        }

        $remaining = ($createdAt + $interval) - time();
        if ($remaining > 0) {
            throw new \RuntimeException('Aguarde ' . $remaining . 's antes de iniciar uma nova captura SIP.');
        }
    }

    private static function startStreamOrFail(int $sessionId, string $source, string $command, string $logPath): array
    {
        $started = SipMonitorCommandRunner::startBackground($command, $logPath, true);
        $output = trim((string)($started['output'] ?? ''));
        $pid = (int)($started['pid'] ?? 0);

        if (!empty($started['ok']) && $pid > 0) {
            return $started;
        }

        $existing = SipMonitorSession::findById($sessionId);
        $notes = $existing ? self::sessionNotes($existing) : [];
        $notes['last_error'] = self::sanitizeExecutionResult($started);
        SipMonitorSession::update($sessionId, [
            'status' => 'failed',
            'ended_at' => date('Y-m-d H:i:s'),
            'notes' => json_encode($notes + [
                'failed_source' => $source,
                'failed_log_path' => $logPath,
                'bootstrap_output' => mb_substr($output, 0, 2000),
                'bootstrap_status' => (int)($started['status'] ?? 0),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        throw new \RuntimeException(
            'Falha ao iniciar captura ' . strtoupper($source) . '. '
            . ((string)($started['friendly_message'] ?? '') !== '' ? $started['friendly_message'] : ($output !== '' ? $output : 'O servidor remoto não devolveu saída útil.'))
        );
    }

    private static function bootstrapSessionStreams(array $session): array
    {
        $sessionId = (int)($session['id'] ?? 0);
        if ($sessionId <= 0) {
            throw new \RuntimeException('Sessão SIP inválida para bootstrap.');
        }

        $notes = self::sessionNotes($session);
        $request = (array)($notes['request'] ?? []);
        $resolvedMode = (string)($session['resolved_mode'] ?? 'auto');
        $primaryPid = null;
        $streams = (array)($notes['streams'] ?? []);
        $startedPids = [];

        if ($streams !== []) {
            return $session;
        }

        if (in_array($resolvedMode, ['sngrep', 'both'], true)) {
            $script = SipMonitorCommandBuilder::buildSngrepScript($sessionId, $request);
            $logPath = (string)($script['log_path'] ?? SipMonitorCommandRunner::remotePathForSession($sessionId, 'sngrep.txt'));
            $started = self::startStreamOrFail($sessionId, 'sngrep', $script['command'], $logPath);
            $streams['sngrep'] = [
                'pid' => (int)($started['pid'] ?? 0),
                'log_path' => $logPath,
                'pid_path' => (string)($started['pid_path'] ?? ''),
                'command' => $script['command'],
                'filter' => $script['filter_label'],
                'timeout_seconds' => (int)($script['timeout_seconds'] ?? 0),
                'max_lines' => (int)($script['max_lines'] ?? 0),
                'started_ok' => (bool)($started['ok'] ?? false),
                'bootstrap_output' => mb_substr(trim((string)($started['output'] ?? '')), 0, 800),
            ];
            if ($primaryPid === null && !empty($started['pid'])) {
                $primaryPid = (int)$started['pid'];
            }
            if (!empty($started['pid'])) {
                $startedPids[] = (int)$started['pid'];
            }
        }

        try {
            if (in_array($resolvedMode, ['pjsip', 'both'], true)) {
                $script = SipMonitorCommandBuilder::buildPjsipLoggerScript($request);
                $logPath = SipMonitorCommandRunner::remotePathForSession($sessionId, 'pjsip.log');
                $started = self::startStreamOrFail($sessionId, 'pjsip', $script['command'], $logPath);
                $streams['pjsip'] = [
                    'pid' => (int)($started['pid'] ?? 0),
                    'log_path' => $logPath,
                    'pid_path' => (string)($started['pid_path'] ?? ''),
                    'command' => $script['command'],
                    'filter' => $script['filter_label'],
                    'timeout_seconds' => (int)($script['timeout_seconds'] ?? 0),
                    'max_lines' => (int)($script['max_lines'] ?? 0),
                    'started_ok' => (bool)($started['ok'] ?? false),
                    'bootstrap_output' => mb_substr(trim((string)($started['output'] ?? '')), 0, 800),
                ];
                if ($primaryPid === null && !empty($started['pid'])) {
                    $primaryPid = (int)$started['pid'];
                }
                if (!empty($started['pid'])) {
                    $startedPids[] = (int)$started['pid'];
                }
            }
        } catch (\Throwable $e) {
            foreach ($startedPids as $pid) {
                SipMonitorCommandRunner::killProcess($pid, true);
            }
            throw $e;
        }

        $notes['streams'] = $streams;
        SipMonitorSession::update($sessionId, [
            'status' => 'running',
            'pid' => $primaryPid,
            'notes' => json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return SipMonitorSession::findById($sessionId) ?: $session;
    }

    private static function resolveStartMode(array $request): string
    {
        $requested = (string)($request['mode'] ?? 'auto');
        if ($requested !== 'auto') {
            return $requested;
        }

        $hasSngrep = SipMonitorCommandRunner::supportsBinary('sngrep', false);
        $hasAsterisk = SipMonitorCommandRunner::supportsBinary('asterisk', true);
        $filterType = (string)($request['filter_type'] ?? 'all');
        $includeTls = !empty($request['include_tls']);
        $tlsPort = (int)($request['tls_port'] ?? 5061);

        if ($hasSngrep && $hasAsterisk && ($includeTls || $tlsPort === 5061 || $filterType === 'all')) {
            return 'both';
        }

        if ($hasAsterisk && ($includeTls || in_array($filterType, ['extension', 'number', 'call_id', 'trunk'], true))) {
            return 'pjsip';
        }

        if ($hasSngrep) {
            return 'sngrep';
        }

        if ($hasAsterisk) {
            return 'pjsip';
        }

        throw new \RuntimeException('Nenhum backend de captura SIP está disponível neste servidor.');
    }

    private static function sanitizeExecutionResult(array $result): array
    {
        return [
            'ok' => (bool)($result['ok'] ?? false),
            'status' => (int)($result['status'] ?? 0),
            'timed_out' => (bool)($result['timed_out'] ?? false),
            'duration_ms' => (int)($result['duration_ms'] ?? 0),
            'friendly_message' => (string)($result['friendly_message'] ?? ''),
            'stdout' => MonitorSshService::summarize((string)($result['stdout'] ?? '')),
            'stderr' => MonitorSshService::summarize((string)($result['stderr'] ?? '')),
        ];
    }

    private static function appendSessionError(int $sessionId, string $message): void
    {
        $session = SipMonitorSession::findById($sessionId);
        if (!$session) {
            return;
        }

        $notes = self::sessionNotes($session);
        $notes['last_error_message'] = $message;
        SipMonitorSession::update($sessionId, [
            'notes' => json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private static function sessionMessage(array $session): string
    {
        $notes = self::sessionNotes($session);
        if (!empty($notes['last_error_message'])) {
            return (string)$notes['last_error_message'];
        }

        $status = (string)($session['status'] ?? '');
        return match ($status) {
            'starting' => 'Preparando conexão SSH e iniciando captura controlada.',
            'running' => 'Captura em execução com tempo e linhas limitados.',
            'completed' => 'Captura concluída. Consulte a saída e os eventos detectados.',
            'failed' => (string)(($notes['last_error']['friendly_message'] ?? null) ?: 'A captura falhou no servidor remoto.'),
            'stopped' => 'Captura encerrada manualmente.',
            'expired' => 'Captura encerrada por tempo limite.',
            default => 'Sessão de captura SIP.',
        };
    }
}
