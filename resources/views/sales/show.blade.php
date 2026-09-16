@php
    use App\Support\Money;

    $profitPaisa = $sale->grossProfitPaisa();
    $netPaisa = $sale->total_paisa - $sale->tax_paisa;
@endphp

<x-app-layout :title="$sale->invoiceNumber()">
    <x-flash />

    <x-page-header :title="$sale->invoiceNumber()"
                   :description="$sale->isVoid()
                       ? __('Cancelled. The goods went back on the shelf and nothing is owed on it.')
                       : __('Paid for and out of the shop.')">
        <x-ai-insight-button />

        <a href="{{ route('sales.receipt', ['sale' => $sale, 'print' => 1]) }}" target="_blank" rel="noopener"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-gray-900 px-4 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600">
            <x-icon name="printer" class="h-4.5 w-4.5" />
            {{ __('Print receipt') }}
        </a>
    </x-page-header>

    <div class="mt-4 flex flex-wrap items-center gap-1.5">
        <x-badge :tone="$sale->status->tone()">{{ $sale->status->label() }}</x-badge>
        <span class="text-xs text-gray-500 dark:text-gray-400">
            {{ $sale->sold_at?->format('d M Y, g:i A') }}
            @if ($sale->user) · {{ __('rung up by') }} {{ $sale->user->name }} @endif
            @if ($sale->register) · {{ $sale->register->name }} @endif
            · <span @class(['font-medium text-gray-700 dark:text-gray-300' => $sale->customer])>{{ $sale->customerName() }}</span>
        </span>
    </div>

    @if ($sale->note)
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $sale->note }}</p>
    @endif

    @if ($sale->isVoid())
        <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
            <p class="font-semibold">
                {{ __('Cancelled by :name on :date.', [
                    'name' => $sale->voider?->name ?? __('someone'),
                    'date' => $sale->voided_at?->format('d M Y, g:i A'),
                ]) }}
            </p>
            <p class="mt-1">{{ __('Reason:') }} {{ $sale->void_reason }}</p>
        </div>
    @endif

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
        <x-stat-card :label="__('Bill total')" :value="Money::withSymbol($sale->total_paisa)" icon="receipt"
                     :hint="$sale->discount_paisa > 0 ? __('after :amount discount', ['amount' => Money::rounded($sale->discount_paisa)]) : null" />

        <x-stat-card :label="__('Paid')" :value="Money::withSymbol($sale->paid_paisa)" icon="wallet"
                     :hint="$sale->change_given_paisa > 0 ? __(':amount given back', ['amount' => Money::withSymbol($sale->change_given_paisa)]) : null" />

        @if ($sale->due_paisa > 0)
            <x-stat-card :label="__('Put on khata')" :value="Money::withSymbol($sale->due_paisa)" icon="book"
                         :tone="$sale->isVoid() ? 'neutral' : 'warning'"
                         :hint="$sale->isVoid() ? __('taken back off the khata') : null" />
        @endif

        @can('see-financials')
            <x-stat-card :label="__('Profit on this bill')" :value="Money::withSymbol($profitPaisa)" icon="chart"
                         :tone="$profitPaisa < 0 ? 'danger' : 'success'"
                         :hint="$netPaisa > 0
                             ? __(':percent% margin · goods cost :cost', [
                                 'percent' => round($profitPaisa / $netPaisa * 100),
                                 'cost' => Money::rounded($sale->costPaisa()),
                             ])
                             : null" />
        @endcan
    </div>

    <div class="mt-5 space-y-2">
        @foreach ($sale->items as $item)
            <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        @if ($item->product_id)
                            <a href="{{ route('stock.show', $item->product_id) }}"
                               class="truncate text-sm font-semibold hover:underline">{{ $item->name }}</a>
                        @else
                            <p class="truncate text-sm font-semibold">{{ $item->name }}</p>
                        @endif

                        <p class="mt-1 text-sm">
                            <span class="font-medium">{{ $item->qtyForInput() }} {{ $item->unit_name }}</span>
                            <span class="text-gray-500 dark:text-gray-400">{{ __('at') }} {{ Money::withSymbol($item->unit_price_paisa) }}</span>
                        </p>

                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            @if ($item->totalDiscountPaisa() > 0)
                                <span class="text-money-in">{{ __(':amount off', ['amount' => Money::withSymbol($item->totalDiscountPaisa())]) }}</span>
                                @if ($item->discount) ({{ $item->discount }}) @endif
                                ·
                            @endif
                            @if ((float) $item->tax_rate > 0)
                                {{ __('GST :rate% = :amount', ['rate' => rtrim(rtrim((string) $item->tax_rate, '0'), '.'), 'amount' => Money::withSymbol($item->tax_paisa)]) }}
                            @else
                                {{ __('no GST') }}
                            @endif
                            @can('see-financials')
                                · {{ __('cost :amount', ['amount' => Money::withSymbol($item->costPaisa())]) }}
                            @endcan
                        </p>
                    </div>

                    <x-money :paisa="$item->line_total_paisa" class="shrink-0 text-sm font-semibold" />
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <x-card :title="__('How it was paid')" :padded="false">
            @foreach ($sale->payments as $payment)
                <div class="flex items-center gap-3 border-b border-gray-100 px-4 py-2.5 last:border-b-0 dark:border-gray-800">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ $payment->method->label() }}</p>
                        @if ($payment->reference || $payment->changePaisa() > 0)
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                @if ($payment->reference) {{ __('ref') }} {{ $payment->reference }} @endif
                                @if ($payment->changePaisa() > 0)
                                    {{ __(':tendered handed over, :change back', [
                                        'tendered' => Money::withSymbol($payment->tendered_paisa),
                                        'change' => Money::withSymbol($payment->changePaisa()),
                                    ]) }}
                                @endif
                            </p>
                        @endif
                    </div>
                    <x-money :paisa="$payment->amount_paisa" class="shrink-0 text-sm font-medium" />
                </div>
            @endforeach
        </x-card>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <dl class="space-y-1.5 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-600 dark:text-gray-400">{{ __('Items') }}</dt>
                    <dd class="tabular-nums">{{ Money::withSymbol($sale->subtotal_paisa) }}</dd>
                </div>
                @if ($sale->discount_paisa > 0)
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-600 dark:text-gray-400">
                            {{ __('Discount') }}
                            @if ($sale->bill_discount) <span class="text-xs">({{ __('bill') }} {{ $sale->bill_discount }})</span> @endif
                        </dt>
                        <dd class="tabular-nums text-money-in">− {{ Money::withSymbol($sale->discount_paisa) }}</dd>
                    </div>
                @endif
                @if ($sale->tax_paisa > 0)
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-600 dark:text-gray-400">{{ $sale->prices_include_tax ? __('GST (included)') : __('GST') }}</dt>
                        <dd class="tabular-nums">{{ $sale->prices_include_tax ? '' : '+ ' }}{{ Money::withSymbol($sale->tax_paisa) }}</dd>
                    </div>
                @endif
                @if ($sale->round_off_paisa !== 0)
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-600 dark:text-gray-400">{{ __('Rounded') }}</dt>
                        <dd class="tabular-nums"><x-money :paisa="$sale->round_off_paisa" signed /></dd>
                    </div>
                @endif
                <div class="flex justify-between gap-3 border-t border-gray-200 pt-1.5 font-semibold dark:border-gray-700">
                    <dt>{{ __('Total') }}</dt>
                    <dd class="tabular-nums">{{ Money::withSymbol($sale->total_paisa) }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <x-card :title="__('Reprint')" :description="__('Opens the receipt sized for the paper, ready for the print dialog.')" class="mt-4">
        <div class="flex flex-wrap gap-2">
            @foreach (['80' => __('80 mm roll'), '58' => __('58 mm roll'), 'a4' => __('A4 invoice')] as $paper => $label)
                <a href="{{ route('sales.receipt', ['sale' => $sale, 'paper' => $paper]) }}" target="_blank" rel="noopener"
                   class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                    <x-icon name="printer" class="h-4 w-4" />
                    {{ $label }}
                </a>
            @endforeach
        </div>

        @if ($counterPrinter)
            <form method="POST" action="{{ route('sales.print', $sale) }}" class="mt-3">
                @csrf
                <button type="submit"
                        class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-gray-900 px-3 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600">
                    <x-icon name="printer" class="h-4 w-4" />
                    {{ __('Send straight to :printer', ['printer' => $counterPrinter->name]) }}
                </button>
            </form>

            <x-input-error :messages="$errors->get('printer')" class="mt-2" />
        @endif
    </x-card>

    @can('supervise')
        @if ($sale->isVoidable())
            <div class="mt-5 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-sm font-semibold">{{ __('Rung up by mistake?') }}</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Cancelling puts the goods back in stock, takes any khata amount off the customer, and leaves the bill on record marked as cancelled. Any cash taken is handed back out of the :register drawer, which has to be open.', ['register' => $sale->register?->name ?? __('counter')]) }}
                </p>

                <form method="POST" action="{{ route('sales.void', $sale) }}" class="mt-3 space-y-2"
                      x-data x-on:submit="if (! confirm('{{ __('Cancel this bill? Stock and khata will be put back.') }}')) $event.preventDefault()">
                    @csrf

                    <input name="reason" required minlength="3" maxlength="255" autocomplete="off" value="{{ old('reason') }}"
                           placeholder="{{ __('Why — e.g. scanned twice, customer changed their mind') }}"
                           aria-label="{{ __('Reason') }}"
                           class="tap-target block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <x-input-error :messages="$errors->get('reason')" />

                    <button type="submit"
                            class="tap-target inline-flex items-center rounded-lg border border-red-300 px-4 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-500/40 dark:text-red-400 dark:hover:bg-red-500/10">
                        {{ __('Cancel this bill') }}
                    </button>
                </form>
            </div>
        @elseif ($sale->isCompleted())
            <p class="mt-5 text-sm text-gray-500 dark:text-gray-400">
                {{ __('Bills can only be cancelled on the day they were rung up. For an older bill, take the goods back as a return.') }}
            </p>
        @endif

        @if ($sale->isCompleted())
            <div class="mt-5 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-sm font-semibold">{{ __('Brought something back?') }}</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Take back part of the bill or all of it. The customer is paid what the line actually earned, GST and discount included, and sellable goods go back on the shelf.') }}
                </p>

                @if ($sale->returns->isNotEmpty())
                    <ul class="mt-3 space-y-1 text-sm">
                        @foreach ($sale->returns as $return)
                            <li class="flex items-center justify-between gap-3">
                                <a href="{{ route('sales.returns.show', $return) }}"
                                   class="font-mono font-medium text-brand-700 hover:underline dark:text-brand-400">{{ $return->reference }}</a>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $return->returned_at->format('d M Y') }}
                                    · {{ Money::withSymbol($return->total_paisa) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($sale->isReturnable())
                    <a href="{{ route('sales.returns.create', $sale) }}"
                       class="tap-target mt-3 inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                        <x-icon name="layers" class="h-4.5 w-4.5" />
                        {{ __('Take goods back') }}
                    </a>
                @else
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Everything on this bill has already come back.') }}
                    </p>
                @endif
            </div>
        @endif
    @endcan
</x-app-layout>
