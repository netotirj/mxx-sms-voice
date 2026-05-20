<?php

namespace App\Service;

class SipMonitorCommandBuilder
{
    public static function normalizeRequest(array $input): array
    {
        $mode = strtolower(trim((string)($input['mode'] ?? 'auto')));
        if (!in_array($mode, ['auto', 'sngrep', 'pjsip', 'both'], true)) {
            $mode = 'auto';
        }

        $filterType = strtolower(trim((string)($input['filter_type'] ?? 'all')));
        if (!in_array($filterType, ['all', 'ip', 'extension', 'number', 'call_id', 'trunk'], true)) {
            $filterType = 'all';
        }

        $filterValue = self::sanitizeFilterValue($filterType, (string)($input['filter_value'] ?? ''));
        $iface = self::sanitizeInterface((string)($input['interface'] ?? ''));
        $port = self::sanitizePort((int)($input['port'] ?? 5060), 5060);
        $tlsPort = self::sanitizePort((int)($input['tls_port'] ?? 5061), 5061);
        $includeTls = !empty($input['include_tls']);
        $durationMinutes = self::sanitizeDuration((int)($input['duration_minutes'] ?? 5));

        return [
            'mode' => $mode,
            'filter_type' => $filterType,
            'filter_value' => $filterValue,
            'interface' => $iface,
            'port' => $port,
            'tls_port' => $tlsPort,
            'include_tls' => $includeTls ? 1 : 0,
            'duration_minutes' => $durationMinutes,
        ];
    }

