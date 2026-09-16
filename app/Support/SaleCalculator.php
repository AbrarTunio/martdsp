<?php

namespace App\Support;

/**
 * The arithmetic of a bill.
 *
 * The till works a bill out as the cashier builds it, and the server works it
 * out again when the sale is completed, from prices it looked up itself.
 * resources/js/sale-maths.js is a line-for-line copy of this class, so both
 * reach the same answer to the paisa. Change one and you must change the other;
 * tests/Unit/SaleCalculatorTest pins the figures both are held to.
 *
 * The order of operations is the order a shopkeeper would work a bill out on
 * paper:
 *
 *  1. Each line is qty × price, rounded half-up to the paisa.
 *  2. The line's own discount comes off — "20" is rupees, "10%" is a percentage.
 *  3. A discount on the whole bill is shared over the lines in proportion to
 *     what each has left, with App\Support\Allocation, so the shares add up to
 *     it exactly.
 *  4. GST is worked out on what each line has left. When shelf prices include
 *     GST it is the part of that amount that was tax; when they do not, it is
 *     added on top.
 *  5. The total may be rounded to the nearest rupee, and the rounding kept as
 *     its own figure so the receipt still adds up.
 *
 * Quantities, rates and typed discounts are read as exact decimals, never as
 * floats, so "0.1 kg" is 100 grams and not 99.99999.
 */
class SaleCalculator
{
    /**
     * What a typed discount may look like: "50", "12.50", "Rs 50", "10%".
     */
    public const DISCOUNT_PATTERN = '/^\s*(?:rs\.?\s*)?\d{1,9}(?:\.\d{1,2})?\s*%?\s*$/i';

    /**
     * Work out a whole bill.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, array{qty: string|int|float, unit_price_paisa: int, tax_rate: string|int|float|null, discount?: string|null}>  $lines
     * @return array{
     *     lines: array<TKey, array{qty_milli: int, gross_paisa: int, discount_paisa: int, bill_discount_paisa: int, net_paisa: int, tax_paisa: int, line_total_paisa: int}>,
     *     subtotal_paisa: int,
     *     line_discount_paisa: int,
     *     bill_discount_paisa: int,
     *     discount_paisa: int,
     *     tax_paisa: int,
     *     exact_total_paisa: int,
     *     round_off_paisa: int,
     *     total_paisa: int,
     * }
     */
    public static function calculate(
        array $lines,
        ?string $billDiscount = null,
        bool $pricesIncludeTax = true,
        bool $roundToRupee = false,
    ): array {
        $worked = [];
        $afterLineDiscount = [];

        foreach ($lines as $key => $line) {
            $qtyMilli = self::qtyMilli($line['qty']);
            $gross = self::divRound($qtyMilli * max(0, (int) $line['unit_price_paisa']), 1000);
            $discount = self::discountPaisa($line['discount'] ?? null, $gross);

            $worked[$key] = [
                'qty_milli' => $qtyMilli,
                'gross_paisa' => $gross,
                'discount_paisa' => $discount,
                'rate_bp' => self::scaled($line['tax_rate'] ?? 0, 2) ?? 0,
            ];

            $afterLineDiscount[$key] = $gross - $discount;
        }

        $billDiscountPaisa = self::discountPaisa($billDiscount, array_sum($afterLineDiscount));
        $shares = Allocation::spread($billDiscountPaisa, $afterLineDiscount);

        $result = [];
        $totals = ['subtotal' => 0, 'line_discount' => 0, 'tax' => 0, 'exact' => 0];

        foreach ($worked as $key => $line) {
            $net = $afterLineDiscount[$key] - ($shares[$key] ?? 0);
            $tax = self::taxPaisa($net, $line['rate_bp'], $pricesIncludeTax);
            $lineTotal = $pricesIncludeTax ? $net : $net + $tax;

            $result[$key] = [
                'qty_milli' => $line['qty_milli'],
                'gross_paisa' => $line['gross_paisa'],
                'discount_paisa' => $line['discount_paisa'],
                'bill_discount_paisa' => $shares[$key] ?? 0,
                'net_paisa' => $net,
                'tax_paisa' => $tax,
                'line_total_paisa' => $lineTotal,
            ];

            $totals['subtotal'] += $line['gross_paisa'];
            $totals['line_discount'] += $line['discount_paisa'];
            $totals['tax'] += $tax;
            $totals['exact'] += $lineTotal;
        }

        $roundOff = $roundToRupee ? self::roundOff($totals['exact']) : 0;

        return [
            'lines' => $result,
            'subtotal_paisa' => $totals['subtotal'],
            'line_discount_paisa' => $totals['line_discount'],
            'bill_discount_paisa' => $billDiscountPaisa,
            'discount_paisa' => $totals['line_discount'] + $billDiscountPaisa,
            'tax_paisa' => $totals['tax'],
            'exact_total_paisa' => $totals['exact'],
            'round_off_paisa' => $roundOff,
            'total_paisa' => $totals['exact'] - $roundOff,
        ];
    }

