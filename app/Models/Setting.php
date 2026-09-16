<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * A single configurable value.
 *
 * The set of recognised keys, their defaults and their casts are declared in
 * config/supermart.php; this table only holds the values the shop has actually
 * changed. Reads go through a cached snapshot of the whole table, held for the
 * length of the request, so touching a setting anywhere in a request costs one
 * query at most — and usually none at all, which matters because the till asks
 * about tax, rounding and credit on every scan.
 */
#[Fillable(['key', 'group', 'value', 'cast_type', 'is_encrypted'])]
class Setting extends Model
{
    /**
     * The settings as they were first read in this request.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $snapshot = null;

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
        ];
    }

    /**
     * Read a setting. Falls back to the explicit default when one is given,
     * otherwise to the default declared in config/supermart.php.
     */
    public static function read(string $key, mixed $default = null): mixed
    {
        $all = static::allCached();

        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        return $default ?? static::schema()[$key]['default'] ?? null;
    }

    /**
     * Every recognised setting with its current value, keyed by setting key.
     * Unsaved settings appear with their declared default.
     *
     * @return array<string, mixed>
     */
    public static function values(): array
    {
        $stored = static::allCached();

        $values = [];

        foreach (static::schema() as $key => $definition) {
            $values[$key] = array_key_exists($key, $stored)
                ? $stored[$key]
                : $definition['default'];
        }

        return $values;
    }

    /**
     * Persist a batch of settings, taking each one's group and cast from the
     * declared schema. Unrecognised keys are ignored rather than stored, so a
     * tampered form cannot write arbitrary rows.
     *
     * @param  array<string, mixed>  $values
     */
    public static function writeMany(array $values): void
    {
        $schema = static::schema();

        foreach ($values as $key => $value) {
            if (! isset($schema[$key])) {
                continue;
            }

            static::write(
                $key,
                static::coerce($value, $schema[$key]['cast']),
                $schema[$key]['group'],
            );
        }
    }

    /**
     * The declared settings schema.
     *
     * @return array<string, array{default: mixed, cast: string, group: string}>
     */
    public static function schema(): array
    {
        return config('supermart.settings', []);
    }

    /**
     * Cast an incoming form value to the type the schema declares for it.
     */
    private static function coerce(mixed $value, string $cast): mixed
    {
        return match ($cast) {
            'integer' => (int) $value,
            'float' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'array' => is_array($value) ? $value : (array) $value,
            default => (string) $value,
        };
    }

    /**
     * Create or update a setting and forget the cached snapshot.
     */
    public static function write(
        string $key,
        mixed $value,
        string $group = 'general',
        bool $encrypted = false,
    ): void {
        $castType = static::inferCastType($value);
        $raw = static::serialize($value, $castType);

        static::updateOrCreate(
            ['key' => $key],
            [
                'group' => $group,
                'value' => $encrypted && $raw !== null ? Crypt::encryptString($raw) : $raw,
                'cast_type' => $castType,
                'is_encrypted' => $encrypted,
            ],
        );

        static::flushCache();
    }

    /**
     * Every setting, decoded, keyed by setting key.
     *
     * Encrypted settings are left out: the snapshot sits in the cache store,
     * and a secret decrypted into it would no longer be a secret. They are
     * read one at a time with secret() instead.
     *
     * @return array<string, mixed>
     */
    public static function allCached(): array
    {
        return static::$snapshot ??= Cache::rememberForever(static::cacheKey(), function (): array {
            return static::query()
                ->where('is_encrypted', false)
                ->get()
                ->mapWithKeys(fn (self $setting): array => [$setting->key => $setting->decodedValue()])
                ->all();
        });
    }

    /**
     * Read an encrypted setting straight from the table, such as an API key.
     */
    public static function secret(string $key): ?string
    {
        $value = static::query()->where('key', $key)->where('is_encrypted', true)->first()?->decodedValue();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Store a secret encrypted, or remove it when given null.
     */
    public static function writeSecret(string $key, ?string $value, string $group = 'general'): void
    {
        if ($value === null || $value === '') {
            static::query()->where('key', $key)->delete();
            static::flushCache();

            return;
        }

        static::write($key, $value, $group, encrypted: true);
    }

    public static function flushCache(): void
    {
        static::$snapshot = null;

        Cache::forget(static::cacheKey());
    }

    /**
     * Drop the in-memory copy without touching the shared cache.
     *
     * A web request is over long before a setting can go stale in it. A queue
     * worker is not: it stays up for hours, so it is handed a fresh copy
     * before each job in case the shop changed something meanwhile.
     */
    public static function forgetSnapshot(): void
    {
        static::$snapshot = null;
    }

    public function decodedValue(): mixed
    {
        $raw = $this->value;

        if ($raw === null) {
            return null;
        }

        if ($this->is_encrypted) {
            $raw = Crypt::decryptString($raw);
        }

        return match ($this->cast_type) {
            'integer' => (int) $raw,
            'float' => (float) $raw,
            'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'array' => json_decode($raw, true),
            default => $raw,
        };
    }

    private static function cacheKey(): string
    {
        return 'settings.all';
    }

    private static function inferCastType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_array($value) => 'array',
            default => 'string',
        };
    }

    private static function serialize(mixed $value, string $castType): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($castType) {
            'boolean' => $value ? '1' : '0',
            'array' => json_encode($value),
            default => (string) $value,
        };
    }
}
