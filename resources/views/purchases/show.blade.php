@php
    use App\Enums\PurchaseStatus;
    use App\Support\Money;

    $isDraft = $purchase->status === PurchaseStatus::Draft;
    $isReceived = $purchase->status === PurchaseStatus::Received;
    $pieceCount = $purchase->items->sum(fn ($item) => $item->qty_base ?: ($item->qty + $item->bonus_qty) * $item->factor());
@endphp

<x-app-layout :title="$purchase->reference">
    <x-flash />

    <x-page-header :title="$purchase->reference"
                   :description="$isReceived
                       ? __('Received. These goods are on the shelf and the bill is on the account.')
                       : ($isDraft
                           ? __('Saved but not received. Nothing on the shelf has changed yet.')
                           : __('Cancelled. Nothing was changed.'))">
        <x-ai-insight-button />

        @if ($isDraft)
            <a href="{{ route('purchases.edit', $purchase) }}"
               class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                {{ __('Edit') }}
            </a>

            <form method="POST" action="{{ route('purchases.receive', $purchase) }}"
                  x-data x-on:submit="if (! confirm('{{ __('Receive this delivery into stock? It cannot be edited afterwards.') }}')) $event.preventDefault()">
                @csrf
                <button type="submit"
                        class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="check" class="h-4.5 w-4.5" />
                    {{ __('Receive into stock') }}
                </button>
            </form>
        @elseif ($isReceived)
            <a href="{{ route('purchases.returns.create', ['purchase' => $purchase->id]) }}"
               class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                {{ __('Send some back') }}
            </a>
        @endif
    </x-page-header>

    <div class="mt-4 flex flex-wrap items-center gap-1.5">
        <x-badge :tone="$purchase->status->tone()">{{ $purchase->status->label() }}</x-badge>
        <span class="text-xs text-gray-500 dark:text-gray-400">
            @if ($purchase->supplier)
                <a href="{{ route('suppliers.show', $purchase->supplier) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-400">{{ $purchase->supplierName() }}</a>
            @else
                {{ $purchase->supplierName() }}
            @endif
            · {{ __('delivered') }} {{ $purchase->purchase_date->format('d M Y') }}
            @if ($purchase->invoice_no) · {{ __('bill') }} {{ $purchase->invoice_no }} @endif
            @if ($purchase->user) · {{ __('entered by') }} {{ $purchase->user->name }} @endif
            @if ($purchase->receiver) · {{ __('received by') }} {{ $purchase->receiver->name }} @endif
        </span>
    </div>

    @if ($purchase->note)
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $purchase->note }}</p>
    @endif

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
        <x-stat-card :label="__('Bill total')" :value="Money::withSymbol($purchase->total_paisa)" icon="truck"
                     :hint="$purchase->discount_paisa > 0 ? __('after :amount discount', ['amount' => Money::rounded($purchase->discount_paisa)]) : null" />

        <x-stat-card :label="__('Paid on delivery')" :value="Money::withSymbol($purchase->paid_paisa)" icon="wallet"
                     :hint="$purchase->payment_method?->label()" />

        @if ($purchase->supplier)
            <x-stat-card :label="__('Put on account')" :value="Money::withSymbol($purchase->unpaidPaisa())"
                         icon="book" :tone="$purchase->unpaidPaisa() > 0 ? 'warning' : 'neutral'"
                         :hint="$purchase->due_on ? __('due :date', ['date' => $purchase->due_on->format('d M Y')]) : null" />
        @endif

        <x-stat-card :label="__('Lines')" :value="number_format($purchase->items->count())" icon="box"
                     :hint="trans_choice(':count piece in total|:count pieces in total', $pieceCount, ['count' => number_format($pieceCount)])" />
    </div>

    <div class="mt-5 space-y-2">
        @foreach ($purchase->items as $item)
            @php
                $salePerBase = $item->product->defaultSaleUnit()?->pricePerBasePaisa() ?? 0;
                $marginPercent = $isReceived && $item->cost_base_paisa > 0 && $salePerBase > 0
                    ? round(($salePerBase - $item->cost_base_paisa) / $salePerBase * 100)
                    : null;
            @endphp

            <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('stock.show', $item->product) }}"
                           class="truncate text-sm font-semibold hover:underline">{{ $item->product->name }}</a>

                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ $item->product->sku }}
                            @if ($item->batch_no) · {{ __('batch') }} {{ $item->batch_no }} @endif
                            @if ($item->expiry_date) · {{ __('expires') }} {{ $item->expiry_date->format('d M Y') }} @endif
                        </p>

                        <p class="mt-1.5 text-sm">
                            <span class="font-medium">{{ $item->quantityInWords() }}</span>
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ __('at') }} {{ Money::withSymbol($item->unit_cost_paisa) }}
                            </span>
                        </p>

                        @if ($isReceived)
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __(':count pieces went on the shelf at :cost each', [
                                    'count' => number_format($item->qty_base),
                                    'cost' => Money::withSymbol($item->cost_base_paisa),
                                ]) }}
                                @can('see-financials')
                                    @if ($marginPercent !== null)
                                        ·
                                        <span @class([
                                            'font-medium',
                                            'text-money-out' => $marginPercent <= 0,
                                            'text-money-in' => $marginPercent > 0,
                                        ])>{{ __(':percent% margin at today\'s price', ['percent' => $marginPercent]) }}</span>
                                    @endif
                                @endcan
                            </p>
                        @endif
                    </div>

                    <div class="shrink-0 text-right">
                        <x-money :paisa="$item->line_total_paisa ?: $item->qty * $item->unit_cost_paisa" class="block text-sm font-semibold" />
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-4 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <dl class="ml-auto max-w-sm space-y-1.5 text-sm">
            <div class="flex justify-between gap-3">
                <dt class="text-gray-600 dark:text-gray-400">{{ __('Items') }}</dt>
                <dd class="tabular-nums">{{ Money::withSymbol($purchase->subtotal_paisa) }}</dd>
            </div>
            @if ($purchase->discount_paisa > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-600 dark:text-gray-400">{{ __('Discount') }}</dt>
                    <dd class="tabular-nums text-money-in">− {{ Money::withSymbol($purchase->discount_paisa) }}</dd>
                </div>
            @endif
            @if ($purchase->tax_paisa > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-600 dark:text-gray-400">{{ __('Tax') }}</dt>
                    <dd class="tabular-nums">+ {{ Money::withSymbol($purchase->tax_paisa) }}</dd>
                </div>
            @endif
            <div class="flex justify-between gap-3 border-t border-gray-200 pt-1.5 font-semibold dark:border-gray-700">
                <dt>{{ __('Total') }}</dt>
                <dd class="tabular-nums">{{ Money::withSymbol($purchase->total_paisa) }}</dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-gray-600 dark:text-gray-400">{{ __('Paid on delivery') }}</dt>
                <dd class="tabular-nums">{{ Money::withSymbol($purchase->paid_paisa) }}</dd>
            </div>
        </dl>
    </div>

    @if ($purchase->returns->isNotEmpty())
        <x-card :title="__('Sent back from this delivery')" :padded="false" class="mt-4">
            @foreach ($purchase->returns as $return)
                <a href="{{ route('purchases.returns.show', $return) }}"
                   class="flex items-center gap-3 border-b border-gray-100 px-4 py-2.5 last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/60">
                    <div class="min-w-0 flex-1">
                        <p class="font-mono text-sm font-medium">{{ $return->reference }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $return->returned_at->format('d M Y') }} · {{ $return->reason->label() }}
                        </p>
                    </div>
                    <x-money :paisa="$return->total_paisa" class="shrink-0 text-sm font-medium" />
                </a>
            @endforeach
        </x-card>
    @endif

    @if ($isDraft)
        <div class="mt-5 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-sm font-semibold">{{ __('Not coming after all?') }}</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Cancelling leaves stock and the supplier\'s account exactly as they are, and keeps the record that this was started.') }}
            </p>

            <form method="POST" action="{{ route('purchases.destroy', $purchase) }}" class="mt-3"
                  x-data x-on:submit="if (! confirm('{{ __('Cancel this delivery? Nothing will change.') }}')) $event.preventDefault()">
                @csrf
                @method('DELETE')

                <button type="submit"
                        class="tap-target inline-flex items-center rounded-lg border border-red-300 px-4 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-500/40 dark:text-red-400 dark:hover:bg-red-500/10">
                    {{ __('Cancel this delivery') }}
                </button>
            </form>
        </div>
    @endif
</x-app-layout>
