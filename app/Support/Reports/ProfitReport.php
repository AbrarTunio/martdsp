<?php

namespace App\Support\Reports;

use App\Enums\SaleStatus;
use App\Models\Category;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * What each product or category actually earned once its cost is paid for.
 *
 * Sales are counted before GST — the tax belongs to the government, not the
 * shop — and after every discount. Cost is what each item cost on the day it
 * was sold, so a later price rise never rewrites an old month. Returns come
 * off on the day they came back: goods that went back on the shelf take
 * their cost back with them, but damaged or expired goods do not, because
 * that loss is real.
 */
final class ProfitReport extends Report
{
    /**
     * Below this margin a line is flagged: it sells, but hardly pays.
     */
    private const THIN_MARGIN = 5.0;

    public function title(): string
    {
        return __('Profit and margin');
    }

    public function description(): string
    {
        return __('What each product or category earned after its cost, and which ones sell at a loss.');
    }

    public function icon(): string
    {
        return 'trend-up';
    }

    public function filters(): array
    {
        return [
            'group' => [
                'label' => __('Group by'),
                'options' => ['product' => __('Each product'), 'category' => __('Each category')],
            ],
            'sort' => [
                'label' => __('Order'),
                'options' => [
                    'profit' => __('Most profit first'),
                    'loss' => __('Least profit first'),
                    'sales' => __('Most sales first'),
                    'margin' => __('Thinnest margin first'),
                ],
            ],
        ];
    }

    public function build(Period $period, array $filters): ReportResult
    {
        $byCategory = $filters['group'] === 'category';

        $products = $this->products($period);
        $lines = $byCategory ? $this->byCategory($products) : $this->byProduct($products);
        $lines = $this->sorted($lines, $filters['sort']);

        $total = $this->total($products);
        $before = $this->total($this->products($period->previous()));
        $against = $period->previous()->label();

        $margin = self::margin($total['profit'], $total['sales']);
        $marginBefore = self::margin($before['profit'], $before['sales']);
        $losing = count(array_filter($lines, fn (array $line): bool => $line['profit_paisa'] < 0));

        $top = array_slice($this->sorted($lines, 'profit'), 0, 10);

        return new ReportResult(
            columns: [
                Column::text('name', $byCategory ? __('Category') : __('Product')),
                $byCategory ? Column::number('products', __('Products')) : Column::quantity('qty', __('Qty sold')),
                Column::money('sales_paisa', __('Sales before GST')),
                Column::money('cost_paisa', __('Cost')),
                Column::money('profit_paisa', __('Profit'), emphasis: true),
                Column::percent('margin', __('Margin')),
                Column::percent('share', __('Share of profit')),
            ],
            rows: $lines,
            totals: [
                'sales_paisa' => $total['sales'],
                'cost_paisa' => $total['cost'],
                'profit_paisa' => $total['profit'],
                'margin' => $margin,
                'products' => $byCategory ? count($products) : null,
            ],
            stats: [
                [
                    'label' => __('Sales before GST'),
                    'value' => Money::rounded($total['sales']),
                    'icon' => 'wallet',
                    'hint' => Trend::describe($total['sales'], $before['sales'], $against),
                    'tone' => Trend::tone($total['sales'], $before['sales']),
                ],
                [
                    'label' => __('Profit'),
                    'value' => Money::rounded($total['profit']),
                    'icon' => 'trend-up',
                    'hint' => Trend::describe($total['profit'], $before['profit'], $against),
                    'tone' => Trend::tone($total['profit'], $before['profit']),
                ],
                [
                    'label' => __('Margin'),
                    'value' => $margin === null ? '—' : number_format($margin, 1).'%',
                    'icon' => 'chart',
                    'hint' => $marginBefore === null
                        ? __('Nothing to compare with in :against', ['against' => $against])
                        : __('Was :margin% in :against', ['margin' => number_format($marginBefore, 1), 'against' => $against]),
                ],
                [
                    'label' => $byCategory ? __('Categories at a loss') : __('Sold at a loss'),
                    'value' => number_format($losing),
                    'icon' => 'alert',
                    'hint' => $losing > 0 ? __('Earned less than they cost — check their prices') : __('Everything earned more than it cost'),
                    'tone' => $losing > 0 ? 'danger' : 'success',
                ],
            ],
            chart: $top === [] ? null : [
                'type' => 'bar',
                'horizontal' => true,
                'money' => true,
                'labels' => array_column($top, 'name'),
                'datasets' => [
                    ['label' => __('Profit'), 'data' => array_column($top, 'profit_paisa'), 'color' => 'brand'],
                    ['label' => __('Cost'), 'data' => array_column($top, 'cost_paisa'), 'color' => 'gray'],
                ],
            ],
            footnote: __('Sales are before GST and after every discount. Cost is what the goods cost on the day they were sold. Returns come off the day they came back; goods put back on the shelf take their cost with them, damaged or expired goods do not. Quantities are in each product\'s smallest unit. Rounding on bill totals is left out.'),
            emptyMessage: __('Nothing was sold in these dates.'),
        );
    }

