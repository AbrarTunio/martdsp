{{--
    Shared by create and edit.

    The packaging card is the heart of it: the shopkeeper lists the sizes an
    item comes in and says what each one holds, and the flattened conversion
    factors are worked out on save by App\Services\PackagingService. The live
    preview here is the same arithmetic in the browser, so a typo is visible
    before it reaches the till.
--}}
@php
    $isEdit = $product->exists;

    /*
     | On a validation failure the packaging rows come back from old() as
     | plain strings. Otherwise they come from the product, or from nothing
     | at all on a fresh form.
     */
    $rows = old('levels') !== null ? array_values(old('levels')) : $levels;

    $defaultSaleIndex = collect($levels)->search(fn (array $level): bool => $level['is_default_sale']);
    $defaultPurchaseIndex = collect($levels)->search(fn (array $level): bool => $level['is_default_purchase']);

    $builder = [
        'units' => $units,
        'baseUnitId' => old('base_unit_id', $product->base_unit_id),
        'rows' => $rows,
        'defaultSale' => (int) old('default_sale', $defaultSaleIndex === false ? 0 : $defaultSaleIndex),
        'defaultPurchase' => (int) old('default_purchase', $defaultPurchaseIndex === false ? 0 : $defaultPurchaseIndex),
        'baseLocked' => $isEdit,
    ];

    $packagingErrors = collect($errors->getMessages())
        ->filter(fn ($messages, string $key): bool => str_starts_with($key, 'levels.'))
        ->flatten()
        ->unique();

    $selectClass = 'block w-full rounded-md border-gray-300 shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

