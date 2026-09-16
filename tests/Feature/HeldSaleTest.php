<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parking a basket while the customer runs back for something.
 *
 * A held basket takes no invoice number and moves nothing. Bringing it back
 * hands the basket to the till and removes it, so it cannot be taken twice.
 */
class HeldSaleTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductUnit $sachet;

    private ProductUnit $carton;

    private Register $register;

    private Customer $customer;

    private User $cashier;

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

        app(StockService::class)->record($this->product, 1440, MovementType::Opening, unitCostPaisa: 2_000);

        $this->register = Register::factory()->create();
        $this->customer = Customer::factory()->create(['name' => 'Aslam Bhai']);
        $this->cashier = User::factory()->cashier()->create();
    }

    private function holdABasket(): Sale
    {
        $this->actingAs($this->cashier)->postJson(route('pos.held.store'), [
            'register_id' => $this->register->id,
            'customer_id' => $this->customer->id,
            'bill_discount' => '10',
            'note' => 'Gone for eggs',
            'lines' => [
                ['product_unit_id' => $this->sachet->id, 'qty' => '2', 'discount' => '5'],
                ['product_unit_id' => $this->carton->id, 'qty' => '1'],
            ],
        ])->assertCreated()->assertJsonPath('held_count', 1);

        return Sale::query()->sole();
    }

    public function test_a_held_basket_takes_no_invoice_number_and_moves_no_stock(): void
    {
        $sale = $this->holdABasket();

        $this->assertSame(SaleStatus::Held, $sale->status);
        $this->assertNull($sale->invoice_no);
        $this->assertSame(406_000 - 500 - 1_000, $sale->total_paisa);
        $this->assertCount(2, $sale->items);
        $this->assertSame(1440, $this->product->fresh()->stock_qty_base);
        $this->assertSame(0, StockMovement::query()->where('type', MovementType::Sale)->count());
    }

    public function test_held_baskets_are_listed_for_every_counter(): void
    {
        $this->holdABasket();

        $this->actingAs(User::factory()->cashier()->create())
            ->getJson(route('pos.held.index'))
            ->assertOk()
            ->assertJsonCount(1, 'held')
            ->assertJsonPath('held.0.items', 2)
            ->assertJsonPath('held.0.note', 'Gone for eggs')
            ->assertJsonPath('held.0.customer', $this->customer->displayName());
    }

    public function test_bringing_a_basket_back_hands_it_to_the_till_and_takes_it_off_hold(): void
    {
        $sale = $this->holdABasket();

        $response = $this->actingAs($this->cashier)->postJson(route('pos.held.resume', $sale));

        $response->assertOk()
            ->assertJsonPath('message', 'Basket is back.')
            ->assertJsonPath('held_count', 0)
            ->assertJsonPath('cart.customer.id', $this->customer->id)
            ->assertJsonPath('cart.bill_discount', '10')
            ->assertJsonPath('cart.note', 'Gone for eggs')
            ->assertJsonCount(2, 'cart.lines');

        $lines = collect($response->json('cart.lines'))->keyBy('item.product_unit_id');

        $this->assertSame('2', $lines[$this->sachet->id]['qty']);
        $this->assertSame('5', $lines[$this->sachet->id]['discount']);
        $this->assertSame('1', $lines[$this->carton->id]['qty']);

        $this->assertModelMissing($sale);
    }

    public function test_a_basket_cannot_be_brought_back_twice(): void
    {
        $sale = $this->holdABasket();

        $this->actingAs($this->cashier)->postJson(route('pos.held.resume', $sale))->assertOk();

        $response = $this->actingAs($this->cashier)->postJson(route('pos.held.resume', $sale->id));

        $response->assertNotFound();
    }

    public function test_a_completed_sale_is_not_a_held_basket(): void
    {
        $sale = Sale::factory()->completed()->create(['register_id' => $this->register->id]);

        $response = $this->actingAs($this->cashier)->postJson(route('pos.held.resume', $sale));

        $response->assertUnprocessable();
        $this->assertStringContainsString('no longer on hold', $response->json('message'));
        $this->assertModelExists($sale);

        $this->actingAs($this->cashier)->deleteJson(route('pos.held.destroy', $sale))->assertUnprocessable();
        $this->assertModelExists($sale);
    }

    public function test_an_item_switched_off_while_the_basket_was_held_is_left_out(): void
    {
        $other = Product::factory()->create(['base_unit_id' => $this->product->base_unit_id]);
        $otherUnit = ProductUnit::factory()->base()->create([
            'product_id' => $other->id,
            'unit_id' => $this->product->base_unit_id,
        ]);

        $this->actingAs($this->cashier)->postJson(route('pos.held.store'), [
            'register_id' => $this->register->id,
            'lines' => [
                ['product_unit_id' => $this->sachet->id, 'qty' => '1'],
                ['product_unit_id' => $otherUnit->id, 'qty' => '1'],
            ],
        ])->assertCreated();

        $other->update(['is_active' => false]);

        $this->actingAs($this->cashier)
            ->postJson(route('pos.held.resume', Sale::query()->sole()))
            ->assertOk()
            ->assertJsonCount(1, 'cart.lines')
            ->assertJsonPath('cart.customer', null)
            ->assertJsonPath('message', 'Basket is back. One item is no longer on sale and was left out.');
    }

    public function test_a_held_basket_can_be_thrown_away(): void
    {
        $sale = $this->holdABasket();

        $this->actingAs($this->cashier)
            ->deleteJson(route('pos.held.destroy', $sale))
            ->assertOk()
            ->assertJsonPath('held_count', 0);

        $this->assertModelMissing($sale);
    }

    public function test_an_empty_basket_cannot_be_held(): void
    {
        $this->actingAs($this->cashier)
            ->postJson(route('pos.held.store'), ['register_id' => $this->register->id, 'lines' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');
    }
}
