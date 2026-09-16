<?php

namespace App\Support\Insights\Packs;

use App\Enums\InsightSeverity;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\Insights\Finding;
use App\Support\Insights\InsightScope;
use App\Support\Insights\MetricPack;
use App\Support\Reports\DailySalesReport;
use App\Support\Reports\Period;
use App\Support\Reports\ProfitReport;
use Illuminate\Support\Facades\DB;

/**
 * Selling: whether takings and margin are going the right way, where the
 * discounts and returns go, when the shop is busy and what sells together.
 *
 * Every figure is for the dates on the page, set against the stretch just
 * before — the same dates last month for "this month".
 */
final class SalesPack extends Pack
{
    /**
     * A change smaller than this, in either direction, is ordinary noise.
     */
    private const TREND_ALERT = 10.0;

    /**
     * Margin moving by this many points is worth a look.
     */
    private const MARGIN_ALERT = 2.0;

    /**
     * Discounts above this share of the full price are generous.
     */
    private const DISCOUNT_ALERT = 5.0;

    /**
     * Returns above this share of sales point to a problem.
     */
    private const RETURNS_ALERT = 3.0;

    /**
     * A cashier giving this many times the shop's usual discount stands out.
     */
    private const DISCOUNT_OUTLIER = 2.0;

    /**
     * Too few bills to read anything into when to open or what sells together.
     */
    private const MIN_BILLS = 30;

    /**
     * The most recent bills looked at for things bought together.
     */
    private const BASKET_SAMPLE = 3000;

    public function __construct(
        private readonly DailySalesReport $sales,
        private readonly ProfitReport $profit,
    ) {}

    public function build(InsightScope $scope): MetricPack
    {
        $period = $scope->period('this_month');
        $previous = $period->previous();
        $against = $previous->label();

        $now = $this->sales->totals($period);
        $before = $this->sales->totals($previous);
        $profit = $this->profit->totals($period);
        $profitBefore = $this->profit->totals($previous);
        $margin = ProfitReport::margin($profit['profit'], $profit['sales']);
        $marginBefore = ProfitReport::margin($profitBefore['profit'], $profitBefore['sales']);

        $gross = (int) DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', SaleStatus::Completed->value)
            ->whereBetween('sales.sold_at', $period->range())
            ->sum('sale_items.gross_paisa');

        $lines = (int) DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', SaleStatus::Completed->value)
            ->whereBetween('sales.sold_at', $period->range())
            ->count();

        $products = $this->profit->bestSellers($period);
        $categories = array_slice($this->profit->bestSellers($period, 'category'), 0, 6);
        [$hours, $weekdays] = $this->busyTimes($period);

        $findings = array_values(array_filter([
            $this->trend((int) $now['net_paisa'], (int) $before['net_paisa'], $against),
            $this->marginShift($margin, $marginBefore, $against),
            $this->lossMakers($products),
            $this->discounts((int) $now['discount_paisa'], $gross),
            $this->discountOutlier($period),
            $this->returns($period, (int) $now['returns_paisa'], (int) $now['sales_paisa']),
            $this->averageBill($now['average_paisa'], $before['average_paisa'], $against),
            $this->peakHours($hours, (int) $now['bills']),
            $this->basketPairs($period),
        ]));

        return new MetricPack(
            page: 'sales',
            title: __('Sales'),
            scopeLabel: $period->name().' ('.$period->label().')',
            summary: __(':net in net sales from :bills bills, averaging :average. Profit :profit at a :margin margin.', [
                'net' => self::money((int) $now['net_paisa']),
                'bills' => number_format((int) $now['bills']),
                'average' => self::money((int) ($now['average_paisa'] ?? 0)),
                'profit' => self::money($profit['profit']),
                'margin' => self::percent($margin),
            ]),
            facts: [
                'dates' => $period->label(),
                'compared_with' => $against,
                'net_sales' => self::money((int) $now['net_paisa']),
                'net_sales_before' => self::money((int) $before['net_paisa']),
                'bills' => (int) $now['bills'],
                'bills_before' => (int) $before['bills'],
                'average_bill' => self::money((int) ($now['average_paisa'] ?? 0)),
                'average_bill_before' => self::money((int) ($before['average_paisa'] ?? 0)),
                'items_per_bill' => $now['bills'] > 0 ? round($lines / $now['bills'], 1) : null,
                'profit_before_gst' => self::money($profit['profit']),
                'profit_before' => self::money($profitBefore['profit']),
                'margin' => self::percent($margin),
                'margin_before' => self::percent($marginBefore),
                'discounts_given' => self::money((int) $now['discount_paisa']),
                'returns' => self::money((int) $now['returns_paisa']),
                'voided_bills' => (int) $now['voided'],
                'paid_by' => [
                    'cash' => self::money((int) $now['cash_paisa']),
                    'card_or_bank' => self::money((int) $now['card_paisa']),
                    'easypaisa_or_jazzcash' => self::money((int) $now['wallet_paisa']),
                    'khata' => self::money((int) $now['khata_paisa']),
                ],
                'categories' => array_map(fn (array $row): array => [
                    'name' => $row['name'],
                    'sales' => self::money($row['sales_paisa']),
                    'margin' => self::percent($row['margin']),
                ], $categories),
                'best_sellers' => array_map(fn (array $row): array => [
                    'name' => $row['name'],
                    'sales' => self::money($row['sales_paisa']),
                    'profit' => self::money($row['profit_paisa']),
                    'margin' => self::percent($row['margin']),
                ], array_slice($products, 0, 8)),
                'bills_by_hour' => $hours,
                'bills_by_weekday' => $weekdays,
            ],
            findings: $findings,
        );
    }

