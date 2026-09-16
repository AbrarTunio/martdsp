@props(['icon' => 'alert', 'title', 'description' => null])

<div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 px-6 py-10 text-center dark:border-gray-700">
    <span class="mb-3 grid h-11 w-11 place-items-center rounded-full bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500">
        <x-icon :name="$icon" class="h-5.5 w-5.5" />
    </span>

    <p class="text-sm font-semibold">{{ $title }}</p>

    @if ($description)
        <p class="mt-1.5 max-w-sm text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
    @endif

    @if (! $slot->isEmpty())
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
