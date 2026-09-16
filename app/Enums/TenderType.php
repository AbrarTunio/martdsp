<?php

namespace App\Enums;

/**
 * How a customer paid at the till.
 *
 * Unlike App\Enums\PaymentMethod, the two wallets are kept apart here: the
 * owner reconciles Easypaisa and JazzCash against two different phones at the
 * end of the day. Khata is a tender too — it is how "put it on my account"
 * sits alongside cash on the same bill.
 */
enum TenderType: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Easypaisa = 'easypaisa';
    case JazzCash = 'jazzcash';
    case BankTransfer = 'bank';
    case Khata = 'khata';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Card => 'Card',
            self::Easypaisa => 'Easypaisa',
            self::JazzCash => 'JazzCash',
            self::BankTransfer => 'Bank transfer',
            self::Khata => 'Khata',
        };
    }

    /**
     * Cash is the only tender that can be over-paid, because it is the only
     * one change can be given back from. It is also the only one the drawer
     * count (Phase 5) has to answer for.
     */
    public function isCash(): bool
    {
        return $this === self::Cash;
    }

    /**
     * A khata sale has to be written on somebody's khata.
     */
    public function needsCustomer(): bool
    {
        return $this === self::Khata;
    }

    /**
     * Whether money actually changed hands. Khata is a promise, not payment.
     */
    public function isPayment(): bool
    {
        return $this !== self::Khata;
    }

    /**
     * The tenders money can actually arrive by. Khata is left out: it is how
     * a bill goes unpaid, so it can never be how one is settled.
     *
     * @return array<string, string>
     */
    public static function paymentOptions(): array
    {
        return collect(self::cases())
            ->filter(fn (self $type): bool => $type->isPayment())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
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
