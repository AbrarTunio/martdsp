<x-app-layout :title="__('Stock takes')">
    <x-flash />

    <x-page-header :title="__('Stock takes')"
                   :description="__('Counting what is really on the shelves, and what the difference cost.')">
        <x-ai-insight-button />

        <a href="{{ route('stock.takes.create') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
            <x-icon name="scan" class="h-4.5 w-4.5" />
            {{ __('Start a count') }}
        </a>
    </x-page-header>

    @if ($open > 0)
        <div class="mt-4 flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3 text-sm dark:border-amber-500/30 dark:bg-amber-500/10">
            <x-icon name="alert" class="mt-px h-4.5 w-4.5 shrink-0 text-amber-600 dark:text-amber-400" />
            <p class="flex-1">
                {{ trans_choice(
                    ':count count is still open, so stock has not changed from it yet.|:count counts are still open, so stock has not changed from them yet.',
                    $open,
                    ['count' => number_format($open)]
                ) }}
                <a href="{{ route('stock.takes.index', ['status' => 'draft']) }}"
                   class="font-medium text-brand-700 hover:underline dark:text-brand-400">{{ __('Show them') }}</a>
            </p>
        </div>
    @endif

    <form method="GET" action="{{ route('stock.takes.index') }}" class="mt-4"
          x-data x-on:change="$el.requestSubmit()">
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <select name="status"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="">{{ __('Every count') }}</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <noscript>
            <button type="submit" class="tap-target mt-2 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white">
                {{ __('Filter') }}
            </button>
        </noscript>
    </form>

    @if ($takes->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="scan"
                           :title="array_filter($filters) ? __('Nothing matched') : __('No counts yet')"
                           :description="__('Pick one section — the dairy fridge, the biscuit aisle — and scan everything on it. Doing one section a week catches theft and damage long before the month-end surprise.')">
                <a href="{{ route('stock.takes.create') }}"
                   class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="scan" class="h-4.5 w-4.5" />
                    {{ __('Start a count') }}
                </a>
            </x-empty-state>
        </div>
    @else
        <ul class="mt-4 space-y-2">
            @foreach ($takes as $take)
                <li class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <a href="{{ route('stock.takes.show', $take) }}"
                       class="flex items-start gap-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <p class="font-mono text-sm font-semibold">{{ $take->reference }}</p>
                                <x-badge :tone="$take->status->tone()">{{ $take->status->label() }}</x-badge>
                            </div>

                            <p class="mt-1 truncate text-sm font-medium">{{ $take->title() }}</p>

                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ ($take->posted_at ?? $take->started_at)->format('d M Y, g:i a') }}
                                @if ($take->user) · {{ $take->user->name }} @endif
                                · {{ trans_choice(':count item|:count items', $take->items_count, ['count' => number_format($take->items_count)]) }}
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            @can('see-financials')
                                @if ($take->status === \App\Enums\StockTakeStatus::Posted)
                                    <x-money :paisa="$take->variance_value_paisa" signed rounded class="block text-sm font-semibold" />
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('difference at cost') }}</p>
                                @endif
                            @endcan

                            <span class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-brand-700 dark:text-brand-400">
                                {{ $take->isEditable() ? __('Carry on') : __('Open') }}
                                <x-icon name="chevron-right" class="h-3.5 w-3.5" />
                            </span>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            {{ $takes->links() }}
        </div>
    @endif
</x-app-layout>
