<?php

namespace App\Http\Middleware;

use App\Service\PerformanceTelemetry;
use Closure;

class PerformanceMonitorMiddleware
{
    public function handle($request, Closure $next)
    {
        $startedAt = microtime(true);
        $startedMemory = memory_get_usage(true);

        $response = $next($request);

        $durationMs = round((microtime(true) - $startedAt) * 1000, 2);
        $memoryDeltaMb = round((memory_get_usage(true) - $startedMemory) / 1024 / 1024, 2);
        $peakMb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        if ($durationMs >= 750 || $peakMb >= 64) {
            PerformanceTelemetry::log('request', [
                'method' => $request->getHttpMethod(),
                'route' => $request->getRouteName(),
                'uri' => $request->getURI(),
                'duration_ms' => $durationMs,
                'memory_delta_mb' => $memoryDeltaMb,
                'peak_mb' => $peakMb,
            ]);
        }

        return $response;
    }
}
