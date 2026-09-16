<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Cashier = 'cashier';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Manager => 'Manager',
            self::Cashier => 'Cashier',
        };
    }

    /**
     * Whether this role may see cost, margin and profit figures.
     */
    public function seesFinancials(): bool
    {
        return $this !== self::Cashier;
    }

    /**
     * Whether this role may approve a drawer variance or void a completed sale.
     */
    public function supervises(): bool
    {
        return in_array($this, [self::Owner, self::Manager], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $role): array => [$role->value => $role->label()])
            ->all();
    }
}
