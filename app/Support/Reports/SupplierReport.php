<?php

namespace App\Support\Reports;

use App\Enums\PurchaseStatus;
use App\Enums\SupplierEntryType;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Who the shop buys from, how much, and what is still owed to each.
 *
 * Bought is the deliveries received in the dates; returned is goods sent
 * back; paid is money handed over. The balance is what is owed right now,
 * whatever the dates say — the question every supplier's salesman asks.
 * A supplier with nothing in the dates still shows when money is owed.
 */
final class SupplierReport extends Report
{
    /**
     * @var list<string>
     */
    private const FIGURES = ['deliveries', 'bought', 'returned', 'paid'];

    public function title(): string
    {
        return __('Suppliers');
    }

    public function description(): string
    {
        return __('What was bought from each supplier, returned, paid, and what is owed to them now.');
    }

    public function icon(): string
    {
        return 'truck';
    }

    public function build(Period $period, array $filters): ReportResult
    {
        $activity = $this->activity($period);
        $before = $this->activity($period->previous());
        $against = $period->previous()->label();

        $suppliers = Supplier::query()
            ->where(fn ($query) => $query->whereKey(array_keys($activity))->orWhere('balance_paisa', '!=', 0))
            ->get(['id', 'name', 'company', 'balance_paisa']);

        $lastDelivery = Purchase::query()
            ->where('status', PurchaseStatus::Received)
            ->whereIn('supplier_id', $suppliers->modelKeys())
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, MAX(received_at) as last_delivery')
            ->toBase()
            ->pluck('last_delivery', 'supplier_id');

        $rows = [];

        foreach ($suppliers as $supplier) {
            $figures = $activity[$supplier->id] ?? array_fill_keys(self::FIGURES, 0);
            $last = $lastDelivery->get($supplier->id);

            $rows[] = [
                'name' => $supplier->name,
                'deliveries' => $figures['deliveries'],
                'bought_paisa' => $figures['bought'],
                'returned_paisa' => $figures['returned'],
                'paid_paisa' => $figures['paid'],
                'balance_paisa' => $supplier->balance_paisa,
                'last_delivery' => $last === null ? null : CarbonImmutable::parse($last),
                '_detail' => $supplier->balance_paisa < 0 ? __('Owes you money') : $supplier->company,
                '_link' => route('suppliers.show', $supplier),
                '_tone' => $supplier->balance_paisa > 0 && $figures['paid'] === 0 && $figures['bought'] > 0 ? 'warning' : null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['bought_paisa'] <=> $a['bought_paisa']
            ?: $b['balance_paisa'] <=> $a['balance_paisa']
            ?: strcmp($a['name'], $b['name']));

        $total = $this->sum($activity);
        $totalBefore = $this->sum($before);
        $owed = (int) $suppliers->where('balance_paisa', '>', 0)->sum('balance_paisa');
        $top = array_slice(array_filter($rows, fn (array $row): bool => $row['bought_paisa'] > 0 || $row['paid_paisa'] > 0), 0, 10);

        return new ReportResult(
            columns: [
                Column::text('name', __('Supplier')),
                Column::number('deliveries', __('Deliveries')),
                Column::money('bought_paisa', __('Bought'), emphasis: true),
                Column::money('returned_paisa', __('Returned')),
                Column::money('paid_paisa', __('Paid')),
                Column::money('balance_paisa', __('Owed now')),
                Column::date('last_delivery', __('Last delivery')),
            ],
            rows: $rows,
            totals: [
                'deliveries' => $total['deliveries'],
                'bought_paisa' => $total['bought'],
                'returned_paisa' => $total['returned'],
                'paid_paisa' => $total['paid'],
                'balance_paisa' => (int) $suppliers->sum('balance_paisa'),
            ],
            stats: [
                [
                    'label' => __('Bought'),
                    'value' => Money::rounded($total['bought']),
                    'icon' => 'truck',
                    'hint' => Trend::describe($total['bought'], $totalBefore['bought'], $against),
                ],
                [
                    'label' => __('Paid to suppliers'),
                    'value' => Money::rounded($total['paid']),
                    'icon' => 'wallet',
                    'hint' => Trend::describe($total['paid'], $totalBefore['paid'], $against),
                ],
                [
                    'label' => __('Returned to suppliers'),
                    'value' => Money::rounded($total['returned']),
                    'icon' => 'box',
                ],
                [
                    'label' => __('Owed to suppliers now'),
                    'value' => Money::rounded($owed),
                    'icon' => 'book',
                    'hint' => trans_choice('{0} Nobody is owed|{1} Owed to one supplier|[2,*] Owed to :count suppliers', $suppliers->where('balance_paisa', '>', 0)->count(), ['count' => $suppliers->where('balance_paisa', '>', 0)->count()]),
                    'tone' => $owed > 0 ? 'warning' : 'success',
                ],
            ],
            chart: $top === [] ? null : [
                'type' => 'bar',
                'money' => true,
                'labels' => array_column($top, 'name'),
                'datasets' => [
                    ['label' => __('Bought'), 'data' => array_column($top, 'bought_paisa'), 'color' => 'sky'],
                    ['label' => __('Paid'), 'data' => array_column($top, 'paid_paisa'), 'color' => 'brand'],
                ],
            ],
            footnote: __('Bought is deliveries received in these dates, returned is goods sent back, paid is money handed over. Owed now is today\'s balance whatever the dates; a supplier in orange was bought from but not paid in these dates.'),
            emptyMessage: __('Nothing was bought, returned or paid in these dates, and no supplier is owed money.'),
        );
    }

    /**
     * @return array<int, array<string, int>>
     */
    private function activity(Period $period): array
    {
        $suppliers = [];

        $add = function (?int $supplierId, string $figure, int $amount) use (&$suppliers): void {
            if ($supplierId === null) {
                return;
            }

            $suppliers[$supplierId] ??= array_fill_keys(self::FIGURES, 0);
            $suppliers[$supplierId][$figure] += $amount;
        };

        Purchase::query()
            ->where('status', PurchaseStatus::Received)
            ->whereBetween('received_at', $period->range())
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COUNT(*) as deliveries, SUM(total_paisa) as bought')
            ->toBase()
            ->get()
            ->each(function (object $row) use ($add): void {
                $supplierId = $row->supplier_id === null ? null : (int) $row->supplier_id;
                $add($supplierId, 'deliveries', (int) $row->deliveries);
                $add($supplierId, 'bought', (int) $row->bought);
            });

        PurchaseReturn::query()
            ->whereBetween('returned_at', $period->range())
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, SUM(total_paisa) as returned')
            ->toBase()
            ->get()
            ->each(fn (object $row) => $add($row->supplier_id === null ? null : (int) $row->supplier_id, 'returned', (int) $row->returned));

        SupplierLedgerEntry::query()
            ->where('type', SupplierEntryType::Payment)
            ->whereBetween('entry_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, SUM(debit_paisa) as paid')
            ->toBase()
            ->get()
            ->each(fn (object $row) => $add((int) $row->supplier_id, 'paid', (int) $row->paid));

        return $suppliers;
    }

    /**
     * @param  array<int, array<string, int>>  $suppliers
     * @return array<string, int>
     */
    private function sum(array $suppliers): array
    {
        $total = array_fill_keys(self::FIGURES, 0);

        foreach ($suppliers as $figures) {
            foreach (self::FIGURES as $figure) {
                $total[$figure] += $figures[$figure];
            }
        }

        return $total;
    }
}
