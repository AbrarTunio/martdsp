<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\BatchService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stock kept in layers.
 *
 * Milk that came in on Monday and milk that came in on Friday are not the same
 * milk, and the shopkeeper is only told which to push to the front if the
 * books keep them apart. These tests hold the two promises that makes: what
 * goes out goes out nearest-date-first, and the layers never quietly say the
 * shelf holds more than it does.
 */
class StockBatchTest extends TestCase
{
    use RefreshDatabase;

    private Product $milk;

    private ProductUnit $packet;

    private ProductUnit $carton;

    private Supplier $supplier;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $packetUnit = Unit::factory()->sachet()->create();
        $cartonUnit = Unit::factory()->carton()->create();

        $this->milk = Product::factory()->perishable()->create([
            'name' => "Olper's Milk",
            'base_unit_id' => $packetUnit->id,
        ]);

        $this->packet = ProductUnit::factory()->base()->create([
            'product_id' => $this->milk->id,
            'unit_id' => $packetUnit->id,
            'sale_price_paisa' => 250_00,
        ]);

        $this->carton = ProductUnit::factory()->holding(12)->create([
            'product_id' => $this->milk->id,
            'unit_id' => $cartonUnit->id,
            'sale_price_paisa' => 2_800_00,
        ]);

        $this->supplier = Supplier::factory()->create(['name' => 'Dairy Distributor']);
        $this->owner = User::factory()->owner()->create();
    }

    /**
     * Take a delivery in through the real screen, so the layer is opened the
     * way a shopkeeper opens one — by typing what is printed on the carton.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function takeIn(int $cartons, string $batchNo, string $expiry, string $unitCost = '2400.00', array $overrides = []): void
    {
        $this->actingAs($this->owner)
            ->post(route('purchases.store'), array_replace_recursive([
                'supplier_id' => $this->supplier->id,
                'invoice_no' => null,
                'purchase_date' => today()->toDateString(),
                'discount' => '',
                'tax' => '',
                'paid' => '',
                'payment_method' => 'cash',
                'note' => null,
                'action' => 'receive',
                'items' => [[
                    'product_id' => $this->milk->id,
                    'product_unit_id' => $this->carton->id,
                    'qty' => $cartons,
                    'bonus_qty' => '',
                    'unit_cost' => $unitCost,
                    'batch_no' => $batchNo,
                    'expiry_date' => $expiry,
                ]],
            ], $overrides))
            ->assertSessionHasNoErrors();
    }

    /**
     * Stock that was already on the shelf when the product was switched over
     * to batch tracking. No layer was written for it, because at the time
     * there were no layers.
     */
    private function stockTakenOnBeforeTracking(int $qtyBase): void
    {
        $this->milk->forceFill(['track_batches' => false, 'track_expiry' => false])->save();

        app(StockService::class)->record($this->milk, $qtyBase, MovementType::Opening, unitCostPaisa: 2000);

        $this->milk->forceFill(['track_batches' => true, 'track_expiry' => true])->save();
    }

    /**
     * @return array<int, array{batch: string|null, qty: int}>
     */
    private function layers(): array
    {
        return StockBatch::query()
            ->where('product_id', $this->milk->id)
            ->soonestFirst()
            ->get()
            ->map(fn (StockBatch $batch): array => [
                'batch' => $batch->batch_no,
                'qty' => $batch->qty_base,
            ])
            ->all();
    }

    public function test_a_delivery_opens_a_layer_carrying_the_number_and_date_off_the_carton(): void
    {
        $this->takeIn(5, 'a1-24', today()->addDays(20)->toDateString());

        $batch = StockBatch::sole();
        $this->milk->refresh();

        $this->assertSame('A1-24', $batch->batch_no, 'stored upper case, so "a1-24" and "A1-24" are one batch');
        $this->assertSame(today()->addDays(20)->toDateString(), $batch->expiry_date->toDateString());
        $this->assertSame(60, $batch->received_base, '5 cartons of 12 packets');
        $this->assertSame(60, $batch->qty_base);
        $this->assertSame(20_000, $batch->cost_base_paisa, 'Rs. 2,400 over 12 packets is Rs. 200 each');
        $this->assertSame(60, $this->milk->stock_qty_base, 'the shelf and the layers agree');
        $this->assertSame($this->milk->id, $batch->purchaseItem?->product_id, 'the layer points back at the line it came in on');
    }

    public function test_a_second_delivery_of_the_same_batch_joins_the_layer_already_open(): void
    {
        $expiry = today()->addDays(20)->toDateString();

        $this->takeIn(5, 'A1-24', $expiry);
        $this->takeIn(3, 'a1-24 ', $expiry);

        $batch = StockBatch::sole();

        $this->assertSame(96, $batch->received_base, '8 cartons in all');
        $this->assertSame(96, $batch->qty_base);
    }

    public function test_stock_leaves_the_layer_nearest_its_date_first(): void
    {
        /* The older milk is taken in second, the way a late delivery of
           short-dated stock really does turn up. */
        $this->takeIn(2, 'FRESH', today()->addDays(30)->toDateString());
        $this->takeIn(2, 'SHORT', today()->addDays(4)->toDateString());

        app(StockService::class)->record($this->milk, -20, MovementType::Sale);

        $this->assertSame([
            ['batch' => 'SHORT', 'qty' => 4],
            ['batch' => 'FRESH', 'qty' => 24],
        ], $this->layers(), 'the short-dated carton went first and the rest came off the fresh one');
    }

    public function test_a_sale_bigger_than_one_layer_runs_on_into_the_next(): void
    {
        $this->takeIn(1, 'SHORT', today()->addDays(4)->toDateString());
        $this->takeIn(2, 'FRESH', today()->addDays(30)->toDateString());

        app(StockService::class)->record($this->milk, -18, MovementType::Sale);

        $this->milk->refresh();

        $this->assertSame([
            ['batch' => 'SHORT', 'qty' => 0],
            ['batch' => 'FRESH', 'qty' => 18],
        ], $this->layers());
        $this->assertSame(18, $this->milk->stock_qty_base, 'the layers still add up to the shelf');
    }

    public function test_a_layer_with_no_date_waits_behind_every_dated_one(): void
    {
        /* Batch numbers kept, dates not — a wholesaler's line where the
           carton carries a number and nothing else. */
        $this->milk->forceFill(['track_expiry' => false])->save();

        $this->takeIn(2, 'NO-DATE', '');
        $this->takeIn(1, 'DATED', today()->addDays(60)->toDateString());

        app(StockService::class)->record($this->milk, -12, MovementType::Sale);

        $this->assertSame([
            ['batch' => 'DATED', 'qty' => 0],
            ['batch' => 'NO-DATE', 'qty' => 24],
        ], $this->layers(), 'nothing is pressing about an undated layer, so it is left alone');
    }

    public function test_goods_brought_back_go_onto_the_layer_nearest_its_date(): void
    {
        $this->takeIn(1, 'SHORT', today()->addDays(4)->toDateString());
        $this->takeIn(1, 'FRESH', today()->addDays(30)->toDateString());

        $stock = app(StockService::class);
        $stock->record($this->milk, -12, MovementType::Sale);
        $stock->record($this->milk, 2, MovementType::SaleReturn);

        $this->assertSame([
            ['batch' => 'SHORT', 'qty' => 2],
            ['batch' => 'FRESH', 'qty' => 12],
        ], $this->layers(), 'back onto the short-dated layer, so the expiry list is not flattered');
    }

    public function test_something_coming_back_goes_onto_the_layer_that_has_just_been_sold_out(): void
    {
        $this->takeIn(1, 'SHORT', today()->addDays(4)->toDateString());

        $stock = app(StockService::class);
        $stock->record($this->milk, -12, MovementType::Sale);
        $stock->record($this->milk, 3, MovementType::SaleReturn);

        $this->assertSame([['batch' => 'SHORT', 'qty' => 3]], $this->layers(), 'an hour ago it was the last packet off that layer');
    }

    public function test_something_coming_back_after_the_last_layer_has_expired_opens_a_fresh_one(): void
    {
        $this->takeIn(1, 'GONE', today()->addDays(2)->toDateString());

        $stock = app(StockService::class);
        $stock->record($this->milk, -12, MovementType::Sale);

        $this->travel(5)->days();

        $stock->record($this->milk, 3, MovementType::SaleReturn);

        $this->milk->refresh();

        $this->assertSame([
            ['batch' => 'GONE', 'qty' => 0],
            ['batch' => null, 'qty' => 3],
        ], $this->layers(), 'sellable goods are not put onto a layer that is already for binning');
        $this->assertSame(3, $this->milk->stock_qty_base, 'the layers still add up to the shelf');
    }

    public function test_stock_that_was_on_the_shelf_before_tracking_is_counted_but_not_layered(): void
    {
        $batches = app(BatchService::class);

        $this->stockTakenOnBeforeTracking(50);
        $this->takeIn(1, 'A1', today()->addDays(10)->toDateString());

        $this->milk->refresh();

        $this->assertSame(62, $this->milk->stock_qty_base);
        $this->assertSame(12, $batches->onLayers($this->milk));
        $this->assertSame(50, $batches->untrackedBase($this->milk), 'the 50 that predate the paperwork');
    }

    public function test_a_sale_is_never_refused_because_the_layers_cannot_cover_it(): void
    {
        $this->stockTakenOnBeforeTracking(50);
        $this->takeIn(1, 'A1', today()->addDays(10)->toDateString());

        app(StockService::class)->record($this->milk, -30, MovementType::Sale);

        $this->milk->refresh();

        $this->assertSame(32, $this->milk->stock_qty_base, 'the shelf is the truth and it went down by 30');
        $this->assertSame([['batch' => 'A1', 'qty' => 0]], $this->layers(), 'the layer gave what it had and the rest was let go');
    }

    public function test_a_product_that_is_not_tracked_keeps_no_layers_at_all(): void
    {
        $soap = Product::factory()->create(['name' => 'Lifebuoy']);

        app(StockService::class)->record($soap, 100, MovementType::Opening, unitCostPaisa: 5000);
        app(StockService::class)->record($soap, -10, MovementType::Sale);

        $this->assertSame(0, StockBatch::query()->where('product_id', $soap->id)->count());
        $this->assertSame(0, app(BatchService::class)->untrackedBase($soap->refresh()), 'nothing to account for, so nothing is missing');
    }

    public function test_goods_sent_back_to_the_supplier_come_off_the_short_dated_layer(): void
    {
        $this->takeIn(1, 'SHORT', today()->addDays(2)->toDateString());
        $this->takeIn(1, 'FRESH', today()->addDays(60)->toDateString());

        app(StockService::class)->record($this->milk, -12, MovementType::PurchaseReturn);

        $this->assertSame([
            ['batch' => 'SHORT', 'qty' => 0],
            ['batch' => 'FRESH', 'qty' => 12],
        ], $this->layers(), 'what goes back to a supplier is the stock that is about to go off');
    }

    public function test_the_expiry_screen_lists_what_is_going_off_soonest_first(): void
    {
        $this->takeIn(1, 'FRESH', today()->addDays(60)->toDateString());
        $this->takeIn(1, 'SOON', today()->addDays(3)->toDateString());

        $this->actingAs($this->owner)
            ->get(route('stock.expiry'))
            ->assertOk()
            ->assertSee('Olper&#039;s Milk', false)
            ->assertSee('SOON')
            ->assertSee('3 days left')
            /* Sixty days out is not this month's problem. */
            ->assertDontSee('FRESH');
    }

    public function test_the_expiry_screen_keeps_what_has_gone_off_on_its_own_list(): void
    {
        $this->takeIn(1, 'GONE', today()->addDays(2)->toDateString());
        $this->takeIn(1, 'SOON', today()->addDays(9)->toDateString());

        $this->travel(5)->days();

        $this->actingAs($this->owner)
            ->get(route('stock.expiry'))
            ->assertOk()
            ->assertSee('SOON')
            ->assertSee('1 batch is past its date')
            /* Binning work is a different list from selling work. */
            ->assertDontSee('GONE');

        $this->actingAs($this->owner)
            ->get(route('stock.expiry', ['view' => 'expired']))
            ->assertOk()
            ->assertSee('GONE')
            ->assertSee('Gone 3 days ago')
            ->assertDontSee('SOON');
    }

    public function test_a_sold_out_layer_never_shows_on_the_expiry_screen(): void
    {
        $this->takeIn(1, 'SOLD-OUT', today()->addDays(3)->toDateString());

        app(StockService::class)->record($this->milk, -12, MovementType::Sale);

        $this->actingAs($this->owner)
            ->get(route('stock.expiry'))
            ->assertOk()
            /* A batch with nothing left on the shelf cannot go off. */
            ->assertDontSee('SOLD-OUT');
    }

    public function test_a_cashier_sees_the_dates_but_not_what_they_cost(): void
    {
        $this->takeIn(1, 'SOON', today()->addDays(3)->toDateString());

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('stock.expiry'))
            ->assertOk()
            ->assertSee('SOON')
            ->assertSee('Batches watched')
            /* What stock cost is the owner's business. */
            ->assertDontSee('Money at risk');
    }

    public function test_a_layer_knows_how_long_it_has_and_what_it_is_worth(): void
    {
        $short = StockBatch::factory()->expiringIn(5)->create(['product_id' => $this->milk->id]);
        $gone = StockBatch::factory()->expired(3)->create(['product_id' => $this->milk->id]);
        $undated = StockBatch::factory()->undated()->create([
            'product_id' => $this->milk->id,
            'batch_no' => null,
        ]);

        $this->assertSame(5, $short->daysLeft());
        $this->assertFalse($short->hasExpired());
        $this->assertSame(-3, $gone->daysLeft());
        $this->assertTrue($gone->hasExpired());
        $this->assertNull($undated->daysLeft());
        $this->assertFalse($undated->hasExpired(), 'no date means nothing to be past');

        $this->assertSame(200_000, $short->valuePaisa(), '100 packets at Rs. 20');
        $this->assertSame($short->batch_no, $short->name());
        $this->assertStringContainsString(today()->format('d M Y'), $undated->name());
    }

    public function test_the_expiry_scopes_pick_out_what_is_going_off(): void
    {
        StockBatch::factory()->expiringIn(3)->create(['product_id' => $this->milk->id]);
        StockBatch::factory()->expiringIn(90)->create(['product_id' => $this->milk->id]);
        StockBatch::factory()->expired(1)->create(['product_id' => $this->milk->id]);
        StockBatch::factory()->expiringIn(2)->emptied()->create(['product_id' => $this->milk->id]);
        StockBatch::factory()->undated()->create(['product_id' => $this->milk->id]);

        $this->assertSame(3, StockBatch::query()->expiringWithin(7)->count(), 'the sold-out layer still counts as a row, the undated one never does');
        $this->assertSame(2, StockBatch::query()->open()->expiringWithin(7)->count());
        $this->assertSame(1, StockBatch::query()->expired()->count());
    }
}
