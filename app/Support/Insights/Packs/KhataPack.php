<?php

namespace App\Support\Insights\Packs;

use App\Enums\CustomerEntryType;
use App\Enums\InsightSeverity;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Sale;
use App\Support\Insights\Finding;
use App\Support\Insights\InsightScope;
use App\Support\Insights\MetricPack;
use App\Support\KhataAging;
use App\Support\Reports\DailySalesReport;
use App\Support\Reports\Period;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The khata: who owes what, how old it is, whether it is being paid back
 * as fast as it is given, and which regulars have gone quiet.
 *
 * On one customer's page the figures are about that customer alone. Only
 * names reach the AI — never a phone number or an address.
 */
final class KhataPack extends Pack
{
    /**
     * The top three owing more than this share of the whole khata is a lot
     * of eggs in few baskets.
     */
    private const CONCENTRATION_ALERT = 50.0;

    /**
     * Someone who bought this many times in the three months before last
     * month is a regular.
     */
    private const REGULAR_VISITS = 3;

    public function __construct(private readonly DailySalesReport $sales) {}

    public function build(InsightScope $scope): MetricPack
    {
        return $scope->customer ? $this->forCustomer($scope->customer) : $this->forShop();
    }

    private function forShop(): MetricPack
    {
        $customers = Customer::query()->owing()->orderByDesc('balance_paisa')->get(['id', 'name', 'balance_paisa', 'credit_limit_paisa']);
        $agings = KhataAging::forMany($customers);
        $buckets = KhataAging::totals($agings);
        $owed = (int) $customers->sum('balance_paisa');

        $month = CarbonImmutable::today()->subDays(29);
        $given = $this->ledgerSum(CustomerEntryType::SaleCredit, 'debit_paisa', $month);
        $collected = $this->ledgerSum(CustomerEntryType::Payment, 'credit_paisa', $month);
        $sales30 = (int) $this->sales->totals(Period::preset('last_30_days'))['net_paisa'];
        $share = $sales30 > 0 ? round($owed / $sales30 * 100, 1) : null;

        $findings = array_values(array_filter([
            $this->overNinety($customers, $agings),
            $this->overLimit($customers),
            $this->paymentGap($customers, $agings),
            $this->debtGrowth($given, $collected),
            $this->receivablesShare($owed, $share),
            $this->concentration($customers, $owed),
            $this->lapsedRegulars(),
            $owed > 0 && $buckets['31_60'] + $buckets['61_90'] + $buckets['over_90'] === 0
                ? new Finding(
                    key: 'khata_on_time',
                    severity: InsightSeverity::Good,
                    title: __('The khata is being paid on time'),
                    detail: __('Nothing on the khata is more than 30 days late.'),
                    action: __('Keep sending reminders the day a payment falls due.'),
                )
                : null,
        ]));

        $labels = KhataAging::labels();

        return new MetricPack(
            page: 'khata',
            title: __('Khata'),
            scopeLabel: __('The khata today; money given and collected over the last 30 days'),
            summary: __(':count customers owe :owed. In the last 30 days :given was given on khata and :collected was collected.', [
                'count' => number_format($customers->count()),
                'owed' => self::money($owed),
                'given' => self::money($given),
                'collected' => self::money($collected),
            ]),
            facts: [
                'customers_owing' => $customers->count(),
                'total_owed' => self::money($owed),
                'owed_by_age' => collect($buckets)->mapWithKeys(fn (int $paisa, string $bucket): array => [$labels[$bucket] => self::money($paisa)])->all(),
                'given_on_khata_last_30_days' => self::money($given),
                'collected_last_30_days' => self::money($collected),
                'net_sales_last_30_days' => self::money($sales30),
                'khata_as_share_of_a_month_of_sales' => self::percent($share),
                'biggest_balances' => $customers->take(5)->map(fn (Customer $customer): array => [
                    'name' => $customer->name,
                    'owes' => self::money($customer->balance_paisa),
                    'days_late' => $agings[$customer->id]->daysLate(),
                ])->values()->all(),
            ],
            findings: $findings,
        );
    }

