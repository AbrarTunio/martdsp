<header class="no-print sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-gray-200 bg-white/90 px-4 backdrop-blur sm:px-6 lg:h-16 lg:px-8 dark:border-gray-800 dark:bg-gray-900/90">
    {{-- Brand shows on phones, where there is no sidebar to carry it. --}}
    <span class="flex items-center gap-2 lg:hidden">
        <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-600 text-white">
            <x-icon name="cart" class="h-4.5 w-4.5" />
        </span>
        <span class="text-[0.9375rem] font-semibold tracking-tight">{{ config('app.name') }}</span>
    </span>

    <div class="ml-auto flex items-center gap-1">
        <button type="button"
                x-data="{
                    dark: document.documentElement.classList.contains('dark'),
                    toggle() {
                        this.dark = ! this.dark;
                        document.documentElement.classList.toggle('dark', this.dark);
                        try { localStorage.setItem('theme', this.dark ? 'dark' : 'light'); } catch (e) {}
                    },
                }"
                x-on:click="toggle()"
                class="tap-target grid place-items-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100"
                :aria-label="dark ? '{{ __('Switch to light theme') }}' : '{{ __('Switch to dark theme') }}'">
            <x-icon name="sun" class="h-5 w-5 dark:hidden" />
            <x-icon name="moon" class="hidden h-5 w-5 dark:block" />
        </button>

        <div x-data="{ open: false }" class="relative">
            <button type="button" x-on:click="open = ! open"
                    class="tap-target grid place-items-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
                    :aria-expanded="open" aria-haspopup="menu"
                    aria-label="{{ __('Account menu') }}">
                <span class="grid h-8 w-8 place-items-center rounded-full bg-gray-200 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                    {{ Str::upper(Str::substr(auth()->user()->name, 0, 2)) }}
                </span>
            </button>

            <div x-show="open" x-on:click.outside="open = false" x-transition x-cloak
                 class="absolute right-0 mt-2 w-56 origin-top-right rounded-xl border border-gray-200 bg-white p-1.5 shadow-lg dark:border-gray-700 dark:bg-gray-800"
                 role="menu">
                <div class="px-2.5 py-2">
                    <p class="truncate text-sm font-medium">{{ auth()->user()->name }}</p>
                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ auth()->user()->email }}</p>
                </div>

                <div class="my-1 border-t border-gray-200 dark:border-gray-700"></div>

                <a href="{{ route('profile.edit') }}" role="menuitem"
                   class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-700">
                    <x-icon name="user" class="h-4.5 w-4.5" />
                    {{ __('My profile') }}
                </a>

                @can('manage-settings')
                    <a href="{{ route('settings.index') }}" role="menuitem"
                       class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-700">
                        <x-icon name="cog" class="h-4.5 w-4.5" />
                        {{ __('Settings') }}
                    </a>
                @endcan

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" role="menuitem"
                            class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">
                        <x-icon name="logout" class="h-4.5 w-4.5" />
                        {{ __('Log out') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
