{{-- Things on the shelves or in the drawers that someone should look at today. --}}
<x-card :title="__('Needs attention')" :padded="false">
    @forelse ($warnings as $warning)
        <a href="{{ $warning['href'] }}"
           class="flex items-center gap-3 border-b border-gray-100 px-4 py-3 last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50">
            <span @class([
                'grid h-9 w-9 shrink-0 place-items-center rounded-lg',
                'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400' => $warning['tone'] === 'danger',
                'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' => $warning['tone'] === 'warning',
            ])>
                <x-icon :name="$warning['icon']" class="h-4.5 w-4.5" />
            </span>

            <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium">{{ $warning['label'] }}</span>
                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $warning['hint'] }}</span>
            </span>

            <span class="text-lg font-semibold tabular-nums">{{ number_format($warning['count']) }}</span>
            <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-gray-400" />
        </a>
    @empty
        <div class="flex items-center gap-3 px-4 py-6">
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                <x-icon name="check" class="h-4.5 w-4.5" />
            </span>
            <span class="text-sm text-gray-600 dark:text-gray-300">{{ __('All clear — nothing low, expired or below zero.') }}</span>
        </div>
    @endforelse
</x-card>
