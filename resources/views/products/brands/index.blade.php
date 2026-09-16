@php($inputClass = 'tap-target block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300')

<x-app-layout :title="__('Brands')">
    <x-flash />

    <x-page-header :title="__('Brands')"
                   :description="__('Company names, so you can filter the product list and see whose lines actually sell.')">
        <a href="{{ route('products.index') }}"
           class="tap-target inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
            {{ __('Back to products') }}
        </a>
    </x-page-header>

    <x-input-error :messages="$errors->get('brand')" class="mt-4" />

    <x-card :title="__('Add a brand')" class="mt-4">
        <form method="POST" action="{{ route('products.brands.store') }}" class="flex flex-wrap gap-2">
            @csrf
            <input type="hidden" name="is_active" value="1">

            <input name="name" required autocomplete="off" value="{{ old('name') }}"
                   placeholder="{{ __('Unilever, Nestlé, National…') }}"
                   class="{{ $inputClass }} min-w-0 flex-1 sm:max-w-sm">

            <button type="submit"
                    class="tap-target shrink-0 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700">
                {{ __('Add') }}
            </button>
        </form>

        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </x-card>

    @if ($brands->isEmpty())
        <div class="mt-4">
            <x-empty-state icon="factory" :title="__('No brands yet')"
                           :description="__('Add one above, or leave brands out entirely — products do not need one.')" />
        </div>
    @else
        <ul class="mt-4 divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white dark:divide-gray-800 dark:border-gray-800 dark:bg-gray-900">
            @foreach ($brands as $brand)
                <li class="flex flex-wrap items-center gap-2 px-3 py-2.5">
                    <form method="POST" action="{{ route('products.brands.update', $brand) }}"
                          class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                        @csrf
                        @method('PATCH')

                        <input name="name" value="{{ $brand->name }}" required autocomplete="off"
                               class="{{ $inputClass }} min-w-0 flex-1 sm:max-w-xs">

                        <label class="flex shrink-0 items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" @checked($brand->is_active)
                                   class="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                            {{ __('In use') }}
                        </label>

                        <span class="shrink-0 text-xs text-gray-400 dark:text-gray-500">
                            {{ trans_choice('{0}no products|{1}1 product|[2,*]:count products', $brand->products_count, ['count' => $brand->products_count]) }}
                        </span>

                        <button type="submit"
                                class="tap-target shrink-0 rounded-lg border border-gray-300 px-3 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                            {{ __('Save') }}
                        </button>
                    </form>

                    <form method="POST" action="{{ route('products.brands.destroy', $brand) }}" class="shrink-0"
                          x-data x-on:submit="if (! confirm('{{ __('Remove this brand?') }}')) $event.preventDefault()">
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
    @endif
</x-app-layout>
