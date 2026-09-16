<?php

namespace App\Enums;

/**
 * How money changed hands.
 *
 * Kept to the ways a Pakistani shop actually pays and is paid. Mobile wallets
 * are one entry rather than two because the shopkeeper thinks of Easypaisa
 * and JazzCash as the same thing: money that arrived on the phone.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case MobileWallet = 'mobile_wallet';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank transfer',
            self::Cheque => 'Cheque',
            self::MobileWallet => 'Easypaisa / JazzCash',
        };
    }

    /**
     * Cash is the only method that leaves the drawer, which the drawer module
     * (Phase 5) will need to know about.
     */
    public function isCash(): bool
    {
        return $this === self::Cash;
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
