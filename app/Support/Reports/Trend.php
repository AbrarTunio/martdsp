<?php

namespace App\Support\Reports;

/**
 * "Up 12% on last month" — how a figure compares with the one before it.
 *
 * Shared by the reports and the dashboard so that a rise is worded and
 * coloured the same everywhere.
 */
final class Trend
{
    /**
     * The change as a percentage of the earlier figure, or null when there
     * was nothing to compare with.
     */
    public static function percent(int $now, int $before): ?float
    {
        return $before === 0 ? null : ($now - $before) / abs($before) * 100;
    }

    public static function describe(int $now, int $before, string $against): string
    {
        $percent = self::percent($now, $before);

        if ($percent === null) {
            return $now === 0
                ? __('Nothing yet, nor in :against', ['against' => $against])
                : __('Nothing to compare with in :against', ['against' => $against]);
        }

        if (abs($percent) < 0.5) {
            return __('Level with :against', ['against' => $against]);
        }

        $replace = [
            'percent' => number_format(abs($percent), abs($percent) < 10 ? 1 : 0),
            'against' => $against,
        ];

        return $percent > 0
            ? __('▲ :percent% on :against', $replace)
            : __('▼ :percent% on :against', $replace);
    }

    /**
     * The stat-card tone: green for up, amber for down. Pass `$higherIsBetter`
     * false for figures like discounts or returns, where up is the worry.
     */
    public static function tone(int $now, int $before, bool $higherIsBetter = true): string
    {
        $percent = self::percent($now, $before);

        if ($percent === null || abs($percent) < 0.5) {
            return 'neutral';
        }

        return ($percent > 0) === $higherIsBetter ? 'success' : 'warning';
    }
}
