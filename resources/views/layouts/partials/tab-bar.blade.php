@php
    use App\Support\Navigation;

    $tabs = Navigation::tabs();
    $overflow = array_values(array_filter(
        Navigation::visible(),
        fn (array $item): bool => ! $item['tab'],
    ));
@endphp

{{--
    The phone's primary navigation. Thumb-reachable, 44px targets, and pinned
    above the iOS home indicator via the pb-safe utility.
--}}
<div x-data="{ more: false }" class="no-print lg:hidden">
    <div x-show="more" x-on:click="more = false" x-transition.opacity x-cloak
         class="fixed inset-0 z-40 bg-gray-900/50"></div>

    <div x-show="more" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="translate-y-full"
         x-transition:enter-end="translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="translate-y-0"
         x-transition:leave-end="translate-y-full"
         class="fixed inset-x-0 bottom-0 z-50 flex max-h-[calc(100dvh-4rem)] flex-col rounded-t-2xl border-t border-gray-200 bg-white pb-safe shadow-2xl dark:border-gray-700 dark:bg-gray-900">
        <div class="flex shrink-0 items-center justify-between px-4 pt-4 pb-2">
            <p class="text-sm font-semibold">{{ __('More') }}</p>
            <button type="button" x-on:click="more = false"
                    class="tap-target grid place-items-center rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800"
                    aria-label="{{ __('Close') }}">
                <x-icon name="close" class="h-5 w-5" />
            </button>
        </div>

        {{-- The list scrolls: on a tablet held on its side there is not room
             for every screen at once, and a link that cannot be reached is a
             screen the shopkeeper cannot get to. --}}
        <ul class="scroll-slim min-h-0 flex-1 overflow-y-auto overscroll-contain px-2 pb-4">
            @foreach ($overflow as $item)
                <li>
                    <a href="{{ route($item['route']) }}"
                       class="flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium hover:bg-gray-100 dark:hover:bg-gray-800">
                        <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0 text-gray-500 dark:text-gray-400" />
                        <span class="flex-1">{{ __($item['label']) }}</span>
                        <x-icon name="chevron-right" class="h-4 w-4 text-gray-400" />
                    </a>
                </li>
            @endforeach

            <li>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">
                        <x-icon name="logout" class="h-5 w-5 shrink-0" />
                        {{ __('Log out') }}
                    </button>
                </form>
            </li>
        </ul>
    </div>

    <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white/95 pb-safe backdrop-blur dark:border-gray-800 dark:bg-gray-900/95"
         aria-label="{{ __('Main navigation') }}">
        <ul class="flex h-tabbar items-stretch">
            @foreach ($tabs as $item)
                @php($active = Navigation::isActive($item['route']))
                <li class="flex-1">
                    <a href="{{ route($item['route']) }}"
                       @class([
                           'flex h-full flex-col items-center justify-center gap-1 px-1 text-[0.6875rem] font-medium',
                           'text-brand-600 dark:text-brand-400' => $active,
                           'text-gray-500 dark:text-gray-400' => ! $active,
                       ])
                       @if ($active) aria-current="page" @endif>
                        <x-icon :name="$item['icon']" class="h-6 w-6" />
                        <span class="truncate">{{ __($item['label']) }}</span>
                    </a>
                </li>
            @endforeach

            @if ($overflow !== [])
                <li class="flex-1">
                    <button type="button" x-on:click="more = true"
                            class="flex h-full w-full flex-col items-center justify-center gap-1 px-1 text-[0.6875rem] font-medium text-gray-500 dark:text-gray-400">
                        <x-icon name="dots" class="h-6 w-6" />
                        <span>{{ __('More') }}</span>
                    </button>
                </li>
            @endif
        </ul>
    </nav>
</div>
