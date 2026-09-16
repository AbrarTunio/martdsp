<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\PurchaseStatus;
use App\Enums\RefundMethod;
use App\Enums\SaleReturnReason;
use App\Enums\SupplierEntryType;
use App\Enums\TenderType;
use App\Models\DrawerSession;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Register;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
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
 * The two reports about people: who sold what at the till, and who the shop
 * buys from and still owes.
 */
class PeopleReportTest extends TestCase
{
    use RefreshDatabase;

    private Register $register;

    private User $owner;

    private ProductUnit $sachet;

    private ProductUnit $carton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-06-15 09:00'));

        $sachetUnit = Unit::factory()->sachet()->create();
        $product = Product::factory()->create(['name' => 'Surf Excel', 'base_unit_id' => $sachetUnit->id]);

        $this->sachet = ProductUnit::factory()->base()->create([
            'product_id' => $product->id,
            'unit_id' => $sachetUnit->id,
            'sale_price_paisa' => 3_000,
        ]);

        $this->carton = ProductUnit::factory()->holding(144)->create([
            'product_id' => $product->id,
            'unit_id' => Unit::factory()->carton()->create()->id,
            'sale_price_paisa' => 400_000,
        ]);

        app(StockService::class)->record($product, 1440, MovementType::Opening, unitCostPaisa: 2_000);

        $this->register = Register::factory()->create();
        $this->owner = User::factory()->owner()->create();

