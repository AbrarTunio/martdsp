<x-app-layout :title="__('Stock')">
    <x-flash />

    <x-page-header :title="__('Stock')"
                   :description="__('What is on the shelf right now, and how it got there.')">
        <x-ai-insight-button />

        @can('supervise')
            <a href="{{ route('stock.adjustments.create') }}"
               class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4.5 w-4.5" />
                {{ __('Correct stock') }}
            </a>
        @endcan
    </x-page-header>

    <div class="mt-4 flex flex-wrap gap-2">
        <a href="{{ route('stock.expiry') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800">
            <x-icon name="clock" class="h-4 w-4 text-gray-500 dark:text-gray-400" />
            {{ __('Going off') }}
        </a>

        @can('supervise')
            <a href="{{ route('stock.takes.index') }}"
               class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800">
                <x-icon name="scan" class="h-4 w-4 text-gray-500 dark:text-gray-400" />
                {{ __('Stock takes') }}
            </a>

            <a href="{{ route('stock.adjustments.index') }}"
               class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800">
                <x-icon name="layers" class="h-4 w-4 text-gray-500 dark:text-gray-400" />
                {{ __('Corrections') }}
            </a>
        @endcan
    </div>

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
        <x-stat-card :label="__('Items stocked')" :value="number_format($summary['items'])" icon="box" />

        <x-stat-card :label="__('Running low')" :value="number_format($summary['low'])"
                     icon="alert" tone="warning"
                     :hint="__('At or below the reorder level')" />

        <x-stat-card :label="__('Nothing left')" :value="number_format($summary['out'])"
                     icon="alert" :tone="$summary['out'] > 0 ? 'danger' : 'neutral'" />

        @if ($summary['value_paisa'] !== null)
            <x-stat-card :label="__('Stock value')" :value="\App\Support\Money::rounded($summary['value_paisa'])"
                         icon="chart" :hint="__('At what you paid, not what you sell it for')" />
        @else
            <x-stat-card :label="__('Below zero')" :value="number_format($summary['negative'])"
                         icon="alert" :tone="$summary['negative'] > 0 ? 'danger' : 'neutral'" />
        @endif
    </div>

    @if ($summary['negative'] > 0 && $summary['value_paisa'] !== null)
        <div class="mt-3 flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3 text-sm dark:border-amber-500/30 dark:bg-amber-500/10">
            <x-icon name="alert" class="mt-px h-4.5 w-4.5 shrink-0 text-amber-600 dark:text-amber-400" />
            <p class="flex-1">
                {{ trans_choice(
                    ':count item has gone below zero.|:count items have gone below zero.',
                    $summary['negative'],
                    ['count' => number_format($summary['negative'])]
                ) }}
                <span class="text-gray-600 dark:text-gray-400">
                    {{ __('That usually means something was sold before the delivery was entered.') }}
                </span>
                <a href="{{ route('stock.index', ['view' => 'negative']) }}"
                   class="font-medium text-brand-700 hover:underline dark:text-brand-400">{{ __('Show them') }}</a>
            </p>
        </div>
    @endif

    <form method="GET" action="{{ route('stock.index') }}" class="mt-4"
          x-data x-on:change="$el.requestSubmit()">
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <div class="relative sm:col-span-2">
                <span class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-gray-400">
                    <x-icon name="search" class="h-4.5 w-4.5" />
                </span>
                <x-text-input name="q" type="search" class="block w-full pl-10"
                              :value="$filters['q'] ?? ''"
                              :placeholder="__('Name, SKU, or scan a barcode')" />
            </div>

            <select name="category"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="">{{ __('All categories') }}</option>
                @foreach ($categories as $id => $name)
                    <option value="{{ $id }}" @selected((string) ($filters['category'] ?? '') === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>

            <select name="view"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                @foreach ([
                    'all' => __('Everything (:count)', ['count' => $summary['items']]),
                    'low' => __('Running low (:count)', ['count' => $summary['low']]),
                    'out' => __('Nothing left (:count)', ['count' => $summary['out']]),
                    'negative' => __('Below zero (:count)', ['count' => $summary['negative']]),
                ] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['view'] ?? 'all') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <noscript>
            <button type="submit" class="tap-target mt-2 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white">
                {{ __('Search') }}
            </button>
        </noscript>
    </form>

    @if ($products->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="layers"
                           :title="array_filter($filters) ? __('Nothing matched') : __('No stock recorded yet')"
                           :description="array_filter($filters)
                               ? __('Try a shorter search, or clear the filters.')
                               : __('Enter what is already on your shelves as opening stock. After that, deliveries and sales keep the numbers up to date on their own.')">
                @if (array_filter($filters))
                    <a href="{{ route('stock.index') }}" class="text-sm font-medium text-brand-700 hover:underline dark:text-brand-400">
                        {{ __('Clear filters') }}
                    </a>
                @else
                    @can('supervise')
                        <a href="{{ route('stock.adjustments.create', ['reason' => 'opening']) }}"
                           class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                            <x-icon name="plus" class="h-4.5 w-4.5" />
                            {{ __('Enter opening stock') }}
                        </a>
                    @endcan
                @endif
            </x-empty-state>
        </div>
    @else
        <ul class="mt-4 space-y-2">
            @foreach ($products as $product)
                <li class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <a href="{{ route('stock.show', $product) }}"
                       class="flex items-start gap-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <p class="truncate text-sm font-semibold">{{ $product->name }}</p>

                                @if ($product->stock_qty_base < 0)
                                    <x-badge tone="danger">{{ __('Below zero') }}</x-badge>
                                @elseif ($product->stock_qty_base <= 0)
                                    <x-badge tone="neutral">{{ __('Nothing left') }}</x-badge>
                                @elseif ($product->isLowOnStock())
                                    <x-badge tone="warning">{{ __('Running low') }}</x-badge>
                                @endif
                            </div>

                            <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">{{ $product->sku }}</p>

                            <p class="mt-1.5 text-sm">
                                <span class="font-semibold tabular-nums">{{ $product->stockBreakdown() }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    · {{ number_format($product->stock_qty_base) }} {{ $product->baseUnit?->name }}
                                </span>
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            @can('see-financials')
                                <x-money :paisa="$product->stockValuePaisa()" rounded class="block text-sm font-semibold" />
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('at cost') }}
                                </p>
                            @endcan

                            <span class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-brand-700 dark:text-brand-400">
                                {{ __('Ledger') }}
                                <x-icon name="chevron-right" class="h-3.5 w-3.5" />
                            </span>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            {{ $products->links() }}
        </div>
    @endif
</x-app-layout>
