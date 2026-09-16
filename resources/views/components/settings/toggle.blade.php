@props(['name', 'label', 'hint' => null, 'checked' => false])

{{--
    A labelled switch. The hidden field ahead of the checkbox means an "off"
    state is actually submitted rather than simply being absent.
--}}
<label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50">
    <input type="hidden" name="{{ $name }}" value="0">
    <input type="checkbox" name="{{ $name }}" value="1" @checked($checked)
           class="mt-0.5 h-4.5 w-4.5 shrink-0 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">

    <span class="min-w-0 flex-1">
        <span class="block text-sm font-medium">{{ $label }}</span>
        @if ($hint)
            <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</span>
        @endif
    </span>
</label>
