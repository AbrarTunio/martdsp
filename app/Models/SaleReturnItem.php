<?php

namespace App\Models;

use Database\Factories\SaleReturnItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of goods handed back, tied to the line on the bill it came off.
 */
#[Fillable([
    'sale_return_id', 'sale_item_id', 'product_id', 'product_unit_id', 'qty', 'qty_base',
    'unit_refund_paisa', 'tax_paisa', 'line_total_paisa', 'cost_base_paisa',
])]
class SaleReturnItem extends Model
{
    /** @use HasFactory<SaleReturnItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'qty_base' => 'integer',
            'unit_refund_paisa' => 'integer',
            'tax_paisa' => 'integer',
            'line_total_paisa' => 'integer',
            'cost_base_paisa' => 'integer',
        ];
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
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
     * "2" rather than "2.000", the way the bill itself prints it.
     */
    public function qtyForInput(): string
    {
        $qty = (string) $this->qty;

        if (! str_contains($qty, '.')) {
            return $qty;
        }

        return rtrim(rtrim($qty, '0'), '.') ?: '0';
    }

    public function costPaisa(): int
    {
        return $this->qty_base * $this->cost_base_paisa;
    }
}
