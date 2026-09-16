@props(['name', 'title', 'wide' => false])

{{--
    A bottom sheet on the till. Slides up from the bottom on a phone, where the
    thumb is; sits in the middle of a PC screen. Opened and closed through the
    till's own `sheet` state, so only one is ever open.

    It is a pop-up, so it goes over everything: the rest of the screen is at
    z-30 to z-50 (top bar, sidebar, tab bar, the total bar), the dark backdrop
    at 55 and the sheet itself at 60. The gap at the top is left clear on
    purpose — it keeps the sheet clear of the top bar and shows enough of the
    dimmed screen behind it that it reads as something laid over the page.
--}}
<div x-show="sheet === '{{ $name }}'" x-cloak x-transition.opacity
     x-on:click="closeSheet()"
     class="fixed inset-0 z-[55] bg-gray-900/60 backdrop-blur-[2px]"></div>

<div x-show="sheet === '{{ $name }}'" x-cloak
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="translate-y-full sm:translate-y-4 sm:opacity-0"
     x-transition:enter-end="translate-y-0 sm:opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="translate-y-0 sm:opacity-100"
     x-transition:leave-end="translate-y-full sm:translate-y-4 sm:opacity-0"
     class="pointer-events-none fixed inset-0 z-[60] flex items-end justify-center pt-16 sm:items-center sm:p-8 sm:pt-20">
    <div @class([
             'pointer-events-auto flex max-h-full w-full flex-col rounded-t-2xl border-t border-gray-200 bg-white pb-safe shadow-2xl dark:border-gray-700 dark:bg-gray-900',
             'sm:rounded-2xl sm:border',
             'sm:max-w-2xl' => $wide,
             'sm:max-w-lg' => ! $wide,
         ])
         role="dialog" aria-modal="true" aria-label="{{ $title }}">
        <div class="flex shrink-0 items-center justify-between gap-3 border-b border-gray-200 px-4 py-2 dark:border-gray-700">
            <p class="text-base font-semibold">{{ $title }}</p>

            <button type="button" x-on:click="closeSheet()"
                    class="tap-target grid place-items-center rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800"
                    aria-label="{{ __('Close') }}">
                <x-icon name="close" class="h-5 w-5" />
            </button>
        </div>

        <div class="scroll-slim min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-4">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="shrink-0 border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
