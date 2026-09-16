<?php

namespace Database\Seeders;

use App\Enums\DrawerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Enums\RefundMethod;
use App\Enums\Role;
use App\Enums\SaleReturnReason;
use App\Enums\TenderType;
use App\Models\Customer;
use App\Models\DrawerSession;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DrawerService;
use App\Services\KhataService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\SupplierLedgerService;
use App\Support\SaleCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A month of trading, day by day.
 *
 * Every figure the demo shows — the charts, the khata balances, the drawer
 * reports, the AI insights — is worked out from real rows, so the history has
 * to be made the same way the shop makes it: through the same services, one
 * bill at a time. Nothing here writes a total straight into a table.
 *
 * The clock is wound back for each moment so that a sale rung up three weeks
 * ago is dated three weeks ago in every ledger that recorded it, and wound
 * forward again when the seeder finishes.
 */
class DemoHistorySeeder extends Seeder
{
    /**
     * The same demo shop every time it is seeded. A fixed seed means a
     * screenshot taken today still matches what somebody else sees tomorrow.
     */
    private const SEED = 20260916;

    private const FLOAT_PAISA = 5_000_00;

    public function __construct(
        private readonly SaleService $sales,
        private readonly SaleReturnService $returns,
        private readonly PurchaseService $purchases,
        private readonly KhataService $khata,
        private readonly DrawerService $drawers,
        private readonly SupplierLedgerService $supplierLedger,
    ) {}

    private User $owner;

    private User $manager;

    /** @var Collection<int, User> */
    private Collection $cashiers;

    /** @var Collection<int, Register> */
    private Collection $counters;

    /** @var Collection<int, Customer> */
    private Collection $khataCustomers;

    /** @var Collection<int, Supplier> */
    private Collection $suppliers;

    /**
     * What can be rung up, and what it costs to ring up: unit id, the product
     * behind it, how many base units it is, and its price and tax.
     *
     * @var array<int, array{unit_id: int, product_id: int, factor: int, price: int, tax: string, weighed: bool}>
     */
    private array $pool = [];

    /**
     * What is on the shelf right now, in base units, so a basket is never
     * built out of stock the shop does not have.
     *
     * @var array<int, int>
     */
    private array $onHand = [];

    /** @var array<int, int> product id => the supplier it is bought from */
    private array $boughtFrom = [];

    private bool $pricesIncludeTax = true;

    private bool $roundToRupee = true;

    /** The real moment the seeder started, kept because the clock is wound back all through this. */
    private Carbon $startedAt;

    private Carbon $realToday;

    public function run(int $days = 28): void
    {
        mt_srand(self::SEED);

        $this->load();

        $this->startedAt = Carbon::now();
        $this->realToday = Carbon::today();

        /* One shift is left open at the end, so whoever opens the demo walks
           into a live counter. That is today's, unless the seeder is run
           before the shop would have opened, in which case yesterday's is
           still going. */
        $liveDay = $this->hasHappened($this->realToday, 9, 10) ? 0 : 1;

        try {
            for ($ago = $days; $ago >= 0; $ago--) {
                $this->tradeOneDay($this->realToday->copy()->subDays($ago), leaveOpen: $ago === $liveDay);
            }
        } finally {
            Carbon::setTestNow();
        }

        $this->command?->info('Traded '.($days + 1).' days: '.Sale::count().' bills rung up.');
    }

