<?php

namespace App\Enums;

/**
 * Why a customer brought goods back.
 *
 * A fixed list rather than a free-text box, because the pattern is the point:
 * one brand coming back damaged week after week is a supplier to have a word
 * with, and "changed their mind" on half the returns is a pricing or a
 * packaging problem. Neither shows up in a sentence typed by a cashier.
 */
enum SaleReturnReason: string
{
    case Damaged = 'damaged';
    case Expired = 'expired';
    case WrongItem = 'wrong_item';
    case ChangedMind = 'changed_mind';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Damaged or broken',
            self::Expired => 'Expired or near expiry',
            self::WrongItem => 'Wrong item given',
            self::ChangedMind => 'Changed their mind',
            self::Other => 'Other',
        };
    }

    /**
     * The <x-badge> tone for this reason.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Damaged, self::Expired => 'danger',
            self::WrongItem => 'warning',
            self::ChangedMind, self::Other => 'neutral',
        };
    }

    /**
     * Whether the goods are fit to go back on the shelf.
     *
     * Something returned because the customer changed their mind is sold
     * again tomorrow; something expired or broken is not, and putting it back
     * into stock would be a lie the shelf count later pays for.
     */
    public function goesBackOnTheShelf(): bool
    {
        return in_array($this, [self::WrongItem, self::ChangedMind, self::Other], true);
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
