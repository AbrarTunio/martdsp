<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\StockTakeStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\Unit;
use App\Models\User;
use App\Services\BatchService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Counting the shelves.
 *
 * What is being defended: a count changes nothing until it is posted; when
 * it is posted, each item is measured against the books as they stood when
 * it was scanned, so selling carries on through the count without a sale
 * showing up as missing or found; and a section swept with "anything not
 * scanned is gone" finds the stock that is on the books but not on the
 * shelf — which is the stock a thief took.
 */
class StockTakeTest extends TestCase
{
    use RefreshDatabase;

    private Category $dairy;

    private Product $milk;

    private ProductUnit $packet;

    private ProductUnit $carton;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $packetUnit = Unit::factory()->sachet()->create();
        $cartonUnit = Unit::factory()->carton()->create();

        $this->dairy = Category::factory()->create(['name' => 'Dairy']);

        $this->milk = Product::factory()->create([
            'name' => "Olper's Milk",
            'category_id' => $this->dairy->id,
            'base_unit_id' => $packetUnit->id,
        ]);

        $this->packet = ProductUnit::factory()->base()->create([
            'product_id' => $this->milk->id,
            'unit_id' => $packetUnit->id,
        ]);

        $this->carton = ProductUnit::factory()->holding(12)->create([
            'product_id' => $this->milk->id,
            'unit_id' => $cartonUnit->id,
        ]);

        $this->owner = User::factory()->owner()->create();

        /* 30 packets on the books at Rs. 200 each. */
        app(StockService::class)->record($this->milk, 30, MovementType::Opening, 200_00);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sheet(array $items, array $overrides = []): array
    {
        return array_replace([
            'name' => null,
            'category_id' => null,
            'missing_are_zero' => '0',
            'note' => null,
            'items' => $items,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(Product $product, ProductUnit $productUnit, int $qty): array
    {
        return [
            'product_id' => $product->id,
            'product_unit_id' => $productUnit->id,
            'qty' => $qty,
        ];
    }

    /**
     * An item in the dairy section (or any other), with a single size and a
     * balance already on the books.
     */
    private function anotherItem(string $name, ?Category $category, int $onTheBooks): array
    {
        $unit = Unit::factory()->create();

        $product = Product::factory()->create([
            'name' => $name,
            'category_id' => $category?->id,
            'base_unit_id' => $unit->id,
        ]);

        $productUnit = ProductUnit::factory()->base()->create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
        ]);

        if ($onTheBooks !== 0) {
            app(StockService::class)->record($product, $onTheBooks, MovementType::Opening, 50_00);
        }

        return [$product->fresh(), $productUnit];
    }

    public function test_a_saved_count_changes_nothing_until_it_is_posted(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([$this->line($this->milk, $this->carton, 2)]) + ['action' => 'draft'])
            ->assertRedirect();

        $take = StockTake::sole();

        $this->assertSame('STK-000001', $take->reference);
        $this->assertSame(StockTakeStatus::Draft, $take->status);
        $this->assertSame(24, $take->items()->sole()->counted_base);
        $this->assertSame(30, (int) $this->milk->fresh()->stock_qty_base);
        $this->assertSame(0, StockMovement::where('type', MovementType::StockTake)->count());
    }

    public function test_posting_sets_stock_to_what_was_counted(): void
    {
        /* Two cartons and three loose packets: 27 on the shelf, 30 on the books. */
        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([$this->line($this->milk, $this->packet, 27)]) + ['action' => 'post'])
            ->assertRedirect();

        $take = StockTake::sole();
        $item = $take->items()->sole();

        $this->assertSame(StockTakeStatus::Posted, $take->status);
        $this->assertSame($this->owner->id, $take->posted_by);
        $this->assertNotNull($take->posted_at);

        $this->assertSame(27, (int) $this->milk->fresh()->stock_qty_base);

