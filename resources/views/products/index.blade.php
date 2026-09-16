<x-app-layout :title="__('Products')">
    <x-flash />

    <x-page-header :title="__('Products')"
                   :description="__('Everything you sell, with its packaging and barcodes.')">
        <x-ai-insight-button />

        @can('supervise')
            <a href="{{ route('products.create') }}"
               class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4.5 w-4.5" />
                {{ __('Add product') }}
            </a>
        @endcan
    </x-page-header>

    @can('supervise')
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach ([
                'products.categories.index' => [__('Categories'), 'layers'],
                'products.brands.index' => [__('Brands'), 'factory'],
                'products.units.index' => [__('Units'), 'box'],
                'products.labels.create' => [__('Print labels'), 'printer'],
            ] as $route => [$label, $icon])
                <a href="{{ route($route) }}"
                   class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800">
                    <x-icon :name="$icon" class="h-4 w-4 text-gray-500 dark:text-gray-400" />
                    {{ $label }}
                </a>
            @endforeach

            @include('products.partials.starter-button')
        </div>
    @endcan

    @include('products.partials.price-check')

    <form method="GET" action="{{ route('products.index') }}" class="mt-4"
          x-data x-on:change="$el.requestSubmit()">
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <div class="relative sm:col-span-2 lg:col-span-2">
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

            <select name="status"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="">{{ __('Everything (:count)', ['count' => $counts['all']]) }}</option>
                <option value="low" @selected(($filters['status'] ?? '') === 'low')>
                    {{ __('Running low (:count)', ['count' => $counts['low']]) }}
                </option>
                <option value="hidden" @selected(($filters['status'] ?? '') === 'hidden')>{{ __('Hidden from the till') }}</option>
            </select>
        </div>

        @if ($brands->isNotEmpty())
            <select name="brand"
                    class="tap-target mt-2 w-full rounded-md border-gray-300 text-sm shadow-xs sm:w-64 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="">{{ __('All brands') }}</option>
                @foreach ($brands as $id => $name)
                    <option value="{{ $id }}" @selected((string) ($filters['brand'] ?? '') === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>
        @endif

        <noscript>
            <button type="submit" class="tap-target mt-2 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white">
                {{ __('Search') }}
            </button>
        </noscript>
    </form>

    @if ($products->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="box"
                           :title="array_filter($filters) ? __('Nothing matched') : __('No products yet')"
                           :description="array_filter($filters)
                               ? __('Try a shorter search, or clear the filters.')
                               : __('Add your first item. You will describe its packaging once — one carton of twelve boxes of twenty-four sachets — and the till will do the counting from then on.')">
                @if (array_filter($filters))
                    <a href="{{ route('products.index') }}" class="text-sm font-medium text-brand-700 hover:underline dark:text-brand-400">
                        {{ __('Clear filters') }}
                    </a>
                @else
                    @can('supervise')
                        <div class="flex flex-wrap items-center justify-center gap-2">
                            <a href="{{ route('products.create') }}"
                               class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                                <x-icon name="plus" class="h-4.5 w-4.5" />
                                {{ __('Add product') }}
                            </a>

                            @include('products.partials.starter-button')
                        </div>
                    @endcan
                @endif
            </x-empty-state>
        </div>
    @else
        <ul class="mt-4 space-y-2">
            @foreach ($products as $product)
                @php($sale = $product->defaultSaleUnit())
                <li class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <p class="truncate text-sm font-semibold">{{ $product->name }}</p>

                                @unless ($product->is_active)
                                    <x-badge tone="neutral">{{ __('Hidden') }}</x-badge>
                                @endunless

                                @if ($product->isLowOnStock())
                                    <x-badge tone="warning">{{ __('Running low') }}</x-badge>
                                @endif
                            </div>

                            @if ($product->name_ur)
                                <p class="mt-0.5 truncate font-urdu text-sm text-gray-600 dark:text-gray-400" dir="rtl">
                                    {{ $product->name_ur }}
                                </p>
                            @endif

                            <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ $product->sku }}
                                @if ($product->category) · {{ $product->category->fullName() }} @endif
                                @if ($product->brand) · {{ $product->brand->name }} @endif
                            </p>

                            <p class="mt-1.5 text-xs text-gray-600 dark:text-gray-400">
                                <span class="font-medium text-gray-900 dark:text-gray-200">{{ $product->stockBreakdown() }}</span>
                                {{ __('in stock') }}
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            @if ($sale)
                                <x-money :paisa="$sale->sale_price_paisa" class="block text-sm font-semibold" />
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('per') }} {{ $sale->unit?->name }}</p>
                            @endif

                            @can('supervise')
                                <a href="{{ route('products.edit', $product) }}"
                                   class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline dark:text-brand-400">
                                    {{ __('Edit') }}
                                    <x-icon name="chevron-right" class="h-3.5 w-3.5" />
                                </a>
                            @endcan
                        </div>
                    </div>

                    @if ($product->productUnits->count() > 1)
                        <div class="mt-2 flex flex-wrap gap-1.5 border-t border-gray-100 pt-2 dark:border-gray-800">
                            @foreach ($product->productUnits->sortByDesc('conversion_factor') as $level)
                                <span class="rounded-md bg-gray-100 px-2 py-0.5 text-[0.6875rem] text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                    {{ $level->label($product->baseUnit?->name) }} · {{ $level->formattedPrice() }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            {{ $products->links() }}
        </div>
    @endif
</x-app-layout>
