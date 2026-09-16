<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StarterCatalogueService;
use Database\Seeders\CatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Seed Products" button.
 *
 * It puts the everyday grocery list in once, with packing and prices but no
 * stock, and from then on it only says "Already seeded". A second press must
 * not bring back products the shop removed or reset prices it has changed.
 */
class StarterCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
    }

    public function test_a_new_shop_is_offered_the_button(): void
    {
        $this->actingAs($this->owner)
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('Seed Products')
            ->assertDontSee('Already seeded');
    }

    public function test_pressing_it_puts_every_product_in_with_its_packing_and_no_stock(): void
    {
        $this->actingAs($this->owner)
            ->post(route('products.starter'))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('status');

        $list = app(StarterCatalogueService::class)->products();

        $this->assertSame(count($list), Product::count());
        $this->assertGreaterThan(200, count($list));
        $this->assertSame(0, Product::whereNull('category_id')->count(), 'Every product lands in one of the shop\'s categories.');
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, (int) Product::sum('stock_qty_base'));
        $this->assertTrue(ActivityLog::where('action', 'catalogue.seeded')->exists());

        foreach (Product::with('productUnits')->get() as $product) {
            $this->assertSame(1, $product->productUnits->where('is_default_sale', true)->count(), $product->name);
            $this->assertSame(1, $product->productUnits->where('is_default_purchase', true)->count(), $product->name);
            $this->assertCount(0, $product->barcodes, $product->name);
        }
    }

    public function test_packs_are_counted_and_priced_from_the_piece(): void
    {
        $this->actingAs($this->owner)->post(route('products.starter'));

        $shampoo = Product::where('sku', 'SUNSILK-SACHET')->sole();
        $carton = $shampoo->productUnits->firstWhere('is_default_purchase', true);

        $this->assertSame(16 * 24, (int) $carton->conversion_factor);
        $this->assertSame(20_00 * 16 * 24, (int) $carton->sale_price_paisa);

        $atta = Product::where('sku', 'ATTA-CHAKKI')->sole();

        $this->assertTrue($atta->is_weighted);
        $this->assertSame(140_00, (int) $atta->defaultSaleUnit()->sale_price_paisa, 'Loose atta is rung up at its kilo price.');
        $this->assertSame('0.00', (string) $atta->tax_rate);
    }

    public function test_after_it_is_done_the_button_says_so_and_a_second_press_changes_nothing(): void
    {
        $this->actingAs($this->owner)->post(route('products.starter'));

        $count = Product::count();
        Product::where('sku', 'LAYS-MASALA')->delete();

        $this->actingAs($this->owner)
            ->get(route('products.index'))
            ->assertSee('Already seeded')
            ->assertDontSee('Seed Products');

        $this->actingAs($this->owner)
            ->post(route('products.starter'))
            ->assertRedirect(route('products.index'));

        $this->assertSame($count - 1, Product::count());
        $this->assertSame(1, ActivityLog::where('action', 'catalogue.seeded')->count());
    }

    public function test_products_the_shop_already_has_are_left_alone(): void
    {
        $this->seed(CatalogueSeeder::class);
        $mine = Product::factory()->create(['name' => 'Dalda Cooking Oil 1 litre', 'sku' => 'MY-DALDA']);

        $this->actingAs($this->owner)->post(route('products.starter'));

        $listed = count(app(StarterCatalogueService::class)->products());

        $this->assertSame($listed, Product::count(), "The list, less the one the shop had, plus the shop's own.");
        $this->assertSame($mine->getKey(), Product::where('name', 'Dalda Cooking Oil 1 litre')->sole()->getKey());
    }

    public function test_a_cashier_cannot_press_it(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('products.starter'))
            ->assertForbidden();

        $this->assertSame(0, Product::count());
    }
}
