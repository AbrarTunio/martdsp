<x-app-layout :title="__('Open a khata')">
    <x-flash />

    <x-page-header :title="__('Open a khata')"
                   :description="__('A customer who may take goods now and pay later.')" />

    <form method="POST" action="{{ route('customers.store') }}" class="mt-5">
        @csrf

        @include('customers._form')

        <div class="mt-5 flex flex-wrap justify-end gap-2">
            <a href="{{ route('customers.index') }}"
               class="tap-target inline-flex items-center rounded-lg border border-gray-300 px-4 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                {{ __('Cancel') }}
            </a>

            <button type="submit"
                    class="tap-target inline-flex items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700">
                {{ __('Open khata') }}
            </button>
        </div>
    </form>
</x-app-layout>
