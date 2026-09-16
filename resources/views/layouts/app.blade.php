@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#059669">

        <title>{{ $title ? $title.' · '.config('app.name') : config('app.name') }}</title>

        {{-- Applied before first paint so the theme never flashes. --}}
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
    <body class="h-full font-sans antialiased bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100">
        <div class="min-h-full">
            @include('layouts.partials.sidebar')

            <div class="lg:pl-64">
                @include('layouts.partials.topbar')

                {{-- x-data makes this an Alpine root so x-rise runs: every page
                     settles in rather than appearing all at once. --}}
                <main x-data x-rise
                      class="px-4 pt-4 pb-[calc(var(--spacing-tabbar)+1.5rem)] sm:px-6 lg:px-8 lg:pb-10">
                    @isset($header)
                        <div class="mb-4">
                            {{ $header }}
                        </div>
                    @endisset

                    {{ $slot }}
                </main>
            </div>

            @include('layouts.partials.tab-bar')
        </div>
    </body>
</html>
