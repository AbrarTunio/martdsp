@php
    use App\Support\Money;

    $isCash = $return->settlement->isCash();
@endphp

<x-app-layout :title="$return->reference">
    <x-flash />

    <x-page-header :title="$return->reference"
                   :description="$isCash
                       ? __('These goods came back and the customer was paid out of the drawer.')
                       : __('These goods came back and the amount came off what the customer owes.')">
        <x-ai-insight-button />

        @if ($return->sale)
            <a href="{{ route('sales.show', $return->sale) }}"
               class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                <x-icon name="receipt" class="h-4.5 w-4.5" />
                {{ __('Open the bill') }}
            </a>
        @endif
    </x-page-header>

    <div class="mt-4 flex flex-wrap items-center gap-1.5">
        <x-badge :tone="$return->reason->tone()">{{ $return->reason->label() }}</x-badge>

        @if ($return->restocked)
            <x-badge tone="success">{{ __('back on the shelf') }}</x-badge>
        @else
            <x-badge tone="danger">{{ __('not restocked') }}</x-badge>
        @endif

        <span class="text-xs text-gray-500 dark:text-gray-400">
            @if ($return->customer)
                <a href="{{ route('customers.show', $return->customer) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-400">{{ $return->customerName() }}</a>
            @else
                {{ $return->customerName() }}
            @endif
            · {{ $return->returned_at->format('d M Y, g:i A') }}
            @if ($return->sale)
                · {{ __('off') }}
                <a href="{{ route('sales.show', $return->sale) }}" class="font-mono font-medium text-brand-700 hover:underline dark:text-brand-400">{{ $return->sale->invoiceNumber() }}</a>
            @endif
            @if ($return->register) · {{ $return->register->name }} @endif
            @if ($return->user) · {{ __('by') }} {{ $return->user->name }} @endif
        </span>
    </div>

    @if ($return->note)
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $return->note }}</p>
    @endif

    @unless ($return->restocked)
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            <p class="font-semibold">{{ __('Nothing went back on the shelf.') }}</p>
            <p class="mt-1">
                {{ __('Goods returned as :reason are not fit to sell again, so stock was left as it was. The whole refund is a loss on the shop.', [
                    'reason' => strtolower($return->reason->label()),
                ]) }}
            </p>
        </div>
    @endunless

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-3">
        <x-stat-card :label="$isCash ? __('Handed back in cash') : __('Off what they owe')"
                     :value="Money::withSymbol($return->total_paisa)" icon="wallet"
                     :hint="$return->tax_paisa > 0 ? __('includes :amount GST', ['amount' => Money::rounded($return->tax_paisa)]) : null" />

        @can('see-financials')
            <x-stat-card :label="__('What the goods cost')" :value="Money::withSymbol($return->cost_value_paisa)" icon="box"
                         :hint="__('At what they cost on the day they were sold')" />

            <x-stat-card :label="$return->lossPaisa() > 0 ? __('Lost on this return') : __('Nothing lost')"
                         :value="Money::withSymbol(abs($return->lossPaisa()))"
                         icon="alert" :tone="$return->lossPaisa() > 0 ? 'danger' : 'success'"
                         :hint="$return->restocked
                             ? __('The profit on these goods is given up, the goods themselves are not')
                             : __('The refund plus the goods, both gone')" />
        @endcan
    </div>

    <div class="mt-5 space-y-2">
        @foreach ($return->items as $item)
            <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        @if ($item->product)
                            <a href="{{ route('stock.show', $item->product) }}"
                               class="truncate text-sm font-semibold hover:underline">{{ $item->saleItem?->name ?? $item->product->name }}</a>
                        @else
                            <p class="truncate text-sm font-semibold">{{ $item->saleItem?->name ?? __('Item') }}</p>
                        @endif

                        <p class="mt-1 text-sm">
                            <span class="font-medium">{{ $item->qtyForInput() }} {{ $item->saleItem?->unit_name }}</span>
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ __('of') }} {{ $item->saleItem?->qtyForInput() }} {{ __('sold') }}
                            </span>
                        </p>

                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ __(':amount back for one', ['amount' => Money::withSymbol($item->unit_refund_paisa)]) }}
                            @if ($item->tax_paisa > 0)
                                · {{ __('GST :amount', ['amount' => Money::withSymbol($item->tax_paisa)]) }}
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
</x-app-layout>
