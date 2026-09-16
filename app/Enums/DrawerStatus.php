<?php

namespace App\Enums;

enum DrawerStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
        };
    }

    /**
     * The <x-badge> tone for this status.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::Closed => 'neutral',
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
