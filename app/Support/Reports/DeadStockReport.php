<?php

namespace App\Support\Reports;

use App\Enums\SaleStatus;
use App\Models\Product;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Goods that sit on the shelf and do not sell.
 *
 * Every rupee here was paid out and is not coming back until a customer
 * picks the item up. A product counts as idle when it has stock and nothing
 * of it has been sold for the chosen number of days — counted from the day
 * it was added when it has never sold at all, so a new line is given a fair
 * chance. The dearest idle stock comes first, because that is where a
 * discount or a return to the supplier frees up the most money.
 */
final class DeadStockReport extends Report
{
    public function title(): string
    {
        return __('Dead stock');
    }

    public function description(): string
    {
        return __('Products with stock that have not sold in weeks, and how much money is stuck in them.');
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function usesPeriod(): bool
    {
        return false;
    }

    public function filters(): array
    {
        return [
            'days' => [
                'label' => __('Not sold for'),
                'options' => [
                    '30' => __('30 days or more'),
                    '60' => __('60 days or more'),
                    '90' => __('90 days or more'),
                    '180' => __('6 months or more'),
                ],
                'default' => '60',
            ],
        ];
    }

    public function build(Period $period, array $filters): ReportResult
    {
        $days = (int) $filters['days'];
        $today = CarbonImmutable::today();
        $cutoff = $today->subDays($days);

        $lastSold = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', SaleStatus::Completed->value)
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id as product_id, MAX(sales.sold_at) as last_sold')
            ->pluck('last_sold', 'product_id');

        $stocked = Product::query()
            ->where('stock_qty_base', '>', 0)
            ->with(['category:id,name', 'productUnits:id,product_id,conversion_factor,sale_price_paisa,is_base'])
            ->get();

        $allCost = $stocked->sum(fn (Product $product): int => ShelfValue::of($product)['cost']);

        $rows = [];

        foreach ($stocked as $product) {
            $sold = $lastSold->get($product->id);
            $sold = $sold === null ? null : CarbonImmutable::parse($sold);
            $since = ($sold ?? CarbonImmutable::parse($product->created_at))->startOfDay();

            if ($since->gt($cutoff)) {
                continue;
            }

            $value = ShelfValue::of($product);

            $rows[] = [
                'name' => $product->name,
                'qty' => $product->stock_qty_base,
                'last_sold' => $sold,
                'idle' => (int) $since->diffInDays($today),
                'cost_paisa' => $value['cost'],
                'retail_paisa' => $value['retail'],
                '_detail' => $sold === null
                    ? trim(__('Never sold').' · '.($product->category?->name ?? ''), ' ·')
                    : $product->category?->name,
                '_link' => route('products.edit', $product),
                '_tone' => $sold === null ? 'danger' : 'warning',
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['cost_paisa'] <=> $a['cost_paisa']
            ?: $b['idle'] <=> $a['idle']
            ?: strcmp($a['name'], $b['name']));

        $stuck = array_sum(array_column($rows, 'cost_paisa'));
        $never = count(array_filter($rows, fn (array $row): bool => $row['last_sold'] === null));
        $top = array_slice($rows, 0, 10);

        return new ReportResult(
            columns: [
                Column::text('name', __('Product')),
                Column::quantity('qty', __('In stock')),
                Column::date('last_sold', __('Last sold')),
                Column::number('idle', __('Days idle')),
                Column::money('cost_paisa', __('Money stuck'), emphasis: true),
                Column::money('retail_paisa', __('At selling price')),
            ],
            rows: $rows,
            totals: [
                'cost_paisa' => $stuck,
                'retail_paisa' => array_sum(array_column($rows, 'retail_paisa')),
            ],
            stats: [
                [
                    'label' => __('Idle products'),
                    'value' => number_format(count($rows)),
                    'icon' => 'clock',
                    'hint' => __('Nothing sold in :days days', ['days' => $days]),
                    'tone' => $rows === [] ? 'success' : 'warning',
                ],
                [
                    'label' => __('Money stuck'),
                    'value' => Money::rounded($stuck),
                    'icon' => 'wallet',
                    'hint' => __('At what it cost to buy'),
                ],
                [
                    'label' => __('Share of all stock'),
                    'value' => $allCost > 0 ? number_format($stuck / $allCost * 100, 1).'%' : '—',
                    'icon' => 'box',
                    'hint' => __('of the money on the shelves'),
                ],
                [
                    'label' => __('Never sold'),
                    'value' => number_format($never),
                    'icon' => 'alert',
                    'hint' => $never > 0 ? __('Stocked but not a single sale') : __('Everything here has sold before'),
                    'tone' => $never > 0 ? 'danger' : 'neutral',
                ],
            ],
            chart: $top === [] ? null : [
                'type' => 'bar',
                'horizontal' => true,
                'money' => true,
                'labels' => array_column($top, 'name'),
                'datasets' => [
                    ['label' => __('Money stuck'), 'data' => array_column($top, 'cost_paisa'), 'color' => 'amber'],
                ],
            ],
            footnote: __('A product is idle when it has stock and no bill has sold any of it in the chosen days, counted from the day it was added if it has never sold. Money stuck is at cost. Try a discount or a bundle, return it to the supplier if you can, and do not reorder it until it moves.'),
            emptyMessage: __('Every product with stock has sold in the last :days days. Nothing is gathering dust.', ['days' => $days]),
        );
    }
}
