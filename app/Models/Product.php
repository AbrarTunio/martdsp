<?php

namespace App\Models;

use App\Support\Packaging;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

/**
 * An item on the shelf.
 *
 * Stock is held in whole base units and nowhere else. Everything the
 * shopkeeper sees — cartons, boxes, sachets — is a presentation of that one
 * number, which is why breaking open a carton needs no stock movement.
 */
#[Fillable([
    'sku', 'name', 'name_ur', 'category_id', 'brand_id', 'base_unit_id',
    'tax_rate', 'is_weighted', 'track_batches', 'track_expiry',
    'reorder_level_base', 'reorder_qty_base', 'is_active',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'tax_rate' => 'decimal:2',
            'is_weighted' => 'boolean',
            'track_batches' => 'boolean',
            'track_expiry' => 'boolean',
            'is_active' => 'boolean',
            'reorder_level_base' => 'integer',
            'reorder_qty_base' => 'integer',
            'avg_cost_base_paisa' => 'integer',
            'stock_qty_base' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $product): void {
            $product->sku ??= static::generateSku();
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function productUnits(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function barcodes(): HasManyThrough
    {
        return $this->hasManyThrough(Barcode::class, ProductUnit::class);
    }

    /**
     * The stock ledger for this item. `stock_qty_base` is a cache of the last
     * row's balance, and `stock:recalculate` proves the two agree.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * The layers this item's stock is made up of, for the goods where age
     * matters. Empty for everything else, which is most of the shop.
     */
    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Name, Urdu name, SKU or any barcode. This is the one search box on the
     * product list, so it has to match whatever the shopkeeper happens to
     * have in front of them.
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('name_ur', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "{$term}%")
                ->orWhereHas('barcodes', fn (Builder $barcodes) => $barcodes->where('code', $term));
        });
    }

    #[Scope]
    protected function lowStock(Builder $query): void
    {
        $query->whereColumn('stock_qty_base', '<=', 'reorder_level_base')
            ->where('reorder_level_base', '>', 0);
    }

    /**
     * The packaging level a sale defaults to when nothing was scanned.
     */
    public function defaultSaleUnit(): ?ProductUnit
    {
        return $this->productUnits->firstWhere('is_default_sale', true)
            ?? $this->productUnits->firstWhere('is_base', true)
            ?? $this->productUnits->first();
    }

    public function baseProductUnit(): ?ProductUnit
    {
        return $this->productUnits->firstWhere('is_base', true);
    }

    /**
     * 1,413 sachets rendered as "4 cartons, 10 boxes, 21 sachets" — a number
     * the shopkeeper can walk to the shelf and verify.
     */
    public function stockBreakdown(): string
    {
        return Packaging::describe($this->stock_qty_base, $this->productUnits);
    }

    /**
     * What the stock on hand is worth at the moving average cost. This is the
     * number that belongs in a valuation report — never the sale price, which
     * would book the profit before anything has been sold.
     */
    public function stockValuePaisa(): int
    {
        return $this->stock_qty_base * $this->avg_cost_base_paisa;
    }

    public function isLowOnStock(): bool
    {
        return $this->reorder_level_base > 0
            && $this->stock_qty_base <= $this->reorder_level_base;
    }

    /**
     * A short, typeable code the shopkeeper can fall back on when a barcode
     * will not scan. Uniqueness is enforced by the column; this only has to
     * avoid colliding often enough to matter.
     */
    public static function generateSku(): string
    {
        do {
            $sku = 'SM-'.Str::upper(Str::random(6));
        } while (static::where('sku', $sku)->exists());

        return $sku;
    }
}
