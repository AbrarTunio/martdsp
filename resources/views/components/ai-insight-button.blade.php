@props(['page' => null, 'scope' => null])

@php
    /**
     * Which set of figures this page belongs to. Pages that have nothing to
     * explain get no button at all, rather than one that says nothing.
     */
    $page ??= \App\Support\Insights\InsightRegistry::forRoute(request()->route()?->getName(), request()->route('key'));

    /**
     * What the person is looking at: the dates they picked, and the customer
     * when they are on one khata.
     */
    $scope ??= array_filter([
        'period' => request()->query('period'),
        'from' => request()->query('from'),
        'to' => request()->query('to'),
        'customer' => request()->route('customer')?->getKey(),
    ], fn ($value) => filled($value));
@endphp

@if ($page && auth()->check() && auth()->user()->can('use-insights'))
    <div x-data="insights({
            url: '{{ route('insights.show', $page) }}',
            token: '{{ csrf_token() }}',
            scope: @js($scope),
            strings: {
                failed: '{{ __('The note could not be written. Please try again.') }}',
                tooMany: '{{ __('That is a lot of asking in one minute. Give it a moment.') }}',
            },
        })" class="relative">
        <button type="button" x-on:click="show()"
                class="tap-target flex items-center gap-1.5 rounded-lg px-2.5 text-gray-500 hover:bg-gray-100 hover:text-brand-700 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-brand-300"
                aria-label="{{ __('Explain this page') }}">
            <x-icon name="sparkles" class="h-5 w-5" />
            <span class="hidden text-sm font-medium sm:inline">{{ __('Insights') }}</span>
        </button>

        <div x-show="open" x-on:click="close()" x-transition.opacity x-cloak
             class="fixed inset-0 z-40 bg-gray-900/50"></div>

        <div x-show="open" x-cloak
             x-on:keydown.escape.window="close()"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-y-full sm:translate-y-0 sm:translate-x-full"
             x-transition:enter-end="translate-y-0 sm:translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="translate-y-0 sm:translate-x-0"
             x-transition:leave-end="translate-y-full sm:translate-y-0 sm:translate-x-full"
             class="scroll-slim fixed inset-x-0 bottom-0 z-50 max-h-[calc(100dvh-4rem)] overflow-y-auto overscroll-contain rounded-t-2xl border-t border-gray-200 bg-white pb-safe dark:border-gray-700 dark:bg-gray-900 sm:inset-y-0 sm:right-0 sm:left-auto sm:w-96 sm:max-h-none sm:rounded-none sm:border-t-0 sm:border-l"
             role="dialog" aria-modal="true">
            <div class="sticky top-0 flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
                <p class="flex items-center gap-2 text-sm font-semibold">
                    <x-icon name="sparkles" class="h-4.5 w-4.5 text-brand-600 dark:text-brand-400" />
                    {{ __('Insights') }}
                </p>

                <div class="flex items-center gap-1">
                    <button type="button" x-on:click="refresh()" x-bind:disabled="loading"
                            class="tap-target grid place-items-center rounded-lg text-gray-500 hover:bg-gray-100 disabled:opacity-40 dark:hover:bg-gray-800"
                            aria-label="{{ __('Write it again') }}">
                        <x-icon name="refresh" class="h-4.5 w-4.5" x-bind:class="loading && 'animate-spin'" />
                    </button>

                    <button type="button" x-on:click="close()"
                            class="tap-target grid place-items-center rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800"
                            aria-label="{{ __('Close') }}">
                        <x-icon name="close" class="h-5 w-5" />
                    </button>
                </div>
            </div>

            <div class="p-4">
                <div x-show="loading" class="space-y-3" aria-live="polite">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Reading this page\'s figures…') }}</p>
                    @foreach (range(1, 3) as $bar)
                        <div class="h-3 animate-pulse rounded bg-gray-200 dark:bg-gray-800" style="width: {{ [92, 78, 60][$loop->index] }}%"></div>
                    @endforeach
                </div>

                <p x-show="error" x-text="error" x-cloak
                   class="rounded-lg bg-red-50 p-3 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-200"></p>

                <div x-show="! loading" x-html="html" x-cloak></div>
            </div>
        </div>
    </div>
@endif
