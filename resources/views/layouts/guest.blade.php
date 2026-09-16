<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#059669">

        <title>{{ config('app.name') }}</title>

        <script>
            (function () {
                try {
                    var stored = localStorage.getItem('theme');
                    var dark = stored
                        ? stored === 'dark'
                        : window.matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.classList.toggle('dark', dark);
                } catch (e) {}
            })();
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full font-sans antialiased bg-gray-100 text-gray-900 dark:bg-gray-950 dark:text-gray-100">
        <div class="flex min-h-full flex-col justify-center px-4 py-10 sm:px-6">
            <div class="mx-auto w-full max-w-sm">
                <div class="mb-6 flex flex-col items-center text-center">
                    <span class="grid h-14 w-14 place-items-center rounded-2xl bg-brand-600 text-white shadow-sm">
                        <x-icon name="cart" class="h-7 w-7" />
                    </span>
                    <h1 class="mt-3 text-xl font-semibold tracking-tight">{{ config('app.name') }}</h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Sign in to the till') }}
                    </p>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </body>
</html>
