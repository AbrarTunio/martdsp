<?php

namespace App\Enums;

/**
 * How much a finding matters. Decided by the shop's own rules, never by the
 * AI, so a badge is the same colour whichever provider wrote the note.
 */
enum InsightSeverity: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Good = 'good';

    public function label(): string
    {
        return match ($this) {
            self::High => __('Act now'),
            self::Medium => __('Look into it'),
            self::Low => __('Worth knowing'),
            self::Good => __('Going well'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::High => 'danger',
            self::Medium => 'warning',
            self::Low => 'neutral',
            self::Good => 'success',
        };
    }

    /**
     * Sort order: the most urgent first, good news last.
     */
    public function rank(): int
    {
        return match ($this) {
            self::High => 0,
            self::Medium => 1,
            self::Low => 2,
            self::Good => 3,
        };
    }
}
