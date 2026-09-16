<?php

namespace App\Support\Ai;

use App\Enums\AiProvider;
use App\Models\Setting;

/**
 * What one answer cost, near enough, in paisa.
 *
 * Prices are the providers' published dollar rates per million words-pieces
 * (tokens), matched on the start of the model's name so a new dated version
 * of a model still finds its price. The exchange rate is the shop's own
 * setting. It is an estimate for the monthly limit — the provider's bill is
 * the final word.
 */
final class AiPricing
{
    /**
     * Writing to the prompt cache costs a quarter more; reading it back costs
     * a tenth.
     */
    private const CACHE_WRITE = 1.25;

    private const CACHE_READ = 0.1;

    public static function estimatePaisa(AiConnection $connection, AiReply $reply): int
    {
        [$in, $out] = self::rates($connection->provider, $connection->model);

        $uncached = max(0, $reply->inputTokens - $reply->cacheReadTokens - $reply->cacheWriteTokens);

        $usd = ($uncached * $in
            + $reply->cacheWriteTokens * $in * self::CACHE_WRITE
            + $reply->cacheReadTokens * $in * self::CACHE_READ
            + $reply->outputTokens * $out) / 1_000_000;

        return (int) ceil($usd * (int) Setting::read('ai.usd_rate', 280) * 100);
    }

    /**
     * Dollars per million tokens in, and out.
     *
     * @return array{0: float, 1: float}
     */
    public static function rates(AiProvider $provider, string $model): array
    {
        $fallback = (array) config('supermart.ai.fallback.'.$provider->value, [1, 3]);

        /** Ollama Cloud is a flat subscription; "Other" could be anything. */
        if (in_array($provider, [AiProvider::OllamaCloud, AiProvider::Custom], true)) {
            return [(float) $fallback[0], (float) $fallback[1]];
        }

        $name = strtolower(str_contains($model, '/') ? substr($model, strrpos($model, '/') + 1) : $model);
        $match = null;

        foreach ((array) config('supermart.ai.prices', []) as $prefix => $rates) {
            if (str_starts_with($name, (string) $prefix) && ($match === null || strlen((string) $prefix) > strlen($match[0]))) {
                $match = [(string) $prefix, $rates];
            }
        }

        $rates = $match[1] ?? $fallback;

        return [(float) $rates[0], (float) $rates[1]];
    }
}
