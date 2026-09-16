<?php

namespace Tests\Feature;

use App\Enums\DrawerStatus;
use App\Models\Customer;
use App\Models\DrawerSession;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\KhataService;
use App\Services\StockService;
use App\Services\SupplierLedgerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * The demo shop has to be a shop, not a pile of rows.
 *
 * Whoever learns the system on this data will trust what it shows them, so
 * the demo has to survive the same questions the real books do: does the
 * shelf agree with the stock ledger, does every khata agree with its own
 * lines, does each closed drawer expect the cash that passed through it. It
 * is seeded through the same services the shop uses, so if any of those
 * breaks, this notices.
 *
 * Only a few days are traded here. The seeder does four weeks by default.
 */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    private const DAYS = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTheDemoShop(self::DAYS);
    }

    private function seedTheDemoShop(int $days): void
    {
        $seeder = $this->app->make(DemoSeeder::class);
        $seeder->setContainer($this->app);
        $seeder->days = $days;
        $seeder->run();
    }

    public function test_the_shop_is_stocked_and_staffed(): void
    {
        $this->assertGreaterThan(30, Product::count(), 'A demo shop with a handful of products teaches nothing');
        $this->assertSame(14, Customer::count());
        $this->assertSame(5, Supplier::count());

        /* Nested packaging is the thing that has to be demonstrated, so at
           least one product must come in three sizes. */
        $deepest = Product::withCount('productUnits')->orderByDesc('product_units_count')->first();
        $this->assertGreaterThanOrEqual(3, $deepest->product_units_count);
    }

    public function test_the_trading_happened_in_the_past_and_the_clock_was_put_back(): void
    {
        $this->assertFalse(Carbon::hasTestNow(), 'The seeder must not leave the clock wound back');

        $sales = Sale::query()->orderBy('sold_at')->get();

        $this->assertGreaterThan(20, $sales->count());
        $this->assertTrue($sales->first()->sold_at->lessThan(today()), 'The history must start before today');
        $this->assertTrue($sales->last()->sold_at->lessThanOrEqualTo(now()), 'Nothing may be dated in the future');
    }

    public function test_the_shelf_agrees_with_the_stock_ledger(): void
    {
        foreach (Product::all() as $product) {
            $rebuild = $this->app->make(StockService::class)->rebuild($product);

            $this->assertFalse($rebuild['drifted'], "The stock ledger for {$product->name} drifted");
            $this->assertGreaterThanOrEqual(0, (int) $product->stock_qty_base, "{$product->name} was sold into the negative");
        }
    }

    public function test_every_khata_agrees_with_its_own_lines(): void
    {
        $owing = 0;

        foreach (Customer::all() as $customer) {
            $this->assertFalse(
                $this->app->make(KhataService::class)->rebuild($customer)['drifted'],
                "{$customer->name}'s khata drifted",
            );

            $owing += (int) $customer->balance_paisa;
        }

        $this->assertGreaterThan(0, $owing, 'A demo with nobody on khata misses the point of the khata');
    }

    public function test_every_supplier_statement_agrees_with_its_own_lines(): void
    {
        foreach (Supplier::all() as $supplier) {
            $this->assertFalse(
                $this->app->make(SupplierLedgerService::class)->rebuild($supplier)['drifted'],
                "The statement for {$supplier->name} drifted",
            );
        }
    }

    public function test_each_closed_drawer_expected_the_cash_that_passed_through_it(): void
    {
        $closed = DrawerSession::query()->where('status', DrawerStatus::Closed)->get();

        $this->assertGreaterThanOrEqual(self::DAYS, $closed->count());

        foreach ($closed as $session) {
            $this->assertSame(
                (int) $session->transactions()->sum('amount_paisa'),
                (int) $session->expected_cash_paisa,
                "The drawer closed on {$session->closed_at} did not expect what went through it",
            );

            /* A count is made of real notes, so it can never be out by more
               than the smallest one. */
            $this->assertLessThan(100_00, abs((int) $session->variance_paisa));
        }
    }

    public function test_the_counter_is_left_open_so_the_demo_starts_mid_shift(): void
    {
        $this->assertTrue(
            DrawerSession::query()->where('status', DrawerStatus::Open)->exists(),
            'Whoever opens the demo should walk into a live shift',
        );
    }

    public function test_it_refuses_to_run_over_a_shop_that_already_has_sales(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already has sales');

        $this->seedTheDemoShop(1);
    }
}
