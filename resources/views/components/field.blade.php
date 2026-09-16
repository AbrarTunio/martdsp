@props(['name', 'label', 'hint' => null, 'required' => false])

<div>
    <label for="{{ $name }}" class="block text-sm font-medium">
        {{ $label }}
        @if ($required)
            <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span>
        @endif
    </label>

    <div class="mt-1.5">
        {{ $slot }}
    </div>

    @if ($hint)
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @endif

    <x-input-error :messages="$errors->get($name)" class="mt-1.5" />
</div>
