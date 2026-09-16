<x-app-layout :title="__('Choose your counter')">
    <x-page-header :title="__('Which counter are you at?')"
                   :description="__('Each counter has its own cash drawer and receipt printer. This device remembers the choice until you log out.')" />

    <ul class="mt-5 grid gap-3 sm:grid-cols-2 lg:max-w-3xl">
        @foreach ($registers as $register)
            <li>
                <form method="POST" action="{{ route('pos.register') }}">
                    @csrf
                    <input type="hidden" name="register_id" value="{{ $register->id }}">

                    <button type="submit"
                            class="flex w-full items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 text-left hover:border-brand-400 hover:bg-brand-50 dark:border-gray-800 dark:bg-gray-900 dark:hover:border-brand-500/60 dark:hover:bg-brand-500/5">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                            <x-icon name="cart" class="h-5.5 w-5.5" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-base font-semibold">{{ $register->name }}</span>
                            @if ($register->location)
                                <span class="block truncate text-sm text-gray-500 dark:text-gray-400">{{ $register->location }}</span>
                            @endif
                        </span>
                        <x-icon name="chevron-right" class="h-5 w-5 shrink-0 text-gray-400" />
                    </button>
                </form>
            </li>
        @endforeach
    </ul>

    @can('manage-settings')
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
            <a href="{{ route('settings.registers.index') }}" class="font-medium text-brand-700 hover:underline dark:text-brand-400">{{ __('Add or rename counters') }}</a>
        </p>
    @endcan
</x-app-layout>
