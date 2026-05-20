<?php

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;

class DashboardPeriodService
{
    public const DEFAULT_PERIOD = 'day';
    private const ALLOWED_PERIODS = ['day', 'week', 'month'];

    public static function normalizePeriod(?string $period): string
    {
        $normalized = strtolower(trim((string)$period));
        return in_array($normalized, self::ALLOWED_PERIODS, true) ? $normalized : self::DEFAULT_PERIOD;
    }

    public static function resolve(?string $period, ?string $timezone = null): array
    {
        $period = self::normalizePeriod($period);
        $timezone = trim((string)$timezone) !== '' ? trim((string)$timezone) : (getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
        $tz = new DateTimeZone($timezone);
        $now = new DateTimeImmutable('now', $tz);

        [$start, $end, $previousStart, $previousEnd] = match ($period) {
            'week' => self::resolveWeek($now),
            'month' => self::resolveMonth($now),
            default => self::resolveDay($now),
        };

        return [
            'period' => $period,
            'timezone' => $timezone,
            'start' => $start,
            'end' => $end,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'start_sql' => $start->format('Y-m-d H:i:s'),
            'end_sql' => $end->format('Y-m-d H:i:s'),
            'previous_start_sql' => $previousStart->format('Y-m-d H:i:s'),
            'previous_end_sql' => $previousEnd->format('Y-m-d H:i:s'),
        ];
    }

    private static function resolveDay(DateTimeImmutable $now): array
    {
        $start = $now->setTime(0, 0, 0);
        $end = $now->setTime(23, 59, 59);
        $previousStart = $start->modify('-1 day');
        $previousEnd = $end->modify('-1 day');

        return [$start, $end, $previousStart, $previousEnd];
    }

    private static function resolveWeek(DateTimeImmutable $now): array
    {
        $dayOfWeek = (int)$now->format('N');
        $start = $now->modify('-' . ($dayOfWeek - 1) . ' days')->setTime(0, 0, 0);
        $end = $start->modify('+6 days')->setTime(23, 59, 59);
        $previousStart = $start->modify('-7 days');
        $previousEnd = $end->modify('-7 days');

        return [$start, $end, $previousStart, $previousEnd];
    }

    private static function resolveMonth(DateTimeImmutable $now): array
    {
        $start = $now->modify('first day of this month')->setTime(0, 0, 0);
        $end = $now->modify('last day of this month')->setTime(23, 59, 59);
        $previousStart = $start->modify('first day of previous month')->setTime(0, 0, 0);
        $previousEnd = $start->modify('last day of previous month')->setTime(23, 59, 59);

        return [$start, $end, $previousStart, $previousEnd];
    }
}
