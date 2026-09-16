@php
    use App\Support\Money;

    $isToday = $date->isToday();
@endphp

<x-app-layout :title="__('Sales')">
    <x-flash />

    <x-page-header :title="__('Sales')"
                   :description="$isToday
                       ? __('Every bill rung up today. Open one to reprint its receipt or, if it was a mistake, cancel it.')
                       : __('Bills rung up on :date.', ['date' => $date->format('l, d M Y')])">
        <x-ai-insight-button />

        @can('supervise')
            <a href="{{ route('sales.returns.index') }}"
               class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                <x-icon name="layers" class="h-4.5 w-4.5" />
                {{ __('Returns') }}
            </a>
        @endcan

        <a href="{{ route('pos.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
            <x-icon name="cart" class="h-4.5 w-4.5" />
            {{ __('Open the till') }}
        </a>
    </x-page-header>

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
        <x-stat-card :label="__('Takings')" :value="Money::rounded($summary['total_paisa'])" icon="wallet"
                     :hint="trans_choice(':count bill|:count bills', $summary['bills'], ['count' => number_format($summary['bills'])])" />

        <x-stat-card :label="__('Average bill')"
                     :value="Money::rounded($summary['bills'] > 0 ? intdiv($summary['total_paisa'], $summary['bills']) : 0)"
                     icon="receipt" />

        <x-stat-card :label="__('Given as discount')" :value="Money::rounded($summary['discount_paisa'])" icon="sparkles"
                     :tone="$summary['total_paisa'] > 0 && $summary['discount_paisa'] * 20 > $summary['total_paisa'] ? 'warning' : 'neutral'"
                     :hint="__('More than 5% of takings is worth a look')" />

        <x-stat-card :label="__('Put on khata')" :value="Money::rounded($summary['khata_paisa'])" icon="book"
                     :tone="$summary['khata_paisa'] > 0 ? 'warning' : 'neutral'"
                     :hint="$summary['voided'] > 0
                         ? trans_choice(':count bill cancelled|:count bills cancelled', $summary['voided'], ['count' => $summary['voided']])
                         : __('Sold but not yet paid for')" />
    </div>

    <form method="GET" action="{{ route('sales.index') }}" class="mt-4"
          x-data x-on:change="$el.requestSubmit()">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
            <input type="date" name="date" value="{{ $date->toDateString() }}" max="{{ today()->toDateString() }}"
                   aria-label="{{ __('Date') }}"
                   class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">

            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" autocomplete="off"
                   placeholder="{{ __('Bill no. or customer') }}" aria-label="{{ __('Bill number or customer') }}"
                   class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">

            <select name="status" aria-label="{{ __('Status') }}"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="">{{ __('Every bill') }}</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>

            @if ($registers->count() > 1)
                <select name="register" aria-label="{{ __('Counter') }}"
                        class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option value="">{{ __('Every counter') }}</option>
                    @foreach ($registers as $id => $name)
                        <option value="{{ $id }}" @selected((string) ($filters['register'] ?? '') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            @endif

            @if ($cashiers->isNotEmpty())
                <select name="cashier" aria-label="{{ __('Cashier') }}"
                        class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option value="">{{ __('Every cashier') }}</option>
                    @foreach ($cashiers as $id => $name)
                        <option value="{{ $id }}" @selected((string) ($filters['cashier'] ?? '') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        <noscript>
            <button type="submit" class="tap-target mt-2 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white">
                {{ __('Filter') }}
            </button>
        </noscript>
    </form>

    @if ($sales->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="receipt"
                           :title="array_filter($filters) ? __('Nothing matched') : ($isToday ? __('No sales yet today') : __('No sales that day'))"
                           :description="__('Bills appear here the moment they are paid for at the till.')" />
        </div>
    @else
        <ul class="mt-4 space-y-2">
            @foreach ($sales as $sale)
                <li @class([
                    'rounded-xl border bg-white dark:bg-gray-900',
                    'border-gray-200 dark:border-gray-800' => ! $sale->isVoid(),
                    'border-red-200 dark:border-red-500/30' => $sale->isVoid(),
                ])>
                    <a href="{{ route('sales.show', $sale) }}"
                       class="flex items-start gap-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <p class="font-mono text-sm font-semibold">{{ $sale->invoiceNumber() }}</p>
                                @if ($sale->isVoid())
                                    <x-badge :tone="$sale->status->tone()">{{ $sale->status->label() }}</x-badge>
                                @endif
                                @if ($sale->due_paisa > 0 && ! $sale->isVoid())
                                    <x-badge tone="warning">{{ __('Khata') }}</x-badge>
                                @endif
                            </div>

                            <p class="mt-1 truncate text-sm">{{ $sale->customerName() }}</p>

                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ $sale->sold_at?->format('g:i A') }}
                                · {{ trans_choice(':count line|:count lines', $sale->items_count, ['count' => $sale->items_count]) }}
                                @if ($sale->user) · {{ $sale->user->name }} @endif
                                @if ($sale->register && $registers->count() > 1) · {{ $sale->register->name }} @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <x-money :paisa="$sale->total_paisa" @class(['block text-sm font-semibold', 'line-through text-gray-400' => $sale->isVoid()]) />
                            @if ($sale->discount_paisa > 0)
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ __(':amount off', ['amount' => Money::rounded($sale->discount_paisa)]) }}
                                </p>
                            @endif
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            {{ $sales->links() }}
        </div>
    @endif
</x-app-layout>
