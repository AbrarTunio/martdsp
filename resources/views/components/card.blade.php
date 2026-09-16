@props(['title' => null, 'description' => null, 'padded' => true])

<section {{ $attributes->merge(['class' => 'rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900']) }}>
    @if ($title || $description || isset($actions))
        <div class="flex items-start gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
            <div class="min-w-0 flex-1">
                @if ($title)
                    <h2 class="text-sm font-semibold">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $description }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="shrink-0">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div @class(['px-4 py-4' => $padded])>
        {{ $slot }}
    </div>
</section>