    private function load(): void
    {
        $this->owner = User::where('role', Role::Owner)->orderBy('id')->firstOrFail();
        $this->manager = User::where('role', Role::Manager)->orderBy('id')->first() ?? $this->owner;
        $this->cashiers = User::where('role', Role::Cashier)->orderBy('id')->get();

        if ($this->cashiers->isEmpty()) {
            $this->cashiers = collect([$this->manager]);
        }

        $this->counters = Register::orderBy('id')->get();
        $this->khataCustomers = Customer::orderBy('id')->get();
        $this->suppliers = Supplier::orderBy('id')->get();

        $this->pricesIncludeTax = (bool) Setting::read('tax.prices_include_tax');
        $this->roundToRupee = (bool) Setting::read('sales.round_to_rupee');

        $products = Product::with('productUnits')->where('is_active', true)->orderBy('id')->get();

        foreach ($products as $index => $product) {
            $this->onHand[$product->id] = (int) $product->stock_qty_base;
            $this->boughtFrom[$product->id] = $this->suppliers[$index % max(1, $this->suppliers->count())]->id;

            $this->addToPool($product);
        }
    }

    /**
     * The sizes a product actually sells in: mostly the everyday one, and now
     * and then the biggest pack, because somebody always buys a whole carton.
     */
    private function addToPool(Product $product): void
    {
        $units = $product->productUnits->sortBy('conversion_factor')->values();
        $everyday = $units->firstWhere('is_default_sale', true) ?? $units->first();
        $biggest = $units->last();

        if (! $everyday) {
            return;
        }

        $entry = fn (ProductUnit $unit): array => [
            'unit_id' => $unit->id,
            'product_id' => $product->id,
            'factor' => max(1, (int) $unit->conversion_factor),
            'price' => (int) $unit->sale_price_paisa,
            'tax' => (string) $product->tax_rate,
            'weighed' => (bool) $product->is_weighted,
        ];

        /* Weighted eight to one, which is roughly how a kiryana sells. */
        for ($i = 0; $i < 8; $i++) {
            $this->pool[] = $entry($everyday);
        }

        if ($biggest && $biggest->id !== $everyday->id) {
            $this->pool[] = $entry($biggest);
        }
    }

    private function tradeOneDay(Carbon $date, bool $leaveOpen): void
    {
        if (! $this->hasHappened($date, 9, 10)) {
            return;
        }

        $dayNumber = (int) $date->dayOfYear;
        $main = $this->counters->first();
        $second = $this->counters->get(1);

        /* Today is only traded as far as the clock has got. A demo seeded at
           lunchtime shows a half-finished day, which is what a shop looks
           like at lunchtime. */
        $closing = $date->isSameDay($this->realToday) ? min(21, (int) $this->startedAt->hour) : 21;

        $this->at($date, 9, 10);
        $shifts = [[$main, $this->cashiers->first(), $this->drawers->open($main, $this->manager, self::FLOAT_PAISA, 'Counted with the manager')]];

        if ($dayNumber % 5 === 0 && $this->hasHappened($date, 10, 30)) {
            $this->takeDelivery($date);
        }

        if ($dayNumber % 6 === 0 && $this->hasHappened($date, 11, 45)) {
            $this->paySupplier($date);
        }

        $this->sellThroughTheDay($date, $main, $this->cashiers->first(), 9, $closing, mt_rand(14, 24));

        /* The second counter is opened for the evening rush on busy days. */
        if ($second && $dayNumber % 3 === 0 && $this->hasHappened($date, 17, 0)) {
            $this->at($date, 17, 0);
            $evening = $this->cashiers->get(1) ?? $this->cashiers->first();
            $shifts[] = [$second, $evening, $this->drawers->open($second, $this->manager, 3_000_00, 'Evening rush')];

            $this->sellThroughTheDay($date, $second, $evening, 17, $closing, mt_rand(6, 12));
        }

        if ($dayNumber % 7 === 0 && $this->hasHappened($date, 12, 20)) {
            $this->takeSomethingBack($date, $main);
        }

        if ($dayNumber % 3 === 0 && $this->hasHappened($date, 18, 15)) {
            $this->collectOnKhata($date, $main);
        }

        $this->payTheDaysExpenses($date, $shifts[0][2]);

        foreach ($shifts as $index => [$register, $cashier, $session]) {
            /* The main counter of the last day is left open, so whoever opens
               the demo walks into a live shift rather than a closed one. */
            if ($leaveOpen && $index === 0) {
                continue;
            }

            $this->closeUp($date, $session, $cashier, short: $dayNumber % 11 === 0 ? 50_00 : 0);
        }
    }