        app(DrawerService::class)->open($this->register, User::factory()->manager()->create(), 500_000, null);
    }

    private function sell(User $cashier, ProductUnit $unit, int $qty, int $paisa): Sale
    {
        return app(SaleService::class)->complete(
            cashier: $cashier,
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function statsByLabel(ReportResult $result): array
    {
        return collect($result->stats)->keyBy('label')->all();
    }

    public function test_each_cashier_gets_their_bills_cancellations_refunds_and_drawer_counts(): void
    {
        $ali = User::factory()->cashier()->create(['name' => 'Ali']);
        $sara = User::factory()->cashier()->create(['name' => 'Sara']);

        $this->sell($ali, $this->sachet, 1, 3_000);
        $cancelled = $this->sell($ali, $this->sachet, 2, 6_000);
        app(SaleService::class)->void($cancelled, $this->owner, 'Rang up twice');

        $big = $this->sell($sara, $this->carton, 1, 400_000);
        $return = app(SaleReturnService::class)->post(
            $big,
            [['sale_item_id' => $big->items->first()->id, 'qty_base' => 12]],
            $sara,
            SaleReturnReason::ChangedMind,
            RefundMethod::Cash,
            $this->register,
        );

        DrawerSession::factory()->closed(500_000, 450_000)->create(['opened_by' => $sara->id]);
        DrawerSession::factory()->closed(500_000, 400_000)->create([
            'opened_by' => $sara->id,
            'closed_at' => CarbonImmutable::parse('2026-05-20 22:00'),
        ]);

        $result = $this->report('cashiers');
        $rows = $this->rowsByName($result);

        $this->assertSame(['Sara', 'Ali'], array_column($result->rows, 'name'));

        $this->assertSame(1, $rows['Ali']['bills']);
        $this->assertSame(3_000, $rows['Ali']['sales_paisa']);
        $this->assertSame(1, $rows['Ali']['voided']);
        $this->assertNull($rows['Ali']['variance_paisa']);
        $this->assertSame('warning', $rows['Ali']['_tone']);
        $this->assertSame('Cashier', $rows['Ali']['_detail']);

        $this->assertSame(1, $rows['Sara']['bills']);
        $this->assertSame(400_000, $rows['Sara']['average_paisa']);
        $this->assertSame($return->total_paisa, $rows['Sara']['returns_paisa']);
        $this->assertGreaterThan(0, $rows['Sara']['returns_paisa']);
        $this->assertSame(1, $rows['Sara']['drawers']);
        $this->assertSame(-50_000, $rows['Sara']['variance_paisa']);
        $this->assertSame('danger', $rows['Sara']['_tone']);

        $this->assertSame(2, $result->totals['bills']);
        $this->assertSame(403_000, $result->totals['sales_paisa']);
        $this->assertSame(1, $result->totals['voided']);

        $stats = $this->statsByLabel($result);
        $this->assertSame('Sara', $stats['Top seller']['value']);
        $this->assertSame('33.3% of all bills rung up', $stats['Cancelled bills']['hint']);
        $this->assertSame('danger', $stats['Drawers short / over']['tone']);
        $this->assertSame('One shift closed', $stats['Drawers short / over']['hint']);

        $this->assertSame(['Sara', 'Ali'], $result->chart['labels']);
    }

    public function test_a_quiet_period_has_no_one_at_the_till(): void
    {
        $result = $this->report('cashiers');

        $this->assertSame([], $result->rows);
        $this->assertNull($result->chart);
        $this->assertSame('—', $this->statsByLabel($result)['Top seller']['value']);
    }

    public function test_suppliers_show_what_was_bought_returned_paid_and_owed(): void
    {
        $hilal = Supplier::factory()->owed(600_000)->create(['name' => 'Hilal Foods']);
        $nestle = Supplier::factory()->owed(200_000)->create(['name' => 'Nestle', 'company' => 'Nestle Pakistan']);
        Supplier::factory()->owed(50_000)->create(['name' => 'Old Friend']);
        Supplier::factory()->owed(-20_000)->create(['name' => 'Credit Note Co']);
        Supplier::factory()->create(['name' => 'Settled Up']);

        Purchase::factory()->received()->create(['supplier_id' => $hilal->id, 'total_paisa' => 1_000_000, 'received_at' => '2026-06-10 11:00']);
        Purchase::factory()->received()->create(['supplier_id' => $hilal->id, 'total_paisa' => 500_000, 'received_at' => '2026-05-20 11:00']);
        Purchase::factory()->received()->create(['supplier_id' => $nestle->id, 'total_paisa' => 200_000, 'received_at' => '2026-06-05 11:00']);
        Purchase::factory()->cash()->received()->create(['total_paisa' => 90_000, 'received_at' => '2026-06-03 11:00']);
        Purchase::factory()->create(['supplier_id' => $nestle->id, 'status' => PurchaseStatus::Draft, 'total_paisa' => 999_000]);

        PurchaseReturn::factory()->create(['supplier_id' => $hilal->id, 'total_paisa' => 100_000, 'returned_at' => '2026-06-12 11:00']);

        SupplierLedgerEntry::factory()->create(['supplier_id' => $hilal->id, 'type' => SupplierEntryType::Payment, 'debit_paisa' => 300_000, 'entry_date' => '2026-06-14']);
        SupplierLedgerEntry::factory()->create(['supplier_id' => $nestle->id, 'type' => SupplierEntryType::Purchase, 'credit_paisa' => 200_000, 'entry_date' => '2026-06-05']);

        $result = $this->report('suppliers');
        $rows = $this->rowsByName($result);

        $this->assertSame(['Hilal Foods', 'Nestle', 'Old Friend', 'Credit Note Co'], array_column($result->rows, 'name'));

        $this->assertSame(1, $rows['Hilal Foods']['deliveries']);
        $this->assertSame(1_000_000, $rows['Hilal Foods']['bought_paisa']);
        $this->assertSame(100_000, $rows['Hilal Foods']['returned_paisa']);
        $this->assertSame(300_000, $rows['Hilal Foods']['paid_paisa']);
        $this->assertSame(600_000, $rows['Hilal Foods']['balance_paisa']);
        $this->assertSame('2026-06-10', $rows['Hilal Foods']['last_delivery']->toDateString());
        $this->assertNull($rows['Hilal Foods']['_tone']);
        $this->assertSame(route('suppliers.show', $hilal), $rows['Hilal Foods']['_link']);

        $this->assertSame(0, $rows['Nestle']['paid_paisa']);
        $this->assertSame('warning', $rows['Nestle']['_tone']);
        $this->assertSame('Nestle Pakistan', $rows['Nestle']['_detail']);

        $this->assertSame(0, $rows['Old Friend']['deliveries']);
        $this->assertNull($rows['Old Friend']['last_delivery']);
        $this->assertSame('Owes you money', $rows['Credit Note Co']['_detail']);

        $this->assertSame(1_200_000, $result->totals['bought_paisa']);
        $this->assertSame(2, $result->totals['deliveries']);
        $this->assertSame(830_000, $result->totals['balance_paisa']);

        $stats = $this->statsByLabel($result);
        $this->assertSame('Rs. 8,500', $stats['Owed to suppliers now']['value']);
        $this->assertSame('Owed to 3 suppliers', $stats['Owed to suppliers now']['hint']);
        $this->assertSame('warning', $stats['Owed to suppliers now']['tone']);
        $this->assertSame('Rs. 3,000', $stats['Paid to suppliers']['value']);

        $this->assertSame(['Hilal Foods', 'Nestle'], $result->chart['labels']);
    }

    public function test_nobody_owed_and_nothing_bought_is_an_empty_report(): void
    {
        Supplier::factory()->create(['name' => 'Settled Up']);

        $result = $this->report('suppliers');

        $this->assertSame([], $result->rows);
        $this->assertNull($result->chart);
        $this->assertSame('success', $this->statsByLabel($result)['Owed to suppliers now']['tone']);
    }

    public function test_the_people_reports_print_download_and_stay_away_from_cashiers(): void
    {
        $this->sell(User::factory()->cashier()->create(), $this->sachet, 1, 3_000);
        Supplier::factory()->owed(50_000)->create();

        foreach (['cashiers', 'suppliers'] as $key) {
            $this->actingAs($this->owner)->get(route('reports.print', $key))->assertOk();
            $this->actingAs($this->owner)->get(route('reports.export', $key))->assertOk()->assertDownload();
            $this->actingAs(User::factory()->cashier()->create())->get(route('reports.show', $key))->assertForbidden();
        }

        $this->actingAs($this->owner)
            ->get(route('reports.index'))
            ->assertSeeInOrder(['People', 'Cashier performance', 'Suppliers']);
    }
}
