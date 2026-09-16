@php
    use App\Enums\AdjustmentStatus;

    $isDraft = $adjustment->status === AdjustmentStatus::Draft;
    $isPosted = $adjustment->status === AdjustmentStatus::Posted;
@endphp

<x-app-layout :title="$adjustment->reference">
    <x-flash />

    <x-page-header :title="$adjustment->reference"
                   :description="$isPosted
                       ? __('Posted. Stock was corrected, and these lines are now part of the ledger.')
                       : ($isDraft
                           ? __('Saved but not posted. Nothing on the shelf has changed yet.')
                           : __('Cancelled. Nothing was changed.'))">
        <x-ai-insight-button />

        @if ($isDraft)
            <a href="{{ route('stock.adjustments.edit', $adjustment) }}"
               class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                {{ __('Edit') }}
            </a>

            <form method="POST" action="{{ route('stock.adjustments.post', $adjustment) }}">
                @csrf
                <button type="submit"
                        class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="check" class="h-4.5 w-4.5" />
                    {{ __('Post') }}
                </button>
            </form>
        @endif
    </x-page-header>

    <div class="mt-4 flex flex-wrap items-center gap-1.5">
        <x-badge :tone="$adjustment->reason->tone()">{{ $adjustment->reason->label() }}</x-badge>
        <x-badge :tone="$adjustment->status->tone()">{{ $adjustment->status->label() }}</x-badge>
        <span class="text-xs text-gray-500 dark:text-gray-400">
            {{ $adjustment->adjusted_at->format('d M Y, g:i a') }}
            @if ($adjustment->user) · {{ __('entered by') }} {{ $adjustment->user->name }} @endif
            @if ($adjustment->approver) · {{ __('posted by') }} {{ $adjustment->approver->name }} @endif
        </span>
    </div>

    @if ($adjustment->note)
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $adjustment->note }}</p>
    @endif

    @if ($isPosted)
        @can('see-financials')
            <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
                <x-stat-card :label="__('Effect on stock value')"
                             :value="($adjustment->value_paisa >= 0 ? '+ ' : '− ').\App\Support\Money::rounded(abs($adjustment->value_paisa))"
                             icon="chart"
                             :tone="$adjustment->value_paisa < 0 ? 'danger' : 'neutral'"
                             :hint="__('At what the stock cost you')" />

                <x-stat-card :label="__('Lines')" :value="number_format($adjustment->items->count())" icon="box" />

                <x-stat-card :label="__('Ledger rows written')" :value="number_format($adjustment->movements->count())"
                             icon="layers"
                             :hint="__('Lines that found no difference write nothing')" />
            </div>
        @endcan
    @endif

    <div class="mt-5 space-y-2">
        @foreach ($adjustment->items as $item)
            <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('stock.show', $item->product) }}"
                           class="truncate text-sm font-semibold hover:underline">{{ $item->product->name }}</a>

                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ $item->product->sku }}
                            @if ($item->note) · {{ $item->note }} @endif
                        </p>

                        <p class="mt-1.5 text-sm">
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ $adjustment->reason->isRecount() ? __('Counted') : __('Entered') }}:
                            </span>
                            <span class="font-medium">{{ $item->quantityInWords() }}</span>

                            @if ($isPosted && $item->system_qty_base !== null && $adjustment->reason->isRecount())
                                <span class="text-gray-500 dark:text-gray-400">
                                    · {{ __('system said :qty', ['qty' => number_format($item->system_qty_base)]) }}
                                </span>
                            @endif
                        </p>
                    </div>

                    <div class="shrink-0 text-right">
                        @if ($isPosted)
                            <p @class([
                                'text-sm font-semibold tabular-nums',
                                'text-money-in' => $item->qty_base > 0,
                                'text-money-out' => $item->qty_base < 0,
                                'text-gray-500 dark:text-gray-400' => $item->qty_base === 0,
                            ])>
                                @if ($item->qty_base === 0)
                                    {{ __('No change') }}
                                @else
                                    {{ $item->qty_base > 0 ? '+' : '−' }} {{ number_format(abs($item->qty_base)) }}
                                @endif
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $item->product->baseUnit?->name }}</p>

                            @can('see-financials')
                                @if ($item->value_paisa !== 0)
                                    <x-money :paisa="$item->value_paisa" signed class="mt-1 block text-xs" />
                                @endif
                            @endcan
                        @else
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Worked out when posted') }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if ($isDraft)
        <div class="mt-5 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-sm font-semibold">{{ __('Finished with this one?') }}</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Cancelling leaves stock exactly as it is and keeps the record that this was started.') }}
            </p>

            <form method="POST" action="{{ route('stock.adjustments.destroy', $adjustment) }}" class="mt-3"
                  x-data x-on:submit="if (! confirm('{{ __('Cancel this correction? Stock will not change.') }}')) $event.preventDefault()">
                @csrf
                @method('DELETE')

                <button type="submit"
                        class="tap-target inline-flex items-center rounded-lg border border-red-300 px-4 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-500/40 dark:text-red-400 dark:hover:bg-red-500/10">
                    {{ __('Cancel this correction') }}
                </button>
            </form>
        </div>
    @endif
</x-app-layout>