    /**
     * Sales, cost and quantity for every product that moved in the period.
     *
     * @return array<int, array{qty: int, sales: int, cost: int}>
     */
    private function products(Period $period): array
    {
        $products = [];

        $add = function (int $productId, int $qty, int $sales, int $cost) use (&$products): void {
            $products[$productId] ??= ['qty' => 0, 'sales' => 0, 'cost' => 0];
            $products[$productId]['qty'] += $qty;
            $products[$productId]['sales'] += $sales;
            $products[$productId]['cost'] += $cost;
        };

        DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', SaleStatus::Completed->value)
            ->whereBetween('sales.sold_at', $period->range())
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id as product_id, SUM(sale_items.qty_base) as qty, SUM(sale_items.line_total_paisa) as total, SUM(sale_items.tax_paisa) as tax, SUM(sale_items.qty_base * sale_items.cost_at_sale_base_paisa) as cost')
            ->get()
            ->each(fn (object $row) => $add((int) $row->product_id, (int) $row->qty, (int) $row->total - (int) $row->tax, (int) $row->cost));

        DB::table('sale_return_items')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->whereBetween('sale_returns.returned_at', $period->range())
            ->groupBy('sale_return_items.product_id', 'sale_returns.restocked')
            ->selectRaw('sale_return_items.product_id as product_id, sale_returns.restocked as restocked, SUM(sale_return_items.qty_base) as qty, SUM(sale_return_items.line_total_paisa) as total, SUM(sale_return_items.tax_paisa) as tax, SUM(sale_return_items.qty_base * sale_return_items.cost_base_paisa) as cost')
            ->get()
            ->each(fn (object $row) => $add(
                (int) $row->product_id,
                -(int) $row->qty,
                -((int) $row->total - (int) $row->tax),
                (bool) $row->restocked ? -(int) $row->cost : 0,
            ));

        return $products;
    }

    /**
     * @param  array<int, array{qty: int, sales: int, cost: int}>  $products
     * @return list<array<string, mixed>>
     */
    private function byProduct(array $products): array
    {
        $details = Product::query()
            ->whereKey(array_keys($products))
            ->with('category:id,name')
            ->get(['id', 'name', 'category_id'])
            ->keyBy('id');

        $total = $this->total($products)['profit'];
        $lines = [];

        foreach ($products as $productId => $figures) {
            $product = $details->get($productId);

            $lines[] = $this->line($figures, $total) + [
                'name' => $product->name ?? __('Deleted product'),
                'qty' => $figures['qty'],
                '_detail' => $product?->category?->name,
                '_link' => $product ? route('products.edit', $product) : null,
            ];
        }

        return $lines;
    }

