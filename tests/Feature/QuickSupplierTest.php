<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding — and adding — the salesman at the door.
 *
 * The first delivery a shop enters is nearly always from somebody nobody has
 * had a chance to type in yet, and the van does not wait. So the delivery
 * page can add a supplier on the spot; and because a supplier added twice
 * means half the money owed sits on an account nobody is looking at, the
 * phone number is what the shop is stopped from entering twice.
 */
class QuickSupplierTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
    }

    public function test_a_supplier_is_added_from_the_delivery_page_and_comes_back_ready_to_use(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson(route('purchases.quick-supplier'), [
                'name' => 'Haji Abdullah',
                'company' => 'Al-Madina Traders',
                'phone' => '3343401969',
                'payment_terms_days' => 15,
            ])
            ->assertCreated();

        $supplier = Supplier::sole();

        $this->assertSame('+923343401969', $supplier->phone);
        $this->assertSame(15, $supplier->payment_terms_days);
        $this->assertTrue($supplier->is_active);
        $this->assertSame(0, $supplier->ledgerEntries()->count());

        $response->assertJsonPath('supplier.id', $supplier->id)
            ->assertJsonPath('supplier.label', 'Haji Abdullah (Al-Madina Traders)')
            ->assertJsonPath('supplier.phone', '+92 334 3401969')
            ->assertJsonPath('supplier.terms', 15)
            ->assertJsonPath('supplier.balance_paisa', 0);
    }

    public function test_a_number_dialled_the_way_it_is_at_home_is_stored_the_one_way(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('purchases.quick-supplier'), [
                'name' => 'Khursheed Traders',
                'phone' => '0334 340 1969',
            ])
            ->assertCreated();

        $this->assertSame('+923343401969', Supplier::sole()->phone);
    }

    public function test_the_same_number_cannot_be_given_to_two_suppliers(): void
    {
        Supplier::factory()->create(['name' => 'Khursheed Traders', 'phone' => '+923343401969']);

        $this->actingAs($this->owner)
            ->postJson(route('purchases.quick-supplier'), [
                'name' => 'Khursheed Sons',
                'phone' => '03343401969',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertSame(1, Supplier::count());
    }

    public function test_a_number_that_is_not_pakistani_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('purchases.quick-supplier'), ['name' => 'Somebody', 'phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertSame(0, Supplier::count());
    }

    public function test_a_supplier_cannot_be_added_without_a_number_to_tell_them_apart_by(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('purchases.quick-supplier'), ['name' => 'Somebody'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertSame(0, Supplier::count());
    }

    public function test_a_supplier_keeps_their_own_number_when_they_are_edited(): void
    {
        $supplier = Supplier::factory()->create(['phone' => '+923343401969']);

        $this->actingAs($this->owner)
            ->put(route('suppliers.update', $supplier), [
                'name' => 'Same Man, New Name',
                'phone' => '03343401969',
                'payment_terms_days' => 7,
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('+923343401969', $supplier->refresh()->phone);
        $this->assertSame('Same Man, New Name', $supplier->name);
    }

    public function test_a_cashier_cannot_add_a_supplier_from_the_delivery_page(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->postJson(route('purchases.quick-supplier'), ['name' => 'Anyone', 'phone' => '3343401969'])
            ->assertForbidden();

        $this->assertSame(0, Supplier::count());
    }

    /**
     * The picker replaced a plain dropdown, so the page has to offer both a
     * way to search what is there and a way to add what is not.
     */
    public function test_the_delivery_page_offers_a_search_box_and_a_way_to_add_a_supplier(): void
    {
        Supplier::factory()->create(['name' => 'Khursheed Traders', 'company' => null]);

        $this->actingAs($this->owner)
            ->get(route('purchases.create'))
            ->assertOk()
            ->assertSee('Khursheed Traders')
            ->assertSee('Add a new supplier')
            ->assertSee('Type any part of the name, the company or the phone number.');
    }
}