    /**
     * Bills, spread across the opening hours in the order they were rung up.
     */
    private function sellThroughTheDay(Carbon $date, Register $register, User $cashier, int $from, int $until, int $bills): void
    {
        $first = $from * 60 + 25;
        $last = $until * 60 - 10;

        if ($last <= $first) {
            return;
        }

        $minutes = [];

        for ($i = 0; $i < $bills; $i++) {
            $minutes[] = mt_rand($first, $last);
        }

        sort($minutes);

        foreach ($minutes as $minute) {
            $this->at($date, intdiv($minute, 60), $minute % 60);
            $this->ringUp($register, $cashier);
        }
    }

    private function ringUp(Register $register, User $cashier): void
    {
        $lines = $this->basket();

        if ($lines === []) {
            return;
        }

        $billDiscount = null;
        $total = $this->priceUp($lines, null);

        if ($total >= 1_500_00 && mt_rand(1, 10) === 1) {
            $billDiscount = '50';
            $total = $this->priceUp($lines, $billDiscount);
        }

        [$customer, $payments] = $this->settleUp($total);

        $sale = $this->sales->complete(
            cashier: $cashier,
            register: $register,
            lines: $lines,
            payments: $payments,
            customer: $customer,
            billDiscount: $billDiscount,
        );

        foreach ($sale->items as $item) {
            $this->onHand[$item->product_id] -= (int) $item->qty_base;
        }
    }

    /**
     * How a customer paid. Mostly cash, with a note handed over and change
     * given back; sometimes a card or a wallet; and now and then a regular
     * puts it on their khata.
     *
     * @return array{0: Customer|null, 1: array<int, array{method: TenderType, amount_paisa: int}>}
     */
    private function settleUp(int $total): array
    {
        $roll = mt_rand(1, 100);

        if ($roll > 90) {
            $customer = $this->aCustomerWithRoomFor($total);

            if ($customer) {
                /* Part in hand, the rest written up — the usual arrangement. */
                $inHand = mt_rand(1, 3) === 1 ? 0 : (intdiv($total, 200) * 100);

                return [$customer, array_values(array_filter([
                    $inHand > 0 ? ['method' => TenderType::Cash, 'amount_paisa' => $inHand] : null,
                    ['method' => TenderType::Khata, 'amount_paisa' => $total - $inHand],
                ]))];
            }
        }

        if ($roll > 80) {
            return [null, [['method' => TenderType::Card, 'amount_paisa' => $total]]];
        }

        if ($roll > 74) {
            $wallet = mt_rand(0, 1) === 0 ? TenderType::Easypaisa : TenderType::JazzCash;

            return [null, [['method' => $wallet, 'amount_paisa' => $total]]];
        }

        return [null, [['method' => TenderType::Cash, 'amount_paisa' => $this->noteHandedOver($total)]]];
    }

    /**
     * Nobody hands over the exact amount. They give the next round figure up,
     * and often a bit more so they get a whole note back.
     */
    private function noteHandedOver(int $total): int
    {
        $rounded = (int) (ceil($total / 50_00) * 50_00);

        return mt_rand(1, 3) === 1 ? $rounded + 50_00 : $rounded;
    }

    private function aCustomerWithRoomFor(int $total): ?Customer
    {
        $customer = $this->khataCustomers[mt_rand(0, $this->khataCustomers->count() - 1)]->fresh();

        if (! $customer || ! $customer->is_active) {
            return null;
        }

        $room = (int) $customer->credit_limit_paisa - (int) $customer->balance_paisa;

        return $room >= $total ? $customer : null;
    }

