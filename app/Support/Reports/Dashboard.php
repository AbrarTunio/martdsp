<?php

namespace App\Support\Reports;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\DrawerSession;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The figures on the home screen.
 *
 * Sales and profit are worked out by the same code as the daily sales and
 * profit reports, so the dashboard never says one thing and a report
 * another. Each panel is its own method, and the controller asks only for
 * the panels the person looking is allowed to see.
 */
final class Dashboard
{
    /**
     * The category doughnut shows this many slices and folds the rest together.
     */
    private const CATEGORY_SLICES = 6;

    public function __construct(
        private readonly DailySalesReport $sales,
        private readonly ProfitReport $profit,
    ) {}

    /**
     * Today, set against the same day last week — a Friday is only fairly
     * compared with a Friday.
     *
     * @return list<array<string, string|null>>
     */
    public function today(): array
    {
        $today = Period::preset('today');
        $lastWeek = $today->from->subWeek()->toDateString();
        $lastWeek = Period::between($lastWeek, $lastWeek);

        $now = $this->sales->totals($today);
        $before = $this->sales->totals($lastWeek);
        $profit = $this->profit->totals($today);
        $margin = ProfitReport::margin($profit['profit'], $profit['sales']);

        return [
            [
                'label' => __('Sales today'),
                'value' => Money::rounded($now['net_paisa']),
                'icon' => 'wallet',
                'hint' => Trend::describe($now['net_paisa'], $before['net_paisa'], $lastWeek->label()),
                'tone' => Trend::tone($now['net_paisa'], $before['net_paisa']),
            ],
            [
                'label' => __('Bills today'),
                'value' => number_format($now['bills']),
                'icon' => 'receipt',
                'hint' => $now['average_paisa'] === null
                    ? __('No bills yet')
                    : __('Average bill :amount', ['amount' => Money::rounded($now['average_paisa'])]),
            ],
            [
                'label' => __('Profit today'),
                'value' => Money::rounded($profit['profit']),
                'icon' => 'trend-up',
                'hint' => $margin === null ? __('Nothing sold yet') : __(':margin% of sales before GST', ['margin' => number_format($margin, 1)]),
                'tone' => $profit['profit'] < 0 ? 'danger' : 'neutral',
            ],
        ];
    }

    /**
     * This month so far, set against the same dates last month.
     *
     * @return array{label: string, stats: list<array<string, string|null>>}
     */
    public function month(): array
    {
        $month = Period::preset('this_month');
        $lastMonth = Period::between(
            $month->from->subMonthNoOverflow()->toDateString(),
            $month->to->subMonthNoOverflow()->toDateString(),
        );
        $against = $lastMonth->label();

        $now = $this->sales->totals($month);
        $before = $this->sales->totals($lastMonth);
        $profit = $this->profit->totals($month);
        $profitBefore = $this->profit->totals($lastMonth);
        $margin = ProfitReport::margin($profit['profit'], $profit['sales']);
        $marginBefore = ProfitReport::margin($profitBefore['profit'], $profitBefore['sales']);

        return [
            'label' => $month->label(),
            'stats' => [
                [
                    'label' => __('Net sales'),
                    'value' => Money::rounded($now['net_paisa']),
                    'icon' => 'wallet',
                    'hint' => Trend::describe($now['net_paisa'], $before['net_paisa'], $against),
                    'tone' => Trend::tone($now['net_paisa'], $before['net_paisa']),
                ],
                [
                    'label' => __('Profit'),
                    'value' => Money::rounded($profit['profit']),
                    'icon' => 'trend-up',
                    'hint' => Trend::describe($profit['profit'], $profitBefore['profit'], $against),
                    'tone' => Trend::tone($profit['profit'], $profitBefore['profit']),
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
                    'label' => __('Bills'),
                    'value' => number_format($now['bills']),
                    'icon' => 'receipt',
                    'hint' => Trend::describe($now['bills'], $before['bills'], $against),
                ],
            ],
        ];
    }

