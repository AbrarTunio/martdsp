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
 * The catalogue screens, and above all the packaging form.
 *
 * A wrong conversion factor is the most expensive mistake this application
 * can make: it is silent, it multiplies through every sale of the item, and
 * nobody notices until the stock count is nonsense. So every way the form can
 * be filled in wrongly is refused here, in the request, not by the database.
 */
class ProductCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private Unit $sachet;

    private Unit $box;

    private Unit $carton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sachet = Unit::factory()->sachet()->create();
        $this->box = Unit::factory()->box()->create();
        $this->carton = Unit::factory()->carton()->create();
    }

    /**
     * The plan's acceptance case: 1 carton = 12 boxes = 24 sachets.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function surfExcelForm(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Surf Excel',
            'name_ur' => 'سرف ایکسل',
            'sku' => 'SM-SURF01',
            'base_unit_id' => $this->sachet->id,
            'tax_rate' => '18',
            'is_active' => '1',
            'reorder_level_base' => '48',
            'reorder_qty_base' => '288',
            'levels' => [
                [
                    'unit_id' => $this->sachet->id,
                    'parent_unit_id' => '',
                    'qty_per_parent' => '1',
                    'sale_price' => '20',
                    'mrp' => '22',
                    'barcodes' => ['8964000101018'],
                ],
                [
                    'unit_id' => $this->box->id,
                    'parent_unit_id' => $this->sachet->id,
                    'qty_per_parent' => '24',
                    'sale_price' => '450',
                    'mrp' => '',
                    'barcodes' => ['8964000101025'],
                ],
                [
                    'unit_id' => $this->carton->id,
                    'parent_unit_id' => $this->box->id,
                    'qty_per_parent' => '12',
                    'sale_price' => '5200',
                    'mrp' => '',
                    'barcodes' => ['8964000101032'],
                ],
            ],
            'default_sale' => '0',
            'default_purchase' => '2',
        ], $overrides);
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    public function test_a_supervisor_can_add_a_product_with_nested_packaging(): void
    {
        $this->actingAs($this->owner())
            ->post(route('products.store'), $this->surfExcelForm())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $product = Product::where('sku', 'SM-SURF01')->firstOrFail();

        $this->assertSame('سرف ایکسل', $product->name_ur);
        $this->assertSame($this->sachet->id, $product->base_unit_id);
        $this->assertSame(48, $product->reorder_level_base);

        $factors = $product->productUnits()->pluck('conversion_factor', 'unit_id');

        $this->assertSame(1, (int) $factors[$this->sachet->id]);
        $this->assertSame(24, (int) $factors[$this->box->id]);
        $this->assertSame(288, (int) $factors[$this->carton->id], 'A carton is 12 boxes of 24 sachets.');
    }

    public function test_prices_are_stored_as_whole_paisa(): void
    {
        $this->actingAs($this->owner())
            ->post(route('products.store'), $this->surfExcelForm([
                'levels' => array_replace_recursive($this->surfExcelForm()['levels'], [
                    0 => ['sale_price' => '20.50'],
                ]),
            ]))
            ->assertSessionHasNoErrors();

        $base = ProductUnit::where('unit_id', $this->sachet->id)->firstOrFail();

        $this->assertSame(2050, $base->sale_price_paisa);
        $this->assertSame(2200, $base->mrp_paisa);
    }

    public function test_the_chosen_defaults_are_marked_on_the_right_levels(): void
    {
        $this->actingAs($this->owner())
            ->post(route('products.store'), $this->surfExcelForm())
            ->assertSessionHasNoErrors();

        $units = ProductUnit::all()->keyBy('unit_id');

        $this->assertTrue((bool) $units[$this->sachet->id]->is_default_sale);
        $this->assertTrue((bool) $units[$this->carton->id]->is_default_purchase);
        $this->assertFalse((bool) $units[$this->box->id]->is_default_sale);
    }

    public function test_every_barcode_on_the_form_is_kept(): void
    {
        $this->actingAs($this->owner())
            ->post(route('products.store'), $this->surfExcelForm())
            ->assertSessionHasNoErrors();

        foreach (['8964000101018', '8964000101025', '8964000101032'] as $code) {
            $this->assertDatabaseHas('barcodes', ['code' => $code]);
        }
    }

    public function test_a_cashier_cannot_add_a_product(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('products.store'), $this->surfExcelForm())
            ->assertForbidden();

        $this->assertDatabaseCount('products', 0);
    }

    public function test_the_form_refuses_the_same_size_listed_twice(): void
    {
        $form = $this->surfExcelForm();
        $form['levels'][2]['unit_id'] = $this->box->id;

        $this->actingAs($this->owner())
            ->post(route('products.store'), $form)
            ->assertSessionHasErrors('levels.2.unit_id');

        $this->assertDatabaseCount('products', 0);
    }

    /**
     * The smallest size has no box to fill in, because it holds nothing but
     * itself. The form must not then ask for one.
     */
    public function test_an_item_sold_in_one_size_only_needs_no_pack_quantity(): void
    {
        $form = $this->surfExcelForm();
        $form['levels'] = [$form['levels'][0]];
        unset($form['levels'][0]['qty_per_parent']);
        $form['default_purchase'] = '0';

        $this->actingAs($this->owner())
            ->post(route('products.store'), $form)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('product_units', [
            'unit_id' => $this->sachet->id,
            'qty_per_parent' => 1,
            'conversion_factor' => 1,
        ]);
    }

    public function test_the_form_refuses_a_pack_that_does_not_say_how_much_it_holds(): void
    {
        $form = $this->surfExcelForm();
        $form['levels'][1]['qty_per_parent'] = '';

        $this->actingAs($this->owner())
            ->post(route('products.store'), $form)
            ->assertSessionHasErrors('levels.1.qty_per_parent');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_the_form_refuses_a_pack_that_contains_nothing(): void
    {
        $form = $this->surfExcelForm();
        $form['levels'][1]['parent_unit_id'] = '';

        $this->actingAs($this->owner())
            ->post(route('products.store'), $form)
            ->assertSessionHasErrors('levels.1.parent_unit_id');
    }

    public function test_the_form_refuses_a_pack_made_of_itself(): void
    {
        $form = $this->surfExcelForm();
        $form['levels'][1]['parent_unit_id'] = $this->box->id;

        $this->actingAs($this->owner())
            ->post(route('products.store'), $form)
            ->assertSessionHasErrors('levels.1.parent_unit_id');
    }

    public function test_the_form_refuses_a_pack_made_of_a_size_not_on_the_form(): void
    {
        $stranger = Unit::factory()->named('Pallet', 'plt')->create();

        $form = $this->surfExcelForm();
        $form['levels'][2]['parent_unit_id'] = $stranger->id;

        $this->actingAs($this->owner())
            ->post(route('products.store'), $form)
            ->assertSessionHasErrors('levels.2.parent_unit_id');
    }

    public function test_the_smallest_unit_must_be_one_of_the_listed_sizes(): void
    {
        $stranger = Unit::factory()->named('Gram', 'g')->create();

        $this->actingAs($this->owner())
            ->post(route('products.store'), $this->surfExcelForm(['base_unit_id' => $stranger->id]))
            ->assertSessionHasErrors('base_unit_id');
    }

    public function test_a_barcode_cannot_be_repeated_within_one_form(): void
    {
        $form = $this->surfExcelForm();
        $form['levels'][1]['barcodes'] = ['8964000101018'];

        $this->actingAs($this->owner())
            ->post(route('products.store'), $form)
            ->assertSessionHasErrors('levels.1.barcodes.0');
    }

    public function test_a_barcode_already_owned_by_another_product_is_refused(): void
    {
        $other = Product::factory()->create(['name' => 'Lifebuoy Soap']);
        Barcode::factory()->create([
            'code' => '8964000101018',
            'product_unit_id' => ProductUnit::factory()->create([
                'product_id' => $other->id,
                'unit_id' => $this->sachet->id,
                'conversion_factor' => 1,
            ])->id,
        ]);

        $this->actingAs($this->owner())
            ->post(route('products.store'), $this->surfExcelForm())
            ->assertSessionHasErrors('levels.0.barcodes.0');
    }

    public function test_a_product_keeps_its_own_barcode_when_it_is_edited(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('products.store'), $this->surfExcelForm())->assertSessionHasNoErrors();

        $product = Product::where('sku', 'SM-SURF01')->firstOrFail();

        $this->actingAs($owner)
            ->patch(route('products.update', $product), $this->surfExcelForm(['name' => 'Surf Excel Easy Wash']))
            ->assertRedirect(route('products.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Surf Excel Easy Wash', $product->fresh()->name);
        $this->assertDatabaseHas('barcodes', ['code' => '8964000101018']);
    }

    public function test_the_smallest_unit_cannot_be_changed_once_stock_is_counted_in_it(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('products.store'), $this->surfExcelForm())->assertSessionHasNoErrors();

        $product = Product::where('sku', 'SM-SURF01')->firstOrFail();

        $this->actingAs($owner)
            ->patch(route('products.update', $product), $this->surfExcelForm(['base_unit_id' => $this->box->id]))
            ->assertSessionHasErrors('base_unit_id');

        $this->assertSame($this->sachet->id, $product->fresh()->base_unit_id);
    }

    public function test_the_sku_of_the_product_being_edited_does_not_clash_with_itself(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('products.store'), $this->surfExcelForm())->assertSessionHasNoErrors();

        $product = Product::where('sku', 'SM-SURF01')->firstOrFail();

        $this->actingAs($owner)
            ->patch(route('products.update', $product), $this->surfExcelForm())
            ->assertSessionHasNoErrors();
    }

    public function test_a_product_is_hidden_rather_than_deleted(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->owner())
            ->delete(route('products.destroy', $product))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => false]);
    }

    public function test_a_cashier_cannot_hide_a_product(): void
    {
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->cashier()->create())
            ->delete(route('products.destroy', $product))
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => true]);
    }

    public function test_the_list_can_be_searched_by_name(): void
    {
        Product::factory()->create(['name' => 'Tapal Danedar']);
        Product::factory()->create(['name' => 'National Chilli Powder']);

        $this->actingAs($this->owner())
            ->get(route('products.index', ['q' => 'Tapal']))
            ->assertOk()
            ->assertSee('Tapal Danedar')
            ->assertDontSee('National Chilli Powder');
    }

    public function test_the_list_can_be_narrowed_to_hidden_items(): void
    {
        Product::factory()->create(['name' => 'Running Item']);
        Product::factory()->inactive()->create(['name' => 'Discontinued Item']);

        $this->actingAs($this->owner())
            ->get(route('products.index', ['status' => 'hidden']))
            ->assertOk()
            ->assertSee('Discontinued Item')
            ->assertDontSee('Running Item');
    }

    public function test_a_cashier_may_read_the_catalogue_for_a_price_check(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('products.index'))
            ->assertOk();
    }

    public function test_a_scanned_code_resolves_to_the_right_level_and_price(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('products.store'), $this->surfExcelForm())->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->getJson(route('products.lookup', ['code' => '8964000101025']))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('product.name', 'Surf Excel')
            ->assertJsonPath('unit.contains', 24)
            ->assertJsonPath('unit.price_paisa', 45000);
    }

    public function test_the_carton_barcode_resolves_to_the_carton_price(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('products.store'), $this->surfExcelForm())->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->getJson(route('products.lookup', ['code' => '8964000101032']))
            ->assertOk()
            ->assertJsonPath('unit.contains', 288)
            ->assertJsonPath('unit.price_paisa', 520000);
    }

    public function test_an_unknown_code_says_so_plainly(): void
    {
        $this->actingAs($this->owner())
            ->getJson(route('products.lookup', ['code' => '0000000000000']))
            ->assertOk()
            ->assertJsonPath('found', false);
    }

    /**
     * The price check is open to cashiers, but it must not hand them an edit
     * link they are not allowed to follow.
     */
    public function test_the_price_check_gives_a_cashier_no_edit_link(): void
    {
        $this->actingAs($this->owner())->post(route('products.store'), $this->surfExcelForm());

        $this->actingAs(User::factory()->cashier()->create())
            ->getJson(route('products.lookup', ['code' => '8964000101018']))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('product.url', null);
    }

    public function test_the_edit_screen_shows_the_packaging_that_was_entered(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('products.store'), $this->surfExcelForm());

        $product = Product::where('sku', 'SM-SURF01')->firstOrFail();

        $this->actingAs($owner)
            ->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('Surf Excel')
            ->assertSee('8964000101032');
    }

    public function test_a_cashier_cannot_open_the_edit_screen(): void
    {
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('products.edit', $product))
            ->assertForbidden();
    }
}
