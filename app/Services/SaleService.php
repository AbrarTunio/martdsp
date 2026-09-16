<?php

namespace App\Services;

use App\Enums\CustomerEntryType;
use App\Enums\MovementType;
use App\Enums\SaleStatus;
use App\Enums\TenderType;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DrawerSession;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use App\Support\Packaging;
use App\Support\SaleCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ringing up a bill.
 *
 * Completing a sale is the moment four things move together: stock leaves
 * the shelf, the money is taken, a khata may be written to, and the bill gets
 * its invoice number. It happens in one transaction or not at all.
 *
 * Prices always come from the database, never from the till. The till sends
 * the total it showed the customer, and if a price moved in the meantime the
 * sale is refused with the new prices rather than charging a figure nobody saw.
 */
class SaleService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly KhataService $khata,
        private readonly DrawerService $drawer,
    ) {}

    /**
     * Finish a sale.
     *
     * @param  array<int, array{product_unit_id: int, qty: string|int|float, discount?: string|null}>  $lines
     * @param  array<int, array{method: TenderType|string, amount_paisa: int, reference?: string|null}>  $payments
     *
     * A bill rung up while the internet was down arrives with an `$offlineUid`
     * the till gave it. If that name has been seen before the bill already
     * stands, and the one on file is handed back rather than a second one
     * being made — a reply lost on a bad line must never charge twice.
     *
     * @throws PricesChangedException when the till's total no longer matches
     * @throws RuntimeException when the basket, the discount, the stock or the payment does not add up
     */
    public function complete(
        User $cashier,
        Register $register,
        array $lines,
        array $payments,
        ?Customer $customer = null,
        ?string $billDiscount = null,
        ?int $expectedTotalPaisa = null,
        ?string $note = null,
        ?string $offlineUid = null,
        ?string $rungUpAt = null,
    ): Sale {
        $already = $offlineUid === null ? null : $this->alreadyTaken($offlineUid);

        if ($already !== null) {
            return $already;
        }

        return DB::transaction(function () use (
            $cashier, $register, $lines, $payments, $customer, $billDiscount, $expectedTotalPaisa, $note,
            $offlineUid, $rungUpAt
        ): Sale {
            $bill = $this->price($lines, $billDiscount);
            $totals = $bill['totals'];

            if ($expectedTotalPaisa !== null && $expectedTotalPaisa !== $totals['total_paisa']) {
                throw new PricesChangedException(
                    prices: collect($bill['lines'])
                        ->mapWithKeys(fn (array $line): array => [$line['unit']->id => (int) $line['unit']->sale_price_paisa])
                        ->all(),
                    totalPaisa: $totals['total_paisa'],
                );
            }

            $this->refuseADiscountBeyondTheCashiersLimit($cashier, $totals);

            $settlement = $this->settle($totals['total_paisa'], $payments, $customer);

            $this->refuseKhataBeyondTheCreditLimit($cashier, $customer, $settlement['due']);

            $drawer = $this->drawer->lockOpenAt($register);

            $invoiceNo = $this->nextInvoiceNumber();

            $products = $this->lockAndCheckStock($bill['lines']);

            $sale = Sale::query()->create([
                'invoice_no' => $invoiceNo,
                'customer_id' => $customer?->id,
                'register_id' => $register->id,
                'drawer_session_id' => $drawer->id,
                'user_id' => $cashier->id,
                'status' => SaleStatus::Completed,
                'subtotal_paisa' => $totals['subtotal_paisa'],
                'bill_discount' => $this->typed($billDiscount),
                'discount_paisa' => $totals['discount_paisa'],
                'tax_paisa' => $totals['tax_paisa'],
                'prices_include_tax' => $bill['prices_include_tax'],
                'round_off_paisa' => $totals['round_off_paisa'],
                'total_paisa' => $totals['total_paisa'],
                'paid_paisa' => $settlement['paid'],
                'change_given_paisa' => $settlement['change'],
                'due_paisa' => $settlement['due'],
                'note' => $this->typed($note),
                'sold_at' => now(),
                'offline_uid' => $offlineUid,
                'offline_rung_at' => $this->rungUpAt($rungUpAt),
            ]);

            foreach ($bill['lines'] as $line) {
                $product = $products[$line['unit']->product_id];

                $item = $this->createItem($sale, $line, (int) $product->avg_cost_base_paisa);

                $this->stock->record(
                    product: $product,
                    qtyBase: -$item->qty_base,
                    type: MovementType::Sale,
                    productUnit: $line['unit'],
                    reference: $sale,
                    userId: $cashier->id,
                    note: $sale->invoiceNumber(),
                );
            }

            foreach ($settlement['rows'] as $row) {
                $sale->payments()->create($row + ['user_id' => $cashier->id]);
            }

            $this->drawer->recordSale($drawer, $sale);

            if ($settlement['due'] > 0) {
                $this->khata->record(
                    customer: $customer,
                    type: CustomerEntryType::SaleCredit,
                    debitPaisa: $settlement['due'],
                    method: TenderType::Khata,
                    reference: $sale,
                    note: $sale->invoiceNumber(),
                    dueDate: today()->addDays((int) Setting::read('khata.credit_days')),
                    userId: $cashier->id,
                );
            }

            ActivityLog::record('sale.completed', $sale, after: [
                'invoice' => $sale->invoiceNumber(),
                'total_paisa' => $sale->total_paisa,
                'discount_paisa' => $sale->discount_paisa,
                'due_paisa' => $sale->due_paisa,
                'items' => count($bill['lines']),
            ]);

            return $sale->load(['items', 'payments', 'customer', 'register', 'user']);
        });
    }

    /**
     * A bill the till has already sent, found by the name it gave itself.
     */
    private function alreadyTaken(string $offlineUid): ?Sale
    {
        return Sale::query()
            ->where('offline_uid', $offlineUid)
            ->with(['items', 'payments', 'customer', 'register', 'user'])
            ->first();
    }

    /**
     * When the cashier actually took the money. Anything the till cannot make
     * sense of, or a time in the future, is dropped rather than trusted — the
     * clock on a phone is not evidence.
     */
    private function rungUpAt(?string $rungUpAt): ?Carbon
    {
        if ($rungUpAt === null) {
            return null;
        }

        $moment = rescue(fn (): Carbon => Carbon::parse($rungUpAt), null, false);

        return $moment !== null && $moment->isPast() && $moment->greaterThan(now()->subWeek())
            ? $moment
            : null;
    }

    /**
     * Park a basket so the next customer can be served. Nothing moves — no
     * stock, no money, no invoice number.
     *
     * @param  array<int, array{product_unit_id: int, qty: string|int|float, discount?: string|null}>  $lines
     */
    public function hold(
        User $cashier,
        Register $register,
        array $lines,
        ?Customer $customer = null,
        ?string $billDiscount = null,
        ?string $note = null,
    ): Sale {
        return DB::transaction(function () use ($cashier, $register, $lines, $customer, $billDiscount, $note): Sale {
            $bill = $this->price($lines, $billDiscount);
            $totals = $bill['totals'];

            $sale = Sale::query()->create([
                'customer_id' => $customer?->id,
                'register_id' => $register->id,
                'user_id' => $cashier->id,
                'status' => SaleStatus::Held,
                'subtotal_paisa' => $totals['subtotal_paisa'],
                'bill_discount' => $this->typed($billDiscount),
                'discount_paisa' => $totals['discount_paisa'],
                'tax_paisa' => $totals['tax_paisa'],
                'prices_include_tax' => $bill['prices_include_tax'],
                'round_off_paisa' => $totals['round_off_paisa'],
                'total_paisa' => $totals['total_paisa'],
                'note' => $this->typed($note),
            ]);

            foreach ($bill['lines'] as $line) {
                $this->createItem($sale, $line, 0);
            }

            return $sale;
        });
    }

    /**
     * Take a parked basket back off the shelf. The held sale is removed and
     * its contents handed back for the till to carry on with.
     *
     * @return array{customer_id: int|null, bill_discount: string|null, note: string|null, lines: array<int, array{product_unit_id: int|null, qty: string, discount: string|null}>}
     *
     * @throws RuntimeException when someone else has already taken it
     */
    public function resume(Sale $sale): array
    {
        return DB::transaction(function () use ($sale): array {
            $locked = $this->lockHeld($sale);
            $locked->load('items');

            $cart = [
                'customer_id' => $locked->customer_id,
                'bill_discount' => $locked->bill_discount,
                'note' => $locked->note,
                'lines' => array_values(array_filter(
                    $locked->cartLines(),
                    fn (array $line): bool => $line['product_unit_id'] !== null,
                )),
            ];

            $locked->delete();

            return $cart;
        });
    }

    /**
     * Throw a parked basket away.
     *
     * @throws RuntimeException when it is no longer on hold
     */
    public function discard(Sale $sale): void
    {
        DB::transaction(function () use ($sale): void {
            $this->lockHeld($sale)->delete();
        });
    }

    /**
     * Cancel a completed sale as if it had never happened: the stock goes
     * back on the shelf at the cost it left at, and anything put on khata
     * comes off again. Any cash is handed back out of the drawer running at
     * the counter it was sold at. The sale stays on the list, marked void,
     * with who did it and why.
     *
     * @throws RuntimeException when the sale is not a completed sale from today, or its cash has no open drawer to come out of
     */
    public function void(Sale $sale, User $supervisor, string $reason): Sale
    {
        return DB::transaction(function () use ($sale, $supervisor, $reason): Sale {
            $drawer = DrawerSession::query()
                ->open()
                ->where('register_id', $sale->register_id)
                ->lockForUpdate()
                ->first();

            $locked = Sale::query()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== SaleStatus::Completed) {
                throw new RuntimeException(__('Only a completed sale can be voided.'));
            }

            if (! $locked->isVoidable()) {
                throw new RuntimeException(__('Only today\'s sales can be voided. For an older sale, take the goods back as a customer return.'));
            }

            if ($this->drawer->cashIn($locked) > 0) {
                if (! $drawer) {
                    throw new RuntimeException(__('The cash for this bill has to come out of the drawer at :register. Open that drawer first.', [
                        'register' => $locked->register?->name ?? __('the counter'),
                    ]));
                }

                $this->drawer->recordVoid($drawer, $locked, $supervisor);
            }

            $items = $locked->items()->with(['productUnit', 'product'])->orderBy('product_id')->orderBy('id')->get();

            foreach ($items as $item) {
                $this->stock->record(
                    product: $item->product ?? Product::query()->findOrFail($item->product_id),
                    qtyBase: $item->qty_base,
                    type: MovementType::SaleVoid,
                    unitCostPaisa: $item->cost_at_sale_base_paisa,
                    productUnit: $item->productUnit,
                    reference: $locked,
                    userId: $supervisor->id,
                    note: __(':invoice voided', ['invoice' => $locked->invoiceNumber()]),
                );
            }

            if ($locked->due_paisa > 0 && $locked->customer) {
                $this->khata->record(
                    customer: $locked->customer,
                    type: CustomerEntryType::SaleVoid,
                    creditPaisa: $locked->due_paisa,
                    reference: $locked,
                    note: __(':invoice voided', ['invoice' => $locked->invoiceNumber()]),
                    userId: $supervisor->id,
                );
            }

            $locked->forceFill([
                'status' => SaleStatus::Void,
                'voided_by' => $supervisor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            ActivityLog::record('sale.voided', $locked, after: [
                'invoice' => $locked->invoiceNumber(),
                'total_paisa' => $locked->total_paisa,
                'reason' => $reason,
            ]);

            return $locked;
        });
    }

    /**
     * Look every line's price up and work the bill out.
     *
     * @param  array<int, array{product_unit_id: int, qty: string|int|float, discount?: string|null}>  $lines
     * @return array{
     *     lines: list<array{unit: ProductUnit, qty_milli: int, qty_base: int, discount: string|null, figures: array<string, int>}>,
     *     totals: array<string, mixed>,
     *     prices_include_tax: bool,
     * }
     *
     * @throws RuntimeException when a line cannot be sold as entered
     */
    private function price(array $lines, ?string $billDiscount): array
    {
        $lines = array_values($lines);

        if ($lines === []) {
            throw new RuntimeException(__('The basket is empty.'));
        }

        $units = ProductUnit::query()
            ->with(['unit', 'product.baseUnit', 'product.productUnits.unit'])
            ->whereIn('id', array_map(fn (array $line): int => (int) $line['product_unit_id'], $lines))
            ->get()
            ->keyBy('id');

        $resolved = [];

        foreach ($lines as $line) {
            $unit = $units->get((int) $line['product_unit_id'])
                ?? throw new RuntimeException(__('Something in the basket is no longer on sale. Remove it and scan it again.'));

            $product = $unit->product;

            if (! $product->is_active) {
                throw new RuntimeException(__(':name has been switched off and cannot be sold.', ['name' => $product->name]));
            }

            $qtyMilli = SaleCalculator::qtyMilli($line['qty']);

            if ($qtyMilli <= 0) {
                throw new RuntimeException(__('Enter how many :name.', ['name' => $product->name]));
            }

            if (! SaleCalculator::isWholeInBase($qtyMilli, (int) $unit->conversion_factor)) {
                throw new RuntimeException(__(':name cannot be sold in that amount: :qty :unit is not a whole number of :base.', [
                    'name' => $product->name,
                    'qty' => $this->qtyString($qtyMilli),
                    'unit' => $unit->unit?->name,
                    'base' => $product->baseUnit?->name,
                ]));
            }

            $resolved[] = [
                'unit' => $unit,
                'qty_milli' => $qtyMilli,
                'qty_base' => SaleCalculator::qtyBase($qtyMilli, (int) $unit->conversion_factor),
                'discount' => $this->typed($line['discount'] ?? null),
            ];
        }

        $pricesIncludeTax = (bool) Setting::read('tax.prices_include_tax');

        $totals = SaleCalculator::calculate(
            lines: array_map(fn (array $line): array => [
                'qty' => $this->qtyString($line['qty_milli']),
                'unit_price_paisa' => (int) $line['unit']->sale_price_paisa,
                'tax_rate' => (string) $line['unit']->product->tax_rate,
                'discount' => $line['discount'],
            ], $resolved),
            billDiscount: $billDiscount,
            pricesIncludeTax: $pricesIncludeTax,
            roundToRupee: (bool) Setting::read('sales.round_to_rupee'),
        );

        foreach ($resolved as $index => $line) {
            $resolved[$index]['figures'] = $totals['lines'][$index];
        }

        return [
            'lines' => $resolved,
            'totals' => $totals,
            'prices_include_tax' => $pricesIncludeTax,
        ];
    }

    /**
     * @param  array{unit: ProductUnit, qty_milli: int, qty_base: int, discount: string|null, figures: array<string, int>}  $line
     */
    private function createItem(Sale $sale, array $line, int $costBasePaisa): SaleItem
    {
        $unit = $line['unit'];

        return $sale->items()->create([
            'product_id' => $unit->product_id,
            'product_unit_id' => $unit->id,
            'name' => $unit->product->name,
            'unit_name' => (string) ($unit->unit?->name ?? ''),
            'qty' => $this->qtyString($line['qty_milli']),
            'qty_base' => $line['qty_base'],
            'unit_price_paisa' => (int) $unit->sale_price_paisa,
            'gross_paisa' => $line['figures']['gross_paisa'],
            'discount' => $line['discount'],
            'discount_paisa' => $line['figures']['discount_paisa'],
            'bill_discount_paisa' => $line['figures']['bill_discount_paisa'],
            'tax_rate' => (string) $unit->product->tax_rate,
            'tax_paisa' => $line['figures']['tax_paisa'],
            'line_total_paisa' => $line['figures']['line_total_paisa'],
            'cost_at_sale_base_paisa' => $costBasePaisa,
        ]);
    }

    /**
     * Owners and managers may discount as they see fit. A cashier is held to
     * the limit in Settings, measured against the bill before any discount.
     *
     * @param  array<string, mixed>  $totals
     *
     * @throws RuntimeException
     */
    private function refuseADiscountBeyondTheCashiersLimit(User $cashier, array $totals): void
    {
        if ($cashier->supervises() || $totals['discount_paisa'] === 0) {
            return;
        }

        $limit = (float) Setting::read('sales.cashier_discount_limit');
        $limitBp = (int) round($limit * 100);
        $allowed = SaleCalculator::divRound($totals['subtotal_paisa'] * $limitBp, 10_000);

        if ($totals['discount_paisa'] > $allowed) {
            throw new RuntimeException(__('The discount on this bill is :discount. A cashier can give up to :limit% (:allowed) — ask a manager to ring it up.', [
                'discount' => Money::withSymbol($totals['discount_paisa']),
                'limit' => rtrim(rtrim(number_format($limit, 2, '.', ''), '0'), '.'),
                'allowed' => Money::withSymbol($allowed),
            ]));
        }
    }

    /**
     * A customer with a credit limit cannot be taken past it by a cashier.
     * A manager can, because they know who is good for it.
     *
     * @throws RuntimeException
     */
    private function refuseKhataBeyondTheCreditLimit(User $cashier, ?Customer $customer, int $duePaisa): void
    {
        if ($duePaisa === 0 || ! $customer || ! $customer->hasCreditLimit() || $cashier->supervises()) {
            return;
        }

        $owedAfter = (int) $customer->fresh()->balance_paisa + $duePaisa;

        if ($owedAfter > $customer->credit_limit_paisa) {
            throw new RuntimeException(__(':name would owe :owed, over their limit of :limit. Take more now, or ask a manager.', [
                'name' => $customer->name,
                'owed' => Money::withSymbol($owedAfter),
                'limit' => Money::withSymbol($customer->credit_limit_paisa),
            ]));
        }
    }

    /**
     * Apply the tenders to the bill.
     *
     * Card, wallet, bank and khata amounts are taken as exact and may not come
     * to more than the bill, because nothing can be handed back from them.
     * Cash pays whatever is left, and anything over is change.
     *
     * @param  array<int, array{method: TenderType|string, amount_paisa: int, reference?: string|null}>  $payments
     * @return array{rows: list<array{method: TenderType, amount_paisa: int, tendered_paisa: int, reference: string|null}>, paid: int, due: int, change: int}
     *
     * @throws RuntimeException
     */
    private function settle(int $totalPaisa, array $payments, ?Customer $customer): array
    {
        $rows = [];
        $cashTendered = 0;
        $nonCash = 0;
        $due = 0;

        foreach ($payments as $payment) {
            $method = $payment['method'] instanceof TenderType
                ? $payment['method']
                : TenderType::from($payment['method']);
            $amount = max(0, (int) $payment['amount_paisa']);

            if ($amount === 0) {
                continue;
            }

            if ($method->isCash()) {
                $cashTendered += $amount;

                continue;
            }

            if ($method->needsCustomer() && ! $customer) {
                throw new RuntimeException(__('Choose the customer whose khata this goes on.'));
            }

            $nonCash += $amount;

            if ($nonCash > $totalPaisa) {
                throw new RuntimeException(__(':method is more than is left to pay. Only cash can be given back as change.', [
                    'method' => __($method->label()),
                ]));
            }

            if ($method === TenderType::Khata) {
                $due += $amount;
            }

            $rows[] = [
                'method' => $method,
                'amount_paisa' => $amount,
                'tendered_paisa' => $amount,
                'reference' => $this->typed($payment['reference'] ?? null),
            ];
        }

        $cashApplied = min($cashTendered, $totalPaisa - $nonCash);

        if ($nonCash + $cashApplied < $totalPaisa) {
            throw new RuntimeException(__(':amount still to pay — take more, or put it on khata.', [
                'amount' => Money::withSymbol($totalPaisa - $nonCash - $cashApplied),
            ]));
        }

        if ($cashApplied > 0) {
            array_unshift($rows, [
                'method' => TenderType::Cash,
                'amount_paisa' => $cashApplied,
                'tendered_paisa' => $cashTendered,
                'reference' => null,
            ]);
        }

        return [
            'rows' => $rows,
            'paid' => $totalPaisa - $due,
            'due' => $due,
            /* Every rupee of cash that the bill did not need goes back over the
               counter — including the whole of it when a card had already paid,
               which is the one case where none of it is applied. */
            'change' => $cashTendered - $cashApplied,
        ];
    }

    /**
     * Lock every product on the bill, in id order so two counters can never
     * wait on each other, and refuse to sell what is not on the shelf unless
     * the shop has chosen to allow it.
     *
     * @param  list<array{unit: ProductUnit, qty_base: int}>  $lines
     * @return Collection<int, Product>
     *
     * @throws RuntimeException
     */
    private function lockAndCheckStock(array $lines): Collection
    {
        $needed = [];

        foreach ($lines as $line) {
            $productId = (int) $line['unit']->product_id;
            $needed[$productId] = ($needed[$productId] ?? 0) + $line['qty_base'];
        }

        $products = Product::query()
            ->whereIn('id', array_keys($needed))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ((bool) Setting::read('sales.allow_negative_stock')) {
            return $products;
        }

        foreach ($lines as $line) {
            $product = $products[(int) $line['unit']->product_id];
            $stock = (int) $product->stock_qty_base;

            if ($stock < $needed[$product->id]) {
                throw new RuntimeException($stock > 0
                    ? __('Only :stock of :name left in stock.', [
                        'stock' => Packaging::describe($stock, $line['unit']->product->productUnits),
                        'name' => $product->name,
                    ])
                    : __(':name is out of stock.', ['name' => $product->name]));
            }
        }

        return $products;
    }

    /**
     * The next number in the sequence, read under a lock so two counters
     * finishing at the same moment cannot both take it. The unique index is
     * the backstop.
     */
    private function nextInvoiceNumber(): int
    {
        $last = Sale::query()
            ->whereNotNull('invoice_no')
            ->orderByDesc('invoice_no')
            ->lockForUpdate()
            ->value('invoice_no');

        return (int) $last + 1;
    }

    /**
     * @throws RuntimeException
     */
    private function lockHeld(Sale $sale): Sale
    {
        $locked = Sale::query()->whereKey($sale->getKey())->lockForUpdate()->first();

        if (! $locked || $locked->status !== SaleStatus::Held) {
            throw new RuntimeException(__('That basket is no longer on hold — someone may have taken it already.'));
        }

        return $locked;
    }

    /**
     * 1250 → "1.25", 2000 → "2".
     */
    private function qtyString(int $qtyMilli): string
    {
        $text = sprintf('%d.%03d', intdiv($qtyMilli, 1000), $qtyMilli % 1000);

        return rtrim(rtrim($text, '0'), '.');
    }

    /**
     * Free text from the till, or null when nothing was typed.
     */
    private function typed(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
