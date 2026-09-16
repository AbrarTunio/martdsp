<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The command that proves the cached balance is only ever a copy.
 *
 * This is the acceptance criterion for the whole inventory phase: every
 * change is a movement row, and the balance can be rebuilt from nothing and
 * match. `--check` exists so the question can be asked of a live shop without
 * writing anything back.
 */
class RecalculateStockCommandTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockService::class);

        $sachet = Unit::factory()->sachet()->create();

        $this->product = Product::factory()->create([
            'name' => 'Surf Excel',
            'base_unit_id' => $sachet->id,
        ]);

        ProductUnit::factory()->base()->create([
            'product_id' => $this->product->id,
            'unit_id' => $sachet->id,
        ]);
    }

    private function tamperWithTheCache(int $qtyBase, int $avgPaisa = 999): void
    {
        $this->product->forceFill([
            'stock_qty_base' => $qtyBase,
            'avg_cost_base_paisa' => $avgPaisa,
        ])->save();
    }

    public function test_an_honest_ledger_needs_no_correcting(): void
    {
        $this->stock->record($this->product, 100, MovementType::Opening, 1000);
        $this->stock->record($this->product, -30, MovementType::Sale);

        $this->artisan('stock:recalculate')
            ->expectsOutputToContain('agree with the ledger')
            ->assertSuccessful();

        $this->assertSame(70, (int) $this->product->fresh()->stock_qty_base);
    }

    public function test_a_drifted_cache_is_rebuilt_from_the_movements(): void
    {
        $this->stock->record($this->product, 100, MovementType::Opening, 1000);
        $this->stock->record($this->product, -30, MovementType::Sale);

        $this->tamperWithTheCache(5);

        $this->artisan('stock:recalculate')
            ->expectsOutputToContain('had drifted')
            ->assertSuccessful();

        $this->assertSame(70, (int) $this->product->fresh()->stock_qty_base);
        $this->assertSame(1000, (int) $this->product->fresh()->avg_cost_base_paisa);
    }

    public function test_check_reports_drift_without_touching_anything(): void
    {
        $this->stock->record($this->product, 100, MovementType::Opening, 1000);

        $this->tamperWithTheCache(5);

        /* A non-zero exit so a scheduled check is noticed rather than
           scrolling past in a log nobody reads. */
        $this->artisan('stock:recalculate --check')->assertFailed();

        $this->assertSame(5, (int) $this->product->fresh()->stock_qty_base);
    }

    public function test_check_is_quiet_and_successful_when_everything_agrees(): void
    {
        $this->stock->record($this->product, 100, MovementType::Opening, 1000);

        $this->artisan('stock:recalculate --check')->assertSuccessful();
    }

    public function test_one_product_can_be_rebuilt_on_its_own(): void
    {
        $other = Product::factory()->create();
        ProductUnit::factory()->base()->create(['product_id' => $other->id]);

        $this->stock->record($this->product, 100, MovementType::Opening, 1000);
        $this->stock->record($other, 40, MovementType::Opening, 800);

        $this->product->forceFill(['stock_qty_base' => 5])->save();
        $other->forceFill(['stock_qty_base' => 5])->save();

        $this->artisan('stock:recalculate --product='.$this->product->sku)->assertSuccessful();

        $this->assertSame(100, (int) $this->product->fresh()->stock_qty_base);
        $this->assertSame(5, (int) $other->fresh()->stock_qty_base, 'Only the named product should have been touched.');
    }

    public function test_an_unknown_product_is_reported_rather_than_silently_doing_nothing(): void
    {
        $this->artisan('stock:recalculate --product=SM-NOPE')
            ->expectsOutputToContain('No products matched')
            ->assertFailed();
    }

    /**
     * A delivery entered the morning after it arrived is dated the night
     * before. The average the shop used was worked out when it was entered,
     * after the day's sales, and a rebuild has to reach the same figure rather
     * than replaying it into the middle of those sales and calling the
     * difference drift.
     */
    public function test_a_delivery_entered_late_rebuilds_to_the_same_average(): void
    {
        $this->stock->record($this->product, 100, MovementType::Opening, 1000, occurredAt: now()->subDays(2));
        $this->stock->record($this->product, -90, MovementType::Sale, occurredAt: now()->subHours(3));
        $this->stock->record($this->product, 100, MovementType::Purchase, 2000, occurredAt: now()->subDay());

        /* (10 × 1000 + 100 × 2000) ÷ 110, rounded to the paisa. */
        $this->assertSame(1909, (int) $this->product->fresh()->avg_cost_base_paisa);

        $this->artisan('stock:recalculate --check')->assertSuccessful();
        $this->artisan('stock:recalculate')->expectsOutputToContain('agree with the ledger')->assertSuccessful();

        $this->assertSame(110, (int) $this->product->fresh()->stock_qty_base);
        $this->assertSame(1909, (int) $this->product->fresh()->avg_cost_base_paisa);
    }

    /**
     * A product with no movements at all is a real state — it has been added
     * to the catalogue but never stocked — and must rebuild to zero rather
     * than being skipped.
     */
    public function test_a_product_that_never_moved_rebuilds_to_nothing(): void
    {
        $this->tamperWithTheCache(42, 700);

        $this->artisan('stock:recalculate')->assertSuccessful();

        $this->assertSame(0, (int) $this->product->fresh()->stock_qty_base);
        $this->assertSame(0, (int) $this->product->fresh()->avg_cost_base_paisa);
    }
}
