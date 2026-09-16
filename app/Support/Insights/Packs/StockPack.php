<?php

namespace App\Support\Insights\Packs;

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentStatus;
use App\Enums\InsightSeverity;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Support\Insights\Finding;
use App\Support\Insights\InsightScope;
use App\Support\Insights\MetricPack;
use App\Support\Reports\DeadStockReport;
use App\Support\Reports\Period;
use App\Support\Reports\ProfitReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The shelves: what is running out, what is not moving, what has expired
 * and where money is quietly leaking.
 *
 * How fast things sell is judged on the last 30 days, whatever dates the
 * page shows, because a shelf is about now.
 */
final class StockPack extends Pack
{
    /**
     * The best sellers watched for running out.
     */
    private const MOVERS = 20;

    /**
     * A shelf holding more than this many days of sales is slow.
     */
    private const SLOW_COVER_DAYS = 120;

    /**
     * A shelf holding fewer than this many days of sales is turning well.
     */
    private const HEALTHY_COVER_DAYS = 45;

    /**
     * Write-offs above this share of what was sold are more than bad luck.
     */
    private const SHRINKAGE_ALERT = 2.0;

    public function __construct(
        private readonly ProfitReport $profit,
        private readonly DeadStockReport $deadStock,
    ) {}

    public function build(InsightScope $scope): MetricPack
    {
        $sold = $this->profit->productFigures(Period::preset('last_30_days'));
        $cogs30 = array_sum(array_column($sold, 'cost'));

        $atCost = (int) Product::query()->where('stock_qty_base', '>', 0)->toBase()
            ->sum(DB::raw('stock_qty_base * avg_cost_base_paisa'));

        $atRetail = (int) DB::table('products')
            ->join('product_units', function ($join): void {
                $join->on('product_units.product_id', '=', 'products.id')->where('product_units.is_base', true);
            })
            ->where('products.stock_qty_base', '>', 0)
            ->sum(DB::raw('products.stock_qty_base * product_units.sale_price_paisa'));

        $cover = $cogs30 > 0 ? (int) round($atCost / ($cogs30 / 30)) : null;

        $counts = [
            'active' => Product::query()->active()->count(),
            'out' => Product::query()->active()->where('stock_qty_base', '<=', 0)->count(),
            'low' => Product::query()->active()->lowStock()->where('stock_qty_base', '>', 0)->count(),
        ];

        $findings = array_values(array_filter([
            $this->belowZero(),
            $this->expired(),
            $this->moversOut($sold),
            $this->sellingBelowCost(),
            $this->lowStock($sold),
            $this->expiring(),
            $this->deadStock(),
            $this->overstock($sold),
            $this->shrinkage(),
            $this->turnover($cover),
        ]));

        $top = $this->bestSellers($sold, 5);

        return new MetricPack(
            page: 'stock',
            title: __('Stock'),
            scopeLabel: __('Stock today; sales speed from the last 30 days'),
            summary: __(':products products on sale. Stock is worth :cost at cost (:retail at selling price). :low are low and :out are out of stock.', [
                'products' => number_format($counts['active']),
                'cost' => self::money($atCost),
                'retail' => self::money($atRetail),
                'low' => number_format($counts['low']),
                'out' => number_format($counts['out']),
            ]),
            facts: [
                'products_on_sale' => $counts['active'],
                'stock_value_at_cost' => self::money($atCost),
                'stock_value_at_selling_price' => self::money($atRetail),
                'cost_of_goods_sold_last_30_days' => self::money($cogs30),
                'days_of_sales_on_the_shelves' => $cover,
                'products_out_of_stock' => $counts['out'],
                'products_low_on_stock' => $counts['low'],
                'best_sellers_last_30_days' => array_map(fn (array $row): array => [
                    'name' => $row['name'],
                    'sales_before_gst' => self::money($row['sales']),
                    'in_stock' => $row['in_stock'],
                ], $top),
            ],
            findings: $findings,
        );
    }

    private function belowZero(): ?Finding
    {
        $names = Product::query()->where('stock_qty_base', '<', 0)->orderBy('stock_qty_base')->limit(5)->pluck('name');
        $count = Product::query()->where('stock_qty_base', '<', 0)->count();

        if ($count === 0) {
            return null;
        }

        return new Finding(
            key: 'below_zero',
            severity: InsightSeverity::High,
            title: trans_choice('{1} One product shows less than nothing in stock|[2,*] :count products show less than nothing in stock', $count, ['count' => $count]),
            detail: __(':names sold more than the system thinks came in. A delivery was not entered, the wrong item was scanned at the till, or the last count was off. Until it is fixed, stock value and profit for these are wrong.', ['names' => self::names($names)]),
            action: __('Count them on the shelf and correct them with a stock adjustment, then check the last delivery of each was entered.'),
            href: route('stock.index', ['view' => 'negative']),
            examples: $names->all(),
        );
    }

