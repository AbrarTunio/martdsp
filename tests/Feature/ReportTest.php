<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\RefundMethod;
use App\Enums\SaleReturnReason;
use App\Enums\TenderType;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Services\DrawerService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Support\Reports\Column;
use App\Support\Reports\Period;
use App\Support\Reports\ReportResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reports every owner reads: who may see them, how dates are picked, and
 * that the Daily sales figures add up the way the till does — returns on the
 * day they came back, voided bills left out, each tender in its own column.
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private ProductUnit $carton;

    private Register $register;

    private User $cashier;

    private User $manager;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-10 09:00'));

        $sachetUnit = Unit::factory()->sachet()->create();
        $cartonUnit = Unit::factory()->carton()->create();

        $product = Product::factory()->create(['name' => 'Surf Excel', 'base_unit_id' => $sachetUnit->id]);

        ProductUnit::factory()->base()->create([
            'product_id' => $product->id,
            'unit_id' => $sachetUnit->id,
            'sale_price_paisa' => 3_000,
        ]);

        $this->carton = ProductUnit::factory()->holding(144)->create([
            'product_id' => $product->id,
            'unit_id' => $cartonUnit->id,
            'sale_price_paisa' => 400_000,
        ]);

        app(StockService::class)->record($product, 1440, MovementType::Opening, unitCostPaisa: 2_000);

        $this->register = Register::factory()->create();
        $this->cashier = User::factory()->cashier()->create();
        $this->manager = User::factory()->manager()->create();
        $this->owner = User::factory()->owner()->create();

        app(DrawerService::class)->open($this->register, $this->manager, 500_000, null);
    }

    /**
     * @param  array<int, array{method: TenderType, amount_paisa: int}>|null  $payments
     */
    private function sell(int $cartons = 1, ?array $payments = null, ?Customer $customer = null): Sale
    {
        return app(SaleService::class)->complete(
            cashier: $this->cashier,
            register: $this->register,
            lines: [['product_unit_id' => $this->carton->id, 'qty' => (string) $cartons]],
            payments: $payments ?? [['method' => TenderType::Cash, 'amount_paisa' => 400_000 * $cartons]],
            customer: $customer,
        );
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, array<string, mixed>>
     */
    private function dailyRows(array $query): array
    {
        $result = $this->actingAs($this->owner)
            ->get(route('reports.show', ['key' => 'daily-sales'] + $query))
            ->assertOk()
            ->viewData('result');

        $rows = [];

        foreach ($result->rows as $row) {
            $rows[$row['date'] instanceof CarbonImmutable ? $row['date']->toDateString() : $row['date']] = $row;
        }

        return $rows;
    }

    public function test_cashiers_are_kept_out_of_every_report_page(): void
    {
        $this->actingAs($this->cashier);

        $this->get(route('reports.index'))->assertForbidden();
        $this->get(route('reports.show', 'daily-sales'))->assertForbidden();
        $this->get(route('reports.print', 'daily-sales'))->assertForbidden();
        $this->get(route('reports.export', 'daily-sales'))->assertForbidden();
    }

    public function test_managers_see_the_list_of_reports(): void
    {
        $this->actingAs($this->manager)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Daily sales')
            ->assertSee(route('reports.show', 'daily-sales'));
    }

    public function test_an_unknown_report_is_not_found(): void
    {
        $this->actingAs($this->owner)->get('/reports/nothing-here')->assertNotFound();
    }

    public function test_daily_sales_add_up_each_day_by_tender(): void
    {
        $customer = Customer::factory()->create();

        $this->travelTo(CarbonImmutable::parse('2026-09-10 11:00'));
        $cash = $this->sell(2);
        $this->sell(1, [
            ['method' => TenderType::Card, 'amount_paisa' => 100_000],
            ['method' => TenderType::Khata, 'amount_paisa' => 300_000],
        ], $customer);

        $this->travelTo(CarbonImmutable::parse('2026-09-12 16:00'));
        $this->sell(1, [['method' => TenderType::Easypaisa, 'amount_paisa' => 400_000]]);

        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00'));
        $rows = $this->dailyRows(['period' => 'custom', 'from' => '2026-09-10', 'to' => '2026-09-15']);

        $this->assertSame(['2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13', '2026-09-14', '2026-09-15'], array_keys($rows));

        $tenth = $rows['2026-09-10'];
        $this->assertSame(2, $tenth['bills']);
        $this->assertSame(1_200_000, $tenth['sales_paisa']);
        $this->assertSame(1_200_000, $tenth['net_paisa']);
        $this->assertSame(800_000, $tenth['cash_paisa']);
        $this->assertSame(100_000, $tenth['card_paisa']);
        $this->assertSame(300_000, $tenth['khata_paisa']);
        $this->assertSame(0, $tenth['wallet_paisa']);
        $this->assertSame(600_000, $tenth['average_paisa']);
        $this->assertSame($cash->tax_paisa + Sale::query()->where('id', '!=', $cash->id)->whereDate('sold_at', '2026-09-10')->sum('tax_paisa'), $tenth['tax_paisa']);

        $this->assertSame(0, $rows['2026-09-11']['bills']);
        $this->assertNull($rows['2026-09-11']['average_paisa']);
        $this->assertSame('muted', $rows['2026-09-11']['_tone']);

        $this->assertSame(400_000, $rows['2026-09-12']['wallet_paisa']);
    }

    public function test_totals_and_the_chart_follow_the_rows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 11:00'));
        $this->sell(2);

        $this->travelTo(CarbonImmutable::parse('2026-09-12 16:00'));
        $this->sell(1);

        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00'));

        $this->actingAs($this->owner)
            ->get(route('reports.show', ['key' => 'daily-sales', 'period' => 'custom', 'from' => '2026-09-10', 'to' => '2026-09-15']))
            ->assertOk()
            ->assertViewHas('result', function (ReportResult $result): bool {
                return $result->totals['bills'] === 2
                    && $result->totals['net_paisa'] === 1_200_000
                    && $result->totals['date'] === 'Total'
                    && $result->chart['labels'][0] === '10 Sep'
                    && $result->chart['datasets'][0]['data'] === [800_000, 0, 400_000, 0, 0, 0];
            });
    }

    public function test_a_voided_bill_is_counted_but_not_sold(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 11:00'));
        $this->sell(1);
        $voided = $this->sell(2);

        app(SaleService::class)->void($voided, $this->manager, 'Rang up twice');

        $row = $this->dailyRows(['period' => 'today'])['2026-09-10'];

        $this->assertSame(1, $row['bills']);
        $this->assertSame(400_000, $row['sales_paisa']);
        $this->assertSame(400_000, $row['cash_paisa']);
        $this->assertSame(1, $row['voided']);
    }

    public function test_a_return_lands_on_the_day_it_came_back(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 11:00'));
        $sale = $this->sell(2);

        $this->travelTo(CarbonImmutable::parse('2026-09-12 16:00'));
        $return = app(SaleReturnService::class)->post(
            $sale,
            [['sale_item_id' => $sale->items->first()->id, 'qty_base' => 144]],
            $this->manager,
            SaleReturnReason::ChangedMind,
            RefundMethod::Cash,
            $this->register,
        );

        $rows = $this->dailyRows(['period' => 'custom', 'from' => '2026-09-10', 'to' => '2026-09-12']);

        $this->assertSame(800_000, $rows['2026-09-10']['net_paisa']);
        $this->assertSame($sale->tax_paisa, $rows['2026-09-10']['tax_paisa']);

        $this->assertSame(0, $rows['2026-09-12']['bills']);
        $this->assertSame($return->total_paisa, $rows['2026-09-12']['returns_paisa']);
        $this->assertSame(-$return->total_paisa, $rows['2026-09-12']['net_paisa']);
        $this->assertSame(-$return->tax_paisa, $rows['2026-09-12']['tax_paisa']);
    }

    public function test_a_sale_just_after_midnight_belongs_to_the_new_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-11 00:10', 'Asia/Karachi'));
        $this->sell(1);

        $rows = $this->dailyRows(['period' => 'today']);

        $this->assertSame(['2026-09-11'], array_keys($rows));
        $this->assertSame(1, $rows['2026-09-11']['bills']);
    }

    public function test_sales_can_be_folded_into_months(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 11:00'));
        $this->sell(1);

        $this->travelTo(CarbonImmutable::parse('2026-09-12 11:00'));
        $this->sell(1);

        $rows = $this->dailyRows(['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-09-12', 'by' => 'month']);

        $this->assertSame(['August 2026', 'September 2026'], array_keys($rows));
        $this->assertSame(0, $rows['August 2026']['bills']);
        $this->assertSame(2, $rows['September 2026']['bills']);
        $this->assertSame(800_000, $rows['September 2026']['net_paisa']);
        $this->assertNull($rows['September 2026']['_link']);
    }

    public function test_an_unknown_filter_value_falls_back_to_the_default(): void
    {
        $this->actingAs($this->owner)
            ->get(route('reports.show', ['key' => 'daily-sales', 'by' => 'century']))
            ->assertOk()
            ->assertViewHas('filters', ['by' => 'day']);
    }

    public function test_preset_periods(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00'));

        $month = Period::preset('this_month');
        $this->assertSame('2026-09-01', $month->from->toDateString());
        $this->assertSame('2026-09-15', $month->to->toDateString());
        $this->assertSame(15, $month->days());

        $before = $month->previous();
        $this->assertSame('2026-08-01', $before->from->toDateString());
        $this->assertSame('2026-08-15', $before->to->toDateString());

        $last = Period::preset('last_month');
        $this->assertSame('2026-08-01', $last->from->toDateString());
        $this->assertSame('2026-08-31', $last->to->toDateString());
        $this->assertSame('2026-07-01', $last->previous()->from->toDateString());
        $this->assertSame('2026-07-31', $last->previous()->to->toDateString());

        $week = Period::preset('last_30_days');
        $this->assertSame(30, $week->days());
        $this->assertSame(30, $week->previous()->days());
        $this->assertSame($week->from->subDay()->toDateString(), $week->previous()->to->toDateString());

        $today = Period::preset('today');
        $this->assertTrue($today->isSingleDay());
        $this->assertSame('Tue, 15 Sep 2026', $today->label());

        $this->assertSame('this_month', Period::preset('nonsense')->preset);
    }

    public function test_picked_dates_are_kept_sensible(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00'));

        $future = Period::between('2026-09-01', '2027-01-01');
        $this->assertSame('2026-09-15', $future->to->toDateString());

        $backwards = Period::between('2026-09-10', '2026-09-01');
        $this->assertSame('2026-09-01', $backwards->from->toDateString());
        $this->assertSame('2026-09-10', $backwards->to->toDateString());

        $long = Period::between('2020-01-01', '2026-09-15');
        $this->assertSame(Period::MAX_DAYS, $long->days());
        $this->assertSame('2026-09-15', $long->to->toDateString());

        $junk = Period::between('2026-02-31', 'yesterday-ish');
        $this->assertLessThanOrEqual(Period::MAX_DAYS, $junk->days());
        $this->assertTrue($junk->to->lte(today()));

        $this->assertSame('1 – 10 Sep 2026', $backwards->label());
        $this->assertSame(['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-10'], $backwards->query());
    }

    public function test_the_excel_download_is_a_spreadsheet_in_rupees(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 11:00'));
        $this->sell(1);

        $response = $this->actingAs($this->owner)
            ->get(route('reports.export', ['key' => 'daily-sales', 'period' => 'today']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('daily-sales-2026-09-10.csv');

        $csv = $response->streamedContent();
        $lines = array_map(str_getcsv(...), preg_split('/\R/', trim(substr($csv, 3))));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame('Day', $lines[0][0]);
        $this->assertContains('Net sales', $lines[0]);
        $this->assertSame('2026-09-10', $lines[1][0]);
        $this->assertContains('4000.00', $lines[1]);
        $this->assertSame('Total', end($lines)[0]);

        $this->assertDatabaseHas('activity_logs', ['action' => 'report.exported', 'user_id' => $this->owner->id]);
    }

    public function test_text_that_looks_like_a_formula_is_defused_for_excel(): void
    {
        $column = Column::text('name', 'Name');

        $this->assertSame("'=HYPERLINK(\"x\")", $column->export('=HYPERLINK("x")'));
        $this->assertSame("'-5 packs", $column->export('-5 packs'));
        $this->assertSame('Surf Excel', $column->export('Surf Excel'));
        $this->assertSame('4000.00', Column::money('m', 'M')->export(400_000));
        $this->assertSame('4,000.00', Column::money('m', 'M')->display(400_000));
    }

    public function test_the_print_page_carries_the_shop_and_the_dates(): void
    {
        Setting::write('shop.name', 'Madina Mart');
        Setting::write('shop.ntn', '1234567-8');

        $this->travelTo(CarbonImmutable::parse('2026-09-10 11:00'));
        $this->sell(1);

        $this->actingAs($this->owner)
            ->get(route('reports.print', ['key' => 'daily-sales', 'period' => 'today']))
            ->assertOk()
            ->assertSee('Daily sales')
            ->assertSee('Thu, 10 Sep 2026')
            ->assertSee('Madina Mart')
            ->assertSee('1234567-8')
            ->assertSee('Print or save as PDF');
    }

    public function test_the_report_page_links_each_day_to_its_bills(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 11:00'));
        $this->sell(1);

        $this->actingAs($this->owner)
            ->get(route('reports.show', ['key' => 'daily-sales', 'period' => 'today']))
            ->assertOk()
            ->assertSee(route('sales.index', ['date' => '2026-09-10']), false)
            ->assertSee(route('reports.export', ['key' => 'daily-sales', 'period' => 'today']), false)
            ->assertSee('x-data="chart(', false);
    }
}
