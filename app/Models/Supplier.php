<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use App\Support\PhoneNumber;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A distributor, wholesaler or salesman the shop buys from.
 *
 * `balance_paisa` is what the shop owes them right now, and it is a cache of
 * the last row of their ledger. Only App\Services\SupplierLedgerService
 * writes it, for the same reason only StockService writes stock.
 */
#[Fillable([
    'name', 'company', 'phone', 'address', 'payment_terms_days', 'notes', 'is_active',
])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer',
            'opening_balance_paisa' => 'integer',
            'balance_paisa' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SupplierLedgerEntry::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        /* A number is looked for the way it is stored, with the 0 or the +92
           that was typed taken off it first. */
        $digits = PhoneNumber::digits($term);

        $query->where(function (Builder $query) use ($term, $digits): void {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('company', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->when($digits !== '', fn (Builder $query) => $query->orWhere('phone', 'like', "%{$digits}%"));
        });
    }

    /**
     * Suppliers the shop currently owes something to.
     */
    #[Scope]
    protected function owed(Builder $query): void
    {
        $query->where('balance_paisa', '>', 0);
    }

    /**
     * "Haji Traders (Nestlé)" — the salesman's name is how the shopkeeper
     * knows them, the company is how the bill is headed.
     */
    public function displayName(): string
    {
        return $this->company ? "{$this->name} ({$this->company})" : $this->name;
    }

    /**
     * How much of the balance is past its due date.
     *
     * Payments are taken to settle the oldest bills first, which is how every
     * distributor in the market reads an account. So whatever is owed beyond
     * the bills that are not yet due must belong to bills that are.
     */
    public function overduePaisa(): int
    {
        if ($this->balance_paisa <= 0) {
            return 0;
        }

        $notYetDue = (int) $this->purchases()
            ->where('status', PurchaseStatus::Received)
            ->whereDate('due_on', '>=', today())
            ->sum(DB::raw('total_paisa - paid_paisa'));

        return max(0, $this->balance_paisa - $notYetDue);
    }
}
