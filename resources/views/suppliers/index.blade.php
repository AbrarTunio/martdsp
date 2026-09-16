<x-app-layout :title="__('Suppliers')">
    <x-flash />

    <x-page-header :title="__('Suppliers')"
                   :description="__('Who you buy from, and what you owe each of them.')">
        <x-ai-insight-button />

        <a href="{{ route('suppliers.create') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
            <x-icon name="plus" class="h-4.5 w-4.5" />
            {{ __('Add supplier') }}
        </a>
    </x-page-header>

    <div class="mt-4 flex flex-wrap gap-2">
        <a href="{{ route('purchases.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800">
            <x-icon name="truck" class="h-4 w-4 text-gray-500 dark:text-gray-400" />
            {{ __('Purchases') }}
        </a>
        <a href="{{ route('purchases.suggestions.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800">
            <x-icon name="alert" class="h-4 w-4 text-gray-500 dark:text-gray-400" />
            {{ __('What to order') }}
        </a>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
        <x-stat-card :label="__('You owe in total')" :value="\App\Support\Money::rounded($summary['owed_paisa'])"
                     icon="wallet" :tone="$summary['owed_paisa'] > 0 ? 'warning' : 'neutral'" />

        <x-stat-card :label="__('Suppliers you owe')" :value="number_format($summary['owed_count'])" icon="factory" />

        <x-stat-card :label="__('Paid in advance')" :value="\App\Support\Money::rounded($summary['advance_paisa'])"
                     icon="check" :hint="__('Money suppliers are holding for you')" />

        <x-stat-card :label="__('Active suppliers')" :value="number_format($summary['active'])" icon="user" />
    </div>

    <form method="GET" action="{{ route('suppliers.index') }}" class="mt-4"
          x-data x-on:change="$el.requestSubmit()">
        <div class="grid gap-2 sm:grid-cols-3">
            <div class="relative sm:col-span-2">
                <span class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-gray-400">
                    <x-icon name="search" class="h-4.5 w-4.5" />
                </span>
                <x-text-input name="q" type="search" class="block w-full pl-10"
                              :value="$filters['q'] ?? ''"
                              :placeholder="__('Name, company or phone')" />
            </div>

            <select name="view"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                @foreach (['active' => __('Active'), 'owed' => __('You owe them'), 'hidden' => __('Hidden'), 'all' => __('Everyone')] as $value => $label)
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

    @if ($suppliers->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="factory"
                           :title="array_filter($filters) ? __('Nobody matched') : __('No suppliers yet')"
                           :description="__('Add the distributors and salesmen you buy from. Each one gets an account, so you always know what you owe them.')">
                <a href="{{ route('suppliers.create') }}"
                   class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="plus" class="h-4.5 w-4.5" />
                    {{ __('Add supplier') }}
                </a>
            </x-empty-state>
        </div>
    @else
        <ul class="mt-4 space-y-2">
            @foreach ($suppliers as $supplier)
                <li class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <a href="{{ route('suppliers.show', $supplier) }}"
                       class="flex items-start gap-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <p class="truncate text-sm font-semibold">{{ $supplier->name }}</p>
                                @unless ($supplier->is_active)
                                    <x-badge>{{ __('Hidden') }}</x-badge>
                                @endunless
                            </div>

                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ $supplier->company ?: __('No company') }}
                                @if ($supplier->phone) · {{ \App\Support\PhoneNumber::forHumans($supplier->phone) }} @endif
                                ·
                                {{ $supplier->payment_terms_days > 0
                                    ? trans_choice('pays in :count day|pays in :count days', $supplier->payment_terms_days, ['count' => $supplier->payment_terms_days])
                                    : __('cash on delivery') }}
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            @if ($supplier->balance_paisa > 0)
                                <x-money :paisa="$supplier->balance_paisa" rounded class="block text-sm font-semibold text-money-out" />
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('you owe') }}</p>
                            @elseif ($supplier->balance_paisa < 0)
                                <x-money :paisa="abs($supplier->balance_paisa)" rounded class="block text-sm font-semibold text-money-in" />
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('they hold for you') }}</p>
                            @else
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Settled') }}</p>
                            @endif
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            {{ $suppliers->links() }}
        </div>
    @endif
</x-app-layout>
