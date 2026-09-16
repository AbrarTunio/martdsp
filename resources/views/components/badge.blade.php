@props(['tone' => 'neutral'])

<span {{ $attributes->class([
    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
    'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => $tone === 'neutral',
    'bg-brand-100 text-brand-800 dark:bg-brand-500/15 dark:text-brand-300' => $tone === 'success',
    'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' => $tone === 'warning',
    'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300' => $tone === 'danger',
    'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300' => $tone === 'info',
]) }}>{{ $slot }}</span>
