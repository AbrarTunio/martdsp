<?php

namespace App\Models;

use Database\Factories\BarcodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A code that identifies one packaging level of one product.
 *
 * Codes are unique across the whole table: a scan at the till has to resolve
 * to a single answer with no follow-up question.
 */
#[Fillable(['product_unit_id', 'code', 'is_primary'])]
class Barcode extends Model
{
    /** @use HasFactory<BarcodeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }
}
