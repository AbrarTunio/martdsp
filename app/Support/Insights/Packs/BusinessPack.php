<?php

namespace App\Support\Insights\Packs;

use App\Enums\InsightSeverity;
use App\Support\Insights\Finding;
use App\Support\Insights\InsightScope;
use App\Support\Insights\MetricPack;
use App\Support\Reports\DailySalesReport;
use App\Support\Reports\Dashboard;
use App\Support\Reports\ProfitReport;
use Throwable;

/**
 * The whole shop on one page: the month's trading, where the money is
 * sitting, and the worst of what every other area has found.
 */
final class BusinessPack extends Pack
{
    /**
     * More than this many findings and the note stops being a note.
     */
    private const KEEP = 8;

    public function __construct(
        private readonly Dashboard $dashboard,
        private readonly DailySalesReport $sales,
        private readonly ProfitReport $profit,
        private readonly SalesPack $salesPack,
        private readonly StockPack $stockPack,
        private readonly KhataPack $khataPack,
        private readonly DrawerPack $drawerPack,
        private readonly BuyingPack $buyingPack,
    ) {}

    public function build(InsightScope $scope): MetricPack
    {
        $period = $scope->period('this_month');
        $before = $period->previous();

        $now = $this->sales->totals($period);
        $then = $this->sales->totals($before);
        $profit = $this->profit->totals($period);
        $profitBefore = $this->profit->totals($before);
        $money = $this->dashboard->money();

        $margin = ProfitReport::margin($profit['profit'], $profit['sales']);
        $held = collect($money['held'])->mapWithKeys(fn (array $row): array => [$row['label'] => self::money($row['paisa'])]);

        return new MetricPack(
            page: 'business',
            title: __('The whole shop'),
            scopeLabel: $period->label(),
            summary: __('Net sales :sales and profit :profit for :period. The shop is holding :total in cash, stock and khata after what it owes suppliers.', [
                'sales' => self::money($now['net_paisa']),
                'profit' => self::money($profit['profit']),
                'period' => $period->label(),
                'total' => self::money($money['total']),
            ]),
            facts: [
                'net_sales' => self::money($now['net_paisa']),
                'net_sales_before' => self::money($then['net_paisa']),
                'sales_change' => self::percent(self::change($now['net_paisa'], $then['net_paisa'])),
                'bills' => $now['bills'],
                'average_bill' => self::money((int) ($now['average_paisa'] ?? 0)),
                'profit' => self::money($profit['profit']),
                'profit_before' => self::money($profitBefore['profit']),
                'margin' => self::percent($margin),
                'given_on_khata' => self::money($now['khata_paisa']),
                'discounts_given' => self::money($now['discount_paisa']),
                'returns' => self::money($now['returns_paisa']),
                'money_the_shop_is_holding' => $held->all(),
                'owed_to_suppliers' => self::money($money['owed']['paisa']),
                'worth_of_the_shop' => self::money($money['total']),
                'things_to_look_at' => collect($this->dashboard->warnings($scope->user))
                    ->mapWithKeys(fn (array $warning): array => [$warning['label'] => $warning['count']])
                    ->all(),
            ],
            findings: $this->everywhereElse($scope),
        );
    }

    /**
     * The findings the other pages would raise, worst first, kept short.
     *
     * A pack that fails must not take the whole note down with it, so each
     * is asked on its own.
     *
     * @return list<Finding>
     */
    private function everywhereElse(InsightScope $scope): array
    {
        $findings = [];

        foreach ([$this->salesPack, $this->stockPack, $this->khataPack, $this->drawerPack, $this->buyingPack] as $pack) {
            try {
                $pack = $pack->build($scope);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($pack->sortedFindings() as $finding) {
                if ($finding->severity !== InsightSeverity::Low) {
                    $findings[] = $finding;
                }
            }
        }

        usort($findings, fn (Finding $a, Finding $b): int => [$a->severity->rank(), -($a->impactPaisa ?? 0)] <=> [$b->severity->rank(), -($b->impactPaisa ?? 0)]);

        return array_slice($findings, 0, self::KEEP);
    }
}
