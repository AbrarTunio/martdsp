@php
    use App\Enums\SaleReturnReason;
    use App\Support\Money;

    $selectClass = 'block w-full rounded-md border-gray-300 shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';

    /* Every line of the bill, with how much of it is still to come back. The
       cashier types against the size the bill used, so the maths on screen is
       the maths on the paper in the customer's hand. */
    $lines = $sale->items->map(function ($item) {
        $factor = max(1, (int) ($item->productUnit?->conversion_factor ?? 1));

        return [
            'sale_item_id' => $item->id,
            'name' => $item->name,
            'unit_name' => $item->unit_name,
            'sold' => $item->qtyForInput(),
            'sold_base' => (int) $item->qty_base,
            'returnable' => rtrim(rtrim(number_format($item->returnableQtyBase() / $factor, 3, '.', ''), '0'), '.') ?: '0',
            'returnable_base' => $item->returnableQtyBase(),
            'factor' => $factor,
            'line_total_paisa' => (int) $item->line_total_paisa,
            'unit_refund_paisa' => $item->netUnitPricePaisa(),
            'qty' => '',
        ];
    })->values();

    $config = [
        'lines' => $lines,
        'reasons' => collect(SaleReturnReason::cases())->map(fn (SaleReturnReason $reason): array => [
            'value' => $reason->value,
            'label' => $reason->label(),
            'restocks' => $reason->goesBackOnTheShelf(),
        ])->values(),
        'reason' => old('reason', SaleReturnReason::ChangedMind->value),
        'settlement' => old('settlement', $sale->customer_id ? 'khata' : 'cash'),
        'hasCustomer' => (bool) $sale->customer_id,
    ];

    $itemErrors = collect($errors->getMessages())
        ->filter(fn ($messages, string $key): bool => str_starts_with($key, 'items'))
        ->flatten()
        ->unique();
@endphp

