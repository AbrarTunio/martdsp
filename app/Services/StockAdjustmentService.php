<?php

namespace App\Services;

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentStatus;
use App\Enums\MovementType;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posting a stock correction.
 *
 * Nothing an adjustment says reaches the ledger until it is posted, and the
 * quantities are worked out at that moment rather than when the line was
 * typed — a recount taken an hour ago must be measured against the shelf as
 * it stands now, or a sale rung up in between would be counted twice.
 *
 * Posting is a one-way door. A mistake is corrected with another adjustment,
 * never by editing this one, because a ledger that can be rewritten proves
 * nothing.
 */
class StockAdjustmentService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Write every line of an adjustment to the stock ledger.
     *
     * @throws RuntimeException when the adjustment has already been posted or
     *                          has nothing on it
     */
    public function post(StockAdjustment $adjustment, ?User $approver = null): StockAdjustment
    {
        if ($adjustment->status !== AdjustmentStatus::Draft) {
            throw new RuntimeException('This adjustment has already been dealt with.');
        }

        if (! $adjustment->items()->exists()) {
            throw new RuntimeException('There is nothing on this adjustment to post.');
        }

        return DB::transaction(function () use ($adjustment, $approver): StockAdjustment {
            $value = 0;

            $items = $adjustment->items()->with(['product.productUnits', 'productUnit'])->get();

            foreach ($items as $item) {
                $value += $this->postItem($adjustment, $item);
            }

            $adjustment->forceFill([
                'status' => AdjustmentStatus::Posted,
                'approved_by' => $approver?->getKey() ?? $adjustment->approved_by,
                'value_paisa' => $value,
                'posted_at' => now(),
            ])->save();

            return $adjustment;
        });
    }

    /**
     * Abandon a draft. Posted adjustments are untouchable by design.
     *
     * @throws RuntimeException when the adjustment has already been posted
     */
    public function cancel(StockAdjustment $adjustment): StockAdjustment
    {
        if ($adjustment->status === AdjustmentStatus::Posted) {
            throw new RuntimeException('A posted adjustment cannot be cancelled. Correct it with another one.');
        }

        $adjustment->forceFill(['status' => AdjustmentStatus::Cancelled])->save();

        return $adjustment;
    }

    /**
     * One line: settle its quantity against the shelf as it stands, write the
     * movement, and return the line's signed value.
     */
    private function postItem(StockAdjustment $adjustment, StockAdjustmentItem $item): int
    {
        $product = $item->product;
        $factor = max(1, (int) ($item->productUnit?->conversion_factor ?? 1));
        $enteredBase = (int) $item->qty * $factor;

        $balanceNow = (int) $product->fresh()->stock_qty_base;
        $reason = $adjustment->reason;

        if ($reason->isRecount()) {
            $qtyBase = $enteredBase - $balanceNow;
        } elseif ($reason->addsStock()) {
            $qtyBase = $enteredBase;
        } else {
            $qtyBase = -$enteredBase;
        }

        /* Opening stock carries its own cost, because there is no earlier
           delivery for the average to come from. Everything else moves at
           what the shop already believes the goods cost. */
        $unitCost = $reason->addsStock() && $item->unit_cost_base_paisa > 0
            ? (int) $item->unit_cost_base_paisa
            : (int) $product->avg_cost_base_paisa;

        $item->forceFill([
            'qty_base' => $qtyBase,
            'system_qty_base' => $balanceNow,
            'unit_cost_base_paisa' => $unitCost,
            'value_paisa' => $qtyBase * $unitCost,
        ])->save();

        if ($qtyBase !== 0) {
            $this->stock->record(
                product: $product,
                qtyBase: $qtyBase,
                type: $this->movementTypeFor($reason),
                unitCostPaisa: $qtyBase > 0 ? $unitCost : null,
                productUnit: $item->productUnit,
                reference: $adjustment,
                userId: $adjustment->user_id,
                note: $item->note ?? $reason->label(),
                occurredAt: $adjustment->adjusted_at,
            );
        }

        return (int) $item->value_paisa;
    }

    /**
     * The ledger reads better when the shop's starting position is called
     * what it is. A report separating "what we began with" from "what we have
     * corrected since" needs the two to be distinguishable.
     */
    private function movementTypeFor(AdjustmentReason $reason): MovementType
    {
        return match (true) {
            $reason->isRecount() => MovementType::StockTake,
            $reason === AdjustmentReason::Opening => MovementType::Opening,
            default => MovementType::Adjustment,
        };
    }
}