    private function expired(): ?Finding
    {
        $batches = StockBatch::query()->open()->expired()->with('product:id,name')->get();

        if ($batches->isEmpty()) {
            return null;
        }

        $value = (int) $batches->sum(fn (StockBatch $batch): int => $batch->qty_base * $batch->cost_base_paisa);
        $names = $batches->pluck('product.name')->filter()->unique()->values();

        return new Finding(
            key: 'expired',
            severity: InsightSeverity::High,
            title: __('Expired goods still on the shelf'),
            detail: __(':names are past their expiry date and still counted as stock. Selling them risks a customer\'s health and the shop\'s name.', ['names' => self::names($names)]),
            action: __('Take them off the shelf today. Ask the supplier whether they take expired goods back; otherwise write them off with a stock adjustment.'),
            impactPaisa: $value,
            href: route('stock.expiry', ['view' => 'expired']),
            examples: $names->take(5)->all(),
        );
    }

    /**
     * @param  array<int, array{qty: int, sales: int, cost: int}>  $sold
     */
    private function moversOut(array $sold): ?Finding
    {
        $movers = $this->bestSellers($sold, self::MOVERS);
        $out = array_values(array_filter($movers, fn (array $row): bool => $row['stock'] <= 0 && $row['active']));

        if ($out === []) {
            return null;
        }

        $perDay = (int) round(array_sum(array_column($out, 'sales')) / 30);
        $names = array_column($out, 'name');

        return new Finding(
            key: 'movers_out',
            severity: InsightSeverity::High,
            title: __('Best sellers have run out'),
            detail: __(':names are among your :top best sellers and none are left. Together they bring in about :amount a day, and a customer who cannot find them may go elsewhere for the whole basket.', [
                'names' => self::names($names),
                'top' => self::MOVERS,
                'amount' => self::money($perDay),
            ]),
            action: __('Order these first. Raise their reorder level so the list warns you earlier next time.'),
            impactPaisa: $perDay,
            href: route('purchases.suggestions.index'),
            examples: array_slice($names, 0, 5),
        );
    }

    private function sellingBelowCost(): ?Finding
    {
        $lines = DB::table('product_units')
            ->join('products', 'products.id', '=', 'product_units.product_id')
            ->join('units', 'units.id', '=', 'product_units.unit_id')
            ->where('products.is_active', true)
            ->where('products.avg_cost_base_paisa', '>', 0)
            ->where('product_units.sale_price_paisa', '>', 0)
            ->whereRaw('product_units.sale_price_paisa < product_units.conversion_factor * products.avg_cost_base_paisa')
            ->orderByRaw('(product_units.conversion_factor * products.avg_cost_base_paisa) - product_units.sale_price_paisa DESC')
            ->get(['products.id', 'products.name', 'units.name as unit']);

        if ($lines->isEmpty()) {
            return null;
        }

        $names = $lines->map(fn (object $line): string => $line->name.' ('.$line->unit.')')->values();
        $products = $lines->pluck('id')->unique()->count();

        return new Finding(
            key: 'negative_margin',
            severity: InsightSeverity::High,
            title: trans_choice('{1} One product is priced below what it costs|[2,*] :count products are priced below what they cost', $products, ['count' => $products]),
            detail: __(':names sell for less than the shop paid for them, so every sale loses money. It usually happens when a supplier raised the price and the shelf price was not changed.', ['names' => self::names($names)]),
            action: __('Open each product and raise its selling price, or check that the last delivery\'s cost was entered correctly.'),
            href: route('products.index'),
            examples: $names->take(5)->all(),
        );
    }

    /**
     * @param  array<int, array{qty: int, sales: int, cost: int}>  $sold
     */
    private function lowStock(array $sold): ?Finding
    {
        $low = Product::query()->active()->lowStock()->where('stock_qty_base', '>', 0)->get(['id', 'name']);

        if ($low->isEmpty()) {
            return null;
        }

        $names = $low->sortByDesc(fn (Product $product): int => $sold[$product->id]['sales'] ?? 0)->pluck('name')->values();

        return new Finding(
            key: 'low_stock',
            severity: InsightSeverity::Medium,
            title: trans_choice('{1} One product is low|[2,*] :count products are low', $low->count(), ['count' => $low->count()]),
            detail: __(':names are at or below their reorder level.', ['names' => self::names($names)]),
            action: __('Start an order from the reorder list; it works out how much of each to buy.'),
            href: route('purchases.suggestions.index'),
            examples: $names->take(5)->all(),
        );
    }

