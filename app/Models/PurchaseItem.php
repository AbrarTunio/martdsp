<?php

namespace App\Models;

use Database\Factories\PurchaseItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One line of a delivery, as printed on the bill.
 *
 * `qty` and `unit_cost_paisa` are the bill's own figures, in the size bought.
 * `qty_base` and `cost_base_paisa` are what the stock ledger sees: every
 * piece that arrived, free ones included, at what each one really cost.
 */
#[Fillable([
    'purchase_id', 'product_id', 'product_unit_id', 'qty', 'bonus_qty', 'qty_base',
    'unit_cost_paisa', 'line_total_paisa', 'cost_base_paisa', 'batch_no', 'expiry_date',
])]
class PurchaseItem extends Model
{
    /** @use HasFactory<PurchaseItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'bonus_qty' => 'integer',
            'qty_base' => 'integer',
            'unit_cost_paisa' => 'integer',
            'line_total_paisa' => 'integer',
            'cost_base_paisa' => 'integer',
            'expiry_date' => 'date',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
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
     * Base units in one of the size this line was bought in.
     */
    public function factor(): int
    {
        return max(1, (int) ($this->productUnit?->conversion_factor ?? 1));
    }

    /**
     * "12 cartons + 1 free" — what was on the bill, not what it flattened to.
     */
    public function quantityInWords(): string
    {
        $name = Str::lower((string) ($this->productUnit?->unit?->name ?? $this->product?->baseUnit?->name ?? ''));
        $words = $this->qty.' '.Str::plural($name, $this->qty);

        if ($this->bonus_qty > 0) {
            $words .= ' + '.__(':count free', ['count' => $this->bonus_qty]);
        }

        return $words;
    }
}
