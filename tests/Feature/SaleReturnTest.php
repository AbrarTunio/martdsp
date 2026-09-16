<?php

namespace Tests\Feature;

use App\Enums\CustomerEntryType;
use App\Enums\DrawerEntryType;
use App\Enums\MovementType;
use App\Enums\RefundMethod;
use App\Enums\SaleReturnReason;
use App\Enums\TenderType;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\DrawerSession;
use App\Models\DrawerTransaction;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\DrawerService;
use App\Services\SaleService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Goods a customer brings back.
 *
 * The bill decides everything: what is handed back is what the line actually
 * earned after its discount, the goods come back on the shelf at what they
 * cost on the day they were sold, and nothing can come back twice. Expired
 * and damaged goods are paid for but never restocked.
 */
class SaleReturnTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductUnit $sachet;

    private ProductUnit $carton;

    private Register $register;

    private Customer $customer;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $sachetUnit = Unit::factory()->sachet()->create();
        $cartonUnit = Unit::factory()->carton()->create();

        $this->product = Product::factory()->create(['name' => 'Surf Excel', 'base_unit_id' => $sachetUnit->id]);

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

        /* Ten cartons on the shelf at Rs. 20 a sachet. */
        app(StockService::class)->record($this->product, 1440, MovementType::Opening, unitCostPaisa: 2_000);

        $this->register = Register::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->cashier = User::factory()->cashier()->create();
        $this->manager = User::factory()->manager()->create();

        app(DrawerService::class)->open($this->register, $this->manager, 500_000, null);
    }

    /**
     * Two cartons, paid for in cash.
     */
    private function sellTwoCartons(?Customer $customer = null): Sale
    {
        return app(SaleService::class)->complete(
            cashier: $this->cashier,
            register: $this->register,
            lines: [['product_unit_id' => $this->carton->id, 'qty' => '2']],
            payments: [['method' => TenderType::Cash, 'amount_paisa' => 800_000]],
            customer: $customer,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function returnForm(Sale $sale, array $overrides = []): array
    {
        return array_replace_recursive([
            'reason' => SaleReturnReason::ChangedMind->value,
            'settlement' => RefundMethod::Cash->value,
            'register_id' => $this->register->id,
            'items' => [
                ['sale_item_id' => $sale->items->first()->id, 'qty' => '1'],
            ],
        ], $overrides);
    }

    public function test_a_carton_comes_back_for_cash_out_of_the_drawer(): void
    {
        $sale = $this->sellTwoCartons();

        $this->assertSame(1440 - 288, $this->product->fresh()->stock_qty_base);

        $this->actingAs($this->manager)
            ->post(route('sales.returns.store', $sale), $this->returnForm($sale))
            ->assertSessionHasNoErrors();

        $return = SaleReturn::query()->sole();

        $this->assertSame('SRT-000001', $return->reference);
        $this->assertSame(400_000, $return->total_paisa, 'Half a two-carton line hands back half the line');
        $this->assertTrue($return->restocked);
        $this->assertSame($this->manager->id, $return->user_id);

        $this->assertSame(1440 - 144, $this->product->fresh()->stock_qty_base);

        $movement = StockMovement::query()->where('type', MovementType::SaleReturn)->sole();
        $this->assertSame(144, $movement->qty_base);
        $this->assertSame(2_000, $movement->unit_cost_base_paisa, 'Back at the cost it left at, not today\'s average');

        $drawer = DrawerTransaction::query()->where('type', DrawerEntryType::Refund)->sole();
        $this->assertSame(-400_000, $drawer->amount_paisa);
    }

    public function test_a_refund_can_come_off_the_khata_instead_of_the_drawer(): void
    {
        $sale = $this->sellTwoCartons($this->customer);

        $this->actingAs($this->manager)
            ->post(route('sales.returns.store', $sale), $this->returnForm($sale, [
                'settlement' => RefundMethod::Khata->value,
            ]))
            ->assertSessionHasNoErrors();

        $return = SaleReturn::query()->sole();

        $this->assertSame(RefundMethod::Khata, $return->settlement);

        $entry = CustomerLedgerEntry::query()->where('type', CustomerEntryType::SaleReturn)->sole();
        $this->assertSame(400_000, $entry->credit_paisa);
        $this->assertSame($this->customer->id, $entry->customer_id);

        $this->assertSame(
            0,
            DrawerTransaction::query()->where('type', DrawerEntryType::Refund)->count(),
            'Nothing leaves the till when the refund comes off what they owe'
        );
    }

    public function test_khata_credit_is_refused_on_a_walk_in_bill(): void
    {
        $sale = $this->sellTwoCartons();

        $this->actingAs($this->manager)
            ->post(route('sales.returns.store', $sale), $this->returnForm($sale, [
                'settlement' => RefundMethod::Khata->value,
            ]))
            ->assertSessionHasErrors('settlement');

        $this->assertSame(0, SaleReturn::query()->count());
    }

    public function test_expired_goods_are_paid_for_but_never_go_back_on_the_shelf(): void
    {
        $sale = $this->sellTwoCartons();
        $onTheShelf = $this->product->fresh()->stock_qty_base;

        $this->actingAs($this->manager)
            ->post(route('sales.returns.store', $sale), $this->returnForm($sale, [
                'reason' => SaleReturnReason::Expired->value,
            ]))
            ->assertSessionHasNoErrors();

        $return = SaleReturn::query()->sole();

        $this->assertFalse($return->restocked);
        $this->assertSame(400_000, $return->total_paisa);
        $this->assertSame($onTheShelf, $this->product->fresh()->stock_qty_base, 'Expired goods are not put back');
        $this->assertSame(0, StockMovement::query()->where('type', MovementType::SaleReturn)->count());

        $this->assertSame(400_000, $return->lossPaisa(), 'The whole refund is lost, goods and all');
    }

    public function test_a_line_returned_in_pieces_never_hands_back_more_than_the_line_earned(): void
    {
        $sale = app(SaleService::class)->complete(
            cashier: $this->cashier,
            register: $this->register,
            lines: [['product_unit_id' => $this->sachet->id, 'qty' => '3']],
            payments: [['method' => TenderType::Cash, 'amount_paisa' => 9_000]],
        );

        $item = $sale->items->first();

        foreach (['1', '1', '1'] as $qty) {
            $this->actingAs($this->manager)
                ->post(route('sales.returns.store', $sale), $this->returnForm($sale, [
                    'items' => [['sale_item_id' => $item->id, 'qty' => $qty]],
                ]))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(
            (int) $item->line_total_paisa,
            (int) SaleReturn::query()->sum('total_paisa'),
            'Three trips to the counter add up to exactly one line'
        );

        $this->assertSame(1440, $this->product->fresh()->stock_qty_base);
    }

    public function test_more_cannot_come_back_than_was_sold(): void
    {
        $sale = $this->sellTwoCartons();

        $this->actingAs($this->manager)
            ->post(route('sales.returns.store', $sale), $this->returnForm($sale, [
                'items' => [['sale_item_id' => $sale->items->first()->id, 'qty' => '3']],
            ]))
            ->assertSessionHasErrors('items.0.qty');

        $this->assertSame(0, SaleReturn::query()->count());
    }

    public function test_what_is_already_back_cannot_come_back_again(): void
    {
        $sale = $this->sellTwoCartons();

        $this->actingAs($this->manager)
            ->post(route('sales.returns.store', $sale), $this->returnForm($sale, [
                'items' => [['sale_item_id' => $sale->items->first()->id, 'qty' => '2']],
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)
            ->post(route('sales.returns.store', $sale), $this->returnForm($sale))
            ->assertSessionHasErrors('items.0.qty');

        $this->assertSame(1, SaleReturn::query()->count());
    }

    public function test_a_sachet_cannot_come_back_in_halves(): void
    {
        $sale = app(SaleService::class)->complete(
            cashier: $this->cashier,
            register: $this->register,
            lines: [['product_unit_id' => $this->sachet->id, 'qty' => '2']],
            payments: [['method' => TenderType::Cash, 'amount_paisa' => 6_000]],
        );

        $this->actingAs($this->manager)
            ->post(route('sales.returns.store', $sale), $this->returnForm($sale, [
                'items' => [['sale_item_id' => $sale->items->first()->id, 'qty' => '0.5']],
            ]))
            ->assertSessionHasErrors('items.0.qty');

        $this->assertSame(0, SaleReturn::query()->count());
    }

    public function test_cash_cannot_be_handed_back_with_no_drawer_open(): void
    {
        $sale = $this->sellTwoCartons();

        /* The shift is counted out and closed: Rs. 5,000 opening plus the
           Rs. 8,000 that came in on the bill. */
        $session = DrawerSession::query()->open()->where('register_id', $this->register->id)->sole();
        app(DrawerService::class)->close($session, $this->manager, [5000 => 2, 1000 => 3], 500_000);

        $this->actingAs($this->manager)
            ->post(route('sales.returns.store', $sale), $this->returnForm($sale))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, SaleReturn::query()->count());
        $this->assertSame(1440 - 288, $this->product->fresh()->stock_qty_base, 'Nothing moved when the refund failed');
    }

    public function test_the_return_screen_shows_every_line_and_what_is_left_of_it(): void
    {
        $sale = $this->sellTwoCartons();

        $this->actingAs($this->manager)
            ->post(route('sales.returns.store', $sale), $this->returnForm($sale))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)
            ->get(route('sales.returns.create', $sale))
            ->assertOk()
            ->assertSee('Surf Excel')
            ->assertSee($sale->invoiceNumber());

        $this->actingAs($this->manager)
            ->get(route('sales.returns.show', SaleReturn::query()->sole()))
            ->assertOk()
            ->assertSee('SRT-000001');
    }

    public function test_a_cashier_cannot_take_goods_back(): void
    {
        $sale = $this->sellTwoCartons();

        $this->actingAs($this->cashier)
            ->get(route('sales.returns.create', $sale))
            ->assertForbidden();

        $this->actingAs($this->cashier)
            ->post(route('sales.returns.store', $sale), $this->returnForm($sale))
            ->assertForbidden();
    }

    public function test_a_cancelled_bill_has_nothing_left_to_return(): void
    {
        $sale = $this->sellTwoCartons();

        $this->actingAs($this->manager)
            ->post(route('sales.void', $sale), ['reason' => 'Rang up the wrong size'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)
            ->get(route('sales.returns.create', $sale))
            ->assertNotFound();
    }
}
