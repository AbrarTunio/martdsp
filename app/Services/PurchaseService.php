<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierEntryType;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Support\Allocation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Receiving a delivery.
 *
 * Receiving is the one moment three ledgers move together: stock goes up,
 * the moving average cost takes in what the goods really cost, and the
 * supplier is owed the bill less whatever was paid on the spot. It happens in
 * one transaction or not at all.
 *
 * "What the goods really cost" is the bill's line price with the bill's
 * discount and tax shared out over the lines by value, divided over every
 * piece that arrived — the free ones on a trade scheme included. GST on a
 * purchase is treated as part of the cost, because a shop that is not
 * claiming input tax back has simply paid more for its stock.
 */
class PurchaseService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly SupplierLedgerService $ledger,
        private readonly BatchService $batches,
    ) {}

    /**
     * Work a draft's figures out from its lines. Called whenever a draft is
     * saved, so the list and the bill agree, and again on receiving.
     */
    public function refreshTotals(Purchase $purchase): Purchase
    {
        $subtotal = 0;

        foreach ($purchase->items()->with('productUnit')->get() as $item) {
            $item->forceFill($this->lineFigures($item))->save();
            $subtotal += (int) $item->line_total_paisa;
        }

        $discount = min((int) $purchase->discount_paisa, $subtotal);

        $purchase->forceFill([
            'subtotal_paisa' => $subtotal,
            'discount_paisa' => $discount,
            'total_paisa' => $subtotal - $discount + (int) $purchase->tax_paisa,
        ])->save();

        return $purchase;
    }

    /**
     * Post a draft to stock, cost and the supplier's balance.
     *
     * @throws RuntimeException when the purchase is not a draft, is empty, or
     *                          its payment does not fit its bill
     */
    public function receive(Purchase $purchase, User $receiver): Purchase
    {
        DB::transaction(function () use ($purchase, $receiver): void {
            $locked = Purchase::query()->whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PurchaseStatus::Draft) {
                throw new RuntimeException(__('This purchase has already been dealt with.'));
            }

            if (! $locked->items()->exists()) {
                throw new RuntimeException(__('There is nothing on this purchase to receive.'));
            }

            $this->refreshTotals($locked);
            $this->refuseAPaymentThatDoesNotFit($locked);

            /** @var Collection<int, PurchaseItem> $items */
            $items = $locked->items()->with(['product', 'productUnit'])->orderBy('id')->get();

            $occurredAt = $this->occurredAt($locked);

            foreach ($this->landedCosts($locked, $items) as $itemId => $landedPaisa) {
                $this->receiveItem($locked, $items->firstWhere('id', $itemId), $landedPaisa, $receiver, $occurredAt);
            }

            $locked->forceFill([
                'status' => PurchaseStatus::Received,
                'due_on' => $locked->supplier
                    ? $locked->purchase_date->copy()->addDays((int) $locked->supplier->payment_terms_days)
                    : null,
                'received_at' => now(),
                'received_by' => $receiver->getKey(),
            ])->save();

            $this->postToSupplier($locked, $receiver);
        });

        return $purchase->refresh();
    }

    /**
     * Abandon a draft. A received purchase is part of three ledgers and is
     * corrected with a return or an adjustment, never removed.
     *
     * @throws RuntimeException when the purchase has already been received
     */
    public function cancel(Purchase $purchase): Purchase
    {
        if ($purchase->status === PurchaseStatus::Received) {
            throw new RuntimeException(__('A received purchase cannot be cancelled. Send the goods back with a return instead.'));
        }

        $purchase->forceFill(['status' => PurchaseStatus::Cancelled])->save();

        return $purchase;
    }

    /**
     * What one line comes to, before the bill's discount and tax.
     *
     * @return array{qty_base: int, line_total_paisa: int}
     */
    private function lineFigures(PurchaseItem $item): array
    {
        return [
            'qty_base' => ((int) $item->qty + (int) $item->bonus_qty) * $item->factor(),
            'line_total_paisa' => (int) $item->qty * (int) $item->unit_cost_paisa,
        ];
    }

    /**
     * Each line's share of the bill: its own total, less its share of the
     * discount, plus its share of the tax. Tax is shared on what is left
     * after the discount, because that is what it was charged on.
     *
     * @param  Collection<int, PurchaseItem>  $items
     * @return array<int, int> landed paisa, keyed by item id
     */
    private function landedCosts(Purchase $purchase, Collection $items): array
    {
        $lineTotals = $items->mapWithKeys(fn (PurchaseItem $item): array => [
            $item->id => (int) $item->line_total_paisa,
        ])->all();

        $discounts = Allocation::spread((int) $purchase->discount_paisa, $lineTotals);

        $afterDiscount = [];

        foreach ($lineTotals as $id => $lineTotal) {
            $afterDiscount[$id] = $lineTotal - $discounts[$id];
        }

        $taxes = Allocation::spread((int) $purchase->tax_paisa, $afterDiscount);

        $landed = [];

        foreach ($afterDiscount as $id => $amount) {
            $landed[$id] = $amount + $taxes[$id];
        }

        return $landed;
    }

    private function receiveItem(
        Purchase $purchase,
        PurchaseItem $item,
        int $landedPaisa,
        User $receiver,
        CarbonInterface $occurredAt,
    ): void {
        $qtyBase = (int) $item->qty_base;

        $costBase = $qtyBase > 0 ? (int) round($landedPaisa / $qtyBase) : 0;

        $item->forceFill(['cost_base_paisa' => $costBase])->save();

        if ($qtyBase === 0) {
            return;
        }

        $this->stock->record(
            product: $item->product,
            qtyBase: $qtyBase,
            type: MovementType::Purchase,
            unitCostPaisa: $costBase,
            productUnit: $item->productUnit,
            reference: $purchase,
            userId: $receiver->getKey(),
            note: $purchase->invoice_no ? __('Bill :number', ['number' => $purchase->invoice_no]) : null,
            occurredAt: $occurredAt,
        );

        /* The delivery is the only place the batch number and the expiry date
           on the carton are known, so this is where the layer is opened. Stock
           going out later comes off these layers by date, not by delivery. */
        $this->batches->receive(
            product: $item->product,
            qtyBase: $qtyBase,
            costBasePaisa: $costBase,
            batchNo: $item->batch_no,
            expiryDate: $item->expiry_date,
            purchaseItem: $item,
            occurredAt: $occurredAt,
        );
    }

    /**
     * The bill goes on the supplier's account in full, and anything paid on
     * delivery comes straight off it. Two lines rather than one net figure,
     * so the statement reads the way the paper bills do.
     */
    private function postToSupplier(Purchase $purchase, User $receiver): void
    {
        $supplier = $purchase->supplier;

        if (! $supplier) {
            return;
        }

        if ($purchase->total_paisa > 0) {
            $this->ledger->record(
                supplier: $supplier,
                type: SupplierEntryType::Purchase,
                creditPaisa: (int) $purchase->total_paisa,
                reference: $purchase,
                note: $purchase->invoice_no
                    ? __(':reference, bill :number', ['reference' => $purchase->reference, 'number' => $purchase->invoice_no])
                    : $purchase->reference,
                entryDate: $purchase->purchase_date,
                userId: $receiver->getKey(),
            );
        }

        if ($purchase->paid_paisa > 0) {
            $this->ledger->record(
                supplier: $supplier,
                type: SupplierEntryType::Payment,
                debitPaisa: (int) $purchase->paid_paisa,
                method: $purchase->payment_method,
                reference: $purchase,
                note: __('Paid on delivery, :reference', ['reference' => $purchase->reference]),
                entryDate: $purchase->purchase_date,
                userId: $receiver->getKey(),
            );
        }
    }

    /**
     * @throws RuntimeException
     */
    private function refuseAPaymentThatDoesNotFit(Purchase $purchase): void
    {
        if ($purchase->paid_paisa > $purchase->total_paisa) {
            throw new RuntimeException(__('More was paid than this bill comes to. Record the extra as a payment to the supplier instead.'));
        }

        if ($purchase->isCashPurchase() && $purchase->paid_paisa !== $purchase->total_paisa) {
            throw new RuntimeException(__('A purchase with no supplier has nobody to owe, so it must be paid in full.'));
        }
    }

    /**
     * When the goods arrived, for the stock ledger's dates. A delivery
     * entered the next morning is dated the day on the bill; the ledger's
     * arithmetic follows the order the rows were written in regardless.
     */
    private function occurredAt(Purchase $purchase): CarbonInterface
    {
        return $purchase->purchase_date->isToday()
            ? now()
            : $purchase->purchase_date->copy()->setTimeFrom(now());
    }
}
