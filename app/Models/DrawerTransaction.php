<?php

namespace App\Models;

use App\Enums\DrawerEntryType;
use Database\Factories\DrawerTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One movement of cash in or out of a drawer. Positive is in, negative out.
 * Append-only.
 */
#[Fillable([
    'drawer_session_id', 'type', 'amount_paisa', 'reference_type', 'reference_id', 'user_id', 'note',
])]
class DrawerTransaction extends Model
{
    /** @use HasFactory<DrawerTransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => DrawerEntryType::class,
            'amount_paisa' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(DrawerSession::class, 'drawer_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
