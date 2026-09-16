{{--
    Where the shop's money is sitting right now. Only the owner and manager
    get this panel. Money kept in the bank or a safe at home is not recorded
    in the app, so it is not counted here.
--}}
@php
    $largest = max(1, ...array_column($money['held'], 'paisa'));
@endphp

<x-card :title="__('Where the money is')" :description="__('Right now, whatever the dates.')">
    <ul class="space-y-3">
        @foreach ($money['held'] as $place)
            <li>
                <a href="{{ $place['href'] }}" class="group block">
                    <span class="flex items-baseline justify-between gap-2">
                        <span class="text-sm font-medium group-hover:text-brand-700 dark:group-hover:text-brand-400">{{ $place['label'] }}</span>
                        <x-money :paisa="$place['paisa']" rounded class="text-sm font-semibold" />
                    </span>
                    <span class="mt-1 block h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                        <span class="block h-full rounded-full bg-brand-500" style="width: {{ max(0, round($place['paisa'] / $largest * 100, 1)) }}%"></span>
                    </span>
                    <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ $place['hint'] }}</span>
                </a>
            </li>
        @endforeach

        <li>
            <a href="{{ $money['owed']['href'] }}" class="group block">
                <span class="flex items-baseline justify-between gap-2">
                    <span class="text-sm font-medium group-hover:text-brand-700 dark:group-hover:text-brand-400">{{ $money['owed']['label'] }}</span>
                    <x-money :paisa="-$money['owed']['paisa']" rounded class="text-sm font-semibold" />
                </span>
                <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ $money['owed']['hint'] }}</span>
            </a>
        </li>
    </ul>

    <div class="mt-4 flex items-baseline justify-between gap-2 border-t border-gray-200 pt-3 dark:border-gray-800">
        <span class="text-sm font-semibold">{{ __('Your money in the business') }}</span>
        <x-money :paisa="$money['total']" rounded class="text-base font-bold" />
    </div>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Cash, stock and khata, less what you owe suppliers. Money in the bank is not counted.') }}</p>
</x-card>
