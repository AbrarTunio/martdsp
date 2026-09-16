<?php

namespace App\Support\Insights\Packs;

use App\Enums\InsightSeverity;
use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\PurchaseSuggestionService;
use App\Support\Insights\Finding;
use App\Support\Insights\InsightScope;
use App\Support\Insights\MetricPack;
use App\Support\Reports\Period;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Buying: what came in from whom, what the shop owes its suppliers and
 * when, costs that have crept up, and what needs ordering now.
 */
final class BuyingPack extends Pack
{
    /**
     * A draft older than this was probably forgotten, or the delivery
     * never came.
     */
    private const STALE_DRAFT_DAYS = 3;

    /**
     * Bills due within this many days are worth planning cash for.
     */
    private const DUE_SOON_DAYS = 7;

    /**
     * How far back to look for the previous price of an item.
     */
    private const PRICE_HISTORY_DAYS = 180;

    public function __construct(private readonly PurchaseSuggestionService $suggestions) {}

    public function build(InsightScope $scope): MetricPack
    {
        $period = $scope->chosenPeriod ?? Period::between(today()->subDays(89), today());

        $received = Purchase::query()
            ->where('status', PurchaseStatus::Received)
            ->whereBetween('purchase_date', $period->range());

        $bought = (int) (clone $received)->sum('total_paisa');
        $deliveries = (clone $received)->count();

        $topSuppliers = (clone $received)
            ->whereNotNull('supplier_id')
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COUNT(*) as deliveries, SUM(total_paisa) as paisa')
            ->orderByDesc('paisa')
            ->limit(5)
            ->toBase()
            ->get();

        $supplierNames = Supplier::query()->whereKey($topSuppliers->pluck('supplier_id'))->get()->keyBy('id');

        $owed = Supplier::query()->owed()->orderByDesc('balance_paisa')->get();
        $overdue = $owed
            ->map(fn (Supplier $supplier): array => ['supplier' => $supplier, 'overdue' => $supplier->overduePaisa()])
            ->filter(fn (array $row): bool => $row['overdue'] > 0)
            ->sortByDesc('overdue')
            ->values();

        $creep = $this->priceCreep($period);
        $reorder = $this->suggestions->grouped();
        $reorderLines = (int) $reorder->sum(fn (array $group): int => $group['lines']->count());

        $findings = array_values(array_filter([
            $this->overduePayables($overdue),
            $this->priceCreepFinding($creep),
            $reorderLines > 0 ? new Finding(
                key: 'reorder',
                severity: InsightSeverity::Medium,
                title: trans_choice('{1} One item needs ordering|[2,*] :count items need ordering', $reorderLines, ['count' => $reorderLines]),
                detail: __('They are at or below their reorder level. Ordering all of them would cost about :money.', ['money' => self::money((int) $reorder->sum('total_paisa'))]),
                action: __('Open the reorder list; it groups the items by the supplier you last bought them from, ready to turn into an order.'),
                impactPaisa: (int) $reorder->sum('total_paisa'),
                href: route('purchases.suggestions.index'),
            ) : null,
            $this->staleDrafts(),
            $this->dueSoon(),
        ]));

        return new MetricPack(
            page: 'buying',
            title: __('Buying and suppliers'),
            scopeLabel: $period->label(),
            summary: __(':count deliveries worth :bought came in. The shop owes its suppliers :owed, of which :overdue is past due.', [
                'count' => number_format($deliveries),
                'bought' => self::money($bought),
                'owed' => self::money((int) $owed->sum('balance_paisa')),
                'overdue' => self::money((int) $overdue->sum('overdue')),
            ]),
            facts: [
                'deliveries_received' => $deliveries,
                'bought' => self::money($bought),
                'top_suppliers' => $topSuppliers->map(fn (object $row): array => [
                    'name' => $supplierNames->get($row->supplier_id)?->displayName() ?? __('Removed supplier'),
                    'deliveries' => (int) $row->deliveries,
                    'bought' => self::money((int) $row->paisa),
                ])->values()->all(),
                'owed_to_suppliers' => self::money((int) $owed->sum('balance_paisa')),
                'past_due' => self::money((int) $overdue->sum('overdue')),
                'biggest_balances' => $owed->take(5)->map(fn (Supplier $supplier): array => [
                    'name' => $supplier->displayName(),
                    'owed' => self::money($supplier->balance_paisa),
                    'payment_terms_days' => $supplier->payment_terms_days,
                ])->values()->all(),
                'items_to_reorder' => $reorderLines,
                'items_costing_more_than_last_time' => count($creep),
            ],
            findings: $findings,
        );
    }

    /**
     * @param  Collection<int, array{supplier: Supplier, overdue: int}>  $overdue
     */
    private function overduePayables(Collection $overdue): ?Finding
    {
        if ($overdue->isEmpty()) {
            return null;
        }

        $names = $overdue->map(fn (array $row): string => $row['supplier']->displayName());

        return new Finding(
            key: 'overdue_payables',
            severity: InsightSeverity::High,
            title: __(':money owed to suppliers is past due', ['money' => self::money((int) $overdue->sum('overdue'))]),
            detail: __('Late with :names. A supplier who is paid late stops giving credit, or quietly raises prices.', ['names' => self::names($names)]),
            action: __('Pay the oldest bills first, or call and agree a new date before they call you.'),
            impactPaisa: (int) $overdue->sum('overdue'),
            href: route('suppliers.index'),
            examples: $names->take(5)->values()->all(),
        );
    }

