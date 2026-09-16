{{--
    The counters open right now. The cash figure is left off for anyone the
    blind-count setting keeps from seeing what the drawer should hold.
--}}
<x-card :title="__('Open drawers')" :padded="false">
    <x-slot:actions>
        <a href="{{ route('drawer.index') }}" class="text-xs font-semibold text-brand-700 hover:underline dark:text-brand-400">{{ __('All drawers') }}</a>
    </x-slot:actions>

    @forelse ($drawers as $session)
        <a href="{{ route('drawer.show', $session) }}"
           class="flex items-center gap-3 border-b border-gray-100 px-4 py-3 last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50">
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                <x-icon name="unlock" class="h-4.5 w-4.5" />
            </span>

            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium">{{ $session->register?->name }}</span>
                <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                    {{ __(':name since :time', [
                        'name' => $session->opener?->name ?? __('Removed user'),
                        'time' => $session->opened_at->isToday() ? $session->opened_at->format('g:i A') : $session->opened_at->format('d M, g:i A'),
                    ]) }}
                </span>
            </span>

            @if ($seesExpected)
                <x-money :paisa="(int) $session->transactions_sum_amount_paisa" rounded class="text-sm font-semibold" />
            @endif
        </a>
    @empty
        <div class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">
            {{ __('No drawer is open. Open one before the first sale.') }}
        </div>
    @endforelse
</x-card>
