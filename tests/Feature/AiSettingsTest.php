<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ai\AiConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cashier_cannot_reach_the_ai_settings(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get('/settings/ai')
            ->assertForbidden();
    }

    public function test_an_owner_sees_the_ai_screen(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get('/settings/ai')
            ->assertOk()
            ->assertSee('Claude (Anthropic)')
            ->assertSee('Fetch models');
    }

    public function test_the_settings_are_saved_and_the_key_is_encrypted(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->from('/settings/ai')
            ->put('/settings/ai', $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/ai');

        $this->assertSame('anthropic', Setting::read('ai.provider'));
        $this->assertSame('claude-opus-5', Setting::read('ai.model'));
        $this->assertSame(1500, Setting::read('ai.monthly_cap'));
        $this->assertSame(90, Setting::read('insights.dead_stock_days'));
        $this->assertSame('sk-ant-secret-key-value', Setting::secret(AiConnection::KEY_SETTING));

        $row = Setting::query()->where('key', AiConnection::KEY_SETTING)->firstOrFail();

        $this->assertTrue((bool) $row->is_encrypted);
        $this->assertStringNotContainsString('sk-ant-secret-key-value', (string) $row->getRawOriginal('value'));
    }

    /**
     * The key is write-only: what comes back to the browser is masked, and an
     * empty field means the saved key stays where it is.
     */
    public function test_the_saved_key_is_never_shown_in_full(): void
    {
        Setting::writeSecret(AiConnection::KEY_SETTING, 'sk-ant-secret-key-value', 'ai');
        Setting::writeMany(['ai.provider' => 'anthropic', 'ai.model' => 'claude-opus-5']);

        $this->actingAs(User::factory()->owner()->create())
            ->get('/settings/ai')
            ->assertOk()
            ->assertDontSee('sk-ant-secret-key-value')
            ->assertSee('sk-a…alue');
    }

    public function test_a_blank_key_keeps_the_one_already_saved(): void
    {
        Setting::writeSecret(AiConnection::KEY_SETTING, 'sk-ant-secret-key-value', 'ai');

        $this->actingAs(User::factory()->owner()->create())
            ->put('/settings/ai', $this->payload(['api_key' => '', 'model' => 'claude-sonnet-5']))
            ->assertSessionHasNoErrors();

        $this->assertSame('sk-ant-secret-key-value', Setting::secret(AiConnection::KEY_SETTING));
        $this->assertSame('claude-sonnet-5', Setting::read('ai.model'));
    }

    public function test_a_custom_provider_must_say_where_its_api_lives(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->from('/settings/ai')
            ->put('/settings/ai', $this->payload(['provider' => 'custom', 'base_url' => '']))
            ->assertSessionHasErrors('base_url');
    }

    public function test_the_key_can_be_forgotten(): void
    {
        Setting::writeSecret(AiConnection::KEY_SETTING, 'sk-ant-secret-key-value', 'ai');

        $this->actingAs(User::factory()->owner()->create())
            ->delete('/settings/ai/key')
            ->assertSessionHasNoErrors();

        $this->assertNull(Setting::secret(AiConnection::KEY_SETTING));
        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.ai_key_forgotten']);
    }

    public function test_the_activity_log_never_records_the_key(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->put('/settings/ai', $this->payload())
            ->assertSessionHasNoErrors();

        $log = ActivityLog::query()->where('action', 'settings.ai_updated')->firstOrFail();

        $this->assertStringNotContainsString('sk-ant-secret-key-value', json_encode([$log->before, $log->after]));
    }

    public function test_the_models_a_key_can_use_are_fetched(): void
    {
        Http::fake(['api.anthropic.com/v1/models*' => Http::response([
            'data' => [
                ['id' => 'claude-opus-5', 'display_name' => 'Claude Opus 5'],
                ['id' => 'claude-haiku-4-5-20251001', 'display_name' => 'Claude Haiku 4.5'],
            ],
            'has_more' => false,
        ])]);

        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/settings/ai/models', ['provider' => 'anthropic', 'api_key' => 'sk-ant-test'])
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonPath('models.0.id', 'claude-opus-5')
            ->assertJsonPath('models.1.name', 'Claude Haiku 4.5');
    }

    public function test_a_rejected_key_is_explained_in_plain_words(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401)]);

        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/settings/ai/models', ['provider' => 'anthropic', 'api_key' => 'sk-wrong'])
            ->assertStatus(422)
            ->assertJson(['ok' => false])
            ->assertJsonPath('message', 'Claude (Anthropic) did not accept the API key. Check it was copied in full.');
    }

    public function test_the_test_button_reports_what_the_model_said_and_what_it_cost(): void
    {
        Http::fake(['api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"reply":"Assalam-o-alaikum, shopkeeper!"}']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 60, 'output_tokens' => 20],
        ])]);

        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/settings/ai/test', [
                'provider' => 'anthropic',
                'api_key' => 'sk-ant-test',
                'model' => 'claude-opus-5',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'said' => 'Assalam-o-alaikum, shopkeeper!']);
    }

    public function test_the_test_button_uses_the_saved_key_when_the_field_is_left_blank(): void
    {
        Setting::writeSecret(AiConnection::KEY_SETTING, 'sk-ant-secret-key-value', 'ai');

        Http::fake(['api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"reply":"Salam"}']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
        ])]);

        $this->actingAs(User::factory()->owner()->create())
            ->postJson('/settings/ai/test', ['provider' => 'anthropic', 'api_key' => '', 'model' => 'claude-opus-5'])
            ->assertOk();

        Http::assertSent(fn ($request): bool => $request->hasHeader('x-api-key', 'sk-ant-secret-key-value'));
    }

    public function test_a_cashier_cannot_spend_the_shops_money_on_a_test(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->cashier()->create())
            ->postJson('/settings/ai/test', ['provider' => 'anthropic', 'api_key' => 'sk-ant-test', 'model' => 'claude-opus-5'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-secret-key-value',
            'model' => 'claude-opus-5',
            'base_url' => '',
            'monthly_cap' => 1500,
            'language' => 'en',
            'usd_rate' => 280,
            'insights' => [
                'dead_stock_days' => 90,
                'dead_stock_value' => 5000,
                'overstock_days' => 90,
                'payment_gap_days' => 45,
                'debt_growth_ratio' => 1.3,
                'receivables_share' => 25,
                'variance_alert' => 500,
                'void_rate_multiple' => 2,
                'price_rise_percent' => 5,
            ],
        ], $overrides);
    }
}
