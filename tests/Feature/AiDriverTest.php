<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Models\Setting;
use App\Support\Ai\AiConnection;
use App\Support\Ai\AiException;
use App\Support\Ai\AiPricing;
use App\Support\Ai\AiPrompt;
use App\Support\Ai\AiReply;
use App\Support\Ai\GeminiDriver;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Talking to the AI companies.
 *
 * Nothing here reaches the internet: every reply is a made-up one in the
 * shape the company really answers in. What is being proved is that the
 * shop asks each company in its own dialect, reads the answer back, and
 * turns a refusal into words a shopkeeper can act on.
 */
class AiDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_claude_is_asked_in_anthropics_dialect(): void
    {
        Http::fake(['api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"reply":"Salam"}']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 30, 'cache_read_input_tokens' => 400],
        ])]);

        $reply = $this->connection(AiProvider::Anthropic, 'claude-opus-5')->driver()->complete(AiPrompt::connectionTest());

        $this->assertSame(500, $reply->inputTokens);
        $this->assertSame(400, $reply->cacheReadTokens);
        $this->assertSame('Salam', $reply->json()['reply']);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            $this->assertSame('claude-opus-5', $body['model']);
            $this->assertSame('ephemeral', $body['system'][0]['cache_control']['type']);
            $this->assertSame('json_schema', $body['output_config']['format']['type']);
            $this->assertSame('disabled', $body['thinking']['type']);

            return $request->hasHeader('x-api-key', 'sk-test') && $request->hasHeader('anthropic-version');
        });
    }

    public function test_the_whole_list_of_claude_models_is_read_page_by_page(): void
    {
        Http::fakeSequence()
            ->push(['data' => [['id' => 'claude-opus-5', 'display_name' => 'Claude Opus 5']], 'has_more' => true, 'last_id' => 'claude-opus-5'])
            ->push(['data' => [['id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5']], 'has_more' => false]);

        $models = $this->connection(AiProvider::Anthropic, 'claude-opus-5')->driver()->listModels();

        $this->assertSame(['claude-opus-5', 'claude-sonnet-5'], array_column($models, 'id'));
    }

    public function test_gemini_is_given_the_answer_shape_in_its_own_dialect(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [
                ['text' => 'thinking out loud', 'thought' => true],
                ['text' => '{"reply":"Salam"}'],
            ]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 700, 'candidatesTokenCount' => 90, 'thoughtsTokenCount' => 10],
        ])]);

        $reply = $this->connection(AiProvider::Gemini, 'gemini-2.5-flash')->driver()->complete(AiPrompt::connectionTest());

        $this->assertSame('{"reply":"Salam"}', $reply->text);
        $this->assertSame(100, $reply->outputTokens);

        Http::assertSent(function ($request): bool {
            $schema = $request->data()['generationConfig']['responseSchema'];

            $this->assertSame('OBJECT', $schema['type']);
            $this->assertSame('STRING', $schema['properties']['reply']['type']);
            $this->assertArrayNotHasKey('additionalProperties', $schema);

            return $request->hasHeader('x-goog-api-key', 'sk-test');
        });
    }

    public function test_the_answer_shape_survives_the_trip_into_geminis_dialect(): void
    {
        $translated = GeminiDriver::translate([
            'type' => 'object',
            'properties' => [
                'headline' => ['type' => 'string'],
                'impact' => ['type' => ['string', 'null']],
                'lines' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['headline', 'impact', 'lines'],
            'additionalProperties' => false,
        ]);

        $this->assertSame('OBJECT', $translated['type']);
        $this->assertSame('ARRAY', $translated['properties']['lines']['type']);
        $this->assertSame('STRING', $translated['properties']['lines']['items']['type']);
        $this->assertTrue($translated['properties']['impact']['nullable']);
        $this->assertSame(['headline', 'impact', 'lines'], $translated['propertyOrdering']);
    }

    public function test_openai_is_held_to_the_exact_answer_shape(): void
    {
        Http::fake(['api.openai.com/*' => $this->chatReply()]);

        $this->connection(AiProvider::OpenAi, 'gpt-5-mini')->driver()->complete(AiPrompt::connectionTest());

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            $this->assertSame('json_schema', $body['response_format']['type']);
            $this->assertTrue($body['response_format']['json_schema']['strict']);

            return isset($body['max_completion_tokens']);
        });
    }

    /**
     * The others speak OpenAI's language but cannot all be held to a shape,
     * so they are asked for JSON and the answer is checked on the way back.
     */
    public function test_the_other_companies_are_only_asked_for_json(): void
    {
        Http::fake(['api.groq.com/*' => $this->chatReply()]);

        $this->connection(AiProvider::Groq, 'llama-3.3-70b-versatile')->driver()->complete(AiPrompt::connectionTest());

        Http::assertSent(function ($request): bool {
            $this->assertSame(['type' => 'json_object'], $request->data()['response_format']);

            return $request->hasHeader('Authorization', 'Bearer sk-test');
        });
    }

    public function test_models_that_cannot_write_a_note_are_left_out_of_the_list(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [
            ['id' => 'gpt-5-mini'],
            ['id' => 'text-embedding-3-small'],
            ['id' => 'whisper-1'],
            ['id' => 'dall-e-3'],
        ]])]);

        $models = $this->connection(AiProvider::OpenAi, 'gpt-5-mini')->driver()->listModels();

        $this->assertSame(['gpt-5-mini'], array_column($models, 'id'));
    }

    public function test_a_model_the_company_does_not_know_is_explained_plainly(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'no such model']], 404)]);

        $this->expectExceptionMessage('OpenAI does not recognise that model. Fetch the list again and pick one.');

        $this->connection(AiProvider::OpenAi, 'gpt-9')->driver()->complete(AiPrompt::connectionTest());
    }

    public function test_a_key_out_of_credit_is_explained_plainly(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'quota']], 429)]);

        $this->expectExceptionMessage('Groq says the key is over its limit or out of credit.');

        $this->connection(AiProvider::Groq, 'llama-3.3-70b-versatile')->driver()->complete(AiPrompt::connectionTest());
    }

    public function test_an_answer_cut_off_halfway_is_not_passed_on_as_one(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"repl']],
            'stop_reason' => 'max_tokens',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 1500],
        ])]);

        $this->expectExceptionMessage('The AI ran out of room before finishing its answer.');

        $this->connection(AiProvider::Anthropic, 'claude-opus-5')->driver()->complete(AiPrompt::connectionTest());
    }

    /**
     * Some models wrap their JSON in a code fence however firmly they are
     * asked not to. The note should not be lost over punctuation.
     */
    public function test_json_wrapped_in_a_sentence_is_still_read(): void
    {
        $reply = new AiReply("Here you go:\n```json\n{\"headline\":\"All well\"}\n```\nHope that helps.", 10, 10, 100);

        $this->assertSame('All well', $reply->json()['headline']);
    }

    public function test_an_answer_with_no_json_in_it_at_all_is_refused(): void
    {
        $this->expectException(AiException::class);

        (new AiReply('I cannot help with that.', 10, 10, 100))->json();
    }

    public function test_what_an_answer_cost_is_worked_out_from_the_model_and_the_dollar_rate(): void
    {
        Setting::writeMany(['ai.usd_rate' => 280]);

        // Opus is $5 in and $25 out per million: 200k in and 40k out is $2.
        $paisa = AiPricing::estimatePaisa(
            $this->connection(AiProvider::Anthropic, 'claude-opus-5'),
            new AiReply('{}', 200_000, 40_000, 900),
        );

        $this->assertSame(2 * 280 * 100, $paisa);
    }

    public function test_reading_back_a_cached_question_costs_a_tenth(): void
    {
        Setting::writeMany(['ai.usd_rate' => 100]);

        $connection = $this->connection(AiProvider::Anthropic, 'claude-opus-5');

        $fresh = AiPricing::estimatePaisa($connection, new AiReply('{}', 1_000_000, 0, 900));
        $cached = AiPricing::estimatePaisa($connection, new AiReply('{}', 1_000_000, 0, 900, cacheReadTokens: 1_000_000));

        $this->assertSame(5 * 100 * 100, $fresh);
        $this->assertSame((int) round($fresh * 0.1), $cached);
    }

    public function test_the_longest_matching_model_name_sets_the_price(): void
    {
        $this->assertSame([5.0, 25.0], AiPricing::rates(AiProvider::Anthropic, 'claude-opus-5'));
        $this->assertSame([15.0, 75.0], AiPricing::rates(AiProvider::Anthropic, 'claude-opus-4-1-20250805'));
        $this->assertSame([0.25, 2.0], AiPricing::rates(AiProvider::OpenAi, 'gpt-5-mini-2026-01-01'));
    }

    public function test_a_model_with_no_published_price_falls_back_to_the_companys_usual(): void
    {
        $this->assertSame([5.0, 25.0], AiPricing::rates(AiProvider::Anthropic, 'claude-something-new'));
        $this->assertSame([0.0, 0.0], AiPricing::rates(AiProvider::OllamaCloud, 'gpt-oss:120b'));
        $this->assertSame([1.0, 3.0], AiPricing::rates(AiProvider::Custom, 'whatever-is-running-here'));
    }

    private function connection(AiProvider $provider, string $model): AiConnection
    {
        return AiConnection::make($provider, 'sk-test', $model, 'http://localhost:11434/v1');
    }

    private function chatReply(): PromiseInterface
    {
        return Http::response([
            'choices' => [['message' => ['content' => '{"reply":"Salam"}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 20],
        ]);
    }
}
