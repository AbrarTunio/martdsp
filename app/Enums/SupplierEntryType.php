<?php

namespace App\Enums;

/**
 * What a line on a supplier's statement is.
 *
 * The ledger is kept the way the shop's own books would keep it: a bill
 * increases what is owed, a payment or a return reduces it. The screens say
 * "you owe more" and "you owe less" — debit and credit mean the opposite to
 * half the people who read them.
 */
enum SupplierEntryType: string
{
    case Opening = 'opening';
    case Purchase = 'purchase';
    case Payment = 'payment';
    case Return = 'return';
    case Refund = 'refund';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Balance brought forward',
            self::Purchase => 'Bill',
            self::Payment => 'Payment',
            self::Return => 'Goods returned',
            self::Refund => 'Cash back for a return',
            self::Adjustment => 'Correction',
        };
    }

    /**
     * The <x-badge> tone for this type.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Purchase, self::Opening => 'warning',
            self::Payment, self::Return => 'success',
            self::Refund => 'info',
            self::Adjustment => 'neutral',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
