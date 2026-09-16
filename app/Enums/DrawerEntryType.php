<?php

namespace App\Enums;

/**
 * Why cash went into or out of a drawer.
 *
 * Pay-ins, pay-outs and safe drops are typed in by hand; the rest are written
 * by whatever moved the money — a sale, a void, a khata payment, a refund.
 */
enum DrawerEntryType: string
{
    case OpeningFloat = 'opening_float';
    case CashSale = 'cash_sale';
    case SaleVoid = 'sale_void';
    case PayIn = 'pay_in';
    case PayOut = 'pay_out';
    case SafeDrop = 'safe_drop';
    case KhataPayment = 'khata_payment';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::OpeningFloat => 'Opening cash',
            self::CashSale => 'Cash sale',
            self::SaleVoid => 'Cancelled sale',
            self::PayIn => 'Cash added',
            self::PayOut => 'Paid out',
            self::SafeDrop => 'Emptied the drawer',
            self::KhataPayment => 'Khata payment',
            self::Refund => 'Refund',
        };
    }

    /**
     * Whether this kind of movement takes cash out of the drawer.
     */
    public function isOut(): bool
    {
        return in_array($this, [self::SaleVoid, self::PayOut, self::SafeDrop, self::Refund], true);
    }

    /**
     * Whether a person types it in, rather than a sale or payment writing it.
     */
    public function isManual(): bool
    {
        return in_array($this, [self::PayIn, self::PayOut, self::SafeDrop], true);
    }

    /**
     * Whether it has to say why. Money leaving by hand always does.
     */
    public function needsNote(): bool
    {
        return $this === self::PayOut;
    }

    public function icon(): string
    {
        return match ($this) {
            self::OpeningFloat => 'wallet',
            self::CashSale => 'receipt',
            self::SaleVoid => 'close',
            self::PayIn => 'plus',
            self::PayOut => 'minus',
            self::SafeDrop => 'safe',
            self::KhataPayment => 'book',
            self::Refund => 'minus',
        };
    }

    /**
     * The three a person can record from the drawer screen.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::PayIn, self::PayOut, self::SafeDrop];
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
