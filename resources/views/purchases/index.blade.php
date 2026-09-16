@php
    use App\Enums\PurchaseStatus;
    use App\Support\Money;
@endphp

<x-app-layout :title="__('Purchases')">
    <x-flash />

    <x-page-header :title="__('Purchases')"
                   :description="__('Every delivery, what it cost, and what is still owed on it.')">
        <x-ai-insight-button />

        <a href="{{ route('purchases.create') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
            <x-icon name="scan" class="h-4.5 w-4.5" />
            {{ __('New delivery') }}
        </a>
    </x-page-header>

    <div class="mt-4 flex flex-wrap gap-2">
        <a href="{{ route('purchases.suggestions.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800">
            <x-icon name="alert" class="h-4 w-4 text-gray-500 dark:text-gray-400" />
            {{ __('What to order') }}
        </a>
        <a href="{{ route('purchases.returns.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800">
            <x-icon name="layers" class="h-4 w-4 text-gray-500 dark:text-gray-400" />
            {{ __('Returns') }}
        </a>
        <a href="{{ route('suppliers.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800">
            <x-icon name="factory" class="h-4 w-4 text-gray-500 dark:text-gray-400" />
            {{ __('Suppliers') }}
        </a>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-3">
        <x-stat-card :label="__('Bought this month')" :value="Money::rounded($summary['this_month_paisa'])" icon="truck" />

        <x-stat-card :label="__('You owe suppliers')" :value="Money::rounded($summary['owed_paisa'])"
                     icon="wallet" :tone="$summary['owed_paisa'] > 0 ? 'warning' : 'neutral'" />

        <x-stat-card :label="__('Not received yet')" :value="number_format($summary['drafts'])"
                     icon="alert" :tone="$summary['drafts'] > 0 ? 'warning' : 'neutral'"
                     :hint="__('Saved deliveries that have not reached stock')" />
    </div>

    <form method="GET" action="{{ route('purchases.index') }}" class="mt-4"
          x-data x-on:change="$el.requestSubmit()">
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <div class="relative sm:col-span-2">
                <span class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-gray-400">
                    <x-icon name="search" class="h-4.5 w-4.5" />
                </span>
                <x-text-input name="q" type="search" class="block w-full pl-10"
                              :value="$filters['q'] ?? ''"
                              :placeholder="__('PUR number, bill number or supplier')" />
            </div>

            <select name="supplier"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="">{{ __('Every supplier') }}</option>
                @foreach ($suppliers as $id => $name)
                    <option value="{{ $id }}" @selected((string) ($filters['supplier'] ?? '') === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>

            <select name="status"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="">{{ __('Any status') }}</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <noscript>
            <button type="submit" class="tap-target mt-2 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white">
                {{ __('Filter') }}
            </button>
        </noscript>
    </form>

    @if ($purchases->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="truck"
                           :title="array_filter($filters) ? __('Nothing matched') : __('No deliveries yet')"
                           :description="__('Enter a delivery by scanning each carton as it comes in. Stock and cost update the moment you receive it.')">
                <a href="{{ route('purchases.create') }}"
                   class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="scan" class="h-4.5 w-4.5" />
                    {{ __('New delivery') }}
                </a>
            </x-empty-state>
        </div>
    @else
        <ul class="mt-4 space-y-2">
            @foreach ($purchases as $purchase)
                <li class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <a href="{{ route('purchases.show', $purchase) }}"
                       class="flex items-start gap-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <p class="font-mono text-sm font-semibold">{{ $purchase->reference }}</p>
                                @unless ($purchase->status === PurchaseStatus::Received)
                                    <x-badge :tone="$purchase->status->tone()">{{ $purchase->status->label() }}</x-badge>
                                @endunless
                            </div>

                            <p class="mt-1 truncate text-sm">{{ $purchase->supplierName() }}</p>

                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ $purchase->purchase_date->format('d M Y') }}
                                @if ($purchase->invoice_no) · {{ __('bill') }} {{ $purchase->invoice_no }} @endif
                                · {{ trans_choice(':count item|:count items', $purchase->items_count, ['count' => $purchase->items_count]) }}
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <x-money :paisa="$purchase->total_paisa" rounded class="block text-sm font-semibold" />
                            @if ($purchase->status === PurchaseStatus::Received && $purchase->unpaidPaisa() > 0)
                                <p class="mt-0.5 text-xs text-money-out">
                                    {{ __(':amount on account', ['amount' => Money::rounded($purchase->unpaidPaisa())]) }}
                                </p>
                            @elseif ($purchase->status === PurchaseStatus::Received)
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('paid') }}</p>
                            @endif
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            {{ $purchases->links() }}
        </div>
    @endif
</x-app-layout>
