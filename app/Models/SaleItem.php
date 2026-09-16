<?php

namespace App\Models;

use Database\Factories\SaleItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line on a bill. Name, size and price are copied onto it when sold, so
 * a receipt reprinted next year says what it said on the day.
 */
#[Fillable([
    'sale_id', 'product_id', 'product_unit_id', 'name', 'unit_name', 'qty', 'qty_base',
    'unit_price_paisa', 'gross_paisa', 'discount', 'discount_paisa', 'bill_discount_paisa',
    'tax_rate', 'tax_paisa', 'line_total_paisa', 'cost_at_sale_base_paisa',
])]
class SaleItem extends Model
{
    /** @use HasFactory<SaleItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'qty_base' => 'integer',
            'unit_price_paisa' => 'integer',
            'gross_paisa' => 'integer',
            'discount_paisa' => 'integer',
            'bill_discount_paisa' => 'integer',
            'tax_rate' => 'decimal:2',
            'tax_paisa' => 'integer',
            'line_total_paisa' => 'integer',
            'cost_at_sale_base_paisa' => 'integer',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
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
     * The times this line has come back, across every return made against
     * the bill.
     */
    public function returnItems(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function costPaisa(): int
    {
        return $this->qty_base * $this->cost_at_sale_base_paisa;
    }

    /**
     * What one of them actually earned the shop: the ticket price less its
     * own discount and its share of the bill-wide one. This, not the ticket
     * price, is what a refund hands back.
     */
    public function netUnitPricePaisa(): int
    {
        if ($this->qty_base <= 0) {
            return 0;
        }

        return intdiv(max(0, $this->line_total_paisa), $this->qty_base);
    }

    /**
     * How much of this line has already gone back, counted in base units so
     * that half a kilo off a two-kilo line is not rounded away.
     */
    public function returnedQtyBase(): int
    {
        return (int) ($this->relationLoaded('returnItems')
            ? $this->returnItems->sum('qty_base')
            : $this->returnItems()->sum('qty_base'));
    }

    /**
     * What is still available to bring back.
     */
    public function returnableQtyBase(): int
    {
        return max(0, (int) $this->qty_base - $this->returnedQtyBase());
    }

    /**
     * "2" rather than "2.000", "1.25" rather than "1.250".
     */
    public function qtyForInput(): string
    {
        $qty = (string) $this->qty;

        if (! str_contains($qty, '.')) {
            return $qty;
        }

        return rtrim(rtrim($qty, '0'), '.') ?: '0';
    }

    /**
     * Everything taken off this line, its own discount and its share of the
     * bill's.
     */
    public function totalDiscountPaisa(): int
    {
        return $this->discount_paisa + $this->bill_discount_paisa;
    }
}
