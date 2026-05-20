<?php

namespace App\Service;

use App\Config\TelephonyConfig;

class MonitorSshService
{
    private const DEFAULT_CONNECT_TIMEOUT = 5;
    private const DEFAULT_COMMAND_TIMEOUT = 15;
    private const LOG_MAX_BYTES = 4000;

    public static function envProfile(
        string $hostKey,
        string $userKey,
        string $portKey,
        string $identityKey,
        string $useSudoKey,
        string $sudoBinKey,
        string $strictHostKeyKey,
        array $fallback = []
    ): array {
        $host = trim((string)TelephonyConfig::env($hostKey, $fallback['host'] ?? ''));
        $mode = trim((string)($fallback['mode'] ?? ($host !== '' ? 'ssh' : 'local')));

        return [
            'mode' => $mode === 'ssh' ? 'ssh' : 'local',
            'host' => $host,
            'user' => trim((string)TelephonyConfig::env($userKey, $fallback['user'] ?? 'root')),
            'port' => max(1, (int)TelephonyConfig::env($portKey, $fallback['port'] ?? 22)),
            'identity_file' => trim((string)TelephonyConfig::env($identityKey, $fallback['identity_file'] ?? '')),
            'use_sudo' => self::envBool($useSudoKey, (bool)($fallback['use_sudo'] ?? false)),
            'sudo_bin' => trim((string)TelephonyConfig::env($sudoBinKey, $fallback['sudo_bin'] ?? 'sudo -n')),
            'strict_host_key' => self::envBool($strictHostKeyKey, (bool)($fallback['strict_host_key'] ?? false)),
        ];
    }

    public static function testConnection(array $profile, int $timeoutSeconds = self::DEFAULT_CONNECT_TIMEOUT): array
    {
        $result = self::run($profile, 'printf "ssh-ok"', [
            'use_sudo' => false,
            'connect_timeout' => max(1, $timeoutSeconds),
            'command_timeout' => max(2, $timeoutSeconds + 2),
            'command_label' => 'ssh-test',
        ]);

        $result['friendly_message'] = self::friendlyMessage($result, 'Falha no teste de conexão SSH.');
        return $result;
    }

