<?php

namespace App\Services;

use App\Enums\PurchaseStatus;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Support\PurchaseRows;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "What do I need to order?" — answered from the reorder points.
 *
 * Every active item at or below its reorder level is listed, with a quantity
 * rounded up to the size it is normally bought in, and grouped under the
 * supplier it last came from. That grouping is the point: the owner is on
 * the phone to one salesman at a time, and wants that salesman's list.
 */
class PurchaseSuggestionService
{
    public function __construct(private readonly PurchaseService $purchases) {}

    /**
     * The reorder list, grouped by last supplier. Items never bought through
     * the system come last, under no supplier.
     *
     * @param  array<int, int>|null  $productIds  limit the list to these items
     * @return Collection<int, array{supplier: Supplier|null, lines: Collection<int, array<string, mixed>>, total_paisa: int}>
     */
    public function grouped(?array $productIds = null): Collection
    {
        $products = Product::query()
            ->active()
            ->lowStock()
            ->with(['baseUnit', 'productUnits.unit'])
            ->when($productIds !== null, fn ($query) => $query->whereKey($productIds))
            ->orderBy('name')
            ->get();

        $lastSuppliers = $this->lastSuppliers($products->modelKeys());
        $suppliers = Supplier::query()->whereKey(array_unique(array_values($lastSuppliers)))->get()->keyBy('id');

        return $products
            ->map(fn (Product $product): array => $this->line($product, $lastSuppliers[$product->id] ?? null))
            ->groupBy(fn (array $line): int => (int) ($line['supplier_id'] ?? 0))
            ->map(fn (Collection $lines, int $supplierId): array => [
                'supplier' => $supplierId > 0 ? $suppliers->get($supplierId) : null,
                'lines' => $lines->values(),
                'total_paisa' => (int) $lines->sum(fn (array $line): int => $line['packs'] * $line['cost_per_pack_paisa']),
            ])
            ->sortBy(fn (array $group): string => $group['supplier'] === null ? "\u{FFFF}" : $group['supplier']->name)
            ->values();
    }

    /**
     * How many base units to order to be comfortably stocked again.
     *
     * The item's own reorder quantity when one is set, topped up by whatever
     * the shelf has gone below zero. Otherwise enough to reach twice the
     * reorder level, which is the rule of thumb a shopkeeper uses anyway.
     */
    public function needBase(Product $product): int
    {
        $stock = (int) $product->stock_qty_base;

        if ($product->reorder_qty_base > 0) {
            return $product->reorder_qty_base + max(0, -$stock);
        }

        return max(1, (2 * $product->reorder_level_base) - $stock);
    }

    /**
     * Turn part of the list into a draft purchase, ready to be checked
     * against the delivery when it arrives.
     *
     * @param  array<int, int>  $productIds
     *
     * @throws RuntimeException when none of the items still need ordering
     */
    public function startDraft(?Supplier $supplier, array $productIds, User $user): Purchase
    {
        $lines = $this->grouped($productIds)->flatMap(fn (array $group): Collection => $group['lines']);

        if ($lines->isEmpty()) {
            throw new RuntimeException(__('None of those items are low any more, so there is nothing to order.'));
        }

        return DB::transaction(function () use ($supplier, $lines, $user): Purchase {
            $purchase = Purchase::create([
                'supplier_id' => $supplier?->id,
                'status' => PurchaseStatus::Draft,
                'purchase_date' => today(),
                'user_id' => $user->getKey(),
                'note' => __('Started from the reorder list'),
            ]);

            foreach ($lines as $line) {
                $purchase->items()->create([
                    'product_id' => $line['product']->id,
                    'product_unit_id' => $line['unit']?->id,
                    'qty' => $line['packs'],
                    'bonus_qty' => 0,
                    'unit_cost_paisa' => $line['cost_per_pack_paisa'],
                ]);
            }

            return $this->purchases->refreshTotals($purchase);
        });
    }

    /**
     * @return array{product: Product, supplier_id: int|null, unit: ProductUnit|null, need_base: int, packs: int, cost_per_pack_paisa: int}
     */
    private function line(Product $product, ?int $supplierId): array
    {
        $unit = PurchaseRows::defaultPurchaseUnit($product);
        $factor = max(1, (int) ($unit?->conversion_factor ?? 1));
        $need = $this->needBase($product);

        return [
            'product' => $product,
            'supplier_id' => $supplierId,
            'unit' => $unit,
            'need_base' => $need,
            'packs' => (int) ceil($need / $factor),
            'cost_per_pack_paisa' => $unit ? (PurchaseRows::lastCostsPerPack($product)[$unit->id] ?? 0) : 0,
        ];
    }

    /**
     * Who each item was last bought from, among received purchases.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int> supplier id keyed by product id
     */
    private function lastSuppliers(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        /* Ordered oldest first, so each product's later purchases overwrite
           its earlier ones and the last one standing is the most recent. */
        return PurchaseItem::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchases.status', PurchaseStatus::Received->value)
            ->whereNotNull('purchases.supplier_id')
            ->whereIn('purchase_items.product_id', $productIds)
            ->orderBy('purchase_items.id')
            ->pluck('purchases.supplier_id', 'purchase_items.product_id')
            ->map(fn ($supplierId): int => (int) $supplierId)
            ->all();
    }
}
