@php
    use App\Support\Money;
@endphp

<x-app-layout :title="__('Customer returns')">
    <x-flash />

    <x-page-header :title="__('Customer returns')"
                   :description="__('Goods brought back off a bill, and what the customer got for them.')">
        <x-ai-insight-button />

        <a href="{{ route('sales.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            <x-icon name="receipt" class="h-4.5 w-4.5" />
            {{ __('Find a bill') }}
        </a>
    </x-page-header>

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-3">
        <x-stat-card :label="__('Given back this month')" :value="Money::rounded($summary['this_month_paisa'])" icon="wallet"
                     :hint="trans_choice(':count return|:count returns', $summary['this_month_count'], ['count' => $summary['this_month_count']])" />

        @can('see-financials')
            <x-stat-card :label="__('Refunded on goods binned')" :value="Money::rounded($summary['written_off_paisa'])"
                         icon="alert" :tone="$summary['written_off_paisa'] > 0 ? 'danger' : 'neutral'"
                         :hint="__('Expired or damaged, so nothing went back on the shelf')" />
        @endcan

        <x-stat-card :label="__('Returns this month')" :value="number_format($summary['this_month_count'])"
                     icon="layers" :tone="$summary['this_month_count'] > 10 ? 'warning' : 'neutral'"
                     :hint="__('Many of one item is worth looking into')" />
    </div>

    <form method="GET" action="{{ route('sales.returns.index') }}" class="mt-4"
          x-data x-on:change="$el.requestSubmit()">
        <div class="grid grid-cols-2 gap-2 lg:max-w-xl">
            <select name="reason" aria-label="{{ __('Reason') }}"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="">{{ __('Any reason') }}</option>
                @foreach ($reasons as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['reason'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <select name="settlement" aria-label="{{ __('How it was given back') }}"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="">{{ __('Cash or khata') }}</option>
                @foreach ($settlements as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['settlement'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <noscript>
            <button type="submit" class="tap-target mt-2 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white">
                {{ __('Filter') }}
            </button>
        </noscript>
    </form>

    @if ($returns->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="layers"
                           :title="array_filter($filters) ? __('Nothing matched') : __('Nothing has come back yet')"
                           :description="__('Open the bill the goods were sold on and take them back from there, so the price, the GST and the shelf all stay right.')" />
        </div>
    @else
        <ul class="mt-4 space-y-2">
            @foreach ($returns as $return)
                <li class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <a href="{{ route('sales.returns.show', $return) }}"
                       class="flex items-start gap-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <p class="font-mono text-sm font-semibold">{{ $return->reference }}</p>
                                <x-badge :tone="$return->reason->tone()">{{ $return->reason->label() }}</x-badge>
                                @unless ($return->restocked)
                                    <x-badge tone="danger">{{ __('not restocked') }}</x-badge>
                                @endunless
                            </div>

                            <p class="mt-1 truncate text-sm">
                                {{ $return->customerName() }}
                                <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $return->sale?->invoiceNumber() }}</span>
                            </p>

                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ $return->returned_at->format('d M Y') }}
                                · {{ trans_choice(':count item|:count items', $return->items_count, ['count' => $return->items_count]) }}
                                @if ($return->user) · {{ $return->user->name }} @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <x-money :paisa="$return->total_paisa" rounded class="block text-sm font-semibold" />
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ $return->settlement->isCash() ? __('cash back') : __('off the khata') }}
                            </p>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            {{ $returns->links() }}
        </div>
    @endif
</x-app-layout>
