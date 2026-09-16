<x-app-layout :title="$supplier->name">
    <x-flash />

    <x-page-header :title="__('Edit :name', ['name' => $supplier->name])"
                   :description="__('Their details only. What you owe them changes through bills, payments and returns.')" />

    <form method="POST" action="{{ route('suppliers.update', $supplier) }}" class="mt-5">
        @csrf
        @method('PUT')

        @include('suppliers._form')

        <div class="mt-5 flex flex-wrap justify-end gap-2">
            <a href="{{ route('suppliers.show', $supplier) }}"
               class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                {{ __('Cancel') }}
            </a>

            <button type="submit"
                    class="tap-target inline-flex items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700">
                {{ __('Save') }}
            </button>
        </div>
    </form>
</x-app-layout>
