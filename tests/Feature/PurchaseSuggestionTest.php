<?php

namespace Tests\Feature;

use App\Enums\PurchaseStatus;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reorder list. It is read on the phone to one salesman at a time, so
 * the grouping by last supplier matters as much as the quantities.
 */
class PurchaseSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private Unit $sachetUnit;

    private Unit $cartonUnit;

    private Supplier $supplier;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sachetUnit = Unit::factory()->sachet()->create();
        $this->cartonUnit = Unit::factory()->carton()->create();
        $this->supplier = Supplier::factory()->create(['name' => 'Unilever Distributor']);
        $this->owner = User::factory()->owner()->create();
    }

    /**
     * An item sold by the sachet and bought by the carton of 144.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: Product, 1: ProductUnit}
     */
    private function cartonItem(array $attributes = []): array
    {
        $product = Product::factory()->reorderAt(144)->create($attributes + ['base_unit_id' => $this->sachetUnit->id]);

        ProductUnit::factory()->base()->create(['product_id' => $product->id, 'unit_id' => $this->sachetUnit->id]);

        $carton = ProductUnit::factory()->holding(144)->create([
            'product_id' => $product->id,
            'unit_id' => $this->cartonUnit->id,
        ]);

        return [$product, $carton];
    }

    /**
     * Buy one carton from the supplier at Rs. 4,320, then let the shelf run
     * down to what the test needs.
     */
    private function boughtFromSupplierAndSoldDownTo(Product $product, ProductUnit $carton, int $stockBase): void
    {
        $this->actingAs($this->owner)->post(route('purchases.store'), [
            'supplier_id' => $this->supplier->id,
            'invoice_no' => 'INV-'.$product->id,
            'purchase_date' => today()->toDateString(),
            'action' => 'receive',
            'items' => [[
                'product_id' => $product->id,
                'product_unit_id' => $carton->id,
                'qty' => 1,
                'unit_cost' => '4320',
            ]],
        ])->assertSessionHasNoErrors();

        $product->forceFill(['stock_qty_base' => $stockBase])->save();
    }

    public function test_low_items_are_grouped_under_the_supplier_they_last_came_from(): void
    {
        [$surf, $surfCarton] = $this->cartonItem(['name' => 'Surf Excel']);
        [$neverBought] = $this->cartonItem(['name' => 'Vim Bar']);
        [$plenty, $plentyCarton] = $this->cartonItem(['name' => 'Lux Soap']);
        [$hidden] = $this->cartonItem(['name' => 'Old Stock', 'is_active' => false]);

        $this->boughtFromSupplierAndSoldDownTo($surf, $surfCarton, 20);
        $this->boughtFromSupplierAndSoldDownTo($plenty, $plentyCarton, 500);

        $groups = app(PurchaseSuggestionService::class)->grouped();

        $this->assertCount(2, $groups);

        $this->assertTrue($groups[0]['supplier']->is($this->supplier));
        $this->assertSame(['Surf Excel'], $groups[0]['lines']->map(fn (array $line): string => $line['product']->name)->all());

        $surfLine = $groups[0]['lines'][0];
        $this->assertSame(2 * 144 - 20, $surfLine['need_base']);
        $this->assertTrue($surfLine['unit']->is($surfCarton));
        $this->assertSame(2, $surfLine['packs'], '268 sachets is two cartons, rounded up');
        $this->assertSame(432_000, $surfLine['cost_per_pack_paisa']);
        $this->assertSame(864_000, $groups[0]['total_paisa']);

        $this->assertNull($groups[1]['supplier'], 'Items never bought through the system come last');
        $this->assertTrue($groups[1]['lines'][0]['product']->is($neverBought));

        $listed = $groups->flatMap(fn (array $group) => $group['lines'])->map(fn (array $line): int => $line['product']->id);
        $this->assertNotContains($plenty->id, $listed);
        $this->assertNotContains($hidden->id, $listed);
    }

    public function test_the_items_own_reorder_quantity_wins_and_covers_any_shortfall(): void
    {
        $service = app(PurchaseSuggestionService::class);

        $this->assertSame(288, $service->needBase(Product::factory()->make([
            'reorder_level_base' => 144, 'reorder_qty_base' => 288, 'stock_qty_base' => 50,
        ])));

        $this->assertSame(298, $service->needBase(Product::factory()->make([
            'reorder_level_base' => 144, 'reorder_qty_base' => 288, 'stock_qty_base' => -10,
        ])));

        $this->assertSame(200, $service->needBase(Product::factory()->make([
            'reorder_level_base' => 100, 'reorder_qty_base' => 0, 'stock_qty_base' => 0,
        ])));
    }

    public function test_ticked_items_become_a_draft_for_the_chosen_supplier(): void
    {
        [$surf, $surfCarton] = $this->cartonItem(['name' => 'Surf Excel']);
        [$vim] = $this->cartonItem(['name' => 'Vim Bar']);

        $this->boughtFromSupplierAndSoldDownTo($surf, $surfCarton, 20);

        $response = $this->actingAs($this->owner)
            ->post(route('purchases.suggestions.store'), [
                'supplier_id' => $this->supplier->id,
                'product_ids' => [$surf->id],
            ]);

        $draft = Purchase::where('status', PurchaseStatus::Draft)->sole();

        $response->assertRedirect(route('purchases.edit', $draft));

        $this->assertTrue($draft->supplier->is($this->supplier));
        $item = $draft->items()->sole();
        $this->assertSame($surf->id, $item->product_id);
        $this->assertSame($surfCarton->id, $item->product_unit_id);
        $this->assertSame(2, $item->qty);
        $this->assertSame(432_000, $item->unit_cost_paisa);
        $this->assertSame(864_000, $draft->total_paisa);

        $this->assertSame(20, $surf->refresh()->stock_qty_base, 'A draft does not touch the shelf');
        $this->assertFalse($draft->items()->where('product_id', $vim->id)->exists(), 'Only ticked items are ordered');
    }

    public function test_items_no_longer_low_leave_nothing_to_order(): void
    {
        [$product] = $this->cartonItem();
        $product->forceFill(['stock_qty_base' => 1000])->save();

        $this->actingAs($this->owner)
            ->post(route('purchases.suggestions.store'), ['product_ids' => [$product->id]])
            ->assertSessionHasErrors('product_ids');

        $this->post(route('purchases.suggestions.store'), ['product_ids' => []])
            ->assertSessionHasErrors('product_ids');

        $this->assertSame(0, Purchase::count());
    }

    public function test_the_reorder_list_renders_grouped_by_supplier(): void
    {
        [$surf, $surfCarton] = $this->cartonItem(['name' => 'Surf Excel']);
        $this->cartonItem(['name' => 'Vim Bar']);

        $this->boughtFromSupplierAndSoldDownTo($surf, $surfCarton, 20);

        $this->actingAs($this->owner)
            ->get(route('purchases.suggestions.index'))
            ->assertOk()
            ->assertSeeInOrder(['Unilever Distributor', 'Surf Excel', 'Vim Bar']);
    }
}
