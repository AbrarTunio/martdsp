<?php

namespace App\Support;

/**
 * Rupee formatting and parsing.
 *
 * Money is stored throughout the application as an integer number of paisa
 * (see docs/DATABASE.md, invariant 3). This class and resources/js/money.js
 * are the only places that convert between paisa and display rupees.
 */
class Money
{
    public const SYMBOL = 'Rs.';

    /**
     * Format paisa as a plain rupee string: 123450 => "1,234.50".
     */
    public static function format(int $paisa): string
    {
        return number_format($paisa / 100, 2);
    }

    /**
     * Format paisa with the currency symbol: 123450 => "Rs. 1,234.50".
     */
    public static function withSymbol(int $paisa): string
    {
        return self::SYMBOL.' '.self::format($paisa);
    }

    /**
     * Format paisa without decimals, for dashboard tiles and badges where the
     * paisa are noise: 123450 => "Rs. 1,235".
     */
    public static function rounded(int $paisa): string
    {
        return self::SYMBOL.' '.number_format($paisa / 100);
    }

    /**
     * Parse a rupee figure typed by a user into integer paisa. Tolerates
     * thousands separators, stray spaces and a leading currency symbol.
     */
    public static function parse(string|int|float|null $input): int
    {
        if (is_int($input)) {
            return $input * 100;
        }

        $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $input) ?? '';

        if ($cleaned === '' || $cleaned === '-' || $cleaned === '.') {
            return 0;
        }

        return (int) round(((float) $cleaned) * 100);
    }

    /**
     * Apply a percentage to a paisa amount, rounding half-up to the paisa.
     * Used for GST and percentage discounts at the line level.
     */
    public static function percentOf(int $paisa, float $percent): int
    {
        return (int) round($paisa * $percent / 100);
    }

    /**
     * The paisa that would be shaved off by rounding an amount to the nearest
     * whole rupee. Positive means the customer pays less than the exact total.
     *
     * The bill records this as an explicit "round off" line rather than
     * absorbing it silently into a total.
     */
    public static function roundingAdjustment(int $paisa): int
    {
        return $paisa - ((int) round($paisa / 100) * 100);
    }
}
