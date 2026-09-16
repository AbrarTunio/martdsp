<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cashier_cannot_reach_settings(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get('/settings')
            ->assertForbidden();
    }

    public function test_an_owner_sees_the_settings_screen(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get('/settings')
            ->assertOk()
            ->assertSee('Super Mart');
    }

    public function test_settings_are_saved(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->from('/settings')
            ->put('/settings', $this->payload(['shop.name' => 'Tunio Kiryana Store']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings');

        $this->assertSame('Tunio Kiryana Store', Setting::read('shop.name'));
        $this->assertSame(18.0, Setting::read('tax.gst_rate'));
    }

    public function test_an_unchecked_toggle_is_stored_as_false(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->put('/settings', $this->payload(['sales.round_to_rupee' => '0']))
            ->assertSessionHasNoErrors();

        $this->assertFalse(Setting::read('sales.round_to_rupee'));
    }

    /**
     * A tampered form must not be able to write arbitrary rows: only keys
     * declared in config/supermart.php are recognised.
     */
    public function test_an_unknown_key_is_ignored(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->put('/settings', $this->payload(['sales.allow_everything' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('settings', ['key' => 'sales.allow_everything']);
    }

    public function test_the_gst_rate_must_be_a_sane_percentage(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->from('/settings')
            ->put('/settings', $this->payload(['tax.gst_rate' => '250']))
            ->assertSessionHasErrors('settings.tax.gst_rate')
            ->assertRedirect('/settings');
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, array<string, string>>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'settings' => array_merge([
                'shop.name' => 'Super Mart',
                'shop.address' => 'Main Bazaar, Hyderabad',
                'shop.phone' => '0300 1234567',
                'shop.ntn' => '',
                'shop.strn' => '',
                'tax.gst_rate' => '18',
                'tax.prices_include_tax' => '1',
                'receipt.paper_width' => '80',
                'receipt.footer_note' => 'Thank you',
                'sales.round_to_rupee' => '1',
                'sales.allow_negative_stock' => '0',
                'sales.cashier_discount_limit' => '10',
                'khata.credit_days' => '30',
                'drawer.variance_tolerance' => '100',
                'drawer.blind_count' => '1',
            ], $overrides),
        ];
    }
}
