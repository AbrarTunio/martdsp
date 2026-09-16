<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only thing in the application allowed to change a stock balance.
 *
 * Every sale, purchase, return and correction comes through `record()`, which
 * appends one ledger row and updates the cached balance inside the same
 * transaction, with the product row locked. Nothing else writes
 * `products.stock_qty_base`, which is what makes `rebuild()` able to prove the
 * cache and the ledger agree.
 *
 * Cost is a moving weighted average, in paisa per base unit. Stock going out
 * never changes it: what a sale costs the shop was settled when the goods
 * arrived, not when they left.
 */
class StockService
{
    public function __construct(private readonly BatchService $batches) {}

    /**
     * Append one movement and settle the balance.
     *
     * @param  int  $qtyBase  signed, in base units — positive arrived, negative left
     * @param  int|null  $unitCostPaisa  cost of one base unit; null keeps the current average
     *
     * @throws RuntimeException when the quantity is zero, which would leave an
     *                          unreadable row explaining nothing
     */
    public function record(
        Product $product,
        int $qtyBase,
        MovementType $type,
        ?int $unitCostPaisa = null,
        ?ProductUnit $productUnit = null,
        ?Model $reference = null,
        ?int $userId = null,
        ?string $note = null,
        mixed $occurredAt = null,
    ): StockMovement {
        if ($qtyBase === 0) {
            throw new RuntimeException('A stock movement of nothing cannot be recorded.');
        }

        return DB::transaction(function () use (
            $product, $qtyBase, $type, $unitCostPaisa, $productUnit, $reference, $userId, $note, $occurredAt
        ): StockMovement {
            /* Locked for the length of the transaction: two counters selling
               the last packet at the same moment must not both succeed. */
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            $balanceBefore = (int) $locked->stock_qty_base;
            $averageBefore = (int) $locked->avg_cost_base_paisa;

            $balanceAfter = $balanceBefore + $qtyBase;
            $unitCost = $unitCostPaisa ?? $averageBefore;

            $averageAfter = $this->averageAfter(
                $balanceBefore,
                $averageBefore,
                $qtyBase,
                $unitCostPaisa,
                $type,
            );

            $movement = $locked->movements()->create([
                'product_unit_id' => $productUnit?->getKey(),
                'qty_base' => $qtyBase,
                'type' => $type,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'unit_cost_base_paisa' => max(0, $unitCost),
                'avg_cost_after_paisa' => $averageAfter,
                'balance_after_base' => $balanceAfter,
                'user_id' => $userId ?? auth()->id(),
                'note' => $note,
                'occurred_at' => $occurredAt ?? now(),
            ]);

            $locked->forceFill([
                'stock_qty_base' => $balanceAfter,
                'avg_cost_base_paisa' => $averageAfter,
            ])->save();

            $this->followOnTheLayers($locked, $qtyBase, $type, $unitCost);

            /* The caller's copy is almost always rendered straight afterwards. */
            $product->forceFill([
                'stock_qty_base' => $balanceAfter,
                'avg_cost_base_paisa' => $averageAfter,
            ])->syncOriginalAttributes(['stock_qty_base', 'avg_cost_base_paisa']);

            return $movement;
        });
    }

    /**
     * Stock arriving, in the packaging it arrived in.
     */
    public function receive(
        Product $product,
        int $qty,
        ProductUnit $productUnit,
        MovementType $type,
        ?int $unitCostPaisa = null,
        ?Model $reference = null,
        ?string $note = null,
        mixed $occurredAt = null,
    ): StockMovement {
        return $this->record(
            product: $product,
            qtyBase: abs($qty) * max(1, (int) $productUnit->conversion_factor),
            type: $type,
            unitCostPaisa: $unitCostPaisa,
            productUnit: $productUnit,
            reference: $reference,
            note: $note,
            occurredAt: $occurredAt,
        );
    }

    /**
     * Stock leaving, in the packaging it left in.
     */
    public function issue(
        Product $product,
        int $qty,
        ProductUnit $productUnit,
        MovementType $type,
        ?Model $reference = null,
        ?string $note = null,
        mixed $occurredAt = null,
    ): StockMovement {
        return $this->record(
            product: $product,
            qtyBase: -abs($qty) * max(1, (int) $productUnit->conversion_factor),
            type: $type,
            productUnit: $productUnit,
            reference: $reference,
            note: $note,
            occurredAt: $occurredAt,
        );
    }

