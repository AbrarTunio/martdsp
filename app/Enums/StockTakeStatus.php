<?php

namespace App\Enums;

/**
 * Where a stock take is in its life.
 *
 * Counting a section of the shop takes an evening, and nothing it says
 * reaches the ledger while it is being counted — the sheet can be left half
 * done and picked up again, which is what actually happens when a customer
 * walks in mid-count.
 *
 * Posting is the one-way door. From then on the count is evidence, and a
 * mistake in it is corrected with another count or a correction, never by
 * editing what was signed off.
 */
enum StockTakeStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Still counting',
            self::Posted => 'Posted',
            self::Cancelled => 'Abandoned',
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
