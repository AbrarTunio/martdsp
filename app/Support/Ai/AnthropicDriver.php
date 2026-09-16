<?php

namespace App\Support\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;

/**
 * Claude, over Anthropic's Messages API.
 *
 * The long instructions are marked for caching, so a second press within a
 * few minutes reads them back at a tenth of the price. The API itself holds
 * the reply to the JSON shape. Thinking is switched off where the model
 * allows it: the figures are already worked out, and the model only has to
 * explain them.
 */
final class AnthropicDriver extends HttpDriver
{
    private const VERSION = '2023-06-01';

    /**
     * Stop paging through the model list after this many pages.
     */
    private const MAX_PAGES = 5;

    protected function client(): PendingRequest
    {
        return $this->http()->withHeaders([
            'x-api-key' => $this->connection->apiKey,
            'anthropic-version' => self::VERSION,
        ]);
    }

    public function listModels(): array
    {
        $models = [];
        $after = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            [$response] = $this->send('GET', 'models', array_filter(['limit' => 100, 'after_id' => $after]));

            foreach ((array) $response->json('data', []) as $model) {
                if (! is_array($model) || ! isset($model['id'])) {
                    continue;
                }

                if (($model['capabilities']['structured_outputs']['supported'] ?? true) === false) {
                    continue;
                }

                $models[] = ['id' => (string) $model['id'], 'name' => (string) ($model['display_name'] ?? $model['id'])];
            }

            if (! $response->json('has_more') || ! $response->json('last_id')) {
                break;
            }

            $after = $response->json('last_id');
        }

        return $models;
    }

    public function complete(AiPrompt $prompt): AiReply
    {
        $payload = [
            'model' => $this->connection->model,
            'max_tokens' => $prompt->maxTokens,
            'system' => [
                ['type' => 'text', 'text' => $prompt->system, 'cache_control' => ['type' => 'ephemeral']],
            ],
            'messages' => [
                ['role' => 'user', 'content' => $prompt->user],
            ],
            'output_config' => [
                'format' => ['type' => 'json_schema', 'schema' => $prompt->schema],
            ],
        ];

        if (! Str::startsWith($this->connection->model, ['claude-fable', 'claude-mythos'])) {
            $payload['thinking'] = ['type' => 'disabled'];
        }

        [$response, $latency] = $this->send('POST', 'messages', $payload);

        $stop = $response->json('stop_reason');

        if ($stop === 'refusal') {
            throw AiException::refused($this->connection->provider->label());
        }

        if ($stop === 'max_tokens') {
            throw AiException::cutShort();
        }

        $text = collect((array) $response->json('content', []))
            ->filter(fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'text')
            ->pluck('text')
            ->implode('');

        $cacheRead = (int) $response->json('usage.cache_read_input_tokens', 0);
        $cacheWrite = (int) $response->json('usage.cache_creation_input_tokens', 0);

        return new AiReply(
            text: $text,
            inputTokens: (int) $response->json('usage.input_tokens', 0) + $cacheRead + $cacheWrite,
            outputTokens: (int) $response->json('usage.output_tokens', 0),
            latencyMs: $latency,
            cacheReadTokens: $cacheRead,
            cacheWriteTokens: $cacheWrite,
        );
    }
}
