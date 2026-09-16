<?php

namespace App\Support\Reports;

use App\Enums\SaleStatus;
use App\Enums\TenderType;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What came in each day, how it was paid, and what went back out as returns.
 *
 * Sales are completed bills, on the day they were rung up. A cancelled bill
 * is not a sale — it is counted on its own, because a run of them is worth a
 * question. A return comes off the day the goods came back, not the day of
 * the bill, so last month's figures never change under the owner's feet.
 */
final class DailySalesReport extends Report
{
    /**
     * @var list<string>
     */
    private const FIGURES = ['bills', 'sales', 'discount', 'tax', 'voided', 'returns', 'return_tax', 'cash', 'card', 'wallet', 'khata'];

    public function title(): string
    {
        return __('Daily sales');
    }

    public function description(): string
    {
        return __('What came in each day, how it was paid, and what went back as returns.');
    }

    public function icon(): string
    {
        return 'receipt';
    }

    public function filters(): array
    {
        return [
            'by' => [
                'label' => __('Show'),
                'options' => ['day' => __('Day by day'), 'month' => __('Month by month')],
            ],
        ];
    }

    public function build(Period $period, array $filters): ReportResult
    {
        $byMonth = ($filters['by'] ?? 'day') === 'month';

        $days = $this->days($period);
        $total = $this->sum($days);
        $before = $this->sum($this->days($period->previous()));
        $against = $period->previous()->label();

        $rows = [];

        foreach ($byMonth ? $this->months($days) : $days as $key => $figures) {
            $rows[] = $this->row($figures) + [
                'date' => $byMonth ? CarbonImmutable::createFromFormat('!Y-m', $key)->format('F Y') : CarbonImmutable::parse($key),
                '_link' => $byMonth ? null : route('sales.index', ['date' => $key]),
                '_tone' => $figures['bills'] === 0 ? 'muted' : null,
            ];
        }

        $net = $total['sales'] - $total['returns'];
        $netBefore = $before['sales'] - $before['returns'];

        return new ReportResult(
            columns: [
                $byMonth ? Column::text('date', __('Month')) : Column::date('date', __('Day')),
                Column::number('bills', __('Bills')),
                Column::money('sales_paisa', __('Sales')),
                Column::money('discount_paisa', __('Discount')),
                Column::money('returns_paisa', __('Returned')),
                Column::money('net_paisa', __('Net sales'), emphasis: true),
                Column::money('tax_paisa', __('GST')),
                Column::money('cash_paisa', __('Cash')),
                Column::money('card_paisa', __('Card & bank')),
                Column::money('wallet_paisa', __('Wallets')),
                Column::money('khata_paisa', __('Khata')),
                Column::money('average_paisa', __('Average bill')),
                Column::number('voided', __('Cancelled')),
            ],
            rows: $rows,
            totals: $this->row($total) + ['date' => __('Total')],
            stats: [
                [
                    'label' => __('Net sales'),
                    'value' => Money::rounded($net),
                    'icon' => 'wallet',
                    'hint' => Trend::describe($net, $netBefore, $against),
                    'tone' => Trend::tone($net, $netBefore),
                ],
                [
                    'label' => __('Bills'),
                    'value' => number_format($total['bills']),
                    'icon' => 'receipt',
                    'hint' => Trend::describe($total['bills'], $before['bills'], $against),
                    'tone' => Trend::tone($total['bills'], $before['bills']),
                ],
                [
                    'label' => __('Average bill'),
                    'value' => Money::rounded($total['bills'] > 0 ? intdiv($total['sales'], $total['bills']) : 0),
                    'icon' => 'cart',
                    'hint' => __(':amount given as discount', ['amount' => Money::rounded($total['discount'])]),
                ],
                [
                    'label' => __('GST collected'),
                    'value' => Money::rounded($total['tax'] - $total['return_tax']),
                    'icon' => 'book',
                    'hint' => $total['returns'] > 0
                        ? __(':amount went back as returns', ['amount' => Money::rounded($total['returns'])])
                        : __('No returns'),
                ],
            ],
            chart: [
                'type' => 'bar',
                'money' => true,
                'labels' => array_map(
                    fn (array $row): string => $byMonth ? $row['date'] : $row['date']->format('j M'),
                    $rows,
                ),
                'datasets' => [
                    ['label' => __('Net sales'), 'data' => array_column($rows, 'net_paisa'), 'color' => 'brand'],
                ],
            ],
            footnote: __('Sales are completed bills on the day they were rung up; cancelled bills are left out and counted on their own. Returns come off the day the goods came back. GST is what was collected less what was refunded.'),
        );
    }

    /**
     * The dates added up into one line — bills, net sales, GST, the tender
     * split — for screens that need the figures without the table.
     *
     * @return array<string, int|null>
     */
    public function totals(Period $period): array
    {
        return $this->row($this->sum($this->days($period)));
    }

