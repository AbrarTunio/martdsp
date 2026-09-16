<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'name_ur', 'parent_id', 'is_active'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    #[Scope]
    protected function topLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * This category and everything filed under it. Counting "Beverages"
     * means counting the juices too.
     *
     * @return array<int, int>
     */
    public function idsWithinIt(): array
    {
        $ids = [$this->id];
        $parents = [$this->id];

        while ($parents !== []) {
            $parents = static::query()->whereIn('parent_id', $parents)->whereNotIn('id', $ids)->pluck('id')->all();
            $ids = [...$ids, ...$parents];
        }

        return $ids;
    }

    /**
     * "Beverages" or "Beverages / Juices", for pickers and reports.
     */
    public function fullName(): string
    {
        return $this->parent ? "{$this->parent->name} / {$this->name}" : $this->name;
    }
}