    /**
     * Items whose latest delivery cost noticeably more than the one before.
     *
     * @return list<array{product_id: int, name: string, before: int, now: int, rise: float, price: int|null}>
     */
    private function priceCreep(Period $period): array
    {
        $rise = (float) self::threshold('price_rise_percent');

        $rows = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('products', 'products.id', '=', 'purchase_items.product_id')
            ->leftJoin('product_units', function ($join): void {
                $join->on('product_units.product_id', '=', 'products.id')->where('product_units.is_base', true);
            })
            ->where('purchases.status', PurchaseStatus::Received->value)
            ->where('purchase_items.cost_base_paisa', '>', 0)
            ->whereBetween('purchases.purchase_date', [$period->from->subDays(self::PRICE_HISTORY_DAYS), $period->to->endOfDay()])
            ->orderBy('purchase_items.product_id')
            ->orderByDesc('purchases.purchase_date')
            ->orderByDesc('purchase_items.id')
            ->get([
                'purchase_items.product_id',
                'products.name',
                'purchase_items.cost_base_paisa',
                'purchases.purchase_date',
                'product_units.sale_price_paisa',
            ])
            ->groupBy('product_id');

        $from = $period->from->startOfDay();
        $found = [];

        foreach ($rows as $productId => $lines) {
            $latest = $lines->first();
            $previous = $lines->first(fn (object $line): bool => $line !== $latest && (int) $line->cost_base_paisa !== (int) $latest->cost_base_paisa);

            if ($previous === null || CarbonImmutable::parse($latest->purchase_date)->lt($from)) {
                continue;
            }

            $change = self::change((int) $latest->cost_base_paisa, (int) $previous->cost_base_paisa);

            if ($change !== null && $change >= $rise) {
                $found[] = [
                    'product_id' => (int) $productId,
                    'name' => $latest->name,
                    'before' => (int) $previous->cost_base_paisa,
                    'now' => (int) $latest->cost_base_paisa,
                    'rise' => $change,
                    'price' => $latest->sale_price_paisa !== null ? (int) $latest->sale_price_paisa : null,
                ];
            }
        }

        usort($found, fn (array $a, array $b): int => $b['rise'] <=> $a['rise']);

        return $found;
    }

    /**
     * @param  list<array{product_id: int, name: string, before: int, now: int, rise: float, price: int|null}>  $creep
     */
    private function priceCreepFinding(array $creep): ?Finding
    {
        if ($creep === []) {
            return null;
        }

        $squeezed = array_values(array_filter($creep, fn (array $item): bool => $item['price'] !== null && $item['price'] <= $item['now']));
        $top = $creep[0];

        return new Finding(
            key: 'price_creep',
            severity: $squeezed !== [] ? InsightSeverity::High : InsightSeverity::Medium,
            title: trans_choice('{1} One item costs more than last time|[2,*] :count items cost more than last time', count($creep), ['count' => count($creep)]),
            detail: __('The biggest jump: :name went from :before to :now each (:rise more).', [
                'name' => $top['name'],
                'before' => self::money($top['before']),
                'now' => self::money($top['now']),
                'rise' => self::percent($top['rise']),
            ]).($squeezed !== []
                ? ' '.__(':names now cost as much as, or more than, you sell them for.', ['names' => self::names(array_column($squeezed, 'name'))])
                : ''),
            action: __('Check the selling price of each so the margin holds, and ask the supplier, or another one, for the old rate.'),
            href: route('products.index'),
            examples: array_column(array_slice($creep, 0, 5), 'name'),
        );
    }

    private function staleDrafts(): ?Finding
    {
        $drafts = Purchase::query()
            ->where('status', PurchaseStatus::Draft)
            ->where('created_at', '<', now()->subDays(self::STALE_DRAFT_DAYS))
            ->count();

        if ($drafts === 0) {
            return null;
        }

        return new Finding(
            key: 'stale_drafts',
            severity: InsightSeverity::Low,
            title: trans_choice('{1} One purchase has been a draft for days|[2,*] :count purchases have been drafts for days', $drafts, ['count' => $drafts]),
            detail: __('Stock from a draft is not on the shelf in the system until it is received, so the counts will be wrong.'),
            action: __('Receive the ones that arrived and cancel the ones that never will.'),
            href: route('purchases.index', ['status' => PurchaseStatus::Draft->value]),
        );
    }

    private function dueSoon(): ?Finding
    {
        $bills = Purchase::query()
            ->where('status', PurchaseStatus::Received)
            ->whereColumn('paid_paisa', '<', 'total_paisa')
            ->whereBetween('due_on', [today(), today()->addDays(self::DUE_SOON_DAYS)->endOfDay()])
            ->get(['id', 'total_paisa', 'paid_paisa']);

        if ($bills->isEmpty()) {
            return null;
        }

        $money = (int) $bills->sum(fn (Purchase $purchase): int => $purchase->total_paisa - $purchase->paid_paisa);

        return new Finding(
            key: 'due_soon',
            severity: InsightSeverity::Low,
            title: __(':money in supplier bills falls due this week', ['money' => self::money($money)]),
            detail: trans_choice('{1} One bill is due in the next :days days.|[2,*] :count bills are due in the next :days days.', $bills->count(), ['count' => $bills->count(), 'days' => self::DUE_SOON_DAYS]),
            action: __('Keep that much cash aside from the drawer takings.'),
            impactPaisa: $money,
            href: route('suppliers.index'),
        );
    }
}
