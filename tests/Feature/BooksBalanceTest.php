<?php

namespace Tests\Feature;

use App\Enums\DrawerEntryType;
use App\Enums\MovementType;
use App\Enums\RefundMethod;
use App\Enums\SaleReturnReason;
use App\Enums\SaleStatus;
use App\Enums\TenderType;
use App\Models\Customer;
use App\Models\DrawerSession;
use App\Models\DrawerTransaction;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\DrawerService;
use App\Services\KhataService;
use App\Services\StockService;
use App\Services\SupplierLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One full day at the shop, and then the books.
 *
 * Every other test file proves one thing in isolation. This one trades a whole
 * day — a delivery on account, four bills paid four different ways, a refund,
 * a khata payment, money out for tea and a drop to the safe — and then asks
 * the only questions a shopkeeper actually cares about at closing time:
 *
 *   Does the shelf agree with the stock ledger?
 *   Does every khata agree with its own lines?
 *   Does the drawer expect exactly the cash that passed through it?
 *   Does every bill add up?
 *
 * If a future change breaks the arithmetic anywhere along that chain, this is
 * the test that notices, because these figures are the ones that cost money
 * when they are wrong.
 */
class BooksBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Product $soap;

    private ProductUnit $sachet;

    private ProductUnit $carton;

    private Product $rice;

    private ProductUnit $kilo;

    private Supplier $supplier;

    private Register $register;

    private User $owner;

    private User $cashier;

    private Customer $bilal;

    private Customer $sana;

    private DrawerSession $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $sachetUnit = Unit::factory()->sachet()->create();
        $cartonUnit = Unit::factory()->carton()->create();
        $kiloUnit = Unit::factory()->create(['name' => 'Kilogram', 'short_name' => 'kg']);

        /* A sachet of soap, 144 to the carton: the nested packaging the till
           has to convert on every line. */
        $this->soap = Product::factory()->create([
            'name' => 'Surf Excel',
            'sku' => 'SURF-1',
            'base_unit_id' => $sachetUnit->id,
        ]);

        $this->sachet = ProductUnit::factory()->base()->create([
            'product_id' => $this->soap->id,
            'unit_id' => $sachetUnit->id,
            'sale_price_paisa' => 40_00,
        ]);

        $this->carton = ProductUnit::factory()->holding(144)->create([
            'product_id' => $this->soap->id,
            'unit_id' => $cartonUnit->id,
            'sale_price_paisa' => 5_400_00,
        ]);

        /* Rice, sold loose by the kilo. */
        $this->rice = Product::factory()->create([
            'name' => 'Basmati rice',
            'sku' => 'RICE-1',
            'base_unit_id' => $kiloUnit->id,
        ]);

        $this->kilo = ProductUnit::factory()->base()->create([
            'product_id' => $this->rice->id,
            'unit_id' => $kiloUnit->id,
            'sale_price_paisa' => 320_00,
        ]);

        $this->supplier = Supplier::factory()->terms(30)->create(['name' => 'Unilever Distributor']);

        $this->register = Register::factory()->create(['name' => 'Front counter']);
        $this->owner = User::factory()->owner()->create(['name' => 'Abrar']);
        $this->cashier = User::factory()->cashier()->create(['name' => 'Kashif']);

        $this->bilal = Customer::factory()->create(['name' => 'Bilal', 'credit_limit_paisa' => 50_000_00]);
        $this->sana = Customer::factory()->create(['name' => 'Sana', 'credit_limit_paisa' => 50_000_00]);

        /* Rice is already on the shelf; the soap arrives during the day. */
        app(StockService::class)->record($this->rice, 200, MovementType::Opening, unitCostPaisa: 250_00);

        $this->tradeAllDay();
    }

    /*
    |--------------------------------------------------------------------------
    | The day
    |--------------------------------------------------------------------------
    */

    /**
     * A delivery, four bills, a refund, a khata payment and two hands in the
     * drawer. Everything goes through the screens a shopkeeper would use, so
     * the controllers are part of what is being proved.
     */
    private function tradeAllDay(): void
    {
        /* Ten cartons at Rs. 4,320, on account. */
        $this->actingAs($this->owner)->post(route('purchases.store'), [
            'supplier_id' => $this->supplier->id,
            'invoice_no' => 'UD-8841',
            'purchase_date' => today()->toDateString(),
            'discount' => '',
            'tax' => '',
            'paid' => '',
            'payment_method' => 'cash',
            'note' => null,
            'action' => 'receive',
            'items' => [[
                'product_id' => $this->soap->id,
                'product_unit_id' => $this->carton->id,
                'qty' => 10,
                'bonus_qty' => '',
                'unit_cost' => '4320.00',
                'batch_no' => '',
                'expiry_date' => '',
            ]],
        ])->assertSessionHasNoErrors();

        $this->shift = app(DrawerService::class)->open($this->register, $this->cashier, 5_000_00, 'Counted twice');

        /* Bill 1 — a carton and three sachets, cash, change given. */
        $this->sell([
            ['product_unit_id' => $this->carton->id, 'qty' => '1'],
            ['product_unit_id' => $this->sachet->id, 'qty' => '3'],
        ], [$this->cash(6_000_00)])->assertCreated();

        /* Bill 2 — rice, half on cash and half on Bilal's khata. */
        $this->sell([
            ['product_unit_id' => $this->kilo->id, 'qty' => '5'],
        ], [
            $this->cash(800_00),
            ['method' => TenderType::Khata->value, 'amount_paisa' => 800_00],
        ], customer: $this->bilal)->assertCreated();

        /* Bill 3 — card and cash together, with a line discount. */
        $this->sell([
            ['product_unit_id' => $this->sachet->id, 'qty' => '10', 'discount' => '5%'],
            ['product_unit_id' => $this->kilo->id, 'qty' => '2'],
        ], [
            ['method' => TenderType::Card->value, 'amount_paisa' => 500_00],
            $this->cash(1_000_00),
        ])->assertCreated();

        /* Bill 4 — all of it on Sana's khata, with a discount off the bill. */
        $this->sell([
            ['product_unit_id' => $this->sachet->id, 'qty' => '6'],
        ], [
            ['method' => TenderType::Khata->value, 'amount_paisa' => 216_00],
        ], customer: $this->sana, billDiscount: '10%')->assertCreated();

        /* One sachet comes back off bill 1, cash handed over the counter. */
        $first = Sale::query()->where('invoice_no', 1)->sole();

        $this->actingAs($this->owner)->post(route('sales.returns.store', $first), [
            'reason' => SaleReturnReason::ChangedMind->value,
            'settlement' => RefundMethod::Cash->value,
            'register_id' => $this->register->id,
            'items' => [[
                'sale_item_id' => $first->items->firstWhere('product_unit_id', $this->sachet->id)->id,
                'qty' => '1',
            ]],
        ])->assertSessionHasNoErrors();

        /* Bilal pays off half his khata in cash at the counter. */
        $this->actingAs($this->cashier)->post(route('customers.payments.store', $this->bilal), [
            'amount' => '400',
            'method' => TenderType::Cash->value,
            'register_id' => $this->register->id,
            'paid_on' => today()->toDateString(),
        ])->assertSessionHasNoErrors();

        /* Tea money out, and a drop to the safe when the drawer gets heavy. */
        $this->actingAs($this->cashier)
            ->post(route('drawer.movements.store', $this->shift), ['type' => 'pay_out', 'amount' => '250', 'note' => 'Tea for the counter'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->post(route('drawer.movements.store', $this->shift), ['type' => 'safe_drop', 'amount' => '5000', 'note' => 'To the safe'])
            ->assertSessionHasNoErrors();

        $this->shift->refresh();
    }

    /**
     * @param  list<array{product_unit_id: int, qty: string, discount?: string}>  $lines
     * @param  list<array{method: string, amount_paisa: int}>  $payments
     */
    private function sell(array $lines, array $payments, ?Customer $customer = null, ?string $billDiscount = null): TestResponse
    {
        return $this->actingAs($this->cashier)->postJson(route('pos.store'), [
            'register_id' => $this->register->id,
            'customer_id' => $customer?->id,
            'bill_discount' => $billDiscount,
            'lines' => $lines,
            'payments' => $payments,
        ]);
    }

    /**
     * @return array{method: string, amount_paisa: int}
     */
    private function cash(int $paisa): array
    {
        return ['method' => TenderType::Cash->value, 'amount_paisa' => $paisa];
    }

    /*
    |--------------------------------------------------------------------------
    | The books
    |--------------------------------------------------------------------------
    */

    public function test_the_day_happened_at_all(): void
    {
        $this->assertSame(4, Sale::query()->where('status', SaleStatus::Completed)->count());
        $this->assertSame(1, DB::table('sale_returns')->count());
        $this->assertSame(1, DB::table('purchases')->count());
    }

    public function test_every_shelf_agrees_with_its_own_stock_ledger(): void
    {
        foreach (Product::query()->get() as $product) {
            $ledger = (int) $product->movements()->sum('qty_base');

            $this->assertSame(
                $ledger,
                (int) $product->stock_qty_base,
                "The shelf figure for {$product->name} does not match the sum of its movements",
            );
        }
    }

    public function test_rebuilding_the_stock_from_the_ledger_changes_nothing(): void
    {
        foreach (Product::query()->get() as $product) {
            $rebuild = app(StockService::class)->rebuild($product);

            $this->assertFalse($rebuild['drifted'], "The books for {$product->name} drifted");
            $this->assertSame($rebuild['balance_before'], $rebuild['balance_after']);
            $this->assertSame($rebuild['average_before'], $rebuild['average_after']);
        }
    }

    public function test_a_carton_leaves_the_shelf_as_a_hundred_and_forty_four_sachets(): void
    {
        /* 10 cartons in, less one carton, 3 sachets, 10 sachets and 6 sachets
           sold, plus one sachet handed back. */
        $expected = (10 * 144) - 144 - 3 - 10 - 6 + 1;

        $this->assertSame($expected, (int) $this->soap->fresh()->stock_qty_base);

        /* And the same figure read the long way round, off the movements. */
        $this->assertSame($expected, (int) $this->soap->movements()->sum('qty_base'));
    }

    public function test_every_khata_agrees_with_its_own_lines(): void
    {
        foreach (Customer::query()->get() as $customer) {
            $lines = (int) $customer->ledgerEntries()->sum(DB::raw('debit_paisa - credit_paisa'));

            $this->assertSame($lines, (int) $customer->balance_paisa, "{$customer->name}'s khata does not match its lines");

            $last = $customer->ledgerEntries()->orderByDesc('id')->first();

            if ($last) {
                $this->assertSame(
                    (int) $customer->balance_paisa,
                    (int) $last->balance_after_paisa,
                    "The running balance on {$customer->name}'s last line is not where the khata ended up",
                );
            }
        }

        /* Bilal put Rs. 800 on the khata and paid Rs. 400 of it back. */
        $this->assertSame(400_00, (int) $this->bilal->fresh()->balance_paisa);
    }

    public function test_rebuilding_a_khata_from_its_lines_changes_nothing(): void
    {
        foreach (Customer::query()->get() as $customer) {
            $this->assertFalse(app(KhataService::class)->rebuild($customer)['drifted'], "{$customer->name}'s khata drifted");
        }
    }

    public function test_the_supplier_is_owed_what_the_delivery_came_to(): void
    {
        $this->assertSame(43_200_00, (int) $this->supplier->fresh()->balance_paisa);
        $this->assertFalse(app(SupplierLedgerService::class)->rebuild($this->supplier)['drifted']);
    }

    public function test_the_drawer_expects_exactly_the_cash_that_passed_through_it(): void
    {
        /* Counted by hand, the way the shopkeeper would: the float, the cash
           actually applied to each bill, less the refund and the money taken
           out, plus what Bilal paid off his khata. */
        $expected = 5_000_00                    // opening float
            + 5_520_00                          // bill 1: one carton and three sachets
            + 800_00                            // bill 2: the cash half
            + 520_00                            // bill 3: what the card did not cover
            - 40_00                             // one sachet refunded
            + 400_00                            // Bilal's khata payment
            - 250_00                            // tea
            - 5_000_00;                         // to the safe

        $this->assertSame($expected, $this->shift->expectedCashPaisa());

        /* And the same figure from the drawer's own lines. */
        $this->assertSame(
            (int) $this->shift->transactions()->sum('amount_paisa'),
            $this->shift->expectedCashPaisa(),
        );
    }

    public function test_nothing_but_cash_ever_reaches_the_drawer(): void
    {
        $this->assertSame(
            0,
            DrawerTransaction::query()->where('type', DrawerEntryType::CashSale)->where('amount_paisa', '<=', 0)->count(),
            'A cash sale can only ever add to the drawer',
        );

        /* Bill 4 went entirely on khata, so it left no line in the drawer. */
        $khataOnly = Sale::query()->where('invoice_no', 4)->sole();

        $this->assertSame(0, DrawerTransaction::query()
            ->where('reference_type', $khataOnly->getMorphClass())
            ->where('reference_id', $khataOnly->getKey())
            ->count());
    }

    public function test_every_bill_adds_up_line_by_line(): void
    {
        foreach (Sale::query()->with(['items', 'payments'])->get() as $sale) {
            $lines = (int) $sale->items->sum('line_total_paisa');

            $this->assertSame(
                $lines - (int) $sale->round_off_paisa,
                (int) $sale->total_paisa,
                "The lines on {$sale->invoiceNumber()} do not come to its total",
            );

            $tax = $sale->prices_include_tax ? 0 : (int) $sale->tax_paisa;

            $this->assertSame(
                (int) $sale->subtotal_paisa - (int) $sale->discount_paisa + $tax - (int) $sale->round_off_paisa,
                (int) $sale->total_paisa,
                "The total on {$sale->invoiceNumber()} is not the subtotal less what came off it",
            );

            $this->assertSame(
                (int) $sale->total_paisa,
                (int) $sale->paid_paisa + (int) $sale->due_paisa,
                "What was paid and what is owed on {$sale->invoiceNumber()} do not come to the total",
            );

            $this->assertSame(
                (int) $sale->total_paisa,
                (int) $sale->payments->sum('amount_paisa'),
                "The payments on {$sale->invoiceNumber()} do not come to the total",
            );

            $cash = $sale->payments->firstWhere('method', TenderType::Cash);

            $this->assertSame(
                $cash ? (int) $cash->tendered_paisa - (int) $cash->amount_paisa : 0,
                (int) $sale->change_given_paisa,
                "The change on {$sale->invoiceNumber()} is not what was handed over less what was kept",
            );
        }
    }

    public function test_what_went_on_khata_is_exactly_what_the_khata_lines_say(): void
    {
        $dueOnBills = (int) Sale::query()->where('status', SaleStatus::Completed)->sum('due_paisa');

        $fromSales = (int) DB::table('customer_ledger_entries')
            ->where('reference_type', (new Sale)->getMorphClass())
            ->sum('debit_paisa');

        $this->assertSame($dueOnBills, $fromSales, 'Money put on khata at the till must reach the khata itself');
    }

    public function test_a_closed_shift_freezes_the_figure_it_closed_on(): void
    {
        $expected = $this->shift->expectedCashPaisa();

        /* Counted note by note: 5,000 + 1,000 + 500 + 4 x 100 + 50 = Rs. 6,950,
           which is exactly what the drawer should hold. */
        $this->actingAs($this->cashier)->post(route('drawer.close.store', $this->shift), [
            'counts' => [5000 => 1, 1000 => 1, 500 => 1, 100 => 4, 50 => 1],
            'left_in_drawer' => '5000',
        ])->assertSessionHasNoErrors();

        $this->shift->refresh();

        $this->assertSame($expected, (int) $this->shift->expected_cash_paisa);
        $this->assertSame(6_950_00, (int) $this->shift->counted_cash_paisa);
        $this->assertSame(0, (int) $this->shift->variance_paisa);

        /* A closed shift answers with the frozen figure, not a fresh sum, so
           the day's report says the same thing a year later. */
        $this->assertSame($expected, $this->shift->expectedCashPaisa());
        $this->assertSame(1_950_00, $this->shift->takenAtClosePaisa(), 'What goes to the owner is what was counted less what stays for tomorrow');
    }

    public function test_the_money_taken_today_is_the_same_whichever_way_it_is_counted(): void
    {
        $bills = (int) Sale::query()->where('status', SaleStatus::Completed)->sum('total_paisa');

        $payments = (int) DB::table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.status', SaleStatus::Completed->value)
            ->sum('sale_payments.amount_paisa');

        $this->assertSame($bills, $payments);
    }

    public function test_the_shop_settings_are_what_the_arithmetic_assumed(): void
    {
        /* If a later change flips either of these the figures above move, and
           the failure should say so plainly rather than looking like a maths
           bug somewhere else. */
        $this->assertTrue((bool) Setting::read('tax.prices_include_tax'));
        $this->assertFalse((bool) Setting::read('sale.round_to_rupee'));
    }
}
