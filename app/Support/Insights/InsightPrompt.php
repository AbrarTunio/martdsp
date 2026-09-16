<?php

namespace App\Support\Insights;

use App\Models\Setting;
use App\Support\Ai\AiPrompt;

/**
 * What the AI is asked, and the shape the answer must come back in.
 *
 * The instructions never change, so a provider that charges less for a
 * prompt it has seen before can do so. Only the page's figures change.
 */
final class InsightPrompt
{
    public static function for(MetricPack $pack): AiPrompt
    {
        return new AiPrompt(
            system: self::system(),
            user: json_encode($pack->forAi(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            schema: self::schema(),
            maxTokens: (int) config('supermart.ai.max_output_tokens', 1500),
        );
    }

    /**
     * The one instruction, the same for every page and every shop.
     */
    public static function system(): string
    {
        $urdu = Setting::read('ai.language') === 'ur';

        $lines = [
            'You advise the owner of a small supermarket in Pakistan. The owner is not a technical person and has no head for jargon. Write the way a trusted accountant would talk across the counter: short sentences, plain words, no bullet-point management-speak, no flattery.',
            'You are given one page of figures from the shop\'s own system as JSON: a summary, a set of facts, and findings its rules already raised.',
            'Rules you must follow:',
            '- Use only the figures given. Never invent a number, a product, a person or a date. If something is not in the figures, do not mention it.',
            '- Money is already written as text, such as "Rs. 4,000". Copy those amounts exactly; never convert them or add decimals.',
            '- Explain what a figure means for the shop and what to do about it. "Sales fell 12%" is not insight; why it matters and what to do next is.',
            '- Put the biggest money first. Two or three things done well beat eight things listed.',
            '- When you write about a finding you were given, copy its key into "finding". When a point is your own reading of the facts, leave "finding" as an empty string.',
            '- Never name a customer or a member of staff in a way that accuses them of theft. Say what the figures show and suggest a check.',
            '- "impact" is the money at stake in words, such as "About Rs. 12,000 tied up", or an empty string when there is none.',
            '- "watch_next" is one to three short things to keep an eye on over the coming days.',
            'Answer with a single JSON object and nothing else, in this shape:',
            '{"headline": string, "summary": string, "insights": [{"finding": string, "title": string, "explanation": string, "action": string, "impact": string}], "watch_next": [string]}',
            'headline is at most 12 words. summary is two or three sentences. Give between one and five insights.',
        ];

        if ($urdu) {
            $lines[] = 'Write every piece of text in Urdu, in the Urdu script, except the JSON keys, the finding keys and the money amounts, which stay exactly as given.';
        }

        return implode("\n", $lines);
    }

    /**
     * Every provider is stricter than the last, so: no extra keys, every
     * key required, and no limits a schema translator might drop.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $text = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'headline' => ['type' => 'string', 'description' => 'The one thing the owner should know, at most 12 words.'],
                'summary' => ['type' => 'string', 'description' => 'Two or three sentences on how this page is doing.'],
                'insights' => [
                    'type' => 'array',
                    'description' => 'One to five points, the biggest money first.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'finding' => ['type' => 'string', 'description' => 'The key of the finding this answers, or an empty string.'],
                            'title' => $text,
                            'explanation' => ['type' => 'string', 'description' => 'What the figures show and why it matters.'],
                            'action' => ['type' => 'string', 'description' => 'What to do about it, in one or two sentences.'],
                            'impact' => ['type' => 'string', 'description' => 'The money at stake in words, or an empty string.'],
                        ],
                        'required' => ['finding', 'title', 'explanation', 'action', 'impact'],
                        'additionalProperties' => false,
                    ],
                ],
                'watch_next' => [
                    'type' => 'array',
                    'description' => 'One to three short things to keep an eye on.',
                    'items' => $text,
                ],
            ],
            'required' => ['headline', 'summary', 'insights', 'watch_next'],
            'additionalProperties' => false,
        ];
    }
}