    private function forCustomer(Customer $customer): MetricPack
    {
        $aging = KhataAging::for($customer);
        $since = CarbonImmutable::today()->subDays(89);

        $entries = $customer->ledgerEntries()->where('entry_date', '>=', $since)->get(['type', 'debit_paisa', 'credit_paisa', 'entry_date']);
        $bought = (int) $entries->where('type', CustomerEntryType::SaleCredit)->sum('debit_paisa');
        $paid = (int) $entries->where('type', CustomerEntryType::Payment)->sum('credit_paisa');

        $lastPayment = $customer->ledgerEntries()->where('type', CustomerEntryType::Payment)->latest('entry_date')->latest('id')->first(['credit_paisa', 'entry_date']);
        $lastBill = Sale::query()->completed()->where('customer_id', $customer->id)->latest('sold_at')->first(['total_paisa', 'sold_at']);

        $findings = [];
        $labels = KhataAging::labels();

        if ($customer->hasCreditLimit() && $customer->balance_paisa > $customer->credit_limit_paisa) {
            $findings[] = new Finding(
                key: 'over_limit',
                severity: InsightSeverity::High,
                title: __('Over their khata limit'),
                detail: __('They owe :owed against a limit of :limit.', ['owed' => self::money($customer->balance_paisa), 'limit' => self::money($customer->credit_limit_paisa)]),
                action: __('Take a payment before giving anything more on khata.'),
                impactPaisa: $customer->balance_paisa - $customer->credit_limit_paisa,
            );
        }

        if ($aging->paisa('over_90') > 0 || $aging->paisa('61_90') > 0) {
            $late = $aging->paisa('over_90') + $aging->paisa('61_90');

            $findings[] = new Finding(
                key: 'overdue',
                severity: $aging->paisa('over_90') > 0 ? InsightSeverity::High : InsightSeverity::Medium,
                title: __(':money is more than two months late', ['money' => self::money($late)]),
                detail: __('The oldest unpaid purchase fell due :days days ago. The older a khata gets, the less of it comes back.', ['days' => $aging->daysLate()]),
                action: __('Send a reminder today and agree a date for a first part-payment.'),
                impactPaisa: $late,
                href: route('customers.reminder', $customer),
            );
        } elseif ($aging->isOverdue()) {
            $findings[] = new Finding(
                key: 'overdue',
                severity: InsightSeverity::Low,
                title: __(':money is past its due date', ['money' => self::money($aging->overduePaisa())]),
                detail: __('The oldest unpaid purchase fell due :days days ago.', ['days' => $aging->daysLate()]),
                action: __('A friendly reminder now is easier than a hard one later.'),
                impactPaisa: $aging->overduePaisa(),
                href: route('customers.reminder', $customer),
            );
        }

        $gap = (int) self::threshold('payment_gap_days');

        if ($customer->balance_paisa > 0 && ($lastPayment === null || $lastPayment->entry_date->lt(today()->subDays($gap)))) {
            $findings[] = new Finding(
                key: 'payment_gap',
                severity: InsightSeverity::Medium,
                title: $lastPayment === null ? __('Has never made a payment') : __('No payment for :days days', ['days' => (int) $lastPayment->entry_date->diffInDays(today())]),
                detail: __('They owe :owed and have not paid anything towards it in :gap days or more.', ['owed' => self::money($customer->balance_paisa), 'gap' => $gap]),
                action: __('Call them, and hold further khata until something is paid.'),
                impactPaisa: $customer->balance_paisa,
            );
        }

        if ($bought > 0 && $paid >= $bought && ! $aging->isOverdue()) {
            $findings[] = new Finding(
                key: 'good_payer',
                severity: InsightSeverity::Good,
                title: __('Pays back what they buy'),
                detail: __('In the last 90 days they bought :bought on khata and paid :paid.', ['bought' => self::money($bought), 'paid' => self::money($paid)]),
                action: __('A reliable customer: a slightly higher limit would be safe.'),
            );
        }

        return new MetricPack(
            page: 'khata',
            title: __('Khata'),
            scopeLabel: __('One customer: :name', ['name' => $customer->name]),
            summary: __(':name owes :owed. In the last 90 days they bought :bought on khata and paid :paid.', [
                'name' => $customer->name,
                'owed' => self::money(max(0, $customer->balance_paisa)),
                'bought' => self::money($bought),
                'paid' => self::money($paid),
            ]),
            facts: [
                'customer' => $customer->name,
                'owes' => self::money(max(0, $customer->balance_paisa)),
                'money_left_with_the_shop' => self::money(max(0, -$customer->balance_paisa)),
                'khata_limit' => $customer->hasCreditLimit() ? self::money($customer->credit_limit_paisa) : __('No limit set'),
                'owed_by_age' => collect($aging->buckets)->mapWithKeys(fn (int $paisa, string $bucket): array => [$labels[$bucket] => self::money($paisa)])->all(),
                'bought_on_khata_last_90_days' => self::money($bought),
                'paid_last_90_days' => self::money($paid),
                'last_payment' => $lastPayment ? self::money((int) $lastPayment->credit_paisa).' on '.$lastPayment->entry_date->format('j M Y') : __('Never'),
                'last_bill' => $lastBill ? self::money($lastBill->total_paisa).' on '.$lastBill->sold_at->format('j M Y') : __('Never'),
            ],
            findings: $findings,
        );
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @param  array<int, KhataAging>  $agings
     */
    private function overNinety(Collection $customers, array $agings): ?Finding
    {
        $late = $customers
            ->filter(fn (Customer $customer): bool => $agings[$customer->id]->paisa('over_90') > 0)
            ->sortByDesc(fn (Customer $customer): int => $agings[$customer->id]->paisa('over_90'));

        if ($late->isEmpty()) {
            return null;
        }

        $money = (int) $late->sum(fn (Customer $customer): int => $agings[$customer->id]->paisa('over_90'));
        $names = $late->pluck('name')->values();

        return new Finding(
            key: 'over_90',
            severity: InsightSeverity::High,
            title: __(':money on the khata is more than 90 days late', ['money' => self::money($money)]),
            detail: __('Owed by :names. Money this old is the hardest to get back; every month it waits, less of it returns.', ['names' => self::names($names)]),
            action: __('Call each of them this week and agree a payment plan. Stop further khata until they pay something.'),
            impactPaisa: $money,
            href: route('customers.aging'),
            examples: $names->take(5)->all(),
        );
    }

    /**
     * @param  Collection<int, Customer>  $customers
     */
    private function overLimit(Collection $customers): ?Finding
    {
        $over = $customers->filter(fn (Customer $customer): bool => $customer->hasCreditLimit() && $customer->balance_paisa > $customer->credit_limit_paisa);

        if ($over->isEmpty()) {
            return null;
        }

        $names = $over->pluck('name')->values();

        return new Finding(
            key: 'over_limit',
            severity: InsightSeverity::High,
            title: trans_choice('{1} One customer is over their khata limit|[2,*] :count customers are over their khata limit', $over->count(), ['count' => $over->count()]),
            detail: __(':names owe more than the limit you set for them.', ['names' => self::names($names)]),
            action: __('Take a payment before the next khata sale, or raise the limit only if you trust them with it.'),
            impactPaisa: (int) $over->sum(fn (Customer $customer): int => $customer->balance_paisa - $customer->credit_limit_paisa),
            href: route('customers.index'),
            examples: $names->take(5)->all(),
        );
    }

    /**
     * People who owe, whose debt is already late, and who have paid nothing
     * for a long time.
     *
     * @param  Collection<int, Customer>  $customers
     * @param  array<int, KhataAging>  $agings
     */
    private function paymentGap(Collection $customers, array $agings): ?Finding
    {
        $gap = (int) self::threshold('payment_gap_days');
        $cutoff = CarbonImmutable::today()->subDays($gap);

        $lastPaid = CustomerLedgerEntry::query()
            ->whereIn('customer_id', $customers->modelKeys())
            ->where('type', CustomerEntryType::Payment)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, MAX(entry_date) as last_paid')
            ->toBase()
            ->pluck('last_paid', 'customer_id');

        $silent = $customers->filter(function (Customer $customer) use ($agings, $lastPaid, $cutoff): bool {
            $paid = $lastPaid->get($customer->id);

            return $agings[$customer->id]->isOverdue()
                && $agings[$customer->id]->oldestDueOn?->lt($cutoff)
                && ($paid === null || CarbonImmutable::parse($paid)->lt($cutoff));
        });

        if ($silent->isEmpty()) {
            return null;
        }

        $names = $silent->pluck('name')->values();

        return new Finding(
            key: 'payment_gap',
            severity: InsightSeverity::Medium,
            title: trans_choice('{1} One customer has paid nothing in :days days|[2,*] :count customers have paid nothing in :days days', $silent->count(), ['count' => $silent->count(), 'days' => $gap]),
            detail: __(':names owe money that is already late and have not paid anything towards it in :days days.', ['names' => self::names($names), 'days' => $gap]),
            action: __('Send each of them a reminder from their khata page.'),
            impactPaisa: (int) $silent->sum('balance_paisa'),
            href: route('customers.aging'),
            examples: $names->take(5)->all(),
        );
    }

    private function debtGrowth(int $given, int $collected): ?Finding
    {
        $ratio = (float) self::threshold('debt_growth_ratio');

        if ($given === 0 || $given < $collected * $ratio) {
            return null;
        }

        return new Finding(
            key: 'debt_growth',
            severity: InsightSeverity::Medium,
            title: __('More is going out on khata than is coming back'),
            detail: __('In the last 30 days :given was given on khata but only :collected was collected. The khata grew by :difference.', [
                'given' => self::money($given),
                'collected' => self::money($collected),
                'difference' => self::money(max(0, $given - $collected)),
            ]),
            action: __('Set a limit for each khata customer and ask for part-payment at every visit.'),
            impactPaisa: max(0, $given - $collected),
            href: route('customers.aging'),
        );
    }

    private function receivablesShare(int $owed, ?float $share): ?Finding
    {
        if ($share === null || $share < (float) self::threshold('receivables_share')) {
            return null;
        }

        return new Finding(
            key: 'receivables_share',
            severity: InsightSeverity::Medium,
            title: __('The khata equals :share of a month\'s sales', ['share' => self::percent($share)]),
            detail: __(':owed is owed to the shop. That is cash the shop needs for its next deliveries.', ['owed' => self::money($owed)]),
            action: __('Collect the oldest balances first, and keep new khata small until the total comes down.'),
            impactPaisa: $owed,
            href: route('customers.aging'),
        );
    }

    /**
     * @param  Collection<int, Customer>  $customers
     */
    private function concentration(Collection $customers, int $owed): ?Finding
    {
        if ($customers->count() < 5 || $owed <= 0) {
            return null;
        }

        $top = $customers->take(3);
        $share = round($top->sum('balance_paisa') / $owed * 100, 1);

        if ($share < self::CONCENTRATION_ALERT) {
            return null;
        }

        $names = $top->pluck('name')->values();

        return new Finding(
            key: 'concentration',
            severity: InsightSeverity::Low,
            title: __('Three customers hold :share of the khata', ['share' => self::percent($share)]),
            detail: __(':names owe most of what is on the khata. If one of them stops paying, it hurts.', ['names' => self::names($names)]),
            action: __('Keep a close eye on these three and set a limit for each.'),
            impactPaisa: (int) $top->sum('balance_paisa'),
            href: route('customers.index'),
            examples: $names->all(),
        );
    }

    /**
     * Regular customers who have stopped coming in. Sometimes it is the
     * khata itself that keeps them away.
     */
    private function lapsedRegulars(): ?Finding
    {
        $today = CarbonImmutable::today();

        $regulars = Sale::query()
            ->completed()
            ->whereNotNull('customer_id')
            ->whereBetween('sold_at', [$today->subDays(120), $today->subDays(30)->endOfDay()])
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) >= ?', [self::REGULAR_VISITS])
            ->pluck('customer_id');

        if ($regulars->isEmpty()) {
            return null;
        }

        $recent = Sale::query()
            ->completed()
            ->whereIn('customer_id', $regulars)
            ->where('sold_at', '>', $today->subDays(30)->endOfDay())
            ->distinct()
            ->pluck('customer_id');

        $gone = Customer::query()
            ->active()
            ->whereKey($regulars->diff($recent))
            ->orderByDesc('balance_paisa')
            ->get(['id', 'name', 'balance_paisa']);

        if ($gone->isEmpty()) {
            return null;
        }

        $names = $gone->pluck('name')->values();
        $owing = $gone->where('balance_paisa', '>', 0)->count();

        return new Finding(
            key: 'lapsed',
            severity: InsightSeverity::Low,
            title: trans_choice('{1} A regular has not been in for a month|[2,*] :count regulars have not been in for a month', $gone->count(), ['count' => $gone->count()]),
            detail: $owing > 0
                ? __(':names used to come often. :owing of them still owe money, which is sometimes why a customer stays away.', ['names' => self::names($names), 'owing' => $owing])
                : __(':names used to come often.', ['names' => self::names($names)]),
            action: __('A call to ask how they are often brings a regular back.'),
            href: route('customers.index'),
            examples: $names->take(5)->all(),
        );
    }

    private function ledgerSum(CustomerEntryType $type, string $column, CarbonImmutable $since): int
    {
        return (int) CustomerLedgerEntry::query()
            ->where('type', $type)
            ->where('entry_date', '>=', $since->toDateString())
            ->sum($column);
    }
}
