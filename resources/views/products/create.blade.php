<x-app-layout :title="__('Add product')">
    <x-page-header :title="__('Add product')"
                   :description="__('Describe the packaging once and every sale afterwards counts itself.')" />

    <form method="POST" action="{{ route('products.store') }}" class="mt-5">
        @csrf

        @include('products._form')

        <div class="mt-5 flex flex-wrap justify-end gap-2">
            <a href="{{ route('products.index') }}"
               class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                {{ __('Cancel') }}
            </a>

            <button type="submit"
                    class="tap-target inline-flex items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-950">
                {{ __('Save product') }}
            </button>
        </div>
    </form>
</x-app-layout>
