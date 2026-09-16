<?php

namespace App\Support\Reports;

use App\Models\Product;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the goods on the shelves are worth right now.
 *
 * At cost is the money already spent on them; at selling prices is what
 * they would bring in if every piece sold. The gap is profit still waiting
 * on the shelf. A product showing less than nothing in stock is listed in
 * red — somebody sold what was never booked in, and it needs counting.
 */
final class StockValuationReport extends Report
{
    /**
     * The doughnut shows this many categories and folds the rest together.
     */
    private const CHART_SLICES = 7;

    public function title(): string
    {
        return __('Stock value');
    }

    public function description(): string
    {
        return __('What the stock is worth at cost and at selling prices, and where the money sits.');
    }

    public function icon(): string
    {
        return 'box';
    }

    public function usesPeriod(): bool
    {
        return false;
    }

    public function filters(): array
    {
        return [
            'group' => [
                'label' => __('Group by'),
                'options' => ['category' => __('Each category'), 'product' => __('Each product')],
            ],
        ];
    }

    public function build(Period $period, array $filters): ReportResult
    {
        $products = Product::query()
            ->where('stock_qty_base', '!=', 0)
            ->with(['category:id,name', 'baseUnit:id,short_name', 'productUnits:id,product_id,conversion_factor,sale_price_paisa,is_base'])
            ->orderBy('name')
            ->get();

        $values = $products->mapWithKeys(fn (Product $product): array => [$product->id => ShelfValue::of($product)]);

        $cost = $values->sum('cost');
        $retail = $values->sum('retail');
        $belowZero = $products->filter(fn (Product $product): bool => $product->stock_qty_base < 0)->count();
        $categories = $this->byCategory($products, $values->all(), $cost);

        $rows = $filters['group'] === 'product'
            ? $this->byProduct($products, $values->all(), $cost)
            : $categories;

        return new ReportResult(
            columns: [
                Column::text('name', $filters['group'] === 'product' ? __('Product') : __('Category')),
                $filters['group'] === 'product' ? Column::quantity('qty', __('In stock')) : Column::number('products', __('Products')),
                Column::money('cost_paisa', __('At cost'), emphasis: true),
                Column::money('retail_paisa', __('At selling price')),
                Column::money('profit_paisa', __('Profit waiting')),
                Column::percent('margin', __('Margin')),
                Column::percent('share', __('Share of stock')),
            ],
            rows: $rows,
            totals: [
                'products' => $filters['group'] === 'product' ? null : $products->count(),
                'cost_paisa' => $cost,
                'retail_paisa' => $retail,
                'profit_paisa' => $retail - $cost,
                'margin' => ProfitReport::margin($retail - $cost, $retail),
            ],
            stats: [
                [
                    'label' => __('Stock at cost'),
                    'value' => Money::rounded($cost),
                    'icon' => 'box',
                    'hint' => __(':count products in stock', ['count' => number_format($products->count() - $belowZero)]),
                ],
                [
                    'label' => __('At selling prices'),
                    'value' => Money::rounded($retail),
                    'icon' => 'wallet',
                ],
                [
                    'label' => __('Profit waiting on the shelf'),
                    'value' => Money::rounded($retail - $cost),
                    'icon' => 'trend-up',
                    'hint' => $retail > 0 ? __(':margin% of the selling price', ['margin' => number_format(ProfitReport::margin($retail - $cost, $retail), 1)]) : null,
                ],
                [
                    'label' => __('Below zero'),
                    'value' => number_format($belowZero),
                    'icon' => 'alert',
                    'hint' => $belowZero > 0 ? __('Sold more than was booked in — count these') : __('No stock below zero'),
                    'tone' => $belowZero > 0 ? 'danger' : 'success',
                ],
            ],
            chart: $cost > 0 ? $this->chart($categories) : null,
            footnote: __('At cost uses the average cost of each product. At selling price uses the smallest unit\'s price, or the cheapest pack per piece where the smallest unit has none. Stock below zero is counted as nothing. Quantities are in each product\'s smallest unit.'),
            emptyMessage: __('There is no stock on the books yet. Add opening stock or receive a purchase first.'),
        );
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  array<int, array{cost: int, retail: int}>  $values
     * @return list<array<string, mixed>>
     */
    private function byProduct(Collection $products, array $values, int $totalCost): array
    {
        $rows = $products
            ->map(function (Product $product) use ($values, $totalCost): array {
                $value = $values[$product->id];
                $negative = $product->stock_qty_base < 0;

                return $this->line($value['cost'], $value['retail'], $totalCost) + [
                    'name' => $product->name,
                    'qty' => $product->stock_qty_base,
                    '_detail' => $negative
                        ? __('Below zero — count it')
                        : trim(($product->category?->name ?? '').' · '.($product->baseUnit?->short_name ?? ''), ' ·'),
                    '_link' => route('products.edit', $product),
                    '_tone' => $negative ? 'danger' : null,
                ];
            })
            ->all();

        usort($rows, fn (array $a, array $b): int => ($b['_tone'] === 'danger') <=> ($a['_tone'] === 'danger')
            ?: $b['cost_paisa'] <=> $a['cost_paisa']
            ?: strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  array<int, array{cost: int, retail: int}>  $values
     * @return list<array<string, mixed>>
     */
    private function byCategory(Collection $products, array $values, int $totalCost): array
    {
        $rows = $products
            ->groupBy(fn (Product $product): int => (int) $product->category_id)
            ->map(function (Collection $group) use ($values, $totalCost): array {
                $cost = $group->sum(fn (Product $product): int => $values[$product->id]['cost']);
                $retail = $group->sum(fn (Product $product): int => $values[$product->id]['retail']);
                $belowZero = $group->filter(fn (Product $product): bool => $product->stock_qty_base < 0)->count();

                return $this->line($cost, $retail, $totalCost) + [
                    'name' => $group->first()->category?->name ?? __('No category'),
                    'products' => $group->count(),
                    '_detail' => $belowZero > 0
                        ? trans_choice('{1} One product below zero|[2,*] :count products below zero', $belowZero, ['count' => $belowZero])
                        : null,
                    '_tone' => $belowZero > 0 ? 'warning' : null,
                ];
            })
            ->values()
            ->all();

        usort($rows, fn (array $a, array $b): int => $b['cost_paisa'] <=> $a['cost_paisa'] ?: strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function line(int $cost, int $retail, int $totalCost): array
    {
        return [
            'cost_paisa' => $cost,
            'retail_paisa' => $retail,
            'profit_paisa' => $retail - $cost,
            'margin' => ProfitReport::margin($retail - $cost, $retail),
            'share' => $totalCost > 0 ? round($cost / $totalCost * 100, 1) : null,
        ];
    }

    /**
     * Where the money sits, biggest categories first and the tail folded into one.
     *
     * @param  list<array<string, mixed>>  $categories
     * @return array<string, mixed>
     */
    private function chart(array $categories): array
    {
        $slices = array_values(array_filter($categories, fn (array $row): bool => $row['cost_paisa'] > 0));
        $shown = array_slice($slices, 0, self::CHART_SLICES);
        $rest = array_sum(array_column(array_slice($slices, self::CHART_SLICES), 'cost_paisa'));

        $labels = array_column($shown, 'name');
        $data = array_column($shown, 'cost_paisa');

        if ($rest > 0) {
            $labels[] = __('Everything else');
            $data[] = $rest;
        }

        return [
            'type' => 'doughnut',
            'money' => true,
            'labels' => $labels,
            'datasets' => [['label' => __('At cost'), 'data' => $data]],
        ];
    }
}
