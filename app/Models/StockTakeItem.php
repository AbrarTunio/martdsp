<?php

namespace App\Models;

use Database\Factories\StockTakeItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One item on a count sheet: what was found, and — once posted — what the
 * books said and what the gap cost.
 */
#[Fillable([
    'stock_take_id', 'product_id', 'product_unit_id', 'counted_qty', 'counted_base',
    'was_counted', 'counted_at', 'system_qty_base', 'variance_base', 'unit_cost_base_paisa',
    'variance_value_paisa', 'note',
])]
class StockTakeItem extends Model
{
    /** @use HasFactory<StockTakeItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'counted_qty' => 'integer',
            'counted_base' => 'integer',
            'was_counted' => 'boolean',
            'counted_at' => 'datetime',
            'system_qty_base' => 'integer',
            'variance_base' => 'integer',
            'unit_cost_base_paisa' => 'integer',
            'variance_value_paisa' => 'integer',
        ];
    }

    public function stockTake(): BelongsTo
    {
        return $this->belongsTo(StockTake::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }

    /**
     * "3 cartons" — what was typed, not what it flattened to.
     */
    public function countInWords(): string
    {
        $name = Str::lower((string) ($this->productUnit?->unit?->name ?? $this->product?->baseUnit?->name ?? ''));

        return $this->counted_qty.' '.Str::plural($name, $this->counted_qty);
    }

    public function isShort(): bool
    {
        return $this->variance_base < 0;
    }

    public function isOver(): bool
    {
        return $this->variance_base > 0;
    }
}
