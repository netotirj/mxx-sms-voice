<?php

namespace App\Service;

use App\Config\TelephonyConfig;

class SipMonitorCommandRunner
{
    private static ?array $capabilitiesCache = null;
    private static array $supportsBinaryCache = [];

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
        if (self::$capabilitiesCache !== null) {
            return self::$capabilitiesCache;
        }

        $profile = self::profile();

        self::$capabilitiesCache = [
            'profile' => [
                'mode' => $profile['mode'],
                'host' => $profile['mode'] === 'ssh' ? (string)$profile['host'] : (gethostname() ?: php_uname('n')),
            ],
            'ssh_probe' => $profile['mode'] === 'ssh'
                ? MonitorSshService::testConnection($profile)
                : ['ok' => true, 'status' => 0, 'output' => 'local-mode'],
            'which_sngrep' => self::run('command -v sngrep || which sngrep || true', false),
            'sngrep_version' => self::run('sngrep -V 2>&1 || true', false),
            'which_asterisk' => self::run('command -v asterisk || which asterisk || true', false),
            'asterisk_version' => self::run('asterisk -rx "core show version" 2>&1 || true', true),
            'pjsip_endpoints' => self::run('asterisk -rx "pjsip show endpoints" 2>&1 || true', true),
            'which_script' => self::run('command -v script || which script || true', false),
            'which_timeout' => self::run('command -v timeout || which timeout || true', false),
        ];

        return self::$capabilitiesCache;
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
        $bootstrap = 'mkdir -p ' . escapeshellarg($dir)
            . ' && touch ' . escapeshellarg($outputPath)
            . ' && : > ' . escapeshellarg($pidPath)
            . ' && printf %s\\n ' . escapeshellarg('[monitor] bootstrap ' . date('Y-m-d H:i:s')) . ' >> ' . escapeshellarg($outputPath)
            . ' && nohup sh -lc ' . escapeshellarg($shellScript)
            . ' >> ' . escapeshellarg($outputPath) . ' 2>&1 < /dev/null & PID=$!; printf %s "$PID" > ' . escapeshellarg($pidPath) . '; printf "__PID__:%s\n" "$PID"';
        $command = $bootstrap;
        if (self::supportsBinary('timeout', false)) {
            $command = 'timeout --signal=TERM 12s /usr/bin/sh -lc ' . escapeshellarg($bootstrap);
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
            'kill -TERM -- -' . $pid . ' 2>/dev/null || kill -TERM ' . $pid . ' 2>/dev/null || true',
            $useSudo
        );
    }

    public static function readSince(string $path, int $offset = 0, int $maxBytes = 65536): array
    {
        $offset = max(0, $offset);
        $maxBytes = max(1024, min(262144, $maxBytes));
        $start = $offset + 1;

        $sizeResult = self::run('if [ -f ' . escapeshellarg($path) . ' ]; then wc -c < ' . escapeshellarg($path) . '; else echo 0; fi', true);
        $size = (int)trim((string)($sizeResult['output'] ?? '0'));

        if ($size <= $offset) {
            return ['chunk' => '', 'cursor' => $size, 'size' => $size];
        }

        $command = 'tail -c +' . $start . ' ' . escapeshellarg($path) . ' 2>/dev/null | head -c ' . $maxBytes;
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
        $command = 'if [ -f ' . escapeshellarg($path) . ' ]; then head -c ' . $maxBytes . ' ' . escapeshellarg($path) . '; fi';
        $result = self::run($command, $useSudo);
        return trim((string)($result['output'] ?? ''));
    }

    public static function inspectPath(string $path, bool $useSudo = true): array
    {
        $command = 'if [ -f ' . escapeshellarg($path) . ' ]; then '
            . 'printf "__FILE__:%s|%s\n" '
            . '"$(wc -c < ' . escapeshellarg($path) . ' | tr -d \' \')" '
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

        $result = self::run('kill -0 ' . $pid . ' >/dev/null 2>&1', $useSudo);
        return (bool)($result['ok'] ?? false);
    }

    public static function testConnection(): array
    {
        return MonitorSshService::testConnection(self::profile(), 5);
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
}
