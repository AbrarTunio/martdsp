<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Categories, brands and units — the short lists the product form draws on.
 *
 * The rule that matters across all three: nothing in use may be removed. A
 * shopkeeper tidying the list must not be able to orphan a product.
 */
class CatalogueVocabularyTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    public function test_a_category_can_be_added(): void
    {
        $this->actingAs($this->owner())
            ->post(route('products.categories.store'), [
                'name' => 'Beverages',
                'name_ur' => 'مشروبات',
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('categories', ['name' => 'Beverages', 'parent_id' => null]);
    }

    public function test_a_category_can_sit_under_another(): void
    {
        $parent = Category::factory()->create(['name' => 'Beverages']);

        $this->actingAs($this->owner())
            ->post(route('products.categories.store'), [
                'name' => 'Soft Drinks',
                'parent_id' => $parent->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('categories', ['name' => 'Soft Drinks', 'parent_id' => $parent->id]);
    }

    public function test_two_top_level_categories_cannot_share_a_name(): void
    {
        Category::factory()->create(['name' => 'Beverages']);

        $this->actingAs($this->owner())
            ->post(route('products.categories.store'), ['name' => 'Beverages'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Category::count());
    }

    /**
     * The same child name under two different parents is fine — "Water" can
     * live under both Beverages and Household.
     */
    public function test_the_same_name_is_allowed_under_different_parents(): void
    {
        $beverages = Category::factory()->create(['name' => 'Beverages']);
        $household = Category::factory()->create(['name' => 'Household']);

        Category::factory()->create(['name' => 'Water', 'parent_id' => $beverages->id]);

        $this->actingAs($this->owner())
            ->post(route('products.categories.store'), ['name' => 'Water', 'parent_id' => $household->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Category::where('name', 'Water')->count());
    }

    public function test_a_category_cannot_be_put_inside_itself(): void
    {
        $category = Category::factory()->create(['name' => 'Beverages']);

        $this->actingAs($this->owner())
            ->patch(route('products.categories.update', $category), [
                'name' => 'Beverages',
                'parent_id' => $category->id,
            ])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_a_category_holding_products_cannot_be_removed(): void
    {
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id]);

        $this->actingAs($this->owner())
            ->delete(route('products.categories.destroy', $category))
            ->assertSessionHasErrors('category');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_a_category_holding_sub_categories_cannot_be_removed(): void
    {
        $parent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id]);

        $this->actingAs($this->owner())
            ->delete(route('products.categories.destroy', $parent))
            ->assertSessionHasErrors('category');

        $this->assertDatabaseHas('categories', ['id' => $parent->id]);
    }

    public function test_an_unused_category_can_be_removed(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->owner())
            ->delete(route('products.categories.destroy', $category))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_a_cashier_cannot_touch_the_category_list(): void
    {
        $cashier = User::factory()->cashier()->create();
        $category = Category::factory()->create();

        $this->actingAs($cashier)->get(route('products.categories.index'))->assertForbidden();
        $this->actingAs($cashier)->post(route('products.categories.store'), ['name' => 'Smuggled'])->assertForbidden();
        $this->actingAs($cashier)->delete(route('products.categories.destroy', $category))->assertForbidden();
    }

    public function test_a_brand_can_be_added(): void
    {
        $this->actingAs($this->owner())
            ->post(route('products.brands.store'), ['name' => 'Tapal'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('brands', ['name' => 'Tapal']);
    }

    public function test_two_brands_cannot_share_a_name(): void
    {
        Brand::factory()->create(['name' => 'Tapal']);

        $this->actingAs($this->owner())
            ->post(route('products.brands.store'), ['name' => 'Tapal'])
            ->assertSessionHasErrors('name');
    }

    public function test_a_brand_in_use_cannot_be_removed(): void
    {
        $brand = Brand::factory()->create();
        Product::factory()->create(['brand_id' => $brand->id]);

        $this->actingAs($this->owner())
            ->delete(route('products.brands.destroy', $brand))
            ->assertSessionHasErrors('brand');

        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
    }

    public function test_a_unit_can_be_added(): void
    {
        $this->actingAs($this->owner())
            ->post(route('products.units.store'), [
                'name' => 'Crate',
                'short_name' => 'crt',
                'type' => 'count',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('units', ['name' => 'Crate', 'short_name' => 'crt']);
    }

    public function test_a_unit_needs_a_short_form_for_the_receipt_line(): void
    {
        $this->actingAs($this->owner())
            ->post(route('products.units.store'), ['name' => 'Crate', 'type' => 'count'])
            ->assertSessionHasErrors('short_name');
    }

    public function test_a_unit_used_by_a_product_cannot_be_removed(): void
    {
        $unit = Unit::factory()->sachet()->create();
        $product = Product::factory()->create(['base_unit_id' => $unit->id]);
        ProductUnit::factory()->create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'conversion_factor' => 1,
        ]);

        $this->actingAs($this->owner())
            ->delete(route('products.units.destroy', $unit))
            ->assertSessionHasErrors('unit');

        $this->assertDatabaseHas('units', ['id' => $unit->id]);
    }

    public function test_an_unused_unit_can_be_removed(): void
    {
        $unit = Unit::factory()->named('Pallet', 'plt')->create();

        $this->actingAs($this->owner())
            ->delete(route('products.units.destroy', $unit))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('units', ['id' => $unit->id]);
    }

    public function test_a_cashier_cannot_touch_the_unit_list(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('products.units.store'), ['name' => 'Crate', 'short_name' => 'crt', 'type' => 'count'])
            ->assertForbidden();
    }
}
