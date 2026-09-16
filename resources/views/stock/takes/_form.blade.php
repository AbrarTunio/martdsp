{{--
    Shared by create and edit.

    A count is one section of the shop, walked with a scanner. The section is
    chosen first because it decides the one risky switch on this screen:
    whether anything in that section nobody scanned is taken as gone.
--}}
@php
    $selectClass = 'tap-target block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';

    $config = [
        'lookupUrl' => route('stock.lookup'),
        'rows' => $rows,
        'categoryId' => old('category_id', $take->category_id),
        'missingAreZero' => (bool) old('missing_are_zero', $take->missing_are_zero),
    ];

    $itemErrors = collect($errors->getMessages())
        ->filter(fn ($messages, string $key): bool => str_starts_with($key, 'items'))
        ->flatten()
        ->unique();
@endphp

<div class="space-y-5" x-data="stockTake(@js($config))">
    <x-card :title="__('What is being counted')">
        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="category_id" :label="__('Section')"
                         :hint="__('Leave as the whole shop for a count of anything, anywhere.')">
                    <select id="category_id" name="category_id" x-model="categoryId" class="{{ $selectClass }}">
                        <option value="">{{ __('Whole shop') }}</option>
                        @foreach ($categories as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field name="name" :label="__('Name')" :hint="__('Optional. For example: Month-end dairy fridge.')">
                    <x-text-input id="name" name="name" class="block w-full" autocomplete="off" maxlength="80"
                                  :value="old('name', $take->name)" />
                </x-field>
            </div>

            <label class="flex items-start gap-2.5 rounded-lg border p-3 transition"
                   x-bind:class="categoryId
                       ? (missingAreZero ? 'cursor-pointer border-amber-400 bg-amber-50 dark:border-amber-500/50 dark:bg-amber-500/10' : 'cursor-pointer border-gray-200 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/60')
                       : 'cursor-not-allowed border-gray-200 opacity-60 dark:border-gray-800'">
                <input type="hidden" name="missing_are_zero" value="0">
                <input type="checkbox" name="missing_are_zero" value="1" x-model="missingAreZero"
                       x-bind:disabled="! categoryId"
                       class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500 dark:border-gray-600 dark:bg-gray-900">
                <span class="min-w-0">
                    <span class="block text-sm font-medium">{{ __('Anything in this section I did not scan is gone') }}</span>
                    <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Use this when you have counted every shelf in the section. Items the books say are there but that were never scanned will be set to none when you post. Choose a section to turn it on.') }}
                    </span>
                </span>
            </label>

            <x-input-error :messages="$errors->get('missing_are_zero')" />

            <x-field name="note" :label="__('Note')" :hint="__('Optional. Who helped, what was odd.')">
                <x-text-input id="note" name="note" class="block w-full" autocomplete="off"
                              :value="old('note', $take->note)" />
            </x-field>
        </div>
    </x-card>

    <x-card :title="__('Count')"
            :description="__('Scan every item on the shelf, one scan each. Scanning a carton counts a whole carton.')">
        {{-- Not a <form>: a scanner's Enter must count the item rather than
             submit the sheet. --}}
        <div class="flex gap-2">
            <input x-model="code" x-ref="scanner" type="text" inputmode="text" autocomplete="off"
                   enterkeyhint="done" placeholder="{{ __('Scan or type a barcode, name or SKU') }}"
                   x-on:keydown.enter.prevent="scan()"
                   class="tap-target block w-full rounded-md border-gray-300 font-mono text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">

            <button type="button" x-on:click="scan()" x-bind:disabled="busy"
                    class="tap-target inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-gray-900 px-4 text-sm font-semibold text-white hover:bg-gray-700 disabled:opacity-50 dark:bg-gray-700 dark:hover:bg-gray-600">
                <x-icon name="scan" class="h-4.5 w-4.5" />
                <span class="hidden sm:inline">{{ __('Count') }}</span>
            </button>
        </div>

        <template x-if="message">
            <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm dark:border-amber-500/30 dark:bg-amber-500/10"
               x-text="message"></p>
        </template>

        @if ($itemErrors->isNotEmpty())
            <ul class="mt-3 space-y-1 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
                @foreach ($itemErrors as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif

        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-400" x-show="! isEmpty" x-cloak>
            <span><span class="font-semibold tabular-nums" x-text="rows.length"></span> {{ __('items counted') }}</span>
            <span class="text-money-out"><span class="font-semibold tabular-nums" x-text="shortLines"></span> {{ __('look short') }}</span>
            <span class="text-money-in"><span class="font-semibold tabular-nums" x-text="overLines"></span> {{ __('look over') }}</span>
        </div>

        <template x-if="isEmpty">
            <p class="mt-4 rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                {{ __('Nothing counted yet. Scan the first item on the shelf.') }}
            </p>
        </template>

        <ul class="mt-3 space-y-2">
            <template x-for="(row, index) in rows" x-bind:key="row.product_id">
                <li class="rounded-lg border p-3 transition"
                    x-bind:class="row.product_id === lastProductId
                        ? 'border-brand-400 bg-brand-50/60 dark:border-brand-500/60 dark:bg-brand-500/10'
                        : 'border-gray-200 dark:border-gray-800'">
                    <input type="hidden" x-bind:name="`items[${index}][product_id]`" x-bind:value="row.product_id">
                    <input type="hidden" x-bind:name="`items[${index}][note]`" x-bind:value="row.note ?? ''">
                    <input type="hidden" x-bind:name="`items[${index}][counted_at]`" x-bind:value="row.counted_at ?? ''">

                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold" x-text="row.name"></p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                <span x-text="row.sku"></span>
                                · {{ __('books say:') }} <span x-text="row.stock_words"></span>
                            </p>
                        </div>

                        <button type="button" x-on:click="remove(index)"
                                class="tap-target shrink-0 rounded-lg px-2 text-gray-400 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-gray-800"
                                aria-label="{{ __('Take this item off the count') }}">
                            <x-icon name="close" class="h-4.5 w-4.5" />
                        </button>
                    </div>

                    <div class="mt-3 grid grid-cols-12 gap-2">
                        <div class="col-span-4 sm:col-span-3">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Counted') }}</label>
                            <input type="number" min="0" step="1" inputmode="numeric"
                                   x-model="row.qty"
                                   x-bind:name="`items[${index}][qty]`"
                                   class="tap-target mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                        </div>

                        <div class="col-span-8 sm:col-span-5">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Size') }}</label>
                            <select x-model="row.product_unit_id" x-bind:name="`items[${index}][product_unit_id]`"
                                    class="tap-target mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <template x-for="unit in row.units" x-bind:key="unit.id">
                                    <option x-bind:value="unit.id" x-text="unit.label"
                                            x-bind:selected="Number(unit.id) === Number(row.product_unit_id)"></option>
                                </template>
                            </select>
                        </div>

                        <div class="col-span-12 flex items-end sm:col-span-4">
                            <p class="w-full text-sm font-semibold tabular-nums sm:text-right"
                               x-bind:class="differenceClass(row)"
                               x-text="differenceInWords(row)"></p>
                        </div>
                    </div>
                </li>
            </template>
        </ul>

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400" x-show="! isEmpty" x-cloak>
            {{ __('The difference is a guide. Keep selling while you count — each item is held against the books as they stood when you scanned it, so a sale after the scan is neither missing nor counted twice.') }}
        </p>
    </x-card>
</div>
