<?php

namespace App\Enums;

/**
 * Why goods went back to a supplier.
 *
 * A required list for the same reason adjustments have one: "expired" coming
 * back from one distributor month after month is a buying problem the owner
 * can fix, and it is invisible in a free-text box.
 */
enum ReturnReason: string
{
    case Expired = 'expired';
    case Damaged = 'damaged';
    case WrongItem = 'wrong_item';
    case Unsold = 'unsold';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Expired => 'Expired or near expiry',
            self::Damaged => 'Damaged',
            self::WrongItem => 'Wrong item delivered',
            self::Unsold => 'Not selling',
            self::Other => 'Other',
        };
    }

    /**
     * The <x-badge> tone for this reason.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Expired, self::Damaged => 'danger',
            self::WrongItem => 'warning',
            self::Unsold, self::Other => 'neutral',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->label()])
            ->all();
    }
}
