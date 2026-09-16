<?php

namespace App\Support;

/**
 * Sharing a whole amount out in proportion, to the paisa.
 *
 * A bill's Rs. 500 discount has to land on its lines before the cost of any
 * one item can be known, and the shares have to add back up to exactly
 * Rs. 500 — a paisa lost to rounding on every delivery is a stock value that
 * never reconciles with what was paid.
 *
 * This is the largest-remainder method: everyone gets the whole paisa they
 * are owed, and the paisa left over go one each to whoever lost the most in
 * the rounding.
 */
class Allocation
{
    /**
     * Split an amount across weights. The result has the same keys as the
     * weights, and its values always sum to exactly the amount.
     *
     * When every weight is zero the amount is shared evenly, because "in
     * proportion to nothing" still has to put the money somewhere.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $weights
     * @return array<TKey, int>
     */
    public static function spread(int $amount, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $weights = array_map(fn (int $weight): int => max(0, $weight), $weights);
        $total = array_sum($weights);

        if ($total === 0) {
            $weights = array_map(fn (): int => 1, $weights);
            $total = count($weights);
        }

        $sign = $amount < 0 ? -1 : 1;
        $amount = abs($amount);

        $shares = [];
        $remainders = [];

        foreach ($weights as $key => $weight) {
            [$shares[$key], $remainders[$key]] = self::divide($amount, $weight, $total);
        }

        /* PHP's sort is stable, so ties go to whichever line came first. */
        $order = array_keys($remainders);
        usort($order, fn (int|string $a, int|string $b): int => $remainders[$b] <=> $remainders[$a]);

        $leftover = $amount - array_sum($shares);

        while ($leftover !== 0) {
            foreach ($order as $key) {
                if ($leftover === 0) {
                    break;
                }

                if ($leftover > 0) {
                    $shares[$key]++;
                    $leftover--;
                } elseif ($shares[$key] > 0) {
                    $shares[$key]--;
                    $leftover++;
                }
            }
        }

        return array_map(fn (int $share): int => $share * $sign, $shares);
    }

    /**
     * One weight's whole share and what the rounding cost it, both measured
     * against the same denominator so the remainders can be compared.
     *
     * @return array{0: int, 1: int|float}
     */
    private static function divide(int $amount, int $weight, int $total): array
    {
        if ($weight === 0) {
            return [0, 0];
        }

        if ($amount <= intdiv(PHP_INT_MAX, $weight)) {
            $product = $amount * $weight;

            return [intdiv($product, $total), $product % $total];
        }

        /* Only reached by amounts no shop's bill will ever come to. Floats
           lose a paisa here and there, which the leftover loop puts back. */
        $exact = $amount / $total * $weight;
        $share = (int) floor($exact);

        return [$share, ($exact - $share) * $total];
    }
}
