<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * A delivery, and the bill that came with it.
 *
 * Nothing on a purchase touches stock, cost or the supplier's balance until
 * it is received — see App\Services\PurchaseService.
 */
#[Fillable([
    'reference', 'supplier_id', 'invoice_no', 'purchase_date', 'due_on', 'status',
    'subtotal_paisa', 'discount_paisa', 'tax_paisa', 'total_paisa', 'paid_paisa',
    'payment_method', 'user_id', 'received_by', 'note', 'received_at',
])]
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'payment_method' => PaymentMethod::class,
            'purchase_date' => 'date',
            'due_on' => 'date',
            'subtotal_paisa' => 'integer',
            'discount_paisa' => 'integer',
            'tax_paisa' => 'integer',
            'total_paisa' => 'integer',
            'paid_paisa' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $purchase): void {
            $purchase->reference ??= static::nextReference();
            $purchase->purchase_date ??= today();
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    /**
     * The stock ledger rows receiving this purchase wrote.
     */
    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    /**
     * The supplier ledger rows receiving this purchase wrote.
     */
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(SupplierLedgerEntry::class, 'reference');
    }

    #[Scope]
    protected function received(Builder $query): void
    {
        $query->where('status', PurchaseStatus::Received);
    }

    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc('purchase_date')->orderByDesc('id');
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * A market purchase made with cash, with no supplier to owe.
     */
    public function isCashPurchase(): bool
    {
        return $this->supplier_id === null;
    }

    public function supplierName(): string
    {
        return $this->supplier?->displayName() ?? __('Cash purchase');
    }

    /**
     * What was left owing on this bill when it was received.
     */
    public function unpaidPaisa(): int
    {
        return max(0, $this->total_paisa - $this->paid_paisa);
    }

    /**
     * PUR-000001 and upwards.
     */
    public static function nextReference(): string
    {
        $last = static::query()->orderByDesc('id')->value('reference');
        $number = $last ? (int) Str::afterLast($last, '-') : 0;

        return 'PUR-'.str_pad((string) ($number + 1), 6, '0', STR_PAD_LEFT);
    }
}
