<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\SupplierEntryType;
use Database\Factories\SupplierLedgerEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line on a supplier's statement.
 *
 * A credit increases what the shop owes; a debit reduces it. Append-only —
 * a wrong line is answered with a correction, never edited.
 */
#[Fillable([
    'supplier_id', 'type', 'method', 'debit_paisa', 'credit_paisa', 'balance_after_paisa',
    'reference_type', 'reference_id', 'entry_date', 'user_id', 'note',
])]
class SupplierLedgerEntry extends Model
{
    /** @use HasFactory<SupplierLedgerEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => SupplierEntryType::class,
            'method' => PaymentMethod::class,
            'debit_paisa' => 'integer',
            'credit_paisa' => 'integer',
            'balance_after_paisa' => 'integer',
            'entry_date' => 'date',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The purchase or return this line came from.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The order the rows were written in, which is the order every balance
     * on them was worked out in — the same rule the stock ledger follows.
     */
    #[Scope]
    protected function inLedgerOrder(Builder $query): void
    {
        $query->orderBy('id');
    }

    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc('id');
    }

    /**
     * The signed effect on what is owed: positive means the shop owes more.
     */
    public function changePaisa(): int
    {
        return $this->credit_paisa - $this->debit_paisa;
    }
}
