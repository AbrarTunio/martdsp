{{--
    Shared by create and edit.

    Laid out in the order the job happens at the van: who it is from, then
    scan every carton, then check the bill's bottom line against the paper.
    The scanner box sits above the list and new lines go to the top, so on a
    phone the item just scanned is always the one in view.
--}}
@php
    $selectClass = 'block w-full rounded-md border-gray-300 shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $smallInput = 'tap-target mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';

    $rupees = fn (?int $paisa): string => $paisa ? number_format($paisa / 100, 2, '.', '') : '';

    $config = [
        'lookupUrl' => route('purchases.lookup'),
        'quickProductUrl' => route('purchases.quick-product'),
        'quickSupplierUrl' => route('purchases.quick-supplier'),
        'csrf' => csrf_token(),
        'rows' => $rows,
        'suppliers' => $suppliers,
        'units' => $units,
        'supplierId' => old('supplier_id', $purchase->supplier_id),
        'purchaseDate' => old('purchase_date', $purchase->purchase_date?->toDateString()),
        'discount' => old('discount', $rupees($purchase->discount_paisa)),
        'tax' => old('tax', $rupees($purchase->tax_paisa)),
        'paid' => old('paid', $rupees($purchase->paid_paisa)),
        'paymentMethod' => old('payment_method', $purchase->payment_method?->value),
    ];

    $itemErrors = collect($errors->getMessages())
        ->filter(fn ($messages, string $key): bool => str_starts_with($key, 'items'))
        ->flatten()
        ->unique();
@endphp

