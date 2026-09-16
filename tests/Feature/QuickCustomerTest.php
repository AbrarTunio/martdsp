<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Opening a khata at the till, and finding it again afterwards.
 *
 * A number is the only thing that tells two Bilals apart, so it is written
 * down one way — +92 and then the number without the 0 — exactly as a
 * supplier's is. Otherwise the same neighbour ends up with a khata under
 * 0300… and another under +92300…, and the shop chases half of what it is
 * owed. Unlike a supplier, a customer may have no number at all: plenty of
 * khatas belong to people the shopkeeper sees every day anyway.
 */
class QuickCustomerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
    }

    public function test_a_number_dialled_the_way_it_is_at_home_is_stored_the_one_way(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('pos.customers.store'), [
                'name' => 'Bilal Ahmed',
                'phone' => '0300 551 0001',
            ])
            ->assertCreated();

        $this->assertSame('+923005510001', Customer::sole()->phone);
    }

    public function test_the_khata_screen_writes_the_number_down_the_same_way_the_till_does(): void
    {
        $this->actingAs($this->owner)
            ->post(route('customers.store'), [
                'name' => 'Bilal Ahmed',
                'phone' => '0300 551 0001',
                'credit_limit' => '5000',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('+923005510001', Customer::sole()->phone);
    }

    public function test_the_same_number_cannot_go_on_two_khatas(): void
    {
        Customer::factory()->create(['name' => 'Bilal Ahmed', 'phone' => '+923005510001']);

        $this->actingAs($this->owner)
            ->postJson(route('pos.customers.store'), [
                'name' => 'Bilal Ahmad',
                'phone' => '03005510001',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertSame(1, Customer::count());
    }

    public function test_a_number_that_is_not_pakistani_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('pos.customers.store'), ['name' => 'Somebody', 'phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertSame(0, Customer::count());
    }

    /**
     * The difference from a supplier: the shopkeeper knows who this is.
     */
    public function test_a_khata_can_be_opened_for_somebody_with_no_number(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('pos.customers.store'), ['name' => 'Chacha Next Door'])
            ->assertCreated();

        $this->assertNull(Customer::sole()->phone);
    }

    public function test_a_customer_is_found_by_the_number_the_way_the_cashier_dials_it(): void
    {
        Customer::factory()->create(['name' => 'Bilal Ahmed', 'phone' => '+923005510001']);

        foreach (['03005510001', '0300 5510001', '+92 300 5510001', '5510001'] as $typed) {
            $this->actingAs($this->owner)
                ->getJson(route('pos.customers.index', ['q' => $typed]))
                ->assertOk()
                ->assertJsonPath('customers.0.name', 'Bilal Ahmed');
        }
    }

    public function test_a_customer_keeps_their_own_number_when_they_are_edited(): void
    {
        $customer = Customer::factory()->create(['phone' => '+923005510001']);

        $this->actingAs($this->owner)
            ->put(route('customers.update', $customer), [
                'name' => 'Same Man, New Name',
                'phone' => '03005510001',
                'credit_limit' => '5000',
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('+923005510001', $customer->refresh()->phone);
        $this->assertSame('Same Man, New Name', $customer->name);
    }
}
