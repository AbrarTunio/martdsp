<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\ReturnReason;
use App\Enums\ReturnSettlement;
use App\Enums\SupplierEntryType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Goods going back to the supplier.
 *
 * They leave the shelf at the average cost, so what stays behind keeps its
 * cost; what the supplier gives back is a separate figure, and the gap is the
 * loss on the return. The account moves by what was given back, never by
 * what the goods cost.
 */
class PurchaseReturnTest extends TestCase
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
        ]);

        $this->carton = ProductUnit::factory()->holding(144)->create([
            'product_id' => $this->product->id,
            'unit_id' => $cartonUnit->id,
        ]);

        /* Ten cartons on the shelf at Rs. 30 a sachet. */
        app(StockService::class)->record($this->product, 1440, MovementType::Opening, unitCostPaisa: 3000);

        $this->supplier = Supplier::factory()->owed(4_320_000)->create();
        $this->owner = User::factory()->owner()->create();
    }

    /**
     * Two cartons back for what they cost, off the account.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function returnForm(array $overrides = []): array
    {
        return array_replace_recursive([
            'supplier_id' => $this->supplier->id,
            'purchase_id' => '',
            'reason' => ReturnReason::Expired->value,
            'settlement' => ReturnSettlement::Credit->value,
            'returned_at' => now()->subMinute()->format('Y-m-d\TH:i'),
            'note' => null,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->carton->id,
                    'qty' => 2,
                    'unit_credit' => '4320',
                ],
            ],
        ], $overrides);
    }

    private function receivedPurchase(Supplier $supplier, string $invoiceNo = 'INV-1'): Purchase
    {
        $this->actingAs($this->owner)->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'invoice_no' => $invoiceNo,
            'purchase_date' => today()->toDateString(),
            'action' => 'receive',
            'items' => [[
                'product_id' => $this->product->id,
                'product_unit_id' => $this->carton->id,
                'qty' => 1,
                'unit_cost' => '4320',
            ]],
        ])->assertSessionHasNoErrors();

        return Purchase::where('invoice_no', $invoiceNo)->sole();
    }

    public function test_goods_sent_back_leave_the_shelf_and_come_off_the_account(): void
    {
        $this->actingAs($this->owner)
            ->post(route('purchases.returns.store'), $this->returnForm())
            ->assertSessionHasNoErrors();

        $return = PurchaseReturn::sole();
        $this->product->refresh();

        $this->assertSame(864_000, $return->total_paisa);
        $this->assertSame(288 * 3000, $return->cost_value_paisa);
        $this->assertSame(0, $return->lossPaisa());

        $this->assertSame(1152, $this->product->stock_qty_base);
        $this->assertSame(3000, $this->product->avg_cost_base_paisa, 'What stays behind keeps its cost');

        $movement = StockMovement::where('type', MovementType::PurchaseReturn)->sole();
        $this->assertSame(-288, $movement->qty_base);
        $this->assertTrue($movement->reference->is($return));

        $this->supplier->refresh();
        $this->assertSame(4_320_000 - 864_000, $this->supplier->balance_paisa);
        $this->assertSame(SupplierEntryType::Return, $this->supplier->ledgerEntries()->sole()->type);
    }

    public function test_less_back_than_the_goods_cost_is_recorded_as_a_loss(): void
    {
        $this->actingAs($this->owner)
            ->post(route('purchases.returns.store'), $this->returnForm([
                'reason' => ReturnReason::Damaged->value,
                'items' => [['unit_credit' => '3000']],
            ]))
            ->assertSessionHasNoErrors();

        $return = PurchaseReturn::sole();

        $this->assertSame(600_000, $return->total_paisa);
        $this->assertSame(864_000 - 600_000, $return->lossPaisa());
        $this->assertSame(4_320_000 - 600_000, $this->supplier->refresh()->balance_paisa);

        $this->get(route('purchases.returns.show', $return))
            ->assertOk()
            ->assertSee($return->reference)
            ->assertSee('Lost on this return');
    }

    public function test_cash_back_shows_on_the_statement_but_leaves_the_balance_alone(): void
    {
        $this->actingAs($this->owner)
            ->post(route('purchases.returns.store'), $this->returnForm([
                'settlement' => ReturnSettlement::Cash->value,
            ]))
            ->assertSessionHasNoErrors();

        $this->supplier->refresh();

        $this->assertSame(4_320_000, $this->supplier->balance_paisa);
        $this->assertSame(
            [SupplierEntryType::Return, SupplierEntryType::Refund],
            $this->supplier->ledgerEntries()->orderBy('id')->pluck('type')->all(),
        );
        $this->assertSame(1152, $this->product->refresh()->stock_qty_base);
    }

    public function test_more_than_the_shelf_holds_cannot_go_back(): void
    {
        $this->actingAs($this->owner)
            ->post(route('purchases.returns.store'), $this->returnForm(['items' => [['qty' => 11]]]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, PurchaseReturn::count());
        $this->assertSame(1440, $this->product->refresh()->stock_qty_base);
        $this->assertSame(4_320_000, $this->supplier->refresh()->balance_paisa);
    }

    public function test_goods_with_no_supplier_can_only_go_back_for_cash(): void
    {
        $this->actingAs($this->owner)
            ->post(route('purchases.returns.store'), $this->returnForm(['supplier_id' => '']))
            ->assertSessionHasErrors('settlement');

        $this->post(route('purchases.returns.store'), $this->returnForm([
            'supplier_id' => '',
            'settlement' => ReturnSettlement::Cash->value,
        ]))->assertSessionHasNoErrors();

        $this->assertNull(PurchaseReturn::sole()->supplier_id);
        $this->assertSame(1152, $this->product->refresh()->stock_qty_base);
    }

    public function test_every_empty_line_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post(route('purchases.returns.store'), $this->returnForm(['items' => [['qty' => 0]]]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, PurchaseReturn::count());
    }

    public function test_opened_from_a_bill_the_form_starts_with_its_items_at_what_was_paid(): void
    {
        $purchase = $this->receivedPurchase($this->supplier);

        $this->actingAs($this->owner)
            ->get(route('purchases.returns.create', ['purchase' => $purchase->id]))
            ->assertOk()
            ->assertViewHas('purchase', fn (?Purchase $shown): bool => $shown?->is($purchase) === true)
            ->assertViewHas('rows', function (array $rows): bool {
                return count($rows) === 1
                    && $rows[0]['product_unit_id'] === $this->carton->id
                    && $rows[0]['qty'] === ''
                    && $rows[0]['unit_cost'] === '4320.00';
            });
    }

    public function test_a_return_against_a_bill_is_linked_to_it(): void
    {
        $purchase = $this->receivedPurchase($this->supplier);

        $this->actingAs($this->owner)
            ->post(route('purchases.returns.store'), $this->returnForm(['purchase_id' => $purchase->id]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(PurchaseReturn::sole()->purchase->is($purchase));

        $this->get(route('purchases.show', $purchase))->assertOk()->assertSee(PurchaseReturn::sole()->reference);
    }

    public function test_a_bill_from_another_supplier_is_refused(): void
    {
        $someoneElse = Supplier::factory()->create();
        $purchase = $this->receivedPurchase($someoneElse);

        $this->actingAs($this->owner)
            ->post(route('purchases.returns.store'), $this->returnForm(['purchase_id' => $purchase->id]))
            ->assertSessionHasErrors('purchase_id');

        $this->assertSame(0, PurchaseReturn::count());
    }

    public function test_the_return_pages_render(): void
    {
        $this->actingAs($this->owner)->post(route('purchases.returns.store'), $this->returnForm());

        $return = PurchaseReturn::sole();

        $this->get(route('purchases.returns.index'))->assertOk()->assertSee($return->reference);
        $this->get(route('purchases.returns.index', ['reason' => ReturnReason::Expired->value]))->assertOk()->assertSee($return->reference);
        $this->get(route('purchases.returns.index', ['reason' => ReturnReason::Damaged->value]))->assertOk()->assertDontSee($return->reference);
        $this->get(route('purchases.returns.show', $return))->assertOk()->assertSee('Surf Excel');
        $this->get(route('suppliers.show', $this->supplier))->assertOk()->assertSee($return->reference);
    }

    public function test_a_cashier_cannot_send_goods_back(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('purchases.returns.store'), $this->returnForm())
            ->assertForbidden();

        $this->assertSame(1440, $this->product->refresh()->stock_qty_base);
    }
}
