<x-app-layout :title="$product->name">
    <x-flash />

    <x-page-header :title="$product->name"
                   :description="__('Every movement, newest first. Each line shows the balance it left behind.')">
        <x-ai-insight-button />

        @can('supervise')
            <a href="{{ route('stock.adjustments.create', ['product' => $product->id]) }}"
               class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4.5 w-4.5" />
                {{ __('Correct') }}
            </a>
        @endcan
    </x-page-header>

    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
        {{ $product->sku }}
        @if ($product->category) · {{ $product->category->fullName() }} @endif
        @if ($product->brand) · {{ $product->brand->name }} @endif
        @can('supervise')
            · <a href="{{ route('products.edit', $product) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-400">{{ __('Edit product') }}</a>
        @endcan
    </p>

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
        <x-stat-card :label="__('On the shelf')" :value="$product->stockBreakdown()"
                     icon="layers"
                     :tone="$product->stock_qty_base < 0 ? 'danger' : ($product->isLowOnStock() ? 'warning' : 'neutral')"
                     :hint="number_format($product->stock_qty_base).' '.$product->baseUnit?->name" />

        <x-stat-card :label="__('Reorder at')"
                     :value="$product->reorder_level_base > 0 ? number_format($product->reorder_level_base) : '—'"
                     :hint="$product->reorder_level_base > 0 ? $product->baseUnit?->name : __('No level set')" />

        @can('see-financials')
            <x-stat-card :label="__('Average cost')"
                         :value="\App\Support\Money::withSymbol($product->avg_cost_base_paisa)"
                         :hint="__('per :unit', ['unit' => $product->baseUnit?->name])" />

            <x-stat-card :label="__('Stock value')"
                         :value="\App\Support\Money::rounded($product->stockValuePaisa())"
                         icon="chart"
                         :hint="__('At what you paid')" />
        @endcan
    </div>

    @if ($product->stock_qty_base < 0)
        <div class="mt-3 flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3 text-sm dark:border-amber-500/30 dark:bg-amber-500/10">
            <x-icon name="alert" class="mt-px h-4.5 w-4.5 shrink-0 text-amber-600 dark:text-amber-400" />
            <p class="flex-1">
                {{ __('This item is below zero, which cannot be true on the shelf.') }}
                <span class="text-gray-600 dark:text-gray-400">
                    {{ __('Usually a delivery has been sold before it was entered. Enter the delivery, then recount.') }}
                </span>
            </p>
        </div>
    @endif

    <form method="GET" action="{{ route('stock.show', $product) }}" class="mt-4"
          x-data x-on:change="$el.requestSubmit()">
        <select name="type"
                class="tap-target w-full rounded-md border-gray-300 text-sm shadow-xs sm:w-64 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
            <option value="">{{ __('Every kind of movement') }}</option>
            @foreach ($types as $value => $label)
                <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <noscript>
            <button type="submit" class="tap-target mt-2 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white">
                {{ __('Filter') }}
            </button>
        </noscript>
    </form>

    @if ($movements->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="layers"
                           :title="($filters['type'] ?? '') ? __('No movements of that kind') : __('Nothing has moved yet')"
                           :description="__('Once you enter opening stock, take a delivery, or ring up a sale, every change lands here and never leaves.')">
                @can('supervise')
                    <a href="{{ route('stock.adjustments.create', ['product' => $product->id, 'reason' => 'opening']) }}"
                       class="text-sm font-medium text-brand-700 hover:underline dark:text-brand-400">
                        {{ __('Enter opening stock for this item') }}
                    </a>
                @endcan
            </x-empty-state>
        </div>
    @else
        <ul class="mt-4 space-y-2">
            @foreach ($movements as $movement)
                <li class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <x-badge :tone="$movement->type->tone()">{{ $movement->type->label() }}</x-badge>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $movement->occurred_at->format('d M Y, g:i a') }}
                                </span>
                            </div>

                            <p class="mt-1.5 text-sm font-semibold">
                                <span @class([
                                    'text-money-in' => $movement->isIncrease(),
                                    'text-money-out' => ! $movement->isIncrease(),
                                ])>{{ $movement->isIncrease() ? '+' : '−' }} {{ $movement->quantityInWords() }}</span>
                            </p>

                            @if ($movement->note)
                                <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">{{ $movement->note }}</p>
                            @endif

                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                @if ($movement->user) {{ $movement->user->name }} @else {{ __('System') }} @endif
                                @php
                                    $referenceUrl = match (true) {
                                        $movement->reference instanceof \App\Models\StockAdjustment => route('stock.adjustments.show', $movement->reference),
                                        $movement->reference instanceof \App\Models\Purchase => route('purchases.show', $movement->reference),
                                        $movement->reference instanceof \App\Models\PurchaseReturn => route('purchases.returns.show', $movement->reference),
                                        default => null,
                                    };
                                @endphp
                                @if ($referenceUrl && auth()->user()->can('supervise'))
                                    · <a href="{{ $referenceUrl }}"
                                         class="font-medium text-brand-700 hover:underline dark:text-brand-400">{{ $movement->reference->reference }}</a>
                                @elseif ($referenceUrl)
                                    · <span class="font-medium">{{ $movement->reference->reference }}</span>
                                @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="text-sm font-semibold tabular-nums">{{ number_format($movement->balance_after_base) }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('left') }}</p>

                            @can('see-financials')
                                <p class="mt-1.5 text-xs text-gray-600 dark:text-gray-400">
                                    {{ __('at') }} {{ \App\Support\Money::withSymbol($movement->unit_cost_base_paisa) }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('avg') }} {{ \App\Support\Money::withSymbol($movement->avg_cost_after_paisa) }}
                                </p>
                            @endcan
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            {{ $movements->links() }}
        </div>
    @endif
</x-app-layout>