    /**
     * How one person's day at the till is going — what a cashier sees
     * instead of the shop's takings.
     *
     * @return list<array<string, string|null>>
     */
    public function counter(User $user): array
    {
        $today = Period::preset('today');

        $mine = Sale::query()
            ->completed()
            ->where('user_id', $user->id)
            ->whereBetween('sold_at', $today->range())
            ->toBase()
            ->selectRaw('COUNT(*) as bills, COALESCE(SUM(total_paisa), 0) as sales')
            ->first();

        $bills = (int) $mine->bills;
        $sales = (int) $mine->sales;

        $cancelled = Sale::query()
            ->where('status', SaleStatus::Void)
            ->where('user_id', $user->id)
            ->whereBetween('sold_at', $today->range())
            ->count();

        return [
            [
                'label' => __('Your bills today'),
                'value' => number_format($bills),
                'icon' => 'receipt',
                'hint' => $bills > 0 ? __('Average bill :amount', ['amount' => Money::rounded(intdiv($sales, $bills))]) : __('No bills yet'),
            ],
            [
                'label' => __('Your sales today'),
                'value' => Money::rounded($sales),
                'icon' => 'wallet',
            ],
            [
                'label' => __('Cancelled today'),
                'value' => number_format($cancelled),
                'icon' => 'close',
                'hint' => $cancelled > 0 ? __('Bills of yours a supervisor voided') : __('None cancelled'),
                'tone' => $cancelled > 0 ? 'warning' : 'neutral',
            ],
        ];
    }

    /**
     * The drawers open right now, oldest shift first, each with what the
     * till says should be in it.
     *
     * @return Collection<int, DrawerSession>
     */
    public function openDrawers(): Collection
    {
        return DrawerSession::query()
            ->open()
            ->with(['register:id,name', 'opener:id,name'])
            ->withSum('transactions', 'amount_paisa')
            ->orderBy('opened_at')
            ->get();
    }

    /**
     * Where the shop's money is sitting right now: in the drawers, on the
     * shelves, and in customers' khata — less what is owed to suppliers.
     *
     * @return array{held: list<array{label: string, paisa: int, hint: string, href: string}>, owed: array{label: string, paisa: int, hint: string, href: string}, total: int}
     */
    public function money(): array
    {
        $drawers = $this->openDrawers();
        $cash = (int) $drawers->sum('transactions_sum_amount_paisa');

        $stock = (int) Product::query()
            ->where('stock_qty_base', '>', 0)
            ->toBase()
            ->sum(DB::raw('stock_qty_base * avg_cost_base_paisa'));

        $customers = Customer::query()->owing()->toBase()->selectRaw('COUNT(*) as people, COALESCE(SUM(balance_paisa), 0) as paisa')->first();
        $suppliers = Supplier::query()->where('balance_paisa', '>', 0)->toBase()->selectRaw('COUNT(*) as people, COALESCE(SUM(balance_paisa), 0) as paisa')->first();

        $receivable = (int) $customers->paisa;
        $payable = (int) $suppliers->paisa;

        return [
            'held' => [
                [
                    'label' => __('Cash in the drawers'),
                    'paisa' => $cash,
                    'hint' => trans_choice('{0} No drawer is open|{1} In one open drawer|[2,*] Across :count open drawers', $drawers->count(), ['count' => $drawers->count()]),
                    'href' => route('drawer.index'),
                ],
                [
                    'label' => __('Stock on the shelves'),
                    'paisa' => $stock,
                    'hint' => __('At what it cost to buy'),
                    'href' => route('reports.show', 'stock-value'),
                ],
                [
                    'label' => __('Khata — customers owe you'),
                    'paisa' => $receivable,
                    'hint' => trans_choice('{0} Nobody owes you|{1} One customer owes you|[2,*] :count customers owe you', (int) $customers->people, ['count' => (int) $customers->people]),
                    'href' => route('customers.index'),
                ],
            ],
            'owed' => [
                'label' => __('You owe suppliers'),
                'paisa' => $payable,
                'hint' => trans_choice('{0} Nobody is owed|{1} Owed to one supplier|[2,*] Owed to :count suppliers', (int) $suppliers->people, ['count' => (int) $suppliers->people]),
                'href' => route('reports.show', 'suppliers'),
            ],
            'total' => $cash + $stock + $receivable - $payable,
        ];
    }