<div class="space-y-5">
    <x-card :title="__('The item')">
        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="name" :label="__('Name')" required>
                    <x-text-input id="name" name="name" class="block w-full" required autofocus
                                  autocomplete="off" :value="old('name', $product->name)" />
                </x-field>

                <x-field name="name_ur" :label="__('Urdu name')" :hint="__('Optional. Shown on the till and on receipts.')">
                    <x-text-input id="name_ur" name="name_ur" class="block w-full font-urdu" dir="rtl"
                                  autocomplete="off" :value="old('name_ur', $product->name_ur)" />
                </x-field>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="category_id" :label="__('Category')">
                    <select id="category_id" name="category_id" class="{{ $selectClass }}">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($categories as $id => $name)
                            <option value="{{ $id }}" @selected((string) old('category_id', $product->category_id) === (string) $id)>
                                {{ $name }}
                            </option>
                        @endforeach
                    </select>
                </x-field>

                <x-field name="brand_id" :label="__('Brand')">
                    <select id="brand_id" name="brand_id" class="{{ $selectClass }}">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($brands as $id => $name)
                            <option value="{{ $id }}" @selected((string) old('brand_id', $product->brand_id) === (string) $id)>
                                {{ $name }}
                            </option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="sku" :label="__('Item code')"
                         :hint="__('Leave blank and one will be made for you. This is what you type when a barcode will not scan.')">
                    <x-text-input id="sku" name="sku" class="block w-full font-mono uppercase"
                                  autocomplete="off" :value="old('sku', $product->sku)" />
                </x-field>

                <x-field name="tax_rate" :label="__('GST rate (%)')" required
                         :hint="__('Most grocery lines follow the shop rate. Set 0 for items that are exempt.')">
                    <x-text-input id="tax_rate" name="tax_rate" type="number" step="0.01" min="0" max="100"
                                  inputmode="decimal" class="block w-full" required
                                  :value="old('tax_rate', $product->tax_rate)" />
                </x-field>
            </div>
        </div>
    </x-card>

    <x-card :title="__('Packaging and prices')"
            :description="__('List the sizes this item comes in and say what each one holds. The till works out the rest.')">
        @if ($packagingErrors->isNotEmpty())
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-500/30 dark:bg-red-500/10">
                <p class="text-sm font-medium text-red-800 dark:text-red-300">{{ __('The packaging needs a look:') }}</p>
                <ul class="mt-1 list-inside list-disc space-y-0.5 text-sm text-red-700 dark:text-red-400">
                    @foreach ($packagingErrors as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div x-data="packagingBuilder({{ Js::from($builder) }})" class="space-y-4">
            <x-field name="base_unit_id" :label="__('Smallest thing you sell')" required
                     :hint="$isEdit
                        ? __('This cannot be changed, because your stock count is measured in it.')
                        : __('One sachet, one bottle, one kilogram. Your stock is counted in this, so pick the smallest loose item a customer can walk out with.')">
                @if ($isEdit)
                    <input type="hidden" name="base_unit_id" value="{{ $product->base_unit_id }}">
                    <div class="tap-target flex items-center rounded-md border border-gray-200 bg-gray-50 px-3 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-800/50 dark:text-gray-400">
                        {{ $product->baseUnit?->name }}
                    </div>
                @else
                    <select id="base_unit_id" name="base_unit_id" required class="{{ $selectClass }}"
                            x-model.number="baseUnitId">
                        <option value="">{{ __('Choose one') }}</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->short_name }})</option>
                        @endforeach
                    </select>
                @endif
            </x-field>

            <template x-if="baseUnitId">
                <div class="space-y-3">
                    <template x-for="(row, index) in rows" x-bind:key="index">
                        <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800"
                             x-bind:class="isBase(row) && 'bg-gray-50 dark:bg-gray-800/40'">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"
                                   x-text="isBase(row) ? '{{ __('Loose / smallest') }}' : '{{ __('Pack') }}'"></p>

                                <button type="button" x-show="! isBase(row)" x-on:click="removeRow(index)"
                                        class="text-xs font-medium text-red-600 hover:underline dark:text-red-400">
                                    {{ __('Remove') }}
                                </button>
                            </div>

                            <div class="mt-2 grid gap-3 sm:grid-cols-12">
                                <div class="sm:col-span-4">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Size') }}</label>
                                    <select x-bind:name="`levels[${index}][unit_id]`" x-model.number="row.unit_id"
                                            x-bind:disabled="isBase(row)" required
                                            class="{{ $selectClass }} mt-1 text-sm disabled:bg-gray-100 dark:disabled:bg-gray-800">
                                        <option value="">{{ __('Choose') }}</option>
                                        <template x-for="unit in availableUnits(row)" x-bind:key="unit.id">
                                            <option x-bind:value="unit.id" x-text="unit.name"></option>
                                        </template>
                                    </select>
                                    {{-- A disabled select posts nothing, so the base row carries its own value. --}}
                                    <template x-if="isBase(row)">
                                        <input type="hidden" x-bind:name="`levels[${index}][unit_id]`" x-bind:value="row.unit_id">
                                    </template>
                                </div>

                                <div class="sm:col-span-5">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Holds') }}</label>
                                    <template x-if="isBase(row)">
                                        <div>
                                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing — this is the smallest size.') }}</p>
                                            {{-- There is no box to fill in here, so the row posts the one
                                                 answer there is: the smallest size holds one of itself. --}}
                                            <input type="hidden" x-bind:name="`levels[${index}][qty_per_parent]`" value="1">
                                        </div>
                                    </template>
                                    <template x-if="! isBase(row)">
                                        <div class="mt-1 flex gap-2">
                                            <input type="number" min="1" step="1" inputmode="numeric" required
                                                   x-bind:name="`levels[${index}][qty_per_parent]`"
                                                   x-model="row.qty_per_parent" placeholder="24"
                                                   class="tap-target w-24 rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">

                                            <select x-bind:name="`levels[${index}][parent_unit_id]`"
                                                    x-model.number="row.parent_unit_id"
                                                    class="{{ $selectClass }} text-sm">
                                                <option value="">{{ __('of…') }}</option>
                                                <template x-for="option in parentOptions(row)" x-bind:key="option.id">
                                                    <option x-bind:value="option.id" x-text="option.name"></option>
                                                </template>
                                            </select>
                                        </div>
                                    </template>
                                </div>

                                <div class="sm:col-span-3">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Sells for (Rs.)') }}</label>
                                    <input type="text" inputmode="decimal" required
                                           x-bind:name="`levels[${index}][sale_price]`" x-model="row.sale_price"
                                           placeholder="0.00"
                                           class="tap-target mt-1 block w-full rounded-md border-gray-300 text-sm tabular-nums shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                </div>
                            </div>

                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                <span class="text-gray-500 dark:text-gray-400" x-text="summary(row)"></span>
                                <span class="text-gray-400 dark:text-gray-500" x-show="pricePerBase(row) !== null"
                                      x-text="pricePerBaseLabel(row)"></span>
                                <span x-show="isPricedAbove(row)"
                                      class="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">
                                    {{ __('Dearer per piece than buying loose — check the price.') }}
                                </span>
                            </div>

                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Barcodes') }}</label>
                                    <div class="mt-1 space-y-1.5">
                                        <template x-for="(code, position) in row.barcodes" x-bind:key="position">
                                            <div class="flex gap-1.5">
                                                <div class="relative min-w-0 flex-1">
                                                    <input type="text" autocomplete="off"
                                                           x-bind:name="`levels[${index}][barcodes][${position}]`"
                                                           x-model="row.barcodes[position]"
                                                           x-on:keydown.enter="barcodeEntered($event, index, position)"
                                                           placeholder="{{ __('Tap here, then scan') }}"
                                                           class="tap-target block w-full rounded-md border-gray-300 pr-9 font-mono text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                                    <x-icon name="check" x-show="justScanned === `${index}-${position}`" x-cloak
                                                            x-transition.opacity
                                                            class="pointer-events-none absolute top-1/2 right-2.5 h-5 w-5 -translate-y-1/2 text-brand-600 dark:text-brand-400" />
                                                </div>

                                                <button type="button" x-show="camera.supported" x-cloak
                                                        x-on:click="openCamera(index, position)"
                                                        class="tap-target grid shrink-0 place-items-center rounded-md border border-gray-300 px-2 text-gray-600 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                                        aria-label="{{ __('Scan with the camera') }}">
                                                    <x-icon name="camera" class="h-4.5 w-4.5" />
                                                </button>

                                                <button type="button" x-on:click="removeBarcode(row, position)"
                                                        class="tap-target grid shrink-0 place-items-center rounded-md border border-gray-300 px-2 text-gray-500 hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800"
                                                        aria-label="{{ __('Remove barcode') }}">
                                                    <x-icon name="close" class="h-4 w-4" />
                                                </button>
                                            </div>
                                        </template>
                                    </div>

                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        <span x-show="! camera.supported">{{ __('Tap the box, then scan the pack with the scanner.') }}</span>
                                        <span x-show="camera.supported" x-cloak>{{ __('Tap the box and scan, or press the camera to use the phone.') }}</span>
                                    </p>

                                    <button type="button" x-on:click="addBarcode(row)"
                                            class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline dark:text-brand-400">
                                        <x-icon name="plus" class="h-3.5 w-3.5" />
                                        {{ __('Another barcode') }}
                                    </button>
                                </div>

                                <div class="space-y-2">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Printed price (Rs.)') }}</label>
                                        <input type="text" inputmode="decimal" placeholder="{{ __('Optional') }}"
                                               x-bind:name="`levels[${index}][mrp]`" x-model="row.mrp"
                                               class="tap-target mt-1 block w-full rounded-md border-gray-300 text-sm tabular-nums shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                    </div>

                                    <div class="flex flex-wrap gap-3">
                                        <label class="flex items-center gap-1.5 text-xs">
                                            <input type="radio" name="default_sale" x-bind:value="index"
                                                   x-model.number="defaultSale"
                                                   class="h-4 w-4 border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                                            {{ __('Usual sale size') }}
                                        </label>

                                        <label class="flex items-center gap-1.5 text-xs">
                                            <input type="radio" name="default_purchase" x-bind:value="index"
                                                   x-model.number="defaultPurchase"
                                                   class="h-4 w-4 border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                                            {{ __('Usual buying size') }}
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <button type="button" x-on:click="addRow()" x-show="rows.length < 6"
                            class="tap-target inline-flex w-full items-center justify-center gap-1.5 rounded-xl border border-dashed border-gray-300 text-sm font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800/50">
                        <x-icon name="plus" class="h-4.5 w-4.5" />
                        {{ __('Add a bigger pack') }}
                    </button>
                </div>
            </template>

            <template x-if="! baseUnitId">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Choose the smallest size first, then the packs it comes in.') }}
                </p>
            </template>

            <div x-on:keydown.escape.window="sheet && closeSheet()">
                @include('pos.partials.camera-sheet')
            </div>
        </div>
    </x-card>

    <x-card :title="__('Stock control')"
            :description="__('Quantities here are in :unit, the smallest size.', ['unit' => $product->baseUnit?->name ?? __('the smallest size')])">
        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="reorder_level_base" :label="__('Tell me when stock falls to')"
                         :hint="__('Leave at 0 for no warning.')">
                    <x-text-input id="reorder_level_base" name="reorder_level_base" type="number" min="0" step="1"
                                  inputmode="numeric" class="block w-full"
                                  :value="old('reorder_level_base', $product->reorder_level_base ?? 0)" />
                </x-field>

                <x-field name="reorder_qty_base" :label="__('Suggest ordering')"
                         :hint="__('Used by the reorder list in phase 3.')">
                    <x-text-input id="reorder_qty_base" name="reorder_qty_base" type="number" min="0" step="1"
                                  inputmode="numeric" class="block w-full"
                                  :value="old('reorder_qty_base', $product->reorder_qty_base ?? 0)" />
                </x-field>
            </div>

            <x-settings.toggle name="is_weighted"
                               :label="__('Sold by weight')"
                               :hint="__('For loose rice, sugar or vegetables weighed at the counter.')"
                               :checked="(bool) old('is_weighted', $product->is_weighted ?? false)" />

            <x-settings.toggle name="track_expiry"
                               :label="__('Has an expiry date')"
                               :hint="__('Milk, bread, medicine. Batch and expiry entry arrives in phase 3.')"
                               :checked="(bool) old('track_expiry', $product->track_expiry ?? false)" />

            <x-settings.toggle name="is_active"
                               :label="__('Available at the till')"
                               :hint="__('Turn this off to hide a line you no longer stock. Its history is kept.')"
                               :checked="(bool) old('is_active', $product->is_active ?? true)" />

            <input type="hidden" name="track_batches" value="{{ (int) old('track_batches', $product->track_batches ?? false) }}">
        </div>
    </x-card>
</div>
