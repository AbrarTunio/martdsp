<?php

namespace App\Models;

use App\Enums\RefundMethod;
use App\Enums\SaleReturnReason;
use App\Support\Money;
use Database\Factories\SaleReturnFactory;
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
 * Goods a customer brought back. Posted the moment it is saved — see
 * App\Services\SaleReturnService.
 *
 * A return is the honest way to undo an older bill. Voiding rubs the sale out
 * and is only offered on the day; a return leaves both the sale and the
 * return on the record, which is what a shop wants to be able to point at
 * when a customer comes back a third time.
 */
#[Fillable([
    'reference', 'sale_id', 'customer_id', 'register_id', 'reason', 'settlement',
    'restocked', 'total_paisa', 'tax_paisa', 'cost_value_paisa', 'user_id', 'note', 'returned_at',
])]
class SaleReturn extends Model
{
    /** @use HasFactory<SaleReturnFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'reason' => SaleReturnReason::class,
            'settlement' => RefundMethod::class,
            'restocked' => 'boolean',
            'total_paisa' => 'integer',
            'tax_paisa' => 'integer',
            'cost_value_paisa' => 'integer',
            'returned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $return): void {
            $return->reference ??= static::nextReference();
            $return->returned_at ??= now();
        });
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The stock rows this return wrote, which is the proof the goods went
     * back on the shelf — and, when the goods were expired, that none did.
     */
    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(CustomerLedgerEntry::class, 'reference');
    }

    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc('returned_at')->orderByDesc('id');
    }

    public function customerName(): string
    {
        return $this->customer?->displayName() ?? __('Walk-in customer');
    }

    /**
     * What the return cost the shop: money handed back, less the value of any
     * goods that came back with it. Expired goods come back worth nothing, so
     * the whole refund is a loss.
     */
    public function lossPaisa(): int
    {
        return $this->total_paisa - ($this->restocked ? $this->cost_value_paisa : 0);
    }

    public function formattedTotal(): string
    {
        return Money::withSymbol($this->total_paisa);
    }

    /**
     * SRT-000001 and upwards, to be read aloud over a phone the way PRT- and
     * ADJ- already are.
     */
    public static function nextReference(): string
    {
        $last = static::query()->orderByDesc('id')->value('reference');
        $number = $last ? (int) Str::afterLast($last, '-') : 0;

        return 'SRT-'.str_pad((string) ($number + 1), 6, '0', STR_PAD_LEFT);
    }
}
