<?php

namespace App\Enums;

/**
 * How the shop made good on goods a customer brought back.
 *
 * Cash comes out of the drawer at the counter and has to be counted for at
 * the end of the shift. Khata takes it off what the customer owes, which is
 * the usual answer for a regular who buys on account — and the only one when
 * the shop would rather not open the drawer at all.
 */
enum RefundMethod: string
{
    case Cash = 'cash';
    case Khata = 'khata';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash back out of the drawer',
            self::Khata => 'Off what they owe on khata',
        };
    }

    public function isCash(): bool
    {
        return $this === self::Cash;
    }

    /**
     * Khata credit needs somebody to credit it to.
     */
    public function needsCustomer(): bool
    {
        return $this === self::Khata;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $method): array => [$method->value => $method->label()])
            ->all();
    }
}
