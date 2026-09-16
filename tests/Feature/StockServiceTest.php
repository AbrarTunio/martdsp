<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The stock ledger and the moving average cost.
 *
 * The invariant under test throughout: the cached balance on `products` is
 * only ever a copy of what the ledger says, and replaying the ledger from
 * nothing must land on the same number. Everything else in the shop —
 * valuation, profit, reorder points — is downstream of that being true.
 */
class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private Product $product;

    private ProductUnit $sachet;

    private ProductUnit $carton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockService::class);

        $sachetUnit = Unit::factory()->sachet()->create();
        $cartonUnit = Unit::factory()->carton()->create();

        $this->product = Product::factory()->create([
            'name' => 'Surf Excel',
            'base_unit_id' => $sachetUnit->id,
        ]);

        $this->sachet = ProductUnit::factory()->create([
            'product_id' => $this->product->id,
            'unit_id' => $sachetUnit->id,
            'conversion_factor' => 1,
            'is_base' => true,
        ]);

        $this->carton = ProductUnit::factory()->create([
            'product_id' => $this->product->id,
            'unit_id' => $cartonUnit->id,
            'conversion_factor' => 288,
        ]);
    }

    public function test_a_movement_updates_the_cached_balance(): void
    {
        $this->stock->record($this->product, 100, MovementType::Opening, 1000);

        $this->assertSame(100, (int) $this->product->fresh()->stock_qty_base);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_each_movement_stamps_the_balance_it_left_behind(): void
    {
        $this->stock->record($this->product, 100, MovementType::Opening, 1000);
        $this->stock->record($this->product, -30, MovementType::Sale);
        $this->stock->record($this->product, 50, MovementType::Purchase, 1200);

        $balances = StockMovement::inLedgerOrder()->pluck('balance_after_base')->all();

        $this->assertSame([100, 70, 120], array_map('intval', $balances));
    }

    public function test_a_movement_of_nothing_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->stock->record($this->product, 0, MovementType::Adjustment);
    }

    public function test_stock_can_be_received_in_the_packaging_it_arrived_in(): void
    {
        $this->stock->receive($this->product, 2, $this->carton, MovementType::Purchase, 950);

        $this->assertSame(576, (int) $this->product->fresh()->stock_qty_base, '2 cartons of 288 sachets.');
        $this->assertSame($this->carton->id, StockMovement::first()->product_unit_id);
    }

    public function test_stock_issued_in_a_pack_leaves_in_base_units(): void
    {
        $this->stock->receive($this->product, 2, $this->carton, MovementType::Purchase, 950);
        $this->stock->issue($this->product, 1, $this->carton, MovementType::Sale);

        $this->assertSame(288, (int) $this->product->fresh()->stock_qty_base);
    }

    /**
     * The example the whole packaging model exists for: goods arrive by the
     * carton and leave by the sachet.
     */
    public function test_a_carton_in_and_sachets_out_reconcile(): void
    {
        $this->stock->receive($this->product, 1, $this->carton, MovementType::Purchase, 1000);
        $this->stock->issue($this->product, 12, $this->sachet, MovementType::Sale);

        $this->assertSame(276, (int) $this->product->fresh()->stock_qty_base);
    }

    public function test_the_first_delivery_sets_the_average_cost(): void
    {
        $this->stock->record($this->product, 100, MovementType::Purchase, 1000);

        $this->assertSame(1000, (int) $this->product->fresh()->avg_cost_base_paisa);
    }

    /**
     * 100 at Rs 10 and 100 at Rs 12 average Rs 11 — the number every margin
     * figure in the shop is then computed against.
     */
    public function test_a_second_delivery_moves_the_average(): void
    {
        $this->stock->record($this->product, 100, MovementType::Purchase, 1000);
        $this->stock->record($this->product, 100, MovementType::Purchase, 1200);

        $this->assertSame(1100, (int) $this->product->fresh()->avg_cost_base_paisa);
    }

    public function test_the_average_is_weighted_by_quantity_not_by_delivery(): void
    {
        $this->stock->record($this->product, 300, MovementType::Purchase, 1000);
        $this->stock->record($this->product, 100, MovementType::Purchase, 1400);

        $this->assertSame(1100, (int) $this->product->fresh()->avg_cost_base_paisa);
    }

    public function test_a_fractional_average_rounds_to_the_nearest_paisa(): void
    {
        $this->stock->record($this->product, 3, MovementType::Purchase, 1000);
        $this->stock->record($this->product, 1, MovementType::Purchase, 1001);

        /* (3 × 1000 + 1001) / 4 = 1000.25 → 1000. */
        $this->assertSame(1000, (int) $this->product->fresh()->avg_cost_base_paisa);

        $this->stock->record($this->product, 4, MovementType::Purchase, 1002);

        /* (4 × 1000 + 4 × 1002) / 8 = 1001. */
        $this->assertSame(1001, (int) $this->product->fresh()->avg_cost_base_paisa);
    }

    /**
     * What a sale costs the shop was settled when the goods arrived. If
     * selling could move the average, the margin on the next sale would
     * depend on how fast the last one was rung up.
     */
    public function test_selling_does_not_change_the_average_cost(): void
    {
        $this->stock->record($this->product, 100, MovementType::Purchase, 1000);
        $this->stock->record($this->product, -60, MovementType::Sale);

        $this->assertSame(1000, (int) $this->product->fresh()->avg_cost_base_paisa);
    }

    public function test_a_sale_is_costed_at_the_average_of_the_moment(): void
    {
        $this->stock->record($this->product, 100, MovementType::Purchase, 1000);
        $this->stock->record($this->product, 100, MovementType::Purchase, 1200);

        $sale = $this->stock->record($this->product, -10, MovementType::Sale);

        $this->assertSame(1100, (int) $sale->unit_cost_base_paisa);
        $this->assertSame(-11000, $sale->valuePaisa());
    }

    /**
     * A customer return comes back at the cost it left at. Re-averaging on a
     * return would let a refund quietly move the shop's cost base.
     */
    public function test_a_customer_return_does_not_revalue_the_stock(): void
    {
        $this->stock->record($this->product, 100, MovementType::Purchase, 1000);
        $this->stock->record($this->product, 5, MovementType::SaleReturn, 9999);

        $this->assertSame(1000, (int) $this->product->fresh()->avg_cost_base_paisa);
        $this->assertSame(105, (int) $this->product->fresh()->stock_qty_base);
    }

    public function test_stock_arriving_on_an_empty_shelf_takes_the_new_cost(): void
    {
        $this->stock->record($this->product, 100, MovementType::Purchase, 1000);
        $this->stock->record($this->product, -100, MovementType::Sale);
        $this->stock->record($this->product, 50, MovementType::Purchase, 1500);

        $this->assertSame(1500, (int) $this->product->fresh()->avg_cost_base_paisa);
    }

    public function test_a_count_writes_only_the_difference(): void
    {
        $this->stock->record($this->product, 100, MovementType::Opening, 1000);

        $movement = $this->stock->countTo($this->product->fresh(), 94);

        $this->assertNotNull($movement);
        $this->assertSame(-6, (int) $movement->qty_base);
        $this->assertSame(94, (int) $this->product->fresh()->stock_qty_base);
    }

    public function test_a_count_that_agrees_writes_nothing(): void
    {
        $this->stock->record($this->product, 100, MovementType::Opening, 1000);

        $this->assertNull($this->stock->countTo($this->product->fresh(), 100));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_a_movement_remembers_who_made_it(): void
    {
        $user = User::factory()->owner()->create();

        $this->actingAs($user);
        $this->stock->record($this->product, 10, MovementType::Adjustment, 100);

        $this->assertSame($user->id, StockMovement::first()->user_id);
    }

    public function test_a_movement_can_point_at_what_caused_it(): void
    {
        $adjustment = StockAdjustment::factory()->create();

        $this->stock->record($this->product, 10, MovementType::Adjustment, 100, reference: $adjustment);

        $movement = StockMovement::first();

        $this->assertSame($adjustment->getMorphClass(), $movement->reference_type);
        $this->assertSame($adjustment->id, (int) $movement->reference_id);
        $this->assertTrue($adjustment->movements()->exists());
    }

    public function test_the_balance_can_be_rebuilt_from_the_ledger_and_match(): void
    {
        $this->stock->record($this->product, 288, MovementType::Opening, 900);
        $this->stock->record($this->product, 576, MovementType::Purchase, 1000);
        $this->stock->record($this->product, -413, MovementType::Sale);
        $this->stock->record($this->product, -6, MovementType::Adjustment);
        $this->stock->record($this->product, 12, MovementType::SaleReturn);

        $before = $this->product->fresh();

        $result = $this->stock->rebuild($this->product->fresh());

        $this->assertFalse($result['drifted'], 'A rebuild of an honest ledger must change nothing.');
        $this->assertSame((int) $before->stock_qty_base, $result['balance_after']);
        $this->assertSame((int) $before->avg_cost_base_paisa, $result['average_after']);
    }

    public function test_a_rebuild_repairs_a_balance_that_was_tampered_with(): void
    {
        $this->stock->record($this->product, 100, MovementType::Opening, 1000);
        $this->stock->record($this->product, -30, MovementType::Sale);

        /* Something outside the service wrote the cache — the exact failure
           this command exists to catch. */
        $this->product->forceFill(['stock_qty_base' => 5, 'avg_cost_base_paisa' => 1])->save();

        $result = $this->stock->rebuild($this->product->fresh());

        $this->assertTrue($result['drifted']);
        $this->assertSame(70, $result['balance_after']);
        $this->assertSame(1000, $result['average_after']);
        $this->assertSame(70, (int) $this->product->fresh()->stock_qty_base);
    }

    public function test_a_rebuild_restamps_movement_rows_that_disagree(): void
    {
        $this->stock->record($this->product, 100, MovementType::Opening, 1000);
        $second = $this->stock->record($this->product, -30, MovementType::Sale);

        $second->forceFill(['balance_after_base' => 999])->save();

        $this->stock->rebuild($this->product->fresh());

        $this->assertSame(70, (int) $second->fresh()->balance_after_base);
    }

    public function test_a_rebuild_of_a_product_with_no_movements_leaves_it_empty(): void
    {
        $result = $this->stock->rebuild($this->product);

        $this->assertSame(0, $result['balance_after']);
        $this->assertSame(0, $result['movements']);
    }

    /**
     * Stock can legitimately go negative — a sale rung up before the delivery
     * was entered — and the ledger must keep working rather than refuse it.
     */
    public function test_stock_may_go_negative_and_still_reconcile(): void
    {
        $this->stock->record($this->product, -10, MovementType::Sale);

        $this->assertSame(-10, (int) $this->product->fresh()->stock_qty_base);

        $this->stock->record($this->product, 100, MovementType::Purchase, 1000);

        $this->assertSame(90, (int) $this->product->fresh()->stock_qty_base);
        $this->assertSame(1000, (int) $this->product->fresh()->avg_cost_base_paisa);

        $this->assertFalse($this->stock->rebuild($this->product->fresh())['drifted']);
    }

    public function test_the_ledger_reads_back_in_the_packaging_it_was_entered_in(): void
    {
        $this->stock->receive($this->product, 2, $this->carton, MovementType::Purchase, 950);

        $movement = StockMovement::with(['productUnit.unit', 'product.productUnits.unit'])->first();

        $this->assertSame('2 cartons', $movement->quantityInWords());
    }

    public function test_stock_value_is_quantity_times_average_cost(): void
    {
        $this->stock->record($this->product, 100, MovementType::Purchase, 1000);
        $this->stock->record($this->product, 100, MovementType::Purchase, 1200);

        $this->assertSame(220000, $this->product->fresh()->stockValuePaisa());
    }
}