<div class="space-y-5" x-data="purchaseEntry(@js($config))" x-on:keydown.enter="guardEnter($event)">
    <x-card :title="__('The bill')">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                {{-- A shop's supplier list only grows, so the salesman is found
                     by typing any part of his name, his company or his number.
                     If he is not on it yet he is added right here, because
                     walking off to another screen loses the half-scanned bill. --}}
                <x-field name="supplier_id" :label="__('From')"
                         :hint="__('Type any part of the name, the company or the phone number.')">
                    <input type="hidden" name="supplier_id" x-bind:value="supplierId">

                    <template x-if="supplier">
                        <div class="flex items-center justify-between gap-3 rounded-md border border-gray-300 px-3 py-2 dark:border-gray-700">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold" x-text="supplier.label"></p>
                                <p class="truncate font-mono text-xs text-gray-500 dark:text-gray-400" x-text="supplier.phone"></p>
                            </div>
                            <button type="button" x-on:click="changeSupplier()"
                                    class="tap-target shrink-0 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                                {{ __('Change') }}
                            </button>
                        </div>
                    </template>

                    <div x-show="! supplier">
                        <input id="supplier_id" x-model="supplierSearch" x-ref="supplierSearch" type="search"
                               autocomplete="off" enterkeyhint="done"
                               x-on:keydown.enter.prevent="chooseFirstSupplier()"
                               placeholder="{{ __('Search a supplier, or leave it empty for a cash purchase') }}"
                               class="{{ $selectClass }}">

                        <ul class="scroll-slim mt-2 max-h-56 space-y-1 overflow-y-auto overscroll-contain" x-show="supplierMatches.length > 0">
                            <template x-for="match in supplierMatches" x-bind:key="match.id">
                                <li>
                                    <button type="button" x-on:click="chooseSupplier(match.id)"
                                            class="tap-target flex w-full items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 text-left hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800">
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-medium" x-text="match.label"></span>
                                            <span class="block truncate font-mono text-xs text-gray-500 dark:text-gray-400" x-text="match.phone"></span>
                                        </span>
                                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400"
                                              x-text="match.balance_paisa > 0 ? `owed ${format(match.balance_paisa)}` : ''"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>

                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400"
                           x-show="supplierSearch.trim() !== '' && supplierMatches.length === 0">
                            {{ __('Nobody on the list matches that.') }}
                        </p>

                        <button type="button" x-on:click="openNewSupplier()"
                                class="tap-target mt-2 inline-flex items-center gap-1.5 rounded-lg border border-dashed border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                            <x-icon name="plus" class="h-4.5 w-4.5" />
                            {{ __('Add a new supplier') }}
                        </button>
                    </div>
                </x-field>

                <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400" x-show="supplier" x-cloak>
                    <span x-text="supplier && supplier.terms > 0 ? `Pays in ${supplier.terms} days` : 'Cash on delivery'"></span>
                    ·
                    <span x-text="supplier && supplier.balance_paisa >= 0 ? `you owe them ${format(supplier ? supplier.balance_paisa : 0)} now` : `they hold ${format(supplier ? -supplier.balance_paisa : 0)} for you`"></span>
                </p>
                <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400" x-show="isCashPurchase">
                    {{ __('With no supplier the bill is paid in full on the spot, and nobody is owed anything.') }}
                </p>
            </div>

            <x-field name="invoice_no" :label="__('Their bill number')" :hint="__('Printed on the paper bill. Stops the same bill being entered twice.')">
                <x-text-input id="invoice_no" name="invoice_no" class="block w-full" autocomplete="off"
                              :value="old('invoice_no', $purchase->invoice_no)" />
            </x-field>

            <x-field name="purchase_date" :label="__('Delivered on')" required>
                <x-text-input id="purchase_date" name="purchase_date" type="date" class="block w-full" required
                              x-model="purchaseDate" :max="today()->toDateString()" />
            </x-field>
        </div>
    </x-card>

    <x-card :title="__('What arrived')"
            :description="__('Scan each carton or packet. Scan it again to count one more.')">
        <div class="flex gap-2">
            <input x-model="code" x-ref="scanner" type="text" inputmode="text" autocomplete="off"
                   enterkeyhint="done" placeholder="{{ __('Scan a barcode, or type a name') }}"
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
                {{ __('Nothing on the bill yet. Scan the first carton.') }}
            </p>
        </template>

        <ul class="mt-3 space-y-2">
            <template x-for="(row, index) in rows" x-bind:key="row.uid">
                <li class="rounded-lg border p-3 transition-colors"
                    x-bind:class="row.uid === lastUid
                        ? 'border-brand-400 bg-brand-50/60 dark:border-brand-500/60 dark:bg-brand-500/5'
                        : 'border-gray-200 dark:border-gray-800'">
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

                    <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-12">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('How many') }}</label>
                            <input type="number" min="0" step="1" inputmode="numeric"
                                   x-model="row.qty" x-bind:name="`items[${index}][qty]`"
                                   class="{{ $smallInput }}">
                        </div>

                        <div class="sm:col-span-4">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Size') }}</label>
                            <select x-model="row.product_unit_id" x-bind:name="`items[${index}][product_unit_id]`"
                                    x-on:change="unitChanged(row)"
                                    class="{{ $smallInput }}">
                                <template x-for="unit in row.units" x-bind:key="unit.id">
                                    <option x-bind:value="unit.id" x-text="unit.label"
                                            x-bind:selected="String(unit.id) === String(row.product_unit_id)"></option>
                                </template>
                            </select>
                        </div>

                        <div class="sm:col-span-3">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">
                                {{ __('Price of one') }} <span x-text="unitName(row)"></span>
                            </label>
                            <input type="text" inputmode="decimal" placeholder="0.00"
                                   x-model="row.unit_cost" x-on:input="costTyped(row)"
                                   x-bind:name="`items[${index}][unit_cost]`"
                                   class="{{ $smallInput }}">
                        </div>

                        <div class="flex items-end justify-end sm:col-span-3">
                            <div class="text-right">
                                <p class="text-sm font-semibold tabular-nums" x-text="format(linePaisa(row))"></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    <span x-text="piecesIn(row).toLocaleString('en-PK')"></span> {{ __('pieces in') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <button type="button" x-on:click="row.showMore = ! row.showMore" x-show="! row.showMore && ! row.track_expiry && ! row.track_batches"
                            class="mt-2 text-xs font-medium text-brand-700 hover:underline dark:text-brand-400">
                        {{ __('Free goods, batch or expiry') }}
                    </button>

                    <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-12"
                         x-show="row.showMore || row.track_expiry || row.track_batches">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Free') }}</label>
                            <input type="number" min="0" step="1" inputmode="numeric" placeholder="0"
                                   x-model="row.bonus_qty" x-bind:name="`items[${index}][bonus_qty]`"
                                   class="{{ $smallInput }}">
                        </div>

                        <div class="sm:col-span-4">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Batch') }}</label>
                            <input type="text" autocomplete="off"
                                   x-model="row.batch_no" x-bind:name="`items[${index}][batch_no]`"
                                   class="{{ $smallInput }}">
                        </div>

                        <div class="col-span-2 sm:col-span-4">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">
                                {{ __('Expires on') }}
                                <span x-show="row.track_expiry" class="text-red-600 dark:text-red-400">*</span>
                            </label>
                            <input type="date"
                                   x-model="row.expiry_date" x-bind:name="`items[${index}][expiry_date]`"
                                   class="{{ $smallInput }}">
                        </div>
                    </div>

                    <template x-if="warnings(row).length > 0">
                        <ul class="mt-2 space-y-1">
                            <template x-for="warning in warnings(row)" x-bind:key="warning">
                                <li class="flex items-start gap-1.5 text-xs text-amber-800 dark:text-amber-300">
                                    <x-icon name="alert" class="mt-px h-3.5 w-3.5 shrink-0" />
                                    <span x-text="warning"></span>
                                </li>
                            </template>
                        </ul>
                    </template>
                </li>
            </template>
        </ul>
    </x-card>

    <x-card :title="__('Bottom line')"
            :description="__('Check these against the paper bill. The discount and tax are shared across the items by value.')">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-4">
                <x-field name="discount" :label="__('Discount on the whole bill')">
                    <x-text-input id="discount" name="discount" inputmode="decimal" class="block w-full"
                                  autocomplete="off" placeholder="0.00" x-model="discount" />
                </x-field>

                <x-field name="tax" :label="__('GST / tax on the bill')" :hint="__('Only if it is added on top of the prices above.')">
                    <x-text-input id="tax" name="tax" inputmode="decimal" class="block w-full"
                                  autocomplete="off" placeholder="0.00" x-model="tax" />
                </x-field>

                <div x-show="! isCashPurchase" x-cloak>
                    <x-field name="paid" :label="__('Paid now')" :hint="__('What you handed over with this delivery. The rest goes on their account.')">
                        <div class="flex gap-2">
                            <x-text-input id="paid" name="paid" inputmode="decimal" class="block w-full"
                                          autocomplete="off" placeholder="0.00" x-model="paid" />
                            <button type="button" x-on:click="payInFull()"
                                    class="tap-target shrink-0 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                                {{ __('All of it') }}
                            </button>
                        </div>
                    </x-field>
                </div>

                <div x-show="showsPaymentMethod" x-cloak>
                    <x-field name="payment_method" :label="__('Paid by')">
                        <select id="payment_method" name="payment_method" x-model="paymentMethod" class="{{ $selectClass }}">
                            @foreach ($methods as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>

                <x-field name="note" :label="__('Note')">
                    <x-text-input id="note" name="note" class="block w-full" autocomplete="off"
                                  :value="old('note', $purchase->note)" />
                </x-field>
            </div>

            <dl class="h-fit space-y-2 rounded-lg bg-gray-50 p-4 text-sm dark:bg-gray-800/60">
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-600 dark:text-gray-400">
                        <span x-text="rows.length"></span> {{ __('lines') }},
                        <span x-text="pieceCount.toLocaleString('en-PK')"></span> {{ __('pieces') }}
                    </dt>
                    <dd class="tabular-nums" x-text="format(subtotalPaisa)"></dd>
                </div>
                <div class="flex justify-between gap-3" x-show="discountPaisa > 0" x-cloak>
                    <dt class="text-gray-600 dark:text-gray-400">{{ __('Discount') }}</dt>
                    <dd class="tabular-nums text-money-in" x-text="`− ${format(discountPaisa)}`"></dd>
                </div>
                <div class="flex justify-between gap-3" x-show="taxPaisa > 0" x-cloak>
                    <dt class="text-gray-600 dark:text-gray-400">{{ __('Tax') }}</dt>
                    <dd class="tabular-nums" x-text="`+ ${format(taxPaisa)}`"></dd>
                </div>
                <div class="flex justify-between gap-3 border-t border-gray-200 pt-2 text-base font-semibold dark:border-gray-700">
                    <dt>{{ __('Bill total') }}</dt>
                    <dd class="tabular-nums" x-text="format(totalPaisa)"></dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-600 dark:text-gray-400">{{ __('Paid now') }}</dt>
                    <dd class="tabular-nums" x-text="format(paidPaisa)"></dd>
                </div>

                <template x-if="supplier">
                    <div class="space-y-2 border-t border-gray-200 pt-2 dark:border-gray-700">
                        <div class="flex justify-between gap-3 font-medium">
                            <dt x-text="balanceAfterPaisa >= 0 ? 'You will owe them' : 'They will hold for you'"></dt>
                            <dd class="tabular-nums" x-text="format(Math.abs(balanceAfterPaisa))"></dd>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400" x-show="dueOn">
                            {{ __('This bill falls due on') }} <span x-text="dueOn"></span>.
                        </p>
                    </div>
                </template>

                <p class="border-t border-gray-200 pt-2 text-xs text-amber-800 dark:border-gray-700 dark:text-amber-300"
                   x-show="warningCount > 0" x-cloak>
                    <span x-text="warningCount"></span> {{ __('lines have something worth checking — see the notes under them.') }}
                </p>
            </dl>
        </div>
    </x-card>

    @include('purchases._actions')

    {{-- The sheet for a barcode the shop has never seen. --}}
    <div x-show="quick.open" x-cloak x-transition.opacity x-on:click="closeQuick()"
         class="fixed inset-0 z-[55] bg-gray-900/60 backdrop-blur-[2px]"></div>

    <div class="pointer-events-none fixed inset-0 z-[60] flex items-end justify-center pt-16 sm:items-center sm:p-8 sm:pt-20">
    <div x-show="quick.open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="translate-y-full sm:translate-y-4 sm:opacity-0"
         x-transition:enter-end="translate-y-0 sm:opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="translate-y-0 sm:opacity-100"
         x-transition:leave-end="translate-y-full sm:translate-y-4 sm:opacity-0"
         x-on:keydown.escape.window="quick.open && closeQuick()"
         class="scroll-slim pointer-events-auto max-h-full w-full overflow-y-auto overscroll-contain rounded-t-2xl border-t border-gray-200 bg-white pb-safe shadow-2xl dark:border-gray-700 dark:bg-gray-900 sm:w-[32rem] sm:rounded-2xl sm:border"
         role="dialog" aria-modal="true" aria-labelledby="quick-title">
        <div class="sticky top-0 flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
            <div>
                <p id="quick-title" class="text-sm font-semibold">{{ __('New product') }}</p>
                <p class="font-mono text-xs text-gray-500 dark:text-gray-400" x-text="quick.code"></p>
            </div>
            <button type="button" x-on:click="closeQuick()"
                    class="tap-target rounded-lg px-2 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800"
                    aria-label="{{ __('Close') }}">
                <x-icon name="close" class="h-5 w-5" />
            </button>
        </div>

        <div class="space-y-4 px-4 py-4">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('Nothing in the shop has this barcode yet. Add it now and it goes straight onto the bill — the rest of its details can be filled in later from Products.') }}
            </p>

            <div>
                <label for="quick_name" class="block text-sm font-medium">{{ __('Name') }} <span class="text-red-600">*</span></label>
                <input id="quick_name" x-ref="quickName" x-model="quick.name" type="text" autocomplete="off"
                       placeholder="{{ __('e.g. Nestlé Everyday 15g') }}" class="{{ $smallInput }}">
                <p class="mt-1 text-xs text-red-600 dark:text-red-400" x-show="quickError('name')" x-text="quickError('name')"></p>
                <p class="mt-1 text-xs text-red-600 dark:text-red-400" x-show="quickError('code')" x-text="quickError('code')"></p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="quick_category" class="block text-sm font-medium">{{ __('Category') }}</label>
                    <select id="quick_category" x-model="quick.category_id" class="{{ $smallInput }}">
                        <option value="">{{ __('Decide later') }}</option>
                        @foreach ($categories as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="quick_base" class="block text-sm font-medium">{{ __('Sold one at a time as a') }}</label>
                    <select id="quick_base" x-model="quick.base_unit_id" class="{{ $smallInput }}">
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400" x-show="quickError('base_unit_id')" x-text="quickError('base_unit_id')"></p>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" x-model="quick.has_pack"
                       class="rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                {{ __('It arrives in bigger packs (a carton, box or dozen)') }}
            </label>

            <div class="grid grid-cols-2 gap-3" x-show="quick.has_pack">
                <div>
                    <label for="quick_pack" class="block text-sm font-medium">{{ __('The pack is a') }}</label>
                    <select id="quick_pack" x-model="quick.pack_unit_id" class="{{ $smallInput }}">
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400" x-show="quickError('pack_unit_id')" x-text="quickError('pack_unit_id')"></p>
                </div>

                <div>
                    <label for="quick_qty" class="block text-sm font-medium">{{ __('How many in one pack') }}</label>
                    <input id="quick_qty" x-model="quick.qty_per_pack" type="number" min="2" step="1" inputmode="numeric"
                           class="{{ $smallInput }}">
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400" x-show="quickError('qty_per_pack')" x-text="quickError('qty_per_pack')"></p>
                </div>

                <fieldset class="col-span-2">
                    <legend class="block text-sm font-medium">{{ __('The barcode you scanned was on') }}</legend>
                    <div class="mt-1.5 flex flex-wrap gap-4">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" value="pack" x-model="quick.scanned_is"
                                   class="border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                            {{ __('the pack') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" value="base" x-model="quick.scanned_is"
                                   class="border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                            {{ __('a single piece') }}
                        </label>
                    </div>
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400" x-show="quickError('scanned_is')" x-text="quickError('scanned_is')"></p>
                </fieldset>
            </div>

            <div>
                <label for="quick_price" class="block text-sm font-medium">{{ __('You sell one piece for') }} <span class="text-red-600">*</span></label>
                <input id="quick_price" x-model="quick.sale_price" type="text" inputmode="decimal" placeholder="0.00"
                       class="{{ $smallInput }}">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" x-show="quick.has_pack && quick.qty_per_pack > 1 && quick.sale_price">
                    {{ __('A full pack will sell for') }}
                    <span class="font-medium" x-text="format(Math.round(parseFloat(quick.sale_price || 0) * 100) * Number(quick.qty_per_pack || 0))"></span>.
                </p>
                <p class="mt-1 text-xs text-red-600 dark:text-red-400" x-show="quickError('sale_price')" x-text="quickError('sale_price')"></p>
            </div>
        </div>

        <div class="sticky bottom-0 flex justify-end gap-2 border-t border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
            <button type="button" x-on:click="closeQuick()"
                    class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                {{ __('Not now') }}
            </button>
            <button type="button" x-on:click="saveQuick()" x-bind:disabled="quick.busy"
                    class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">
                <x-icon name="plus" class="h-4.5 w-4.5" />
                {{ __('Add and put on the bill') }}
            </button>
        </div>
    </div>
    </div>

    {{-- The sheet for a salesman who is not on the list yet. --}}
    <div x-show="newSupplier.open" x-cloak x-transition.opacity x-on:click="closeNewSupplier()"
         class="fixed inset-0 z-[55] bg-gray-900/60 backdrop-blur-[2px]"></div>

    <div class="pointer-events-none fixed inset-0 z-[60] flex items-end justify-center pt-16 sm:items-center sm:p-8 sm:pt-20">
    <div x-show="newSupplier.open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="translate-y-full sm:translate-y-4 sm:opacity-0"
         x-transition:enter-end="translate-y-0 sm:opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="translate-y-0 sm:opacity-100"
         x-transition:leave-end="translate-y-full sm:translate-y-4 sm:opacity-0"
         x-on:keydown.escape.window="newSupplier.open && closeNewSupplier()"
         class="scroll-slim pointer-events-auto max-h-full w-full overflow-y-auto overscroll-contain rounded-t-2xl border-t border-gray-200 bg-white pb-safe shadow-2xl dark:border-gray-700 dark:bg-gray-900 sm:w-[32rem] sm:rounded-2xl sm:border"
         role="dialog" aria-modal="true" aria-labelledby="new-supplier-title">
        <div class="sticky top-0 flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
            <p id="new-supplier-title" class="text-sm font-semibold">{{ __('New supplier') }}</p>
            <button type="button" x-on:click="closeNewSupplier()"
                    class="tap-target rounded-lg px-2 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800"
                    aria-label="{{ __('Close') }}">
                <x-icon name="close" class="h-5 w-5" />
            </button>
        </div>

        <div class="space-y-4 px-4 py-4">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('Enough to put this bill on their account. Their address, credit days and anything already owed can be filled in later from Suppliers.') }}
            </p>

            <div>
                <label for="new_supplier_name" class="block text-sm font-medium">{{ __('Name') }} <span class="text-red-600">*</span></label>
                <input id="new_supplier_name" x-ref="newSupplierName" x-model="newSupplier.name" type="text" autocomplete="off"
                       placeholder="{{ __('The salesman you deal with') }}" class="{{ $smallInput }}">
                <p class="mt-1 text-xs text-red-600 dark:text-red-400" x-show="newSupplierError('name')" x-text="newSupplierError('name')"></p>
            </div>

            <div>
                <label for="new_supplier_company" class="block text-sm font-medium">{{ __('Company') }}</label>
                <input id="new_supplier_company" x-model="newSupplier.company" type="text" autocomplete="off"
                       placeholder="{{ __('The name printed on their bills') }}" class="{{ $smallInput }}">
                <p class="mt-1 text-xs text-red-600 dark:text-red-400" x-show="newSupplierError('company')" x-text="newSupplierError('company')"></p>
            </div>

            <div>
                <label for="new_supplier_phone" class="block text-sm font-medium">{{ __('Phone') }} <span class="text-red-600">*</span></label>
                <div class="mt-1 flex gap-2">
                    <select aria-label="{{ __('Country code') }}"
                            class="w-24 shrink-0 rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                        <option value="+92">+92</option>
                    </select>
                    <input id="new_supplier_phone" x-model="newSupplier.phone" type="tel" inputmode="numeric"
                           maxlength="10" autocomplete="off" placeholder="3001234567"
                           x-on:input="newSupplier.phone = tidyNumber(newSupplier.phone)"
                           class="tap-target block w-full min-w-0 rounded-md border-gray-300 font-mono text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Leave off the 0 in front. This is what stops the same supplier being added twice.') }}
                </p>
                <p class="mt-1 text-xs text-red-600 dark:text-red-400" x-show="newSupplierError('phone')" x-text="newSupplierError('phone')"></p>
            </div>

            <div>
                <label for="new_supplier_terms" class="block text-sm font-medium">{{ __('Days to pay') }}</label>
                <input id="new_supplier_terms" x-model="newSupplier.payment_terms_days" type="number" min="0" max="365"
                       inputmode="numeric" class="{{ $smallInput }}">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('0 means cash on delivery.') }}</p>
                <p class="mt-1 text-xs text-red-600 dark:text-red-400" x-show="newSupplierError('payment_terms_days')" x-text="newSupplierError('payment_terms_days')"></p>
            </div>
        </div>

        <div class="sticky bottom-0 flex justify-end gap-2 border-t border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
            <button type="button" x-on:click="closeNewSupplier()"
                    class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                {{ __('Not now') }}
            </button>
            <button type="button" x-on:click="saveNewSupplier()" x-bind:disabled="newSupplier.busy"
                    class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">
                <x-icon name="plus" class="h-4.5 w-4.5" />
                {{ __('Add and use on this bill') }}
            </button>
        </div>
    </div>
    </div>
</div>
