<?php

namespace App\Support\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The stretch of days a report looks at.
 *
 * Every report reads the same `period` from the address — "this month",
 * "last month", or `custom` with a `from` and `to` — so a link to a report
 * always opens on the same days, and the owner can send it to the manager.
 *
 * Days are whole days in the shop's own time: a sale at ten past midnight
 * belongs to the day it was rung up on the shop wall, not in London.
 */
final class Period
{
    /** The longest a custom range may run, so a mistyped year cannot ask for a century. */
    public const MAX_DAYS = 731;

    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $preset,
    ) {}

    /**
     * The choices offered above every report, in the order they are shown.
     *
     * @return array<string, string>
     */
    public static function presets(): array
    {
        return [
            'today' => __('Today'),
            'yesterday' => __('Yesterday'),
            'this_week' => __('This week'),
            'this_month' => __('This month'),
            'last_month' => __('Last month'),
            'last_30_days' => __('Last 30 days'),
            'this_year' => __('This year'),
            'custom' => __('Pick dates'),
        ];
    }

    public static function fromRequest(Request $request, string $default = 'this_month'): self
    {
        $preset = $request->query('period');

        if ($preset === 'custom') {
            return self::between($request->query('from'), $request->query('to'));
        }

        return self::preset(is_string($preset) && array_key_exists($preset, self::presets()) ? $preset : $default);
    }

    public static function preset(string $preset): self
    {
        $today = CarbonImmutable::today();

        [$from, $to] = match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'this_week' => [$today->startOfWeek(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'last_30_days' => [$today->subDays(29), $today],
            'this_year' => [$today->startOfYear(), $today],
            default => [$today->startOfMonth(), $today],
        };

        return new self($from, $to, array_key_exists($preset, self::presets()) && $preset !== 'custom' ? $preset : 'this_month');
    }

    /**
     * A range typed in by hand. Anything unreadable falls back to something
     * sensible rather than an error: a report is a question, not a form.
     */
    public static function between(mixed $from, mixed $to): self
    {
        $today = CarbonImmutable::today();

        $to = (self::date($to) ?? $today)->min($today);
        $from = self::date($from) ?? $to->startOfMonth();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from->min($today)];
        }

        if (self::span($from, $to) > self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS - 1);
        }

        return new self($from, $to, 'custom');
    }

    /**
     * The first and last moments of the period, for a `whereBetween`.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        return [$this->from->startOfDay(), $this->to->endOfDay()];
    }

    public function days(): int
    {
        return self::span($this->from, $this->to);
    }

    public function isSingleDay(): bool
    {
        return $this->from->equalTo($this->to);
    }

    /**
     * Every day in the period as Y-m-d, oldest first, so a report can show a
     * day nothing was sold rather than skip it.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = [];

        for ($day = $this->from; $day->lessThanOrEqualTo($this->to); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /**
     * The stretch to compare against: the same days of last month for a
     * month, the same days last year for a year, and otherwise the same
     * number of days just before.
     */
    public function previous(): self
    {
        return match ($this->preset) {
            'this_month' => new self($this->from->subMonthNoOverflow(), $this->to->subMonthNoOverflow(), 'custom'),
            'last_month' => new self($this->from->subMonthNoOverflow(), $this->from->subMonthNoOverflow()->endOfMonth()->startOfDay(), 'custom'),
            'this_year' => new self($this->from->subYearNoOverflow(), $this->to->subYearNoOverflow(), 'custom'),
            default => new self($this->from->subDays($this->days()), $this->from->subDay(), 'custom'),
        };
    }

    /**
     * The period in words, e.g. "1 – 15 Sep 2026".
     */
    public function label(): string
    {
        if ($this->isSingleDay()) {
            return $this->from->format('D, d M Y');
        }

        if ($this->from->isSameMonth($this->to)) {
            return $this->from->format('j').' – '.$this->to->format('j M Y');
        }

        if ($this->from->isSameYear($this->to)) {
            return $this->from->format('j M').' – '.$this->to->format('j M Y');
        }

        return $this->from->format('j M Y').' – '.$this->to->format('j M Y');
    }

    /**
     * The preset's own name, or the dates for a custom range.
     */
    public function name(): string
    {
        return $this->preset === 'custom' ? $this->label() : self::presets()[$this->preset];
    }

    /**
     * The address parameters that reopen this period.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        if ($this->preset !== 'custom') {
            return ['period' => $this->preset];
        }

        return ['period' => 'custom', 'from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }

    /**
     * For a file name: "2026-09-01-to-2026-09-15".
     */
    public function slug(): string
    {
        return $this->isSingleDay()
            ? $this->from->toDateString()
            : $this->from->toDateString().'-to-'.$this->to->toDateString();
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
            return null;
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value);
    }

    private static function span(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) round($from->startOfDay()->diffInDays($to->startOfDay())) + 1;
    }
}