    private function expiring(): ?Finding
    {
        $batches = StockBatch::query()->open()->stillSellable()->expiringWithin(30)->with('product:id,name')->get();

        if ($batches->isEmpty()) {
            return null;
        }

        $value = (int) $batches->sum(fn (StockBatch $batch): int => $batch->qty_base * $batch->cost_base_paisa);
        $names = $batches->sortBy('expiry_date')->pluck('product.name')->filter()->unique()->values();

        return new Finding(
            key: 'expiring',
            severity: InsightSeverity::Medium,
            title: __('Goods expiring within 30 days'),
            detail: __(':names reach their expiry date within a month.', ['names' => self::names($names)]),
            action: __('Move them to the front of the shelf, put a small discount on them, or ask the supplier to swap them for fresh stock.'),
            impactPaisa: $value,
            href: route('stock.expiry', ['view' => '30']),
            examples: $names->take(5)->all(),
        );
    }

    private function deadStock(): ?Finding
    {
        $days = (int) self::threshold('dead_stock_days');
        $rows = $this->deadStock->build(Period::preset('today'), ['days' => (string) $days])->rows;

        if ($rows === []) {
            return null;
        }

        $stuck = (int) array_sum(array_column($rows, 'cost_paisa'));
        $names = array_column($rows, 'name');
        $link = in_array((string) $days, array_keys($this->deadStock->filters()['days']['options']), true)
            ? route('reports.show', ['key' => 'dead-stock', 'days' => $days])
            : route('reports.show', 'dead-stock');

        return new Finding(
            key: 'dead_stock',
            severity: $stuck >= (int) self::threshold('dead_stock_value') * 100 ? InsightSeverity::Medium : InsightSeverity::Low,
            title: trans_choice('{1} One product has not sold in :days days|[2,*] :count products have not sold in :days days', count($rows), ['count' => count($rows), 'days' => $days]),
            detail: __(':money of the shop\'s money is sitting in :names. That money could be buying things that sell.', [
                'money' => self::money($stuck),
                'names' => self::names($names),
            ]),
            action: __('Put them on offer, sell them alongside a best seller, or return them to the supplier, and stop reordering them.'),
            impactPaisa: $stuck,
            href: $link,
            examples: array_slice($names, 0, 5),
        );
    }

    /**
     * Things that do sell, but far more are on the shelf than will sell soon.
     *
     * @param  array<int, array{qty: int, sales: int, cost: int}>  $sold
     */
    private function overstock(array $sold): ?Finding
    {
        $days = (int) self::threshold('overstock_days');
        $selling = array_filter($sold, fn (array $figures): bool => $figures['qty'] > 0);

        if ($selling === []) {
            return null;
        }

        $excess = Product::query()
            ->whereKey(array_keys($selling))
            ->where('stock_qty_base', '>', 0)
            ->get(['id', 'name', 'stock_qty_base', 'avg_cost_base_paisa'])
            ->map(function (Product $product) use ($selling, $days): array {
                $perDay = $selling[$product->id]['qty'] / 30;
                $extra = max(0, $product->stock_qty_base - (int) ceil($perDay * $days));

                return [
                    'name' => $product->name,
                    'cover' => (int) round($product->stock_qty_base / $perDay),
                    'excess' => $extra * $product->avg_cost_base_paisa,
                ];
            })
            ->filter(fn (array $row): bool => $row['cover'] > $days && $row['excess'] > 0)
            ->sortByDesc('excess')
            ->values();

        if ($excess->isEmpty()) {
            return null;
        }

        $value = (int) $excess->sum('excess');
        $names = $excess->map(fn (array $row): string => __(':name (:days days)', ['name' => $row['name'], 'days' => number_format($row['cover'])]));

        return new Finding(
            key: 'overstock',
            severity: $value >= (int) self::threshold('dead_stock_value') * 100 ? InsightSeverity::Medium : InsightSeverity::Low,
            title: __('More on the shelf than will sell in :days days', ['days' => $days]),
            detail: __('At the speed they sell, :names will take a long time to clear. About :money is tied up beyond :days days of sales.', [
                'names' => self::names($names),
                'money' => self::money($value),
                'days' => $days,
            ]),
            action: __('Buy these in smaller lots, and lower their reorder quantity.'),
            impactPaisa: $value,
            href: route('reports.show', 'stock-value'),
            examples: $names->take(5)->all(),
        );
    }

