<?php

namespace App\Support\Reports;

use App\Enums\DrawerStatus;
use App\Enums\SaleStatus;
use App\Models\DrawerSession;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\Money;

/**
 * How each person at the till did.
 *
 * Bills and sales are the completed bills they rang up. Cancelled bills are
 * the ones they rang up that a supervisor later voided — a few are normal,
 * a habit of them is worth a quiet word. Returns are the ones they handed
 * money back for. The drawer figures come from the shifts they opened and
 * closed: what the count came to against what the till expected.
 */
final class CashierReport extends Report
{
    /**
     * @var list<string>
     */
    private const FIGURES = ['bills', 'sales', 'discount', 'voided', 'returns', 'drawers', 'variance', 'short_drawers'];

    public function title(): string
    {
        return __('Cashier performance');
    }

    public function description(): string
    {
        return __('Bills, sales, discounts, cancellations and drawer counts for each person at the till.');
    }

    public function icon(): string
    {
        return 'users';
    }

    public function build(Period $period, array $filters): ReportResult
    {
        $people = $this->people($period);

        $names = User::query()
            ->whereKey(array_keys($people))
            ->get(['id', 'name', 'role'])
            ->keyBy('id');

        $rows = [];

        foreach ($people as $userId => $figures) {
            $user = $names->get($userId);
            $cancelRate = $figures['bills'] + $figures['voided'] > 0
                ? $figures['voided'] / ($figures['bills'] + $figures['voided']) * 100
                : 0;

            $rows[] = [
                'name' => $user->name ?? __('Removed user'),
                'bills' => $figures['bills'],
                'sales_paisa' => $figures['sales'],
                'average_paisa' => $figures['bills'] > 0 ? intdiv($figures['sales'], $figures['bills']) : null,
                'discount_paisa' => $figures['discount'],
                'voided' => $figures['voided'],
                'returns_paisa' => $figures['returns'],
                'drawers' => $figures['drawers'],
                'variance_paisa' => $figures['drawers'] > 0 ? $figures['variance'] : null,
                '_detail' => $user?->role?->label(),
                '_tone' => match (true) {
                    $figures['short_drawers'] > 0 && $figures['variance'] < 0 => 'danger',
                    $cancelRate >= 5 => 'warning',
                    default => null,
                },
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['sales_paisa'] <=> $a['sales_paisa'] ?: strcmp($a['name'], $b['name']));

        $total = array_fill_keys(self::FIGURES, 0);

        foreach ($people as $figures) {
            foreach (self::FIGURES as $figure) {
                $total[$figure] += $figures[$figure];
            }
        }

        $best = $rows[0] ?? null;

        return new ReportResult(
            columns: [
                Column::text('name', __('Name')),
                Column::number('bills', __('Bills')),
                Column::money('sales_paisa', __('Sales'), emphasis: true),
                Column::money('average_paisa', __('Average bill')),
                Column::money('discount_paisa', __('Discount given')),
                Column::number('voided', __('Cancelled')),
                Column::money('returns_paisa', __('Refunded')),
                Column::number('drawers', __('Shifts')),
                Column::money('variance_paisa', __('Drawer short / over')),
            ],
            rows: $rows,
            totals: [
                'bills' => $total['bills'],
                'sales_paisa' => $total['sales'],
                'average_paisa' => $total['bills'] > 0 ? intdiv($total['sales'], $total['bills']) : null,
                'discount_paisa' => $total['discount'],
                'voided' => $total['voided'],
                'returns_paisa' => $total['returns'],
                'drawers' => $total['drawers'],
                'variance_paisa' => $total['variance'],
            ],
            stats: [
                [
                    'label' => __('People at the till'),
                    'value' => number_format(count(array_filter($rows, fn (array $row): bool => $row['bills'] > 0))),
                    'icon' => 'users',
                    'hint' => __(':count bills between them', ['count' => number_format($total['bills'])]),
                ],
                [
                    'label' => __('Top seller'),
                    'value' => $best && $best['sales_paisa'] > 0 ? $best['name'] : '—',
                    'icon' => 'trend-up',
                    'hint' => $best && $best['sales_paisa'] > 0 ? Money::rounded($best['sales_paisa']) : null,
                ],
                [
                    'label' => __('Cancelled bills'),
                    'value' => number_format($total['voided']),
                    'icon' => 'close',
                    'hint' => $total['voided'] > 0
                        ? __(':percent% of all bills rung up', ['percent' => number_format($total['voided'] / ($total['bills'] + $total['voided']) * 100, 1)])
                        : __('None cancelled'),
                    'tone' => $total['voided'] > 0 ? 'warning' : 'neutral',
                ],
                [
                    'label' => __('Drawers short / over'),
                    'value' => $total['drawers'] > 0 ? Money::rounded($total['variance']) : '—',
                    'icon' => 'safe',
                    'hint' => trans_choice('{0} No shifts closed|{1} One shift closed|[2,*] :count shifts closed', $total['drawers'], ['count' => $total['drawers']]),
                    'tone' => match (true) {
                        $total['variance'] < 0 => 'danger',
                        $total['variance'] > 0 => 'warning',
                        default => 'neutral',
                    },
                ],
            ],
            chart: $total['sales'] > 0 ? [
                'type' => 'bar',
                'money' => true,
                'labels' => array_column($rows, 'name'),
                'datasets' => [
                    ['label' => __('Sales'), 'data' => array_column($rows, 'sales_paisa'), 'color' => 'brand'],
                ],
            ] : null,
            footnote: __('Sales are the completed bills each person rang up. Cancelled are their bills a supervisor voided. Refunded is money they handed back on returns. Shifts and drawer short / over are the drawers they opened that were closed in these dates; short is money missing from the count.'),
            emptyMessage: __('Nobody rang up a bill in these dates.'),
        );
    }

    /**
     * Every figure for every person who did anything at the till in the period.
     *
     * @return array<int, array<string, int>>
     */
    private function people(Period $period): array
    {
        $people = [];

        $add = function (int $userId, string $figure, int $amount) use (&$people): void {
            $people[$userId] ??= array_fill_keys(self::FIGURES, 0);
            $people[$userId][$figure] += $amount;
        };

        Sale::query()
            ->completed()
            ->whereBetween('sold_at', $period->range())
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as bills, SUM(total_paisa) as sales, SUM(discount_paisa) as discount')
            ->toBase()
            ->get()
            ->each(function (object $row) use ($add): void {
                foreach (['bills', 'sales', 'discount'] as $figure) {
                    $add((int) $row->user_id, $figure, (int) $row->{$figure});
                }
            });

        Sale::query()
            ->where('status', SaleStatus::Void)
            ->whereBetween('sold_at', $period->range())
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as voided')
            ->toBase()
            ->get()
            ->each(fn (object $row) => $add((int) $row->user_id, 'voided', (int) $row->voided));

        SaleReturn::query()
            ->whereBetween('returned_at', $period->range())
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(total_paisa) as paisa')
            ->toBase()
            ->get()
            ->each(fn (object $row) => $add((int) $row->user_id, 'returns', (int) $row->paisa));

        DrawerSession::query()
            ->where('status', DrawerStatus::Closed)
            ->whereBetween('closed_at', $period->range())
            ->groupBy('opened_by')
            ->selectRaw('opened_by, COUNT(*) as drawers, SUM(variance_paisa) as variance, SUM(CASE WHEN variance_paisa < 0 THEN 1 ELSE 0 END) as short_drawers')
            ->toBase()
            ->get()
            ->each(function (object $row) use ($add): void {
                $add((int) $row->opened_by, 'drawers', (int) $row->drawers);
                $add((int) $row->opened_by, 'variance', (int) $row->variance);
                $add((int) $row->opened_by, 'short_drawers', (int) $row->short_drawers);
            });

        return $people;
    }
}