        $this->assertSame(30, $item->system_qty_base);
        $this->assertSame(-3, $item->variance_base);
        $this->assertSame(-600_00, $item->variance_value_paisa);
        $this->assertSame(-600_00, $take->variance_value_paisa);

        $movement = StockMovement::where('type', MovementType::StockTake)->sole();

        $this->assertSame(-3, (int) $movement->qty_base);
        $this->assertTrue($movement->reference->is($take));
        $this->assertSame($this->owner->id, $movement->user_id);
    }

    public function test_a_surplus_comes_in_without_moving_the_cost(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([$this->line($this->milk, $this->carton, 3)]) + ['action' => 'post']);

        $milk = $this->milk->fresh();

        $this->assertSame(36, (int) $milk->stock_qty_base);
        $this->assertSame(200_00, (int) $milk->avg_cost_base_paisa);
        $this->assertSame(1_200_00, StockTake::sole()->variance_value_paisa);
    }

    public function test_a_count_that_matches_the_books_writes_nothing_to_the_ledger(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([$this->line($this->milk, $this->packet, 30)]) + ['action' => 'post']);

        $this->assertSame(StockTakeStatus::Posted, StockTake::sole()->status);
        $this->assertSame(0, StockMovement::where('type', MovementType::StockTake)->count());
        $this->assertSame(0, StockTake::sole()->variance_value_paisa);
    }

    /**
     * The shop does not close for a count. Packets sold after the shelf was
     * scanned are already off the books by the time the count is posted, and
     * must not be written off a second time as missing.
     */
    public function test_a_sale_after_an_item_was_counted_is_neither_missing_nor_counted_twice(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([$this->line($this->milk, $this->packet, 30)]) + ['action' => 'draft']);

        /* Four sold after the scan: off the shelf and off the books together,
           so the shelf now holds 26 and so do the books. Held against the
           books at posting, the 30 on the sheet would look like four found;
           held against the books when it was counted, it matches. */
        $this->travel(5)->minutes();
        app(StockService::class)->record($this->milk, -4, MovementType::Sale);

        $take = StockTake::sole();
        $this->actingAs($this->owner)->post(route('stock.takes.post', $take))->assertRedirect();

        $this->assertSame(26, (int) $this->milk->fresh()->stock_qty_base);
        $this->assertSame(30, (int) $take->items()->sole()->system_qty_base);
        $this->assertSame(0, (int) $take->items()->sole()->variance_base);
        $this->assertSame(0, StockMovement::where('type', MovementType::StockTake)->count());
    }

    public function test_a_shortfall_is_measured_at_the_moment_it_was_counted(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([$this->line($this->milk, $this->packet, 25)]) + ['action' => 'draft']);

        /* 25 on the shelf against 30 on the books when counted: five gone.
           Two more sold later are the till's business, not the count's. */
        $this->travel(5)->minutes();
        app(StockService::class)->record($this->milk, -2, MovementType::Sale);

        $take = StockTake::sole();
        $this->actingAs($this->owner)->post(route('stock.takes.post', $take));

        $this->assertSame(23, (int) $this->milk->fresh()->stock_qty_base);
        $this->assertSame(30, (int) $take->items()->sole()->system_qty_base);
        $this->assertSame(-5, (int) StockMovement::where('type', MovementType::StockTake)->sole()->qty_base);
    }

    public function test_the_scan_time_comes_from_the_shop_and_carries_through_a_save(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('stock.lookup', ['code' => $this->milk->sku]))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('scanned_at', now()->toIso8601String());

        $scannedAt = now()->subMinutes(10);

        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([
                $this->line($this->milk, $this->packet, 30) + ['counted_at' => $scannedAt->toIso8601String()],
            ]) + ['action' => 'draft']);

        $take = StockTake::sole();

        $this->assertSame($scannedAt->timestamp, $take->items()->sole()->counted_at->timestamp);
        $this->assertSame($scannedAt->timestamp, $take->started_at->timestamp);

        /* Carrying on keeps it, and the form hands it back. */
        $this->actingAs($this->owner)
            ->get(route('stock.takes.edit', $take))
            ->assertViewHas('rows', fn (array $rows): bool => $rows[0]['counted_at'] === $take->items()->sole()->counted_at->toIso8601String());
    }

    public function test_a_scan_time_cannot_reach_outside_the_count(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([
                $this->line($this->milk, $this->packet, 30) + ['counted_at' => now()->addDay()->toIso8601String()],
            ]) + ['action' => 'draft']);

        $take = StockTake::sole();

        /* From the future: held at now. */
        $this->assertSame(now()->timestamp, $take->items()->sole()->counted_at->timestamp);

        /* From before the count began: held at its start. */
        $this->travel(1)->hour();

        $this->actingAs($this->owner)
            ->put(route('stock.takes.update', $take), $this->sheet([
                $this->line($this->milk, $this->packet, 30) + ['counted_at' => now()->subYear()->toIso8601String()],
            ]) + ['action' => 'draft']);

        $this->assertSame($take->started_at->timestamp, $take->items()->sole()->counted_at->timestamp);
    }

    public function test_sweeping_a_section_zeroes_what_was_not_scanned_in_it_and_its_subsections(): void
    {
        $yoghurt = Category::factory()->childOf($this->dairy)->create(['name' => 'Yoghurt']);
        $snacks = Category::factory()->create(['name' => 'Snacks']);

        [$cheese] = $this->anotherItem('Cheese Slices', $this->dairy, 8);
        [$dahi] = $this->anotherItem('Nestle Dahi', $yoghurt, 5);
        [$chips] = $this->anotherItem('Lays', $snacks, 40);
        [$butter] = $this->anotherItem('Butter', $this->dairy, 0);

        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet(
                [$this->line($this->milk, $this->packet, 30)],
                ['category_id' => $this->dairy->id, 'missing_are_zero' => '1'],
            ) + ['action' => 'post'])
            ->assertSessionHasNoErrors();

        $take = StockTake::sole();

        $this->assertSame(0, (int) $cheese->fresh()->stock_qty_base);
        $this->assertSame(0, (int) $dahi->fresh()->stock_qty_base);

        /* Another section is not touched, and an item already at none is not
           padded onto the sheet. */
        $this->assertSame(40, (int) $chips->fresh()->stock_qty_base);
        $this->assertFalse($take->items()->where('product_id', $butter->id)->exists());

        $notFound = $take->items()->where('was_counted', false)->pluck('product_id')->sort()->values()->all();
        $this->assertSame(collect([$cheese->id, $dahi->id])->sort()->values()->all(), $notFound);

        $this->assertStringContainsString('Not found on STK-000001',
            (string) StockMovement::where('product_id', $cheese->id)->where('type', MovementType::StockTake)->sole()->note);
    }

    public function test_a_count_without_a_section_leaves_unscanned_items_alone(): void
    {
        [$cheese] = $this->anotherItem('Cheese Slices', $this->dairy, 8);

        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([$this->line($this->milk, $this->packet, 30)]) + ['action' => 'post']);

        $this->assertSame(8, (int) $cheese->fresh()->stock_qty_base);
    }

    public function test_sweeping_needs_a_section(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet(
                [$this->line($this->milk, $this->packet, 30)],
                ['missing_are_zero' => '1'],
            ))
            ->assertSessionHasErrors('missing_are_zero');

        $this->assertDatabaseCount('stock_takes', 0);
    }

    public function test_a_shortfall_on_a_batch_tracked_item_comes_off_the_nearest_date_first(): void
    {
        $yoghurtUnit = Unit::factory()->create();
        $yoghurt = Product::factory()->perishable()->create(['name' => 'Yoghurt Cup', 'base_unit_id' => $yoghurtUnit->id]);
        $cup = ProductUnit::factory()->base()->create(['product_id' => $yoghurt->id, 'unit_id' => $yoghurtUnit->id]);

        foreach ([['B-LATE', 20, 12], ['B-SOON', 5, 10]] as [$batchNo, $days, $qty]) {
            app(StockService::class)->record($yoghurt, $qty, MovementType::Purchase, 80_00);
            app(BatchService::class)->receive(
                product: $yoghurt,
                qtyBase: $qty,
                costBasePaisa: 80_00,
                batchNo: $batchNo,
                expiryDate: today()->addDays($days)->toDateString(),
            );
        }

        /* 22 on the books, 15 on the shelf: seven gone. */
        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([$this->line($yoghurt, $cup, 15)]) + ['action' => 'post'])
            ->assertSessionHasNoErrors();

        $this->assertSame(15, (int) $yoghurt->fresh()->stock_qty_base);
        $this->assertSame(3, (int) StockBatch::where('batch_no', 'B-SOON')->value('qty_base'));
        $this->assertSame(12, (int) StockBatch::where('batch_no', 'B-LATE')->value('qty_base'));
    }

    public function test_a_count_cannot_be_posted_twice(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([$this->line($this->milk, $this->packet, 27)]) + ['action' => 'post']);

        $take = StockTake::sole();

        $this->actingAs($this->owner)
            ->from(route('stock.takes.show', $take))
            ->post(route('stock.takes.post', $take))
            ->assertSessionHasErrors('take');

        $this->assertSame(1, StockMovement::where('type', MovementType::StockTake)->count());
        $this->assertSame(27, (int) $this->milk->fresh()->stock_qty_base);
    }

    public function test_a_posted_count_can_no_longer_be_edited(): void
    {
        $take = StockTake::factory()->posted()->create(['user_id' => $this->owner->id]);

        $this->actingAs($this->owner)->get(route('stock.takes.edit', $take))->assertForbidden();
        $this->actingAs($this->owner)
            ->put(route('stock.takes.update', $take), $this->sheet([$this->line($this->milk, $this->packet, 1)]))
            ->assertForbidden();
    }

    public function test_a_count_with_nothing_on_it_cannot_be_posted(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([]) + ['action' => 'draft'])
            ->assertRedirect();

        $take = StockTake::sole();

        $this->actingAs($this->owner)
            ->from(route('stock.takes.show', $take))
            ->post(route('stock.takes.post', $take))
            ->assertSessionHasErrors('take');

        $this->assertSame(StockTakeStatus::Draft, $take->fresh()->status);
    }

    public function test_carrying_on_replaces_the_lines(): void
    {
        [$cheese, $slice] = $this->anotherItem('Cheese Slices', $this->dairy, 8);

        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([$this->line($this->milk, $this->packet, 10)]) + ['action' => 'draft']);

        $take = StockTake::sole();

        $this->actingAs($this->owner)
            ->get(route('stock.takes.edit', $take))
            ->assertOk()
            ->assertViewHas('rows', fn (array $rows): bool => count($rows) === 1 && $rows[0]['name'] === "Olper's Milk");

        $this->actingAs($this->owner)
            ->put(route('stock.takes.update', $take), $this->sheet([
                $this->line($this->milk, $this->carton, 2),
                $this->line($cheese, $slice, 7),
            ]) + ['action' => 'draft'])
            ->assertRedirect(route('stock.takes.show', $take));

        $this->assertSame(2, $take->items()->count());
        $this->assertSame(24, $take->items()->where('product_id', $this->milk->id)->value('counted_base'));
        $this->assertSame(30, (int) $this->milk->fresh()->stock_qty_base);
    }

    public function test_an_open_count_can_be_abandoned_and_changes_nothing(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([$this->line($this->milk, $this->packet, 3)]) + ['action' => 'draft']);

        $take = StockTake::sole();

        $this->actingAs($this->owner)
            ->delete(route('stock.takes.destroy', $take))
            ->assertRedirect(route('stock.takes.index'));

        $this->assertSame(StockTakeStatus::Cancelled, $take->fresh()->status);
        $this->assertSame(30, (int) $this->milk->fresh()->stock_qty_base);

        $this->actingAs($this->owner)
            ->from(route('stock.takes.show', $take))
            ->post(route('stock.takes.post', $take))
            ->assertSessionHasErrors('take');
    }

    public function test_a_posted_count_cannot_be_abandoned(): void
    {
        $take = StockTake::factory()->posted()->create(['user_id' => $this->owner->id]);

        $this->actingAs($this->owner)
            ->from(route('stock.takes.show', $take))
            ->delete(route('stock.takes.destroy', $take))
            ->assertSessionHasErrors('take');

        $this->assertSame(StockTakeStatus::Posted, $take->fresh()->status);
    }

    public function test_the_same_item_cannot_be_on_the_count_twice(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([
                $this->line($this->milk, $this->packet, 3),
                $this->line($this->milk, $this->carton, 1),
            ]))
            ->assertSessionHasErrors('items.1.product_id');
    }

    public function test_a_size_belonging_to_another_item_is_refused(): void
    {
        [$cheese] = $this->anotherItem('Cheese Slices', $this->dairy, 8);

        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([$this->line($cheese, $this->carton, 1)]))
            ->assertSessionHasErrors('items.0.product_unit_id');
    }

    public function test_a_cashier_cannot_count_or_see_counts(): void
    {
        $cashier = User::factory()->cashier()->create();
        $take = StockTake::factory()->create(['user_id' => $this->owner->id]);

        $this->actingAs($cashier)->get(route('stock.takes.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('stock.takes.create'))->assertForbidden();
        $this->actingAs($cashier)->get(route('stock.takes.show', $take))->assertForbidden();
        $this->actingAs($cashier)->post(route('stock.takes.store'), $this->sheet([]))->assertForbidden();
        $this->actingAs($cashier)->post(route('stock.takes.post', $take))->assertForbidden();
        $this->actingAs($cashier)->delete(route('stock.takes.destroy', $take))->assertForbidden();
    }

    public function test_the_posted_count_shows_what_was_short_and_what_it_cost(): void
    {
        [$cheese] = $this->anotherItem('Cheese Slices', $this->dairy, 8);

        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet(
                [$this->line($this->milk, $this->packet, 27)],
                ['category_id' => $this->dairy->id, 'missing_are_zero' => '1', 'name' => 'Dairy fridge'],
            ) + ['action' => 'post']);

        $take = StockTake::sole();

        $this->actingAs($this->owner)
            ->get(route('stock.takes.show', $take))
            ->assertOk()
            ->assertSee('Dairy fridge')
            ->assertSee("Olper's Milk")
            ->assertSee('Cheese Slices')
            ->assertSee('Not found')
            ->assertSee('Overall difference');

        $this->actingAs($this->owner)
            ->get(route('stock.takes.index'))
            ->assertOk()
            ->assertSee('STK-000001')
            ->assertSee('Dairy fridge');
    }

    public function test_an_open_count_shows_what_has_been_scanned_so_far(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([$this->line($this->milk, $this->carton, 2)]) + ['action' => 'draft']);

        $this->actingAs($this->owner)
            ->get(route('stock.takes.show', StockTake::sole()))
            ->assertOk()
            ->assertSee('Still counting')
            ->assertSee('2 cartons')
            ->assertSee('Carry on counting');
    }

    public function test_the_count_appears_on_the_items_ledger(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stock.takes.store'), $this->sheet([$this->line($this->milk, $this->packet, 27)]) + ['action' => 'post']);

        $this->actingAs($this->owner)
            ->get(route('stock.show', $this->milk))
            ->assertOk()
            ->assertSee('Stock take');
    }
}
