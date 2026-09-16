<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierEntryType;
use App\Models\Barcode;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Taking a delivery in.
 *
 * The phase's acceptance case is the first test: a delivery entered by
 * scanning, received, and stock, cost and the supplier's account all right
 * afterwards. The rest defend the arithmetic that makes the cost right — a
 * discount and tax shared over the lines, free goods lowering the cost of
 * every piece — and the guards that keep one paper bill from being counted
 * twice.
 */
class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductUnit $sachet;

    private ProductUnit $carton;

    private Supplier $supplier;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $sachetUnit = Unit::factory()->sachet()->create();
        $cartonUnit = Unit::factory()->carton()->create();

        $this->product = Product::factory()->create([
            'name' => 'Surf Excel',
            'base_unit_id' => $sachetUnit->id,
        ]);

        $this->sachet = ProductUnit::factory()->base()->create([
            'product_id' => $this->product->id,
            'unit_id' => $sachetUnit->id,
            'sale_price_paisa' => 40_00,
        ]);

        $this->carton = ProductUnit::factory()->holding(144)->create([
            'product_id' => $this->product->id,
            'unit_id' => $cartonUnit->id,
            'sale_price_paisa' => 5_760_00,
        ]);

        Barcode::factory()->primary()->create([
            'product_unit_id' => $this->carton->id,
            'code' => '8961000100017',
        ]);

        $this->supplier = Supplier::factory()->terms(30)->create(['name' => 'Unilever Distributor']);
        $this->owner = User::factory()->owner()->create();
    }

    /**
     * Ten cartons at Rs. 4,320, put on account.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function deliveryForm(array $overrides = []): array
    {
        return array_replace_recursive([
            'supplier_id' => $this->supplier->id,
            'invoice_no' => 'INV-5521',
            'purchase_date' => today()->toDateString(),
            'discount' => '',
            'tax' => '',
            'paid' => '',
            'payment_method' => 'cash',
            'note' => null,
            'action' => 'receive',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->carton->id,
                    'qty' => 10,
                    'bonus_qty' => '',
                    'unit_cost' => '4320.00',
                    'batch_no' => '',
                    'expiry_date' => '',
                ],
            ],
        ], $overrides);
    }

    public function test_a_delivery_is_entered_by_scanning_and_received_end_to_end(): void
    {
        $scan = $this->actingAs($this->owner)
            ->getJson(route('purchases.lookup', ['code' => '8961000100017']))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('row.product_id', $this->product->id)
            ->assertJsonPath('row.product_unit_id', $this->carton->id);

        $row = $scan->json('row');

        $this->post(route('purchases.store'), $this->deliveryForm([
            'items' => [[
                'product_id' => $row['product_id'],
                'product_unit_id' => $row['product_unit_id'],
            ]],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $purchase = Purchase::sole();
        $this->product->refresh();
        $this->supplier->refresh();

        $this->assertSame(PurchaseStatus::Received, $purchase->status);
        $this->assertSame(4_320_000, $purchase->total_paisa);
        $this->assertSame(today()->addDays(30)->toDateString(), $purchase->due_on->toDateString());
        $this->assertSame($this->owner->id, $purchase->received_by);

        $this->assertSame(1440, $this->product->stock_qty_base, '10 cartons of 144 sachets');
        $this->assertSame(3000, $this->product->avg_cost_base_paisa, 'Rs. 4,320 over 144 sachets is Rs. 30 each');

        $movement = StockMovement::sole();
        $this->assertSame(MovementType::Purchase, $movement->type);
        $this->assertTrue($movement->reference->is($purchase));

        $this->assertSame(4_320_000, $this->supplier->balance_paisa);
        $this->assertSame(SupplierEntryType::Purchase, $this->supplier->ledgerEntries()->sole()->type);
    }

    public function test_a_new_price_is_blended_into_the_average_cost(): void
    {
        app(StockService::class)->record($this->product, 1440, MovementType::Opening, unitCostPaisa: 2800);

        $this->actingAs($this->owner)
            ->post(route('purchases.store'), $this->deliveryForm())
            ->assertSessionHasNoErrors();

        /* 1,440 at Rs. 28 and 1,440 at Rs. 30 average out at Rs. 29. */
        $this->product->refresh();
        $this->assertSame(2880, $this->product->stock_qty_base);
        $this->assertSame(2900, $this->product->avg_cost_base_paisa);
    }

    public function test_the_discount_tax_and_free_goods_all_land_in_the_cost(): void
    {
        $this->actingAs($this->owner)
            ->post(route('purchases.store'), $this->deliveryForm([
                'discount' => '3200',
                'tax' => '7200',
                'items' => [['bonus_qty' => 2]],
            ]))
            ->assertSessionHasNoErrors();

        $purchase = Purchase::sole();
        $item = $purchase->items()->sole();

        /* Rs. 43,200 − 3,200 + 7,200 = Rs. 47,200 for 12 cartons (two free). */
        $this->assertSame(4_720_000, $purchase->total_paisa);
        $this->assertSame(1728, $item->qty_base);
        $this->assertSame((int) round(4_720_000 / 1728), $item->cost_base_paisa);

        $this->product->refresh();
        $this->assertSame(1728, $this->product->stock_qty_base);
        $this->assertSame($item->cost_base_paisa, $this->product->avg_cost_base_paisa);
        $this->assertSame(4_720_000, $this->supplier->refresh()->balance_paisa);
    }

    public function test_the_discount_is_shared_over_the_lines_by_value(): void
    {
        $other = Product::factory()->create(['base_unit_id' => $this->sachet->unit_id]);
        $otherPiece = ProductUnit::factory()->base()->create([
            'product_id' => $other->id,
            'unit_id' => $this->sachet->unit_id,
        ]);

        $this->actingAs($this->owner)
            ->post(route('purchases.store'), $this->deliveryForm([
                'discount' => '1000',
                'items' => [
                    ['qty' => 1, 'unit_cost' => '3000'],
                    [
                        'product_id' => $other->id,
                        'product_unit_id' => $otherPiece->id,
                        'qty' => 100,
                        'unit_cost' => '10',
                    ],
                ],
            ]))
            ->assertSessionHasNoErrors();

        /* Rs. 3,000 and Rs. 1,000 of goods: the Rs. 1,000 discount splits 750 / 250. */
        $this->assertSame((int) round(225_000 / 144), $this->product->refresh()->avg_cost_base_paisa);
        $this->assertSame(750, $other->refresh()->avg_cost_base_paisa);

        $this->assertSame(300_000, $this->supplier->refresh()->balance_paisa);
    }

    public function test_money_paid_on_delivery_comes_straight_off_the_account(): void
    {
        $this->actingAs($this->owner)
            ->post(route('purchases.store'), $this->deliveryForm(['paid' => '20000']))
            ->assertSessionHasNoErrors();

        $this->supplier->refresh();

        $this->assertSame(2_320_000, $this->supplier->balance_paisa);
        $this->assertSame(
            [SupplierEntryType::Purchase, SupplierEntryType::Payment],
            $this->supplier->ledgerEntries()->orderBy('id')->pluck('type')->all(),
        );
        $this->assertSame(2_320_000, Purchase::sole()->unpaidPaisa());
    }

    public function test_goods_bought_at_the_market_are_paid_in_full_and_owe_nobody(): void
    {
        $this->actingAs($this->owner)
            ->post(route('purchases.store'), $this->deliveryForm([
                'supplier_id' => '',
                'invoice_no' => '',
                'paid' => '',
            ]))
            ->assertSessionHasNoErrors();

        $purchase = Purchase::sole();

        $this->assertNull($purchase->supplier_id);
        $this->assertSame($purchase->total_paisa, $purchase->paid_paisa);
        $this->assertNull($purchase->due_on);
        $this->assertSame(1440, $this->product->refresh()->stock_qty_base);
        $this->assertSame(0, $this->supplier->refresh()->ledgerEntries()->count());
    }

    public function test_a_draft_changes_nothing_until_it_is_received(): void
    {
        $this->actingAs($this->owner)
            ->post(route('purchases.store'), $this->deliveryForm(['action' => 'draft']))
            ->assertSessionHasNoErrors();

        $purchase = Purchase::sole();

        $this->assertSame(PurchaseStatus::Draft, $purchase->status);
        $this->assertSame(0, $this->product->refresh()->stock_qty_base);
        $this->assertSame(0, $this->supplier->refresh()->balance_paisa);

        $this->put(route('purchases.update', $purchase), $this->deliveryForm([
            'action' => 'draft',
            'items' => [['qty' => 12]],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(5_184_000, $purchase->refresh()->total_paisa);

        $this->post(route('purchases.receive', $purchase))->assertRedirect(route('purchases.show', $purchase));

        $this->assertSame(PurchaseStatus::Received, $purchase->refresh()->status);
        $this->assertSame(1728, $this->product->refresh()->stock_qty_base);
        $this->assertSame(5_184_000, $this->supplier->refresh()->balance_paisa);
    }

    public function test_a_received_purchase_cannot_be_edited_received_again_or_cancelled(): void
    {
        $this->actingAs($this->owner)->post(route('purchases.store'), $this->deliveryForm());

        $purchase = Purchase::sole();

        $this->get(route('purchases.edit', $purchase))->assertForbidden();
        $this->put(route('purchases.update', $purchase), $this->deliveryForm())->assertForbidden();

        $this->post(route('purchases.receive', $purchase))->assertSessionHasErrors('purchase');
        $this->delete(route('purchases.destroy', $purchase))->assertSessionHasErrors('purchase');

        $this->assertSame(1440, $this->product->refresh()->stock_qty_base);
        $this->assertSame(4_320_000, $this->supplier->refresh()->balance_paisa);
    }

    public function test_a_draft_can_be_cancelled_without_touching_anything(): void
    {
        $this->actingAs($this->owner)->post(route('purchases.store'), $this->deliveryForm(['action' => 'draft']));

        $purchase = Purchase::sole();

        $this->delete(route('purchases.destroy', $purchase))->assertRedirect(route('purchases.index'));

        $this->assertSame(PurchaseStatus::Cancelled, $purchase->refresh()->status);
        $this->post(route('purchases.receive', $purchase))->assertSessionHasErrors('purchase');
        $this->assertSame(0, $this->product->refresh()->stock_qty_base);
    }

    public function test_the_same_paper_bill_cannot_be_entered_twice(): void
    {
        $this->actingAs($this->owner)->post(route('purchases.store'), $this->deliveryForm());

        $this->post(route('purchases.store'), $this->deliveryForm())
            ->assertSessionHasErrors('invoice_no');

        $this->assertSame(1, Purchase::count());
        $this->assertSame(1440, $this->product->refresh()->stock_qty_base);
    }

    public function test_an_item_tracked_by_expiry_needs_its_date_only_when_received(): void
    {
        $this->product->update(['track_expiry' => true]);

        $this->actingAs($this->owner)
            ->post(route('purchases.store'), $this->deliveryForm())
            ->assertSessionHasErrors('items.0.expiry_date');

        $this->post(route('purchases.store'), $this->deliveryForm(['action' => 'draft']))
            ->assertSessionHasNoErrors();

        $this->post(route('purchases.store'), $this->deliveryForm([
            'invoice_no' => 'INV-5522',
            'items' => [['expiry_date' => today()->addYear()->toDateString(), 'batch_no' => 'B-77']],
        ]))->assertSessionHasNoErrors();

        $received = Purchase::where('status', PurchaseStatus::Received)->sole();
        $this->assertSame('B-77', $received->items()->sole()->batch_no);
    }

    public function test_the_bill_must_add_up(): void
    {
        $this->actingAs($this->owner)
            ->post(route('purchases.store'), $this->deliveryForm(['discount' => '50000']))
            ->assertSessionHasErrors('discount');

        $this->post(route('purchases.store'), $this->deliveryForm(['paid' => '50000']))
            ->assertSessionHasErrors('paid');

        $this->post(route('purchases.store'), $this->deliveryForm(['items' => [['qty' => 0]]]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Purchase::count());
    }

    public function test_a_size_from_another_item_is_refused(): void
    {
        $other = ProductUnit::factory()->base()->create();

        $this->actingAs($this->owner)
            ->post(route('purchases.store'), $this->deliveryForm(['items' => [['product_unit_id' => $other->id]]]))
            ->assertSessionHasErrors('items.0.product_unit_id');
    }

    public function test_an_unknown_barcode_offers_to_add_the_product_on_the_spot(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('purchases.lookup', ['code' => '8969999000012']))
            ->assertOk()
            ->assertJsonPath('found', false)
            ->assertJsonPath('is_barcode', true);

        $created = $this->postJson(route('purchases.quick-product'), [
            'code' => '8969999000012',
            'name' => 'Lays Masala 20g',
            'category_id' => null,
            'base_unit_id' => $this->sachet->unit_id,
            'pack_unit_id' => $this->carton->unit_id,
            'qty_per_pack' => 48,
            'scanned_is' => 'pack',
            'sale_price' => '30',
        ])->assertCreated()->assertJsonPath('found', true);

        $product = Product::where('name', 'Lays Masala 20g')->sole();
        $carton = $product->productUnits()->where('unit_id', $this->carton->unit_id)->sole();

        $this->assertSame($carton->id, $created->json('row.product_unit_id'));
        $this->assertSame(48, $carton->conversion_factor);
        $this->assertSame(1_440_00, $carton->sale_price_paisa);

        $this->getJson(route('purchases.lookup', ['code' => '8969999000012']))
            ->assertJsonPath('found', true)
            ->assertJsonPath('row.product_unit_id', $carton->id);
    }

    public function test_a_typed_name_finds_the_item_but_a_partial_barcode_does_not(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('purchases.lookup', ['code' => 'surf']))
            ->assertJsonPath('found', true)
            ->assertJsonPath('row.product_id', $this->product->id);

        $this->getJson(route('purchases.lookup', ['code' => '89610001']))
            ->assertJsonPath('found', false);
    }

    public function test_the_last_price_paid_is_offered_on_the_next_delivery(): void
    {
        $this->actingAs($this->owner)->post(route('purchases.store'), $this->deliveryForm());

        $this->getJson(route('purchases.lookup', ['code' => '8961000100017']))
            ->assertJsonPath('row.unit_cost', '4320.00');
    }

    public function test_the_purchase_pages_render(): void
    {
        $this->actingAs($this->owner)->post(route('purchases.store'), $this->deliveryForm(['action' => 'draft']));

        $purchase = Purchase::sole();

        $this->get(route('purchases.show', $purchase))->assertOk()->assertSee($purchase->reference)->assertSee('Surf Excel');
        $this->get(route('purchases.edit', $purchase))->assertOk()->assertSee('Surf Excel');
        $this->get(route('purchases.index'))->assertOk()->assertSee($purchase->reference);

        $this->post(route('purchases.receive', $purchase));

        $this->get(route('purchases.show', $purchase))->assertOk()->assertSee('Send some back');
        $this->get(route('suppliers.show', $this->supplier))->assertOk()->assertSee($purchase->reference);
    }

    public function test_a_cashier_cannot_enter_a_delivery(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('purchases.store'), $this->deliveryForm())
            ->assertForbidden();

        $this->assertSame(0, Purchase::count());
    }
}
