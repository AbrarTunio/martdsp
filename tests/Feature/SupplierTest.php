<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SupplierEntryType;
use App\Models\Supplier;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A supplier's account. The balance on the screen is a cache of the
 * statement, so every test checks both: the number, and the line that
 * explains it.
 */
class SupplierTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
    }

    public function test_a_supplier_is_added_with_what_was_already_owed(): void
    {
        $this->actingAs($this->owner)
            ->post(route('suppliers.store'), [
                'name' => 'Nestle Distributor',
                'phone' => '0300-1234567',
                'payment_terms_days' => 15,
                'opening_balance' => '25000',
            ])
            ->assertSessionHasNoErrors();

        $supplier = Supplier::sole();

        $this->assertSame(15, $supplier->payment_terms_days);
        $this->assertTrue($supplier->is_active);
        $this->assertSame(2_500_000, $supplier->balance_paisa);
        $this->assertSame(2_500_000, $supplier->opening_balance_paisa);

        $entry = $supplier->ledgerEntries()->sole();
        $this->assertSame(SupplierEntryType::Opening, $entry->type);
        $this->assertSame(2_500_000, $entry->credit_paisa);
        $this->assertSame(2_500_000, $entry->balance_after_paisa);
    }

    public function test_an_advance_already_paid_starts_the_account_below_zero(): void
    {
        $this->actingAs($this->owner)
            ->post(route('suppliers.store'), [
                'name' => 'Shan Foods',
                'phone' => '3451234567',
                'opening_balance' => '5000',
                'opening_is_advance' => '1',
            ])
            ->assertSessionHasNoErrors();

        $supplier = Supplier::sole();

        $this->assertSame(-500_000, $supplier->balance_paisa);
        $this->assertSame(500_000, $supplier->ledgerEntries()->sole()->debit_paisa);
    }

    public function test_a_supplier_owed_nothing_gets_no_opening_line(): void
    {
        $this->actingAs($this->owner)
            ->post(route('suppliers.store'), ['name' => 'Local Bakery', 'phone' => '3211234567'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Supplier::sole()->ledgerEntries()->count());
    }

    public function test_a_payment_comes_off_the_balance_and_says_so_on_the_statement(): void
    {
        $supplier = Supplier::factory()->owed(3_000_000)->create();

        $this->actingAs($this->owner)
            ->post(route('suppliers.payments.store', $supplier), [
                'amount' => '12000',
                'method' => PaymentMethod::BankTransfer->value,
                'paid_on' => today()->toDateString(),
                'note' => 'Meezan transfer',
            ])
            ->assertRedirect(route('suppliers.show', $supplier))
            ->assertSessionHasNoErrors();

        $supplier->refresh();
        $entry = $supplier->ledgerEntries()->where('type', SupplierEntryType::Payment)->sole();

        $this->assertSame(1_800_000, $supplier->balance_paisa);
        $this->assertSame(1_200_000, $entry->debit_paisa);
        $this->assertSame(1_800_000, $entry->balance_after_paisa);
        $this->assertSame(PaymentMethod::BankTransfer, $entry->method);
        $this->assertSame($this->owner->id, $entry->user_id);
    }

    public function test_paying_more_than_owed_leaves_an_advance(): void
    {
        $supplier = Supplier::factory()->owed(100_000)->create();

        $this->actingAs($this->owner)
            ->post(route('suppliers.payments.store', $supplier), [
                'amount' => '1500',
                'method' => PaymentMethod::Cash->value,
                'paid_on' => today()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(-50_000, $supplier->refresh()->balance_paisa);
    }

    public function test_a_payment_ten_times_what_is_owed_is_taken_for_a_typing_mistake(): void
    {
        $supplier = Supplier::factory()->owed(100_000)->create();

        $this->actingAs($this->owner)
            ->post(route('suppliers.payments.store', $supplier), [
                'amount' => '10001',
                'method' => PaymentMethod::Cash->value,
                'paid_on' => today()->toDateString(),
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(100_000, $supplier->refresh()->balance_paisa);
    }

    public function test_a_payment_cannot_be_dated_in_the_future(): void
    {
        $supplier = Supplier::factory()->owed(100_000)->create();

        $this->actingAs($this->owner)
            ->post(route('suppliers.payments.store', $supplier), [
                'amount' => '500',
                'method' => PaymentMethod::Cash->value,
                'paid_on' => today()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('paid_on');
    }

    public function test_a_correction_moves_the_balance_either_way_with_its_reason(): void
    {
        $supplier = Supplier::factory()->owed(100_000)->create();

        $this->actingAs($this->owner)
            ->post(route('suppliers.adjustments.store', $supplier), [
                'amount' => '250',
                'direction' => 'more',
                'note' => 'Bill 771 was not handed over',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(125_000, $supplier->refresh()->balance_paisa);

        $this->post(route('suppliers.adjustments.store', $supplier), [
            'amount' => '50',
            'direction' => 'less',
            'note' => 'Rounded off on the phone',
        ])->assertSessionHasNoErrors();

        $this->assertSame(120_000, $supplier->refresh()->balance_paisa);

        $last = $supplier->ledgerEntries()->latest('id')->first();
        $this->assertSame(SupplierEntryType::Adjustment, $last->type);
        $this->assertSame(5_000, $last->debit_paisa);
        $this->assertSame('Rounded off on the phone', $last->note);
    }

    public function test_a_correction_without_a_reason_is_refused(): void
    {
        $supplier = Supplier::factory()->owed(100_000)->create();

        $this->actingAs($this->owner)
            ->post(route('suppliers.adjustments.store', $supplier), [
                'amount' => '250',
                'direction' => 'less',
                'note' => '',
            ])
            ->assertSessionHasErrors('note');

        $this->assertSame(100_000, $supplier->refresh()->balance_paisa);
    }

    public function test_a_supplier_is_hidden_never_deleted(): void
    {
        $supplier = Supplier::factory()->owed(100_000)->create();

        $this->actingAs($this->owner)
            ->delete(route('suppliers.destroy', $supplier))
            ->assertRedirect(route('suppliers.index'));

        $supplier->refresh();
        $this->assertFalse($supplier->is_active);
        $this->assertSame(100_000, $supplier->balance_paisa);

        $listed = fn ($suppliers): bool => $suppliers->contains('id', $supplier->id);

        $this->get(route('suppliers.index'))->assertOk()->assertViewHas('suppliers', fn ($suppliers) => ! $listed($suppliers));
        $this->get(route('suppliers.index', ['view' => 'hidden']))->assertOk()->assertViewHas('suppliers', $listed);
    }

    public function test_a_supplier_can_be_edited(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'Old Name']);

        $this->actingAs($this->owner)
            ->put(route('suppliers.update', $supplier), [
                'name' => 'New Name',
                'phone' => PhoneNumber::national($supplier->phone),
                'payment_terms_days' => 45,
                'is_active' => '1',
            ])
            ->assertRedirect(route('suppliers.show', $supplier))
            ->assertSessionHasNoErrors();

        $supplier->refresh();
        $this->assertSame('New Name', $supplier->name);
        $this->assertSame(45, $supplier->payment_terms_days);
    }

    public function test_the_supplier_pages_render(): void
    {
        $supplier = Supplier::factory()->owed(100_000)->create(['name' => 'Engro Foods']);

        $this->actingAs($this->owner);

        $this->get(route('suppliers.index'))->assertOk()->assertSee('Engro Foods');
        $this->get(route('suppliers.index', ['view' => 'owed']))->assertOk()->assertSee('Engro Foods');
        $this->get(route('suppliers.show', $supplier))->assertOk()->assertSee('Engro Foods');
        $this->get(route('suppliers.edit', $supplier))->assertOk()->assertSee('Engro Foods');
    }

    public function test_a_cashier_cannot_touch_a_supplier_account(): void
    {
        $supplier = Supplier::factory()->owed(100_000)->create();

        $this->actingAs(User::factory()->cashier()->create());

        $this->get(route('suppliers.show', $supplier))->assertForbidden();
        $this->post(route('suppliers.payments.store', $supplier), [
            'amount' => '500',
            'method' => PaymentMethod::Cash->value,
            'paid_on' => today()->toDateString(),
        ])->assertForbidden();
        $this->post(route('suppliers.store'), ['name' => 'Anyone'])->assertForbidden();

        $this->assertSame(100_000, $supplier->refresh()->balance_paisa);
    }
}
