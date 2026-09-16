<?php

namespace App\Services;

use App\Models\Barcode;
use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Database\Eloquent\Collection;

/**
 * Turns whatever a scanner or a typed search produces into a product.
 *
 * A USB barcode scanner is a keyboard that types very fast and presses Enter.
 * By the time the code reaches here it is just a string, so the only thing
 * that matters is resolving it in one indexed lookup.
 */
class ScanService
{
    /**
     * The packaging level a scanned code identifies, with everything the till
     * needs to price it already loaded.
     */
    public function resolve(string $code): ?ProductUnit
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        $barcode = Barcode::with(['productUnit.unit', 'productUnit.product.baseUnit'])
            ->where('code', $code)
            ->first();

        return $barcode?->productUnit;
    }

    /**
     * Whether a code is already claimed, so the product form can say so
     * before the unique index does it less kindly.
     */
    public function isTaken(string $code, ?int $ignoreProductId = null): bool
    {
        $query = Barcode::where('code', trim($code));

        if ($ignoreProductId !== null) {
            $query->whereHas('productUnit', fn ($unit) => $unit->where('product_id', '!=', $ignoreProductId));
        }

        return $query->exists();
    }

    /**
     * Typed search for the counter: a few matching products, cheapest path
     * first. Used when an item has no barcode or the label is torn.
     *
     * @return Collection<int, Product>
     */
    public function search(string $term, int $limit = 15): Collection
    {
        return Product::query()
            ->active()
            ->search($term)
            ->with(['baseUnit', 'productUnits.unit'])
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}
