<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AiInsightRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question put to the AI about a page, with what it cost and what it
 * said. Written once and never changed.
 */
#[Fillable([
    'page', 'scope_hash', 'status', 'provider', 'model', 'input_tokens', 'output_tokens',
    'cost_paisa', 'latency_ms', 'metric_pack', 'response', 'error', 'user_id',
])]
class AiInsightRun extends Model
{
    /** @use HasFactory<AiInsightRunFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const ANSWERED = 'ai';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_paisa' => 'integer',
            'latency_ms' => 'integer',
            'metric_pack' => 'array',
            'response' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function answered(Builder $query): void
    {
        $query->where('status', self::ANSWERED);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function thisMonth(Builder $query): void
    {
        $query->where('created_at', '>=', CarbonImmutable::now()->startOfMonth());
    }

    /**
     * What the AI has cost so far this calendar month, in paisa.
     */
    public static function spentThisMonth(): int
    {
        return (int) static::query()->thisMonth()->sum('cost_paisa');
    }
}
