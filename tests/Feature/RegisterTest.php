<?php

namespace Tests\Feature;

use App\Models\Register;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The counters in the shop, and which one a phone or PC is standing at.
 *
 * The shop always keeps at least one counter in use. A counter that has sold
 * something keeps its history, so it can be switched off but not removed.
 */
class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
    }

    public function test_the_first_visit_makes_a_counter_so_the_shop_can_sell_straight_away(): void
    {
        $this->actingAs($this->owner)
            ->get(route('settings.registers.index'))
            ->assertOk()
            ->assertSee('Counter 1');

        $this->assertTrue(Register::query()->sole()->is_active);
    }

    public function test_a_cashier_cannot_manage_counters(): void
    {
        $cashier = User::factory()->cashier()->create();
        $register = Register::factory()->create();

        $this->actingAs($cashier)->get(route('settings.registers.index'))->assertForbidden();
        $this->actingAs($cashier)->post(route('settings.registers.store'), ['name' => 'Counter 2'])->assertForbidden();
        $this->actingAs($cashier)->patch(route('settings.registers.update', $register), ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($cashier)->delete(route('settings.registers.destroy', $register))->assertForbidden();

        $this->assertModelExists($register);
    }

    public function test_a_counter_is_added_with_its_own_paper(): void
    {
        $this->actingAs($this->owner)
            ->post(route('settings.registers.store'), [
                'name' => 'Counter 2',
                'location' => 'By the door',
                'printer_profile' => '58',
            ])
            ->assertSessionHasNoErrors();

        $register = Register::query()->sole();

        $this->assertSame('By the door', $register->location);
        $this->assertSame('58', $register->paper());
    }

    public function test_two_counters_cannot_share_a_name_and_the_paper_must_be_one_we_print_on(): void
    {
        Register::factory()->create(['name' => 'Counter 1']);

        $this->actingAs($this->owner)
            ->post(route('settings.registers.store'), ['name' => 'Counter 1', 'printer_profile' => 'letter'])
            ->assertSessionHasErrors(['name', 'printer_profile']);

        $this->assertSame(1, Register::query()->count());
    }

    public function test_a_counter_is_renamed_and_switched_off(): void
    {
        Register::factory()->create();
        $register = Register::factory()->create(['name' => 'Counter 2']);

        $this->actingAs($this->owner)
            ->patch(route('settings.registers.update', $register), [
                'name' => 'Back counter',
                'location' => 'Near the fridge',
                'printer_profile' => 'a4',
            ])
            ->assertSessionHasNoErrors();

        $register->refresh();

        $this->assertSame('Back counter', $register->name);
        $this->assertSame('a4', $register->printer_profile);
        $this->assertFalse($register->is_active, 'An unticked box switches it off');
    }

    public function test_keeping_its_own_name_is_not_a_clash(): void
    {
        $register = Register::factory()->create(['name' => 'Counter 1']);

        $this->actingAs($this->owner)
            ->patch(route('settings.registers.update', $register), ['name' => 'Counter 1', 'is_active' => '1'])
            ->assertSessionHasNoErrors();
    }

    public function test_the_last_counter_in_use_cannot_be_switched_off_or_removed(): void
    {
        $register = Register::factory()->create();
        Register::factory()->inactive()->create();

        $this->actingAs($this->owner)
            ->patch(route('settings.registers.update', $register), ['name' => $register->name])
            ->assertSessionHasErrors('register');

        $this->actingAs($this->owner)
            ->delete(route('settings.registers.destroy', $register))
            ->assertSessionHasErrors('register');

        $this->assertTrue($register->fresh()->is_active);
    }

    public function test_a_counter_that_never_sold_is_removed_but_one_that_did_is_only_switched_off(): void
    {
        Register::factory()->create();
        $unused = Register::factory()->create();
        $used = Register::factory()->create();
        Sale::factory()->completed()->create(['register_id' => $used->id]);

        $this->actingAs($this->owner)->delete(route('settings.registers.destroy', $unused))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->delete(route('settings.registers.destroy', $used))->assertSessionHasNoErrors();

        $this->assertModelMissing($unused);
        $this->assertModelExists($used);
        $this->assertFalse($used->fresh()->is_active);
    }

    public function test_with_two_counters_in_use_the_till_asks_which_one_and_remembers(): void
    {
        $cashier = User::factory()->cashier()->create();
        Register::factory()->create(['name' => 'Front']);
        $back = Register::factory()->create(['name' => 'Back']);

        $this->actingAs($cashier)
            ->get(route('pos.index'))
            ->assertOk()
            ->assertViewIs('pos.choose-register')
            ->assertSee('Front')
            ->assertSee('Back');

        $this->actingAs($cashier)
            ->post(route('pos.register'), ['register_id' => $back->id])
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('pos.register_id', $back->id);

        $this->actingAs($cashier)
            ->get(route('pos.index'))
            ->assertOk()
            ->assertViewIs('pos.open-drawer')
            ->assertViewHas('register', fn (Register $register): bool => $register->is($back));
    }

    public function test_a_switched_off_counter_cannot_be_chosen(): void
    {
        $cashier = User::factory()->cashier()->create();
        $off = Register::factory()->inactive()->create();

        $this->actingAs($cashier)
            ->post(route('pos.register'), ['register_id' => $off->id])
            ->assertSessionHasErrors('register_id');
    }

    public function test_a_receipt_prints_on_its_counters_paper_unless_asked_otherwise(): void
    {
        $register = Register::factory()->create(['printer_profile' => '58']);
        $sale = Sale::factory()->completed()->create(['register_id' => $register->id]);

        $this->actingAs($this->owner)
            ->get(route('sales.receipt', $sale))
            ->assertOk()
            ->assertViewHas('paper', '58');

        $this->actingAs($this->owner)
            ->get(route('sales.receipt', ['sale' => $sale, 'paper' => 'a4']))
            ->assertViewHas('paper', 'a4');

        $this->actingAs($this->owner)
            ->get(route('sales.receipt', ['sale' => $sale, 'paper' => 'nonsense']))
            ->assertViewHas('paper', '80');
    }
}
