<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\RefundMethod;
use App\Enums\SaleReturnReason;
use App\Enums\TenderType;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\DrawerService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Support\Reports\ReportResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Profit, stock value and dead stock: the three reports that tell the owner
 * where the money went and where it is sitting.
 */
class ProfitAndStockReportTest extends TestCase
{
    use RefreshDatabase;

    private Unit $sachetUnit;

    private Unit $cartonUnit;

    private Register $register;

    private User $cashier;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));

        $this->sachetUnit = Unit::factory()->sachet()->create();
        $this->cartonUnit = Unit::factory()->carton()->create();

        $this->register = Register::factory()->create();
        $this->cashier = User::factory()->cashier()->create();
        $this->owner = User::factory()->owner()->create();

        app(DrawerService::class)->open($this->register, User::factory()->manager()->create(), 500_000, null);
    }

    /**
     * A product sold by the sachet and by the carton of 144, with ten
     * cartons on the shelf at the given cost per sachet.
     *
     * @return array{0: Product, 1: ProductUnit, 2: ProductUnit}
     */
    private function product(string $name, int $costPaisa, int $sachetPaisa = 3_000, int $cartonPaisa = 400_000, ?Category $category = null, int $stock = 1440): array
    {
        $product = Product::factory()->create([
            'name' => $name,
            'base_unit_id' => $this->sachetUnit->id,
            'category_id' => $category?->id,
        ]);

        $sachet = ProductUnit::factory()->base()->create([
            'product_id' => $product->id,
            'unit_id' => $this->sachetUnit->id,
            'sale_price_paisa' => $sachetPaisa,
        ]);

        $carton = ProductUnit::factory()->holding(144)->create([
            'product_id' => $product->id,
            'unit_id' => $this->cartonUnit->id,
            'sale_price_paisa' => $cartonPaisa,
        ]);

        if ($stock > 0) {
            app(StockService::class)->record($product, $stock, MovementType::Opening, unitCostPaisa: $costPaisa);
        }

        return [$product->fresh(), $sachet, $carton];
    }

    private function sell(ProductUnit $unit, int $qty, int $paisa): Sale
    {
        return app(SaleService::class)->complete(
            cashier: $this->cashier,
            register: $this->register,
            lines: [['product_unit_id' => $unit->id, 'qty' => (string) $qty]],
            payments: [['method' => TenderType::Cash, 'amount_paisa' => $paisa]],
        );
    }

    /**
     * @param  array<string, string>  $query
     */
    private function report(string $key, array $query = []): ReportResult
    {
        return $this->actingAs($this->owner)
            ->get(route('reports.show', ['key' => $key] + $query))
            ->assertOk()
            ->viewData('result');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rowsByName(ReportResult $result): array
    {
        return collect($result->rows)->keyBy('name')->all();
    }

    private static function earned(Sale $sale): int
    {
        return (int) $sale->items->sum(fn (SaleItem $item): int => $item->line_total_paisa - $item->tax_paisa);
    }

    public function test_profit_is_sales_before_gst_less_what_the_goods_cost(): void
    {
        [, , $carton] = $this->product('Surf Excel', costPaisa: 2_000);

        $this->travelTo(CarbonImmutable::parse('2026-06-10 11:00'));
        $sale = $this->sell($carton, 2, 800_000);

        $row = $this->rowsByName($this->report('profit', ['period' => 'this_month']))['Surf Excel'];

        $this->assertSame(288, $row['qty']);
        $this->assertSame(self::earned($sale), $row['sales_paisa']);
        $this->assertSame(288 * 2_000, $row['cost_paisa']);
        $this->assertSame(self::earned($sale) - 576_000, $row['profit_paisa']);
        $this->assertSame(round((self::earned($sale) - 576_000) / self::earned($sale) * 100, 1), $row['margin']);
        $this->assertSame(100.0, $row['share']);
        $this->assertNull($row['_tone']);
    }

    public function test_returns_come_off_and_only_shelved_goods_take_their_cost_back(): void
    {
        [, , $surf] = $this->product('Surf Excel', costPaisa: 2_000);
        [, , $tea] = $this->product('Tapal Tea', costPaisa: 2_000);

        $this->travelTo(CarbonImmutable::parse('2026-06-10 11:00'));
        $surfSale = $this->sell($surf, 2, 800_000);
        $teaSale = $this->sell($tea, 2, 800_000);

        $this->travelTo(CarbonImmutable::parse('2026-06-12 11:00'));
        $returns = app(SaleReturnService::class);
        $shelved = $returns->post($surfSale, [['sale_item_id' => $surfSale->items->first()->id, 'qty_base' => 144]], $this->owner, SaleReturnReason::ChangedMind, RefundMethod::Cash, $this->register);
        $damaged = $returns->post($teaSale, [['sale_item_id' => $teaSale->items->first()->id, 'qty_base' => 144]], $this->owner, SaleReturnReason::Damaged, RefundMethod::Cash, $this->register);

        $rows = $this->rowsByName($this->report('profit', ['period' => 'this_month']));

        $this->assertSame(144, $rows['Surf Excel']['qty']);
        $this->assertSame(self::earned($surfSale) - ($shelved->total_paisa - $shelved->tax_paisa), $rows['Surf Excel']['sales_paisa']);
        $this->assertSame(144 * 2_000, $rows['Surf Excel']['cost_paisa']);

        $this->assertSame(144, $rows['Tapal Tea']['qty']);
        $this->assertSame(self::earned($teaSale) - ($damaged->total_paisa - $damaged->tax_paisa), $rows['Tapal Tea']['sales_paisa']);
        $this->assertSame(288 * 2_000, $rows['Tapal Tea']['cost_paisa'], 'Damaged goods never came back, so their cost stays in.');
    }

    public function test_a_product_sold_below_cost_is_flagged_and_voids_are_left_out(): void
    {
        [, $sachet] = $this->product('Cooking Oil', costPaisa: 5_000);
        [, , $surf] = $this->product('Surf Excel', costPaisa: 2_000);

        $this->travelTo(CarbonImmutable::parse('2026-06-10 11:00'));
        $this->sell($sachet, 10, 30_000);
        $voided = $this->sell($surf, 1, 400_000);
        app(SaleService::class)->void($voided, $this->owner, 'Wrong item');

        $result = $this->report('profit', ['period' => 'this_month', 'sort' => 'loss']);
        $rows = $this->rowsByName($result);

        $this->assertSame(['Cooking Oil'], array_keys($rows));
        $this->assertLessThan(0, $rows['Cooking Oil']['profit_paisa']);
        $this->assertSame('danger', $rows['Cooking Oil']['_tone']);
        $this->assertNull($rows['Cooking Oil']['share']);
        $this->assertSame('1', $result->stats[3]['value']);
        $this->assertSame('danger', $result->stats[3]['tone']);
    }

    public function test_profit_can_be_read_by_category(): void
    {
        $home = Category::factory()->create(['name' => 'Home care']);
        [, , $surf] = $this->product('Surf Excel', costPaisa: 2_000, category: $home);
        [, , $ariel] = $this->product('Ariel', costPaisa: 2_000, category: $home);
        [, , $loose] = $this->product('Loose item', costPaisa: 2_000);

        $this->travelTo(CarbonImmutable::parse('2026-06-10 11:00'));
        $this->sell($surf, 1, 400_000);
        $this->sell($ariel, 1, 400_000);
        $this->sell($loose, 1, 400_000);

        $result = $this->report('profit', ['period' => 'this_month', 'group' => 'category']);
        $rows = $this->rowsByName($result);

        $this->assertSame(['Home care', 'No category'], array_keys($rows));
        $this->assertSame(2, $rows['Home care']['products']);
        $this->assertSame(288 * 2_000, $rows['Home care']['cost_paisa']);
        $this->assertSame(3, $result->totals['products']);
        $this->assertSame('Category', $result->columns[0]->label);
    }

    public function test_stock_is_valued_at_cost_and_at_the_till(): void
    {
        $this->product('Surf Excel', costPaisa: 2_000);
        $this->product('Carton only', costPaisa: 2_000, sachetPaisa: 0, cartonPaisa: 360_000);
        [$broken] = $this->product('Miscounted', costPaisa: 1_000, stock: 0);
        $broken->forceFill(['stock_qty_base' => -5])->save();

        $result = $this->report('stock-value', ['group' => 'product']);
        $rows = $this->rowsByName($result);

        $this->assertSame(1440 * 2_000, $rows['Surf Excel']['cost_paisa']);
        $this->assertSame(1440 * 3_000, $rows['Surf Excel']['retail_paisa']);
        $this->assertSame(1440 * 2_500, $rows['Carton only']['retail_paisa'], 'Priced by the carton: Rs. 3,600 for 144 is Rs. 25 a sachet.');

        $this->assertSame(-5, $rows['Miscounted']['qty']);
        $this->assertSame(0, $rows['Miscounted']['cost_paisa']);
        $this->assertSame('danger', $rows['Miscounted']['_tone']);
        $this->assertSame('Miscounted', $result->rows[0]['name'], 'Stock below zero is listed first.');

        $this->assertSame(2 * 1440 * 2_000, $result->totals['cost_paisa']);
        $this->assertSame('1', $result->stats[3]['value']);
        $this->assertSame('doughnut', $result->chart['type']);
    }

    public function test_stock_value_by_category_has_no_dates_to_pick(): void
    {
        $food = Category::factory()->create(['name' => 'Food']);
        $this->product('Rice', costPaisa: 1_000, category: $food);
        $this->product('Surf Excel', costPaisa: 2_000);

        $response = $this->actingAs($this->owner)->get(route('reports.show', 'stock-value'))->assertOk();

        $response->assertDontSee('name="period"', false)->assertSee('As it stands at');

        $rows = $this->rowsByName($response->viewData('result'));
        $this->assertSame(['No category', 'Food'], array_keys($rows));
        $this->assertSame(66.7, $rows['No category']['share']);
    }

    public function test_dead_stock_lists_what_has_not_sold_for_the_chosen_days(): void
    {
        [, $surfSachet] = $this->product('Surf Excel', costPaisa: 2_000);
        $this->product('Never sold', costPaisa: 1_000);
        [, $teaSachet] = $this->product('Tapal Tea', costPaisa: 2_000);

        $this->travelTo(CarbonImmutable::parse('2026-07-01 11:00'));
        $this->sell($surfSachet, 1, 3_000);

        $this->travelTo(CarbonImmutable::parse('2026-08-10 11:00'));
        $this->sell($teaSachet, 1, 3_000);

        $this->travelTo(CarbonImmutable::parse('2026-09-01 11:00'));
        $this->product('Just added', costPaisa: 1_000);

        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00'));

        $result = $this->report('dead-stock');
        $rows = $this->rowsByName($result);

        $this->assertSame(['Surf Excel', 'Never sold'], array_keys($rows));
        $this->assertSame(76, $rows['Surf Excel']['idle']);
        $this->assertSame('2026-07-01', $rows['Surf Excel']['last_sold']->toDateString());
        $this->assertSame('warning', $rows['Surf Excel']['_tone']);
        $this->assertNull($rows['Never sold']['last_sold']);
        $this->assertSame(106, $rows['Never sold']['idle']);
        $this->assertSame('danger', $rows['Never sold']['_tone']);
        $this->assertSame(1439 * 2_000 + 1440 * 1_000, $result->totals['cost_paisa']);

        $this->assertSame(['Never sold'], array_keys($this->rowsByName($this->report('dead-stock', ['days' => '90']))));
        $this->assertSame(['Surf Excel', 'Tapal Tea', 'Never sold'], array_keys($this->rowsByName($this->report('dead-stock', ['days' => '30']))));
    }

    public function test_the_reports_list_shows_every_section(): void
    {
        $this->actingAs($this->owner)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSeeInOrder(['Sales', 'Daily sales', 'Profit and margin', 'Stock', 'Stock value', 'Dead stock']);
    }

    public function test_every_report_prints_and_downloads(): void
    {
        [, , $carton] = $this->product('Surf Excel', costPaisa: 2_000);
        $this->sell($carton, 1, 400_000);

        foreach (['profit', 'stock-value', 'dead-stock'] as $key) {
            $this->actingAs($this->owner)->get(route('reports.print', $key))->assertOk();
            $this->actingAs($this->owner)->get(route('reports.export', $key))->assertOk()->assertDownload();
        }
    }
}
