@php
    $selectClass = 'block w-full rounded-md border-gray-300 shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $smallInput = 'tap-target mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';

    $config = [
        'lookupUrl' => route('purchases.lookup'),
        'rows' => $rows,
        'suppliers' => $suppliers,
        'supplierId' => old('supplier_id', $return->supplier_id),
        'settlement' => old('settlement', $return->settlement?->value),
    ];

    $itemErrors = collect($errors->getMessages())
        ->filter(fn ($messages, string $key): bool => str_starts_with($key, 'items'))
        ->flatten()
        ->unique();

    $cancelUrl = $purchase ? route('purchases.show', $purchase) : route('purchases.returns.index');
@endphp

<x-app-layout :title="__('Send goods back')">
    <x-flash />

    <x-page-header :title="__('Send goods back')"
                   :description="$purchase
                       ? __('From :reference. Enter how many of each are going back; leave the rest empty.', ['reference' => $purchase->reference])
                       : __('Scan what the salesman is taking away. Stock goes down and the account is settled the moment you save.')" />

    <form method="POST" action="{{ route('purchases.returns.store') }}" class="mt-5">
        @csrf

        @if ($purchase)
            <input type="hidden" name="purchase_id" value="{{ $purchase->id }}">
        @endif

        <div class="space-y-5" x-data="purchaseReturn(@js($config))" x-on:keydown.enter="guardEnter($event)">
            <x-card :title="__('Who is taking it')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-field name="supplier_id" :label="__('Supplier')">
                            @if ($purchase)
                                <input type="hidden" name="supplier_id" value="{{ $purchase->supplier_id }}">
                                <p class="mt-1 text-sm font-medium">{{ $purchase->supplierName() }}</p>
                            @else
                                <select id="supplier_id" name="supplier_id" x-model="supplierId" class="{{ $selectClass }} mt-1">
                                    <option value="">{{ __('No supplier — bought for cash at the market') }}</option>
                                    @foreach ($suppliers as $supplier)
                                        <option value="{{ $supplier['id'] }}">{{ $supplier['label'] }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </x-field>
                        @error('purchase_id')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <x-field name="reason" :label="__('Why it is going back')" required>
                        <select id="reason" name="reason" class="{{ $selectClass }} mt-1" required>
                            @foreach ($reasons as $value => $label)
                                <option value="{{ $value }}" @selected(old('reason', $return->reason?->value) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field name="returned_at" :label="__('When')" required>
                        <x-text-input id="returned_at" name="returned_at" type="datetime-local" class="mt-1 block w-full" required
                                      :value="old('returned_at', $return->returned_at?->format('Y-m-d\TH:i'))"
                                      :max="now()->format('Y-m-d\TH:i')" />
                    </x-field>
                </div>
            </x-card>

            <x-card :title="__('What is going back')"
                    :description="__('Scan each packet or carton. Scan it again to count one more.')">
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
                        {{ __('Nothing listed yet. Scan the first item going back.') }}
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
                                        · {{ __('on the shelf now:') }} <span x-text="row.stock_words || '{{ __('none') }}'"></span>
                                    </p>
                                </div>

                                <button type="button" x-on:click="remove(row)"
                                        class="tap-target shrink-0 rounded-lg px-2 text-gray-400 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-gray-800"
                                        aria-label="{{ __('Remove this item') }}">
                                    <x-icon name="close" class="h-4.5 w-4.5" />
                                </button>
                            </div>

                            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-12">
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('How many') }}</label>
                                    <input type="number" min="0" step="1" inputmode="numeric" placeholder="0"
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
                                        {{ __('Back for one') }} <span x-text="unitName(row)"></span>
                                    </label>
                                    <input type="text" inputmode="decimal" placeholder="0.00"
                                           x-model="row.unit_cost" x-on:input="creditTyped(row)"
                                           x-bind:name="`items[${index}][unit_credit]`"
                                           class="{{ $smallInput }}">
                                </div>

                                <div class="flex items-end justify-end sm:col-span-3">
                                    <div class="text-right">
                                        <p class="text-sm font-semibold tabular-nums" x-text="format(linePaisa(row))"></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            <span x-text="piecesIn(row).toLocaleString('en-PK')"></span> {{ __('pieces off the shelf') }}
                                        </p>
                                    </div>
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

            <x-card :title="__('Settling up')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <fieldset>
                        <legend class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('How they made good') }}</legend>

                        <div class="mt-2 space-y-2">
                            @foreach ($settlements as $value => $label)
                                <label class="flex items-center gap-2 text-sm"
                                       @if ($value !== 'cash') x-bind:class="! hasSupplier && 'opacity-50'" @endif>
                                    <input type="radio" name="settlement" value="{{ $value }}" x-model="settlement"
                                           @if ($value !== 'cash') x-bind:disabled="! hasSupplier" @endif
                                           class="h-4 w-4 border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900">
                                    {{ __($label) }}
                                </label>
                            @endforeach
                        </div>

                        <p x-show="! hasSupplier" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('With no supplier there is no account to take it off, so it can only be cash back.') }}
                        </p>

                        @error('settlement')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    <x-field name="note" :label="__('Note')" :hint="__('Optional. The salesman\'s name or a credit note number helps later.')">
                        <x-text-input id="note" name="note" type="text" class="mt-1 block w-full" maxlength="255"
                                      :value="old('note')" />
                    </x-field>
                </div>

                <dl class="mt-4 space-y-1.5 border-t border-gray-200 pt-4 text-sm dark:border-gray-800">
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-600 dark:text-gray-400">{{ __('Pieces going back') }}</dt>
                        <dd class="tabular-nums" x-text="pieceCount.toLocaleString('en-PK')"></dd>
                    </div>
                    <div class="flex justify-between gap-3 text-base font-semibold">
                        <dt x-text="settlement === 'cash' ? @js(__('Cash back')) : @js(__('Off what you owe'))"></dt>
                        <dd class="tabular-nums" x-text="format(totalPaisa)"></dd>
                    </div>
                </dl>
            </x-card>

            <div class="sticky bottom-0 -mx-4 flex items-center justify-end gap-2 border-t border-gray-200 bg-white/95 px-4 py-3 pb-safe backdrop-blur sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0 dark:border-gray-800 dark:bg-gray-900/95 sm:dark:bg-transparent">
                <a href="{{ $cancelUrl }}"
                   class="tap-target inline-flex items-center rounded-lg px-4 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
                    {{ __('Cancel') }}
                </a>

                <button type="submit" x-on:click="confirmPost($event)" x-bind:disabled="! hasQuantities"
                        class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">
                    <x-icon name="check" class="h-4.5 w-4.5" />
                    {{ __('Send back') }}
                </button>
            </div>
        </div>
    </form>
</x-app-layout>
