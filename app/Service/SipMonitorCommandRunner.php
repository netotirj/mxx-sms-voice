<?php

namespace App\Service;

use App\Config\TelephonyConfig;

class SipMonitorCommandRunner
{
    private static ?array $capabilitiesCache = null;
    private static ?array $diagnosticsSnapshotCache = null;
    private static array $supportsBinaryCache = [];
    private static array $resolvedBinaryCache = [];

    private const BINARY_CANDIDATES = [
        'asterisk' => ['/usr/sbin/asterisk', '/sbin/asterisk', '/usr/bin/asterisk', '/bin/asterisk'],
        'sngrep' => ['/usr/bin/sngrep', '/bin/sngrep', '/usr/sbin/sngrep', '/sbin/sngrep'],
        'timeout' => ['/usr/bin/timeout', '/bin/timeout'],
        'script' => ['/usr/bin/script', '/bin/script'],
        'tail' => ['/usr/bin/tail', '/bin/tail'],
        'head' => ['/usr/bin/head', '/bin/head'],
        'wc' => ['/usr/bin/wc', '/bin/wc'],
        'mkdir' => ['/usr/bin/mkdir', '/bin/mkdir'],
        'touch' => ['/usr/bin/touch', '/bin/touch'],
        'kill' => ['/usr/bin/kill', '/bin/kill'],
        'sh' => ['/usr/bin/sh', '/bin/sh'],
        'stdbuf' => ['/usr/bin/stdbuf', '/bin/stdbuf'],
        'bash' => ['/usr/bin/bash', '/bin/bash'],
        'env' => ['/usr/bin/env', '/bin/env'],
    ];

