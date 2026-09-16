@php
    use App\Enums\StockTakeStatus;
    use App\Support\Money;
    use App\Support\Packaging;

    $isDraft = $take->status === StockTakeStatus::Draft;
    $isPosted = $take->status === StockTakeStatus::Posted;

    $inWords = fn (int $qtyBase, $product): string => $qtyBase === 0
        ? __('none')
        : Packaging::describe(abs($qtyBase), $product->productUnits);

    $canSeeMoney = (bool) auth()->user()?->can('see-financials');
    $shortValue = (int) $short->sum('variance_value_paisa');
    $overValue = (int) $over->sum('variance_value_paisa');
@endphp

<x-app-layout :title="$take->reference">
    <x-flash />

    <x-page-header :title="$take->reference"
                   :description="$isPosted
                       ? __('Posted. Stock was set to what was counted, and the differences are in the ledger.')
                       : ($isDraft
                           ? __('Still counting. Nothing on the books has changed yet.')
                           : __('Abandoned. Nothing was changed.'))">
        <x-ai-insight-button />

        @if ($isDraft)
            <a href="{{ route('stock.takes.edit', $take) }}"
               class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                <x-icon name="scan" class="h-4.5 w-4.5" />
                {{ __('Carry on counting') }}
            </a>

            <form method="POST" action="{{ route('stock.takes.post', $take) }}"
                  x-data x-on:submit="if (! confirm(@js(__('Post this count? Stock will be set to what was counted.')))) $event.preventDefault()">
                @csrf
                <button type="submit"
                        class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="check" class="h-4.5 w-4.5" />
                    {{ __('Post') }}
                </button>
            </form>
        @endif
    </x-page-header>

    <x-input-error :messages="$errors->get('take')" class="mt-3" />

    <div class="mt-4 flex flex-wrap items-center gap-1.5">
        <x-badge :tone="$take->status->tone()">{{ $take->status->label() }}</x-badge>
        <span class="text-sm font-medium">{{ $take->title() }}</span>
        <span class="text-xs text-gray-500 dark:text-gray-400">
            · {{ __('started') }} {{ $take->started_at->format('d M Y, g:i a') }}
            @if ($take->user) {{ __('by') }} {{ $take->user->name }} @endif
            @if ($take->posted_at) · {{ __('posted') }} {{ $take->posted_at->format('d M Y, g:i a') }} @endif
            @if ($take->poster) {{ __('by') }} {{ $take->poster->name }} @endif
        </span>
    </div>

    @if ($take->missing_are_zero && $take->category)
        <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">
            {{ __('Anything in :section that was not scanned is taken as gone.', ['section' => $take->category->fullName()]) }}
        </p>
    @endif

    @if ($take->note)
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $take->note }}</p>
    @endif

    @if ($isPosted)
        <div class="mt-4 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
            @can('see-financials')
                <x-stat-card :label="__('Overall difference')"
                             :value="($take->variance_value_paisa >= 0 ? '+ ' : '− ').Money::rounded(abs($take->variance_value_paisa))"
                             icon="chart"
                             :tone="$take->variance_value_paisa < 0 ? 'danger' : 'success'"
                             :hint="__('At what the stock cost you')" />
            @endcan

            <x-stat-card :label="__('Items counted')" :value="number_format($items->count())" icon="box" />

            <x-stat-card :label="__('Short')" :value="number_format($short->count())" icon="alert"
                         :tone="$short->isNotEmpty() ? 'danger' : 'neutral'"
                         :hint="$canSeeMoney && $shortValue !== 0 ? '− '.Money::rounded(abs($shortValue)) : __('Fewer on the shelf than the books said')" />

            <x-stat-card :label="__('Over')" :value="number_format($over->count())" icon="layers"
                         :tone="$over->isNotEmpty() ? 'warning' : 'neutral'"
                         :hint="$canSeeMoney && $overValue !== 0 ? '+ '.Money::rounded($overValue) : __('More on the shelf than the books said')" />
        </div>

        @foreach ([
            ['lines' => $short, 'title' => __('Short'), 'description' => __('Fewer on the shelf than the books said. The biggest losses come first.')],
            ['lines' => $over, 'title' => __('Over'), 'description' => __('More on the shelf than the books said — often a delivery that was never entered, or a sale rung up as the wrong item.')],
        ] as $group)
            @if ($group['lines']->isNotEmpty())
                <section class="mt-6">
                    <h2 class="text-base font-semibold">{{ $group['title'] }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $group['description'] }}</p>

                    <ul class="mt-3 space-y-2">
                        @foreach ($group['lines'] as $item)
                            <li class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                                <div class="flex items-start gap-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <a href="{{ route('stock.show', $item->product) }}"
                                               class="truncate text-sm font-semibold hover:underline">{{ $item->product->name }}</a>

                                            @unless ($item->was_counted)
                                                <x-badge tone="danger">{{ __('Not found') }}</x-badge>
                                            @endunless
                                        </div>

                                        <p class="mt-1 text-sm">
                                            <span class="text-gray-500 dark:text-gray-400">{{ __('Counted') }}:</span>
                                            <span class="font-medium">{{ $item->was_counted ? $item->countInWords() : __('none') }}</span>
                                            <span class="text-gray-500 dark:text-gray-400">
                                                · {{ __('books said') }} {{ (int) $item->system_qty_base < 0 ? '−' : '' }}{{ $inWords((int) $item->system_qty_base, $item->product) }}
                                            </span>
                                        </p>
                                    </div>

                                    <div class="shrink-0 text-right">
                                        <p @class([
                                            'text-sm font-semibold tabular-nums',
                                            'text-money-in' => $item->isOver(),
                                            'text-money-out' => $item->isShort(),
                                        ])>
                                            {{ $item->isOver() ? '+' : '−' }} {{ $inWords($item->variance_base, $item->product) }}
                                        </p>

                                        @can('see-financials')
                                            @if ($item->variance_value_paisa !== 0)
                                                <x-money :paisa="$item->variance_value_paisa" signed class="mt-1 block text-xs" />
                                            @endif
                                        @endcan
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endforeach

        @if ($matched->isNotEmpty())
            <details class="mt-6 rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <summary class="tap-target flex cursor-pointer items-center gap-2 px-3 text-sm font-medium">
                    <x-icon name="check" class="h-4.5 w-4.5 text-money-in" />
                    {{ trans_choice(':count item matched the books exactly|:count items matched the books exactly', $matched->count(), ['count' => number_format($matched->count())]) }}
                </summary>

                <ul class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                    @foreach ($matched as $item)
                        <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <span class="truncate">{{ $item->product->name }}</span>
                            <span class="shrink-0 text-gray-500 dark:text-gray-400">{{ $item->countInWords() }}</span>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    @else
        @if ($items->isEmpty())
            <div class="mt-5">
                <x-empty-state icon="scan" :title="__('Nothing counted yet')"
                               :description="__('Carry on counting to scan the first item.')" />
            </div>
        @else
            <ul class="mt-5 space-y-2">
                @foreach ($items as $item)
                    <li class="flex items-start gap-3 rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('stock.show', $item->product) }}"
                               class="truncate text-sm font-semibold hover:underline">{{ $item->product->name }}</a>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $item->product->sku }}</p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="text-sm font-semibold">{{ $item->countInWords() }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Counted') }} {{ ($item->counted_at ?? $take->started_at)->format('g:i a') }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

    @if ($isDraft)
        <div class="mt-5 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-sm font-semibold">{{ __('Not going to finish this one?') }}</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Abandoning leaves stock exactly as it is and keeps the record that this count was started.') }}
            </p>

            <form method="POST" action="{{ route('stock.takes.destroy', $take) }}" class="mt-3"
                  x-data x-on:submit="if (! confirm(@js(__('Abandon this count? Stock will not change.')))) $event.preventDefault()">
                @csrf
                @method('DELETE')

                <button type="submit"
                        class="tap-target inline-flex items-center rounded-lg border border-red-300 px-4 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-500/40 dark:text-red-400 dark:hover:bg-red-500/10">
                    {{ __('Abandon this count') }}
                </button>
            </form>
        </div>
    @endif
</x-app-layout>