    /**
     * A basket, built only from what is actually on the shelf.
     *
     * @return array<int, array{product_unit_id: int, qty: string}>
     */
    private function basket(): array
    {
        $wanted = mt_rand(1, 5) <= 3 ? mt_rand(1, 2) : mt_rand(3, 5);
        $lines = [];
        $taken = [];

        for ($attempt = 0; $attempt < $wanted * 4 && count($lines) < $wanted; $attempt++) {
            $pick = $this->pool[array_rand($this->pool)];

            if (isset($taken[$pick['unit_id']])) {
                continue;
            }

            $qty = $this->howMany($pick);
            $qtyBase = intdiv(SaleCalculator::qtyMilli($qty) * $pick['factor'], 1000);

            if ($qtyBase <= 0 || $qtyBase > ($this->onHand[$pick['product_id']] ?? 0)) {
                continue;
            }

            $taken[$pick['unit_id']] = true;
            $lines[] = ['product_unit_id' => $pick['unit_id'], 'qty' => $qty];
        }

        return $lines;
    }

    /**
     * @param  array{factor: int, weighed: bool}  $pick
     */
    private function howMany(array $pick): string
    {
        if ($pick['weighed']) {
            return ['0.5', '1', '1', '1.5', '2', '2.5', '5'][mt_rand(0, 6)];
        }

        if ($pick['factor'] > 1) {
            return '1';
        }

        return (string) [1, 1, 1, 2, 2, 3, 4, 6][mt_rand(0, 7)];
    }

    /**
     * What the bill will come to, worked out with the same calculator the
     * till uses, so the cash handed over is a believable figure.
     *
     * @param  array<int, array{product_unit_id: int, qty: string}>  $lines
     */
    private function priceUp(array $lines, ?string $billDiscount): int
    {
        $prices = collect($this->pool)->keyBy('unit_id');

        $totals = SaleCalculator::calculate(
            lines: array_map(fn (array $line): array => [
                'qty' => $line['qty'],
                'unit_price_paisa' => (int) $prices[$line['product_unit_id']]['price'],
                'tax_rate' => (string) $prices[$line['product_unit_id']]['tax'],
            ], $lines),
            billDiscount: $billDiscount,
            pricesIncludeTax: $this->pricesIncludeTax,
            roundToRupee: $this->roundToRupee,
        );

        return (int) $totals['total_paisa'];
    }

