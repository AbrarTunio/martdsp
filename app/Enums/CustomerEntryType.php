<?php

namespace App\Enums;

/**
 * What a line on a customer's khata is.
 *
 * A sale on credit increases what the customer owes; a payment, a return or a
 * write-off reduces it. The screens say "owes more" and "paid", never debit
 * and credit.
 */
enum CustomerEntryType: string
{
    case Opening = 'opening';
    case SaleCredit = 'sale_credit';
    case Payment = 'payment';
    case SaleReturn = 'sale_return';
    case SaleVoid = 'sale_void';
    case Adjustment = 'adjustment';
    case WriteOff = 'write_off';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Balance brought forward',
            self::SaleCredit => 'Bought on khata',
            self::Payment => 'Payment received',
            self::SaleReturn => 'Goods returned',
            self::SaleVoid => 'Sale cancelled',
            self::Adjustment => 'Correction',
            self::WriteOff => 'Written off',
        };
    }

    /**
     * The <x-badge> tone for this type.
     */
    public function tone(): string
    {
        return match ($this) {
            self::SaleCredit, self::Opening => 'warning',
            self::Payment, self::SaleReturn => 'success',
            self::SaleVoid, self::WriteOff => 'danger',
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
