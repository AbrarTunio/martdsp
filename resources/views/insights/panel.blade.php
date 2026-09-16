{{--
    One note about one page: the headline, what the figures say, the points
    worth acting on, and where the note came from.

    Returned on its own to the "Explain this page" button, so it carries no
    layout of its own.
--}}
<div class="space-y-4">
    @if ($insight->notice)
        <p class="flex gap-2 rounded-lg bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
            <x-icon name="alert" class="h-4 w-4 shrink-0" />
            <span>{{ $insight->notice }}</span>
        </p>
    @endif

    <div>
        <p class="text-[0.9375rem] font-semibold leading-snug">{{ $insight->headline }}</p>
        <p class="mt-1.5 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ $insight->summary }}</p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">{{ $insight->pack->title }} · {{ $insight->pack->scopeLabel }}</p>
    </div>

    @forelse ($insight->items as $item)
        <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
            <div class="flex items-start justify-between gap-2">
                <p class="text-sm font-semibold leading-snug">{{ $item['title'] }}</p>
                <x-badge :tone="$item['severity']->tone()" class="shrink-0">{{ $item['severity']->label() }}</x-badge>
            </div>

            @if ($item['explanation'])
                <p class="mt-1.5 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ $item['explanation'] }}</p>
            @endif

            @if ($item['action'])
                <p class="mt-2 flex gap-1.5 text-sm text-gray-800 dark:text-gray-200">
                    <x-icon name="chevron-right" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600 dark:text-brand-400" />
                    <span>{{ $item['action'] }}</span>
                </p>
            @endif

            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                @if ($item['impact'])
                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('Money involved: :amount', ['amount' => $item['impact']]) }}</span>
                @endif

                @if ($item['href'])
                    <a href="{{ $item['href'] }}" class="font-medium text-brand-700 hover:underline dark:text-brand-400">{{ __('Take me there') }}</a>
                @endif
            </div>
        </div>
    @empty
        <p class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-400">
            {{ __('Nothing on this page needs your attention right now.') }}
        </p>
    @endforelse

    @if ($insight->watchNext !== [])
        <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-800/60">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Keep an eye on') }}</p>
            <ul class="mt-1.5 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                @foreach ($insight->watchNext as $line)
                    <li class="flex gap-1.5">
                        <span aria-hidden="true">·</span>
                        <span>{{ $line }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="border-t border-gray-200 pt-3 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-500">
        {{ $insight->credit() }}
        @if ($insight->meta['cached'] ?? false)
            · {{ __('Saved answer — press Refresh for a new one') }}
        @endif
    </p>
</div>