    private function trend(int $now, int $before, string $against): ?Finding
    {
        $change = self::change($now, $before);

        if ($change === null || abs($change) < self::TREND_ALERT) {
            return null;
        }

        return $change < 0
            ? new Finding(
                key: 'sales_trend',
                severity: InsightSeverity::Medium,
                title: __('Sales are down :change%', ['change' => number_format(abs($change), 1)]),
                detail: __('Net sales were :now against :before in :against.', ['now' => self::money($now), 'before' => self::money($before), 'against' => $against]),
                action: __('Check whether best sellers ran out, a regular customer stopped coming, or a shop nearby is offering lower prices.'),
                impactPaisa: $before - $now,
                href: route('reports.show', 'daily-sales'),
            )
            : new Finding(
                key: 'sales_trend',
                severity: InsightSeverity::Good,
                title: __('Sales are up :change%', ['change' => number_format($change, 1)]),
                detail: __('Net sales were :now against :before in :against.', ['now' => self::money($now), 'before' => self::money($before), 'against' => $against]),
                action: __('Keep the best sellers well stocked so the growth is not lost to empty shelves.'),
                impactPaisa: $now - $before,
                href: route('reports.show', 'daily-sales'),
            );
    }

    private function marginShift(?float $margin, ?float $before, string $against): ?Finding
    {
        if ($margin === null || $before === null || abs($margin - $before) < self::MARGIN_ALERT) {
            return null;
        }

        $down = $margin < $before;

        return new Finding(
            key: 'margin_shift',
            severity: $down ? InsightSeverity::Medium : InsightSeverity::Good,
            title: $down
                ? __('Margin fell from :before to :now', ['before' => self::percent($before), 'now' => self::percent($margin)])
                : __('Margin rose from :before to :now', ['before' => self::percent($before), 'now' => self::percent($margin)]),
            detail: $down
                ? __('Each rupee of sales is keeping less profit than in :against. Suppliers may have raised prices without the shelf prices following, or more low-margin goods are selling.', ['against' => $against])
                : __('Each rupee of sales is keeping more profit than in :against.', ['against' => $against]),
            action: $down
                ? __('Open the profit report sorted by thinnest margin and check the prices at the top.')
                : __('See which categories drove it and give them more shelf space.'),
            href: route('reports.show', ['key' => 'profit', 'sort' => 'margin']),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function lossMakers(array $products): ?Finding
    {
        $losing = array_values(array_filter($products, fn (array $row): bool => $row['profit_paisa'] < 0));

        if ($losing === []) {
            return null;
        }

        usort($losing, fn (array $a, array $b): int => $a['profit_paisa'] <=> $b['profit_paisa']);
        $names = array_column($losing, 'name');

        return new Finding(
            key: 'loss_makers',
            severity: InsightSeverity::High,
            title: trans_choice('{1} One product sold at a loss|[2,*] :count products sold at a loss', count($losing), ['count' => count($losing)]),
            detail: __(':names earned less than they cost to buy.', ['names' => self::names($names)]),
            action: __('Raise their prices, or check whether a discount or a wrong cost is behind it.'),
            impactPaisa: (int) abs(array_sum(array_column($losing, 'profit_paisa'))),
            href: route('reports.show', ['key' => 'profit', 'sort' => 'loss']),
            examples: array_slice($names, 0, 5),
        );
    }

    private function discounts(int $discount, int $gross): ?Finding
    {
        $share = $gross > 0 ? round($discount / $gross * 100, 1) : null;

        if ($share === null || $share < self::DISCOUNT_ALERT) {
            return null;
        }

        return new Finding(
            key: 'discounts',
            severity: InsightSeverity::Medium,
            title: __(':share of the full price was given away in discounts', ['share' => self::percent($share)]),
            detail: __(':money in discounts. Every rupee of discount comes straight out of profit.', ['money' => self::money($discount)]),
            action: __('Agree a limit for discounts at the till, and keep bigger ones for the owner or manager.'),
            impactPaisa: $discount,
            href: route('reports.show', 'cashiers'),
        );
    }

    /**
     * One person at the till giving far more discount than everyone else.
     */
    private function discountOutlier(Period $period): ?Finding
    {
        $people = Sale::query()
            ->completed()
            ->whereBetween('sold_at', $period->range())
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(discount_paisa) as discount, SUM(subtotal_paisa) as gross')
            ->toBase()
            ->get();

        $gross = (int) $people->sum('gross');
        $discount = (int) $people->sum('discount');

        if ($people->count() < 2 || $gross === 0 || $discount === 0) {
            return null;
        }

        $usual = $discount / $gross;

        $outliers = $people
            ->filter(fn (object $row): bool => (int) $row->gross > 0
                && (int) $row->discount >= 50_000
                && (int) $row->discount / (int) $row->gross >= $usual * self::DISCOUNT_OUTLIER)
            ->sortByDesc('discount');

        if ($outliers->isEmpty()) {
            return null;
        }

        $names = User::query()->whereKey($outliers->pluck('user_id'))->pluck('name', 'id');
        $list = $outliers->map(fn (object $row): string => __(':name (:money)', [
            'name' => $names->get($row->user_id, __('Someone')),
            'money' => self::money((int) $row->discount),
        ]))->values();

        return new Finding(
            key: 'discount_outlier',
            severity: InsightSeverity::Medium,
            title: __('One person at the till gives much bigger discounts'),
            detail: __(':names gave at least :times times the shop\'s usual discount on what they sold.', [
                'names' => self::names($list),
                'times' => self::DISCOUNT_OUTLIER,
            ]),
            action: __('Look through their bills with discounts and ask about the biggest ones.'),
            impactPaisa: (int) $outliers->sum('discount'),
            href: route('reports.show', ['key' => 'cashiers'] + $period->query()),
            examples: $list->take(5)->all(),
        );
    }

    private function returns(Period $period, int $returns, int $sales): ?Finding
    {
        $share = $sales > 0 ? round($returns / $sales * 100, 1) : null;

        if ($share === null || $share < self::RETURNS_ALERT) {
            return null;
        }

        $reason = SaleReturn::query()
            ->whereBetween('returned_at', $period->range())
            ->groupBy('reason')
            ->selectRaw('reason, SUM(total_paisa) as paisa')
            ->orderByDesc('paisa')
            ->first();

        return new Finding(
            key: 'returns',
            severity: InsightSeverity::Medium,
            title: __(':share of sales came back as returns', ['share' => self::percent($share)]),
            detail: $reason
                ? __(':money was refunded, most often for ":reason".', ['money' => self::money($returns), 'reason' => $reason->reason->label()])
                : __(':money was refunded.', ['money' => self::money($returns)]),
            action: __('Look at the returns list for the same product or the same cashier coming up again and again.'),
            impactPaisa: $returns,
            href: route('sales.returns.index'),
        );
    }

    private function averageBill(?int $now, ?int $before, string $against): ?Finding
    {
        $change = $now !== null && $before !== null ? self::change($now, $before) : null;

        if ($change === null || $change > -self::TREND_ALERT) {
            return null;
        }

        return new Finding(
            key: 'average_bill',
            severity: InsightSeverity::Low,
            title: __('Customers are spending less per visit'),
            detail: __('The average bill fell to :now from :before in :against.', ['now' => self::money($now), 'before' => self::money($before), 'against' => $against]),
            action: __('Suggest one more item at the till, place small add-ons near the counter, and keep bigger packs of everyday items in stock.'),
        );
    }

    /**
     * @param  array<string, int>  $hours
     */
    private function peakHours(array $hours, int $bills): ?Finding
    {
        if ($bills < self::MIN_BILLS || $hours === []) {
            return null;
        }

        arsort($hours);
        $busiest = array_slice(array_keys($hours), 0, 3);
        $quietest = array_key_last($hours);

        return new Finding(
            key: 'peak_hours',
            severity: InsightSeverity::Low,
            title: __('Busiest at :hours', ['hours' => self::names($busiest)]),
            detail: __('Most bills are rung up at :busy. The quietest open hour is :quiet.', ['busy' => self::names($busiest), 'quiet' => $quietest]),
            action: __('Have both counters open and the shelves full before the busy hours; restock and count in the quiet one.'),
        );
    }

    /**
     * Two products that turn up on the same bill again and again.
     */
    private function basketPairs(Period $period): ?Finding
    {
        $saleIds = Sale::query()
            ->completed()
            ->whereBetween('sold_at', $period->range())
            ->latest('sold_at')
            ->limit(self::BASKET_SAMPLE)
            ->pluck('id');

        if ($saleIds->count() < self::MIN_BILLS) {
            return null;
        }

        $baskets = DB::table('sale_items')
            ->whereIn('sale_id', $saleIds)
            ->select('sale_id', 'product_id')
            ->distinct()
            ->get()
            ->groupBy('sale_id')
            ->map(fn ($items) => $items->pluck('product_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all())
            ->filter(fn (array $products): bool => count($products) >= 2 && count($products) <= 40);

        $pairs = [];

        foreach ($baskets as $products) {
            $count = count($products);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $key = $products[$i].'-'.$products[$j];
                    $pairs[$key] = ($pairs[$key] ?? 0) + 1;
                }
            }
        }

        $minimum = max(5, (int) ceil($baskets->count() * 0.03));
        $pairs = array_filter($pairs, fn (int $bills): bool => $bills >= $minimum);

        if ($pairs === []) {
            return null;
        }

        arsort($pairs);
        $pairs = array_slice($pairs, 0, 3, true);

        $ids = collect(array_keys($pairs))->flatMap(fn (string $key): array => explode('-', $key))->unique();
        $names = Product::query()->whereKey($ids)->pluck('name', 'id');

        $list = collect($pairs)->map(function (int $bills, string $key) use ($names): string {
            [$a, $b] = explode('-', $key);

            return __(':a with :b (:bills bills)', ['a' => $names->get((int) $a), 'b' => $names->get((int) $b), 'bills' => $bills]);
        })->values();

        return new Finding(
            key: 'basket_pairs',
            severity: InsightSeverity::Low,
            title: __('Bought together'),
            detail: __('These often end up on the same bill: :pairs.', ['pairs' => $list->implode('; ')]),
            action: __('Shelve them next to each other, or offer them as a pair for a little less.'),
            examples: $list->all(),
        );
    }

    /**
     * How many bills were rung up in each hour of the day and on each day
     * of the week. Worked out here rather than in the database so it reads
     * the same on every kind of database.
     *
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private function busyTimes(Period $period): array
    {
        $hours = [];
        $weekdays = [];

        Sale::query()
            ->completed()
            ->whereBetween('sold_at', $period->range())
            ->select(['id', 'sold_at'])
            ->lazyById(1000)
            ->each(function (Sale $sale) use (&$hours, &$weekdays): void {
                $at = $sale->sold_at->timezone(config('app.timezone'));
                $hours[$at->hour] = ($hours[$at->hour] ?? 0) + 1;
                $weekdays[$at->format('l')] = ($weekdays[$at->format('l')] ?? 0) + 1;
            });

        ksort($hours);
        arsort($weekdays);

        $labelled = [];

        foreach ($hours as $hour => $bills) {
            $from = now()->setTime($hour, 0);
            $labelled[$from->format('ga').'–'.$from->addHour()->format('ga')] = $bills;
        }

        return [$labelled, $period->days() >= 14 ? $weekdays : []];
    }
}
