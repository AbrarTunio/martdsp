@php
    use App\Support\Packaging;
@endphp

<x-app-layout :title="__('Going off')">
    <x-flash />

    <x-page-header :title="__('Going off')"
                   :description="__('Stock nearing its date, soonest first. Sell it, mark it down, or take it off the shelf before it is worth nothing.')">
        <x-ai-insight-button />

        <a href="{{ route('stock.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            <x-icon name="layers" class="h-4.5 w-4.5" />
            {{ __('All stock') }}
        </a>
    </x-page-header>

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
        <x-stat-card :label="__('Already gone')" :value="number_format($summary['expired'])"
                     icon="alert" :tone="$summary['expired'] > 0 ? 'danger' : 'neutral'"
                     :hint="__('Past its date and still on the shelf')" />

        <x-stat-card :label="__('Goes this week')" :value="number_format($summary['week'])"
                     icon="clock" :tone="$summary['week'] > 0 ? 'warning' : 'neutral'" />

        <x-stat-card :label="__('Goes this month')" :value="number_format($summary['month'])"
                     icon="clock" />

        @if ($summary['at_risk_paisa'] !== null)
            <x-stat-card :label="__('Money at risk')" :value="\App\Support\Money::rounded($summary['at_risk_paisa'])"
                         icon="chart" :tone="$summary['at_risk_paisa'] > 0 ? 'warning' : 'neutral'"
                         :hint="__('What the next month of dates cost you')" />
        @else
            <x-stat-card :label="__('Batches watched')" :value="number_format($batches->total())" icon="box" />
        @endif
    </div>

    @if ($summary['expired'] > 0 && $view !== 'expired')
        <div class="mt-3 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-3.5 py-3 text-sm dark:border-red-500/30 dark:bg-red-500/10">
            <x-icon name="alert" class="mt-px h-4.5 w-4.5 shrink-0 text-red-600 dark:text-red-400" />
            <p class="flex-1">
                {{ trans_choice(
                    ':count batch is past its date and still counted as stock.|:count batches are past their date and still counted as stock.',
                    $summary['expired'],
                    ['count' => number_format($summary['expired'])]
                ) }}
                <span class="text-gray-600 dark:text-gray-400">
                    {{ __('Take them off the shelf and write them off, so the shelf and the books agree.') }}
                </span>
                <a href="{{ route('stock.expiry', ['view' => 'expired']) }}"
                   class="font-medium text-brand-700 hover:underline dark:text-brand-400">{{ __('Show them') }}</a>
            </p>
        </div>
    @endif

    <form method="GET" action="{{ route('stock.expiry') }}" class="mt-4"
          x-data x-on:change="$el.requestSubmit()">
        <div class="grid gap-2 sm:grid-cols-3">
            <div class="relative sm:col-span-2">
                <span class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-gray-400">
                    <x-icon name="search" class="h-4.5 w-4.5" />
                </span>
                <x-text-input name="q" type="search" class="block w-full pl-10"
                              :value="$filters['q'] ?? ''"
                              :placeholder="__('Name, SKU, or scan a barcode')" />
            </div>

            <select name="view"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                @foreach ($views as $value => $label)
                    <option value="{{ $value }}" @selected($view === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <noscript>
            <button type="submit" class="tap-target mt-2 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white">
                {{ __('Search') }}
            </button>
        </noscript>
    </form>

    @if ($batches->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="clock"
                           :title="$view === 'expired' ? __('Nothing has gone off') : __('Nothing is close to its date')"
                           :description="array_filter($filters)
                               ? __('Nothing here matched that search. Try a shorter one, or look further ahead.')
                               : __('Only items marked to track batches or expiry appear here. Tick that on a product, and every delivery of it from then on is watched by its date.')">
                @if (array_filter($filters))
                    <a href="{{ route('stock.expiry', ['view' => $view]) }}"
                       class="text-sm font-medium text-brand-700 hover:underline dark:text-brand-400">
                        {{ __('Clear the search') }}
                    </a>
                @elseif ($view !== '90')
                    <a href="{{ route('stock.expiry', ['view' => '90']) }}"
                       class="text-sm font-medium text-brand-700 hover:underline dark:text-brand-400">
                        {{ __('Look three months ahead') }}
                    </a>
                @endif
            </x-empty-state>
        </div>
    @else
        <ul class="mt-4 space-y-2">
            @foreach ($batches as $batch)
                @php
                    $daysLeft = $batch->daysLeft();
                    $gone = $batch->hasExpired();
                @endphp

                <li class="rounded-xl border bg-white dark:bg-gray-900 {{ $gone
                    ? 'border-red-200 dark:border-red-500/30'
                    : ($daysLeft !== null && $daysLeft <= 7 ? 'border-amber-200 dark:border-amber-500/30' : 'border-gray-200 dark:border-gray-800') }}">
                    <a href="{{ route('stock.show', $batch->product) }}"
                       class="flex items-start gap-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <p class="truncate text-sm font-semibold">{{ $batch->product?->name }}</p>

                                @if ($gone)
                                    <x-badge tone="danger">
                                        {{ trans_choice('Gone :count day ago|Gone :count days ago', abs($daysLeft), ['count' => abs($daysLeft)]) }}
                                    </x-badge>
                                @elseif ($daysLeft === 0)
                                    <x-badge tone="danger">{{ __('Goes today') }}</x-badge>
                                @elseif ($daysLeft !== null && $daysLeft <= 7)
                                    <x-badge tone="warning">
                                        {{ trans_choice(':count day left|:count days left', $daysLeft, ['count' => $daysLeft]) }}
                                    </x-badge>
                                @elseif ($daysLeft !== null)
                                    <x-badge tone="neutral">
                                        {{ trans_choice(':count day left|:count days left', $daysLeft, ['count' => $daysLeft]) }}
                                    </x-badge>
                                @endif
                            </div>

                            <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Batch') }} {{ $batch->name() }}
                                @if ($batch->expiry_date)
                                    · {{ __('until') }} {{ $batch->expiry_date->format('d M Y') }}
                                @endif
                            </p>

                            <p class="mt-1.5 text-sm">
                                <span class="font-semibold tabular-nums">
                                    {{ $batch->product ? Packaging::describe($batch->qty_base, $batch->product->productUnits) : $batch->qty_base }}
                                </span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    · {{ number_format($batch->qty_base) }} {{ $batch->product?->baseUnit?->name }}
                                </span>
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            @can('see-financials')
                                <x-money :paisa="$batch->valuePaisa()" rounded class="block text-sm font-semibold" />
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('at cost') }}</p>
                            @endcan

                            <x-icon name="chevron-right" class="mt-1 ml-auto h-4 w-4 text-gray-400" />
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            {{ $batches->links() }}
        </div>
    @endif
</x-app-layout>
