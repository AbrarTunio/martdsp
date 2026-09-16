<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\StockTakeStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posting a physical count.
 *
 * The shop keeps selling through a count — nobody closes for it — so each
 * line is measured against the books as they stood at the moment that item
 * was counted, read back from the ledger. What was sold after the scan is
 * already off the books and is left alone; only the gap the scan found is
 * written. Measuring against the books at posting instead would call every
 * packet sold after being scanned a surplus.
 *
 * For items kept in batches the difference comes off, or goes back onto, the
 * layers nearest their date, the same way a sale does. A count finds out how
 * much is on the shelf, not which delivery it came from, and assuming the
 * missing stock was the oldest is both the likeliest truth and the one that
 * keeps the expiry list from looking better than it is.
 */
class StockTakeService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Settle every line against the books and write the differences.
     *
     * @throws RuntimeException when the count has already been dealt with, or
     *                          there is nothing on it to post
     */
    public function post(StockTake $take, User $poster): StockTake
    {
        return DB::transaction(function () use ($take, $poster): StockTake {
            $locked = StockTake::query()->whereKey($take->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== StockTakeStatus::Draft) {
                throw new RuntimeException(__('This count has already been dealt with.'));
            }

            $this->addWhatWasNotFound($locked);

            $items = $locked->items()->with(['productUnit'])->orderBy('id')->get();

            if ($items->isEmpty()) {
                throw new RuntimeException(__('Nothing has been counted yet. Scan at least one item before posting.'));
            }

            $varianceValue = 0;

            foreach ($items as $item) {
                $varianceValue += $this->postItem($locked, $item, $poster);
            }

            $locked->forceFill([
                'status' => StockTakeStatus::Posted,
                'posted_by' => $poster->getKey(),
                'posted_at' => now(),
                'variance_value_paisa' => $varianceValue,
            ])->save();

            return $take->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Abandon a count that is still open. Nothing it said ever reached the
     * books, so there is nothing to undo.
     *
     * @throws RuntimeException when the count has already been posted
     */
    public function cancel(StockTake $take): StockTake
    {
        if ($take->status === StockTakeStatus::Posted) {
            throw new RuntimeException(__('A posted count cannot be abandoned. Count again to correct it.'));
        }

        $take->forceFill(['status' => StockTakeStatus::Cancelled])->save();

        return $take;
    }

    /**
     * When the counter said so, everything in the section that the books say
     * is there and nobody scanned is put on the sheet as found empty — as of
     * now, since the claim is that the shelf is empty now.
     *
     * Items the books already show as none are left off: they are right, and
     * a sheet padded with "0 counted, 0 expected" hides the lines that matter.
     */
    private function addWhatWasNotFound(StockTake $take): void
    {
        if (! $take->missing_are_zero || ! $take->category_id) {
            return;
        }

        $category = Category::find($take->category_id);

        if (! $category) {
            return;
        }

        $alreadyCounted = $take->items()->pluck('product_id')->all();

        Product::query()
            ->with('productUnits')
            ->whereIn('category_id', $category->idsWithinIt())
            ->whereNotIn('id', $alreadyCounted)
            ->where('stock_qty_base', '!=', 0)
            ->orderBy('id')
            ->each(function (Product $product) use ($take): void {
                $take->items()->create([
                    'product_id' => $product->id,
                    'product_unit_id' => $product->baseProductUnit()?->id,
                    'counted_qty' => 0,
                    'counted_base' => 0,
                    'was_counted' => false,
                    'counted_at' => now(),
                ]);
            });
    }

    /**
     * One line: lock the item, read what the books said when it was counted,
     * write the gap, and return what that gap cost.
     */
    private function postItem(StockTake $take, StockTakeItem $item, User $poster): int
    {
        /* Locked first, so no sale can land between reading the books and
           writing the difference against them. */
        $product = Product::query()->whereKey($item->product_id)->lockForUpdate()->firstOrFail();

        $factor = max(1, (int) ($item->productUnit?->conversion_factor ?? 1));
        $countedBase = (int) $item->counted_qty * $factor;
        $booksWhenCounted = $this->booksAt($product, $item->counted_at ?? now());
        $variance = $countedBase - $booksWhenCounted;
        $unitCost = (int) $product->avg_cost_base_paisa;

        $item->forceFill([
            'counted_base' => $countedBase,
            'system_qty_base' => $booksWhenCounted,
            'variance_base' => $variance,
            'unit_cost_base_paisa' => $unitCost,
            'variance_value_paisa' => $variance * $unitCost,
        ])->save();

        if ($variance !== 0) {
            $this->stock->record(
                product: $product,
                qtyBase: $variance,
                type: MovementType::StockTake,
                reference: $take,
                userId: $poster->getKey(),
                note: $item->was_counted
                    ? ($item->note ?: __('Counted on :reference', ['reference' => $take->reference]))
                    : __('Not found on :reference', ['reference' => $take->reference]),
                occurredAt: now(),
            );
        }

        return (int) $item->variance_value_paisa;
    }

    /**
     * The balance the books held at a moment: today's balance with every
     * movement written since taken back off.
     *
     * Read in the order rows were written rather than by their business date,
     * because a delivery can be dated the morning it arrived and entered in
     * the afternoon — it was not on the books until it was entered.
     */
    private function booksAt(Product $product, CarbonInterface $moment): int
    {
        $movedSince = (int) StockMovement::query()
            ->where('product_id', $product->getKey())
            ->where('created_at', '>', $moment)
            ->sum('qty_base');

        return (int) $product->stock_qty_base - $movedSince;
    }
}
