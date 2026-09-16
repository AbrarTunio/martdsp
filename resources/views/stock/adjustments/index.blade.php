<x-app-layout :title="__('Stock corrections')">
    <x-flash />

    <x-page-header :title="__('Stock corrections')"
                   :description="__('Every time stock was changed by hand, and why.')">
        <x-ai-insight-button />

        <a href="{{ route('stock.adjustments.create') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
            <x-icon name="plus" class="h-4.5 w-4.5" />
            {{ __('New correction') }}
        </a>
    </x-page-header>

    @if ($drafts > 0)
        <div class="mt-4 flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3 text-sm dark:border-amber-500/30 dark:bg-amber-500/10">
            <x-icon name="alert" class="mt-px h-4.5 w-4.5 shrink-0 text-amber-600 dark:text-amber-400" />
            <p class="flex-1">
                {{ trans_choice(
                    ':count correction is saved but not posted, so stock has not changed.|:count corrections are saved but not posted, so stock has not changed.',
                    $drafts,
                    ['count' => number_format($drafts)]
                ) }}
                <a href="{{ route('stock.adjustments.index', ['status' => 'draft']) }}"
                   class="font-medium text-brand-700 hover:underline dark:text-brand-400">{{ __('Show them') }}</a>
            </p>
        </div>
    @endif

    <form method="GET" action="{{ route('stock.adjustments.index') }}" class="mt-4"
          x-data x-on:change="$el.requestSubmit()">
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <select name="reason"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="">{{ __('Every reason') }}</option>
                @foreach ($reasons as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['reason'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <select name="status"
                    class="tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="">{{ __('Posted and unposted') }}</option>
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

    @if ($adjustments->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="layers"
                           :title="array_filter($filters) ? __('Nothing matched') : __('No corrections yet')"
                           :description="__('Corrections are for the things a till cannot know: what was already on your shelves when you started, what broke, what expired, and what a recount found.')">
                <a href="{{ route('stock.adjustments.create') }}"
                   class="tap-target inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="plus" class="h-4.5 w-4.5" />
                    {{ __('New correction') }}
                </a>
            </x-empty-state>
        </div>
    @else
        <ul class="mt-4 space-y-2">
            @foreach ($adjustments as $adjustment)
                <li class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <a href="{{ route('stock.adjustments.show', $adjustment) }}"
                       class="flex items-start gap-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <p class="font-mono text-sm font-semibold">{{ $adjustment->reference }}</p>
                                <x-badge :tone="$adjustment->reason->tone()">{{ $adjustment->reason->label() }}</x-badge>

                                @unless ($adjustment->status === \App\Enums\AdjustmentStatus::Posted)
                                    <x-badge :tone="$adjustment->status->tone()">{{ $adjustment->status->label() }}</x-badge>
                                @endunless
                            </div>

                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ $adjustment->adjusted_at->format('d M Y, g:i a') }}
                                @if ($adjustment->user) · {{ $adjustment->user->name }} @endif
                            </p>

                            <p class="mt-1 text-sm">
                                {{ trans_choice(':count item|:count items', $adjustment->items_count, ['count' => $adjustment->items_count]) }}
                                @if ($adjustment->note)
                                    <span class="text-gray-600 dark:text-gray-400">· {{ $adjustment->note }}</span>
                                @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            @can('see-financials')
                                @if ($adjustment->status === \App\Enums\AdjustmentStatus::Posted)
                                    <x-money :paisa="$adjustment->value_paisa" signed rounded class="block text-sm font-semibold" />
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('at cost') }}</p>
                                @endif
                            @endcan

                            <span class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-brand-700 dark:text-brand-400">
                                {{ __('Open') }}
                                <x-icon name="chevron-right" class="h-3.5 w-3.5" />
                            </span>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            {{ $adjustments->links() }}
        </div>
    @endif
</x-app-layout>
