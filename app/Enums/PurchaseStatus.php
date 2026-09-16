<?php

namespace App\Enums;

/**
 * Where a delivery is in its short life.
 *
 * A draft touches nothing: a delivery can be scanned in over half an hour,
 * checked against the paper bill, and only then received. Receiving is the
 * one-way door — it writes stock, cost and the supplier's balance in one go,
 * and from then on a mistake is corrected with a return or an adjustment.
 */
enum PurchaseStatus: string
{
    case Draft = 'draft';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Not received yet',
            self::Received => 'Received',
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
            self::Received => 'success',
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
