@php($inputClass = 'tap-target block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300')

<x-app-layout :title="__('Print labels')">
    <x-page-header :title="__('Print labels')"
                   :description="__('For loose stock and anything that arrives without a barcode. Every size gets its own label, because a carton and a sachet are different prices.')">
        <a href="{{ route('products.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            {{ __('Back to products') }}
        </a>
    </x-page-header>

    <x-card :title="__('Find the item')" class="mt-4">
        <form method="GET" action="{{ route('products.labels.create') }}" class="flex flex-wrap gap-2">
            <div class="relative min-w-0 flex-1">
                <span class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-gray-400">
                    <x-icon name="search" class="h-4.5 w-4.5" />
                </span>
                <input name="q" type="search" value="{{ $query }}" autocomplete="off"
                       placeholder="{{ __('Name, item code, or scan a barcode') }}"
                       class="{{ $inputClass }} pl-10">
            </div>

            <button type="submit"
                    class="tap-target shrink-0 rounded-lg bg-gray-900 px-5 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600">
                {{ __('Search') }}
            </button>
        </form>

        @if ($matches !== null)
            @if ($matches->isEmpty())
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing matched that.') }}</p>
            @else
                <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($matches as $match)
                        <li>
                            <a href="{{ route('products.labels.create', ['product' => $match->id]) }}"
                               class="flex items-center justify-between gap-3 py-2 text-sm hover:text-brand-700 dark:hover:text-brand-400">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium">{{ $match->name }}</span>
                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $match->sku }}</span>
                                </span>
                                <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-gray-400" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </x-card>

    @if ($product)
        <form method="GET" action="{{ route('products.labels.sheet') }}" target="_blank" class="mt-4 space-y-4">
            <x-card :title="$product->name" :description="__('How many of each size?')">
                <ul class="space-y-2">
                    @foreach ($product->productUnits->sortByDesc('conversion_factor') as $level)
                        @php($code = $level->primaryBarcode()?->code ?? $product->sku)
                        <li class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium">{{ $level->label($product->baseUnit?->name) }}</p>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $level->formattedPrice() }} ·
                                    <span class="font-mono">{{ $code }}</span>
                                    @unless ($level->primaryBarcode())
                                        <span class="text-amber-700 dark:text-amber-400">{{ __('(no barcode — the item code will be printed)') }}</span>
                                    @endunless
                                </p>
                            </div>

                            <x-barcode :code="$code" :width="30" :height="9" class="shrink-0" />

                            <div class="shrink-0">
                                <label for="copies-{{ $level->id }}" class="block text-xs font-medium text-gray-600 dark:text-gray-400">
                                    {{ __('Labels') }}
                                </label>
                                <input id="copies-{{ $level->id }}" type="number" min="0" max="200" step="1"
                                       name="copies[{{ $level->id }}]" value="0" inputmode="numeric"
                                       class="{{ $inputClass }} mt-1 w-24">
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-card>

            <x-card :title="__('Paper')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="size" class="block text-sm font-medium">{{ __('Label size') }}</label>
                        <select id="size" name="size" class="{{ $inputClass }} mt-1.5">
                            @foreach ($sizes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Print at 100% scale, with page margins off, or the barcodes will not scan.') }}
                        </p>
                    </div>

                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                        <input type="hidden" name="show_price" value="0">
                        <input type="checkbox" name="show_price" value="1" checked
                               class="mt-0.5 h-4.5 w-4.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                        <span>
                            <span class="block text-sm font-medium">{{ __('Print the price on the label') }}</span>
                            <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Leave this off if you expect the price to change before the labels are used up.') }}
                            </span>
                        </span>
                    </label>
                </div>
            </x-card>

            <div class="flex justify-end">
                <button type="submit"
                        class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="printer" class="h-4.5 w-4.5" />
                    {{ __('Make the sheet') }}
                </button>
            </div>
        </form>
    @endif
</x-app-layout>
