<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\ProductUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One packaging level of one product: the sachet, the box of 24, the carton
 * of 12 boxes.
 *
 * conversion_factor is the flattened number of base units this level holds.
 * It is written by PackagingService whenever the packaging is saved, never
 * typed by hand, because the till must resolve a scan in one lookup rather
 * than by walking the parent chain.
 */
#[Fillable([
    'product_id', 'unit_id', 'parent_unit_id', 'qty_per_parent', 'conversion_factor',
    'sale_price_paisa', 'mrp_paisa', 'is_base', 'is_default_sale', 'is_default_purchase',
])]
class ProductUnit extends Model
{
    /** @use HasFactory<ProductUnitFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'qty_per_parent' => 'integer',
            'conversion_factor' => 'integer',
            'sale_price_paisa' => 'integer',
            'mrp_paisa' => 'integer',
            'is_base' => 'boolean',
            'is_default_sale' => 'boolean',
            'is_default_purchase' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The unit this level was described in terms of — "1 carton = 12 *boxes*".
     */
    public function parentUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'parent_unit_id');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(Barcode::class);
    }

    /**
     * Largest packaging first, which is how a unit picker should read.
     */
    #[Scope]
    protected function largestFirst(Builder $query): void
    {
        $query->orderByDesc('conversion_factor');
    }

    public function primaryBarcode(): ?Barcode
    {
        return $this->barcodes->firstWhere('is_primary', true) ?? $this->barcodes->first();
    }

    /**
     * "Carton (288 sachets)" — the size is the part that stops a cashier
     * picking the wrong line.
     */
    public function label(?string $baseUnitName = null): string
    {
        $name = $this->unit?->name ?? '';

        if ($this->is_base || $this->conversion_factor <= 1) {
            return $name;
        }

        $base = $baseUnitName ?? $this->product?->baseUnit?->name ?? 'base units';

        return "{$name} ({$this->conversion_factor} {$base})";
    }

    /**
     * What one base unit costs at this packaging level's price. Comparing
     * this across levels is what exposes a carton priced worse than a sachet.
     */
    public function pricePerBasePaisa(): int
    {
        if ($this->conversion_factor <= 0) {
            return 0;
        }

        return intdiv($this->sale_price_paisa, $this->conversion_factor);
    }

    public function formattedPrice(): string
    {
        return Money::withSymbol($this->sale_price_paisa);
    }
}
