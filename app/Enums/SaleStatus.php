<?php

namespace App\Enums;

/**
 * Where a bill stands.
 *
 * A held sale is a parked basket and has touched nothing. Only a completed
 * sale has moved stock, taken money or written to a khata, and only a
 * supervisor can void one — which puts every one of those things back.
 */
enum SaleStatus: string
{
    case Held = 'held';
    case Completed = 'completed';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Held => 'On hold',
            self::Completed => 'Completed',
            self::Void => 'Voided',
        };
    }

    /**
     * The <x-badge> tone for this status.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Held => 'warning',
            self::Completed => 'success',
            self::Void => 'danger',
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
