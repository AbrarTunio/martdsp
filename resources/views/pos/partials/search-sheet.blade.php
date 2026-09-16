{{-- Finding an item by name: loose goods, torn labels, a barcode that will not scan. --}}
<x-pos.sheet name="search" :title="__('Find an item')" wide>
    <div class="relative">
        <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 h-5 w-5 -translate-y-1/2 text-gray-400" />
        <input x-ref="searchInput" type="search" autocomplete="off" enterkeyhint="search"
               x-model="searchTerm" x-on:input.debounce.250ms="runSearch()"
               placeholder="{{ __('Name, Urdu name or SKU') }}"
               class="block h-12 w-full rounded-xl border-gray-300 pl-10 text-base shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
    </div>

    <p x-show="searching" x-cloak class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('Looking…') }}</p>

    <p x-show="! searching && searchTerm.trim().length >= 2 && searchResults.length === 0" x-cloak
       class="mt-4 rounded-lg border border-dashed border-gray-300 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
        {{ __('Nothing on sale matches that. Check the spelling, or ask a manager to add the item.') }}
    </p>

    <ul class="mt-3 space-y-2">
        <template x-for="item in searchResults" x-bind:key="item.product_id">
            <li class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold" x-text="item.name"></p>
                        <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                            <span x-text="item.sku"></span>
                            · {{ __('in stock:') }} <span x-text="item.stock_label || @js(__('none'))"></span>
                        </p>
                    </div>
                    <template x-if="item.name_ur">
                        <p class="shrink-0 font-urdu text-sm" dir="rtl" x-text="item.name_ur"></p>
                    </template>
                </div>

                <div class="mt-2 flex flex-wrap gap-1.5">
                    <template x-for="unit in item.units" x-bind:key="unit.id">
                        <button type="button" x-on:click="pick(item, unit.id)"
                                class="tap-target inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 text-sm hover:border-brand-400 hover:bg-brand-50 dark:border-gray-700 dark:hover:bg-brand-500/10">
                            <span class="font-medium" x-text="unit.name"></span>
                            <span class="tabular-nums text-gray-600 dark:text-gray-400" x-text="format(unit.price_paisa)"></span>
                        </button>
                    </template>
                </div>
            </li>
        </template>
    </ul>
</x-pos.sheet>
