@props(['label', 'value', 'icon' => null, 'hint' => null, 'tone' => 'neutral'])

<div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <div class="flex items-start justify-between gap-2">
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>

        @if ($icon)
            <span @class([
                'grid h-8 w-8 shrink-0 place-items-center rounded-lg',
                'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => $tone === 'neutral',
                'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400' => $tone === 'success',
                'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' => $tone === 'warning',
                'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400' => $tone === 'danger',
            ])>
                <x-icon :name="$icon" class="h-4.5 w-4.5" />
            </span>
        @endif
    </div>

    <p class="mt-2 text-xl font-semibold tracking-tight tabular-nums sm:text-2xl">{{ $value }}</p>

    @if ($hint)
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @endif
</div>
