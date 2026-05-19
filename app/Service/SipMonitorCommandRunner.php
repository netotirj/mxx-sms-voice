<?php

namespace App\Service;

use App\Config\TelephonyConfig;

class SipMonitorCommandRunner
{
    public static function profile(): array
    {
        $host = trim((string)TelephonyConfig::env('SIP_MONITOR_HOST', TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_HOST', TelephonyConfig::env('SERVERASTERISK', ''))));
        $mode = trim((string)TelephonyConfig::env('SIP_MONITOR_EXEC_MODE', $host !== '' ? 'ssh' : 'local'));

        return [
            'mode' => $mode === 'ssh' ? 'ssh' : 'local',
            'host' => $host,
            'user' => trim((string)TelephonyConfig::env('SIP_MONITOR_USER', TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_USER', 'root'))),
            'port' => max(1, (int)TelephonyConfig::env('SIP_MONITOR_PORT', TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_PORT', 22))),
            'identity_file' => trim((string)TelephonyConfig::env('SIP_MONITOR_KEY', TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_KEY', ''))),
            'strict_host_key' => filter_var((string)TelephonyConfig::env('SIP_MONITOR_STRICT_HOST_KEY', '0'), FILTER_VALIDATE_BOOL),
            'use_sudo' => filter_var((string)TelephonyConfig::env('SIP_MONITOR_USE_SUDO', TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_USE_SUDO', '1')), FILTER_VALIDATE_BOOL),
            'sudo_bin' => trim((string)TelephonyConfig::env('SIP_MONITOR_SUDO_BIN', TelephonyConfig::env('SERVICES_MONITOR_ASTERISK_SUDO_BIN', 'sudo -n'))),
            'remote_dir' => trim((string)TelephonyConfig::env('SIP_MONITOR_REMOTE_DIR', '/tmp/maxx-sip-monitor')),
            'local_dir' => dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sip-monitor',
        ];
    }

    public static function capabilities(): array
    {
        $profile = self::profile();

        return [
            'profile' => [
                'mode' => $profile['mode'],
                'host' => $profile['mode'] === 'ssh' ? (string)$profile['host'] : (gethostname() ?: php_uname('n')),
            ],
            'ssh_probe' => $profile['mode'] === 'ssh'
                ? self::runSsh($profile, 'printf "ssh-ok"', false)
                : ['ok' => true, 'status' => 0, 'output' => 'local-mode'],
            'which_sngrep' => self::run('command -v sngrep || which sngrep || true', false),
            'sngrep_version' => self::run('sngrep -V 2>&1 || true', false),
            'which_asterisk' => self::run('command -v asterisk || which asterisk || true', false),
            'asterisk_version' => self::run('asterisk -rx "core show version" 2>&1 || true', true),
            'pjsip_endpoints' => self::run('asterisk -rx "pjsip show endpoints" 2>&1 || true', true),
            'which_script' => self::run('command -v script || which script || true', false),
            'which_timeout' => self::run('command -v timeout || which timeout || true', false),
        ];
    }

    public static function supportsBinary(string $binary, bool $useSudo = false): bool
    {
        $result = self::run('command -v ' . escapeshellarg($binary) . ' >/dev/null 2>&1', $useSudo);
        return (bool)$result['ok'];
    }

    public static function run(string $command, bool $useSudo = true): array
    {
        $profile = self::profile();
        if (($profile['mode'] ?? 'local') === 'ssh') {
            return self::runSsh($profile, $command, $useSudo);
        }

        return self::runLocal($useSudo ? self::withSudo($profile, $command) : $command);
    }

    public static function startBackground(string $shellScript, string $outputPath, bool $useSudo = true): array
    {
        $profile = self::profile();
        $dir = dirname($outputPath);
        $command = 'mkdir -p ' . escapeshellarg($dir)
            . ' && touch ' . escapeshellarg($outputPath)
            . ' && nohup sh -lc ' . escapeshellarg($shellScript)
            . ' >> ' . escapeshellarg($outputPath) . ' 2>&1 < /dev/null & echo $!';

        $result = ($profile['mode'] ?? 'local') === 'ssh'
            ? self::runSsh($profile, $command, $useSudo)
            : self::runLocal($useSudo ? self::withSudo($profile, $command) : $command);

        $pid = (int)trim((string)($result['output'] ?? '0'));

        return $result + ['pid' => $pid];
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

    public static function remotePathForSession(int $sessionId, string $filename): string
    {
        $profile = self::profile();
        $base = ($profile['mode'] ?? 'local') === 'ssh'
            ? rtrim((string)$profile['remote_dir'], '/')
            : rtrim(str_replace('\\', '/', (string)$profile['local_dir']), '/');

        return $base . '/session-' . $sessionId . '/' . ltrim($filename, '/');
    }

    private static function runLocal(string $command): array
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

    private static function runSsh(array $profile, string $command, bool $useSudo = true): array
    {
        $host = trim((string)($profile['host'] ?? ''));
        if ($host === '') {
            return ['ok' => false, 'status' => 127, 'output' => 'Host SSH do monitor SIP não configurado.'];
        }

        $user = trim((string)($profile['user'] ?? 'root'));
        $port = max(1, (int)($profile['port'] ?? 22));
        $identityFile = trim((string)($profile['identity_file'] ?? ''));
        $strictHostKey = (bool)($profile['strict_host_key'] ?? false);
        $target = $user !== '' ? "{$user}@{$host}" : $host;

        $parts = ['ssh', '-T', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5', '-p', (string)$port];
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

        $remoteCommand = $useSudo ? self::withSudo($profile, $command) : $command;
        $sshPrefix = implode(' ', array_map('escapeshellarg', $parts));
        $fullCommand = $sshPrefix . ' ' . escapeshellarg($target) . ' ' . escapeshellarg($remoteCommand) . ' 2>&1';

        return self::runLocal($fullCommand);
    }

    private static function withSudo(array $profile, string $command): string
    {
        if (empty($profile['use_sudo'])) {
            return $command;
        }

        $sudoBin = trim((string)($profile['sudo_bin'] ?? 'sudo -n'));
        $parts = preg_split('/\s+/', $sudoBin) ?: [];
        $parts = array_values(array_filter(array_map(static fn ($value): string => trim((string)$value), $parts)));
        if ($parts === []) {
            $parts = ['sudo', '-n'];
        }

        return implode(' ', array_map('escapeshellarg', $parts)) . ' ' . $command;
    }
}