    public static function resolveMode(array $request, array $capabilities): string
    {
        $requested = (string)$request['mode'];
        $hasSngrep = trim((string)($capabilities['which_sngrep']['output'] ?? '')) !== '';
        $hasAsterisk = trim((string)($capabilities['which_asterisk']['output'] ?? '')) !== '';
        $filterType = (string)$request['filter_type'];
        $includeTls = !empty($request['include_tls']);
        $tlsPort = (int)($request['tls_port'] ?? 5061);

        if ($requested !== 'auto') {
            return $requested;
        }

        if ($hasAsterisk && in_array($filterType, ['extension', 'number', 'call_id', 'trunk'], true)) {
            return 'pjsip';
        }

        if ($hasSngrep && $hasAsterisk && ($includeTls || $tlsPort === 5061 || $filterType === 'all')) {
            return 'both';
        }

        if ($hasAsterisk && $includeTls) {
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

    public static function buildSngrepScript(int $sessionId, array $request): array
    {
        $timeoutSeconds = self::captureTimeoutSeconds($request);
        $maxLines = self::maxLines();
        $sngrep = SipMonitorCommandRunner::resolveBinary('sngrep', true);
        $head = SipMonitorCommandRunner::resolveBinary('head', true);
        $timeout = SipMonitorCommandRunner::resolveBinary('timeout', false);
        $bash = SipMonitorCommandRunner::resolveBinary('bash', false);
        $env = SipMonitorCommandRunner::resolveBinary('env', false);
        $command = [$sngrep];
        $textPath = SipMonitorCommandRunner::remotePathForSession($sessionId, 'sngrep.log');

        $command[] = '-N';
        $command[] = '--text';

        $iface = trim((string)($request['interface'] ?? ''));
        if ($iface !== '') {
            $command[] = '-d';
            $command[] = $iface;
        }

        $ports = ['port ' . (int)$request['port']];
        if (!empty($request['include_tls'])) {
            $ports[] = 'port ' . (int)$request['tls_port'];
        }

        $bpf = '(' . implode(' or ', $ports) . ')';
        if ((string)$request['filter_type'] === 'ip' && $request['filter_value'] !== '') {
            $bpf .= ' and host ' . $request['filter_value'];
        }

        $baseCommand = self::quoteCommand(array_merge($command, [$bpf]));
        $shell = 'set -o pipefail; ' . $baseCommand . ' 2>&1 | ' . $head . ' -n ' . $maxLines;
        if (SipMonitorCommandRunner::supportsBinary('timeout', false)) {
            $shell = $timeout . ' --signal=TERM ' . $timeoutSeconds . 's ' . $env . ' ' . $bash . ' -lc ' . escapeshellarg($shell);
        } else {
            $shell = $env . ' ' . $bash . ' -lc ' . escapeshellarg($shell);
        }

        return [
            'command' => $shell,
            'log_path' => $textPath,
            'filter_label' => $bpf,
            'timeout_seconds' => $timeoutSeconds,
            'max_lines' => $maxLines,
        ];
    }

    public static function buildPjsipLoggerScript(array $request): array
    {
        $timeoutSeconds = self::captureTimeoutSeconds($request);
        $maxLines = self::maxLines();
        $filterType = (string)$request['filter_type'];
        $filterValue = (string)($request['filter_value'] ?? '');
        $supportsScript = SipMonitorCommandRunner::supportsBinary('script', false);
        $asterisk = SipMonitorCommandRunner::resolveBinary('asterisk', true);
        $script = SipMonitorCommandRunner::resolveBinary('script', false);
        $timeout = SipMonitorCommandRunner::resolveBinary('timeout', false);
        $head = SipMonitorCommandRunner::resolveBinary('head', true);
        $grep = SipMonitorCommandRunner::supportsBinary('grep', false)
            ? SipMonitorCommandRunner::resolveBinary('grep', false)
            : '';
        $stdbuf = SipMonitorCommandRunner::supportsBinary('stdbuf', false)
            ? SipMonitorCommandRunner::resolveBinary('stdbuf', false)
            : '';
        $bash = SipMonitorCommandRunner::resolveBinary('bash', false);
        $env = SipMonitorCommandRunner::resolveBinary('env', false);

        $loggerOn = ($filterType === 'ip' && $filterValue !== '')
            ? $asterisk . ' -rx "pjsip set logger host ' . addslashes($filterValue) . '" >/dev/null 2>&1 || ' . $asterisk . ' -rx "pjsip set logger on" >/dev/null 2>&1'
            : $asterisk . ' -rx "pjsip set logger on" >/dev/null 2>&1';

        $console = ($stdbuf !== '' ? $stdbuf . ' -oL -eL ' : '') . $asterisk . ' -rvvvvv';
        if ($supportsScript) {
            $console = $script . ' -qefc ' . escapeshellarg($console) . ' /dev/null';
        }

        $lineFilter = '';
        if ($grep !== '' && in_array($filterType, ['extension', 'number', 'call_id', 'trunk'], true) && $filterValue !== '') {
            $lineFilter = ' | ' . $grep . ' --line-buffered -i -- ' . escapeshellarg($filterValue);
        }

        $inner = 'set -o pipefail; '
            . $asterisk . ' -rx "pjsip set logger off" >/dev/null 2>&1 || true'
            . '; ' . $loggerOn
            . '; trap \'' . $asterisk . ' -rx "pjsip set logger off" >/dev/null 2>&1 || true\' EXIT INT TERM'
            . '; ' . $console . ' 2>&1' . $lineFilter . ' | ' . $head . ' -n ' . $maxLines;

        $shell = SipMonitorCommandRunner::supportsBinary('timeout', false)
            ? $timeout . ' --signal=TERM ' . $timeoutSeconds . 's ' . $env . ' ' . $bash . ' -lc ' . escapeshellarg($inner)
            : $env . ' ' . $bash . ' -lc ' . escapeshellarg($inner);

        return [
            'command' => $shell,
            'filter_label' => $filterType === 'ip' && $filterValue !== '' ? 'host ' . $filterValue : 'all',
            'timeout_seconds' => $timeoutSeconds,
            'max_lines' => $maxLines,
        ];
    }

    public static function parseSipLines(int $sessionId, string $source, string $chunk, int $remainingSlots = 300): array
    {
        if ($chunk === '' || $remainingSlots <= 0) {
            return [];
        }

        $rows = [];
        $blocks = preg_split('/(?:\r\n|\r|\n){2,}/', $chunk) ?: [];
        foreach ($blocks as $block) {
            $block = trim((string)$block);
            if ($block === '') {
                continue;
            }

            $isRelevant = preg_match('/\b(?:INVITE|ACK|BYE|CANCEL|REGISTER|OPTIONS|100 Trying|180 Ringing|183 Session Progress|200 OK|4\d\d|5\d\d|6\d\d|SIP\/2\.0|Call-ID:)\b/i', $block);
            if (!$isRelevant) {
                continue;
            }

            $callId = null;
            if (preg_match('/Call-ID:\s*([^\s]+)/i', $block, $callIdMatch)) {
                $callId = mb_substr(trim($callIdMatch[1]), 0, 255);
            }

            $method = null;
            if (preg_match('/(?:^|\n)\s*(INVITE|ACK|BYE|CANCEL|REGISTER|OPTIONS)\b/i', $block, $methodMatch)) {
                $method = strtoupper($methodMatch[1]);
            }

            $status = null;
            if (preg_match('/(?:^|\n)\s*(?:SIP\/2\.0\s+)?(100 Trying|180 Ringing|183 Session Progress|200 OK|4\d\d|5\d\d|6\d\d)\b/i', $block, $statusMatch)) {
                $status = strtoupper($statusMatch[1]);
            }

            $compactBlock = preg_replace('/\s+/', ' ', $block) ?? $block;
            $rows[] = [
                'session_id' => $sessionId,
                'source' => $source,
                'line' => mb_substr($compactBlock, 0, 1000),
                'detected_call_id' => $callId,
                'detected_method' => $method,
                'detected_status' => $status,
                'created_at' => date('Y-m-d H:i:s'),
            ];

            if (count($rows) >= $remainingSlots) {
                break;
            }
        }

        return $rows;
    }

    private static function quoteCommand(array $parts): string
    {
        return implode(' ', array_map(static fn ($part): string => escapeshellarg((string)$part), $parts));
    }

    private static function sanitizeFilterValue(string $type, string $value): string
    {
        $value = trim($value);
        if ($value === '' || $type === 'all') {
            return '';
        }

        $patterns = [
            'ip' => '/^[0-9a-fA-F\.:\/_-]{3,64}$/',
            'extension' => '/^[A-Za-z0-9_\-\.]{1,40}$/',
            'number' => '/^[0-9\+\-\(\)\s]{3,40}$/',
            'call_id' => '/^[A-Za-z0-9@\.\-_:]{3,120}$/',
            'trunk' => '/^[A-Za-z0-9_\-\.]{1,80}$/',
        ];

        if (!isset($patterns[$type]) || !preg_match($patterns[$type], $value)) {
            throw new \InvalidArgumentException('Filtro inválido para captura SIP.');
        }

        return $value;
    }

    private static function sanitizeInterface(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $value)) {
            throw new \InvalidArgumentException('Interface de rede inválida.');
        }

        return $value;
    }

    private static function sanitizePort(int $value, int $fallback): int
    {
        if ($value < 1 || $value > 65535) {
            return $fallback;
        }

        return $value;
    }

    private static function sanitizeDuration(int $minutes): int
    {
        return in_array($minutes, [5, 10, 15, 30], true) ? $minutes : 5;
    }

    public static function captureTimeoutSeconds(array $request): int
    {
        $minutes = (int)($request['duration_minutes'] ?? 5);
        $seconds = $minutes * 60;
        return max(300, min(1800, $seconds));
    }

    public static function maxLines(): int
    {
        return  max(50, min(1000, (int)\App\Config\TelephonyConfig::env('SIP_MONITOR_MAX_LINES', 400)));
    }
}