    /**
     * Things someone should look at today. Only the ones with something in
     * them come back, the red ones first.
     *
     * @return list<array{label: string, count: int, hint: string, icon: string, href: string, tone: string}>
     */
    public function warnings(User $user): array
    {
        $warnings = [
            [
                'label' => __('Below zero'),
                'count' => Product::query()->where('stock_qty_base', '<', 0)->count(),
                'hint' => __('Sold more than was booked in — count these'),
                'icon' => 'alert',
                'href' => route('stock.index', ['view' => 'negative']),
                'tone' => 'danger',
            ],
            [
                'label' => __('Past expiry, still in stock'),
                'count' => StockBatch::query()->open()->expired()->count(),
                'hint' => __('Take these off the shelf'),
                'icon' => 'alert',
                'href' => route('stock.expiry', ['view' => 'expired']),
                'tone' => 'danger',
            ],
            [
                'label' => __('Low on stock'),
                'count' => Product::query()->active()->lowStock()->count(),
                'hint' => __('At or below the reorder level'),
                'icon' => 'box',
                'href' => route('stock.index', ['view' => 'low']),
                'tone' => 'warning',
            ],
            [
                'label' => __('Expiring within 30 days'),
                'count' => StockBatch::query()->open()->stillSellable()->expiringWithin(30)->count(),
                'hint' => __('Bring these to the front of the shelf'),
                'icon' => 'clock',
                'href' => route('stock.expiry', ['view' => '30']),
                'tone' => 'warning',
            ],
        ];

        if ($user->supervises()) {
            $warnings[] = [
                'label' => __('Drawers waiting for approval'),
                'count' => DrawerSession::query()->awaitingApproval()->count(),
                'hint' => __('Closed short or over by more than the limit'),
                'icon' => 'safe',
                'href' => route('drawer.index', ['status' => 'waiting']),
                'tone' => 'warning',
            ];
        }

        return array_values(array_filter($warnings, fn (array $warning): bool => $warning['count'] > 0));
    }

    /**
     * Net sales for each of the last 30 days, with the average drawn across
     * so a good day and a bad one stand out at a glance.
     *
     * @return array<string, mixed>|null
     */
    public function trend(): ?array
    {
        $days = $this->sales->netByDay(Period::preset('last_30_days'));

        if (array_filter($days) === []) {
            return null;
        }

        $average = intdiv(array_sum($days), count($days));

        return [
            'type' => 'line',
            'money' => true,
            'labels' => array_map(fn (string $day): string => CarbonImmutable::parse($day)->format('j M'), array_keys($days)),
            'datasets' => [
                ['label' => __('Net sales'), 'data' => array_values($days), 'color' => 'brand', 'fill' => true],
                ['label' => __('Daily average'), 'data' => array_fill(0, count($days), $average), 'color' => 'gray', 'dashed' => true],
            ],
        ];
    }

    /**
     * The ten products that brought in the most this month, with what each
     * earned beside it.
     *
     * @return array<string, mixed>|null
     */
    public function topProducts(): ?array
    {
        $top = array_slice($this->sold('product'), 0, 10);

        return $top === [] ? null : [
            'type' => 'bar',
            'horizontal' => true,
            'money' => true,
            'labels' => array_column($top, 'name'),
            'datasets' => [
                ['label' => __('Sales before GST'), 'data' => array_column($top, 'sales_paisa'), 'color' => 'sky'],
                ['label' => __('Profit'), 'data' => array_column($top, 'profit_paisa'), 'color' => 'brand'],
            ],
        ];
    }

    /**
     * This month's sales split by category.
     *
     * @return array<string, mixed>|null
     */
    public function categoryMix(): ?array
    {
        $categories = $this->sold('category');

        if ($categories === []) {
            return null;
        }

        $shown = array_slice($categories, 0, self::CATEGORY_SLICES);
        $rest = array_sum(array_column(array_slice($categories, self::CATEGORY_SLICES), 'sales_paisa'));

        $labels = array_column($shown, 'name');
        $data = array_column($shown, 'sales_paisa');

        if ($rest > 0) {
            $labels[] = __('Everything else');
            $data[] = $rest;
        }

        return [
            'type' => 'doughnut',
            'money' => true,
            'labels' => $labels,
            'datasets' => [['label' => __('Sales before GST'), 'data' => $data]],
        ];
    }

    /**
     * This month's products or categories that sold, biggest first.
     *
     * @return list<array<string, mixed>>
     */
    private function sold(string $group): array
    {
        return array_values(array_filter(
            $this->profit->bestSellers(Period::preset('this_month'), $group),
            fn (array $line): bool => $line['sales_paisa'] > 0,
        ));
    }
}
