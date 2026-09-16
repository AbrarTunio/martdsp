<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Support\Packaging;
use Illuminate\Support\Facades\DB;

/**
 * Saves a product's packaging.
 *
 * The shopkeeper types sizes relative to each other ("1 carton = 12 boxes").
 * This turns that into the flattened conversion factors the till reads, and
 * keeps the one-base-unit and one-default rules true no matter what the form
 * sends.
 */
class PackagingService
{
    /**
     * Replace a product's packaging levels with the given set.
     *
     * Each level is:
     * ['unit_id' => int, 'parent_unit_id' => ?int, 'qty_per_parent' => int,
     *  'sale_price_paisa' => int, 'mrp_paisa' => ?int, 'is_default_sale' => bool,
     *  'is_default_purchase' => bool, 'barcodes' => array<int, string>]
     *
     * @param  array<int, array<string, mixed>>  $levels
     */
    public function sync(Product $product, array $levels): void
    {
        $levels = $this->withBaseLevel($product, $levels);
        $factors = Packaging::factors($this->chainOf($levels));

        DB::transaction(function () use ($product, $levels, $factors): void {
            $keptUnitIds = [];

            foreach ($levels as $level) {
                $unitId = (int) $level['unit_id'];
                $isBase = $unitId === (int) $product->base_unit_id;

                $productUnit = $product->productUnits()->updateOrCreate(
                    ['unit_id' => $unitId],
                    [
                        'parent_unit_id' => $isBase ? null : $level['parent_unit_id'],
                        'qty_per_parent' => $isBase ? 1 : max(1, (int) $level['qty_per_parent']),
                        'conversion_factor' => $factors[$unitId],
                        'sale_price_paisa' => (int) ($level['sale_price_paisa'] ?? 0),
                        'mrp_paisa' => $level['mrp_paisa'] ?? null,
                        'is_base' => $isBase,
                        'is_default_sale' => (bool) ($level['is_default_sale'] ?? false),
                        'is_default_purchase' => (bool) ($level['is_default_purchase'] ?? false),
                    ],
                );

                $this->syncBarcodes($productUnit, $level['barcodes'] ?? []);
                $keptUnitIds[] = $unitId;
            }

            $product->productUnits()->whereNotIn('unit_id', $keptUnitIds)->delete();

            $product->load('productUnits');

            $this->ensureOneDefault($product, 'is_default_sale');
            $this->ensureOneDefault($product, 'is_default_purchase');
        });

        $product->load('productUnits.unit', 'productUnits.barcodes');
    }

    /**
     * The base unit is always a packaging level, whether or not the form sent
     * it. Without it nothing else has anything to be measured against.
     *
     * @param  array<int, array<string, mixed>>  $levels
     * @return array<int, array<string, mixed>>
     */
    private function withBaseLevel(Product $product, array $levels): array
    {
        $baseUnitId = (int) $product->base_unit_id;

        foreach ($levels as $level) {
            if ((int) $level['unit_id'] === $baseUnitId) {
                return $levels;
            }
        }

        array_unshift($levels, [
            'unit_id' => $baseUnitId,
            'parent_unit_id' => null,
            'qty_per_parent' => 1,
            'sale_price_paisa' => 0,
            'mrp_paisa' => null,
            'is_default_sale' => false,
            'is_default_purchase' => false,
            'barcodes' => [],
        ]);

        return $levels;
    }

    /**
     * @param  array<int, array<string, mixed>>  $levels
     * @return array<int, array{unit_id: int, parent_unit_id: int|null, qty_per_parent: int}>
     */
    private function chainOf(array $levels): array
    {
        return array_map(fn (array $level): array => [
            'unit_id' => (int) $level['unit_id'],
            'parent_unit_id' => $level['parent_unit_id'] === null ? null : (int) $level['parent_unit_id'],
            'qty_per_parent' => max(1, (int) ($level['qty_per_parent'] ?? 1)),
        ], $levels);
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function syncBarcodes(ProductUnit $productUnit, array $codes): void
    {
        $codes = collect($codes)
            ->map(fn ($code): string => trim((string) $code))
            ->filter()
            ->unique()
            ->values();

        $productUnit->barcodes()->whereNotIn('code', $codes)->delete();

        foreach ($codes as $index => $code) {
            $productUnit->barcodes()->updateOrCreate(
                ['code' => $code],
                ['is_primary' => $index === 0],
            );
        }
    }

    /**
     * Exactly one level carries each default. A form that ticked none, or
     * ticked several, still has to leave the product usable at the till.
     */
    private function ensureOneDefault(Product $product, string $column): void
    {
        $units = $product->productUnits;

        $chosen = $units->firstWhere($column, true)
            ?? ($column === 'is_default_sale'
                ? $units->firstWhere('is_base', true)
                : $units->sortByDesc('conversion_factor')->first());

        if (! $chosen) {
            return;
        }

        $product->productUnits()
            ->whereKeyNot($chosen->getKey())
            ->update([$column => false]);

        $chosen->forceFill([$column => true])->save();
    }
}
