<?php

namespace App\Support\Ai;

use App\Enums\AiProvider;
use App\Models\Setting;

/**
 * Everything needed to reach one AI: the company, the key, the model and
 * where the company's servers are.
 *
 * Held only on the server. The key is decrypted when a call is about to be
 * made and never handed to a page.
 */
final readonly class AiConnection
{
    public const KEY_SETTING = 'ai.api_key';

    public function __construct(
        public AiProvider $provider,
        public string $apiKey,
        public string $model,
        public string $baseUrl,
    ) {}

    /**
     * The saved connection, or null until a provider, key and model have
     * all been saved.
     */
    public static function fromSettings(): ?self
    {
        $provider = AiProvider::tryFrom((string) Setting::read('ai.provider'));
        $key = Setting::secret(self::KEY_SETTING);
        $model = trim((string) Setting::read('ai.model'));

        if ($provider === null || $key === null || $model === '') {
            return null;
        }

        return self::make($provider, $key, $model, (string) Setting::read('ai.base_url'));
    }

    /**
     * A connection from what is on the settings form, which may not be saved
     * yet — so a key can be tried before it is kept.
     */
    public static function make(AiProvider $provider, string $apiKey, string $model = '', ?string $baseUrl = null): self
    {
        $baseUrl = $provider->needsBaseUrl() ? (string) $baseUrl : (string) $provider->baseUrl();

        return new self($provider, trim($apiKey), trim($model), rtrim(trim($baseUrl), '/'));
    }

    public function driver(): AiDriver
    {
        $driver = $this->provider->driver();

        return new $driver($this);
    }

    /**
     * The saved key as the settings page shows it: enough to recognise,
     * not enough to use — "sk-a…9f2c".
     */
    public static function maskedKey(): ?string
    {
        $key = Setting::secret(self::KEY_SETTING);

        if ($key === null) {
            return null;
        }

        return mb_strlen($key) <= 12
            ? str_repeat('•', 8)
            : mb_substr($key, 0, 4).'…'.mb_substr($key, -4);
    }
}
