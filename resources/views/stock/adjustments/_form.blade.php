{{--
    Shared by create and edit.

    The shape of this screen follows the job: pick why, then stand at the
    shelf with a scanner and count. The reason is chosen first because it
    changes what the quantity column means — "how many broke" against "how
    many you counted" — and getting that backwards is the one mistake that
    would silently corrupt a balance.
--}}
@php
    $selectClass = 'block w-full rounded-md border-gray-300 shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';

    $config = [
        'lookupUrl' => route('stock.lookup'),
        'rows' => $rows,
        'reasons' => $reasons,
        'reason' => old('reason', $adjustment->reason?->value),
    ];

    $itemErrors = collect($errors->getMessages())
        ->filter(fn ($messages, string $key): bool => str_starts_with($key, 'items'))
        ->flatten()
        ->unique();
@endphp

<div class="space-y-5" x-data="stockAdjustment(@js($config))">
    <x-card :title="__('Why is stock being corrected?')">
        <div class="space-y-4">
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($reasons as $reason)
                    <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border p-3 transition"
                           x-bind:class="reason === '{{ $reason['value'] }}'
                               ? 'border-brand-500 bg-brand-50 dark:border-brand-500 dark:bg-brand-500/10'
                               : 'border-gray-200 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/60'">
                        <input type="radio" name="reason" value="{{ $reason['value'] }}" x-model="reason"
                               class="mt-0.5 border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium">{{ $reason['label'] }}</span>
                            <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ $reason['description'] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>

            <x-input-error :messages="$errors->get('reason')" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="adjusted_at" :label="__('When')" required
                         :hint="__('The moment being corrected for. Usually now.')">
                    <x-text-input id="adjusted_at" name="adjusted_at" type="datetime-local" class="block w-full" required
                                  :value="old('adjusted_at', $adjustment->adjusted_at?->format('Y-m-d\TH:i'))" />
                </x-field>

                <x-field name="note" :label="__('Note')" :hint="__('Optional. A line for whoever reads this in six months.')">
                    <x-text-input id="note" name="note" class="block w-full" autocomplete="off"
                                  :value="old('note', $adjustment->note)" />
                </x-field>
            </div>
        </div>
    </x-card>

    <x-card :title="__('What is being corrected')"
            :description="__('Scan an item to put it on the list. Scan it again to count one more.')">
        {{-- Not a <form>: this sits inside the page's form, and a scanner's
             Enter must add the item rather than submit the correction. --}}
        <div class="flex gap-2">
            <input x-model="code" x-ref="scanner" type="text" inputmode="text" autocomplete="off"
                   enterkeyhint="done" placeholder="{{ __('Scan or type a barcode, name or SKU') }}"
                   x-on:keydown.enter.prevent="scan()"
                   class="tap-target block w-full rounded-md border-gray-300 font-mono text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">

            <button type="button" x-on:click="scan()" x-bind:disabled="busy"
                    class="tap-target inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-gray-900 px-4 text-sm font-semibold text-white hover:bg-gray-700 disabled:opacity-50 dark:bg-gray-700 dark:hover:bg-gray-600">
                <x-icon name="scan" class="h-4.5 w-4.5" />
                <span class="hidden sm:inline">{{ __('Add') }}</span>
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

        <template x-if="isEmpty">
            <p class="mt-4 rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                {{ __('Nothing on the list yet. Scan the first item.') }}
            </p>
        </template>

        <ul class="mt-3 space-y-2">
            <template x-for="(row, index) in rows" x-bind:key="row.product_id">
                <li class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                    <input type="hidden" x-bind:name="`items[${index}][product_id]`" x-bind:value="row.product_id">

                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold" x-text="row.name"></p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                <span x-text="row.sku"></span>
                                · {{ __('on the shelf now:') }} <span x-text="row.stock_words"></span>
                            </p>
                        </div>

                        <button type="button" x-on:click="remove(index)"
                                class="tap-target shrink-0 rounded-lg px-2 text-gray-400 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-gray-800"
                                aria-label="{{ __('Remove this item') }}">
                            <x-icon name="close" class="h-4.5 w-4.5" />
                        </button>
                    </div>

                    <div class="mt-3 grid gap-2 sm:grid-cols-12">
                        <div class="sm:col-span-3">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400"
                                   x-text="quantityLabel"></label>
                            <input type="number" min="0" step="1" inputmode="numeric"
                                   x-model="row.qty"
                                   x-bind:name="`items[${index}][qty]`"
                                   class="tap-target mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                        </div>

                        <div class="sm:col-span-4">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Size') }}</label>
                            <select x-model="row.product_unit_id" x-bind:name="`items[${index}][product_unit_id]`"
                                    class="tap-target mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <template x-for="unit in row.units" x-bind:key="unit.id">
                                    <option x-bind:value="unit.id" x-text="unit.label"></option>
                                </template>
                            </select>
                        </div>

                        <div class="sm:col-span-3" x-show="addsStock" x-cloak>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">
                                {{ __('Cost of one') }}
                            </label>
                            <input type="text" inputmode="decimal" placeholder="0.00"
                                   x-model="row.unit_cost"
                                   x-bind:name="`items[${index}][unit_cost]`"
                                   class="tap-target mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                        </div>

                        <div class="flex items-end sm:col-span-2">
                            <p class="w-full text-sm font-semibold tabular-nums sm:text-right"
                               x-bind:class="effectClass(row)"
                               x-text="effectInWords(row)"></p>
                        </div>
                    </div>
                </li>
            </template>
        </ul>

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400" x-show="! isEmpty" x-cloak>
            <span x-text="movingLines"></span>
            {{ __('of') }} <span x-text="rows.length"></span>
            {{ __('lines would change something. Nothing is written until you post.') }}
        </p>

        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400" x-show="isRecount" x-cloak>
            {{ __('A recount sets the balance to what you counted. If a sale is rung up while you are counting, the difference is worked out again at the moment you post, so nothing is counted twice.') }}
        </p>
    </x-card>
</div>
