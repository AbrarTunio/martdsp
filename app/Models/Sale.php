<?php

namespace App\Models;

use App\Enums\SaleStatus;
use App\Support\Money;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One bill at the till.
 *
 * Only App\Services\SaleService writes a sale, because completing one moves
 * stock, takes money and may write to a khata, and those three have to happen
 * together or not at all.
 */
#[Fillable([
    'invoice_no', 'customer_id', 'register_id', 'drawer_session_id', 'user_id', 'status',
    'subtotal_paisa', 'bill_discount', 'discount_paisa', 'tax_paisa', 'prices_include_tax',
    'round_off_paisa', 'total_paisa', 'paid_paisa', 'change_given_paisa', 'due_paisa',
    'note', 'sold_at', 'offline_uid', 'offline_rung_at', 'voided_by', 'voided_at', 'void_reason',
])]
class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'invoice_no' => 'integer',
            'subtotal_paisa' => 'integer',
            'discount_paisa' => 'integer',
            'tax_paisa' => 'integer',
            'prices_include_tax' => 'boolean',
            'round_off_paisa' => 'integer',
            'total_paisa' => 'integer',
            'paid_paisa' => 'integer',
            'change_given_paisa' => 'integer',
            'due_paisa' => 'integer',
            'sold_at' => 'datetime',
            'offline_rung_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    /**
     * The drawer shift it was rung up in.
     */
    public function drawerSession(): BelongsTo
    {
        return $this->belongsTo(DrawerSession::class);
    }

    /**
     * The cashier who rang it up.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * The stock ledger rows this sale wrote, and those its void wrote back.
     */
    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    /**
     * The khata rows this sale wrote.
     */
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(CustomerLedgerEntry::class, 'reference');
    }

    /**
     * Everything that has come back off this bill, however many trips the
     * customer made.
     */
    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    #[Scope]
    protected function completed(Builder $query): void
    {
        $query->where('status', SaleStatus::Completed);
    }

    #[Scope]
    protected function held(Builder $query): void
    {
        $query->where('status', SaleStatus::Held);
    }

    /**
     * Completed and voided sales — the ones with an invoice number.
     */
    #[Scope]
    protected function rungUp(Builder $query): void
    {
        $query->whereIn('status', [SaleStatus::Completed, SaleStatus::Void]);
    }

    /**
     * A cashier sees their own bills; a supervisor sees the shop's.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! $user->supervises()) {
            $query->where('user_id', $user->id);
        }
    }

    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc('sold_at')->orderByDesc('id');
    }

    /**
     * INV-000042. A held sale has no number yet.
     */
    public function invoiceNumber(): string
    {
        if ($this->invoice_no === null) {
            return __('On hold');
        }

        return 'INV-'.str_pad((string) $this->invoice_no, 6, '0', STR_PAD_LEFT);
    }

    public function customerName(): string
    {
        return $this->customer?->displayName() ?? __('Walk-in customer');
    }

    public function isCompleted(): bool
    {
        return $this->status === SaleStatus::Completed;
    }

    public function isVoid(): bool
    {
        return $this->status === SaleStatus::Void;
    }

    /**
     * A void puts the day back as if the sale never happened, so it is only
     * offered while the day is still open. An older sale is corrected with a
     * customer return, which leaves both events on the record.
     */
    public function isVoidable(): bool
    {
        return $this->isCompleted() && $this->sold_at?->isToday() === true;
    }

    /**
     * Whether anything on this bill is still available to bring back. A
     * cancelled bill has nothing: the goods are already back on the shelf.
     */
    public function isReturnable(): bool
    {
        return $this->isCompleted() && $this->items->contains(
            fn (SaleItem $item): bool => $item->returnableQtyBase() > 0
        );
    }

    /**
     * What has already been refunded against this bill, so the screens can
     * say so without adding it up again in the view.
     */
    public function refundedPaisa(): int
    {
        return (int) $this->returns->sum('total_paisa');
    }

    /**
     * What the goods on this bill cost the shop, at the average cost each
     * line was sold at.
     */
    public function costPaisa(): int
    {
        return (int) $this->items->sum(
            fn (SaleItem $item): int => $item->costPaisa()
        );
    }

    /**
     * What the shop kept after GST and the cost of the goods.
     */
    public function grossProfitPaisa(): int
    {
        return $this->total_paisa - $this->tax_paisa - $this->costPaisa();
    }

    public function formattedTotal(): string
    {
        return Money::withSymbol($this->total_paisa);
    }

    /**
     * The cart as the till holds it, so a held basket comes back exactly as
     * it was left.
     *
     * @return array<int, array{product_unit_id: int|null, qty: string, discount: string|null}>
     */
    public function cartLines(): array
    {
        return $this->items
            ->map(fn (SaleItem $item): array => [
                'product_unit_id' => $item->product_unit_id,
                'qty' => $item->qtyForInput(),
                'discount' => $item->discount,
            ])
            ->values()
            ->all();
    }
}
