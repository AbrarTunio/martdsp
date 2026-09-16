<?php

namespace Tests\Feature;

use App\Models\AiInsightRun;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ai\AiConnection;
use App\Support\Insights\InsightRegistry;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InsightTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cashier_may_not_ask_for_a_note(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->post('/insights/business')
            ->assertForbidden();
    }

    public function test_a_page_nobody_explains_is_not_found(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post('/insights/weather')
            ->assertNotFound();
    }

    /**
     * The whole point of the fallback: with no key at all, every page still
     * explains itself from the shop's own checks.
     */
    public function test_every_page_explains_itself_without_an_api_key(): void
    {
        Http::fake();

        $owner = User::factory()->owner()->create();

        foreach (InsightRegistry::pages() as $page) {
            $this->actingAs($owner)
                ->post('/insights/'.$page)
                ->assertOk()
                ->assertSee('From the shop\'s own checks')
                ->assertSee('No AI is set up yet');
        }

        Http::assertNothingSent();
    }

    public function test_the_ai_writes_the_note_when_a_key_is_saved(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/v1/messages' => $this->answer()]);

        $this->actingAs(User::factory()->owner()->create())
            ->post('/insights/business')
            ->assertOk()
            ->assertSee('Sales are steady, khata is not')
            ->assertSee('Written by AI')
            ->assertSee('Claude (Anthropic)');

        $this->assertDatabaseHas('ai_insight_runs', ['page' => 'business', 'status' => AiInsightRun::ANSWERED]);
    }

    public function test_the_same_figures_reuse_the_saved_answer(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/v1/messages' => $this->answer()]);

        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/insights/business')->assertOk();

        $this->actingAs($owner)
            ->post('/insights/business')
            ->assertOk()
            ->assertSee('Saved answer');

        Http::assertSentCount(1);
        $this->assertSame(1, AiInsightRun::query()->count());
    }

    public function test_refresh_asks_again(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/v1/messages' => $this->answer()]);

        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/insights/business')->assertOk();
        $this->actingAs($owner)->post('/insights/business', ['refresh' => true])->assertOk();

        Http::assertSentCount(2);
    }

    public function test_the_ai_is_not_asked_once_the_month_is_switched_off(): void
    {
        $this->connect();
        Setting::writeMany(['ai.monthly_cap' => 0]);
        Http::fake();

        $this->actingAs(User::factory()->owner()->create())
            ->post('/insights/business')
            ->assertOk()
            ->assertSee('AI notes are switched off');

        Http::assertNothingSent();
    }

    public function test_the_ai_is_not_asked_once_the_months_budget_is_spent(): void
    {
        $this->connect();
        Setting::writeMany(['ai.monthly_cap' => 10]);
        AiInsightRun::factory()->create(['cost_paisa' => 1500]);
        Http::fake();

        $this->actingAs(User::factory()->owner()->create())
            ->post('/insights/business')
            ->assertOk()
            ->assertSee('budget of Rs. 10 is used up');

        Http::assertNothingSent();
    }

    public function test_an_answer_that_makes_no_sense_falls_back_to_the_shops_own_checks(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Sorry, I cannot help with that.']],
            'usage' => ['input_tokens' => 400, 'output_tokens' => 10],
        ])]);

        $this->actingAs(User::factory()->owner()->create())
            ->post('/insights/business')
            ->assertOk()
            ->assertSee('not in the expected form')
            ->assertSee('From the shop\'s own checks');

        $this->assertDatabaseHas('ai_insight_runs', ['page' => 'business', 'status' => AiInsightRun::FAILED]);
    }

    public function test_a_provider_having_a_bad_day_does_not_stop_the_page(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/v1/messages' => Http::response(['error' => ['message' => 'overloaded']], 529)]);

        $this->actingAs(User::factory()->owner()->create())
            ->post('/insights/business')
            ->assertOk()
            ->assertSee('From the shop\'s own checks');
    }

    /**
     * The key lives on the server. It goes to the AI company and nowhere else
     * — never into a page, not even the one that talks to the AI.
     */
    public function test_the_key_never_reaches_the_browser(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/v1/messages' => $this->answer()]);

        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/insights/business')->assertOk()->assertDontSee('sk-ant-secret-key-value');
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertDontSee('sk-ant-secret-key-value');

        Http::assertSent(fn ($request): bool => $request->hasHeader('x-api-key', 'sk-ant-secret-key-value'));
    }

    /**
     * A key the AI invents must not be able to put a red badge or a link on a
     * line the shop's own checks never raised.
     */
    public function test_an_invented_finding_key_is_ignored(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/v1/messages' => $this->answer([
            'insights' => [[
                'finding' => 'the_sky_is_falling',
                'title' => 'Something dramatic',
                'explanation' => 'Made up.',
                'action' => 'Do nothing.',
                'impact' => '',
            ]],
        ])]);

        $this->actingAs(User::factory()->owner()->create())
            ->post('/insights/business')
            ->assertOk()
            ->assertSee('Something dramatic')
            ->assertSee('Worth knowing');
    }

    /**
     * A khata note about one customer is about that customer only.
     */
    public function test_one_customers_khata_can_be_explained(): void
    {
        $customer = Customer::factory()->create(['name' => 'Bilal General Store', 'phone' => '03001234567']);

        $this->actingAs(User::factory()->owner()->create())
            ->post('/insights/khata', ['customer' => $customer->getKey()])
            ->assertOk()
            ->assertSee('Bilal General Store')
            ->assertDontSee('03001234567');
    }

    private function connect(): void
    {
        Setting::writeMany([
            'ai.provider' => 'anthropic',
            'ai.model' => 'claude-opus-5',
            'ai.monthly_cap' => 2000,
            'ai.language' => 'en',
            'ai.usd_rate' => 280,
        ]);

        Setting::writeSecret(AiConnection::KEY_SETTING, 'sk-ant-secret-key-value', 'ai');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function answer(array $overrides = []): PromiseInterface
    {
        $reply = array_merge([
            'headline' => 'Sales are steady, khata is not',
            'summary' => 'The month is on last month\'s pace, but more is going out on khata than coming back.',
            'insights' => [],
            'watch_next' => ['Khata collected next week'],
        ], $overrides);

        return Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($reply)]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 900, 'output_tokens' => 250],
        ]);
    }
}
