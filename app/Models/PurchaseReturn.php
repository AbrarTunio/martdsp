<?php

namespace App\Models;

use App\Enums\ReturnReason;
use App\Enums\ReturnSettlement;
use Database\Factories\PurchaseReturnFactory;
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
 * Goods sent back to a supplier. Posted the moment it is saved — see
 * App\Services\PurchaseReturnService.
 */
#[Fillable([
    'reference', 'supplier_id', 'purchase_id', 'reason', 'settlement', 'total_paisa',
    'cost_value_paisa', 'user_id', 'note', 'returned_at',
])]
class PurchaseReturn extends Model
{
    /** @use HasFactory<PurchaseReturnFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'reason' => ReturnReason::class,
            'settlement' => ReturnSettlement::class,
            'total_paisa' => 'integer',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(SupplierLedgerEntry::class, 'reference');
    }

    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc('returned_at')->orderByDesc('id');
    }

    public function supplierName(): string
    {
        return $this->supplier?->displayName() ?? __('Market (no supplier)');
    }

    /**
     * What the return cost the shop: the goods left at what they cost, and
     * the supplier gave back this much less. Negative is a gain.
     */
    public function lossPaisa(): int
    {
        return $this->cost_value_paisa - $this->total_paisa;
    }

    /**
     * PRT-000001 and upwards.
     */
    public static function nextReference(): string
    {
        $last = static::query()->orderByDesc('id')->value('reference');
        $number = $last ? (int) Str::afterLast($last, '-') : 0;

        return 'PRT-'.str_pad((string) ($number + 1), 6, '0', STR_PAD_LEFT);
    }
}
