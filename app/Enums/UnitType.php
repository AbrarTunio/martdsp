<?php

namespace App\Enums;

/**
 * What a unit measures. This decides whether a quantity can be fractional:
 * you can sell 0.75 kg of rice, but not 0.75 of a sachet.
 */
enum UnitType: string
{
    case Count = 'count';
    case Weight = 'weight';
    case Volume = 'volume';

    public function label(): string
    {
        return match ($this) {
            self::Count => 'Count',
            self::Weight => 'Weight',
            self::Volume => 'Volume',
        };
    }

    /**
     * Whether a part of this unit can be sold.
     */
    public function allowsFractions(): bool
    {
        return $this !== self::Count;
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
