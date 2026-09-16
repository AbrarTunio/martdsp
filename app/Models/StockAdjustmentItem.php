<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\StockAdjustmentItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One shelf on one adjustment.
 *
 * `qty` is what the shopkeeper typed, in the size they chose; `qty_base` is
 * the signed effect in base units, which is the only number the ledger ever
 * sees.
 */
#[Fillable([
    'stock_adjustment_id', 'product_id', 'product_unit_id', 'qty', 'qty_base',
    'system_qty_base', 'unit_cost_base_paisa', 'value_paisa', 'note',
])]
class StockAdjustmentItem extends Model
{
    /** @use HasFactory<StockAdjustmentItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'qty_base' => 'integer',
            'system_qty_base' => 'integer',
            'unit_cost_base_paisa' => 'integer',
            'value_paisa' => 'integer',
        ];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
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
    public function quantityInWords(): string
    {
        $name = Str::lower((string) ($this->productUnit?->unit?->name ?? $this->product?->baseUnit?->name ?? ''));

        return $this->qty.' '.Str::plural($name, $this->qty);
    }

    public function formattedValue(): string
    {
        return Money::withSymbol(abs($this->value_paisa));
    }
}
