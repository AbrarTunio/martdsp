@php
    use App\Support\ActivityAction;

    $selectClass = 'tap-target rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';

    /*
     | The subject is stored as a class name. The shopkeeper does not need the
     | namespace — "Sale #418" says everything.
     */
    $subject = static function ($entry): ?string {
        if ($entry->subject_type === null) {
            return null;
        }

        return class_basename($entry->subject_type).' #'.$entry->subject_id;
    };
@endphp

<x-app-layout :title="__('Activity')">
    <x-flash />

    <x-page-header :title="__('Activity')"
                   :description="__('Who did what, and when. Every bill cancelled, every khata written off, every drawer counted. Nothing here can be changed or removed.')" />

    <form method="GET" action="{{ route('activity.index') }}" class="mt-4"
          x-data x-on:change="$el.requestSubmit()">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
            <input type="date" name="from" value="{{ $from->toDateString() }}" max="{{ today()->toDateString() }}"
                   aria-label="{{ __('From') }}" class="{{ $selectClass }}">

            <input type="date" name="to" value="{{ $to->toDateString() }}" max="{{ today()->toDateString() }}"
                   aria-label="{{ __('To') }}" class="{{ $selectClass }}">

            <select name="area" aria-label="{{ __('Part of the shop') }}" class="{{ $selectClass }}">
                <option value="">{{ __('Everything') }}</option>
                @foreach ($areas as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['area'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <select name="user" aria-label="{{ __('Who') }}" class="{{ $selectClass }}">
                <option value="">{{ __('Everyone') }}</option>
                @foreach ($people as $id => $name)
                    <option value="{{ $id }}" @selected((string) ($filters['user'] ?? '') === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>

            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" autocomplete="off"
                   placeholder="{{ __('Search') }}" aria-label="{{ __('Search the log') }}" class="{{ $selectClass }}">
        </div>

        <noscript>
            <button type="submit" class="tap-target mt-2 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white">
                {{ __('Filter') }}
            </button>
        </noscript>
    </form>

    @if ($entries->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="clock"
                           :title="__('Nothing recorded')"
                           :description="__('Nothing was done in the shop between those two dates — or nothing that matches the filter. Try widening the dates.')" />
        </div>
    @else
        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
            {{ trans_choice(':count thing recorded|:count things recorded', $entries->total(), ['count' => number_format($entries->total())]) }}
            · {{ $from->format('d M Y') }} – {{ $to->format('d M Y') }}
        </p>

        <ul class="mt-2 space-y-2">
            @foreach ($entries as $entry)
                <li class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-start gap-3">
                        <span @class([
                            'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                            'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => ActivityAction::tone($entry->action) === 'neutral',
                            'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400' => ActivityAction::tone($entry->action) === 'danger',
                        ])>
                            <x-icon :name="ActivityAction::icon($entry->action)" class="h-4.5 w-4.5" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <p class="text-sm font-medium">{{ ActivityAction::label($entry->action) }}</p>

                                @if ($subject($entry))
                                    <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $subject($entry) }}</span>
                                @endif
                            </div>

                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ $entry->user?->name ?? __('The shop itself') }}
                                · {{ $entry->created_at->format('d M, g:i A') }}
                                @if ($entry->ip) · {{ $entry->ip }} @endif
                            </p>

                            @if ($entry->before || $entry->after)
                                <details class="group mt-1.5">
                                    <summary class="cursor-pointer text-xs font-medium text-brand-700 hover:underline dark:text-brand-400">
                                        {{ __('What changed') }}
                                    </summary>

                                    <div class="mt-1.5 grid gap-2 sm:grid-cols-2">
                                        @if ($entry->before)
                                            <div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-800/60">
                                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Before') }}</p>
                                                <pre class="mt-1 overflow-x-auto whitespace-pre-wrap break-words font-mono text-[11px] text-gray-700 dark:text-gray-300">{{ json_encode($entry->before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                            </div>
                                        @endif

                                        @if ($entry->after)
                                            <div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-800/60">
                                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('After') }}</p>
                                                <pre class="mt-1 overflow-x-auto whitespace-pre-wrap break-words font-mono text-[11px] text-gray-700 dark:text-gray-300">{{ json_encode($entry->after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                            </div>
                                        @endif
                                    </div>
                                </details>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            {{ $entries->links() }}
        </div>
    @endif
</x-app-layout>
