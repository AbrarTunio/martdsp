@php($inputClass = 'tap-target block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300')

<x-app-layout :title="__('Categories')">
    <x-flash />

    <x-page-header :title="__('Categories')"
                   :description="__('The aisles of your shop. Two levels is plenty — a third is just another place to lose a product.')">
        <a href="{{ route('products.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            {{ __('Back to products') }}
        </a>
    </x-page-header>

    <x-input-error :messages="$errors->get('category')" class="mt-4" />

    <x-card :title="__('Add a category')" class="mt-4">
        <form method="POST" action="{{ route('products.categories.store') }}" class="grid gap-3 sm:grid-cols-12">
            @csrf

            <div class="sm:col-span-4">
                <label for="name" class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Name') }}</label>
                <input id="name" name="name" required autocomplete="off" value="{{ old('name') }}"
                       class="{{ $inputClass }} mt-1">
            </div>

            <div class="sm:col-span-3">
                <label for="name_ur" class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Urdu name') }}</label>
                <input id="name_ur" name="name_ur" dir="rtl" autocomplete="off" value="{{ old('name_ur') }}"
                       class="{{ $inputClass }} mt-1 font-urdu">
            </div>

            <div class="sm:col-span-3">
                <label for="parent_id" class="block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Inside') }}</label>
                <select id="parent_id" name="parent_id" class="{{ $inputClass }} mt-1">
                    <option value="">{{ __('Top level') }}</option>
                    @foreach ($parents as $id => $name)
                        <option value="{{ $id }}" @selected((string) old('parent_id') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-end sm:col-span-2">
                <input type="hidden" name="is_active" value="1">
                <button type="submit"
                        class="tap-target w-full rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">
                    {{ __('Add') }}
                </button>
            </div>
        </form>

        <x-input-error :messages="$errors->get('name')" class="mt-2" />
        <x-input-error :messages="$errors->get('parent_id')" class="mt-2" />
    </x-card>

    @if ($categories->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="layers" :title="__('No categories yet')"
                           :description="__('Add one above. Products can also be left uncategorised.')" />
        </div>
    @else
        <ul class="mt-4 space-y-2">
            @foreach ($categories as $category)
                <li class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    @include('products.categories._row', ['category' => $category, 'depth' => 0])

                    @foreach ($category->children as $child)
                        @include('products.categories._row', ['category' => $child, 'depth' => 1])
                    @endforeach
                </li>
            @endforeach
        </ul>
    @endif
</x-app-layout>