    /**
     * Stock written off for damage, expiry, theft or the shop's own use in
     * the last 90 days, against what was sold in that time.
     */
    private function shrinkage(): ?Finding
    {
        $from = CarbonImmutable::today()->subDays(89);
        $reasons = [AdjustmentReason::Damage, AdjustmentReason::Expiry, AdjustmentReason::Theft, AdjustmentReason::InternalUse];

        $lost = StockAdjustment::query()
            ->where('status', AdjustmentStatus::Posted)
            ->whereIn('reason', $reasons)
            ->where('posted_at', '>=', $from)
            ->where('value_paisa', '<', 0)
            ->groupBy('reason')
            ->selectRaw('reason, SUM(value_paisa) as paisa')
            ->toBase()
            ->pluck('paisa', 'reason')
            ->map(fn (mixed $paisa): int => abs((int) $paisa));

        $total = (int) $lost->sum();

        if ($total === 0) {
            return null;
        }

        $cogs = $this->profit->totals(Period::between($from, CarbonImmutable::today()))['cost'];
        $share = $cogs > 0 ? round($total / $cogs * 100, 1) : null;

        $split = $lost->sortDesc()
            ->map(fn (int $paisa, string $reason): string => AdjustmentReason::from($reason)->label().' '.self::money($paisa))
            ->values();

        return new Finding(
            key: 'shrinkage',
            severity: $share !== null && $share >= self::SHRINKAGE_ALERT ? InsightSeverity::Medium : InsightSeverity::Low,
            title: __('Stock written off in the last 90 days'),
            detail: $share === null
                ? __(':money was written off (:split).', ['money' => self::money($total), 'split' => $split->implode(', ')])
                : __(':money was written off (:split) — :share of the cost of everything sold in that time.', [
                    'money' => self::money($total),
                    'split' => $split->implode(', '),
                    'share' => self::percent($share),
                ]),
            action: __('Check which products are written off most. Damage points to shelving or handling; expiry points to over-buying; theft points to where the cameras and the counter face.'),
            impactPaisa: $total,
            href: route('stock.adjustments.index'),
        );
    }

    private function turnover(?int $cover): ?Finding
    {
        if ($cover === null) {
            return null;
        }

        if ($cover <= self::HEALTHY_COVER_DAYS) {
            return new Finding(
                key: 'turnover',
                severity: InsightSeverity::Good,
                title: __('Stock is turning over well'),
                detail: __('The shelves hold about :days days of sales, so money comes back quickly.', ['days' => $cover]),
                action: __('Keep ordering little and often.'),
            );
        }

        if ($cover >= self::SLOW_COVER_DAYS) {
            return new Finding(
                key: 'turnover',
                severity: InsightSeverity::Low,
                title: __('Money is sitting on the shelves'),
                detail: __('At the last month\'s pace, the stock on hand would take about :days days to sell.', ['days' => $cover]),
                action: __('Order less of the slow items and put the money into the best sellers.'),
                href: route('reports.show', 'stock-value'),
            );
        }

        return null;
    }

    /**
     * The biggest sellers of the last 30 days, with what is left of each.
     *
     * @param  array<int, array{qty: int, sales: int, cost: int}>  $sold
     * @return list<array{name: string, sales: int, stock: int, active: bool, in_stock: string}>
     */
    private function bestSellers(array $sold, int $limit): array
    {
        uasort($sold, fn (array $a, array $b): int => $b['sales'] <=> $a['sales']);
        $sold = array_slice(array_filter($sold, fn (array $figures): bool => $figures['sales'] > 0), 0, $limit, true);

        $products = Product::query()
            ->whereKey(array_keys($sold))
            ->with('productUnits')
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($sold as $productId => $figures) {
            $product = $products->get($productId);

            if ($product === null) {
                continue;
            }

            $rows[] = [
                'name' => $product->name,
                'sales' => $figures['sales'],
                'stock' => $product->stock_qty_base,
                'active' => $product->is_active,
                'in_stock' => $product->stock_qty_base > 0 ? $product->stockBreakdown() : __('None'),
            ];
        }

        return $rows;
    }
}
