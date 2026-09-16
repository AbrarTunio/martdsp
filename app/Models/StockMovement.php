<?php

namespace App\Models;

use App\Enums\MovementType;
use App\Support\Money;
use App\Support\Packaging;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One line of the stock ledger.
 *
 * This table is the source of truth for every quantity in the shop.
 * `products.stock_qty_base` is a cache of it, and `stock:recalculate` proves
 * the two agree. Rows are appended and never edited — a mistake is corrected
 * with another movement, because a ledger that can be rewritten is not
 * evidence of anything.
 */
#[Fillable([
    'product_id', 'product_unit_id', 'qty_base', 'type', 'reference_type', 'reference_id',
    'unit_cost_base_paisa', 'avg_cost_after_paisa', 'balance_after_base', 'batch_id',
    'user_id', 'note', 'occurred_at',
])]
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'qty_base' => 'integer',
            'type' => MovementType::class,
            'unit_cost_base_paisa' => 'integer',
            'avg_cost_after_paisa' => 'integer',
            'balance_after_base' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The purchase, sale or adjustment that caused this movement.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Ledger order: the order the rows were written in, which is the order
     * every balance and average on them was worked out in.
     *
     * Not `occurred_at`. A delivery entered the next morning is dated the
     * night before, and replaying it into the middle of the day's sales would
     * produce a different average cost from the one the shop actually used —
     * so a rebuild would report drift in a ledger that had none. The date is
     * for reports; the key is for arithmetic.
     */
    #[Scope]
    protected function inLedgerOrder(Builder $query): void
    {
        $query->orderBy('id');
    }

    /**
     * Newest first, in the same order as the ledger, so that each row's
     * "left" figure follows from the one beneath it.
     */
    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc('id');
    }

    #[Scope]
    protected function between(Builder $query, mixed $from, mixed $to): void
    {
        $query->whereBetween('occurred_at', [$from, $to]);
    }

    public function isIncrease(): bool
    {
        return $this->qty_base > 0;
    }

    /**
     * The quantity in the packaging it was entered in — "2 cartons" rather
     * than "576 sachets" — falling back to a base-unit breakdown.
     */
    public function quantityInWords(): string
    {
        $factor = (int) ($this->productUnit?->conversion_factor ?? 0);
        $qty = abs($this->qty_base);

        if ($factor > 1 && $qty % $factor === 0) {
            $packs = intdiv($qty, $factor);
            $name = Str::lower((string) ($this->productUnit?->unit?->name ?? ''));

            return $packs.' '.Str::plural($name, $packs);
        }

        return Packaging::describe($qty, $this->product?->productUnits ?? collect());
    }

    /**
     * What this movement was worth at the cost it moved at. Stock valuation
     * and shrinkage both read this rather than today's cost.
     */
    public function valuePaisa(): int
    {
        return $this->qty_base * $this->unit_cost_base_paisa;
    }

    public function formattedValue(): string
    {
        return Money::withSymbol(abs($this->valuePaisa()));
    }
}
