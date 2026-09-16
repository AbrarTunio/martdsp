<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PaymentMethod;
use App\Enums\ReturnSettlement;
use App\Enums\SupplierEntryType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseReturn;
use App\Models\User;
use App\Support\Packaging;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sending goods back to a supplier.
 *
 * The goods leave the shelf at what the shop already believed they cost —
 * the moving average — so the average of what stays behind does not move.
 * What the supplier gives back for them is a separate question: the gap
 * between the two is a loss (or, rarely, a gain) on the return, and it is
 * recorded rather than buried in the cost of everything else.
 *
 * A return is posted the moment it is saved. There is no draft: it is a few
 * packets handed to a salesman, and nothing to come back to later.
 */
class PurchaseReturnService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly SupplierLedgerService $ledger,
    ) {}

    /**
     * Record a return and post it to stock and the supplier's account.
     *
     * @param  array{supplier_id: int|null, purchase_id: int|null, reason: mixed, settlement: ReturnSettlement, note: string|null, returned_at: mixed}  $attributes
     * @param  array<int, array{product_id: int, product_unit_id: int|null, qty: int, unit_credit_paisa: int}>  $items
     *
     * @throws RuntimeException when there is nothing to return, when goods with
     *                          no supplier are returned for credit, or when a line
     *                          asks for more than is on the shelf
     */
    public function post(array $attributes, array $items, User $user): PurchaseReturn
    {
        if ($items === []) {
            throw new RuntimeException(__('There is nothing on this return.'));
        }

        if ($attributes['supplier_id'] === null && $attributes['settlement'] !== ReturnSettlement::Cash) {
            throw new RuntimeException(__('Goods bought with no supplier can only be returned for cash — there is no account to take it off.'));
        }

        return DB::transaction(function () use ($attributes, $items, $user): PurchaseReturn {
            $return = PurchaseReturn::create($attributes + ['user_id' => $user->getKey()]);

            $total = 0;
            $costValue = 0;

            foreach ($items as $line) {
                [$lineTotal, $lineCost] = $this->postLine($return, $line, $user);

                $total += $lineTotal;
                $costValue += $lineCost;
            }

            $return->forceFill([
                'total_paisa' => $total,
                'cost_value_paisa' => $costValue,
            ])->save();

            $this->postToSupplier($return, $user);

            return $return;
        });
    }

    /**
     * One line: check the shelf holds it, write the item and the movement,
     * and return what the supplier gives back and what the goods cost.
     *
     * For a product kept in batches the goods come off the layers nearest
     * their date, the same way a sale does. That is almost always what is
     * happening anyway — what goes back to a supplier is the short-dated
     * stock — and picking the layer by hand would be one more question to
     * answer at a counter where nobody is looking at batch numbers.
     *
     * @param  array{product_id: int, product_unit_id: int|null, qty: int, unit_credit_paisa: int}  $line
     * @return array{0: int, 1: int}
     *
     * @throws RuntimeException
     */
    private function postLine(PurchaseReturn $return, array $line, User $user): array
    {
        /* Locked before the shelf is checked, so a sale at the counter cannot
           take the last packet between the check and the movement. */
        $product = Product::query()->whereKey($line['product_id'])->lockForUpdate()->firstOrFail();
        $product->load(['productUnits.unit', 'baseUnit']);

        $productUnit = $line['product_unit_id'] ? ProductUnit::find($line['product_unit_id']) : null;
        $qtyBase = (int) $line['qty'] * max(1, (int) ($productUnit?->conversion_factor ?? 1));

        if ($qtyBase > (int) $product->stock_qty_base) {
            throw new RuntimeException(__(':name: the shelf only holds :have, so :want cannot go back. Recount it first if that is wrong.', [
                'name' => $product->name,
                'have' => Packaging::describe(max(0, (int) $product->stock_qty_base), $product->productUnits),
                'want' => Packaging::describe($qtyBase, $product->productUnits),
            ]));
        }

        $costBase = (int) $product->avg_cost_base_paisa;
        $lineTotal = (int) $line['qty'] * (int) $line['unit_credit_paisa'];

        $return->items()->create([
            'product_id' => $product->id,
            'product_unit_id' => $productUnit?->id,
            'qty' => (int) $line['qty'],
            'qty_base' => $qtyBase,
            'unit_credit_paisa' => (int) $line['unit_credit_paisa'],
            'line_total_paisa' => $lineTotal,
            'cost_base_paisa' => $costBase,
        ]);

        $this->stock->record(
            product: $product,
            qtyBase: -$qtyBase,
            type: MovementType::PurchaseReturn,
            productUnit: $productUnit,
            reference: $return,
            userId: $user->getKey(),
            note: $return->reason->label(),
            occurredAt: $return->returned_at,
        );

        return [$lineTotal, $qtyBase * $costBase];
    }

    /**
     * Goods going back always come off what is owed. When the supplier paid
     * cash for them instead, a second line puts it back — so the statement
     * shows both what happened and that it was settled.
     */
    private function postToSupplier(PurchaseReturn $return, User $user): void
    {
        $supplier = $return->supplier;

        if (! $supplier || $return->total_paisa === 0) {
            return;
        }

        $this->ledger->record(
            supplier: $supplier,
            type: SupplierEntryType::Return,
            debitPaisa: $return->total_paisa,
            reference: $return,
            note: __(':reference, :reason', ['reference' => $return->reference, 'reason' => $return->reason->label()]),
            entryDate: $return->returned_at->toDateString(),
            userId: $user->getKey(),
        );

        if ($return->settlement === ReturnSettlement::Cash) {
            $this->ledger->record(
                supplier: $supplier,
                type: SupplierEntryType::Refund,
                creditPaisa: $return->total_paisa,
                method: PaymentMethod::Cash,
                reference: $return,
                note: __('Cash received for :reference', ['reference' => $return->reference]),
                entryDate: $return->returned_at->toDateString(),
                userId: $user->getKey(),
            );
        }
    }
}
