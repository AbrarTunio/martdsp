<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\StockBatch;
use Illuminate\Support\Collection;

/**
 * Keeping the layers in step with the shelf.
 *
 * The stock ledger already says how much of a product there is. This says how
 * that amount is made up for the goods where age matters — which delivery,
 * under which expiry date — so the shopkeeper can be told what to push to the
 * front before it goes off.
 *
 * Stock leaves first-expiry-first-out. That is not an accounting choice; it is
 * what anyone facing up a shelf does by hand, and doing it in the books too is
 * what keeps the expiring-stock list honest.
 *
 * Two rules keep this from ever blocking the till:
 *
 * - Layers are only kept for products flagged for batches or expiry. For
 *   everything else there is nothing to keep apart.
 * - When the layers do not cover what is leaving — stock that was already on
 *   the shelf when tracking was switched on, say — what can be taken is taken
 *   and the rest is let go. A sale is never refused because the paperwork is
 *   behind; the balance the customer is charged against is the product's, not
 *   the layer's.
 */
class BatchService
{
    /**
     * Whether this product's stock is kept in layers at all.
     */
    public function tracks(Product $product): bool
    {
        return (bool) $product->track_batches || (bool) $product->track_expiry;
    }

    /**
     * Open a layer for goods that have just been taken in.
     *
     * A second delivery of the same batch number and expiry date joins the
     * layer that is already open rather than starting another, so the shelf
     * reads the way it looks.
     */
    public function receive(
        Product $product,
        int $qtyBase,
        int $costBasePaisa,
        ?string $batchNo = null,
        mixed $expiryDate = null,
        ?PurchaseItem $purchaseItem = null,
        mixed $occurredAt = null,
    ): ?StockBatch {
        if (! $this->tracks($product) || $qtyBase <= 0) {
            return null;
        }

        $batchNo = $this->tidy($batchNo);
        $expiry = $expiryDate ? today()->parse($expiryDate)->toDateString() : null;

        $existing = StockBatch::query()
            ->where('product_id', $product->getKey())
            ->where('batch_no', $batchNo)
            ->when($expiry === null,
                fn ($query) => $query->whereNull('expiry_date'),
                fn ($query) => $query->whereDate('expiry_date', $expiry),
            )
            ->lockForUpdate()
            ->first();

        if ($existing) {
            $existing->forceFill([
                'received_base' => $existing->received_base + $qtyBase,
                'qty_base' => $existing->qty_base + $qtyBase,
                'cost_base_paisa' => $costBasePaisa,
            ])->save();

            return $existing;
        }

        return StockBatch::create([
            'product_id' => $product->getKey(),
            'purchase_item_id' => $purchaseItem?->getKey(),
            'batch_no' => $batchNo,
            'expiry_date' => $expiry,
            'received_base' => $qtyBase,
            'qty_base' => $qtyBase,
            'cost_base_paisa' => $costBasePaisa,
            'received_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * Take stock off the layers, soonest to expire first.
     *
     * @return int what was actually taken; less than asked for when the
     *             layers do not cover it
     */
    public function consume(Product $product, int $qtyBase): int
    {
        if (! $this->tracks($product) || $qtyBase <= 0) {
            return 0;
        }

        $remaining = $qtyBase;

        /** @var Collection<int, StockBatch> $batches */
        $batches = StockBatch::query()
            ->where('product_id', $product->getKey())
            ->open()
            ->soonestFirst()
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $taken = min($remaining, $batch->qty_base);

            $batch->forceFill(['qty_base' => $batch->qty_base - $taken])->save();

            $remaining -= $taken;
        }

        return $qtyBase - $remaining;
    }

    /**
     * Put stock back on the layers.
     *
     * Nothing on a bill says which layer the customer's packet came off, so
     * the guess has to be made. It goes back on the layer that is nearest its
     * date, because under first-expiry-first-out that is the one it most
     * likely came from, and because putting it anywhere later would quietly
     * make the expiring-stock list look better than the shelf is.
     *
     * A layer that has been sold out is still a candidate — a packet coming
     * back an hour after it was the last one off the shelf belongs exactly
     * there. A layer that is already past its date is not: putting sellable
     * goods on it would put them on the binning list.
     *
     * When there is nowhere sensible to put it — no layer yet, or every one
     * of them expired — a fresh layer carrying no date is opened, so the
     * total on the layers still adds up to the total on the shelf.
     *
     * @return int what was actually put back
     */
    public function restore(Product $product, int $qtyBase, int $costBasePaisa = 0): int
    {
        if (! $this->tracks($product) || $qtyBase <= 0) {
            return 0;
        }

        $batch = StockBatch::query()
            ->where('product_id', $product->getKey())
            ->stillSellable()
            ->soonestFirst()
            ->lockForUpdate()
            ->first();

        if (! $batch) {
            $this->receive(
                product: $product,
                qtyBase: $qtyBase,
                costBasePaisa: $costBasePaisa ?: (int) $product->avg_cost_base_paisa,
            );

            return $qtyBase;
        }

        $batch->forceFill(['qty_base' => $batch->qty_base + $qtyBase])->save();

        return $qtyBase;
    }

    /**
     * What the layers of one product add up to. Compared against the
     * product's own balance, the gap is stock that was on the shelf before
     * tracking was switched on.
     */
    public function onLayers(Product $product): int
    {
        return (int) StockBatch::query()
            ->where('product_id', $product->getKey())
            ->sum('qty_base');
    }

    /**
     * Stock that was there before the layers were, which no batch can
     * account for. Nothing for a product that is not tracked at all, since
     * there is nothing it was meant to account for. Never less than nothing:
     * the layers are topped up on a return, so they can catch up but not
     * overshoot.
     */
    public function untrackedBase(Product $product): int
    {
        if (! $this->tracks($product)) {
            return 0;
        }

        return max(0, (int) $product->stock_qty_base - $this->onLayers($product));
    }

    /**
     * A batch number as it should be stored: trimmed, upper case, or nothing
     * at all. "b12", "B12 " and "B12" are one batch, not three.
     */
    private function tidy(?string $batchNo): ?string
    {
        $batchNo = strtoupper(trim((string) $batchNo));

        return $batchNo === '' ? null : $batchNo;
    }
}
