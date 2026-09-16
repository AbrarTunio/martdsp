<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A regular who buys on khata.
 *
 * `balance_paisa` is what they owe the shop right now, and it is a cache of
 * the last row of their khata. Only App\Services\KhataService writes it.
 */
#[Fillable([
    'name', 'name_ur', 'phone', 'address', 'credit_limit_paisa', 'notes', 'is_active',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'credit_limit_paisa' => 'integer',
            'opening_balance_paisa' => 'integer',
            'balance_paisa' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CustomerLedgerEntry::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Name, Urdu name or phone — the till's customer box takes whichever the
     * cashier was told.
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        /* A number is looked for the way it is stored, with the 0 or the +92
           the cashier typed taken off it first. */
        $digits = PhoneNumber::digits($term);

        $query->where(function (Builder $query) use ($term, $digits): void {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('name_ur', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->when($digits !== '', fn (Builder $query) => $query->orWhere('phone', 'like', "%{$digits}%"));
        });
    }

    /**
     * Customers who owe the shop something.
     */
    #[Scope]
    protected function owing(Builder $query): void
    {
        $query->where('balance_paisa', '>', 0);
    }

    /**
     * "Aslam (0300 1234567)" — two Aslams on one street is the normal case.
     */
    public function displayName(): string
    {
        return $this->phone ? "{$this->name} ({$this->phone})" : $this->name;
    }

    public function hasCreditLimit(): bool
    {
        return $this->credit_limit_paisa > 0;
    }
}