    /**
     * A delivery: everything that has fallen below its reorder level, from
     * the distributor who supplies it.
     */
    private function takeDelivery(Carbon $date): void
    {
        $this->at($date, 10, 30);

        $low = Product::query()
            ->whereColumn('stock_qty_base', '<=', 'reorder_level_base')
            ->with('productUnits')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Product $product): int => $this->boughtFrom[$product->id]);

        foreach ($low as $supplierId => $products) {
            $supplier = $this->suppliers->firstWhere('id', $supplierId);

            if (! $supplier) {
                continue;
            }

            $this->receiveFrom($supplier, $products, $date);
        }
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function receiveFrom(Supplier $supplier, Collection $products, Carbon $date): void
    {
        $items = [];
        $billPaisa = 0;

        foreach ($products as $product) {
            $unit = $product->productUnits->firstWhere('is_default_purchase', true)
                ?? $product->productUnits->sortByDesc('conversion_factor')->first();

            if (! $unit) {
                continue;
            }

            $factor = max(1, (int) $unit->conversion_factor);
            $qty = max(1, (int) ceil(((int) $product->reorder_qty_base) / $factor));
            $cost = max(1, (int) round((int) $product->avg_cost_base_paisa * $factor));

            $items[] = [
                'product_id' => $product->id,
                'product_unit_id' => $unit->id,
                'qty' => $qty,
                'bonus_qty' => 0,
                'unit_cost_paisa' => $cost,
            ];

            $billPaisa += $qty * $cost;
            $this->onHand[$product->id] += $qty * $factor;
        }

        if ($items === []) {
            return;
        }

        /* The cash-and-carry is paid at the counter; everyone else bills the
           shop and waits for their terms. */
        $onTheSpot = (int) $supplier->payment_terms_days === 0;

        $purchase = Purchase::create([
            'supplier_id' => $supplier->id,
            'invoice_no' => strtoupper(substr($supplier->name, 0, 3)).'-'.$date->format('ymd'),
            'purchase_date' => $date->toDateString(),
            'status' => PurchaseStatus::Draft,
            'discount_paisa' => 0,
            'tax_paisa' => 0,
            'paid_paisa' => $onTheSpot ? $billPaisa : 0,
            'payment_method' => $onTheSpot ? PaymentMethod::Cash : null,
            'user_id' => $this->owner->id,
        ]);

        $purchase->items()->createMany($items);

        $this->purchases->receive($purchase, $this->manager);
    }

    /**
     * Money sent to a distributor against what the shop owes them.
     */
    private function paySupplier(Carbon $date): void
    {
        $this->at($date, 11, 45);

        $owed = $this->suppliers->map->fresh()
            ->filter(fn (?Supplier $supplier): bool => $supplier && $supplier->balance_paisa > 0)
            ->values();

        if ($owed->isEmpty()) {
            return;
        }

        $supplier = $owed[mt_rand(0, $owed->count() - 1)];
        $paying = (int) (floor(min((int) $supplier->balance_paisa, intdiv((int) $supplier->balance_paisa, 2) + 1_000_00) / 100_00) * 100_00);

        if ($paying <= 0) {
            return;
        }

        $this->supplierLedger->payment(
            supplier: $supplier,
            paisa: $paying,
            method: PaymentMethod::BankTransfer,
            entryDate: $date->toDateString(),
            note: 'Online transfer against account',
        );
    }

    /**
     * Something bought in the last day or two comes back over the counter.
     */
    private function takeSomethingBack(Carbon $date, Register $register): void
    {
        $this->at($date, 12, 20);

        $sale = Sale::query()
            ->whereNull('customer_id')
            ->where('sold_at', '>=', $date->copy()->subDays(2))
            ->where('sold_at', '<', $date)
            ->with('items')
            ->orderByDesc('id')
            ->first();

        $item = $sale?->items->first(fn (SaleItem $item): bool => $item->qty_base > 0);

        if (! $sale || ! $item) {
            return;
        }

        $this->returns->post(
            sale: $sale,
            lines: [['sale_item_id' => $item->id, 'qty_base' => min((int) $item->qty_base, max(1, intdiv((int) $item->qty_base, 2)))]],
            user: $this->manager,
            reason: [SaleReturnReason::Damaged, SaleReturnReason::WrongItem, SaleReturnReason::ChangedMind][mt_rand(0, 2)],
            settlement: RefundMethod::Cash,
            register: $register,
        );

        $this->onHand[$item->product_id] = (int) Product::whereKey($item->product_id)->value('stock_qty_base');
    }

    /**
     * A regular comes in and puts something towards their khata.
     */
    private function collectOnKhata(Carbon $date, Register $register): void
    {
        $this->at($date, 18, 15);

        $owing = $this->khataCustomers->map->fresh()
            ->filter(fn (?Customer $customer): bool => $customer && $customer->balance_paisa >= 500_00)
            ->values();

        if ($owing->isEmpty()) {
            return;
        }

        $customer = $owing[mt_rand(0, $owing->count() - 1)];
        $balance = (int) $customer->balance_paisa;
        $paying = mt_rand(1, 4) === 1 ? $balance : (int) (floor(($balance / 2) / 100_00) * 100_00);

        if ($paying <= 0) {
            return;
        }

        $this->khata->payment(
            customer: $customer,
            paisa: $paying,
            method: TenderType::Cash,
            register: $register,
            entryDate: $date->toDateString(),
            note: 'Paid at the counter',
            user: $this->cashiers->first(),
        );
    }

    /**
     * The small cash that leaves the drawer during the day, and the big cash
     * that goes to the safe before closing.
     */
    private function payTheDaysExpenses(Carbon $date, DrawerSession $session): void
    {
        if (! $this->hasHappened($date, 16, 5)) {
            return;
        }

        $this->at($date, 16, 5);

        $spends = [
            [150_00, 'Tea and snacks for the staff'],
            [300_00, 'Rickshaw fare for a home delivery'],
            [600_00, 'Shop cleaning and sweeper'],
            [450_00, 'Load shedding — generator petrol'],
        ];

        [$amount, $why] = $spends[mt_rand(0, count($spends) - 1)];

        $this->drawers->move($session, $this->manager, DrawerEntryType::PayOut, $amount, $why);

        if (! $this->hasHappened($date, 19, 40)) {
            return;
        }

        $this->at($date, 19, 40);

        $inTheDrawer = $session->refresh()->expectedCashPaisa();

        if ($inTheDrawer > 25_000_00) {
            $this->drawers->move(
                $session,
                $this->manager,
                DrawerEntryType::SafeDrop,
                (int) (floor(($inTheDrawer - 10_000_00) / 1_000_00) * 1_000_00),
                'Sent upstairs to the safe',
            );
        }
    }

    /**
     * Closing time: the drawer is counted note by note, the float is left in
     * for tomorrow, and the rest goes to the owner.
     */
    private function closeUp(Carbon $date, DrawerSession $session, User $cashier, int $short): void
    {
        $this->at($date, 21, 30);

        $expected = $session->refresh()->expectedCashPaisa();
        $counts = $this->countedOut(max(0, $expected - $short));
        $counted = $this->worthOf($counts);

        $closed = $this->drawers->close(
            session: $session,
            user: $cashier,
            counts: $counts,
            leftInDrawerPaisa: min(self::FLOAT_PAISA, $counted),
            reason: $short > 0 ? 'Change given wrong on a busy evening' : null,
        );

        /* A short drawer waits for a manager. The oldest ones have been
           signed off; the most recent is still sitting there to be looked at,
           which is exactly what the approvals screen is for. */
        if ($closed->needs_approval && $closed->approved_at === null && $date->lessThan($this->realToday->copy()->subDays(3))) {
            $this->at($date->copy()->addDay(), 9, 30);
            $this->drawers->approve($closed, $this->owner, 'Checked the receipts, wrote it off');
        }
    }

    /**
     * Break an amount into the notes and coins that would actually be in the
     * drawer. Anything under a rupee cannot be counted, which is why a real
     * count is a rupee or two under now and then.
     *
     * @return array<int, int>
     */
    private function countedOut(int $paisa): array
    {
        $rupees = intdiv($paisa, 100);
        $counts = [];

        foreach (array_map('intval', config('supermart.cash.denominations')) as $note) {
            $howMany = intdiv($rupees, $note);

            if ($howMany > 0) {
                $counts[$note] = $howMany;
                $rupees -= $howMany * $note;
            }
        }

        return $counts;
    }

    /**
     * @param  array<int, int>  $counts
     */
    private function worthOf(array $counts): int
    {
        $total = 0;

        foreach ($counts as $note => $howMany) {
            $total += $note * 100 * $howMany;
        }

        return $total;
    }

    /**
     * Wind the clock to a moment on a given day. Everything written after
     * this call — the sale, the stock movement, the khata line, the drawer
     * entry — is dated then.
     */
    private function at(Carbon $date, int $hour, int $minute): void
    {
        Carbon::setTestNow($date->copy()->setTime($hour, $minute, mt_rand(0, 59)));
    }

    /**
     * Whether a moment in the shop's day has already been and gone. Nothing
     * is written for a moment that has not arrived yet, so the demo never
     * contains a sale dated later than the afternoon it was seeded.
     */
    private function hasHappened(Carbon $date, int $hour, int $minute): bool
    {
        return $date->copy()->setTime($hour, $minute)->lessThanOrEqualTo($this->startedAt);
    }
}
