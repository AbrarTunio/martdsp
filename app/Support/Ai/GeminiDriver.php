<?php

namespace App\Support\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;

/**
 * Google Gemini, over the Generative Language API.
 *
 * Gemini describes the JSON shape in its own dialect (types in capitals, no
 * "additionalProperties"), so the shared schema is translated on the way out.
 */
final class GeminiDriver extends HttpDriver
{
    protected function client(): PendingRequest
    {
        return $this->http()->withHeaders(['x-goog-api-key' => $this->connection->apiKey]);
    }

    public function listModels(): array
    {
        [$response] = $this->send('GET', 'models', ['pageSize' => 200]);

        return collect((array) $response->json('models', []))
            ->filter(fn (mixed $model): bool => is_array($model)
                && isset($model['name'])
                && in_array('generateContent', (array) ($model['supportedGenerationMethods'] ?? []), true))
            ->map(fn (array $model): array => [
                'id' => Str::after((string) $model['name'], 'models/'),
                'name' => (string) ($model['displayName'] ?? Str::after((string) $model['name'], 'models/')),
            ])
            ->reject(fn (array $model): bool => (bool) preg_match('/(embed|imagen|image|tts|aqa|veo|live)/i', $model['id']))
            ->values()
            ->all();
    }

    public function complete(AiPrompt $prompt): AiReply
    {
        [$response, $latency] = $this->send('POST', 'models/'.rawurlencode($this->connection->model).':generateContent', [
            'systemInstruction' => ['parts' => [['text' => $prompt->system]]],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt->user]]],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => self::translate($prompt->schema),
                'maxOutputTokens' => $prompt->maxTokens * 2,
            ],
        ]);

        if ($response->json('promptFeedback.blockReason')) {
            throw AiException::refused($this->connection->provider->label());
        }

        $finish = $response->json('candidates.0.finishReason');

        if (in_array($finish, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'RECITATION'], true)) {
            throw AiException::refused($this->connection->provider->label());
        }

        $text = collect((array) $response->json('candidates.0.content.parts', []))
            ->filter(fn (mixed $part): bool => is_array($part) && isset($part['text']) && ! ($part['thought'] ?? false))
            ->pluck('text')
            ->implode('');

        if ($finish === 'MAX_TOKENS' && trim($text) === '') {
            throw AiException::cutShort();
        }

        return new AiReply(
            text: $text,
            inputTokens: (int) $response->json('usageMetadata.promptTokenCount', 0),
            outputTokens: (int) $response->json('usageMetadata.candidatesTokenCount', 0) + (int) $response->json('usageMetadata.thoughtsTokenCount', 0),
            latencyMs: $latency,
            cacheReadTokens: (int) $response->json('usageMetadata.cachedContentTokenCount', 0),
        );
    }

    /**
     * The shared JSON schema in Gemini's dialect.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function translate(array $schema): array
    {
        $types = (array) ($schema['type'] ?? 'string');
        $nullable = in_array('null', $types, true);
        $type = collect($types)->reject(fn (string $type): bool => $type === 'null')->first() ?? 'string';

        $translated = ['type' => strtoupper($type)];

        if ($nullable) {
            $translated['nullable'] = true;
        }

        foreach (['description', 'enum'] as $key) {
            if (isset($schema[$key])) {
                $translated[$key] = $schema[$key];
            }
        }

        if (isset($schema['properties'])) {
            $translated['properties'] = array_map(fn (array $property): array => self::translate($property), $schema['properties']);
            $translated['propertyOrdering'] = array_keys($schema['properties']);
        }

        if (isset($schema['required'])) {
            $translated['required'] = $schema['required'];
        }

        if (isset($schema['items'])) {
            $translated['items'] = self::translate($schema['items']);
        }

        return $translated;
    }
}
