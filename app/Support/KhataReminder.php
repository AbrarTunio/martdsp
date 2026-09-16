<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Setting;

/**
 * The message a shopkeeper sends a customer who owes money.
 *
 * It is written to be read on a phone: who it is from, what is owed, how much
 * of it is late, and when they last paid — nothing else. The shopkeeper can
 * edit every word before it is sent; this is a starting point, not a demand
 * letter.
 */
final class KhataReminder
{
    /**
     * @param  CustomerLedgerEntry|null  $lastPayment  the customer's most recent payment, if any
     */
    public static function message(Customer $customer, KhataAging $aging, ?CustomerLedgerEntry $lastPayment = null): string
    {
        $shop = trim((string) Setting::read('shop.name')) ?: (string) config('app.name');
        $phone = trim((string) Setting::read('shop.phone'));

        $lines = [
            __('Assalam-o-Alaikum :name,', ['name' => $customer->name]),
            '',
            __('This is a khata reminder from :shop.', ['shop' => $shop]),
            __('Your balance today is :amount.', ['amount' => Money::withSymbol(max(0, $aging->owedPaisa))]),
        ];

        if ($aging->isOverdue()) {
            $lines[] = __(':amount of this is past due:since.', [
                'amount' => Money::withSymbol($aging->overduePaisa()),
                'since' => $aging->oldestDueOn
                    ? ' '.__('— the oldest since :date', ['date' => $aging->oldestDueOn->format('d M Y')])
                    : '',
            ]);
        }

        if ($lastPayment) {
            $lines[] = __('Your last payment was :amount on :date. Shukriya.', [
                'amount' => Money::withSymbol((int) $lastPayment->credit_paisa),
                'date' => $lastPayment->entry_date->format('d M Y'),
            ]);
        }

        $lines[] = '';
        $lines[] = __('Please clear it whenever you are passing by. If you have already paid, please ignore this message.');
        $lines[] = $phone !== ''
            ? __('— :shop, :phone', ['shop' => $shop, 'phone' => $phone])
            : __('— :shop', ['shop' => $shop]);

        return implode("\n", $lines);
    }

    /**
     * A Pakistani mobile number in the form wa.me wants: country code, no
     * plus, no spaces. Returns null when there is nothing dialable.
     */
    public static function whatsappNumber(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        $digits = match (true) {
            $digits === '' => '',
            str_starts_with($digits, '00') => substr($digits, 2),
            str_starts_with($digits, '92') => $digits,
            str_starts_with($digits, '0') => '92'.substr($digits, 1),
            strlen($digits) === 10 => '92'.$digits,
            default => $digits,
        };

        return strlen($digits) >= 10 ? $digits : null;
    }
}
