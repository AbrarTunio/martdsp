<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_is_no_public_registration(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_a_manager_cannot_manage_staff(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get('/settings/staff')
            ->assertForbidden();
    }

    public function test_an_owner_can_create_an_account(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post('/settings/staff', [
                'name' => 'Bilal',
                'email' => 'Bilal@Example.com',
                'phone' => '0300 1234567',
                'role' => Role::Cashier->value,
                'password' => 'counter-pass',
                'password_confirmation' => 'counter-pass',
                'pin_code' => '4321',
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/staff');

        $created = User::firstWhere('email', 'bilal@example.com');

        $this->assertNotNull($created);
        $this->assertSame(Role::Cashier, $created->role);
        $this->assertTrue(Hash::check('counter-pass', $created->password));
        $this->assertTrue(Hash::check('4321', $created->pin_code));
    }

    public function test_a_blank_password_on_edit_keeps_the_current_one(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->cashier()->create();
        $before = $staff->password;

        $this->actingAs($owner)
            ->put("/settings/staff/{$staff->id}", [
                'name' => 'Renamed',
                'email' => $staff->email,
                'role' => Role::Cashier->value,
                'password' => '',
                'password_confirmation' => '',
                'pin_code' => '',
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $staff->refresh();

        $this->assertSame('Renamed', $staff->name);
        $this->assertSame($before, $staff->password);
    }

    public function test_deactivating_is_not_deleting(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->cashier()->create();

        $this->actingAs($owner)
            ->delete("/settings/staff/{$staff->id}")
            ->assertRedirect('/settings/staff');

        $this->assertNotNull($staff->fresh());
        $this->assertFalse($staff->fresh()->is_active);
    }

    public function test_a_deactivated_user_cannot_log_in(): void
    {
        $staff = User::factory()->cashier()->inactive()->create();

        $this->post('/login', [
            'email' => $staff->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_you_cannot_deactivate_yourself_from_the_list(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->from('/settings/staff')
            ->delete("/settings/staff/{$owner->id}")
            ->assertSessionHasErrors('staff');

        $this->assertTrue($owner->fresh()->is_active);
    }

    /**
     * The edit form can reach is_active and role, so it can reach the same
     * lockout the destroy route refuses. Both paths need the guard.
     */
    public function test_you_cannot_deactivate_yourself_from_the_edit_form(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->from("/settings/staff/{$owner->id}/edit")
            ->put("/settings/staff/{$owner->id}", [
                'name' => $owner->name,
                'email' => $owner->email,
                'role' => Role::Owner->value,
                'is_active' => '0',
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_the_last_owner_cannot_be_demoted(): void
    {
        $owner = User::factory()->owner()->create();
        $other = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->from("/settings/staff/{$other->id}/edit")
            ->put("/settings/staff/{$other->id}", [
                'name' => $other->name,
                'email' => $other->email,
                'role' => Role::Cashier->value,
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Role::Cashier, $other->fresh()->role);

        $this->actingAs($owner)
            ->from("/settings/staff/{$owner->id}/edit")
            ->put("/settings/staff/{$owner->id}", [
                'name' => $owner->name,
                'email' => $owner->email,
                'role' => Role::Manager->value,
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(Role::Owner, $owner->fresh()->role);
    }

    public function test_the_last_active_owner_cannot_be_deactivated(): void
    {
        $owner = User::factory()->owner()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager);

        $this->actingAs($owner)
            ->from('/settings/staff')
            ->delete("/settings/staff/{$owner->id}")
            ->assertSessionHasErrors('staff');

        $this->assertTrue($owner->fresh()->is_active);
    }
}
