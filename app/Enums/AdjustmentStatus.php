<?php

namespace App\Enums;

/**
 * Where an adjustment is in its short life.
 *
 * A draft touches nothing, so a half-counted shelf can be left and returned
 * to. Posting is the one-way door: it writes to the ledger, and from then on
 * a mistake is corrected with another adjustment rather than by editing this
 * one.
 */
enum AdjustmentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Not posted yet',
            self::Posted => 'Posted',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * The <x-badge> tone for this status.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Posted => 'success',
            self::Cancelled => 'neutral',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}
