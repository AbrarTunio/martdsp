<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\TenderType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\DrawerService;
use App\Services\PrintService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Selling when the line to the shop computer is down.
 *
 * The till keeps the bill and sends it later. Two things must hold for that to
 * be safe: a bill sent twice must only ever be charged once, and a bill that
 * arrives late must land in today's takings at the moment it is recorded, not
 * at the moment it was rung up, or the drawer count will never agree.
 */
class OfflineSaleTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductUnit $sachet;

    private Register $register;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $sachetUnit = Unit::factory()->sachet()->create();

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

        app(StockService::class)->record($this->product, 500, MovementType::Opening, unitCostPaisa: 2_000);

        $this->register = Register::factory()->create();
        $this->cashier = User::factory()->cashier()->create();

        app(DrawerService::class)->open($this->register, User::factory()->owner()->create(), 500_000, null);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function sell(array $extra = []): TestResponse
    {
        return $this->actingAs($this->cashier)->postJson(route('pos.store'), [
            'register_id' => $this->register->id,
            'lines' => [['product_unit_id' => $this->sachet->id, 'qty' => '2']],
            'payments' => [['method' => TenderType::Cash->value, 'amount_paisa' => 6_000]],
        ] + $extra);
    }

    public function test_the_same_bill_sent_twice_is_only_charged_once(): void
    {
        $uid = (string) Str::uuid();

        $first = $this->sell(['offline_uid' => $uid])->assertCreated();
        $second = $this->sell(['offline_uid' => $uid])->assertCreated();

        $this->assertSame(1, Sale::query()->count(), 'The second try must not make a second bill');
        $this->assertSame($first->json('sale.id'), $second->json('sale.id'));
        $this->assertSame('INV-000001', $second->json('sale.invoice'));

        /* And the stock came off the shelf once, not twice. */
        $this->assertSame(498, $this->product->fresh()->stock_qty_base);
    }

    public function test_two_different_bills_are_both_taken(): void
    {
        $this->sell(['offline_uid' => (string) Str::uuid()])->assertCreated();
        $this->sell(['offline_uid' => (string) Str::uuid()])->assertCreated();

        $this->assertSame(2, Sale::query()->count());
        $this->assertSame(496, $this->product->fresh()->stock_qty_base);
    }

    public function test_a_bill_sent_without_a_name_is_still_taken(): void
    {
        $this->sell()->assertCreated();
        $this->sell()->assertCreated();

        $this->assertSame(2, Sale::query()->count());
        $this->assertNull(Sale::query()->first()->offline_uid);
    }

    public function test_the_moment_the_money_changed_hands_is_kept(): void
    {
        $rungUp = now()->subMinutes(40);

        $this->sell([
            'offline_uid' => (string) Str::uuid(),
            'offline_rung_at' => $rungUp->toIso8601String(),
        ])->assertCreated();

        $sale = Sale::query()->sole();

        $this->assertNotNull($sale->offline_rung_at);
        $this->assertSame($rungUp->toDateTimeString(), $sale->offline_rung_at->toDateTimeString());
    }

    public function test_a_late_bill_lands_in_the_takings_at_the_moment_it_is_recorded(): void
    {
        $this->sell([
            'offline_uid' => (string) Str::uuid(),
            'offline_rung_at' => now()->subHours(3)->toIso8601String(),
        ])->assertCreated();

        $sale = Sale::query()->sole();

        /* sold_at is what the drawer count and the day's report read, so it
           stays at the moment the shop computer took the bill. */
        $this->assertTrue($sale->sold_at->greaterThan(now()->subMinute()));
    }

    public function test_a_time_from_the_future_is_ignored(): void
    {
        $this->sell([
            'offline_uid' => (string) Str::uuid(),
            'offline_rung_at' => now()->addHours(2)->toIso8601String(),
        ])->assertCreated();

        $this->assertNull(Sale::query()->sole()->offline_rung_at, 'A till with a wrong clock must not be believed');
    }

    public function test_a_time_from_last_month_is_ignored(): void
    {
        $this->sell([
            'offline_uid' => (string) Str::uuid(),
            'offline_rung_at' => now()->subMonth()->toIso8601String(),
        ])->assertCreated();

        $this->assertNull(Sale::query()->sole()->offline_rung_at);
    }

    public function test_a_name_that_is_not_a_proper_name_is_refused(): void
    {
        $this->sell(['offline_uid' => 'bill-one'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('offline_uid');

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_a_bill_at_the_counter_still_prints(): void
    {
        $this->mock(PrintService::class)
            ->shouldReceive('afterSale')->once()->andReturn(true);

        $this->sell(['offline_uid' => (string) Str::uuid()])
            ->assertCreated()
            ->assertJsonPath('printed', true);
    }

    public function test_a_bill_caught_up_later_does_not_print_or_open_the_drawer(): void
    {
        /* The customer left half an hour ago. Nobody is standing at the
           printer, and the drawer must not jump at an empty counter. */
        $this->mock(PrintService::class)->shouldNotReceive('afterSale');

        $this->sell([
            'offline_uid' => (string) Str::uuid(),
            'offline_rung_at' => now()->subMinutes(30)->toIso8601String(),
        ])
            ->assertCreated()
            ->assertJsonPath('printed', false);
    }

    public function test_a_bill_sent_twice_does_not_print_twice(): void
    {
        $this->mock(PrintService::class)
            ->shouldReceive('afterSale')->once()->andReturn(true);

        $uid = (string) Str::uuid();

        $this->sell(['offline_uid' => $uid])->assertCreated()->assertJsonPath('printed', true);
        $this->sell(['offline_uid' => $uid])->assertCreated()->assertJsonPath('printed', false);
    }
}
