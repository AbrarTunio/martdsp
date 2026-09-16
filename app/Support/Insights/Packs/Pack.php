<?php

namespace App\Support\Insights\Packs;

use App\Models\Setting;
use App\Support\Insights\InsightScope;
use App\Support\Insights\MetricPack;
use App\Support\Money;

/**
 * The figures and checks for one area of the shop.
 *
 * Each pack reads what it needs straight from the database and returns the
 * same shape, so the insight panel, the AI prompt and the tests treat them
 * all alike.
 */
abstract class Pack
{
    abstract public function build(InsightScope $scope): MetricPack;

    protected static function money(int $paisa): string
    {
        return Money::rounded($paisa);
    }

    /**
     * "Surf, Ariel and Tapal" or "Surf, Ariel, Tapal and 4 more".
     *
     * @param  iterable<string>  $names
     */
    protected static function names(iterable $names, int $show = 3): string
    {
        $names = array_values(is_array($names) ? $names : iterator_to_array($names, false));
        $shown = array_slice($names, 0, $show);
        $rest = count($names) - count($shown);

        if ($rest > 0) {
            return implode(', ', $shown).' '.__('and :count more', ['count' => $rest]);
        }

        if (count($shown) <= 1) {
            return (string) ($shown[0] ?? '');
        }

        return implode(', ', array_slice($shown, 0, -1)).' '.__('and').' '.end($shown);
    }

    protected static function percent(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1).'%';
    }

    /**
     * How much bigger (or smaller) now is than before, as a percentage.
     */
    protected static function change(int|float $now, int|float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }

    protected static function threshold(string $key): mixed
    {
        return Setting::read('insights.'.$key);
    }
}
