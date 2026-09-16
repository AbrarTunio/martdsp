<?php

namespace Tests\Feature;

use App\Enums\CustomerEntryType;
use App\Enums\MovementType;
use App\Enums\SaleStatus;
use App\Enums\TenderType;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\DrawerService;
use App\Services\SaleService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cancelling a bill rung up by mistake.
 *
 * Only a supervisor can, and only on the day: the goods go back on the shelf
 * at the cost they left at, anything put on khata comes off, and the bill
 * stays on the list marked void with who did it and why.
 */
class SaleVoidTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

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

        ProductUnit::factory()->base()->create([
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
        $this->customer = Customer::factory()->create();
        $this->cashier = User::factory()->cashier()->create();
        $this->manager = User::factory()->manager()->create();

        app(DrawerService::class)->open($this->register, $this->manager, 500_000, null);
    }

    /**
     * One carton, half in cash and half on khata.
     */
    private function ringUpACarton(?User $cashier = null): Sale
    {
        return app(SaleService::class)->complete(
            cashier: $cashier ?? $this->cashier,
            register: $this->register,
            lines: [['product_unit_id' => $this->carton->id, 'qty' => '1']],
            payments: [
                ['method' => TenderType::Cash, 'amount_paisa' => 200_000],
                ['method' => TenderType::Khata, 'amount_paisa' => 200_000],
            ],
            customer: $this->customer,
        );
    }

    public function test_a_manager_voids_a_bill_and_the_stock_and_khata_go_back(): void
    {
        $sale = $this->ringUpACarton();

        $this->assertSame(1440 - 144, $this->product->fresh()->stock_qty_base);
        $this->assertSame(200_000, $this->customer->fresh()->balance_paisa);

        $this->actingAs($this->manager)
            ->post(route('sales.void', $sale), ['reason' => 'Rang up the wrong size'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('sales.show', $sale));

        $sale->refresh();

        $this->assertSame(SaleStatus::Void, $sale->status);
        $this->assertSame($this->manager->id, $sale->voided_by);
        $this->assertSame('Rang up the wrong size', $sale->void_reason);
        $this->assertNotNull($sale->voided_at);

        $this->assertSame(1440, $this->product->fresh()->stock_qty_base);

        $movement = StockMovement::query()->where('type', MovementType::SaleVoid)->sole();
        $this->assertSame(144, $movement->qty_base);
        $this->assertSame(2_000, $movement->unit_cost_base_paisa, 'Back on the shelf at the cost it left at');

        $this->assertSame(0, $this->customer->fresh()->balance_paisa);

        $entry = CustomerLedgerEntry::query()->where('type', CustomerEntryType::SaleVoid)->sole();
        $this->assertSame(200_000, $entry->credit_paisa);

        $this->actingAs($this->manager)
            ->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('Rang up the wrong size');
    }

    public function test_a_voided_bill_cannot_be_voided_again(): void
    {
        $sale = $this->ringUpACarton();

        $this->actingAs($this->manager)->post(route('sales.void', $sale), ['reason' => 'Mistake']);
        $this->actingAs($this->manager)
            ->post(route('sales.void', $sale), ['reason' => 'Mistake again'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(1440, $this->product->fresh()->stock_qty_base, 'The stock went back only once');
        $this->assertSame(1, StockMovement::query()->where('type', MovementType::SaleVoid)->count());
    }

    public function test_a_cashier_cannot_void(): void
    {
        $sale = $this->ringUpACarton();

        $this->actingAs($this->cashier)
            ->post(route('sales.void', $sale), ['reason' => 'Mistake'])
            ->assertForbidden();

        $this->assertSame(SaleStatus::Completed, $sale->fresh()->status);
    }

    public function test_a_void_needs_a_reason(): void
    {
        $sale = $this->ringUpACarton();

        $this->actingAs($this->manager)
            ->post(route('sales.void', $sale), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(SaleStatus::Completed, $sale->fresh()->status);
    }

    public function test_yesterdays_bill_cannot_be_voided(): void
    {
        $sale = $this->ringUpACarton();
        $sale->forceFill(['sold_at' => now()->subDay()])->save();

        $this->actingAs($this->manager)
            ->post(route('sales.void', $sale), ['reason' => 'Too late'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(SaleStatus::Completed, $sale->fresh()->status);
        $this->assertSame(1440 - 144, $this->product->fresh()->stock_qty_base);
    }

    public function test_a_cashier_cannot_open_another_cashiers_bill(): void
    {
        $sale = $this->ringUpACarton(User::factory()->cashier()->create());

        $this->actingAs($this->cashier)->get(route('sales.show', $sale))->assertForbidden();
        $this->actingAs($this->cashier)->get(route('sales.receipt', $sale))->assertForbidden();

        $own = $this->ringUpACarton();

        $this->actingAs($this->cashier)->get(route('sales.show', $own))->assertOk();
        $this->actingAs($this->cashier)->get(route('sales.receipt', $own))->assertOk();
    }

    public function test_a_held_basket_has_no_bill_page(): void
    {
        $held = Sale::factory()->held()->create(['register_id' => $this->register->id]);

        $this->actingAs($this->manager)->get(route('sales.show', $held))->assertNotFound();
        $this->actingAs($this->manager)->get(route('sales.receipt', $held))->assertNotFound();
    }
}
