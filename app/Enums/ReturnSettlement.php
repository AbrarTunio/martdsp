<?php

namespace App\Enums;

/**
 * How a supplier made good on goods sent back.
 *
 * Almost always a credit: the salesman takes the expired packets and the
 * next bill is that much smaller. Cash back is the exception, and the only
 * option when the goods came from the market with no supplier to owe.
 */
enum ReturnSettlement: string
{
    case Credit = 'credit';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Take it off what I owe',
            self::Cash => 'They gave me cash back',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $settlement): array => [$settlement->value => $settlement->label()])
            ->all();
    }
}
