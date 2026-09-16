<?php

namespace App\Services;

use App\Enums\CustomerEntryType;
use App\Enums\MovementType;
use App\Enums\RefundMethod;
use App\Enums\SaleReturnReason;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\Money;
use App\Support\Packaging;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Taking goods back from a customer.
 *
 * Three things have to agree, and that is why this is a service and not a
 * controller: the money handed back, the goods put on the shelf, and what the
 * bill says was sold in the first place. A refund worked out from the shelf
 * price rather than the bill gives back a discount the customer never paid;
 * goods put back without checking the bill let the same packet be returned
 * twice.
 *
 * The goods come back at what they cost on the day they were sold, not at
 * today's average, so a return can never move the shop's cost base — see
 * App\Enums\MovementType::revaluesStock().
 *
 * A return is posted the moment it is saved. The customer is at the counter
 * with their hand out; there is no draft to come back to.
 */
class SaleReturnService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly DrawerService $drawer,
        private readonly KhataService $khata,
    ) {}

    /**
     * Record a return against a bill and settle it.
     *
     * @param  array<int, array{sale_item_id: int, qty_base: int}>  $lines
     *
     * @throws RuntimeException when the bill cannot be returned against, when a
     *                          line asks for more than was sold, when khata credit
     *                          is asked for on a walk-in bill, or when the cash has
     *                          no open drawer to come out of
     */
    public function post(
        Sale $sale,
        array $lines,
        User $user,
        SaleReturnReason $reason,
        RefundMethod $settlement,
        ?Register $register = null,
        ?string $note = null,
    ): SaleReturn {
        if ($lines === []) {
            throw new RuntimeException(__('There is nothing on this return.'));
        }

        if ($settlement->needsCustomer() && $sale->customer_id === null) {
            throw new RuntimeException(__('This bill has no customer, so there is no khata to put the refund on. Hand the cash back instead.'));
        }

        return DB::transaction(function () use ($sale, $lines, $user, $reason, $settlement, $register, $note): SaleReturn {
            /* The drawer is locked before anything else is written, because a
               cash refund and a cash sale must not interleave on one shift. */
            $session = $settlement->isCash()
                ? $this->drawer->lockOpenAt($this->registerFor($sale, $register))
                : null;

            $locked = Sale::query()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== SaleStatus::Completed) {
                throw new RuntimeException($locked->status === SaleStatus::Void
                    ? __('That bill was cancelled, so the goods are already back on the shelf.')
                    : __('Only a completed bill can be returned against.'));
            }

            $return = SaleReturn::create([
                'sale_id' => $locked->getKey(),
                'customer_id' => $locked->customer_id,
                'register_id' => $session?->register_id ?? $register?->getKey() ?? $locked->register_id,
                'reason' => $reason,
                'settlement' => $settlement,
                'restocked' => $reason->goesBackOnTheShelf(),
                'user_id' => $user->getKey(),
                'note' => $note,
                'returned_at' => now(),
            ]);

            $totals = ['total' => 0, 'tax' => 0, 'cost' => 0];

            foreach ($lines as $line) {
                $figures = $this->postLine($return, $locked, $line, $user);

                $totals['total'] += $figures['total'];
                $totals['tax'] += $figures['tax'];
                $totals['cost'] += $figures['cost'];
            }

            $return->forceFill([
                'total_paisa' => $totals['total'],
                'tax_paisa' => $totals['tax'],
                'cost_value_paisa' => $totals['cost'],
            ])->save();

            if ($session) {
                $this->drawer->recordRefund($session, $return, $user);
            }

            if ($settlement->needsCustomer() && $return->total_paisa > 0 && $locked->customer) {
                $this->khata->record(
                    customer: $locked->customer,
                    type: CustomerEntryType::SaleReturn,
                    creditPaisa: $return->total_paisa,
                    reference: $return,
                    note: __(':reference against :invoice', [
                        'reference' => $return->reference,
                        'invoice' => $locked->invoiceNumber(),
                    ]),
                    userId: $user->getKey(),
                );
            }

            return $return;
        });
    }

    /**
     * One line: check the bill still has that much left on it, write the item
     * and, when the goods are fit to sell, put them back on the shelf.
     *
     * @param  array{sale_item_id: int, qty_base: int}  $line
     * @return array{total: int, tax: int, cost: int}
     *
     * @throws RuntimeException when the line is not on the bill, is empty, or asks
     *                          for more than is left to come back
     */
    private function postLine(SaleReturn $return, Sale $sale, array $line, User $user): array
    {
        /* Locked so that two counters cannot each refund the last packet of
           the same line at the same moment. */
        $item = SaleItem::query()
            ->whereKey($line['sale_item_id'])
            ->where('sale_id', $sale->getKey())
            ->lockForUpdate()
            ->first();

        if (! $item) {
            throw new RuntimeException(__('That line is not on :invoice.', ['invoice' => $sale->invoiceNumber()]));
        }

        $qtyBase = (int) $line['qty_base'];

        if ($qtyBase <= 0) {
            throw new RuntimeException(__(':name: enter how many are coming back.', ['name' => $item->name]));
        }

        $available = $item->returnableQtyBase();

        if ($qtyBase > $available) {
            throw new RuntimeException(__(':name: only :have of what was sold is still to come back — the rest is on an earlier return.', [
                'name' => $item->name,
                'have' => $this->describeQuantity($item, $available),
            ]));
        }

        $figures = $this->lineFigures($item, $qtyBase);

        $return->items()->create([
            'sale_item_id' => $item->getKey(),
            'product_id' => $item->product_id,
            'product_unit_id' => $item->product_unit_id,
            'qty' => $this->qtyInSoldUnits($item, $qtyBase),
            'qty_base' => $qtyBase,
            'unit_refund_paisa' => $item->netUnitPricePaisa(),
            'tax_paisa' => $figures['tax'],
            'line_total_paisa' => $figures['total'],
            'cost_base_paisa' => (int) $item->cost_at_sale_base_paisa,
        ]);

        /* Expired or broken goods are refunded but never restocked. Writing
           them back in would only surface as a shortage at the next shelf
           count, long after anybody remembers why. */
        if ($return->restocked && $item->product_id) {
            $this->stock->record(
                product: Product::query()->findOrFail($item->product_id),
                qtyBase: $qtyBase,
                type: MovementType::SaleReturn,
                unitCostPaisa: (int) $item->cost_at_sale_base_paisa,
                productUnit: $item->productUnit,
                reference: $return,
                userId: $user->getKey(),
                note: __(':reference, :reason', [
                    'reference' => $return->reference,
                    'reason' => __($return->reason->label()),
                ]),
                occurredAt: $return->returned_at,
            );
        }

        return $figures;
    }

    /**
     * What one line hands back.
     *
     * Returning a whole line hands back exactly what the line earned, to the
     * paisa. Part of a line is shared out in proportion, so returning a line
     * in pieces can never add up to more — or less — than returning it at
     * once.
     *
     * @return array{total: int, tax: int, cost: int}
     */
    private function lineFigures(SaleItem $item, int $qtyBase): array
    {
        $soldBase = max(1, (int) $item->qty_base);
        $whole = $qtyBase >= (int) $item->qty_base;

        return [
            'total' => $whole
                ? (int) $item->line_total_paisa
                : intdiv((int) $item->line_total_paisa * $qtyBase, $soldBase),
            'tax' => $whole
                ? (int) $item->tax_paisa
                : intdiv((int) $item->tax_paisa * $qtyBase, $soldBase),
            'cost' => $qtyBase * (int) $item->cost_at_sale_base_paisa,
        ];
    }

    /**
     * The quantity written on the return in the size the bill used, so that
     * one packet off a two-packet line reads as "1", not "24".
     */
    private function qtyInSoldUnits(SaleItem $item, int $qtyBase): string
    {
        $factor = max(1, (int) ($item->productUnit?->conversion_factor ?? 1));

        return number_format($qtyBase / $factor, 3, '.', '');
    }

    /**
     * A quantity said the way the shop says it — "2 packets (24 sachets)".
     */
    private function describeQuantity(SaleItem $item, int $qtyBase): string
    {
        $product = $item->product;

        if (! $product) {
            return (string) $qtyBase;
        }

        return Packaging::describe($qtyBase, $product->loadMissing('productUnits')->productUnits);
    }

    /**
     * Whose drawer the cash comes out of: the counter the cashier is standing
     * at, or the one the bill was rung up on when nothing else is offered.
     *
     * @throws RuntimeException when there is no counter to take it from
     */
    private function registerFor(Sale $sale, ?Register $register): Register
    {
        $register ??= $sale->register;

        if (! $register) {
            throw new RuntimeException(__('Say which counter the cash is coming out of.'));
        }

        return $register;
    }

    /**
     * A one-line summary of what a return gave back, for the flash message
     * the cashier reads before handing the money over.
     */
    public function describe(SaleReturn $return): string
    {
        return __(':reference is recorded. :amount :how.', [
            'reference' => $return->reference,
            'amount' => Money::withSymbol($return->total_paisa),
            'how' => $return->settlement->isCash()
                ? __('is handed back in cash')
                : __('comes off what they owe'),
        ]);
    }
}
