<?php

namespace Tests\Feature;

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentStatus;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correcting stock by hand.
 *
 * Two things are being defended here. The first is that a correction changes
 * nothing until it is posted, so a half-counted shelf can be walked away
 * from. The second is that the quantity is settled at the moment of posting
 * rather than the moment of typing — the shopkeeper counts the shelf, a
 * cashier sells two off it while they are still counting, and the recount
 * must not quietly put those two back.
 */
class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductUnit $sachet;

    private ProductUnit $carton;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $sachetUnit = Unit::factory()->sachet()->create();
        $cartonUnit = Unit::factory()->carton()->create();

        $this->product = Product::factory()->create([
            'name' => 'Surf Excel',
            'base_unit_id' => $sachetUnit->id,
        ]);

        $this->sachet = ProductUnit::factory()->base()->create([
            'product_id' => $this->product->id,
            'unit_id' => $sachetUnit->id,
        ]);

        $this->carton = ProductUnit::factory()->holding(288)->create([
            'product_id' => $this->product->id,
            'unit_id' => $cartonUnit->id,
        ]);

        $this->owner = User::factory()->owner()->create();
    }

    /**
     * The acceptance case: two cartons of opening stock at Rs. 4,320 each.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function openingStockForm(array $overrides = []): array
    {
        return array_replace_recursive([
            'reason' => AdjustmentReason::Opening->value,
            'note' => 'What was on the shelf on day one',
            'adjusted_at' => now()->format('Y-m-d\TH:i'),
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->carton->id,
                    'qty' => 2,
                    'unit_cost' => '4320.00',
                    'note' => null,
                ],
            ],
        ], $overrides);
    }

    public function test_opening_stock_is_entered_in_the_packaging_it_sits_in(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.adjustments.store'), $this->openingStockForm(['action' => 'post']))
            ->assertRedirect();

        $adjustment = StockAdjustment::sole();

        $this->assertSame(AdjustmentStatus::Posted, $adjustment->status);
        $this->assertSame(576, (int) $this->product->fresh()->stock_qty_base);

        /* Rs. 4,320 a carton of 288 is Rs. 15 a sachet. */
        $this->assertSame(1500, (int) $this->product->fresh()->avg_cost_base_paisa);
    }

    public function test_a_draft_changes_nothing_until_it_is_posted(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.adjustments.store'), $this->openingStockForm(['action' => 'draft']))
            ->assertRedirect();

        $this->assertSame(AdjustmentStatus::Draft, StockAdjustment::sole()->status);
        $this->assertSame(0, (int) $this->product->fresh()->stock_qty_base);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_posting_a_draft_writes_it_to_the_ledger(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.adjustments.store'), $this->openingStockForm(['action' => 'draft']));

        $adjustment = StockAdjustment::sole();

        $this->actingAs($this->owner)
            ->post(route('stock.adjustments.post', $adjustment))
            ->assertRedirect(route('stock.adjustments.show', $adjustment));

        $movement = StockMovement::sole();

        $this->assertSame(576, (int) $movement->qty_base);
        $this->assertSame(MovementType::Opening, $movement->type ?? null);
    }

    public function test_a_posted_correction_cannot_be_posted_twice(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.adjustments.store'), $this->openingStockForm(['action' => 'post']));

        $adjustment = StockAdjustment::sole();

        $this->actingAs($this->owner)
            ->post(route('stock.adjustments.post', $adjustment))
            ->assertSessionHasErrors('adjustment');

        $this->assertSame(576, (int) $this->product->fresh()->stock_qty_base);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_a_posted_correction_can_no_longer_be_edited(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.adjustments.store'), $this->openingStockForm(['action' => 'post']));

        $adjustment = StockAdjustment::sole();

        $this->actingAs($this->owner)
            ->get(route('stock.adjustments.edit', $adjustment))
            ->assertForbidden();
    }

    public function test_a_draft_can_be_edited_and_its_lines_replaced(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.adjustments.store'), $this->openingStockForm(['action' => 'draft']));

        $adjustment = StockAdjustment::sole();

        $this->actingAs($this->owner)
            ->get(route('stock.adjustments.edit', $adjustment))
            ->assertOk()
            ->assertSee('Surf Excel');

        $this->actingAs($this->owner)
            ->put(route('stock.adjustments.update', $adjustment), $this->openingStockForm([
                'action' => 'post',
                'items' => [['qty' => 3]],
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('stock_adjustment_items', 1);
        $this->assertSame(864, (int) $this->product->fresh()->stock_qty_base);
    }

    /**
     * The heart of it. The count is taken, a sale happens while the counting
     * is still going on, and the difference is worked out against the shelf
     * as it stands at the moment of posting.
     */
    public function test_a_recount_is_settled_against_the_shelf_at_the_moment_of_posting(): void
    {
        app(StockService::class)->record($this->product, 100, MovementType::Opening, 1000);

        $this->actingAs($this->owner)->post(route('stock.adjustments.store'), [
            'reason' => AdjustmentReason::Recount->value,
            'adjusted_at' => now()->format('Y-m-d\TH:i'),
            'action' => 'draft',
            'items' => [[
                'product_id' => $this->product->id,
                'product_unit_id' => $this->sachet->id,
                'qty' => 95,
            ]],
        ]);

        /* A sale is rung up between the count and the posting. */
        app(StockService::class)->record($this->product, -2, MovementType::Sale);

        $adjustment = StockAdjustment::sole();

        $this->actingAs($this->owner)->post(route('stock.adjustments.post', $adjustment));

        $this->assertSame(95, (int) $this->product->fresh()->stock_qty_base);

        $correction = StockMovement::where('type', MovementType::StockTake)->sole();

        /* 98 on the shelf, 95 counted: the correction is three, not five. */
        $this->assertSame(-3, (int) $correction->qty_base);
        $this->assertSame(98, (int) $adjustment->items()->sole()->system_qty_base);
    }

    public function test_a_recount_that_agrees_with_the_shelf_writes_nothing(): void
    {
        app(StockService::class)->record($this->product, 100, MovementType::Opening, 1000);

        $this->actingAs($this->owner)->post(route('stock.adjustments.store'), [
            'reason' => AdjustmentReason::Recount->value,
            'adjusted_at' => now()->format('Y-m-d\TH:i'),
            'action' => 'post',
            'items' => [[
                'product_id' => $this->product->id,
                'product_unit_id' => $this->sachet->id,
                'qty' => 100,
            ]],
        ]);

        $this->assertSame(AdjustmentStatus::Posted, StockAdjustment::sole()->status);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(100, (int) $this->product->fresh()->stock_qty_base);
    }

    public function test_a_recount_of_zero_is_accepted_as_an_empty_shelf(): void
    {
        app(StockService::class)->record($this->product, 40, MovementType::Opening, 1000);

        $this->actingAs($this->owner)->post(route('stock.adjustments.store'), [
            'reason' => AdjustmentReason::Recount->value,
            'adjusted_at' => now()->format('Y-m-d\TH:i'),
            'action' => 'post',
            'items' => [[
                'product_id' => $this->product->id,
                'product_unit_id' => $this->sachet->id,
                'qty' => 0,
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, (int) $this->product->fresh()->stock_qty_base);
    }

    public function test_a_loss_takes_stock_away_at_what_it_cost(): void
    {
        app(StockService::class)->record($this->product, 100, MovementType::Opening, 1000);

        $this->actingAs($this->owner)->post(route('stock.adjustments.store'), [
            'reason' => AdjustmentReason::Damage->value,
            'adjusted_at' => now()->format('Y-m-d\TH:i'),
            'action' => 'post',
            'items' => [[
                'product_id' => $this->product->id,
                'product_unit_id' => $this->sachet->id,
                'qty' => 10,
            ]],
        ]);

        $this->assertSame(90, (int) $this->product->fresh()->stock_qty_base);

        /* Written off at the cost it was carried at, not at a sale price. */
        $this->assertSame(-10000, (int) StockAdjustment::sole()->value_paisa);

        /* Taking stock out must never move the average. */
        $this->assertSame(1000, (int) $this->product->fresh()->avg_cost_base_paisa);
    }

    public function test_a_draft_can_be_cancelled_and_leaves_stock_alone(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.adjustments.store'), $this->openingStockForm(['action' => 'draft']));

        $adjustment = StockAdjustment::sole();

        $this->actingAs($this->owner)
            ->delete(route('stock.adjustments.destroy', $adjustment))
            ->assertRedirect(route('stock.adjustments.index'));

        $this->assertSame(AdjustmentStatus::Cancelled, $adjustment->fresh()->status);
        $this->assertSame(0, (int) $this->product->fresh()->stock_qty_base);
    }

    public function test_a_posted_correction_cannot_be_cancelled(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.adjustments.store'), $this->openingStockForm(['action' => 'post']));

        $adjustment = StockAdjustment::sole();

        $this->actingAs($this->owner)
            ->delete(route('stock.adjustments.destroy', $adjustment))
            ->assertSessionHasErrors('adjustment');

        $this->assertSame(AdjustmentStatus::Posted, $adjustment->fresh()->status);
    }

    public function test_the_same_item_cannot_be_listed_twice(): void
    {
        $this->actingAs($this->owner)->post(route('stock.adjustments.store'), $this->openingStockForm([
            'items' => [
                ['product_id' => $this->product->id, 'product_unit_id' => $this->sachet->id, 'qty' => 5, 'unit_cost' => '15.00'],
                ['product_id' => $this->product->id, 'product_unit_id' => $this->carton->id, 'qty' => 1, 'unit_cost' => '4320.00'],
            ],
        ]))->assertSessionHasErrors('items.1.product_id');

        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_a_size_belonging_to_another_product_is_refused(): void
    {
        $other = Product::factory()->create();
        $otherUnit = ProductUnit::factory()->base()->create(['product_id' => $other->id]);

        $this->actingAs($this->owner)->post(route('stock.adjustments.store'), $this->openingStockForm([
            'items' => [['product_unit_id' => $otherUnit->id]],
        ]))->assertSessionHasErrors('items.0.product_unit_id');
    }

    public function test_a_correction_with_nothing_on_it_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('stock.adjustments.store'), $this->openingStockForm([
            'items' => [['qty' => 0]],
        ]))->assertSessionHasErrors('items');
    }

    public function test_a_correction_cannot_be_dated_in_the_future(): void
    {
        $this->actingAs($this->owner)->post(route('stock.adjustments.store'), $this->openingStockForm([
            'adjusted_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ]))->assertSessionHasErrors('adjusted_at');
    }

    public function test_a_cashier_cannot_reach_the_corrections_area(): void
    {
        $cashier = User::factory()->cashier()->create();

        $adjustment = StockAdjustment::factory()->create(['user_id' => $this->owner->id]);

        $this->actingAs($cashier)->get(route('stock.adjustments.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('stock.adjustments.create'))->assertForbidden();
        $this->actingAs($cashier)->get(route('stock.adjustments.show', $adjustment))->assertForbidden();
        $this->actingAs($cashier)->post(route('stock.adjustments.store'), $this->openingStockForm())->assertForbidden();
        $this->actingAs($cashier)->post(route('stock.adjustments.post', $adjustment))->assertForbidden();
    }

    public function test_a_cashier_can_still_read_stock_levels(): void
    {
        $cashier = User::factory()->cashier()->create();

        $this->actingAs($cashier)->get(route('stock.index'))->assertOk();
        $this->actingAs($cashier)->get(route('stock.show', $this->product))->assertOk();
    }

    /**
     * A cashier seeing the margin on every item is a different conversation
     * from a cashier answering "do we have any left?".
     */
    public function test_cost_is_kept_from_a_cashier(): void
    {
        app(StockService::class)->record($this->product, 100, MovementType::Opening, 123400);

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('stock.show', $this->product))
            ->assertOk()
            ->assertDontSee('1,234.00');

        $this->actingAs($this->owner)
            ->get(route('stock.show', $this->product))
            ->assertOk()
            ->assertSee('1,234.00');
    }

    public function test_a_scan_returns_the_item_with_what_is_on_the_shelf(): void
    {
        app(StockService::class)->record($this->product, 576, MovementType::Opening, 1500);

        $this->actingAs($this->owner)
            ->getJson(route('stock.lookup', ['code' => $this->product->sku]))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('row.product_id', $this->product->id)
            ->assertJsonPath('row.stock_base', 576)
            ->assertJsonPath('row.stock_words', '2 cartons');
    }

    public function test_an_unknown_scan_says_so_rather_than_guessing(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('stock.lookup', ['code' => 'nothing-uses-this']))
            ->assertOk()
            ->assertJsonPath('found', false);
    }

    public function test_a_cashier_cannot_use_the_correction_lookup(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->getJson(route('stock.lookup', ['code' => $this->product->sku]))
            ->assertForbidden();
    }

    public function test_the_correction_appears_on_the_products_ledger(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.adjustments.store'), $this->openingStockForm(['action' => 'post']));

        $this->actingAs($this->owner)
            ->get(route('stock.show', $this->product))
            ->assertOk()
            ->assertSee('Opening stock')
            ->assertSee(StockAdjustment::sole()->reference);
    }
}
