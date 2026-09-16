<?php

namespace App\Services;

use App\Support\Money;
use RuntimeException;

/**
 * A price was changed in the back office while a basket was being rung up,
 * so the total on the cashier's screen is not what the server would charge.
 *
 * Carries the current prices so the till can correct itself and let the
 * cashier confirm the new total, rather than charging a figure nobody saw.
 */
class PricesChangedException extends RuntimeException
{
    /**
     * @param  array<int, int>  $prices  current sale price in paisa, keyed by product unit id
     */
    public function __construct(
        public readonly array $prices,
        public readonly int $totalPaisa,
    ) {
        parent::__construct(__('Some prices have changed since these items were scanned. The new total is :total — check it with the customer and press Complete again.', [
            'total' => Money::withSymbol($totalPaisa),
        ]));
    }
}
