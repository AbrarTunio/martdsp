<?php

namespace App\Models;

use Database\Factories\StockBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One layer of stock: what arrived on one delivery under one expiry date.
 *
 * Only products flagged to track batches or expiry have these. For everything
 * else a tin of ghee is a tin of ghee and there is nothing to keep apart.
 */
#[Fillable([
    'product_id', 'purchase_item_id', 'batch_no', 'expiry_date',
    'received_base', 'qty_base', 'cost_base_paisa', 'received_at',
])]
class StockBatch extends Model
{
    /** @use HasFactory<StockBatchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'received_base' => 'integer',
            'qty_base' => 'integer',
            'cost_base_paisa' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    /**
     * Layers with something still on the shelf.
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('qty_base', '>', 0);
    }

    /**
     * First expiry, first out. A layer with no date has nothing pressing
     * about it, so it waits behind every dated one.
     */
    #[Scope]
    protected function soonestFirst(Builder $query): void
    {
        $query->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderBy('id');
    }

    /**
     * Layers goods can still go back onto: anything that has not had its day
     * yet, sold out or not.
     */
    #[Scope]
    protected function stillSellable(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereNull('expiry_date')
                ->orWhereDate('expiry_date', '>=', today());
        });
    }

    /**
     * Dated layers that are already past their day or reach it within the
     * given number of days.
     */
    #[Scope]
    protected function expiringWithin(Builder $query, int $days): void
    {
        $query->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', today()->addDays($days));
    }

    #[Scope]
    protected function expired(Builder $query): void
    {
        $query->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', today());
    }

    /**
     * Days until it goes off — negative once it has. Null when the layer
     * carries no date at all.
     */
    public function daysLeft(): ?int
    {
        if (! $this->expiry_date instanceof Carbon) {
            return null;
        }

        return (int) today()->diffInDays($this->expiry_date, false);
    }

    public function hasExpired(): bool
    {
        $days = $this->daysLeft();

        return $days !== null && $days < 0;
    }

    /**
     * What is left of this layer, at what it landed at. This is the money at
     * risk if it is not sold in time.
     */
    public function valuePaisa(): int
    {
        return $this->qty_base * $this->cost_base_paisa;
    }

    /**
     * The batch as the shopkeeper would say it — its number when the carton
     * carries one, otherwise the day it arrived.
     */
    public function name(): string
    {
        if (filled($this->batch_no)) {
            return (string) $this->batch_no;
        }

        return __('Taken in :date', ['date' => $this->received_at->format('d M Y')]);
    }
}
