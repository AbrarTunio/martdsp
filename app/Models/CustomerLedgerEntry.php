<?php

namespace App\Models;

use App\Enums\CustomerEntryType;
use App\Enums\TenderType;
use Database\Factories\CustomerLedgerEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line in a customer's khata.
 *
 * A debit means the customer owes more; a credit means they owe less.
 * Append-only — a wrong line is answered with a correction, never edited.
 */
#[Fillable([
    'customer_id', 'type', 'method', 'debit_paisa', 'credit_paisa', 'balance_after_paisa',
    'reference_type', 'reference_id', 'entry_date', 'due_date', 'user_id', 'note',
])]
class CustomerLedgerEntry extends Model
{
    /** @use HasFactory<CustomerLedgerEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => CustomerEntryType::class,
            'method' => TenderType::class,
            'debit_paisa' => 'integer',
            'credit_paisa' => 'integer',
            'balance_after_paisa' => 'integer',
            'entry_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The sale or payment this line came from.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

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
     * The signed effect on what is owed: positive means the customer owes more.
     */
    public function changePaisa(): int
    {
        return $this->debit_paisa - $this->credit_paisa;
    }
}
