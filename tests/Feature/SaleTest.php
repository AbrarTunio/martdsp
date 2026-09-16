<?php

namespace Tests\Feature;

use App\Enums\CustomerEntryType;
use App\Enums\MovementType;
use App\Enums\SaleStatus;
use App\Enums\TenderType;
use App\Models\Barcode;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\DrawerService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ringing up a bill at the till.
 *
 * The server prices the basket itself from the database, takes the stock off
 * in base units whatever size was scanned, and settles the payment — cash
 * gives change, everything else is exact, and what is left goes on khata.
 */
class SaleTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductUnit $sachet;

    private ProductUnit $carton;

    private Register $register;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $sachetUnit = Unit::factory()->sachet()->create();
        $cartonUnit = Unit::factory()->carton()->create();

        $this->product = Product::factory()->create([
            'name' => 'Surf Excel',
            'sku' => 'SURF-1',
            'base_unit_id' => $sachetUnit->id,
        ]);

        $this->sachet = ProductUnit::factory()->base()->create([
            'product_id' => $this->product->id,
            'unit_id' => $sachetUnit->id,
            'sale_price_paisa' => 3_000,
        ]);

        $this->carton = ProductUnit::factory()->holding(144)->create([
            'product_id' => $this->product->id,
            'unit_id' => $cartonUnit->id,
            'sale_price_paisa' => 400_000,
        ]);

        Barcode::factory()->primary()->create(['product_unit_id' => $this->sachet->id, 'code' => '8961000000011']);
        Barcode::factory()->create(['product_unit_id' => $this->carton->id, 'code' => '8961000000028']);

        /* Ten cartons on the shelf, bought at Rs. 20 a sachet. */
        app(StockService::class)->record($this->product, 1440, MovementType::Opening, unitCostPaisa: 2_000);

        $this->register = Register::factory()->create();
        $this->owner = User::factory()->owner()->create();
        $this->cashier = User::factory()->cashier()->create();

        app(DrawerService::class)->open($this->register, $this->owner, 500_000, null);
    }

    /**
     * @param  list<array{product_unit_id: int, qty: string, discount?: string}>  $lines
     * @param  list<array{method: string, amount_paisa: int, reference?: string}>  $payments
     * @param  array<string, mixed>  $extra
     */
    private function sell(array $lines, array $payments, ?User $as = null, array $extra = []): TestResponse
    {
        return $this->actingAs($as ?? $this->cashier)->postJson(route('pos.store'), [
            'register_id' => $this->register->id,
            'lines' => $lines,
            'payments' => $payments,
        ] + $extra);
    }

    /**
     * @return list<array{method: string, amount_paisa: int}>
     */
    private function cash(int $paisa): array
    {
        return [['method' => TenderType::Cash->value, 'amount_paisa' => $paisa]];
    }

    public function test_a_carton_and_some_sachets_come_off_the_shelf_in_sachets(): void
    {
        $response = $this->sell([
            ['product_unit_id' => $this->carton->id, 'qty' => '1'],
            ['product_unit_id' => $this->sachet->id, 'qty' => '3'],
        ], $this->cash(410_000));

        $response->assertCreated()
            ->assertJsonPath('sale.invoice', 'INV-000001')
            ->assertJsonPath('sale.total_paisa', 409_000)
            ->assertJsonPath('sale.change_paisa', 1_000)
            ->assertJsonPath('sale.due_paisa', 0);

        $sale = Sale::query()->sole();

        $this->assertSame(SaleStatus::Completed, $sale->status);
        $this->assertSame(1440 - 147, $this->product->fresh()->stock_qty_base);

        $cartonLine = $sale->items->firstWhere('product_unit_id', $this->carton->id);
        $this->assertSame(144, $cartonLine->qty_base);
        $this->assertSame(2_000, $cartonLine->cost_at_sale_base_paisa, 'Cost is kept as it was on the day');

        $movement = StockMovement::query()->where('type', MovementType::Sale)->where('product_unit_id', $this->carton->id)->sole();
        $this->assertSame(-144, $movement->qty_base);
        $this->assertTrue($movement->reference->is($sale));

        $payment = $sale->payments->sole();
        $this->assertSame(TenderType::Cash, $payment->method);
        $this->assertSame(409_000, $payment->amount_paisa);
        $this->assertSame(410_000, $payment->tendered_paisa);
    }

    public function test_invoice_numbers_run_on_one_after_another(): void
    {
        $this->sell([['product_unit_id' => $this->sachet->id, 'qty' => '1']], $this->cash(3_000))
            ->assertJsonPath('sale.invoice', 'INV-000001');

        $this->sell([['product_unit_id' => $this->sachet->id, 'qty' => '1']], $this->cash(3_000))
            ->assertJsonPath('sale.invoice', 'INV-000002');
    }

    public function test_half_a_carton_sells_as_seventy_two_sachets(): void
    {
        $this->sell([['product_unit_id' => $this->carton->id, 'qty' => '0.5']], $this->cash(200_000))
            ->assertCreated()
            ->assertJsonPath('sale.total_paisa', 200_000);

        $this->assertSame(1440 - 72, $this->product->fresh()->stock_qty_base);
    }

    public function test_half_a_sachet_cannot_be_sold(): void
    {
        $response = $this->sell([['product_unit_id' => $this->sachet->id, 'qty' => '0.5']], $this->cash(1_500));

        $response->assertUnprocessable();
        $this->assertStringContainsString('cannot be sold in that amount', $response->json('message'));
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_card_is_taken_exactly_and_the_cash_on_top_gives_change(): void
    {
        $response = $this->sell([['product_unit_id' => $this->carton->id, 'qty' => '1']], [
            ['method' => TenderType::Card->value, 'amount_paisa' => 100_000, 'reference' => 'TX-99'],
            ['method' => TenderType::Cash->value, 'amount_paisa' => 305_000],
        ]);

        $response->assertCreated()->assertJsonPath('sale.change_paisa', 5_000);

        $payments = Sale::query()->sole()->payments()->orderBy('id')->get();

        $this->assertSame(TenderType::Cash, $payments[0]->method, 'Cash is listed first');
        $this->assertSame(300_000, $payments[0]->amount_paisa);
        $this->assertSame(305_000, $payments[0]->tendered_paisa);
        $this->assertSame(TenderType::Card, $payments[1]->method);
        $this->assertSame(100_000, $payments[1]->amount_paisa);
        $this->assertSame('TX-99', $payments[1]->reference);
    }

    public function test_a_card_cannot_pay_more_than_the_bill(): void
    {
        $response = $this->sell([['product_unit_id' => $this->sachet->id, 'qty' => '1']], [
            ['method' => TenderType::Card->value, 'amount_paisa' => 5_000],
        ]);

        $response->assertUnprocessable();
        $this->assertStringContainsString('Only cash can be given back as change', $response->json('message'));
    }

    /**
     * The card took the whole bill and the cashier counted the cash out anyway.
     * The shop is owed the bill once, so the cash is not a second payment — it
     * all goes back as change. The till now switches the cash box off in this
     * case; this is what the books do if it ever gets through anyway.
     */
    public function test_cash_counted_on_top_of_a_card_that_already_paid_is_all_change(): void
    {
        $response = $this->sell([['product_unit_id' => $this->carton->id, 'qty' => '1']], [
            ['method' => TenderType::Card->value, 'amount_paisa' => 400_000],
            ['method' => TenderType::Cash->value, 'amount_paisa' => 400_000],
        ]);

        $response->assertCreated()
            ->assertJsonPath('sale.total_paisa', 400_000)
            ->assertJsonPath('sale.change_paisa', 400_000)
            ->assertJsonPath('sale.due_paisa', 0);

        $sale = Sale::query()->sole();

        $this->assertSame(400_000, (int) $sale->payments()->sum('amount_paisa'), 'The bill is paid once, not twice');

        $payment = $sale->payments->sole();
        $this->assertSame(TenderType::Card, $payment->method, 'None of the cash was needed, so none of it was taken');
        $this->assertSame(400_000, $payment->amount_paisa);
    }

    public function test_what_is_not_paid_goes_on_the_customers_khata(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->sell([['product_unit_id' => $this->carton->id, 'qty' => '1']], [
            ['method' => TenderType::Cash->value, 'amount_paisa' => 100_000],
            ['method' => TenderType::Khata->value, 'amount_paisa' => 300_000],
        ], extra: ['customer_id' => $customer->id]);

        $response->assertCreated()
            ->assertJsonPath('sale.due_paisa', 300_000)
            ->assertJsonPath('sale.paid_paisa', 100_000)
            ->assertJsonPath('sale.customer_balance', 'Rs. 3,000.00');

        $this->assertSame(300_000, $customer->fresh()->balance_paisa);

        $entry = CustomerLedgerEntry::query()->sole();
        $this->assertSame(CustomerEntryType::SaleCredit, $entry->type);
        $this->assertSame(300_000, $entry->debit_paisa);
        $this->assertSame(today()->addDays(30)->toDateString(), $entry->due_date->toDateString());
        $this->assertTrue($entry->reference->is(Sale::query()->sole()));
    }

    public function test_khata_needs_a_customer(): void
    {
        $response = $this->sell([['product_unit_id' => $this->sachet->id, 'qty' => '1']], [
            ['method' => TenderType::Khata->value, 'amount_paisa' => 3_000],
        ]);

        $response->assertUnprocessable()->assertJsonPath('message', 'Choose the customer whose khata this goes on.');
        $this->assertSame(0, CustomerLedgerEntry::query()->count());
    }

    public function test_a_short_payment_is_refused_and_nothing_moves(): void
    {
        $response = $this->sell([['product_unit_id' => $this->sachet->id, 'qty' => '2']], $this->cash(5_000));

        $response->assertUnprocessable();
        $this->assertStringContainsString('still to pay', $response->json('message'));
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(1440, $this->product->fresh()->stock_qty_base);
    }

    public function test_more_than_is_on_the_shelf_is_refused_unless_the_shop_allows_it(): void
    {
        $lines = [['product_unit_id' => $this->carton->id, 'qty' => '11']];

        $response = $this->sell($lines, $this->cash(4_400_000));

        $response->assertUnprocessable();
        $this->assertStringContainsString('left in stock', $response->json('message'));

        Setting::write('sales.allow_negative_stock', true, 'sales');

        $this->sell($lines, $this->cash(4_400_000))->assertCreated();
        $this->assertSame(-144, $this->product->fresh()->stock_qty_base);
    }

    public function test_a_cashier_cannot_discount_past_their_limit_but_a_manager_can(): void
    {
        $lines = [['product_unit_id' => $this->sachet->id, 'qty' => '10']];

        $response = $this->sell($lines, $this->cash(25_000), extra: ['bill_discount' => '50']);

        $response->assertUnprocessable();
        $this->assertStringContainsString('ask a manager', $response->json('message'));

        $this->sell($lines, $this->cash(27_000), extra: ['bill_discount' => '30'])
            ->assertCreated()
            ->assertJsonPath('sale.total_paisa', 27_000);

        $this->sell($lines, $this->cash(25_000), User::factory()->manager()->create(), ['bill_discount' => '50'])
            ->assertCreated()
            ->assertJsonPath('sale.total_paisa', 25_000);
    }

    public function test_a_cashier_cannot_take_a_customer_past_their_credit_limit_but_a_manager_can(): void
    {
        $customer = Customer::factory()->limit(100_000)->owing(50_000)->create();
        $lines = [['product_unit_id' => $this->sachet->id, 'qty' => '20']];
        $onKhata = [['method' => TenderType::Khata->value, 'amount_paisa' => 60_000]];

        $response = $this->sell($lines, $onKhata, extra: ['customer_id' => $customer->id]);

        $response->assertUnprocessable();
        $this->assertStringContainsString('over their limit', $response->json('message'));
        $this->assertSame(50_000, $customer->fresh()->balance_paisa);

        $this->sell($lines, $onKhata, User::factory()->manager()->create(), ['customer_id' => $customer->id])
            ->assertCreated();

        $this->assertSame(110_000, $customer->fresh()->balance_paisa);
    }

    public function test_a_price_changed_since_the_scan_is_sent_back_to_the_till(): void
    {
        $this->sachet->update(['sale_price_paisa' => 3_500]);

        $response = $this->sell(
            [['product_unit_id' => $this->sachet->id, 'qty' => '2']],
            $this->cash(6_000),
            extra: ['expected_total_paisa' => 6_000],
        );

        $response->assertUnprocessable()
            ->assertJsonPath('repriced.total_paisa', 7_000)
            ->assertJsonPath("repriced.prices.{$this->sachet->id}", 3_500);

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_a_switched_off_counter_cannot_sell(): void
    {
        $this->register->update(['is_active' => false]);

        $this->sell([['product_unit_id' => $this->sachet->id, 'qty' => '1']], $this->cash(3_000))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('register_id');
    }

    public function test_a_scanned_barcode_finds_the_exact_size(): void
    {
        $this->actingAs($this->cashier)
            ->getJson(route('pos.lookup', ['code' => '8961000000028']))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('item.product_unit_id', $this->carton->id)
            ->assertJsonPath('item.stock_qty_base', 1440)
            ->assertJsonCount(2, 'item.units');
    }

    public function test_a_typed_sku_or_name_finds_the_usual_selling_size(): void
    {
        $this->actingAs($this->cashier)
            ->getJson(route('pos.lookup', ['code' => 'SURF-1']))
            ->assertOk()
            ->assertJsonPath('item.product_unit_id', $this->sachet->id);

        $this->actingAs($this->cashier)
            ->getJson(route('pos.lookup', ['code' => 'surf']))
            ->assertOk()
            ->assertJsonPath('item.product_id', $this->product->id);
    }

    public function test_an_unknown_barcode_says_so_and_unknown_text_suggests_a_search(): void
    {
        $this->actingAs($this->cashier)
            ->getJson(route('pos.lookup', ['code' => '8969999999999']))
            ->assertNotFound()
            ->assertJsonPath('found', false)
            ->assertJsonPath('search', false);

        $this->actingAs($this->cashier)
            ->getJson(route('pos.lookup', ['code' => 'zzz']))
            ->assertNotFound()
            ->assertJsonPath('search', true);
    }

    public function test_a_switched_off_product_cannot_be_scanned_in(): void
    {
        $this->product->update(['is_active' => false]);

        $response = $this->actingAs($this->cashier)->getJson(route('pos.lookup', ['code' => '8961000000011']));

        $response->assertNotFound();
        $this->assertStringContainsString('switched off', $response->json('message'));
    }

    public function test_search_lists_matching_products_with_their_sizes(): void
    {
        $this->actingAs($this->cashier)
            ->getJson(route('pos.search', ['q' => 'surf']))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonCount(2, 'items.0.units');

        $this->actingAs($this->cashier)
            ->getJson(route('pos.search', ['q' => 's']))
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function test_the_till_opens_on_the_only_counter_and_remembers_it(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('pos.index'))
            ->assertOk()
            ->assertViewIs('pos.index')
            ->assertSessionHas('pos.register_id', $this->register->id);
    }

    public function test_the_days_bills_one_bill_and_its_receipts_render(): void
    {
        $this->sell([['product_unit_id' => $this->carton->id, 'qty' => '1']], $this->cash(400_000));
        $sale = Sale::query()->sole();

        $this->actingAs($this->owner)->get(route('sales.index'))->assertOk()->assertSee('INV-000001');
        $this->actingAs($this->owner)->get(route('sales.show', $sale))->assertOk()->assertSee('Surf Excel');

        foreach (['80', '58', 'a4'] as $paper) {
            $this->actingAs($this->owner)
                ->get(route('sales.receipt', ['sale' => $sale, 'paper' => $paper]))
                ->assertOk()
                ->assertViewHas('paper', $paper)
                ->assertSee('INV-000001');
        }

        $this->actingAs($this->owner)
            ->get(route('sales.receipt', ['sale' => $sale, 'print' => 1]))
            ->assertOk()
            ->assertSee('window.print()', false);
    }

    public function test_a_cashier_sees_only_their_own_bills(): void
    {
        $other = User::factory()->cashier()->create();

        Sale::factory()->completed()->create(['user_id' => $this->cashier->id, 'register_id' => $this->register->id]);
        Sale::factory()->completed()->create(['user_id' => $other->id, 'register_id' => $this->register->id]);

        $this->actingAs($this->cashier)
            ->get(route('sales.index'))
            ->assertOk()
            ->assertSee('INV-000001')
            ->assertDontSee('INV-000002');

        $this->actingAs($this->owner)
            ->get(route('sales.index'))
            ->assertSee('INV-000001')
            ->assertSee('INV-000002');
    }
}
