<?php

namespace App\Enums;

use App\Support\Ai\AiDriver;
use App\Support\Ai\AnthropicDriver;
use App\Support\Ai\GeminiDriver;
use App\Support\Ai\OpenAiCompatibleDriver;

/**
 * The AI companies the shop can bring its own key for.
 *
 * Only three kinds of conversation are needed to reach all of them: Claude
 * has its own, Gemini has its own, and the rest speak the same language as
 * OpenAI — so they are one driver pointed at a different address.
 */
enum AiProvider: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case Gemini = 'gemini';
    case Groq = 'groq';
    case Zai = 'zai';
    case OllamaCloud = 'ollama';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Claude (Anthropic)',
            self::OpenAi => 'OpenAI',
            self::Gemini => 'Google Gemini',
            self::Groq => 'Groq',
            self::Zai => 'Z.ai',
            self::OllamaCloud => 'Ollama Cloud',
            self::Custom => 'Other (OpenAI-compatible)',
        };
    }

    /**
     * @return class-string<AiDriver>
     */
    public function driver(): string
    {
        return match ($this) {
            self::Anthropic => AnthropicDriver::class,
            self::Gemini => GeminiDriver::class,
            default => OpenAiCompatibleDriver::class,
        };
    }

    /**
     * Where the provider's API lives. Only "Other" asks for one.
     */
    public function baseUrl(): ?string
    {
        return match ($this) {
            self::Anthropic => 'https://api.anthropic.com/v1',
            self::OpenAi => 'https://api.openai.com/v1',
            self::Gemini => 'https://generativelanguage.googleapis.com/v1beta',
            self::Groq => 'https://api.groq.com/openai/v1',
            self::Zai => 'https://api.z.ai/api/paas/v4',
            self::OllamaCloud => 'https://ollama.com/v1',
            self::Custom => null,
        };
    }

    public function needsBaseUrl(): bool
    {
        return $this === self::Custom;
    }

    /**
     * Where the shopkeeper gets a key, in words.
     */
    public function keyHint(): string
    {
        return match ($this) {
            self::Anthropic => 'Create a key at console.anthropic.com → API keys.',
            self::OpenAi => 'Create a key at platform.openai.com → API keys.',
            self::Gemini => 'Create a key at aistudio.google.com → Get API key.',
            self::Groq => 'Create a key at console.groq.com → API keys.',
            self::Zai => 'Create a key at z.ai → API keys.',
            self::OllamaCloud => 'Create a key at ollama.com → Settings → Keys.',
            self::Custom => 'Any service that accepts OpenAI-style requests, such as a model running on another computer.',
        };
    }

    /**
     * The model picked for them after fetching, when the key can reach it.
     * Writing a short note from figures is not demanding work.
     */
    public function suggestedModel(): ?string
    {
        return match ($this) {
            self::Anthropic => 'claude-opus-5',
            self::OpenAi => 'gpt-5-mini',
            self::Gemini => 'gemini-2.5-flash',
            self::Groq => 'llama-3.3-70b-versatile',
            self::Zai => 'glm-4.6',
            self::OllamaCloud => 'gpt-oss:120b',
            self::Custom => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $provider): array => [$provider->value => $provider->label()])
            ->all();
    }
}
