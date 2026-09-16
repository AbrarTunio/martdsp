<?php

namespace App\Support\Insights;

use App\Models\Customer;
use App\Models\User;
use App\Support\Reports\Period;
use Illuminate\Http\Request;

/**
 * What the person was looking at when they pressed the button: the dates on
 * the page, and the customer when it was one customer's khata.
 */
final readonly class InsightScope
{
    public function __construct(
        public User $user,
        public ?Period $chosenPeriod = null,
        public ?Customer $customer = null,
    ) {}

    public static function fromRequest(Request $request, User $user): self
    {
        $customer = $request->integer('customer') > 0
            ? Customer::query()->find($request->integer('customer'))
            : null;

        return new self(
            user: $user,
            chosenPeriod: $request->filled('period') ? Period::fromRequest($request) : null,
            customer: $customer,
        );
    }

    /**
     * The dates on the page, or the page's usual stretch when none were picked.
     */
    public function period(string $default = 'this_month'): Period
    {
        return $this->chosenPeriod ?? Period::preset($default);
    }

    /**
     * Sent back with the button, so a refresh covers the same thing.
     *
     * @return array<string, string|int>
     */
    public function query(): array
    {
        return array_filter(
            ($this->chosenPeriod?->query() ?? []) + ['customer' => $this->customer?->getKey()],
            fn (mixed $value): bool => $value !== null,
        );
    }
}
