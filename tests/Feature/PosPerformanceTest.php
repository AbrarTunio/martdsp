<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\Barcode;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\DrawerService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The till has to stay quick as the shop grows.
 *
 * A scan that costs one query with fifty products and fifty with five
 * thousand is a till that feels fine the week it is installed and unusable a
 * year later — on a shop counter, over a phone connection, that is the whole
 * difference. So the lookups are counted here against two shops of very
 * different sizes, and the counts have to match.
 *
 * The numbers below are ceilings, not targets. Raising one is allowed when
 * something genuinely needs another query; going from a fixed count to one
 * that grows with the catalogue is not.
 */
class PosPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Register $register;

    private Unit $sachet;

    private Unit $carton;

    /** @var list<int> */
    private array $sachetUnitIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sachet = Unit::factory()->sachet()->create();
        $this->carton = Unit::factory()->carton()->create();
        $this->cashier = User::factory()->cashier()->create();
        $this->register = Register::factory()->create();

        app(DrawerService::class)->open($this->register, $this->cashier, 5_000_00, 'Counted in');

        $this->actingAs($this->cashier);
    }

    /**
     * Some of what a request does is done once and kept — the settings, the
     * counter the session is standing at. Counting a cold first request
     * against a warm second one would compare two different things, so the
     * page is opened once before anything is measured.
     */
    private function warmUp(): void
    {
        $this->get(route('pos.index'))->assertOk();
    }

    /**
     * What one scan, one search and one till screen cost in a shop of the
     * given size.
     *
     * @return array<string, int>
     */
    private function countTheTillsQueries(int $products): array
    {
        $this->stockTheShelves($products);
        $this->warmUp();

        return [
            'till screen' => $this->queriesFor(fn () => $this->get(route('pos.index'))->assertOk()),
            'scan a barcode' => $this->queriesFor(fn () => $this->getJson(route('pos.lookup', ['code' => $this->barcodeFor(1)]))->assertOk()),
            'type an item code' => $this->queriesFor(fn () => $this->getJson(route('pos.lookup', ['code' => 'SKU-1']))->assertOk()),
            'search by name' => $this->queriesFor(fn () => $this->getJson(route('pos.search', ['q' => 'Item']))->assertOk()),
            'find a customer' => $this->queriesFor(fn () => $this->getJson(route('pos.customers.index', ['q' => 'a']))->assertOk()),
            'list held baskets' => $this->queriesFor(fn () => $this->getJson(route('pos.held.index'))->assertOk()),
        ];
    }

    public function test_a_scan_costs_the_same_in_a_big_shop_as_in_a_small_one(): void
    {
        $small = $this->countTheTillsQueries(4);
        $big = $this->countTheTillsQueries(60);

        foreach ($small as $what => $queries) {
            $this->assertSame($queries, $big[$what], "Looking up by {$what} got dearer as the shop grew");
        }
    }

    public function test_nothing_at_the_till_takes_more_queries_than_it_should(): void
    {
        $ceilings = [
            'till screen' => 8,
            'scan a barcode' => 8,
            'type an item code' => 6,
            'search by name' => 5,
            'find a customer' => 2,
            'list held baskets' => 4,
        ];

        foreach ($this->countTheTillsQueries(60) as $what => $queries) {
            $this->assertLessThanOrEqual($ceilings[$what], $queries, "The till now takes {$queries} queries for: {$what}");
        }
    }

    /**
     * A bill costs a fixed amount of work plus a little per line — the lock,
     * the movement, the running balance and the line itself. What it must not
     * cost is anything per product in the catalogue.
     */
    public function test_a_bill_costs_the_same_per_line_however_many_products_the_shop_has(): void
    {
        $this->stockTheShelves(4);
        $this->warmUp();
        $small = $this->queriesForABillOf(3);

        $this->stockTheShelves(60);
        $big = $this->queriesForABillOf(3);

        $this->assertSame($small, $big, 'Ringing up got dearer as the shop grew');

        $longer = $this->queriesForABillOf(6);

        $this->assertLessThanOrEqual(6, intdiv($longer - $big, 3), 'Each extra line on a bill is costing too much');
    }

    private function queriesForABillOf(int $lines): int
    {
        $basket = [];

        foreach (array_slice($this->sachetUnitIds, 0, $lines) as $unitId) {
            $basket[] = ['product_unit_id' => $unitId, 'qty' => '1'];
        }

        return $this->queriesFor(fn () => $this->postJson(route('pos.store'), [
            'register_id' => $this->register->id,
            'lines' => $basket,
            'payments' => [['method' => 'cash', 'amount_paisa' => 1_000_00]],
        ])->assertCreated());
    }

    private function queriesFor(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $work();

        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $queries;
    }

    /**
     * Fill the shop up to the given number of products, each in two sizes
     * with a barcode on each, and put some khata customers and a held basket
     * about the place so the till has something to list.
     */
    private function stockTheShelves(int $products): void
    {
        $stock = app(StockService::class);

        for ($i = count($this->sachetUnitIds) + 1; $i <= $products; $i++) {
            $product = Product::factory()->create([
                'name' => "Item {$i}",
                'sku' => "SKU-{$i}",
                'base_unit_id' => $this->sachet->id,
            ]);

            $sachet = ProductUnit::factory()->base()->create([
                'product_id' => $product->id,
                'unit_id' => $this->sachet->id,
                'sale_price_paisa' => 30_00,
            ]);

            $carton = ProductUnit::factory()->holding(144)->create([
                'product_id' => $product->id,
                'unit_id' => $this->carton->id,
                'sale_price_paisa' => 4_000_00,
            ]);

            Barcode::factory()->create(['product_unit_id' => $sachet->id, 'code' => $this->barcodeFor($i)]);
            Barcode::factory()->create(['product_unit_id' => $carton->id, 'code' => '81000'.$this->serial($i)]);

            $stock->record($product, 144_000, MovementType::Opening, unitCostPaisa: 20_00);

            $this->sachetUnitIds[] = $sachet->id;
        }

        if (Customer::query()->doesntExist()) {
            Customer::factory()->count(40)->create();

            $this->postJson(route('pos.held.store'), [
                'register_id' => $this->register->id,
                'lines' => [['product_unit_id' => $this->sachetUnitIds[0], 'qty' => '2']],
            ])->assertCreated();

            $this->assertTrue(Sale::query()->held()->exists());
        }
    }

    private function barcodeFor(int $serial): string
    {
        return '80000'.$this->serial($serial);
    }

    private function serial(int $number): string
    {
        return str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}
