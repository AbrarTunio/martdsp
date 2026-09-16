<?php

namespace App\Support\Insights\Packs;

use App\Enums\DrawerEntryType;
use App\Enums\DrawerStatus;
use App\Enums\InsightSeverity;
use App\Enums\SaleStatus;
use App\Models\DrawerSession;
use App\Models\DrawerTransaction;
use App\Models\Sale;
use App\Models\User;
use App\Support\Insights\Finding;
use App\Support\Insights\InsightScope;
use App\Support\Insights\MetricPack;
use Illuminate\Support\Collection;

/**
 * The cash drawers: whether the counts match, who keeps coming up short,
 * who cancels more bills than everyone else, and drawers that hold too
 * much cash or were never closed.
 */
final class DrawerPack extends Pack
{
    /**
     * A drawer open this long was almost certainly forgotten at closing time.
     */
    private const LEFT_OPEN_HOURS = 18;

    /**
     * Cash sitting in a drawer beyond this is worth moving to the safe.
     */
    private const DROP_CASH = 50_000_00;

    /**
     * Short on this many shifts, and on at least half of them, is a pattern
     * rather than bad luck.
     */
    private const PERSISTENT_SHORTS = 3;

    /**
     * Fewer cancelled bills than this is too few to call anyone out.
     */
    private const MIN_VOIDS = 3;

    public function build(InsightScope $scope): MetricPack
    {
        $period = $scope->period('last_30_days');

        $closed = DrawerSession::query()
            ->with(['opener:id,name', 'register:id,name'])
            ->where('status', DrawerStatus::Closed)
            ->whereBetween('closed_at', $period->range())
            ->get();

        $open = DrawerSession::query()->open()->with(['opener:id,name', 'register:id,name'])->get();
        $awaiting = DrawerSession::query()->awaitingApproval()->count();

        $shorts = $closed->filter(fn (DrawerSession $session): bool => $session->variance_paisa < 0);
        $overs = $closed->filter(fn (DrawerSession $session): bool => $session->variance_paisa > 0);
        $people = $this->people($closed);
        $voids = $this->voidsByPerson($period->range());

        $paidOut = (int) DrawerTransaction::query()
            ->where('type', DrawerEntryType::PayOut)
            ->whereBetween('created_at', $period->range())
            ->sum('amount_paisa');

        $findings = array_values(array_filter([
            $this->persistentShort($people),
            $this->largeVariance($closed),
            $this->voidRate($voids),
            $awaiting > 0 ? new Finding(
                key: 'awaiting_approval',
                severity: InsightSeverity::Medium,
                title: trans_choice('{1} One drawer is waiting to be signed off|[2,*] :count drawers are waiting to be signed off', $awaiting, ['count' => $awaiting]),
                detail: __('These closed with a gap bigger than the shop allows, and nobody has looked at them yet.'),
                action: __('Open each one, read the reason given, and approve it or talk to the person who closed it.'),
                href: route('drawer.index'),
            ) : null,
            $this->leftOpen($open),
            $this->dropCash($open),
            $closed->count() >= 5 && $shorts->isEmpty() && $this->largest($closed) < (int) self::threshold('variance_alert') * 100
                ? new Finding(
                    key: 'counts_match',
                    severity: InsightSeverity::Good,
                    title: __('The drawer counts are matching'),
                    detail: __('None of the :count shifts closed in these dates came up short.', ['count' => $closed->count()]),
                    action: __('Keep counting blind at every close; it is what keeps the counts honest.'),
                )
                : null,
        ]));

        return new MetricPack(
            page: 'drawer',
            title: __('Cash drawers'),
            scopeLabel: $period->label(),
            summary: __(':count shifts closed. :shorts came up short and :overs over, a net gap of :net.', [
                'count' => $closed->count(),
                'shorts' => $shorts->count(),
                'overs' => $overs->count(),
                'net' => self::money((int) $closed->sum('variance_paisa')),
            ]),
            facts: [
                'shifts_closed' => $closed->count(),
                'net_short_or_over' => self::money((int) $closed->sum('variance_paisa')),
                'shifts_short' => $shorts->count(),
                'total_short' => self::money((int) -$shorts->sum('variance_paisa')),
                'shifts_over' => $overs->count(),
                'total_over' => self::money((int) $overs->sum('variance_paisa')),
                'cash_paid_out_of_drawers' => self::money(abs($paidOut)),
                'waiting_to_be_signed_off' => $awaiting,
                'drawers_open_now' => $open->map(fn (DrawerSession $session): array => [
                    'counter' => $session->register?->name,
                    'opened_by' => $session->opener?->name,
                    'hours_open' => (int) $session->opened_at->diffInHours(now()),
                    'cash_expected_inside' => self::money($session->expectedCashPaisa()),
                ])->values()->all(),
                'by_person' => collect($people)->map(fn (array $person): array => [
                    'name' => $person['name'],
                    'shifts' => $person['shifts'],
                    'short_shifts' => $person['shorts'],
                    'net_short_or_over' => self::money($person['variance']),
                ])->values()->all(),
                'cancelled_bills_by_person' => collect($voids['people'])->map(fn (array $person): array => [
                    'name' => $person['name'],
                    'cancelled' => $person['voided'],
                    'out_of' => $person['voided'] + $person['bills'],
                ])->values()->all(),
            ],
            findings: $findings,
        );
    }

