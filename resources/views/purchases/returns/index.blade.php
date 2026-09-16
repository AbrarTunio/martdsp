@php
    use App\Enums\ReturnSettlement;
    use App\Support\Money;
@endphp

<x-app-layout :title="__('Returns to suppliers')">
    <x-flash />

    <x-page-header :title="__('Returns to suppliers')"
                   :description="__('Expired, damaged or unwanted goods that went back, and what came back for them.')">
        <x-ai-insight-button />

        <a href="{{ route('purchases.returns.create') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
            <x-icon name="plus" class="h-4.5 w-4.5" />
            {{ __('Send goods back') }}
        </a>
    </x-page-header>

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-3">
        <x-stat-card :label="__('Sent back this month')" :value="Money::rounded($summary['this_month_paisa'])" icon="layers" />

        @can('see-financials')
            <x-stat-card :label="__('Lost on returns this month')" :value="Money::rounded(max(0, $summary['loss_this_month_paisa']))"
                         icon="alert" :tone="$summary['loss_this_month_paisa'] > 0 ? 'danger' : 'neutral'"
                         :hint="__('What the goods cost, less what came back')" />
        @endcan

        <x-stat-card :label="__('Expiry returns this month')" :value="number_format($summary['expired_this_month'])"
                     icon="box" :tone="$summary['expired_this_month'] > 2 ? 'warning' : 'neutral'"
                     :hint="__('Many of these means ordering too much')" />
    </div>

    <form method="GET" action="{{ route('purchases.returns.index') }}" class="mt-4"
          x-data x-on:change="$el.requestSubmit()">
        <div class="grid grid-cols-2 gap-2 lg:max-w-xl">
            <select name="supplier" aria-label="{{ __('Supplier') }}"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="">{{ __('Every supplier') }}</option>
                @foreach ($suppliers as $id => $name)
                    <option value="{{ $id }}" @selected((string) ($filters['supplier'] ?? '') === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>

            <select name="reason" aria-label="{{ __('Reason') }}"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="">{{ __('Any reason') }}</option>
                @foreach ($reasons as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['reason'] ?? '') === $value)>{{ $label }}</option>
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
                           :title="array_filter($filters) ? __('Nothing matched') : __('Nothing has gone back yet')"
                           :description="__('When a salesman takes back expired or damaged stock, record it here so the shelf count and his account stay right.')" />
        </div>
    @else
        <ul class="mt-4 space-y-2">
            @foreach ($returns as $return)
                <li class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <a href="{{ route('purchases.returns.show', $return) }}"
                       class="flex items-start gap-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <p class="font-mono text-sm font-semibold">{{ $return->reference }}</p>
                                <x-badge :tone="$return->reason->tone()">{{ $return->reason->label() }}</x-badge>
                            </div>

                            <p class="mt-1 truncate text-sm">{{ $return->supplierName() }}</p>

                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ $return->returned_at->format('d M Y') }}
                                · {{ trans_choice(':count item|:count items', $return->items_count, ['count' => $return->items_count]) }}
                                @if ($return->user) · {{ $return->user->name }} @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <x-money :paisa="$return->total_paisa" rounded class="block text-sm font-semibold" />
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ $return->settlement === ReturnSettlement::Cash ? __('cash back') : __('credited') }}
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