    public static function run(array $profile, string $command, array $options = []): array
    {
        $useSudo = (bool)($options['use_sudo'] ?? ($profile['use_sudo'] ?? false));
        $connectTimeout = max(1, (int)($options['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT));
        $commandTimeout = max(1, (int)($options['command_timeout'] ?? self::DEFAULT_COMMAND_TIMEOUT));
        $commandLabel = (string)($options['command_label'] ?? 'remote-command');
        $start = microtime(true);

        if (($profile['mode'] ?? 'local') === 'ssh' && trim((string)($profile['host'] ?? '')) === '') {
            $result = [
                'ok' => false,
                'status' => 127,
                'stdout' => '',
                'stderr' => 'Host SSH não configurado.',
                'output' => 'Host SSH não configurado.',
                'timed_out' => false,
                'mode' => 'ssh',
                'host' => '',
                'command_label' => $commandLabel,
                'duration_ms' => 0,
            ];
            $result['friendly_message'] = self::friendlyMessage($result, 'Host SSH não configurado.');
            self::debugLog($profile, $commandLabel, $command, $result);
            return $result;
        }

        if (($profile['mode'] ?? 'local') === 'ssh') {
            $shellCommand = self::buildSshShellCommand($profile, $command, $useSudo, $connectTimeout);
        } else {
            $shellCommand = $useSudo ? self::withSudo($profile, $command) : $command;
        }

        $result = self::executeProcess($shellCommand, $commandTimeout);
        $result['mode'] = (string)($profile['mode'] ?? 'local');
        $result['host'] = (string)($profile['host'] ?? '');
        $result['command_label'] = $commandLabel;
        $result['duration_ms'] = (int)round((microtime(true) - $start) * 1000);
        $result['friendly_message'] = self::friendlyMessage($result);

        self::debugLog($profile, $commandLabel, $command, $result);

        return $result;
    }

    public static function maskProfile(array $profile): array
    {
        $masked = $profile;
        if (!empty($masked['identity_file'])) {
            $masked['identity_file'] = '***';
        }
        if (!empty($masked['user'])) {
            $masked['user'] = (string)$masked['user'];
        }
        return $masked;
    }

    public static function summarize(string $value, int $maxBytes = self::LOG_MAX_BYTES): string
    {
        $value = trim(str_replace("\0", '', $value));
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        return substr($value, 0, $maxBytes) . '...';
    }

    public static function friendlyMessage(array $result, string $fallback = 'Falha ao executar comando remoto.'): string
    {
        if (!empty($result['timed_out'])) {
            return 'Tempo limite excedido ao executar o comando remoto.';
        }

        $haystack = strtolower(trim((string)(($result['stderr'] ?? '') . "\n" . ($result['stdout'] ?? ''))));
        if ($haystack === '') {
            return !empty($result['ok']) ? 'Comando executado com sucesso.' : $fallback;
        }

        if (str_contains($haystack, 'permission denied')) {
            return 'Permissão negada no servidor remoto.';
        }
        if (str_contains($haystack, 'connection refused')) {
            return 'Conexão recusada pelo servidor remoto.';
        }
        if (str_contains($haystack, 'could not resolve hostname') || str_contains($haystack, 'name or service not known')) {
            return 'Host SSH inválido ou não resolvido.';
        }
        if (str_contains($haystack, 'no such file') || str_contains($haystack, 'command not found') || str_contains($haystack, 'not recognized as an internal')) {
            return 'Comando remoto inexistente ou indisponível.';
        }
        if (str_contains($haystack, 'sudo: a password is required')) {
            return 'O usuário SSH não possui sudo não interativo para este comando.';
        }
        if (str_contains($haystack, 'operation not permitted')) {
            return 'Operação não permitida no servidor remoto.';
        }
        if (str_contains($haystack, 'host key verification failed')) {
            return 'Falha na verificação da chave do host SSH.';
        }
        if (str_contains($haystack, 'connection timed out')) {
            return 'Tempo limite excedido ao conectar no servidor remoto.';
        }

        return !empty($result['ok'])
            ? 'Comando executado com sucesso.'
            : $fallback;
    }

    private static function buildSshShellCommand(array $profile, string $command, bool $useSudo, int $connectTimeout): string
    {
        $host = trim((string)($profile['host'] ?? ''));

        $user = trim((string)($profile['user'] ?? 'root'));
        $port = max(1, (int)($profile['port'] ?? 22));
        $identityFile = trim((string)($profile['identity_file'] ?? ''));
        $strictHostKey = (bool)($profile['strict_host_key'] ?? false);
        $target = $user !== '' ? "{$user}@{$host}" : $host;

        $parts = [
            'ssh',
            '-T',
            '-o',
            'BatchMode=yes',
            '-o',
            'ConnectTimeout=' . $connectTimeout,
            '-o',
            'LogLevel=ERROR',
            '-o',
            'ServerAliveInterval=5',
            '-o',
            'ServerAliveCountMax=1',
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

        $remoteCommand = $useSudo ? self::withSudo($profile, $command) : $command;
        $sshPrefix = implode(' ', array_map('escapeshellarg', $parts));

        return $sshPrefix . ' ' . escapeshellarg($target) . ' ' . escapeshellarg($remoteCommand);
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

        return implode(' ', array_map('escapeshellarg', $parts))
            . ' /usr/bin/sh -lc '
            . escapeshellarg($command);
    }

    private static function executeProcess(string $command, int $timeoutSeconds): array
    {
        if (!function_exists('proc_open')) {
            return [
                'ok' => false,
                'status' => 127,
                'stdout' => '',
                'stderr' => 'proc_open() desabilitado neste host.',
                'output' => 'proc_open() desabilitado neste host.',
                'timed_out' => false,
            ];
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            return [
                'ok' => false,
                'status' => 127,
                'stdout' => '',
                'stderr' => 'Não foi possível iniciar o processo do comando.',
                'output' => 'Não foi possível iniciar o processo do comando.',
                'timed_out' => false,
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                @proc_terminate($process);
                usleep(200000);
                $status = proc_get_status($process);
                if (!empty($status['running'])) {
                    @proc_terminate($process, 9);
                }
                break;
            }

            usleep(100000);
        } while (true);

        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($timedOut && $exitCode === -1) {
            $exitCode = 124;
        }

        $output = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));

        return [
            'ok' => !$timedOut && $exitCode === 0,
            'status' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'output' => trim($output),
            'timed_out' => $timedOut,
        ];
    }

    private static function debugLog(array $profile, string $label, string $command, array $result): void
    {
        if (!self::debugEnabled()) {
            return;
        }

        $root = dirname(__DIR__, 2);
        $dir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $payload = [
            'time' => date('Y-m-d H:i:s'),
            'label' => $label,
            'profile' => self::maskProfile($profile),
            'status' => (int)($result['status'] ?? 0),
            'ok' => (bool)($result['ok'] ?? false),
            'timed_out' => (bool)($result['timed_out'] ?? false),
            'duration_ms' => (int)($result['duration_ms'] ?? 0),
            'friendly_message' => (string)($result['friendly_message'] ?? ''),
            'command' => self::summarize($command, 1200),
            'stdout' => self::summarize((string)($result['stdout'] ?? '')),
            'stderr' => self::summarize((string)($result['stderr'] ?? '')),
        ];

        @file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'monitor-ssh.log',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND
        );
    }

    private static function debugEnabled(): bool
    {
        return self::envBool('MONITOR_DEBUG', false) || self::envBool('MONITOR_SSH_DEBUG', false);
    }

    private static function envBool(string $key, bool $default = false): bool
    {
        $fallback = $default ? 'true' : 'false';
        $value = strtolower(trim((string)TelephonyConfig::env($key, $fallback)));
        return in_array($value, ['1', 'true', 'yes', 'on', 'y'], true);
    }
}
