<?php

namespace App\Support\Reports;

use App\Models\Product;

/**
 * What the stock of one product is worth, at cost and at the till.
 *
 * At cost it is the running average cost of one smallest unit. At the till
 * it is priced by the smallest unit where that has a price, or else by the
 * pack that works out cheapest per piece — the lowest the goods would fetch,
 * so the figure is never flattering. Stock below zero is worth nothing: it
 * is a counting mistake to be fixed, not goods on the shelf.
 *
 * Expects the product's units to be loaded with productUnits.
 */
final class ShelfValue
{
    /**
     * @return array{cost: int, retail: int}
     */
    public static function of(Product $product): array
    {
        $qty = max(0, (int) $product->stock_qty_base);

        return [
            'cost' => $qty * (int) $product->avg_cost_base_paisa,
            'retail' => self::retail($product, $qty),
        ];
    }

    private static function retail(Product $product, int $qty): int
    {
        if ($qty === 0) {
            return 0;
        }

        $priced = $product->productUnits
            ->filter(fn ($unit): bool => (int) $unit->sale_price_paisa > 0 && (int) $unit->conversion_factor > 0);

        $unit = $priced->firstWhere('is_base', true)
            ?? $priced->sortBy(fn ($unit): float => $unit->sale_price_paisa / $unit->conversion_factor)->first();

        return $unit === null
            ? 0
            : (int) round($qty * (int) $unit->sale_price_paisa / (int) $unit->conversion_factor);
    }
}
