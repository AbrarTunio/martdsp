<?php

namespace App\Support\Ai;

use App\Enums\AiProvider;
use Illuminate\Http\Client\PendingRequest;

/**
 * OpenAI, and every company that copied its way of talking: Groq, Z.ai,
 * Ollama Cloud and any other service the shop points it at.
 *
 * OpenAI itself can be held to the exact JSON shape. The others are only
 * asked for JSON, and the instructions describe the shape; the answer is
 * checked on the way back either way.
 */
final class OpenAiCompatibleDriver extends HttpDriver
{
    /**
     * OpenAI's newer models think before they write, and the thinking comes
     * out of the same allowance, so they are given more room.
     */
    private const REASONING_ROOM = 4;

    protected function client(): PendingRequest
    {
        return $this->http()->withToken($this->connection->apiKey);
    }

    public function listModels(): array
    {
        [$response] = $this->send('GET', 'models');

        return collect((array) $response->json('data', []))
            ->filter(fn (mixed $model): bool => is_array($model) && isset($model['id']))
            ->map(fn (array $model): array => ['id' => (string) $model['id'], 'name' => (string) ($model['name'] ?? $model['id'])])
            ->reject(fn (array $model): bool => $this->isNotForWriting($model['id']))
            ->sortBy('id')
            ->values()
            ->all();
    }

    public function complete(AiPrompt $prompt): AiReply
    {
        $isOpenAi = $this->connection->provider === AiProvider::OpenAi;

        $payload = [
            'model' => $this->connection->model,
            'messages' => [
                ['role' => 'system', 'content' => $prompt->system],
                ['role' => 'user', 'content' => $prompt->user],
            ],
        ];

        if ($isOpenAi) {
            $payload['max_completion_tokens'] = $prompt->maxTokens * self::REASONING_ROOM;
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'answer', 'strict' => true, 'schema' => $prompt->schema],
            ];
        } else {
            $payload['max_tokens'] = $prompt->maxTokens;
            $payload['response_format'] = ['type' => 'json_object'];
        }

        [$response, $latency] = $this->send('POST', 'chat/completions', $payload);

        $text = (string) $response->json('choices.0.message.content', '');
        $finish = $response->json('choices.0.finish_reason');

        if ($finish === 'content_filter' || $response->json('choices.0.message.refusal')) {
            throw AiException::refused($this->connection->provider->label());
        }

        if ($finish === 'length' && trim($text) === '') {
            throw AiException::cutShort();
        }

        return new AiReply(
            text: $text,
            inputTokens: (int) $response->json('usage.prompt_tokens', 0),
            outputTokens: (int) $response->json('usage.completion_tokens', 0),
            latencyMs: $latency,
            cacheReadTokens: (int) $response->json('usage.prompt_tokens_details.cached_tokens', 0),
        );
    }

    /**
     * Speech, picture, embedding and moderation models share the same list
     * on most providers; none of them can write a note.
     */
    private function isNotForWriting(string $id): bool
    {
        return (bool) preg_match('/(embed|whisper|tts|dall-e|image|moderation|audio|transcribe|realtime|guard|speech|sora)/i', $id);
    }
}
