<?php

namespace App\Models;

use App\Enums\UnitType;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A unit name on its own carries no size. "Box" means 24 sachets only in the
 * context of one product, which is what ProductUnit records.
 */
#[Fillable(['name', 'short_name', 'type'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => UnitType::class,
        ];
    }

    public function productUnits(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }
}
