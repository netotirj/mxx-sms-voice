<?php

namespace App\Service;

class PerformanceTelemetry
{
    public static function log(string $event, array $context = []): void
    {
        $pairs = [];
        foreach ($context as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $pairs[] = $key . '=' . str_replace(["\r", "\n"], ' ', (string)$value);
        }

        error_log('[perf] ' . $event . ($pairs ? ' ' . implode(' ', $pairs) : ''));
    }
}
