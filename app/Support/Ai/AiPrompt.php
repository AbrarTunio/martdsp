<?php

namespace App\Support\Ai;

/**
 * What is sent: a fixed set of instructions, the page's figures, and the
 * shape the answer must come back in.
 */
final readonly class AiPrompt
{
    /**
     * @param  string  $system  The same every time, so providers that cache it can.
     * @param  string  $user  This page's figures.
     * @param  array<string, mixed>  $schema  A JSON schema for the reply.
     */
    public function __construct(
        public string $system,
        public string $user,
        public array $schema,
        public int $maxTokens = 1500,
    ) {}

    /**
     * The smallest useful question, for the Test button: proves the key, the
     * model and JSON replies all work, for a fraction of a rupee.
     */
    public static function connectionTest(): self
    {
        return new self(
            system: 'You are checking that a connection works. Answer in JSON only.',
            user: 'Reply with a JSON object whose "reply" is a short greeting to a shopkeeper in Pakistan, under ten words.',
            schema: [
                'type' => 'object',
                'properties' => ['reply' => ['type' => 'string']],
                'required' => ['reply'],
                'additionalProperties' => false,
            ],
            maxTokens: 200,
        );
    }
}
