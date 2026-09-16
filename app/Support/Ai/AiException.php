<?php

namespace App\Support\Ai;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Something went wrong talking to the AI. The message is written for the
 * shopkeeper, because it is shown to them as it is.
 */
final class AiException extends RuntimeException
{
    public static function fromResponse(Response $response, string $provider): self
    {
        return new self(match (true) {
            in_array($response->status(), [401, 403], true) => __(':provider did not accept the API key. Check it was copied in full.', ['provider' => $provider]),
            $response->status() === 404 => __(':provider does not recognise that model. Fetch the list again and pick one.', ['provider' => $provider]),
            $response->status() === 429 => __(':provider says the key is over its limit or out of credit.', ['provider' => $provider]),
            $response->serverError() => __(':provider is having trouble right now. Try again in a few minutes.', ['provider' => $provider]),
            default => __(':provider turned the request down: :reason', [
                'provider' => $provider,
                'reason' => self::reason($response),
            ]),
        });
    }

    public static function unreachable(string $provider): self
    {
        return new self(__('Could not reach :provider. Check the internet connection.', ['provider' => $provider]));
    }

    public static function unreadable(): self
    {
        return new self(__('The AI answered, but not in the expected form.'));
    }

    public static function refused(string $provider): self
    {
        return new self(__(':provider declined to answer this one.', ['provider' => $provider]));
    }

    public static function cutShort(): self
    {
        return new self(__('The AI ran out of room before finishing its answer.'));
    }

    /**
     * The provider's own words, trimmed. Error bodies differ by company, so
     * the usual places are tried in turn.
     */
    private static function reason(Response $response): string
    {
        $message = $response->json('error.message')
            ?? $response->json('error')
            ?? $response->json('message')
            ?? $response->reason();

        return mb_strimwidth(is_string($message) ? $message : $response->reason(), 0, 200, '…');
    }
}