    /**
     * Net sales for each day of the period, keyed by date, a quiet day as nought.
     *
     * @return array<string, int>
     */
    public function netByDay(Period $period): array
    {
        return array_map(fn (array $figures): int => $figures['sales'] - $figures['returns'], $this->days($period));
    }

    /**
     * Every day of the period with its figures, a quiet day as zeroes.
     *
     * @return array<string, array<string, int>>
     */
    private function days(Period $period): array
    {
        $days = array_fill_keys($period->dates(), array_fill_keys(self::FIGURES, 0));

        $add = function (string $day, string $figure, int $amount) use (&$days): void {
            $day = substr($day, 0, 10);

            if (isset($days[$day])) {
                $days[$day][$figure] += $amount;
            }
        };

        Sale::query()
            ->completed()
            ->whereBetween('sold_at', $period->range())
            ->selectRaw('DATE(sold_at) as day, COUNT(*) as bills, SUM(total_paisa) as sales, SUM(discount_paisa) as discount, SUM(tax_paisa) as tax')
            ->groupByRaw('DATE(sold_at)')
            ->toBase()
            ->get()
            ->each(function (object $row) use ($add): void {
                foreach (['bills', 'sales', 'discount', 'tax'] as $figure) {
                    $add((string) $row->day, $figure, (int) $row->{$figure});
                }
            });

        Sale::query()
            ->where('status', SaleStatus::Void)
            ->whereBetween('sold_at', $period->range())
            ->selectRaw('DATE(sold_at) as day, COUNT(*) as voided')
            ->groupByRaw('DATE(sold_at)')
            ->toBase()
            ->get()
            ->each(fn (object $row) => $add((string) $row->day, 'voided', (int) $row->voided));

        DB::table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.status', SaleStatus::Completed->value)
            ->whereBetween('sales.sold_at', $period->range())
            ->selectRaw('DATE(sales.sold_at) as day, sale_payments.method as method, SUM(sale_payments.amount_paisa) as paisa')
            ->groupByRaw('DATE(sales.sold_at), sale_payments.method')
            ->get()
            ->each(fn (object $row) => $add((string) $row->day, self::tenderFigure((string) $row->method), (int) $row->paisa));

        SaleReturn::query()
            ->whereBetween('returned_at', $period->range())
            ->selectRaw('DATE(returned_at) as day, SUM(total_paisa) as paisa, SUM(tax_paisa) as tax')
            ->groupByRaw('DATE(returned_at)')
            ->toBase()
            ->get()
            ->each(function (object $row) use ($add): void {
                $add((string) $row->day, 'returns', (int) $row->paisa);
                $add((string) $row->day, 'return_tax', (int) $row->tax);
            });

        return $days;
    }

    /**
     * @param  array<string, array<string, int>>  $days
     * @return array<string, array<string, int>>
     */
    private function months(array $days): array
    {
        $months = [];

        foreach ($days as $day => $figures) {
            $month = substr($day, 0, 7);
            $months[$month] = isset($months[$month]) ? $this->sum([$months[$month], $figures]) : $figures;
        }

        return $months;
    }

    /**
     * @param  array<array-key, array<string, int>>  $days
     * @return array<string, int>
     */
    private function sum(array $days): array
    {
        $total = array_fill_keys(self::FIGURES, 0);

        foreach ($days as $figures) {
            foreach (self::FIGURES as $figure) {
                $total[$figure] += $figures[$figure];
            }
        }

        return $total;
    }

    /**
     * @param  array<string, int>  $figures
     * @return array<string, int|null>
     */
    private function row(array $figures): array
    {
        return [
            'bills' => $figures['bills'],
            'sales_paisa' => $figures['sales'],
            'discount_paisa' => $figures['discount'],
            'returns_paisa' => $figures['returns'],
            'net_paisa' => $figures['sales'] - $figures['returns'],
            'tax_paisa' => $figures['tax'] - $figures['return_tax'],
            'cash_paisa' => $figures['cash'],
            'card_paisa' => $figures['card'],
            'wallet_paisa' => $figures['wallet'],
            'khata_paisa' => $figures['khata'],
            'average_paisa' => $figures['bills'] > 0 ? intdiv($figures['sales'], $figures['bills']) : null,
            'voided' => $figures['voided'],
        ];
    }

    /**
     * Card and bank transfer land in the same bank account, and the two
     * wallets on the shop's phones, so that is how they are added up here.
     */
    private static function tenderFigure(string $method): string
    {
        return match (TenderType::tryFrom($method)) {
            TenderType::Cash => 'cash',
            TenderType::Easypaisa, TenderType::JazzCash => 'wallet',
            TenderType::Khata => 'khata',
            default => 'card',
        };
    }
}
