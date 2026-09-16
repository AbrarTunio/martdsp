<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductUnit;

/**
 * A product the way the till needs it: every size it is sold in, with its
 * price, and enough about stock to warn before selling what is not there.
 *
 * Built only from relations already loaded — `baseUnit` and
 * `productUnits.unit` on the product — so a scan costs one query.
 */
class PosItem
{
    /**
     * @return array{
     *     product_id: int,
     *     name: string,
     *     name_ur: string|null,
     *     sku: string,
     *     is_weighted: bool,
     *     tax_rate: string,
     *     stock_qty_base: int,
     *     stock_label: string,
     *     base_unit: string,
     *     product_unit_id: int|null,
     *     units: list<array{id: int, name: string, label: string, factor: int, price_paisa: int}>,
     * }
     */
    public static function fromProduct(Product $product, ?ProductUnit $selected = null): array
    {
        $baseName = (string) ($product->baseUnit?->name ?? '');
        $selected ??= $product->defaultSaleUnit();

        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'name_ur' => $product->name_ur,
            'sku' => $product->sku,
            'is_weighted' => (bool) $product->is_weighted,
            'tax_rate' => (string) $product->tax_rate,
            'stock_qty_base' => (int) $product->stock_qty_base,
            'stock_label' => $product->stockBreakdown(),
            'base_unit' => $baseName,
            'product_unit_id' => $selected?->id,
            'units' => $product->productUnits
                ->sortByDesc('conversion_factor')
                ->map(fn (ProductUnit $unit): array => [
                    'id' => $unit->id,
                    'name' => (string) ($unit->unit?->name ?? ''),
                    'label' => $unit->label($baseName),
                    'factor' => max(1, (int) $unit->conversion_factor),
                    'price_paisa' => (int) $unit->sale_price_paisa,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The same, starting from the size a barcode identified.
     *
     * @return array<string, mixed>
     */
    public static function fromProductUnit(ProductUnit $productUnit): array
    {
        $product = $productUnit->product;
        $product->loadMissing(['baseUnit', 'productUnits.unit']);

        return self::fromProduct($product, $productUnit);
    }
}
