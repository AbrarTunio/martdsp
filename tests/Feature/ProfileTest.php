<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/profile')
            ->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => 'Abdul Rehman',
                'email' => 'abdul@example.com',
                'phone' => '0300 1234567',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Abdul Rehman', $user->name);
        $this->assertSame('abdul@example.com', $user->email);
        $this->assertSame('0300 1234567', $user->phone);
    }

    public function test_email_is_folded_to_lowercase(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => 'Abdul@Example.COM',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('abdul@example.com', $user->refresh()->email);
    }

    /**
     * Breeze shipped a self-delete route. It is gone, because a departed
     * cashier's sales and drawer counts must stay attributable to a person.
     */
    public function test_an_account_cannot_be_deleted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/profile')
            ->assertMethodNotAllowed();

        $this->assertNotNull($user->fresh());
    }
}