    /**
     * Set the balance to a counted figure, writing only the difference.
     *
     * Returns null when the count already matches, because a ledger row
     * saying "nothing changed" is noise in the one place that must stay
     * readable.
     */
    public function countTo(
        Product $product,
        int $countedBase,
        MovementType $type = MovementType::StockTake,
        ?Model $reference = null,
        ?string $note = null,
        mixed $occurredAt = null,
    ): ?StockMovement {
        $variance = $countedBase - (int) $product->fresh()->stock_qty_base;

        if ($variance === 0) {
            return null;
        }

        return $this->record(
            product: $product,
            qtyBase: $variance,
            type: $type,
            reference: $reference,
            note: $note,
            occurredAt: $occurredAt,
        );
    }

    /**
     * Replay a product's whole ledger and write the balances back.
     *
     * This is both the repair and the proof: if the cached balance was right,
     * nothing changes. Used by `stock:recalculate`.
     *
     * @return array{balance_before: int, balance_after: int, average_before: int, average_after: int, movements: int, drifted: bool}
     */
    public function rebuild(Product $product): array
    {
        return DB::transaction(function () use ($product): array {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            $balanceBefore = (int) $locked->stock_qty_base;
            $averageBefore = (int) $locked->avg_cost_base_paisa;

            $balance = 0;
            $average = 0;
            $counted = 0;

            $locked->movements()->inLedgerOrder()->chunkById(500, function ($movements) use (&$balance, &$average, &$counted): void {
                foreach ($movements as $movement) {
                    $qty = (int) $movement->qty_base;

                    $average = $this->averageAfter(
                        $balance,
                        $average,
                        $qty,
                        $movement->type->revaluesStock() && $qty > 0 ? (int) $movement->unit_cost_base_paisa : null,
                        $movement->type,
                    );

                    $balance += $qty;
                    $counted++;

                    /* Written back so a row that was mis-stamped by an older
                       bug stops lying about what the shelf held. */
                    if ((int) $movement->balance_after_base !== $balance || (int) $movement->avg_cost_after_paisa !== $average) {
                        $movement->forceFill([
                            'balance_after_base' => $balance,
                            'avg_cost_after_paisa' => $average,
                        ])->save();
                    }
                }
            }, 'id');

            $locked->forceFill([
                'stock_qty_base' => $balance,
                'avg_cost_base_paisa' => $average,
            ])->save();

            return [
                'balance_before' => $balanceBefore,
                'balance_after' => $balance,
                'average_before' => $averageBefore,
                'average_after' => $average,
                'movements' => $counted,
                'drifted' => $balanceBefore !== $balance || $averageBefore !== $average,
            ];
        });
    }

    /**
     * Keep the batch layers in step with the movement just written.
     *
     * A delivery is left alone: only the purchase knows the batch number and
     * the expiry date on the carton, so it opens its own layer. Everything
     * else is a quantity with no paperwork attached — stock going out comes
     * off the layers nearest their date, stock coming back goes onto them.
     */
    private function followOnTheLayers(Product $product, int $qtyBase, MovementType $type, int $unitCostPaisa): void
    {
        if (! $this->batches->tracks($product) || $type === MovementType::Purchase) {
            return;
        }

        if ($qtyBase < 0) {
            $this->batches->consume($product, abs($qtyBase));

            return;
        }

        $this->batches->restore($product, $qtyBase, $unitCostPaisa);
    }

    /**
     * The moving weighted average after a movement.
     *
     * Only stock arriving at a stated cost moves the average, and only while
     * the balance it is averaging against is positive: averaging into a
     * negative balance produces a cost with no meaning, so the arriving cost
     * simply becomes the new average.
     */
    private function averageAfter(
        int $balanceBefore,
        int $averageBefore,
        int $qtyBase,
        ?int $unitCostPaisa,
        MovementType $type,
    ): int {
        if ($qtyBase <= 0 || $unitCostPaisa === null || ! $type->revaluesStock()) {
            return $averageBefore;
        }

        if ($balanceBefore <= 0) {
            return max(0, $unitCostPaisa);
        }

        $value = ($balanceBefore * $averageBefore) + ($qtyBase * max(0, $unitCostPaisa));

        return intdiv($value + intdiv($balanceBefore + $qtyBase, 2), $balanceBefore + $qtyBase);
    }
}
