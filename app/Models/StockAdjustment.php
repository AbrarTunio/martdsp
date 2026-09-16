<?php

namespace App\Models;

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentStatus;
use App\Support\Money;
use Database\Factories\StockAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * A correction to stock made by hand, with a reason attached.
 *
 * Opening stock is one of these too — it is simply the first correction,
 * from nothing to what is already on the shelf — which keeps one screen and
 * one code path instead of two.
 */
#[Fillable([
    'reference', 'reason', 'note', 'status', 'user_id', 'approved_by',
    'value_paisa', 'adjusted_at', 'posted_at',
])]
class StockAdjustment extends Model
{
    /** @use HasFactory<StockAdjustmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'reason' => AdjustmentReason::class,
            'status' => AdjustmentStatus::class,
            'value_paisa' => 'integer',
            'adjusted_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $adjustment): void {
            $adjustment->reference ??= static::nextReference();
            $adjustment->adjusted_at ??= now();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The ledger rows this adjustment wrote, which is the proof that posting
     * it did what it said it would.
     */
    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    #[Scope]
    protected function posted(Builder $query): void
    {
        $query->where('status', AdjustmentStatus::Posted);
    }

    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc('adjusted_at')->orderByDesc('id');
    }

    #[Scope]
    protected function shrinkage(Builder $query): void
    {
        $query->where('status', AdjustmentStatus::Posted)
            ->whereIn('reason', collect(AdjustmentReason::cases())
                ->filter(fn (AdjustmentReason $reason): bool => $reason->isShrinkage())
                ->map(fn (AdjustmentReason $reason): string => $reason->value)
                ->all());
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function formattedValue(): string
    {
        return Money::withSymbol(abs($this->value_paisa));
    }

    /**
     * ADJ-000001 and upwards. Readable aloud over a phone, which is how a
     * shopkeeper will actually refer to one.
     */
    public static function nextReference(): string
    {
        $last = static::query()->orderByDesc('id')->value('reference');
        $number = $last ? (int) Str::afterLast($last, '-') : 0;

        return 'ADJ-'.str_pad((string) ($number + 1), 6, '0', STR_PAD_LEFT);
    }
}
