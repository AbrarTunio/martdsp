<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How old a customer's khata is.
 *
 * Payments settle the oldest purchases first, which is how every shopkeeper
 * in the market reads a khata: the last thing cleared is the oldest thing
 * owed. So the buckets are worked out by walking the khata from its first
 * line, letting every payment, return and write-off eat into the purchases
 * ahead of it, and sorting whatever survives by its due date.
 */
final class KhataAging
{
    /** @var list<string> */
    public const BUCKETS = ['current', '1_30', '31_60', '61_90', 'over_90'];

    /**
     * @param  array<string, int>  $buckets  paisa still owed, keyed by bucket
     * @param  int  $owedPaisa  everything still owed
     * @param  int  $advancePaisa  money the customer has left with the shop
     * @param  Carbon|null  $oldestDueOn  the due date of the oldest unpaid purchase
     */
    private function __construct(
        public readonly array $buckets,
        public readonly int $owedPaisa,
        public readonly int $advancePaisa,
        public readonly ?Carbon $oldestDueOn,
    ) {}

    public static function for(Customer $customer): self
    {
        return self::fromEntries($customer->ledgerEntries()->inLedgerOrder()->get());
    }

    /**
     * One aging per customer, from a single query over their khatas.
     *
     * @param  Collection<int, Customer>  $customers
     * @return array<int, self>
     */
    public static function forMany(Collection $customers): array
    {
        if ($customers->isEmpty()) {
            return [];
        }

        $entries = CustomerLedgerEntry::query()
            ->whereIn('customer_id', $customers->modelKeys())
            ->inLedgerOrder()
            ->get()
            ->groupBy('customer_id');

        return $customers
            ->mapWithKeys(fn (Customer $customer): array => [
                $customer->getKey() => self::fromEntries($entries->get($customer->getKey()) ?? collect()),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, CustomerLedgerEntry>  $entries  in ledger order, oldest first
     */
    public static function fromEntries(Collection $entries): self
    {
        /** @var list<array{paisa: int, due: Carbon}> $owing */
        $owing = [];
        $paid = 0;

        foreach ($entries as $entry) {
            $paid += (int) $entry->credit_paisa;

            if ($entry->debit_paisa > 0) {
                $owing[] = [
                    'paisa' => (int) $entry->debit_paisa,
                    'due' => self::dueDate($entry),
                ];
            }
        }

        foreach ($owing as $index => $line) {
            if ($paid <= 0) {
                break;
            }

            $settled = min($paid, $line['paisa']);
            $owing[$index]['paisa'] -= $settled;
            $paid -= $settled;
        }

        $buckets = array_fill_keys(self::BUCKETS, 0);
        $owed = 0;
        $oldest = null;

        foreach ($owing as $line) {
            if ($line['paisa'] <= 0) {
                continue;
            }

            $buckets[self::bucketFor($line['due'])] += $line['paisa'];
            $owed += $line['paisa'];

            if ($line['due']->lt(today()) && ($oldest === null || $line['due']->lt($oldest))) {
                $oldest = $line['due'];
            }
        }

        return new self($buckets, $owed, $paid, $oldest);
    }

    public function paisa(string $bucket): int
    {
        return $this->buckets[$bucket] ?? 0;
    }

    /**
     * Everything whose day to be paid has already passed.
     */
    public function overduePaisa(): int
    {
        return $this->owedPaisa - $this->paisa('current');
    }

    public function isOverdue(): bool
    {
        return $this->overduePaisa() > 0;
    }

    /**
     * How many days late the oldest unpaid purchase is.
     */
    public function daysLate(): int
    {
        return $this->oldestDueOn ? (int) abs($this->oldestDueOn->diffInDays(today())) : 0;
    }

    /**
     * Every bucket in order, ready to be listed or drawn as a bar.
     *
     * @return list<array{key: string, label: string, paisa: int, tone: string}>
     */
    public function lines(): array
    {
        $labels = self::labels();
        $tones = self::tones();

        return array_map(fn (string $bucket): array => [
            'key' => $bucket,
            'label' => $labels[$bucket],
            'paisa' => $this->paisa($bucket),
            'tone' => $tones[$bucket],
        ], self::BUCKETS);
    }

    /**
     * The buckets of many customers added together.
     *
     * @param  array<int, self>  $agings
     * @return array<string, int>
     */
    public static function totals(array $agings): array
    {
        $totals = array_fill_keys(self::BUCKETS, 0);

        foreach ($agings as $aging) {
            foreach (self::BUCKETS as $bucket) {
                $totals[$bucket] += $aging->paisa($bucket);
            }
        }

        return $totals;
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'current' => __('Not due yet'),
            '1_30' => __('1–30 days'),
            '31_60' => __('31–60 days'),
            '61_90' => __('61–90 days'),
            'over_90' => __('Over 90 days'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function tones(): array
    {
        return [
            'current' => 'neutral',
            '1_30' => 'warning',
            '31_60' => 'warning',
            '61_90' => 'danger',
            'over_90' => 'danger',
        ];
    }

    /**
     * When a purchase was meant to be settled. Lines that carry no due date —
     * a balance brought forward, a correction — are aged from their own date.
     */
    private static function dueDate(CustomerLedgerEntry $entry): Carbon
    {
        $date = $entry->due_date ?? $entry->entry_date ?? today();

        return $date->copy()->startOfDay();
    }

    private static function bucketFor(Carbon $due): string
    {
        $days = $due->lt(today()) ? (int) abs($due->diffInDays(today())) : 0;

        return match (true) {
            $days <= 0 => 'current',
            $days <= 30 => '1_30',
            $days <= 60 => '31_60',
            $days <= 90 => '61_90',
            default => 'over_90',
        };
    }
}
