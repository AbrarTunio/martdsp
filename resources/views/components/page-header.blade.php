@props(['title', 'description' => null])

<div class="flex flex-wrap items-start justify-between gap-3">
    <div class="min-w-0">
        <h1 class="text-lg font-semibold tracking-tight sm:text-xl">{{ $title }}</h1>
        @if ($description)
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
        @endif
    </div>

    @if (! $slot->isEmpty())
        <div class="flex shrink-0 items-center gap-2">{{ $slot }}</div>
    @endif
</div>
