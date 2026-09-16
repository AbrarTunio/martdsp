<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\TenderType;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DrawerSession;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\DrawerService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Support\Money;
use App\Support\Reports\Period;
use App\Support\Reports\ProfitReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The home screen: today's takings for the owner, a cashier's own day for a
 * cashier, and the things on the shelves that need looking at.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Register $register;

    private User $owner;

    private User $cashier;

    private Product $product;

    private ProductUnit $carton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-06-08 10:00'));

        $sachetUnit = Unit::factory()->sachet()->create();
        $this->product = Product::factory()->create([
            'name' => 'Surf Excel',
            'base_unit_id' => $sachetUnit->id,
            'category_id' => Category::factory()->create(['name' => 'Washing'])->id,
        ]);

        ProductUnit::factory()->base()->create([
            'product_id' => $this->product->id,
            'unit_id' => $sachetUnit->id,
            'sale_price_paisa' => 3_000,
        ]);

        $this->carton = ProductUnit::factory()->holding(144)->create([
            'product_id' => $this->product->id,
            'unit_id' => Unit::factory()->carton()->create()->id,
            'sale_price_paisa' => 400_000,
        ]);

        app(StockService::class)->record($this->product, 1440, MovementType::Opening, unitCostPaisa: 2_000);

        $this->register = Register::factory()->create();
        $this->owner = User::factory()->owner()->create();
        $this->cashier = User::factory()->cashier()->create();

        app(DrawerService::class)->open($this->register, $this->owner, 500_000, null);
    }

    private function sellCarton(?User $cashier = null): Sale
    {
        return app(SaleService::class)->complete(
            cashier: $cashier ?? $this->cashier,
            register: $this->register,
            lines: [['product_unit_id' => $this->carton->id, 'qty' => '1']],
            payments: [['method' => TenderType::Cash, 'amount_paisa' => 400_000]],
        );
    }

    public function test_the_owner_sees_today_against_the_same_day_last_week_and_the_month_so_far(): void
    {
        $this->sellCarton();

        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00'));
        $this->sellCarton();
        $this->sellCarton();

        $response = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();

        $today = $response->viewData('today');
        $this->assertSame('Rs. 8,000', $today[0]['value']);
        $this->assertSame('▲ 100% on Mon, 08 Jun 2026', $today[0]['hint']);
        $this->assertSame('success', $today[0]['tone']);
        $this->assertSame('2', $today[1]['value']);
        $this->assertSame('Average bill Rs. 4,000', $today[1]['hint']);

        $profit = app(ProfitReport::class)->totals(Period::preset('today'));
        $this->assertGreaterThan(0, $profit['profit']);
        $this->assertSame(Money::rounded($profit['profit']), $today[2]['value']);

        $month = $response->viewData('month');
        $this->assertSame('1 – 15 Jun 2026', $month['label']);
        $this->assertSame('Rs. 12,000', $month['stats'][0]['value']);
        $this->assertSame('Nothing to compare with in 1 – 15 May 2026', $month['stats'][0]['hint']);
        $this->assertSame('3', $month['stats'][3]['value']);

        $trend = $response->viewData('trend');
        $this->assertCount(30, $trend['labels']);
        $this->assertSame('15 Jun', end($trend['labels']));
        $this->assertSame(800_000, end($trend['datasets'][0]['data']));
        $this->assertSame(400_000, $trend['datasets'][0]['data'][22]);
        $this->assertSame(40_000, $trend['datasets'][1]['data'][0]);

        $this->assertSame(['Surf Excel'], $response->viewData('topProducts')['labels']);
        $this->assertSame(['Washing'], $response->viewData('categoryMix')['labels']);

        $response->assertSee('Best sellers this month')
            ->assertSee('x-data="chart(', false)
            ->assertSee('href="'.route('reports.index').'"', false);
    }

    public function test_the_owner_sees_where_the_money_is(): void
    {
        $this->sellCarton();

        Customer::factory()->owing(150_000)->create();
        Customer::factory()->create()->forceFill(['balance_paisa' => -10_000])->save();
        Supplier::factory()->owed(300_000)->create();
        Supplier::factory()->owed(-5_000)->create();

        $money = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->viewData('money');

        [$cash, $stock, $khata] = $money['held'];

        $this->assertSame(900_000, $cash['paisa']);
        $this->assertSame('In one open drawer', $cash['hint']);
        $this->assertSame(1296 * 2_000, $stock['paisa']);
        $this->assertSame(150_000, $khata['paisa']);
        $this->assertSame('One customer owes you', $khata['hint']);
        $this->assertSame(300_000, $money['owed']['paisa']);
        $this->assertSame(900_000 + 2_592_000 + 150_000 - 300_000, $money['total']);

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertSeeInOrder(['Where the money is', 'Cash in the drawers', 'Rs. 9,000', 'Your money in the business', 'Rs. 33,420']);
    }

    public function test_a_cashier_sees_their_own_day_and_none_of_the_shop_s_money(): void
    {
        $this->sellCarton();
        $this->sellCarton(User::factory()->cashier()->create());
        app(SaleService::class)->void($this->sellCarton(), $this->owner, 'Rang up twice');

        $response = $this->actingAs($this->cashier)->get(route('dashboard'))->assertOk();

        $counter = $response->viewData('counter');
        $this->assertSame('1', $counter[0]['value']);
        $this->assertSame('Rs. 4,000', $counter[1]['value']);
        $this->assertSame('1', $counter[2]['value']);
        $this->assertSame('warning', $counter[2]['tone']);

        foreach (['today', 'month', 'money', 'trend', 'topProducts', 'categoryMix'] as $panel) {
            $this->assertNull($response->viewData($panel), $panel);
        }

        $response->assertSee('Your day')
            ->assertSee($this->register->name)
            ->assertDontSee('Where the money is')
            ->assertDontSee('Profit today')
            ->assertDontSee('href="'.route('reports.index').'"', false);
    }

    public function test_blind_count_hides_the_drawer_cash_from_a_cashier(): void
    {
        $this->sellCarton();

        Setting::write('drawer.blind_count', false);

        $this->actingAs($this->cashier)->get(route('dashboard'))->assertOk()->assertSee('Rs. 9,000');

        Setting::write('drawer.blind_count', true);

        $this->actingAs($this->cashier)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('seesExpected', false)
            ->assertSee($this->register->name)
            ->assertDontSee('Rs. 9,000');
    }

    public function test_only_what_needs_looking_at_is_listed_red_first(): void
    {
        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertViewHas('warnings', [])
            ->assertSee('All clear');

        $this->product->forceFill(['reorder_level_base' => 2_000])->save();
        Product::factory()->create(['name' => 'Missing Soap'])->forceFill(['stock_qty_base' => -5])->save();
        StockBatch::factory()->expired()->create(['product_id' => $this->product->id]);
        StockBatch::factory()->expiringIn(10)->create(['product_id' => $this->product->id]);
        StockBatch::factory()->expiringIn(10)->emptied()->create(['product_id' => $this->product->id]);
        DrawerSession::factory()->closed(500_000, 400_000)->create(['needs_approval' => true]);

        $owner = $this->actingAs($this->owner)->get(route('dashboard'))->viewData('warnings');

        $this->assertSame(
            ['Below zero', 'Past expiry, still in stock', 'Low on stock', 'Expiring within 30 days', 'Drawers waiting for approval'],
            array_column($owner, 'label'),
        );
        $this->assertSame([1, 1, 1, 1, 1], array_column($owner, 'count'));
        $this->assertSame(route('stock.index', ['view' => 'low']), $owner[2]['href']);

        $cashier = $this->actingAs($this->cashier)->get(route('dashboard'))->viewData('warnings');

        $this->assertNotContains('Drawers waiting for approval', array_column($cashier, 'label'));
    }

    public function test_a_shop_with_no_sales_yet_shows_empty_panels_not_zero_charts(): void
    {
        $response = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();

        $this->assertNull($response->viewData('trend'));
        $this->assertNull($response->viewData('topProducts'));
        $this->assertNull($response->viewData('categoryMix'));

        $response->assertSee('No sales yet')
            ->assertSee('Nothing sold this month')
            ->assertSee('No bills yet');
    }
}
