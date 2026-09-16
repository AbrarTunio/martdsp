<?php

namespace Tests\Feature;

use App\Models\Barcode;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Printing shelf and pack labels.
 *
 * Each packaging level gets its own label because a carton and the sachet
 * inside it are different prices, and a level with no barcode of its own must
 * still be labelable — otherwise freshly entered loose stock can never reach
 * the shelf.
 */
class BarcodeLabelTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductUnit $sachetLevel;

    private ProductUnit $boxLevel;

    protected function setUp(): void
    {
        parent::setUp();

        $sachet = Unit::factory()->sachet()->create();
        $box = Unit::factory()->box()->create();

        $this->product = Product::factory()->create([
            'name' => 'Surf Excel',
            'sku' => 'SM-SURF01',
            'base_unit_id' => $sachet->id,
        ]);

        $this->sachetLevel = ProductUnit::factory()->create([
            'product_id' => $this->product->id,
            'unit_id' => $sachet->id,
            'conversion_factor' => 1,
            'sale_price_paisa' => 2000,
            'is_default_sale' => true,
        ]);

        $this->boxLevel = ProductUnit::factory()->create([
            'product_id' => $this->product->id,
            'unit_id' => $box->id,
            'conversion_factor' => 24,
            'sale_price_paisa' => 45000,
        ]);

        Barcode::factory()->primary()->create([
            'product_unit_id' => $this->sachetLevel->id,
            'code' => '8964000101018',
        ]);
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    public function test_the_picker_finds_a_product_by_name(): void
    {
        $this->actingAs($this->owner())
            ->get(route('products.labels.create', ['q' => 'Surf']))
            ->assertOk()
            ->assertSee('Surf Excel');
    }

    public function test_the_picker_shows_every_packaging_level_of_the_chosen_product(): void
    {
        $this->actingAs($this->owner())
            ->get(route('products.labels.create', ['product' => $this->product->id]))
            ->assertOk()
            ->assertSee('Sachet')
            ->assertSee('Box');
    }

    public function test_a_sheet_prints_one_label_per_copy_asked_for(): void
    {
        $response = $this->actingAs($this->owner())->get(route('products.labels.sheet', [
            'copies' => [$this->sachetLevel->id => 3],
            'size' => 'a4',
            'show_price' => 1,
        ]));

        $response->assertOk()->assertViewHas('labels');

        $this->assertCount(3, $response->viewData('labels'));
    }

    public function test_a_level_with_no_barcode_falls_back_to_the_item_code(): void
    {
        $response = $this->actingAs($this->owner())->get(route('products.labels.sheet', [
            'copies' => [$this->boxLevel->id => 1],
            'size' => 'a4',
        ]));

        $response->assertOk()->assertSee('SM-SURF01');

        $this->assertSame('SM-SURF01', $response->viewData('labels')[0]['code']);
    }

    public function test_the_sheet_draws_the_barcode_as_an_svg(): void
    {
        $this->actingAs($this->owner())
            ->get(route('products.labels.sheet', [
                'copies' => [$this->sachetLevel->id => 1],
                'size' => 'a4',
            ]))
            ->assertOk()
            ->assertSee('<svg', false)
            ->assertSee('8964000101018');
    }

    public function test_the_price_is_left_off_when_it_was_not_asked_for(): void
    {
        $this->actingAs($this->owner())
            ->get(route('products.labels.sheet', [
                'copies' => [$this->sachetLevel->id => 1],
                'size' => 'a4',
                'show_price' => 0,
            ]))
            ->assertOk()
            ->assertDontSee('Rs 20.00');
    }

    public function test_the_price_is_printed_when_it_was_asked_for(): void
    {
        $this->actingAs($this->owner())
            ->get(route('products.labels.sheet', [
                'copies' => [$this->sachetLevel->id => 1],
                'size' => 'a4',
                'show_price' => 1,
            ]))
            ->assertOk()
            ->assertSee('20.00');
    }

    /**
     * A thermal roll is one label across; A4 stationery is three. The sheet
     * has to be laid out in millimetres for either.
     */
    public function test_the_thermal_roll_uses_its_own_page_size(): void
    {
        $response = $this->actingAs($this->owner())->get(route('products.labels.sheet', [
            'copies' => [$this->sachetLevel->id => 1],
            'size' => 'thermal',
        ]));

        $response->assertOk()->assertSee('50mm 30mm', false);

        $this->assertSame(1, $response->viewData('size')['columns']);
    }

    public function test_an_unknown_paper_size_is_refused(): void
    {
        $this->actingAs($this->owner())
            ->get(route('products.labels.sheet', [
                'copies' => [$this->sachetLevel->id => 1],
                'size' => 'poster',
            ]))
            ->assertSessionHasErrors('size');
    }

    public function test_levels_asked_for_zero_copies_are_skipped(): void
    {
        $response = $this->actingAs($this->owner())->get(route('products.labels.sheet', [
            'copies' => [$this->sachetLevel->id => 2, $this->boxLevel->id => 0],
            'size' => 'a4',
        ]));

        $response->assertOk();

        $this->assertCount(2, $response->viewData('labels'));
    }

    public function test_a_cashier_cannot_print_labels(): void
    {
        $cashier = User::factory()->cashier()->create();

        $this->actingAs($cashier)->get(route('products.labels.create'))->assertForbidden();

        $this->actingAs($cashier)
            ->get(route('products.labels.sheet', ['copies' => [$this->sachetLevel->id => 1], 'size' => 'a4']))
            ->assertForbidden();
    }
}
