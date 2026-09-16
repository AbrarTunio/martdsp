<?php

namespace App\Support;

use App\Enums\PurchaseStatus;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseItem;
use App\Models\Supplier;

/**
 * One line of the purchase form, in the shape the Alpine component reads.
 *
 * Shared by the scanner lookup, the quick-create sheet, a saved draft and a
 * form coming back after a validation failure, so the four can never drift
 * apart. It carries what the shop last paid for each size, because "same as
 * last time?" is the question asked at every carton, and the average cost
 * and sale price, so the form can warn when a price looks wrong before it
 * reaches the ledger.
 */
class PurchaseRows
{
    /**
     * One supplier in the shape the form's picker reads.
     *
     * The phone number is on it because the picker searches by it: the
     * shopkeeper often knows the salesman by the number in their phone long
     * before they can spell the company on the bill.
     *
     * @return array{id: int, label: string, name: string, company: string, phone: string, terms: int, balance_paisa: int}
     */
    public static function supplier(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'label' => $supplier->displayName(),
            'name' => (string) $supplier->name,
            'company' => (string) $supplier->company,
            'phone' => PhoneNumber::forHumans($supplier->phone),
            'terms' => (int) $supplier->payment_terms_days,
            'balance_paisa' => (int) $supplier->balance_paisa,
        ];
    }

    /**
     * @param  array<string, string|null>  $typed  what was already entered against the line
     * @return array<string, mixed>
     */
    public static function row(Product $product, int|string|null $productUnitId = null, array $typed = []): array
    {
        $product->loadMissing(['baseUnit', 'productUnits.unit']);

        $lastCosts = static::lastCostsPerPack($product);

        $units = $product->productUnits
            ->sortBy('conversion_factor')
            ->values()
            ->map(fn (ProductUnit $level): array => [
                'id' => $level->id,
                'label' => $level->label($product->baseUnit?->name),
                'factor' => (int) $level->conversion_factor,
                'last_cost' => ($lastCosts[$level->id] ?? 0) > 0
                    ? number_format($lastCosts[$level->id] / 100, 2, '.', '')
                    : '',
                'sale_price_paisa' => (int) $level->sale_price_paisa,
            ])
            ->all();

        $chosen = filled($productUnitId)
            ? (int) $productUnitId
            : (static::defaultPurchaseUnit($product)?->id ?? ($units[0]['id'] ?? null));

        $lastCostForChosen = collect($units)->firstWhere('id', $chosen)['last_cost'] ?? '';

        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'units' => $units,
            'product_unit_id' => $chosen,
            'qty' => (string) ($typed['qty'] ?? ''),
            'bonus_qty' => (string) ($typed['bonus_qty'] ?? ''),
            'unit_cost' => (string) ($typed['unit_cost'] ?? $lastCostForChosen),
            'batch_no' => (string) ($typed['batch_no'] ?? ''),
            'expiry_date' => (string) ($typed['expiry_date'] ?? ''),
            'avg_cost_base' => (int) $product->avg_cost_base_paisa,
            'sale_price_base' => $product->defaultSaleUnit()?->pricePerBasePaisa() ?? 0,
            'track_expiry' => (bool) $product->track_expiry,
            'track_batches' => (bool) $product->track_batches,
            'stock_base' => (int) $product->stock_qty_base,
            'stock_words' => Packaging::describe($product->stock_qty_base, $product->productUnits),
        ];
    }

    /**
     * The size a delivery of this item usually comes in: the one marked on
     * the product, or else the biggest.
     */
    public static function defaultPurchaseUnit(Product $product): ?ProductUnit
    {
        return $product->productUnits->firstWhere('is_default_purchase', true)
            ?? $product->productUnits->sortByDesc('conversion_factor')->first();
    }

    /**
     * What the shop last paid for one of each size, in paisa.
     *
     * The size bought last time gets the bill's own figure. Any other size is
     * worked out from it, so a box is priced sensibly even though only cartons
     * have ever been bought. With no delivery on record yet, the moving
     * average stands in.
     *
     * @return array<int, int> keyed by product unit id
     */
    public static function lastCostsPerPack(Product $product): array
    {
        $last = PurchaseItem::query()
            ->with('productUnit')
            ->where('product_id', $product->id)
            ->where('unit_cost_paisa', '>', 0)
            ->whereHas('purchase', fn ($query) => $query->where('status', PurchaseStatus::Received))
            ->latest('id')
            ->first();

        $costs = [];

        foreach ($product->productUnits as $level) {
            $factor = max(1, (int) $level->conversion_factor);

            $costs[$level->id] = match (true) {
                $last !== null && (int) $last->product_unit_id === (int) $level->id => (int) $last->unit_cost_paisa,
                $last !== null => (int) round($last->unit_cost_paisa / $last->factor() * $factor),
                default => (int) $product->avg_cost_base_paisa * $factor,
            };
        }

        return $costs;
    }
}
