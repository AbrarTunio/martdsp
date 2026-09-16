@php
    use App\Enums\ReturnSettlement;
    use App\Support\Money;

    $isCash = $return->settlement === ReturnSettlement::Cash;
@endphp

<x-app-layout :title="$return->reference">
    <x-flash />

    <x-page-header :title="$return->reference"
                   :description="$isCash
                       ? __('These goods went back and the supplier paid cash for them.')
                       : __('These goods went back and were taken off what you owe.')">
        <x-ai-insight-button />
    </x-page-header>

    <div class="mt-4 flex flex-wrap items-center gap-1.5">
        <x-badge :tone="$return->reason->tone()">{{ $return->reason->label() }}</x-badge>
        <span class="text-xs text-gray-500 dark:text-gray-400">
            @if ($return->supplier)
                <a href="{{ route('suppliers.show', $return->supplier) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-400">{{ $return->supplierName() }}</a>
            @else
                {{ $return->supplierName() }}
            @endif
            · {{ $return->returned_at->format('d M Y, h:i A') }}
            @if ($return->purchase)
                · {{ __('from') }}
                <a href="{{ route('purchases.show', $return->purchase) }}" class="font-mono font-medium text-brand-700 hover:underline dark:text-brand-400">{{ $return->purchase->reference }}</a>
            @endif
            @if ($return->user) · {{ __('by') }} {{ $return->user->name }} @endif
        </span>
    </div>

    @if ($return->note)
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $return->note }}</p>
    @endif

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-3">
        <x-stat-card :label="$isCash ? __('Cash back') : __('Off what you owe')"
                     :value="Money::withSymbol($return->total_paisa)" icon="wallet" />

        @can('see-financials')
            <x-stat-card :label="__('What the goods cost')" :value="Money::withSymbol($return->cost_value_paisa)" icon="box"
                         :hint="__('At the average cost when they left')" />

            <x-stat-card :label="$return->lossPaisa() > 0 ? __('Lost on this return') : __('Nothing lost')"
                         :value="Money::withSymbol(abs($return->lossPaisa()))"
                         icon="alert" :tone="$return->lossPaisa() > 0 ? 'danger' : 'success'"
                         :hint="$return->lossPaisa() < 0 ? __('The supplier gave back more than they cost') : null" />
        @endcan
    </div>

    <div class="mt-5 space-y-2">
        @foreach ($return->items as $item)
            <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('stock.show', $item->product) }}"
                           class="truncate text-sm font-semibold hover:underline">{{ $item->product->name }}</a>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $item->product->sku }}</p>

                        <p class="mt-1.5 text-sm">
                            <span class="font-medium">{{ $item->quantityInWords() }}</span>
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ __('at') }} {{ Money::withSymbol($item->unit_credit_paisa) }}
                            </span>
                        </p>
                    </div>

                    <x-money :paisa="$item->line_total_paisa" class="shrink-0 text-sm font-semibold" />
                </div>
            </div>
        @endforeach
    </div>
</x-app-layout>
