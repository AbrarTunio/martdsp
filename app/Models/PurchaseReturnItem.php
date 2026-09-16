<?php

namespace App\Models;

use Database\Factories\PurchaseReturnItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One line of goods sent back.
 */
#[Fillable([
    'purchase_return_id', 'product_id', 'product_unit_id', 'qty', 'qty_base',
    'unit_credit_paisa', 'line_total_paisa', 'cost_base_paisa',
])]
class PurchaseReturnItem extends Model
{
    /** @use HasFactory<PurchaseReturnItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'qty_base' => 'integer',
            'unit_credit_paisa' => 'integer',
            'line_total_paisa' => 'integer',
            'cost_base_paisa' => 'integer',
        ];
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function quantityInWords(): string
    {
        $name = Str::lower((string) ($this->productUnit?->unit?->name ?? $this->product?->baseUnit?->name ?? ''));

        return $this->qty.' '.Str::plural($name, $this->qty);
    }
}