<x-app-layout :title="__('Take goods back')">
    <x-flash />

    <x-page-header :title="__('Take goods back')"
                   :description="__('Off :invoice, rung up :when. Enter how many of each are coming back and leave the rest empty.', [
                       'invoice' => $sale->invoiceNumber(),
                       'when' => $sale->sold_at?->format('d M Y, g:i A'),
                   ])" />

    <form method="POST" action="{{ route('sales.returns.store', $sale) }}" class="mt-5">
        @csrf

        <div class="space-y-5" x-data="saleReturn(@js($config))" x-on:keydown.enter="guardEnter($event)">
            <x-card :title="__('What is coming back')"
                    :description="__('Only what has not already been returned can be entered.')">
                @if ($itemErrors->isNotEmpty())
                    <ul class="mb-3 space-y-1 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
                        @foreach ($itemErrors as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                @endif

                <ul class="space-y-2">
                    <template x-for="(line, index) in lines" x-bind:key="line.sale_item_id">
                        <li class="rounded-lg border p-3 transition-colors"
                            x-bind:class="qtyBase(line) > 0
                                ? 'border-brand-400 bg-brand-50/60 dark:border-brand-500/60 dark:bg-brand-500/5'
                                : 'border-gray-200 dark:border-gray-800'">
                            <input type="hidden" x-bind:name="`items[${index}][sale_item_id]`" x-bind:value="line.sale_item_id">

                            <div class="flex items-start gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold" x-text="line.name"></p>
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('sold') }} <span x-text="`${line.sold} ${line.unit_name}`"></span>
                                        · <span x-text="format(line.unit_refund_paisa)"></span> {{ __('back for one') }}
                                    </p>
                                </div>

                                <div class="shrink-0 text-right">
                                    <p class="text-sm font-semibold tabular-nums" x-text="format(linePaisa(line))"></p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        <template x-if="line.returnable_base > 0">
                                            <span><span x-text="line.returnable"></span> {{ __('still to come back') }}</span>
                                        </template>
                                        <template x-if="line.returnable_base <= 0">
                                            <span>{{ __('already all back') }}</span>
                                        </template>
                                    </p>
                                </div>
                            </div>

                            <div class="mt-3 flex items-end gap-2">
                                <div class="w-32">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400"
                                           x-bind:for="`qty-${line.sale_item_id}`">
                                        {{ __('Coming back') }}
                                    </label>
                                    <input type="text" inputmode="decimal" placeholder="0" autocomplete="off"
                                           x-bind:id="`qty-${line.sale_item_id}`"
                                           x-model="line.qty" x-bind:name="`items[${index}][qty]`"
                                           x-bind:disabled="line.returnable_base <= 0"
                                           class="tap-target mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                </div>

                                <button type="button" x-on:click="fill(line)" x-bind:disabled="line.returnable_base <= 0"
                                        class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:hover:bg-gray-800">
                                    {{ __('All of it') }}
                                </button>
                            </div>

                            <template x-if="isPartUnit(line)">
                                <p class="mt-2 flex items-start gap-1.5 text-xs text-amber-800 dark:text-amber-300">
                                    <x-icon name="alert" class="mt-px h-3.5 w-3.5 shrink-0" />
                                    <span>{{ __('This cannot come back in parts of a piece.') }}</span>
                                </p>
                            </template>

                            <template x-if="isOverReturn(line)">
                                <p class="mt-2 flex items-start gap-1.5 text-xs text-red-700 dark:text-red-400">
                                    <x-icon name="alert" class="mt-px h-3.5 w-3.5 shrink-0" />
                                    <span>
                                        {{ __('Only') }} <span x-text="line.returnable"></span>
                                        <span x-text="line.unit_name"></span> {{ __('is still to come back.') }}
                                    </span>
                                </p>
                            </template>
                        </li>
                    </template>
                </ul>

                <button type="button" x-on:click="clear()" x-show="hasQuantities"
                        class="mt-3 text-sm font-medium text-gray-500 hover:underline dark:text-gray-400">
                    {{ __('Start again') }}
                </button>
            </x-card>

            <x-card :title="__('Why, and what they get')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="reason" :label="__('Why it is coming back')" required>
                        <select id="reason" name="reason" x-model="reason" class="{{ $selectClass }} mt-1" required>
                            @foreach ($reasons as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <fieldset>
                        <legend class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('How they get it back') }}</legend>

                        <div class="mt-2 space-y-2">
                            @foreach ($settlements as $value => $label)
                                <label class="flex items-center gap-2 text-sm"
                                       @if ($value === 'khata') x-bind:class="! hasCustomer && 'opacity-50'" @endif>
                                    <input type="radio" name="settlement" value="{{ $value }}" x-model="settlement"
                                           @if ($value === 'khata') x-bind:disabled="! hasCustomer" @endif
                                           class="h-4 w-4 border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>

                        @unless ($sale->customer_id)
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('This bill has no customer on it, so there is no khata to put the refund on.') }}
                            </p>
                        @endunless

                        <x-input-error :messages="$errors->get('settlement')" class="mt-1" />
                    </fieldset>

                    @if ($registers->count() > 1)
                        <x-field name="register_id" :label="__('Counter the cash comes out of')"
                                 :hint="__('Its drawer has to be open.')">
                            <select id="register_id" name="register_id" class="{{ $selectClass }} mt-1"
                                    x-bind:disabled="settlement !== 'cash'">
                                @foreach ($registers as $id => $name)
                                    <option value="{{ $id }}" @selected((int) old('register_id', $sale->register_id) === (int) $id)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </x-field>
                    @else
                        <input type="hidden" name="register_id" value="{{ old('register_id', $sale->register_id ?? $registers->keys()->first()) }}">
                        <x-input-error :messages="$errors->get('register_id')" />
                    @endif

                    <x-field name="note" :label="__('Note')" :hint="__('Optional. What the customer said helps when the same item keeps coming back.')">
                        <x-text-input id="note" name="note" type="text" class="mt-1 block w-full" maxlength="255"
                                      :value="old('note')" />
                    </x-field>
                </div>

                <template x-if="! restocks">
                    <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                        {{ __('The customer is paid, but nothing goes back on the shelf — goods returned for this reason are not fit to sell again. Put them aside for binning.') }}
                    </p>
                </template>

                <dl class="mt-4 space-y-1.5 border-t border-gray-200 pt-4 text-sm dark:border-gray-800">
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-600 dark:text-gray-400">{{ __('Pieces coming back') }}</dt>
                        <dd class="tabular-nums" x-text="pieceCount.toLocaleString('en-PK')"></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-600 dark:text-gray-400">{{ __('Bill was') }}</dt>
                        <dd class="tabular-nums">{{ Money::withSymbol($sale->total_paisa) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 text-base font-semibold">
                        <dt x-text="settlement === 'cash' ? @js(__('Cash back')) : @js(__('Off what they owe'))"></dt>
                        <dd class="tabular-nums" x-text="format(totalPaisa)"></dd>
                    </div>
                </dl>
            </x-card>

            <div class="sticky bottom-0 -mx-4 flex items-center justify-end gap-2 border-t border-gray-200 bg-white/95 px-4 py-3 pb-safe backdrop-blur sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0 dark:border-gray-800 dark:bg-gray-900/95 sm:dark:bg-transparent">
                <a href="{{ route('sales.show', $sale) }}"
                   class="tap-target inline-flex items-center rounded-lg px-4 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
                    {{ __('Cancel') }}
                </a>

                <button type="submit" x-on:click="confirmPost($event)" x-bind:disabled="! canSave"
                        class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">
                    <x-icon name="check" class="h-4.5 w-4.5" />
                    {{ __('Take it back') }}
                </button>
            </div>
        </div>
    </form>
</x-app-layout>