    /**
     * @param  Collection<int, DrawerSession>  $closed
     * @return list<array{name: string, shifts: int, shorts: int, variance: int}>
     */
    private function people(Collection $closed): array
    {
        return $closed
            ->groupBy('opened_by')
            ->map(fn (Collection $sessions): array => [
                'name' => $sessions->first()->opener?->name ?? __('Removed user'),
                'shifts' => $sessions->count(),
                'shorts' => $sessions->filter(fn (DrawerSession $session): bool => $session->variance_paisa < 0)->count(),
                'variance' => (int) $sessions->sum('variance_paisa'),
            ])
            ->sortBy('variance')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{name: string, shifts: int, shorts: int, variance: int}>  $people
     */
    private function persistentShort(array $people): ?Finding
    {
        $flagged = array_values(array_filter($people, fn (array $person): bool => $person['shorts'] >= self::PERSISTENT_SHORTS
            && $person['shifts'] <= $person['shorts'] * 2
            && $person['variance'] < 0));

        if ($flagged === []) {
            return null;
        }

        $worst = $flagged[0];

        return new Finding(
            key: 'persistent_short',
            severity: InsightSeverity::High,
            title: __(':name\'s drawer keeps coming up short', ['name' => $worst['name']]),
            detail: __('Short on :shorts of :shifts shifts, :money missing in all.', [
                'shorts' => $worst['shorts'],
                'shifts' => $worst['shifts'],
                'money' => self::money(-$worst['variance']),
            ]).(count($flagged) > 1 ? ' '.__('Also: :names.', ['names' => self::names(array_column(array_slice($flagged, 1), 'name'))]) : ''),
            action: __('Watch their next few closes yourself, check their cancelled bills and refunds, and make sure nobody else uses their drawer.'),
            impactPaisa: -(int) array_sum(array_column($flagged, 'variance')),
            href: route('reports.show', 'cashiers'),
            examples: array_column($flagged, 'name'),
        );
    }

    /**
     * @param  Collection<int, DrawerSession>  $closed
     */
    private function largeVariance(Collection $closed): ?Finding
    {
        $alert = (int) self::threshold('variance_alert') * 100;
        $big = $closed
            ->filter(fn (DrawerSession $session): bool => abs((int) $session->variance_paisa) >= $alert)
            ->sortBy(fn (DrawerSession $session): int => -abs((int) $session->variance_paisa));

        if ($alert <= 0 || $big->isEmpty()) {
            return null;
        }

        $worst = $big->first();

        return new Finding(
            key: 'large_variance',
            severity: InsightSeverity::Medium,
            title: trans_choice('{1} One shift was off by :alert or more|[2,*] :count shifts were off by :alert or more', $big->count(), ['count' => $big->count(), 'alert' => self::money($alert)]),
            detail: __('The biggest: :money :direction on :counter, closed by :name on :date.', [
                'money' => self::money(abs((int) $worst->variance_paisa)),
                'direction' => $worst->variance_paisa < 0 ? __('short') : __('over'),
                'counter' => $worst->register?->name ?? __('a counter'),
                'name' => $worst->opener?->name ?? __('a removed user'),
                'date' => $worst->closed_at->format('j M'),
            ]),
            action: __('An over is as worth checking as a short: it usually means a sale was not rung up.'),
            impactPaisa: (int) $big->sum(fn (DrawerSession $session): int => abs((int) $session->variance_paisa)),
            href: route('drawer.show', $worst),
        );
    }

    /**
     * Completed and cancelled bills per person, and the shop's rate overall.
     *
     * @param  array{0: mixed, 1: mixed}  $range
     * @return array{rate: float, people: list<array{name: string, bills: int, voided: int}>}
     */
    private function voidsByPerson(array $range): array
    {
        $rows = Sale::query()
            ->whereIn('status', [SaleStatus::Completed, SaleStatus::Void])
            ->whereBetween('sold_at', $range)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as voided, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as bills', [SaleStatus::Void->value, SaleStatus::Completed->value])
            ->toBase()
            ->get();

        $names = User::query()->whereKey($rows->pluck('user_id'))->pluck('name', 'id');
        $voided = (int) $rows->sum('voided');
        $all = $voided + (int) $rows->sum('bills');

        return [
            'rate' => $all > 0 ? $voided / $all : 0.0,
            'people' => $rows
                ->filter(fn (object $row): bool => (int) $row->voided > 0)
                ->sortByDesc('voided')
                ->map(fn (object $row): array => [
                    'name' => $names->get($row->user_id) ?? __('Removed user'),
                    'bills' => (int) $row->bills,
                    'voided' => (int) $row->voided,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array{rate: float, people: list<array{name: string, bills: int, voided: int}>}  $voids
     */
    private function voidRate(array $voids): ?Finding
    {
        $multiple = (float) self::threshold('void_rate_multiple');

        $flagged = array_values(array_filter($voids['people'], function (array $person) use ($voids, $multiple): bool {
            $rate = $person['voided'] / max(1, $person['voided'] + $person['bills']);

            return $person['voided'] >= self::MIN_VOIDS && $voids['rate'] > 0 && $rate >= $voids['rate'] * $multiple;
        }));

        if ($flagged === []) {
            return null;
        }

        $worst = $flagged[0];
        $rate = $worst['voided'] / max(1, $worst['voided'] + $worst['bills']) * 100;

        return new Finding(
            key: 'void_rate',
            severity: InsightSeverity::Medium,
            title: __(':name cancels far more bills than the rest of the shop', ['name' => $worst['name']]),
            detail: __(':voided of their :all bills were cancelled (:rate), against :shop across the shop.', [
                'voided' => $worst['voided'],
                'all' => $worst['voided'] + $worst['bills'],
                'rate' => self::percent($rate),
                'shop' => self::percent($voids['rate'] * 100),
            ]),
            action: __('Look at the cancelled bills and their reasons. Cancelling after taking the cash is a common way money goes missing.'),
            href: route('reports.show', 'cashiers'),
            examples: array_column($flagged, 'name'),
        );
    }

    /**
     * @param  Collection<int, DrawerSession>  $open
     */
    private function leftOpen(Collection $open): ?Finding
    {
        $stale = $open->filter(fn (DrawerSession $session): bool => $session->opened_at->lt(now()->subHours(self::LEFT_OPEN_HOURS)));

        if ($stale->isEmpty()) {
            return null;
        }

        $oldest = $stale->sortBy('opened_at')->first();

        return new Finding(
            key: 'left_open',
            severity: InsightSeverity::Medium,
            title: trans_choice('{1} A drawer has been open since :date|[2,*] :count drawers have been open for more than a day', $stale->count(), ['count' => $stale->count(), 'date' => $oldest->opened_at->format('j M, g:ia')]),
            detail: __('A drawer that is never closed is never counted, so a gap can hide in it for days.'),
            action: __('Count and close it now, then open a fresh one with the float.'),
            href: route('drawer.show', $oldest),
        );
    }

    /**
     * @param  Collection<int, DrawerSession>  $open
     */
    private function dropCash(Collection $open): ?Finding
    {
        $heavy = $open
            ->map(fn (DrawerSession $session): array => ['session' => $session, 'cash' => $session->expectedCashPaisa()])
            ->filter(fn (array $row): bool => $row['cash'] > self::DROP_CASH)
            ->sortByDesc('cash');

        if ($heavy->isEmpty()) {
            return null;
        }

        $top = $heavy->first();

        return new Finding(
            key: 'drop_cash',
            severity: $top['cash'] > self::DROP_CASH * 2 ? InsightSeverity::Medium : InsightSeverity::Low,
            title: __(':money is sitting in the :counter drawer', ['money' => self::money($top['cash']), 'counter' => $top['session']->register?->name ?? __('open')]),
            detail: __('The more cash in a drawer, the more there is to lose to a mistake or a theft.'),
            action: __('Take some out to the safe and record it as a safe drop, so the count still matches at closing.'),
            impactPaisa: $top['cash'],
            href: route('drawer.show', $top['session']),
        );
    }

    /**
     * @param  Collection<int, DrawerSession>  $closed
     */
    private function largest(Collection $closed): int
    {
        return (int) $closed->max(fn (DrawerSession $session): int => abs((int) $session->variance_paisa));
    }
}
