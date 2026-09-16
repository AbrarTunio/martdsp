<?php

namespace App\Models;

use App\Enums\StockTakeStatus;
use Database\Factories\StockTakeFactory;
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
 * A walk round part of the shop with a scanner, counting what is really
 * there.
 *
 * The count sheet is kept after posting because it is the evidence: which
 * items were short, by how much, what that cost, and who signed it off.
 */
#[Fillable([
    'reference', 'name', 'status', 'category_id', 'missing_are_zero', 'note',
    'user_id', 'posted_by', 'variance_value_paisa', 'started_at', 'posted_at',
])]
class StockTake extends Model
{
    /** @use HasFactory<StockTakeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => StockTakeStatus::class,
            'missing_are_zero' => 'boolean',
            'variance_value_paisa' => 'integer',
            'started_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $take): void {
            $take->reference ??= static::nextReference();
            $take->started_at ??= now();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTakeItem::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * The ledger rows the count wrote — one per item that was out.
     */
    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc('started_at')->orderByDesc('id');
    }

    #[Scope]
    protected function posted(Builder $query): void
    {
        $query->where('status', StockTakeStatus::Posted);
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * The count in the shopkeeper's words: its name, else the section, else
     * the whole shop.
     */
    public function title(): string
    {
        if (filled($this->name)) {
            return (string) $this->name;
        }

        return $this->category
            ? __('Count of :section', ['section' => $this->category->fullName()])
            : __('Whole shop count');
    }

    /**
     * STK-000001 and upwards.
     */
    public static function nextReference(): string
    {
        $last = static::query()->orderByDesc('id')->value('reference');
        $number = $last ? (int) Str::afterLast($last, '-') : 0;

        return 'STK-'.str_pad((string) ($number + 1), 6, '0', STR_PAD_LEFT);
    }
}
