<?php

namespace App\Support;

use App\Models\ProductUnit;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The packaging maths.
 *
 * A shopkeeper describes packaging the way it arrives: "1 box = 24 sachets,
 * 1 carton = 12 boxes". The till needs the flattened answer: a carton is 288
 * sachets. These functions convert between the two and render a base-unit
 * balance back into something countable on the shelf.
 *
 * Deliberately free of Eloquent so the arithmetic can be tested on its own.
 */
class Packaging
{
    /**
     * Flatten a chain of packaging levels into base units per level.
     *
     * Each level is ['unit_id' => int, 'parent_unit_id' => ?int, 'qty_per_parent' => int].
     * Exactly one level must be the base, identified by a null parent.
     *
     * @param  array<int, array{unit_id: int, parent_unit_id: int|null, qty_per_parent: int}>  $levels
     * @return array<int, int> unit_id => conversion factor in base units
     *
     * @throws RuntimeException when the chain has no base, or loops back on itself
     */
    public static function factors(array $levels): array
    {
        $byUnit = [];

        foreach ($levels as $level) {
            $byUnit[(int) $level['unit_id']] = $level;
        }

        $factors = [];

        foreach (array_keys($byUnit) as $unitId) {
            $factors[$unitId] = self::resolve($unitId, $byUnit, []);
        }

        return $factors;
    }

    /**
     * @param  array<int, array{unit_id: int, parent_unit_id: int|null, qty_per_parent: int}>  $byUnit
     * @param  array<int, true>  $seen
     */
    private static function resolve(int $unitId, array $byUnit, array $seen): int
    {
        if (isset($seen[$unitId])) {
            throw new RuntimeException('This packaging refers back to itself. Each size must be described in terms of a smaller one.');
        }

        $level = $byUnit[$unitId] ?? throw new RuntimeException('One packaging size refers to a size that is not listed.');

        $parentId = $level['parent_unit_id'] === null ? null : (int) $level['parent_unit_id'];

        if ($parentId === null) {
            return 1;
        }

        $qty = max(1, (int) $level['qty_per_parent']);
        $seen[$unitId] = true;

        return $qty * self::resolve($parentId, $byUnit, $seen);
    }

    /**
     * Split a base-unit quantity across the packaging levels, largest first.
     *
     * 1,413 sachets with sachet/box(24)/carton(288) becomes
     * 4 cartons, 10 boxes, 21 sachets.
     *
     * @param  array<int, array{factor: int, name: string}>  $levels
     * @return array<int, array{qty: int, name: string, factor: int}>
     */
    public static function breakdown(int $qtyBase, array $levels): array
    {
        $levels = collect($levels)
            ->filter(fn (array $level): bool => (int) $level['factor'] > 0)
            ->sortByDesc('factor')
            ->values();

        if ($levels->isEmpty()) {
            return [];
        }

        $remaining = abs($qtyBase);
        $parts = [];

        foreach ($levels as $index => $level) {
            $factor = (int) $level['factor'];
            $isSmallest = $index === $levels->count() - 1;
            $qty = intdiv($remaining, $factor);

            if ($qty > 0 || ($isSmallest && $parts === [])) {
                $parts[] = ['qty' => $qty, 'name' => $level['name'], 'factor' => $factor];
            }

            $remaining -= $qty * $factor;
        }

        return $parts;
    }

    /**
     * The same breakdown as a sentence: "4 cartons, 10 boxes, 21 sachets".
     *
     * @param  Collection<int, ProductUnit>|array<int, array{factor: int, name: string}>  $levels
     */
    public static function describe(int $qtyBase, Collection|array $levels): string
    {
        $levels = $levels instanceof Collection
            ? $levels->map(fn ($productUnit): array => [
                'factor' => (int) $productUnit->conversion_factor,
                'name' => (string) ($productUnit->unit?->name ?? ''),
            ])->all()
            : $levels;

        $parts = self::breakdown($qtyBase, $levels);

        if ($parts === []) {
            return '—';
        }

        $sentence = collect($parts)
            ->map(fn (array $part): string => $part['qty'].' '.Str::lower(Str::plural($part['name'], $part['qty'])))
            ->implode(', ');

        return $qtyBase < 0 ? '−'.$sentence : $sentence;
    }

    /**
     * Base units contained in a quantity of some packaging level.
     */
    public static function toBase(int $qty, int $conversionFactor): int
    {
        return $qty * max(1, $conversionFactor);
    }
}
