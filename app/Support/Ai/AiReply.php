<?php

namespace App\Support\Ai;

use JsonException;

/**
 * What came back, and what it used.
 *
 * $inputTokens is everything read, including any part the provider served
 * from its cache; the cache counts are kept apart because they are billed
 * differently.
 */
final readonly class AiReply
{
    public function __construct(
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
    ) {}

    /**
     * The reply as a JSON object. Tolerates a model that wraps its JSON in a
     * code fence or a sentence, which some do even when asked not to.
     *
     * @return array<string, mixed>
     *
     * @throws AiException
     */
    public function json(): array
    {
        $text = trim($this->text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            throw AiException::unreadable();
        }

        try {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw AiException::unreadable();
        }

        if (! is_array($decoded)) {
            throw AiException::unreadable();
        }

        return $decoded;
    }
}
