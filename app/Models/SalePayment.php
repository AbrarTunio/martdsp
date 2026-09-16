<?php

namespace App\Models;

use App\Enums\TenderType;
use Database\Factories\SalePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tender on a bill: the cash part, the card part, the khata part.
 */
#[Fillable(['sale_id', 'method', 'amount_paisa', 'tendered_paisa', 'reference', 'user_id'])]
class SalePayment extends Model
{
    /** @use HasFactory<SalePaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'method' => TenderType::class,
            'amount_paisa' => 'integer',
            'tendered_paisa' => 'integer',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What went back across the counter from this tender.
     */
    public function changePaisa(): int
    {
        return max(0, $this->tendered_paisa - $this->amount_paisa);
    }
}
