@php
    use App\Support\Money;
    use App\Support\Packaging;

    $selectClass = 'tap-target block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

<x-app-layout :title="__('What to order')">
    <x-flash />

    <x-page-header :title="__('What to order')"
                   :description="__('Everything at or below its reorder level, grouped under the supplier it last came from. Tick what you want and start the order.')">
        <x-ai-insight-button />
    </x-page-header>

    <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3">
        <x-stat-card :label="__('Items running low')" :value="number_format($lineCount)"
                     icon="alert" :tone="$lineCount > 0 ? 'warning' : 'neutral'" />

        <x-stat-card :label="__('Roughly what it costs')" :value="Money::rounded($totalPaisa)" icon="wallet"
                     :hint="__('At the last price paid')" />
    </div>

    @error('product_ids')
        <p class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-400">{{ $message }}</p>
    @enderror

    @if ($groups->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="check"
                           :title="__('Nothing needs ordering')"
                           :description="__('No item is at or below its reorder level. Set a reorder level on an item to have it appear here when it runs low.')">
                <a href="{{ route('products.index') }}"
                   class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                    {{ __('Go to items') }}
                </a>
            </x-empty-state>
        </div>
    @else
        <div class="mt-5 space-y-5">
            @foreach ($groups as $group)
                @php
                    $lineCosts = $group['lines']
                        ->mapWithKeys(fn (array $line): array => [(string) $line['product']->id => $line['packs'] * $line['cost_per_pack_paisa']])
                        ->all();
                @endphp

                <form method="POST" action="{{ route('purchases.suggestions.store') }}"
                      x-data="{ picked: @js(array_keys($lineCosts)), costs: @js($lineCosts),
                                get total() { return this.picked.reduce((sum, id) => sum + (this.costs[id] ?? 0), 0) } }">
                    @csrf

                    <x-card :title="$group['supplier']?->displayName() ?? __('Never bought through the app')"
                            :description="$group['supplier']?->phone ?? ($group['supplier'] ? null : __('Pick who to order these from below.'))"
                            :padded="false">
                        <x-slot:actions>
                            <span class="text-sm font-semibold tabular-nums" x-text="money.rounded(total)">
                                {{ Money::rounded($group['total_paisa']) }}
                            </span>
                        </x-slot:actions>

                        <ul>
                            @foreach ($group['lines'] as $line)
                                @php
                                    $product = $line['product'];
                                    $unitName = $line['unit']?->unit?->name ?? $product->baseUnit?->name ?? '';
                                @endphp

                                <li class="border-b border-gray-100 last:border-b-0 dark:border-gray-800">
                                    <label class="flex cursor-pointer items-start gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                                        <input type="checkbox" name="product_ids[]" value="{{ $product->id }}"
                                               x-model="picked" checked
                                               class="mt-0.5 h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900">

                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-semibold">{{ $product->name }}</p>
                                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                                {{ __(':stock left, reorder at :level', [
                                                    'stock' => $product->stock_qty_base > 0 ? $product->stockBreakdown() : __('none'),
                                                    'level' => Packaging::describe($product->reorder_level_base, $product->productUnits),
                                                ]) }}
                                            </p>
                                            <p class="mt-1 text-sm">
                                                <span class="font-medium">{{ $line['packs'] }} {{ Str::lower(Str::plural($unitName, $line['packs'])) }}</span>
                                                @if ($line['unit'] && $line['unit']->conversion_factor > 1)
                                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                                        ({{ number_format($line['packs'] * $line['unit']->conversion_factor) }} {{ Str::lower(Str::plural($product->baseUnit?->name ?? '', $line['packs'] * $line['unit']->conversion_factor)) }})
                                                    </span>
                                                @endif
                                            </p>
                                        </div>

                                        <div class="shrink-0 text-right">
                                            @if ($line['cost_per_pack_paisa'] > 0)
                                                <x-money :paisa="$line['packs'] * $line['cost_per_pack_paisa']" rounded class="block text-sm font-medium" />
                                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                                    {{ __(':price each', ['price' => Money::rounded($line['cost_per_pack_paisa'])]) }}
                                                </p>
                                            @else
                                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('no price yet') }}</p>
                                            @endif
                                        </div>
                                    </label>
                                </li>
                            @endforeach
                        </ul>

                        <div class="flex flex-col gap-2 border-t border-gray-100 p-4 sm:flex-row sm:items-end dark:border-gray-800">
                            <div class="flex-1">
                                <label for="supplier_{{ $loop->index }}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('Order from') }}
                                </label>
                                <select id="supplier_{{ $loop->index }}" name="supplier_id" class="{{ $selectClass }} mt-1">
                                    <option value="">{{ __('No supplier — I will buy it at the market') }}</option>
                                    @foreach ($suppliers as $supplier)
                                        <option value="{{ $supplier->id }}" @selected($group['supplier']?->id === $supplier->id)>{{ $supplier->displayName() }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <button type="submit" x-bind:disabled="picked.length === 0"
                                    class="tap-target inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">
                                <x-icon name="truck" class="h-4.5 w-4.5" />
                                <span x-text="picked.length === 1 ? @js(__('Start an order for 1 item')) : @js(__('Start an order for :count items')).replace(':count', picked.length)">
                                    {{ __('Start an order') }}
                                </span>
                            </button>
                        </div>
                    </x-card>
                </form>
            @endforeach
        </div>

        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
            {{ __('Starting an order saves it as a delivery that has not arrived. Nothing changes in stock until you receive it.') }}
        </p>
    @endif
</x-app-layout>
