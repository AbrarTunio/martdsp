@php($inputClass = 'tap-target block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300')

<x-app-layout :title="__('Units')">
    <x-flash />

    <x-page-header :title="__('Units')"
                   :description="__('The words for sizes. A unit carries no size of its own — a box means 24 sachets only for the product that says so.')">
        <a href="{{ route('products.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            {{ __('Back to products') }}
        </a>
    </x-page-header>

    <x-input-error :messages="$errors->get('unit')" class="mt-4" />

    <x-card :title="__('Add a unit')" class="mt-4">
        <form method="POST" action="{{ route('products.units.store') }}" class="grid gap-3 sm:grid-cols-12">
            @csrf

            <div class="sm:col-span-4">
                <label for="name" class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Name') }}</label>
                <input id="name" name="name" required autocomplete="off" value="{{ old('name') }}"
                       placeholder="{{ __('Crate') }}" class="{{ $inputClass }} mt-1">
            </div>

            <div class="sm:col-span-3">
                <label for="short_name" class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Short form') }}</label>
                <input id="short_name" name="short_name" required autocomplete="off" maxlength="12"
                       value="{{ old('short_name') }}" placeholder="{{ __('crt') }}" class="{{ $inputClass }} mt-1">
            </div>

            <div class="sm:col-span-3">
                <label for="type" class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Measures') }}</label>
                <select id="type" name="type" class="{{ $inputClass }} mt-1">
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}" @selected(old('type') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-end sm:col-span-2">
                <button type="submit"
                        class="tap-target w-full rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                    {{ __('Add') }}
                </button>
            </div>
        </form>

        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ __('Count is for things you can hand over whole. Weight and volume allow part of one to be sold — 0.75 kg of rice, but never 0.75 of a sachet.') }}
        </p>

        @foreach (['name', 'short_name', 'type'] as $field)
            <x-input-error :messages="$errors->get($field)" class="mt-2" />
        @endforeach
    </x-card>

    <ul class="mt-4 divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white dark:divide-gray-800 dark:border-gray-800 dark:bg-gray-900">
        @foreach ($units as $unit)
            <li class="flex flex-wrap items-center gap-2 px-3 py-2.5">
                <form method="POST" action="{{ route('products.units.update', $unit) }}"
                      class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                    @csrf
                    @method('PATCH')

                    <input name="name" value="{{ $unit->name }}" required autocomplete="off"
                           class="{{ $inputClass }} min-w-0 flex-1 sm:max-w-[12rem]">

                    <input name="short_name" value="{{ $unit->short_name }}" required autocomplete="off" maxlength="12"
                           class="{{ $inputClass }} w-24 shrink-0">

                    <select name="type" class="{{ $inputClass }} w-32 shrink-0">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}" @selected($unit->type->value === $value)>{{ $label }}</option>
                        @endforeach
                    </select>

                    <span class="shrink-0 text-xs text-gray-400 dark:text-gray-500">
                        {{ trans_choice('{0}unused|{1}on 1 product|[2,*]on :count products', $unit->product_units_count, ['count' => $unit->product_units_count]) }}
                    </span>

                    <button type="submit"
                            class="tap-target shrink-0 rounded-lg border border-gray-300 px-3 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                        {{ __('Save') }}
                    </button>
                </form>

                <form method="POST" action="{{ route('products.units.destroy', $unit) }}" class="shrink-0"
                      x-data x-on:submit="if (! confirm('{{ __('Remove this unit?') }}')) $event.preventDefault()">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="tap-target grid place-items-center rounded-lg px-2 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                            aria-label="{{ __('Remove') }}">
                        <x-icon name="close" class="h-4 w-4" />
                    </button>
                </form>
            </li>
        @endforeach
    </ul>
</x-app-layout>
