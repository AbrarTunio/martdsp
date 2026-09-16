<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <x-field name="email" :label="__('Email')" required>
            <x-text-input id="email" name="email" type="email" :value="old('email')"
                          class="block w-full" required autofocus autocomplete="username"
                          inputmode="email" />
        </x-field>

        <x-field name="password" :label="__('Password')" required>
            <x-text-input id="password" name="password" type="password"
                          class="block w-full" required autocomplete="current-password" />
        </x-field>

        <label for="remember_me" class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
            <input id="remember_me" name="remember" type="checkbox"
                   class="rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900">
            {{ __('Keep me signed in on this device') }}
        </label>

        <button type="submit"
                class="tap-target flex w-full items-center justify-center rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900">
            {{ __('Log in') }}
        </button>

        @if (Route::has('password.request'))
            <p class="text-center text-sm">
                <a href="{{ route('password.request') }}"
                   class="text-gray-500 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100">
                    {{ __('Forgot your password?') }}
                </a>
            </p>
        @endif
    </form>

    <p class="mt-5 border-t border-gray-200 pt-4 text-center text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
        {{ __('Accounts are created by the shop owner under Settings → Staff.') }}
    </p>
</x-guest-layout>
