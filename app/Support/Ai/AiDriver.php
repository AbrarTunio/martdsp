<?php

namespace App\Support\Ai;

/**
 * One way of talking to an AI company's servers.
 *
 * Every driver answers the same two questions — which models can this key
 * use, and what does the model say to this prompt — so the rest of the app
 * never needs to know which company is on the other end.
 */
interface AiDriver
{
    /**
     * The models the key can reach, for the dropdown in Settings.
     *
     * @return list<array{id: string, name: string}>
     *
     * @throws AiException
     */
    public function listModels(): array;

    /**
     * Ask the model, expecting JSON in the shape of the prompt's schema.
     *
     * @throws AiException
     */
    public function complete(AiPrompt $prompt): AiReply;
}
