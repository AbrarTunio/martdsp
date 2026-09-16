<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cashier_may_not_read_the_log(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get('/activity')
            ->assertForbidden();
    }

    public function test_actions_are_shown_in_plain_words(): void
    {
        $manager = User::factory()->manager()->create(['name' => 'Rukhsana']);

        $this->actingAs($manager);
        ActivityLog::record('sale.voided');

        $this->get('/activity')
            ->assertOk()
            ->assertSee('Bill cancelled')
            ->assertSee('Rukhsana')
            ->assertDontSee('sale.voided');
    }

    public function test_an_action_nobody_named_still_appears(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        ActivityLog::record('teapot.polished');

        $this->get('/activity')
            ->assertOk()
            ->assertSee('Teapot polished');
    }

    public function test_the_log_can_be_narrowed_to_one_part_of_the_shop(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        ActivityLog::record('sale.voided');
        ActivityLog::record('customer.written_off');

        $this->get('/activity?area=khata')
            ->assertOk()
            ->assertSee('Khata written off')
            ->assertDontSee('Bill cancelled');
    }

    public function test_the_log_can_be_narrowed_to_one_person(): void
    {
        $owner = User::factory()->owner()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager);
        ActivityLog::record('drawer.closed');

        $this->actingAs($owner);
        ActivityLog::record('backup.created');

        $this->get('/activity?user='.$manager->getKey())
            ->assertOk()
            ->assertSee('Drawer counted and closed')
            ->assertDontSee('Backup made');
    }

    /**
     * A log that only ever shows this week hides the very thing a supervisor
     * comes looking for, so the dates are honoured.
     */
    public function test_only_the_chosen_days_are_shown(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $old = ActivityLog::record('sale.voided');
        $old->forceFill(['created_at' => now()->subMonths(2)])->save();

        $this->get('/activity')->assertOk()->assertSee('Nothing recorded');

        $this->get('/activity?from='.now()->subMonths(3)->toDateString().'&to='.today()->toDateString())
            ->assertOk()
            ->assertSee('Bill cancelled');
    }

    public function test_dates_the_wrong_way_round_are_swapped_rather_than_showing_nothing(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        ActivityLog::record('sale.completed');

        $this->get('/activity?from='.today()->toDateString().'&to='.today()->subDays(10)->toDateString())
            ->assertOk()
            ->assertSee('Bill rung up');
    }

    public function test_an_area_nobody_has_heard_of_matches_nothing(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        ActivityLog::record('sale.completed');

        $this->get('/activity?area=weather')
            ->assertOk()
            ->assertSee('Nothing recorded');
    }

    public function test_the_log_is_reachable_from_the_sidebar_for_a_supervisor_only(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(route('activity.index'));

        $this->actingAs(User::factory()->cashier()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee(route('activity.index'));
    }
}