    public static function profile(): array
    {
        $host = trim((string)TelephonyConfig::env('SIP_MONITOR_HOST', TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_HOST', TelephonyConfig::env('SERVERASTERISK', ''))));
        $mode = trim((string)TelephonyConfig::env('SIP_MONITOR_EXEC_MODE', $host !== '' ? 'ssh' : 'local'));

        $profile = MonitorSshService::envProfile(
            'SIP_MONITOR_HOST',
            'SIP_MONITOR_USER',
            'SIP_MONITOR_PORT',
            'SIP_MONITOR_KEY',
            'SIP_MONITOR_USE_SUDO',
            'SIP_MONITOR_SUDO_BIN',
            'SIP_MONITOR_STRICT_HOST_KEY',
            [
                'host' => TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_HOST', TelephonyConfig::env('SERVERASTERISK', '')),
                'user' => TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_USER', 'root'),
                'port' => (int)TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_PORT', 22),
                'identity_file' => TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_KEY', ''),
                'use_sudo' => filter_var((string)TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_USE_SUDO', '1'), FILTER_VALIDATE_BOOL),
                'sudo_bin' => TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_SUDO_BIN', 'sudo -n'),
                'strict_host_key' => filter_var((string)TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_STRICT_HOST_KEY', '0'), FILTER_VALIDATE_BOOL),
                'mode' => $mode === 'ssh' ? 'ssh' : 'local',
            ]
        );

        return [
            'mode' => (string)($profile['mode'] ?? ($mode === 'ssh' ? 'ssh' : 'local')),
            'host' => (string)($profile['host'] ?? $host),
            'user' => (string)($profile['user'] ?? 'root'),
            'port' => (int)($profile['port'] ?? 22),
            'identity_file' => (string)($profile['identity_file'] ?? ''),
            'strict_host_key' => (bool)($profile['strict_host_key'] ?? false),
            'use_sudo' => (bool)($profile['use_sudo'] ?? true),
            'sudo_bin' => (string)($profile['sudo_bin'] ?? 'sudo -n'),
            'remote_dir' => trim((string)TelephonyConfig::env('SIP_MONITOR_REMOTE_DIR', '/tmp/maxx-sip-monitor')),
            'local_dir' => dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sip-monitor',
        ];
    }

    public static function capabilities(): array
    {
        return (array)(self::diagnosticsSnapshot()['capabilities'] ?? []);
    }

    public static function diagnosticsSnapshot(bool $forceRefresh = false): array
    {
        if (!$forceRefresh && self::$diagnosticsSnapshotCache !== null) {
            return self::$diagnosticsSnapshotCache;
        }

        if (!$forceRefresh) {
            $cached = self::readDiagnosticsSnapshotCache();
            if ($cached !== null) {
                self::$diagnosticsSnapshotCache = $cached;
                self::$capabilitiesCache = (array)($cached['capabilities'] ?? []);
                return $cached;
            }
        }

        try {
            $snapshot = self::buildDiagnosticsSnapshot();
        } catch (\Throwable $e) {
            $stale = self::readDiagnosticsSnapshotCache(true);
            if ($stale !== null && !$forceRefresh) {
                $stale['stale'] = true;
                $stale['error'] = $e->getMessage();
                self::$diagnosticsSnapshotCache = $stale;
                self::$capabilitiesCache = (array)($stale['capabilities'] ?? []);
                return $stale;
            }

            throw $e;
        }

        self::$diagnosticsSnapshotCache = $snapshot;
        self::$capabilitiesCache = (array)($snapshot['capabilities'] ?? []);
        self::writeDiagnosticsSnapshotCache($snapshot);

        return $snapshot;
    }

    public static function cachedDiagnosticsSnapshot(): ?array
    {
        $cached = self::readDiagnosticsSnapshotCache(true);
        if ($cached !== null) {
            self::$diagnosticsSnapshotCache = $cached;
            self::$capabilitiesCache = (array)($cached['capabilities'] ?? []);
        }

        return $cached;
    }

    public static function emptyDiagnosticsSnapshot(): array
    {
        $profile = self::profile();

        return [
            'checked_at' => null,
            'stale' => false,
            'connection' => [
                'ok' => false,
                'status' => 0,
                'friendly_message' => 'Diagnóstico SSH ainda não executado nesta sessão.',
                'output' => '',
                'stdout' => '',
                'stderr' => '',
                'duration_ms' => 0,
            ],
            'capabilities' => [
                'profile' => [
                    'mode' => $profile['mode'],
                    'host' => $profile['mode'] === 'ssh' ? (string)$profile['host'] : (gethostname() ?: php_uname('n')),
                ],
                'ssh_probe' => ['ok' => false, 'status' => 0, 'output' => 'pending'],
                'which_sngrep' => ['ok' => false, 'status' => 0, 'output' => ''],
                'sngrep_version' => ['ok' => false, 'status' => 0, 'output' => ''],
                'which_asterisk' => ['ok' => false, 'status' => 0, 'output' => ''],
                'asterisk_version' => ['ok' => false, 'status' => 0, 'output' => ''],
                'pjsip_endpoints' => ['ok' => false, 'status' => 0, 'output' => ''],
                'which_script' => ['ok' => false, 'status' => 0, 'output' => ''],
                'which_timeout' => ['ok' => false, 'status' => 0, 'output' => ''],
            ],
        ];
    }

    public static function supportsBinary(string $binary, bool $useSudo = false): bool
    {
        $cacheKey = ($useSudo ? '1' : '0') . ':' . $binary;
        if (array_key_exists($cacheKey, self::$supportsBinaryCache)) {
            return self::$supportsBinaryCache[$cacheKey];
        }

        $result = self::run('command -v ' . escapeshellarg($binary) . ' >/dev/null 2>&1', $useSudo);
        self::$supportsBinaryCache[$cacheKey] = (bool)$result['ok'];
        return self::$supportsBinaryCache[$cacheKey];
    }

    public static function resolveBinary(string $binary, bool $useSudo = false): string
    {
        $cacheKey = ($useSudo ? '1' : '0') . ':' . $binary;
        if (isset(self::$resolvedBinaryCache[$cacheKey])) {
            return self::$resolvedBinaryCache[$cacheKey];
        }

        foreach (self::BINARY_CANDIDATES[$binary] ?? [] as $candidate) {
            $result = self::run('[ -x ' . escapeshellarg($candidate) . ' ]', $useSudo);
            if (!empty($result['ok'])) {
                self::$resolvedBinaryCache[$cacheKey] = $candidate;
                return $candidate;
            }
        }

        $result = self::run('command -v ' . escapeshellarg($binary) . ' 2>/dev/null || which ' . escapeshellarg($binary) . ' 2>/dev/null || true', $useSudo);
        $resolved = trim((string)($result['output'] ?? ''));
        if ($resolved !== '') {
            $firstLine = trim((string)preg_split('/\r\n|\r|\n/', $resolved)[0]);
            if ($firstLine !== '') {
                self::$resolvedBinaryCache[$cacheKey] = $firstLine;
                return $firstLine;
            }
        }

        self::$resolvedBinaryCache[$cacheKey] = $binary;
        return $binary;
    }

    public static function run(string $command, bool $useSudo = true): array
    {
        $profile = self::profile();
        return MonitorSshService::run($profile, $command, [
            'use_sudo' => $useSudo,
            'connect_timeout' => 5,
            'command_timeout' => self::commandTimeoutSeconds(),
            'command_label' => 'sip-monitor-run',
        ]);
    }

    public static function startBackground(string $shellScript, string $outputPath, bool $useSudo = true): array
    {
        $profile = self::profile();
        $dir = dirname($outputPath);
        $pidPath = $outputPath . '.pid';
        $mkdir = self::resolveBinary('mkdir', $useSudo);
        $touch = self::resolveBinary('touch', $useSudo);
        $sh = self::resolveBinary('sh', $useSudo);
        $timeout = self::resolveBinary('timeout', false);
        $bootstrap = $mkdir . ' -p ' . escapeshellarg($dir)
            . ' && ' . $touch . ' ' . escapeshellarg($outputPath)
            . ' && : > ' . escapeshellarg($pidPath)
            . ' && printf %s\\n ' . escapeshellarg('[monitor] bootstrap ' . date('Y-m-d H:i:s')) . ' >> ' . escapeshellarg($outputPath)
            . ' && nohup ' . $sh . ' -lc ' . escapeshellarg($shellScript)
            . ' >> ' . escapeshellarg($outputPath) . ' 2>&1 < /dev/null & PID=$!; printf %s "$PID" > ' . escapeshellarg($pidPath) . '; printf "__PID__:%s\n" "$PID"';
        $command = $bootstrap;
        if (self::supportsBinary('timeout', false)) {
            $command = $timeout . ' --signal=TERM 12s ' . $sh . ' -lc ' . escapeshellarg($bootstrap);
        }

        $result = MonitorSshService::run($profile, $command, [
            'use_sudo' => $useSudo,
            'connect_timeout' => 5,
            'command_timeout' => min(20, max(10, self::commandTimeoutSeconds())),
            'command_label' => 'sip-monitor-start-background',
        ]);

        $output = trim((string)($result['output'] ?? ''));
        $pid = 0;
        if (preg_match('/__PID__:(\d+)/', $output, $matches)) {
            $pid = (int)$matches[1];
        } elseif (preg_match('/(\d+)/', $output, $matches)) {
            $pid = (int)$matches[1];
        }

        return $result + ['pid' => $pid, 'pid_path' => $pidPath];
    }

    public static function killProcess(?int $pid, bool $useSudo = true): array
    {
        $pid = (int)$pid;
        if ($pid <= 0) {
            return ['ok' => true, 'status' => 0, 'output' => 'PID ausente.'];
        }

        return self::run(
            self::resolveBinary('kill', $useSudo) . ' -TERM -- -' . $pid . ' 2>/dev/null || '
            . self::resolveBinary('kill', $useSudo) . ' -TERM ' . $pid . ' 2>/dev/null || true',
            $useSudo
        );
    }

    public static function readSince(string $path, int $offset = 0, int $maxBytes = 65536): array
    {
        $offset = max(0, $offset);
        $maxBytes = max(1024, min(262144, $maxBytes));
        $start = $offset + 1;

        $wc = self::resolveBinary('wc', true);
        $tail = self::resolveBinary('tail', true);
        $head = self::resolveBinary('head', true);
        $sizeResult = self::run('if [ -f ' . escapeshellarg($path) . ' ]; then ' . $wc . ' -c < ' . escapeshellarg($path) . '; else echo 0; fi', true);
        $size = (int)trim((string)($sizeResult['output'] ?? '0'));

        if ($size <= $offset) {
            return ['chunk' => '', 'cursor' => $size, 'size' => $size];
        }

        $command = $tail . ' -c +' . $start . ' ' . escapeshellarg($path) . ' 2>/dev/null | ' . $head . ' -c ' . $maxBytes;
        $chunkResult = self::run($command, true);

        return [
            'chunk' => (string)($chunkResult['output'] ?? ''),
            'cursor' => min($size, $offset + strlen((string)($chunkResult['output'] ?? ''))),
            'size' => $size,
        ];
    }

    public static function readSmallFile(string $path, bool $useSudo = true, int $maxBytes = 4096): string
    {
        $maxBytes = max(128, min(65536, $maxBytes));
        $head = self::resolveBinary('head', $useSudo);
        $command = 'if [ -f ' . escapeshellarg($path) . ' ]; then ' . $head . ' -c ' . $maxBytes . ' ' . escapeshellarg($path) . '; fi';
        $result = self::run($command, $useSudo);
        return trim((string)($result['output'] ?? ''));
    }

    public static function inspectPath(string $path, bool $useSudo = true): array
    {
        $wc = self::resolveBinary('wc', $useSudo);
        $command = 'if [ -f ' . escapeshellarg($path) . ' ]; then '
            . 'printf "__FILE__:%s|%s\n" '
            . '"$(' . $wc . ' -c < ' . escapeshellarg($path) . ' | tr -d \' \')" '
            . '"$(date -r ' . escapeshellarg($path) . ' \'+%Y-%m-%d %H:%M:%S\' 2>/dev/null || stat -c \'%y\' ' . escapeshellarg($path) . ' 2>/dev/null | cut -d. -f1)"; '
            . 'else printf "__MISSING__\n"; fi';
        $result = self::run($command, $useSudo);
        $output = trim((string)($result['output'] ?? ''));

        if (!str_starts_with($output, '__FILE__:')) {
            return [
                'exists' => false,
                'size' => 0,
                'updated_at' => null,
            ];
        }

        $value = substr($output, strlen('__FILE__:'));
        [$size, $updatedAt] = array_pad(explode('|', $value, 2), 2, null);

        return [
            'exists' => true,
            'size' => max(0, (int)$size),
            'updated_at' => $updatedAt ? trim((string)$updatedAt) : null,
        ];
    }

    public static function processAlive(?int $pid, bool $useSudo = true): bool
    {
        $pid = (int)$pid;
        if ($pid <= 0) {
            return false;
        }

        $result = self::run(self::resolveBinary('kill', $useSudo) . ' -0 ' . $pid . ' >/dev/null 2>&1', $useSudo);
        return (bool)($result['ok'] ?? false);
    }

    public static function testConnection(bool $forceRefresh = false): array
    {
        $snapshot = self::diagnosticsSnapshot($forceRefresh);
        return (array)($snapshot['connection'] ?? []);
    }

    public static function remotePathForSession(int $sessionId, string $filename): string
    {
        $profile = self::profile();
        $base = ($profile['mode'] ?? 'local') === 'ssh'
            ? rtrim((string)$profile['remote_dir'], '/')
            : rtrim(str_replace('\\', '/', (string)$profile['local_dir']), '/');

        return $base . '/session-' . $sessionId . '/' . ltrim($filename, '/');
    }

    private static function commandTimeoutSeconds(): int
    {
        $value = (int)TelephonyConfig::env('SIP_MONITOR_COMMAND_TIMEOUT_SECONDS', 15);
        return max(5, min(120, $value));
    }

    private static function diagnosticCommandTimeoutSeconds(): int
    {
        $value = (int)TelephonyConfig::env('SIP_MONITOR_DIAGNOSTIC_TIMEOUT_SECONDS', 6);
        return max(2, min(10, $value));
    }

    private static function diagnosticsCacheTtlSeconds(): int
    {
        $value = (int)TelephonyConfig::env('SIP_MONITOR_STATUS_CACHE_SECONDS', 20);
        return max(5, min(120, $value));
    }

    private static function diagnosticsCachePath(): string
    {
        $profile = self::profile();
        $baseDir = rtrim(str_replace('\\', '/', (string)$profile['local_dir']), '/');
        return $baseDir . '/diagnostics-cache.json';
    }

    private static function buildDiagnosticsSnapshot(): array
    {
        $profile = self::profile();
        $connection = $profile['mode'] === 'ssh'
            ? MonitorSshService::testConnection($profile, 5)
            : ['ok' => true, 'status' => 0, 'output' => 'local-mode'];

        $capabilities = [
            'profile' => [
                'mode' => $profile['mode'],
                'host' => $profile['mode'] === 'ssh' ? (string)$profile['host'] : (gethostname() ?: php_uname('n')),
            ],
            'ssh_probe' => $connection,
            'which_sngrep' => self::runDiagnostic('command -v sngrep || which sngrep || true', false),
            'sngrep_version' => self::runDiagnostic(self::resolveBinary('sngrep', false) . ' -V 2>&1 || true', false),
            'which_asterisk' => self::runDiagnostic('command -v asterisk || which asterisk || true', false),
            'asterisk_version' => self::runDiagnostic(self::resolveBinary('asterisk', true) . ' -rx "core show version" 2>&1 || true', true),
            'pjsip_endpoints' => self::runDiagnostic(self::resolveBinary('asterisk', true) . ' -rx "pjsip show endpoints" 2>&1 || true', true),
            'which_script' => self::runDiagnostic('command -v script || which script || true', false),
            'which_timeout' => self::runDiagnostic('command -v timeout || which timeout || true', false),
        ];

        return [
            'checked_at' => date('Y-m-d H:i:s'),
            'connection' => $connection,
            'capabilities' => $capabilities,
            'stale' => false,
        ];
    }

    private static function runDiagnostic(string $command, bool $useSudo = true): array
    {
        $profile = self::profile();
        return MonitorSshService::run($profile, $command, [
            'use_sudo' => $useSudo,
            'connect_timeout' => 5,
            'command_timeout' => self::diagnosticCommandTimeoutSeconds(),
            'command_label' => 'sip-monitor-diagnostic',
        ]);
    }

    private static function readDiagnosticsSnapshotCache(bool $allowStale = false): ?array
    {
        $path = self::diagnosticsCachePath();
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $checkedAt = strtotime((string)($decoded['checked_at'] ?? ''));
        $isFresh = $checkedAt !== false && (time() - $checkedAt) <= self::diagnosticsCacheTtlSeconds();
        if (!$allowStale && !$isFresh) {
            return null;
        }

        $decoded['stale'] = !$isFresh;
        return $decoded;
    }

    private static function writeDiagnosticsSnapshotCache(array $snapshot): void
    {
        $path = self::diagnosticsCachePath();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        @file_put_contents($path, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
