<x-app-layout :title="__('Add supplier')">
    <x-flash />

    <x-page-header :title="__('Add supplier')"
                   :description="__('A distributor, wholesaler or salesman you buy stock from.')" />

    <form method="POST" action="{{ route('suppliers.store') }}" class="mt-5">
        @csrf

        @include('suppliers._form')

        <div class="mt-5 flex flex-wrap justify-end gap-2">
            <a href="{{ route('suppliers.index') }}"
               class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                {{ __('Cancel') }}
            </a>

            <button type="submit"
                    class="tap-target inline-flex items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700">
                {{ __('Add supplier') }}
            </button>
        </div>
    </form>
</x-app-layout>
