<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many of one note or coin were in the drawer when it was counted.
 */
#[Fillable(['drawer_session_id', 'denomination', 'count', 'subtotal_paisa'])]
class CashCountLine extends Model
{
    protected function casts(): array
    {
        return [
            'denomination' => 'integer',
            'count' => 'integer',
            'subtotal_paisa' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(DrawerSession::class, 'drawer_session_id');
    }
}
