<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Unit;
use App\Services\PackagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackagingServiceTest extends TestCase
{
    use RefreshDatabase;

    private Unit $sachet;

    private Unit $box;

    private Unit $carton;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sachet = Unit::factory()->sachet()->create();
        $this->box = Unit::factory()->box()->create();
        $this->carton = Unit::factory()->carton()->create();

        $this->product = Product::factory()->create([
            'name' => 'Surf Excel',
            'base_unit_id' => $this->sachet->id,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function surfExcelLevels(): array
    {
        return [
            [
                'unit_id' => $this->sachet->id,
                'parent_unit_id' => null,
                'qty_per_parent' => 1,
                'sale_price_paisa' => 2000,
                'is_default_sale' => true,
                'barcodes' => ['8964000101018'],
            ],
            [
                'unit_id' => $this->box->id,
                'parent_unit_id' => $this->sachet->id,
                'qty_per_parent' => 24,
                'sale_price_paisa' => 45000,
                'barcodes' => ['8964000101025'],
            ],
            [
                'unit_id' => $this->carton->id,
                'parent_unit_id' => $this->box->id,
                'qty_per_parent' => 12,
                'sale_price_paisa' => 520000,
                'is_default_purchase' => true,
                'barcodes' => ['8964000101032'],
            ],
        ];
    }

    public function test_packaging_is_flattened_to_base_units_on_save(): void
    {
        app(PackagingService::class)->sync($this->product, $this->surfExcelLevels());

        $units = $this->product->productUnits()->pluck('conversion_factor', 'unit_id');

        $this->assertSame(1, $units[$this->sachet->id]);
        $this->assertSame(24, $units[$this->box->id]);
        $this->assertSame(288, $units[$this->carton->id]);
    }

    public function test_how_the_shopkeeper_typed_it_is_kept_alongside_the_flattened_number(): void
    {
        app(PackagingService::class)->sync($this->product, $this->surfExcelLevels());

        $carton = $this->product->productUnits()->where('unit_id', $this->carton->id)->sole();

        $this->assertSame($this->box->id, $carton->parent_unit_id);
        $this->assertSame(12, $carton->qty_per_parent);
    }

    public function test_the_base_level_is_added_even_when_the_form_omits_it(): void
    {
        $levels = $this->surfExcelLevels();
        array_shift($levels);

        app(PackagingService::class)->sync($this->product, $levels);

        $base = $this->product->productUnits()->where('unit_id', $this->sachet->id)->sole();

        $this->assertTrue($base->is_base);
        $this->assertSame(1, $base->conversion_factor);
    }

    public function test_removing_a_level_removes_it_from_the_product(): void
    {
        $service = app(PackagingService::class);
        $service->sync($this->product, $this->surfExcelLevels());

        $withoutCarton = array_slice($this->surfExcelLevels(), 0, 2);
        $service->sync($this->product->fresh(), $withoutCarton);

        $this->assertSame(2, $this->product->productUnits()->count());
        $this->assertDatabaseMissing('product_units', [
            'product_id' => $this->product->id,
            'unit_id' => $this->carton->id,
        ]);
    }

    public function test_barcodes_are_attached_to_the_packaging_level_that_carries_them(): void
    {
        app(PackagingService::class)->sync($this->product, $this->surfExcelLevels());

        $box = $this->product->productUnits()->where('unit_id', $this->box->id)->sole();

        $this->assertSame('8964000101025', $box->barcodes()->value('code'));
        $this->assertTrue($box->barcodes()->value('is_primary'));
    }

    public function test_a_barcode_removed_from_the_form_is_deleted(): void
    {
        $service = app(PackagingService::class);
        $service->sync($this->product, $this->surfExcelLevels());

        $levels = $this->surfExcelLevels();
        $levels[1]['barcodes'] = [];
        $service->sync($this->product->fresh(), $levels);

        $this->assertDatabaseMissing('barcodes', ['code' => '8964000101025']);
    }

    /**
     * A product with no sale default cannot be rung up, so one is always
     * chosen even when the form ticks nothing.
     */
    public function test_a_sale_default_is_chosen_when_the_form_ticks_none(): void
    {
        $levels = $this->surfExcelLevels();
        unset($levels[0]['is_default_sale']);

        app(PackagingService::class)->sync($this->product, $levels);

        $this->assertSame(1, $this->product->productUnits()->where('is_default_sale', true)->count());
    }

    public function test_only_one_level_can_be_the_sale_default(): void
    {
        $levels = $this->surfExcelLevels();
        $levels[1]['is_default_sale'] = true;
        $levels[2]['is_default_sale'] = true;

        app(PackagingService::class)->sync($this->product, $levels);

        $this->assertSame(1, $this->product->productUnits()->where('is_default_sale', true)->count());
    }

    public function test_the_shelf_balance_reads_in_real_packaging(): void
    {
        app(PackagingService::class)->sync($this->product, $this->surfExcelLevels());

        $product = Product::with('productUnits.unit')->find($this->product->id);
        $product->forceFill(['stock_qty_base' => 1413])->save();

        $this->assertSame('4 cartons, 10 boxes, 21 sachets', $product->stockBreakdown());
    }

    public function test_saving_twice_does_not_duplicate_levels(): void
    {
        $service = app(PackagingService::class);

        $service->sync($this->product, $this->surfExcelLevels());
        $service->sync($this->product->fresh(), $this->surfExcelLevels());

        $this->assertSame(3, $this->product->productUnits()->count());
        $this->assertSame(3, $this->product->barcodes()->count());
    }
}