    /**
     * A typed discount in paisa, never more than the amount it comes off.
     */
    public static function discountPaisa(?string $input, int $basePaisa): int
    {
        $text = preg_replace('/[^0-9.%]/', '', (string) $input) ?? '';

        if ($text === '' || $basePaisa <= 0) {
            return 0;
        }

        $isPercent = str_contains($text, '%');
        $number = self::scaled(str_replace('%', '', $text), 2) ?? 0;

        $paisa = $isPercent
            ? self::divRound($basePaisa * min($number, 10_000), 10_000)
            : $number;

        return min($basePaisa, $paisa);
    }

    /**
     * GST on a line. `$rateBp` is the rate in hundredths of a percent, so
     * 18% is 1800.
     */
    public static function taxPaisa(int $netPaisa, int $rateBp, bool $pricesIncludeTax): int
    {
        if ($netPaisa <= 0 || $rateBp <= 0) {
            return 0;
        }

        return $pricesIncludeTax
            ? self::divRound($netPaisa * $rateBp, 10_000 + $rateBp)
            : self::divRound($netPaisa * $rateBp, 10_000);
    }

    /**
     * The paisa shaved off to reach the nearest rupee. Half a rupee rounds up,
     * so the figure is between -49 and 50.
     */
    public static function roundOff(int $paisa): int
    {
        return $paisa - intdiv($paisa + 50, 100) * 100;
    }

    /**
     * A quantity in thousandths: "1.25" is 1250.
     */
    public static function qtyMilli(string|int|float|null $qty): int
    {
        return self::scaled($qty, 3) ?? 0;
    }

    /**
     * Whether this many of a packaging level comes to a whole number of base
     * units. Half a carton of 24 boxes is 12 boxes and can be sold; half a
     * sachet cannot.
     */
    public static function isWholeInBase(int $qtyMilli, int $conversionFactor): bool
    {
        return ($qtyMilli * max(1, $conversionFactor)) % 1000 === 0;
    }

    /**
     * Base units in a quantity of a packaging level, once it is known to be
     * whole.
     */
    public static function qtyBase(int $qtyMilli, int $conversionFactor): int
    {
        return intdiv($qtyMilli * max(1, $conversionFactor), 1000);
    }

    /**
     * Read a non-negative decimal as an integer scaled by 10^decimals, from
     * its digits rather than through a float. Digits beyond `$decimals` are
     * dropped. Null when the value is not a number.
     */
    public static function scaled(string|int|float|null $value, int $decimals): ?int
    {
        $text = trim((string) $value);

        if (! preg_match('/^(\d*)(?:\.(\d*))?$/', $text, $matches) || $text === '' || $text === '.') {
            return null;
        }

        $whole = $matches[1] === '' ? 0 : (int) $matches[1];
        $fraction = substr(str_pad($matches[2] ?? '', $decimals, '0'), 0, $decimals);

        return $whole * (10 ** $decimals) + ($decimals > 0 ? (int) $fraction : 0);
    }

    /**
     * a ÷ b rounded half-up, for a ≥ 0 and b > 0.
     */
    public static function divRound(int $numerator, int $denominator): int
    {
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
