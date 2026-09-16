<x-app-layout :title="$customer->name">
    <x-flash />

    <x-page-header :title="__('Edit :name', ['name' => $customer->name])"
                   :description="__('Their details and credit limit only. The balance moves through sales, payments and corrections.')" />

    <form method="POST" action="{{ route('customers.update', $customer) }}" class="mt-5">
        @csrf
        @method('PUT')

        @include('customers._form')

        <div class="mt-5 flex flex-wrap justify-end gap-2">
            <a href="{{ route('customers.show', $customer) }}"
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