    /**
     * @param  array<int, array{qty: int, sales: int, cost: int}>  $products
     * @return list<array<string, mixed>>
     */
    private function byCategory(array $products): array
    {
        $categoryOf = Product::query()
            ->whereKey(array_keys($products))
            ->pluck('category_id', 'id');

        $names = Category::query()
            ->whereKey($categoryOf->filter()->unique()->values())
            ->pluck('name', 'id');

        $groups = [];

        foreach ($products as $productId => $figures) {
            $key = (int) ($categoryOf->get($productId) ?? 0);

            $groups[$key] ??= ['sales' => 0, 'cost' => 0, 'products' => 0];
            $groups[$key]['sales'] += $figures['sales'];
            $groups[$key]['cost'] += $figures['cost'];
            $groups[$key]['products']++;
        }

        $total = $this->total($products)['profit'];
        $lines = [];

        foreach ($groups as $categoryId => $figures) {
            $lines[] = $this->line($figures, $total) + [
                'name' => $names->get($categoryId) ?? __('No category'),
                'products' => $figures['products'],
            ];
        }

        return $lines;
    }

    /**
     * @param  array{sales: int, cost: int}  $figures
     * @return array<string, mixed>
     */
    private function line(array $figures, int $totalProfit): array
    {
        $profit = $figures['sales'] - $figures['cost'];
        $margin = self::margin($profit, $figures['sales']);

        return [
            'sales_paisa' => $figures['sales'],
            'cost_paisa' => $figures['cost'],
            'profit_paisa' => $profit,
            'margin' => $margin,
            'share' => $totalProfit > 0 && $profit > 0 ? round($profit / $totalProfit * 100, 1) : null,
            '_tone' => match (true) {
                $profit < 0 => 'danger',
                $margin !== null && $margin < self::THIN_MARGIN => 'warning',
                default => null,
            },
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function sorted(array $lines, string $sort): array
    {
        usort($lines, fn (array $a, array $b): int => match ($sort) {
            'loss' => $a['profit_paisa'] <=> $b['profit_paisa'],
            'sales' => $b['sales_paisa'] <=> $a['sales_paisa'],
            'margin' => ($a['margin'] ?? PHP_FLOAT_MAX) <=> ($b['margin'] ?? PHP_FLOAT_MAX),
            default => $b['profit_paisa'] <=> $a['profit_paisa'],
        } ?: strcmp((string) $a['name'], (string) $b['name']));

        return $lines;
    }

    /**
     * @param  array<int, array{qty: int, sales: int, cost: int}>  $products
     * @return array{sales: int, cost: int, profit: int}
     */
    private function total(array $products): array
    {
        $sales = array_sum(array_column($products, 'sales'));
        $cost = array_sum(array_column($products, 'cost'));

        return ['sales' => $sales, 'cost' => $cost, 'profit' => $sales - $cost];
    }

    /**
     * Sales, cost and profit for the dates without the table — the same sums
     * the report prints, so the dashboard and the report always agree.
     *
     * @return array{sales: int, cost: int, profit: int}
     */
    public function totals(Period $period): array
    {
        return $this->total($this->products($period));
    }

    /**
     * Every product or category that sold in the dates, biggest seller first.
     *
     * @return list<array<string, mixed>>
     */
    public function bestSellers(Period $period, string $group = 'product'): array
    {
        $products = $this->products($period);

        return $this->sorted($group === 'category' ? $this->byCategory($products) : $this->byProduct($products), 'sales');
    }

    /**
     * Quantity sold (in the smallest unit), sales and cost for every product
     * that moved in the dates, keyed by product — for the insight checks.
     *
     * @return array<int, array{qty: int, sales: int, cost: int}>
     */
    public function productFigures(Period $period): array
    {
        return $this->products($period);
    }

    /**
     * Profit as a share of sales, or null when nothing was sold to measure it by.
     */
    public static function margin(int $profit, int $sales): ?float
    {
        return $sales > 0 ? round($profit / $sales * 100, 1) : null;
    }
}
