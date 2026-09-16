<x-app-layout :title="__('Khata')">
    <x-flash />

    <x-page-header :title="__('Khata')"
                   :description="__('Everyone who buys on account, and what each of them owes today.')">
        <x-ai-insight-button />

        <a href="{{ route('customers.create') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
            <x-icon name="plus" class="h-4.5 w-4.5" />
            {{ __('Open a khata') }}
        </a>
    </x-page-header>

    <div class="mt-4 flex flex-wrap gap-2">
        <a href="{{ route('customers.aging') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800">
            <x-icon name="clock" class="h-4 w-4 text-gray-500 dark:text-gray-400" />
            {{ __('How old is the money') }}
        </a>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
        <x-stat-card :label="__('Owed to you in total')" :value="\App\Support\Money::rounded($summary['owed_paisa'])"
                     icon="book" :tone="$summary['owed_paisa'] > 0 ? 'warning' : 'neutral'" />

        <x-stat-card :label="__('Customers who owe')" :value="number_format($summary['owing_count'])" icon="user" />

        <x-stat-card :label="__('Advance you hold')" :value="\App\Support\Money::rounded($summary['advance_paisa'])"
                     icon="wallet" :hint="__('Money customers have already paid in')" />

        <x-stat-card :label="__('Active khatas')" :value="number_format($summary['active'])" icon="check" />
    </div>

    <form method="GET" action="{{ route('customers.index') }}" class="mt-4"
          x-data x-on:change="$el.requestSubmit()">
        <div class="grid gap-2 sm:grid-cols-3">
            <div class="relative sm:col-span-2">
                <span class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-gray-400">
                    <x-icon name="search" class="h-4.5 w-4.5" />
                </span>
                <x-text-input name="q" type="search" class="block w-full pl-10"
                              :value="$filters['q'] ?? ''"
                              :placeholder="__('Name or phone')" />
            </div>

            <select name="view"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                @foreach (['active' => __('Active'), 'owing' => __('They owe you'), 'hidden' => __('Hidden'), 'all' => __('Everyone')] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['view'] ?? 'active') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <noscript>
            <button type="submit" class="tap-target mt-2 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white">
                {{ __('Filter') }}
            </button>
        </noscript>
    </form>

    @if ($customers->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="book"
                           :title="array_filter($filters) ? __('Nobody matched') : __('No khatas yet')"
                           :description="__('Open a khata for the customers you let take goods on credit. Every sale, payment and correction is written down, so the balance is never argued over.')">
                <a href="{{ route('customers.create') }}"
                   class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="plus" class="h-4.5 w-4.5" />
                    {{ __('Open a khata') }}
                </a>
            </x-empty-state>
        </div>
    @else
        <ul class="mt-4 space-y-2">
            @foreach ($customers as $customer)
                <li class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <a href="{{ route('customers.show', $customer) }}"
                       class="flex items-start gap-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <p class="truncate text-sm font-semibold">{{ $customer->name }}</p>
                                @if ($customer->name_ur)
                                    <span class="truncate text-xs text-gray-500 dark:text-gray-400" dir="rtl">{{ $customer->name_ur }}</span>
                                @endif
                                @unless ($customer->is_active)
                                    <x-badge>{{ __('Hidden') }}</x-badge>
                                @endunless
                                @if ($customer->hasCreditLimit() && $customer->balance_paisa >= $customer->credit_limit_paisa)
                                    <x-badge tone="danger">{{ __('At the limit') }}</x-badge>
                                @endif
                            </div>

                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ $customer->phone ? \App\Support\PhoneNumber::forHumans($customer->phone) : __('No phone') }}
                                @if ($customer->hasCreditLimit())
                                    · {{ __('limit :amount', ['amount' => \App\Support\Money::rounded($customer->credit_limit_paisa)]) }}
                                @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            @if ($customer->balance_paisa > 0)
                                <x-money :paisa="$customer->balance_paisa" rounded class="block text-sm font-semibold text-money-out" />
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('they owe') }}</p>
                            @elseif ($customer->balance_paisa < 0)
                                <x-money :paisa="abs($customer->balance_paisa)" rounded class="block text-sm font-semibold text-money-in" />
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('advance with you') }}</p>
                            @else
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Clear') }}</p>
                            @endif
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            {{ $customers->links() }}
        </div>
    @endif
</x-app-layout>
